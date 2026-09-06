<?php
/**
 * Property 17: One contact per email, with the configured membership.
 *
 * Feature: enquiry-data-layer, Property 17: For any set of enquiries over any
 * set of email addresses, and any configured list and tag identifiers, linkage
 * results in exactly one FluentCRM contact per distinct email address, each
 * holding the enquiry's `first_name`, `last_name`, `email` and `phone`,
 * belonging to the configured list and carrying the configured tag, with that
 * contact's subscriber identifier recorded on every enquiry sharing the email.
 *
 * **Validates: Requirements 6.1, 6.2, 6.3, 6.4, 6.5**
 *
 * How the property is instantiated:
 *
 * - The contact universe is `tests/fakes/FakeCrm.php`, which keys contacts by
 *   trimmed, lower-cased email — the identity FluentCRM itself matches on. So
 *   "exactly one contact per distinct email" is read straight off the fake's
 *   contact count rather than inferred from the call log.
 * - The enquiries are real rows, written through `EnquiryStore::create()`
 *   against real tables, because the other half of the claim is about what the
 *   *enquiry* holds afterwards: every enquiry sharing an email must end up
 *   holding that one contact's subscriber identifier.
 * - Email addresses come from a generated pool and each enquiry picks one from
 *   it, so a single run covers one email per enquiry, several enquiries on one
 *   email, and any mixture of the two, which is what makes criterion 6.2 (reuse
 *   rather than create) a quantified claim rather than a worked example.
 * - The expected contact data is computed by a reference merge rather than taken
 *   from the last enquiry linked: an upsert writes over the fields it carries
 *   and omits an empty value rather than blanking what FluentCRM already holds,
 *   so with `phone` generated sometimes-empty the expected contact is the
 *   last **non-empty** value per field, in link order.
 * - Production mode throughout. The staging prefix and the `test-record` tag are
 *   Property 19's subject, and leaving them out here keeps `first_name` a
 *   straight comparison against what the enquiry holds (Requirement 6.3).
 * - `Settings` is deliberately not loaded, so the linker reads the configured
 *   list and tag from the stored options, which is the same value either way.
 *
 * @package MarthrownEnquiryHub
 */

use Eris\TestTrait;
use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\ContactLinker;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Fakes\FakeCrm;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;

/**
 * Class ContactUpsertMembershipPropertyTest
 */
class ContactUpsertMembershipPropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables can never collide with a real
	 * Enquiry Store table sitting in the same test database.
	 */
	const PREFIX_SEGMENT = 'mehcu_';

	/**
	 * Most distinct email addresses one iteration draws.
	 *
	 * Three is enough for the groupings that matter — one email, two emails with
	 * an uneven split, and more emails than enquiries — while keeping each
	 * iteration's write count low enough for a database-backed property.
	 */
	const EMAILS_MAX = 3;

	/**
	 * Most enquiries one iteration writes.
	 *
	 * Above the email ceiling, so an iteration can put several enquiries on one
	 * address, which is the case Requirement 6.2 is about.
	 */
	const ENQUIRIES_MAX = 6;

	/**
	 * The WordPress table prefix in force outside this test.
	 *
	 * @var string
	 */
	private $original_prefix = '';

	/**
	 * The installed CRM fake.
	 *
	 * @var FakeCrm|null
	 */
	private $crm = null;

	/**
	 * Load the classes under test.
	 *
	 * @param mixed $factory WordPress fixture factory (unused).
	 * @return void
	 */
	public static function wpSetUpBeforeClass( $factory = null ) {
		unset( $factory );

		require_once MEH_INCLUDES_DIR . 'class-log.php';
		require_once MEH_INCLUDES_DIR . 'class-clock.php';
		require_once MEH_INCLUDES_DIR . 'class-schema.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-query.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-store.php';
		require_once MEH_INCLUDES_DIR . 'class-history-recorder.php';
		require_once MEH_INCLUDES_DIR . 'class-staging-marker.php';
		require_once MEH_INCLUDES_DIR . 'class-contact-linker.php';
	}

	public function set_up() {
		parent::set_up();

		global $wpdb;

		/*
		 * The WordPress test case rewrites CREATE TABLE into its TEMPORARY form,
		 * and `Schema::install()` verifies each table through `SHOW TABLES`,
		 * which cannot see a temporary table. This test therefore works against
		 * real tables at its own prefix and cleans them up itself.
		 */
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->original_prefix = $wpdb->prefix;
		$wpdb->prefix          = $this->original_prefix . self::PREFIX_SEGMENT;

		$this->drop_tables();
		$this->assertTrue( Schema::install(), 'The fixture install should succeed.' );

		$this->crm = FakeCrm::install();

		Clock::freeze( '2025-06-02 11:30:00' );
	}

	public function tear_down() {
		global $wpdb;

		FakeCrm::uninstall();
		$this->crm = null;

		delete_option( 'meh_enquiry_list' );
		delete_option( 'meh_enquiry_tag' );

		Clock::unfreeze();
		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/**
	 * Feature: enquiry-data-layer, Property 17: One contact per email, with the
	 * configured membership.
	 *
	 * **Validates: Requirements 6.1, 6.2, 6.3, 6.4, 6.5**
	 *
	 * @eris-shrink 10
	 */
	public function test_one_contact_per_email_with_the_configured_membership() {
		$this->limitTo( Iterations::count( 100 ) )
			->forAll( self::scenario() )
			->then(
				function ( array $case ) {
					$this->reset_universe( (int) $case['list_id'], (int) $case['tag_id'] );

					$linked = $this->link_all( $case['emails'], $case['enquiries'] );
					$groups = self::group_by_contact( $linked );

					// Requirements 6.1, 6.2: one contact per distinct email, and
					// a second enquiry on an address reuses the first's contact
					// rather than adding another.
					$this->assertSame(
						count( $groups ),
						$this->crm->contact_count(),
						'There should be exactly one contact per distinct email address.'
					);

					// One upsert per link all the same: reuse is FluentCRM
					// matching on email, not the linker skipping the call.
					$this->assertSame(
						count( $linked ),
						$this->crm->call_count( 'createOrUpdate' ),
						'Every link should perform its own upsert.'
					);

					$subscriber_ids = array();

					foreach ( $groups as $key => $group ) {
						$this->assert_contact_data( $key, $group );
						$this->assert_membership( $key, (int) $case['list_id'], (int) $case['tag_id'] );

						$subscriber_ids[] = $this->assert_recorded_on_every_enquiry( $key, $group );
					}

					// Distinct addresses are distinct contacts: no group may end
					// up pointing at another group's subscriber.
					$this->assertSame(
						count( $subscriber_ids ),
						count( array_unique( $subscriber_ids ) ),
						'Each distinct email should resolve its own subscriber identifier.'
					);
				}
			);
	}

	/* ---------------------------------------------------------------------
	 * Generators
	 * ------------------------------------------------------------------ */

	/**
	 * One case: a pool of email addresses, a set of enquiries each drawing one
	 * address from that pool, and the configured list and tag identifiers.
	 *
	 * The pool size is bound first so each enquiry can choose an index within it,
	 * which is what lets one iteration hold any grouping of enquiries over
	 * addresses rather than a fixed round robin.
	 *
	 * @return \Eris\Generator
	 */
	protected static function scenario() {
		return \Eris\Generators::bind(
			\Eris\Generators::choose( 1, self::EMAILS_MAX ),
			function ( $pool_size ) {
				$pool_size = max( 1, (int) $pool_size );

				return \Eris\Generators::associative(
					array(
						'emails'    => \Eris\Generators::vector( $pool_size, Generators::email() ),
						'enquiries' => self::enquiries( $pool_size ),
						'list_id'   => self::configured_id(),
						'tag_id'    => self::configured_id(),
					)
				);
			}
		);
	}

	/**
	 * Between one and self::ENQUIRIES_MAX enquiries, each naming an index into
	 * the email pool and carrying its own contact field values.
	 *
	 * `phone` may be empty, which the store permits (Requirement 1.19) and which
	 * an upsert omits rather than sends, so the reference merge below has
	 * something to be right about.
	 *
	 * @param int $pool_size Number of addresses in the pool.
	 * @return \Eris\Generator
	 */
	protected static function enquiries( $pool_size ) {
		return \Eris\Generators::bind(
			\Eris\Generators::choose( 1, self::ENQUIRIES_MAX ),
			function ( $count ) use ( $pool_size ) {
				return \Eris\Generators::vector(
					max( 1, (int) $count ),
					\Eris\Generators::associative(
						array(
							'email_index' => \Eris\Generators::choose( 0, $pool_size - 1 ),
							'first_name'  => Generators::first_name(),
							'last_name'   => Generators::last_name(),
							'phone'       => Generators::phone_or_empty(),
							'message'     => Generators::message_or_empty(),
						)
					)
				);
			}
		);
	}

	/**
	 * A configured FluentCRM list or tag identifier.
	 *
	 * Always positive: "the configured list" and "the configured tag" are what
	 * the property quantifies over, and an unconfigured one is a different claim.
	 *
	 * @return \Eris\Generator
	 */
	protected static function configured_id() {
		return \Eris\Generators::oneOf(
			\Eris\Generators::constant( 1 ),
			\Eris\Generators::choose( 1, 99999 )
		);
	}

	/* ---------------------------------------------------------------------
	 * Assertions
	 * ------------------------------------------------------------------ */

	/**
	 * The contact holds exactly the four linked fields, at the values the
	 * enquiries on that address wrote (Requirement 6.3).
	 *
	 * @param string $key   Lower-cased contact email.
	 * @param array  $group Enquiries on that address, in link order.
	 * @return void
	 */
	private function assert_contact_data( $key, array $group ) {
		$contact = $this->crm->contact( $key );

		$this->assertNotNull( $contact, sprintf( 'A contact should exist for %s.', $key ) );

		$expected = self::expected_contact_data( $group );
		$actual   = $contact['data'];

		ksort( $expected );
		ksort( $actual );

		$this->assertSame( $expected, $actual, sprintf( 'The contact for %s should hold the linked fields.', $key ) );
	}

	/**
	 * The contact belongs to the configured list and carries the configured tag,
	 * once each and with nothing else attached (Requirement 6.4).
	 *
	 * @param string $key     Lower-cased contact email.
	 * @param int    $list_id Configured list identifier.
	 * @param int    $tag_id  Configured tag identifier.
	 * @return void
	 */
	private function assert_membership( $key, $list_id, $tag_id ) {
		$this->assertSame(
			array( $list_id ),
			$this->crm->lists_for( $key ),
			sprintf( 'The contact for %s should belong to the configured list.', $key )
		);

		$this->assertSame(
			array( $tag_id ),
			$this->crm->tags_for( $key ),
			sprintf( 'The contact for %s should carry the configured tag.', $key )
		);
	}

	/**
	 * Every enquiry on the address holds that one contact's subscriber
	 * identifier and reads back `synced` (Requirements 6.5, 5.5).
	 *
	 * @param string $key   Lower-cased contact email.
	 * @param array  $group Enquiries on that address, in link order.
	 * @return int The subscriber identifier the address resolved to.
	 */
	private function assert_recorded_on_every_enquiry( $key, array $group ) {
		$subscriber_id = $this->crm->subscriber_id_for( $key );

		$this->assertGreaterThan( 0, $subscriber_id, sprintf( 'The contact for %s should hold an id.', $key ) );

		foreach ( $group as $enquiry ) {
			$this->assertSame(
				$subscriber_id,
				(int) $enquiry['result']['subscriber_id'],
				'Linkage should report the contact\'s subscriber identifier.'
			);

			$stored = EnquiryStore::find( (int) $enquiry['id'] );

			$this->assertIsArray( $stored, 'The linked enquiry should read back.' );
			$this->assertSame(
				$subscriber_id,
				(int) $stored['fluentcrm_subscriber_id'],
				sprintf( 'Enquiry %d should record the contact for %s.', (int) $enquiry['id'], $key )
			);
			$this->assertSame(
				ContactLinker::STATE_SYNCED,
				$stored['crm_sync_state'],
				sprintf( 'Enquiry %d should read back synced.', (int) $enquiry['id'] )
			);
		}

		return $subscriber_id;
	}

	/* ---------------------------------------------------------------------
	 * Reference implementation
	 * ------------------------------------------------------------------ */

	/**
	 * The contact data a sequence of upserts on one address leaves behind.
	 *
	 * An upsert writes the four linked fields and omits an empty value rather
	 * than sending it, so the last non-empty value per field wins and a field
	 * empty on every enquiry never appears at all. `email` is never omitted: it
	 * is the matching key.
	 *
	 * @param array $group Enquiries on one address, in link order.
	 * @return array<string,string>
	 */
	private static function expected_contact_data( array $group ) {
		$expected = array();

		foreach ( $group as $enquiry ) {
			foreach ( ContactLinker::LINKED_FIELDS as $field ) {
				$value = isset( $enquiry['fields'][ $field ] ) ? trim( (string) $enquiry['fields'][ $field ] ) : '';

				if ( '' === $value && 'email' !== $field ) {
					continue;
				}

				$expected[ $field ] = $value;
			}
		}

		return $expected;
	}

	/**
	 * Group the linked enquiries by the contact identity their email resolves
	 * to, preserving link order within each group.
	 *
	 * The key is the trimmed, lower-cased address, because that is the identity
	 * FluentCRM matches on: two enquiries whose addresses differ only in case
	 * are two enquiries on one contact.
	 *
	 * @param array $linked Linked enquiries, in link order.
	 * @return array<string,array>
	 */
	private static function group_by_contact( array $linked ) {
		$groups = array();

		foreach ( $linked as $enquiry ) {
			$key = strtolower( trim( (string) $enquiry['fields']['email'] ) );

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array();
			}

			$groups[ $key ][] = $enquiry;
		}

		return $groups;
	}

	/* ---------------------------------------------------------------------
	 * Writes
	 * ------------------------------------------------------------------ */

	/**
	 * Store every enquiry in the case and link each one, in order.
	 *
	 * @param array $emails    The email pool.
	 * @param array $enquiries The enquiry specifications.
	 * @return array Linked enquiries: id, submitted fields, and the link result.
	 */
	private function link_all( array $emails, array $enquiries ) {
		$linked = array();

		foreach ( $enquiries as $spec ) {
			$index  = (int) $spec['email_index'] % count( $emails );
			$fields = array(
				'first_name' => (string) $spec['first_name'],
				'last_name'  => (string) $spec['last_name'],
				'email'      => (string) $emails[ $index ],
				'phone'      => (string) $spec['phone'],
				'message'    => (string) $spec['message'],
			);

			$id     = $this->store( $fields );
			$result = ContactLinker::link( $id );

			$this->assertNotWPError( $result, 'Linking a stored enquiry should succeed.' );

			$linked[] = array(
				'id'     => $id,
				'fields' => $fields,
				'result' => $result,
			);
		}

		return $linked;
	}

	/**
	 * Store one enquiry through the store, and assert the write succeeded.
	 *
	 * One candidate date, so every write exercises the parent-plus-child path
	 * `create()` actually takes.
	 *
	 * @param array $fields Submitted contact and message values.
	 * @return int The identifier assigned.
	 */
	private function store( array $fields ) {
		$row = array_merge(
			array(
				'total_guests'      => 40,
				'status'            => 'new',
				'created_at'        => '2025-06-01 10:00:00',
				'updated_at'        => '2025-06-01 10:00:00',
				'status_changed_at' => '2025-06-01 10:00:00',
				'source'            => 'webhook:fixture',
			),
			$fields
		);

		$id = EnquiryStore::create(
			$row,
			array( Generators::date_at( 10 ) ),
			array( 'event_type' => array( 'wedding' ) ),
			$fields
		);

		$this->assertNotWPError( $id, 'Storing an enquiry should succeed.' );
		$this->assertGreaterThan( 0, $id, 'A stored enquiry should get a positive identifier.' );

		return (int) $id;
	}

	/* ---------------------------------------------------------------------
	 * Fixtures
	 * ------------------------------------------------------------------ */

	/**
	 * Empty the tables and the contact universe, and configure the list and tag.
	 *
	 * @param int $list_id Configured list identifier.
	 * @param int $tag_id  Configured tag identifier.
	 * @return void
	 */
	private function reset_universe( $list_id, $tag_id ) {
		global $wpdb;

		foreach ( array( 'enquiries', 'dates', 'terms', 'history' ) as $key ) {
			$table = Schema::table( $key );
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
		}

		update_option( 'meh_enquiry_list', (int) $list_id );
		update_option( 'meh_enquiry_tag', (int) $tag_id );

		$this->crm->reset()->will_succeed();
	}

	/**
	 * Drop every Enquiry Store table at the test prefix.
	 *
	 * @return void
	 */
	private function drop_tables() {
		global $wpdb;

		foreach ( array_keys( Schema::TABLES ) as $key ) {
			$table = Schema::table( $key );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}
	}
}
