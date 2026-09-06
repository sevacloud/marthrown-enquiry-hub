<?php
/**
 * Property 36: Migration is idempotent and preview is read-only.
 *
 * Feature: enquiry-data-layer, Property 36: For any population of FluentCRM
 * contacts, a preview run reports a count equal to the number of enquiries a
 * real run would create (0 when no contact is eligible) and leaves every Enquiry
 * Store table unchanged; and running the migration a second time creates no
 * additional enquiry, leaving the enquiry set equal to the set after the first
 * run.
 *
 * **Validates: Requirements 15.7, 15.8, 15.9**
 *
 * Six things about how the property is instantiated are worth stating plainly:
 *
 * - The claim is about what a run writes, so it runs against real tables. Whether
 *   a second run quietly inserted a duplicate enquiry, appended a history entry
 *   or re-stored a note is a question only the stored rows can answer, and the
 *   comparison is every column of every row of all six Enquiry Store tables
 *   rather than a count.
 * - "The count a real run would create" is checked both ways round. The test
 *   computes it independently from the generated population — eligible, holding
 *   an email, not already migrated — and asserts `would_create` equals that, and
 *   then asserts the run's `created` equals the same number. A preview that
 *   agreed with the run only because both were wrong the same way would fail the
 *   first assertion.
 * - The population reaches 0 eligible contacts, so Requirement 15.9's "report a
 *   count of 0" is a case the generator produces rather than a separate example:
 *   membership is drawn per contact over list, tag, both and elsewhere, and an
 *   empty population is generated too.
 * - Both idempotence checks are exercised. A contact's pre-migration state is
 *   generated as none, an already-stored enquiry carrying its subscriber id with
 *   `source = 'migration:fluentcrm'`, or that enquiry plus a ledger entry. And
 *   each further run may wipe `meh_migrated_subscribers` first, which is the
 *   lost-ledger case: with the ledger gone, only the stored enquiry can keep the
 *   run from re-migrating, so a `SKIP_LEDGER` that had been doing all the work
 *   shows up as a created enquiry.
 * - "Twice" is generated as one or two further runs, each preceded by a preview.
 *   Requirement 15.7 is about the second run, but a runner idempotent only once
 *   would satisfy the letter of it while writing on the third.
 * - The clock moves strictly forward before each further run, so any write a
 *   further run made would be stamped with an instant nothing in the tables
 *   already holds, and shows up as a changed value rather than as an identical
 *   row written twice.
 *
 * FluentCRM is stood in for by tests/fakes/FakeCrm.php: contacts go into its read
 * universe with `add_subscriber()`, which is separate from the write universe it
 * records, so the preview assertions can also say the contact population itself
 * came back unchanged.
 *
 * Like the other store tests, this one works against real tables at its own
 * prefix, because `Schema::install()` verifies each table through `SHOW TABLES`,
 * which cannot see the test case's temporary tables.
 *
 * @package MarthrownEnquiryHub
 */

use Eris\TestTrait;
use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\ContactLinker;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\MigrationRunner;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Fakes\FakeCrm;
use MarthrownEnquiryHub\Tests\Iterations;

/**
 * Class MigrationIdempotencePropertyTest
 */
class MigrationIdempotencePropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables can never collide with a real
	 * Enquiry Store table sitting in the same test database.
	 */
	const PREFIX_SEGMENT = 'mehmi_';

	/**
	 * Configured enquiry list identifier.
	 */
	const LIST_ID = 7;

	/**
	 * Configured enquiry tag identifier.
	 */
	const TAG_ID = 12;

	/**
	 * The instant the first run of every iteration happens at.
	 */
	const RUN_AT = '2025-07-14 09:00:00';

	/**
	 * Creation time the pre-migrated enquiries are seeded with.
	 */
	const SEEDED_AT = '2024-11-02 10:30:00';

	/**
	 * Most contacts one population holds.
	 *
	 * Small on purpose: the interesting variable is the mix of membership,
	 * emptiness and pre-migration state, and five contacts already reach every
	 * mix that matters while leaving the iteration count affordable against a
	 * real database.
	 */
	const MAX_CONTACTS = 5;

	/**
	 * Further runs one iteration performs, beyond the first.
	 */
	const MAX_REPEATS = 2;

	/**
	 * Email addresses the population draws from.
	 *
	 * Fewer addresses than contacts, deliberately: two contacts sharing an email
	 * are two contacts, and a runner that deduplicated by email rather than by
	 * subscriber identifier would migrate fewer enquiries than the preview
	 * promised.
	 *
	 * @var string[]
	 */
	const EMAILS = array( 'ada@example.com', 'bob@example.com', 'cleo@example.com' );

	/**
	 * Where a contact sits relative to the configured list and tag.
	 *
	 * `elsewhere` is a contact in neither, so a population holding only those
	 * makes the eligible count 0 (Requirement 15.9).
	 *
	 * @var string[]
	 */
	const MEMBERSHIPS = array( 'list', 'tag', 'both', 'elsewhere' );

	/**
	 * Legacy `meh_enquiry_status` values, recognised and not.
	 *
	 * @var string[]
	 */
	const LEGACY_STATUSES = array( 'new', 'replied', 'quoted', 'converted', 'closed', 'archived', '' );

	/**
	 * Contact creation times, including the absent one.
	 *
	 * @var string[]
	 */
	const CREATED_TIMES = array( '2023-01-05 08:15:00', '2024-06-30 23:59:59', '2025-02-14 12:00:00', '' );

	/**
	 * How much of a contact was already migrated before the first run.
	 *
	 * `stored` is an enquiry carrying the subscriber id with the migration
	 * source and no ledger entry — the state a site is in after losing the
	 * ledger. `stored_and_ledger` is both, the normal state after a run. Either
	 * one alone has to be enough to skip the contact (Requirement 15.7).
	 *
	 * @var string[]
	 */
	const PRE_STATES = array( 'none', 'none', 'stored', 'stored_and_ledger' );

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
		require_once MEH_INCLUDES_DIR . 'class-note-service.php';
		require_once MEH_INCLUDES_DIR . 'class-staging-marker.php';
		require_once MEH_INCLUDES_DIR . 'class-contact-linker.php';
		require_once MEH_INCLUDES_DIR . 'class-migration-runner.php';
	}

	public function set_up() {
		parent::set_up();

		global $wpdb;

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->original_prefix = $wpdb->prefix;
		$wpdb->prefix          = $this->original_prefix . self::PREFIX_SEGMENT;

		$this->drop_tables();
		$this->assertTrue( Schema::install(), 'The fixture install should succeed.' );

		update_option( 'meh_enquiry_list', self::LIST_ID );
		update_option( 'meh_enquiry_tag', self::TAG_ID );

		$this->crm = FakeCrm::install();
	}

	public function tear_down() {
		global $wpdb;

		FakeCrm::uninstall();
		$this->crm = null;

		delete_option( 'meh_enquiry_list' );
		delete_option( 'meh_enquiry_tag' );
		delete_option( MigrationRunner::LEDGER_OPTION );
		delete_option( MigrationRunner::COMPLETED_OPTION );

		Clock::unfreeze();
		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/**
	 * Feature: enquiry-data-layer, Property 36: Migration is idempotent and
	 * preview is read-only.
	 *
	 * **Validates: Requirements 15.7, 15.8, 15.9**
	 *
	 * @eris-shrink 10
	 */
	public function test_migration_is_idempotent_and_preview_is_read_only() {
		$this->limitTo( Iterations::count( 75 ) )
			->forAll( self::unshrunk( self::scenario() ) )
			->then(
				function ( array $scenario ) {
					$population = self::resolve( $scenario['population'] );

					$this->reset_world();
					$this->seed_pre_migrated( $population );
					$this->seed_contacts( $population );

					$this->assertTrue(
						Clock::freeze( self::RUN_AT ),
						'The clock should freeze under the test harness.'
					);

					// Requirements 15.8, 15.9: the preview counts, and writes
					// nothing while doing it.
					$preview = $this->assert_preview_writes_nothing( 'the first preview' );

					$this->assertSame(
						self::expected_eligible( $population ),
						$preview['eligible'],
						'The preview should account for every contact in the list or holding the tag.'
					);
					$this->assertSame(
						self::expected_creations( $population ),
						$preview['would_create'],
						'The preview count should be the number of enquiries a run would create.'
					);

					$result = MigrationRunner::run();

					$this->assertSame(
						$preview['would_create'],
						$result['created'],
						'The run should create exactly what the preview promised.'
					);

					// The state every further run has to leave exactly as it is.
					$rows = $this->tables();
					$at   = Clock::now();

					foreach ( $scenario['repeats'] as $index => $repeat ) {
						/*
						 * Strictly later than the previous run, so anything a
						 * further run wrote would carry an instant nothing in the
						 * tables already holds.
						 */
						$at = Clock::offset( (int) $repeat['gap'], $at );
						$this->assertTrue( Clock::freeze( $at ) );

						if ( $repeat['wipe'] ) {
							// The lost-ledger case: only an already-stored
							// enquiry can now keep the run from re-migrating.
							delete_option( MigrationRunner::LEDGER_OPTION );
						}

						$this->assert_further_run_writes_nothing( $rows, (int) $index + 2 );
					}
				}
			);
	}

	/* ---------------------------------------------------------------------
	 * Assertions
	 * ------------------------------------------------------------------ */

	/**
	 * Run a preview and assert it changed nothing at all (Requirement 15.8).
	 *
	 * The ledger and the completion time are checked alongside the tables,
	 * because a preview that recorded either would have told a later run that
	 * work it never did was done. The contact population is checked too: a
	 * preview reads FluentCRM and nothing more.
	 *
	 * @param string $context Which preview this was, for the failure message.
	 * @return array The preview result.
	 */
	private function assert_preview_writes_nothing( $context ) {
		$tables      = $this->tables();
		$ledger      = MigrationRunner::ledger();
		$completed   = MigrationRunner::completed_at();
		$subscribers = $this->crm->subscribers();

		$preview = MigrationRunner::preview();

		$this->assertSame(
			$tables,
			$this->tables(),
			'A preview should leave every Enquiry Store table byte-identical: ' . $context
		);
		$this->assertSame(
			$ledger,
			MigrationRunner::ledger(),
			'A preview should not write the ledger: ' . $context
		);
		$this->assertSame(
			$completed,
			MigrationRunner::completed_at(),
			'A preview should not record a completion time: ' . $context
		);
		$this->assertSame(
			$subscribers,
			$this->crm->subscribers(),
			'A preview should leave the contact population unchanged: ' . $context
		);
		$this->assertSame(
			array(),
			$this->crm->create_or_update_calls(),
			'A preview should write no contact: ' . $context
		);

		return $preview;
	}

	/**
	 * Everything a further run has to leave exactly as it found it
	 * (Requirement 15.7).
	 *
	 * @param array $rows    Every Enquiry Store table after the first run.
	 * @param int   $attempt Which run this is, counting from 1.
	 * @return void
	 */
	private function assert_further_run_writes_nothing( array $rows, $attempt ) {
		$context = sprintf( 'run %d', $attempt );

		$preview = $this->assert_preview_writes_nothing( $context );

		$this->assertSame(
			0,
			$preview['would_create'],
			'Every migrated contact should already be accounted for: ' . $context
		);

		$result = MigrationRunner::run();

		$this->assertSame( 0, $result['created'], 'A further run should create no enquiry: ' . $context );
		$this->assertSame(
			$result['eligible'],
			$result['skipped'],
			'A further run should skip every contact it read: ' . $context
		);
		$this->assertCount(
			$result['skipped'],
			$result['reasons'],
			'A further run should record one reason per skip: ' . $context
		);

		foreach ( $result['reasons'] as $skip ) {
			$this->assertContains(
				$skip['reason'],
				MigrationRunner::SKIP_REASONS,
				'Every skip should carry a recognised reason: ' . $context
			);
		}

		$this->assertSame(
			$rows,
			$this->tables(),
			'A further run should leave every Enquiry Store table byte-identical: ' . $context
		);
	}

	/* ---------------------------------------------------------------------
	 * The reference count
	 * ------------------------------------------------------------------ */

	/**
	 * Contacts in the configured list or holding the configured tag.
	 *
	 * @param array $population Resolved population.
	 * @return int
	 */
	private static function expected_eligible( array $population ) {
		$eligible = 0;

		foreach ( $population as $contact ) {
			if ( 'elsewhere' !== $contact['membership'] ) {
				++$eligible;
			}
		}

		return $eligible;
	}

	/**
	 * Enquiries a run would create, reckoned independently of the runner.
	 *
	 * Eligible, holding an email address, and not already migrated by either
	 * check. Zero when the population holds no such contact (Requirement 15.9).
	 *
	 * @param array $population Resolved population.
	 * @return int
	 */
	private static function expected_creations( array $population ) {
		$creations = 0;

		foreach ( $population as $contact ) {
			if ( 'elsewhere' === $contact['membership'] ) {
				continue;
			}

			if ( '' === $contact['email'] ) {
				continue;
			}

			if ( 'none' !== $contact['pre'] ) {
				continue;
			}

			++$creations;
		}

		return $creations;
	}

	/* ---------------------------------------------------------------------
	 * Generators
	 * ------------------------------------------------------------------ */

	/**
	 * One scenario: a contact population and the further runs performed on it.
	 *
	 * @return \Eris\Generator
	 */
	protected static function scenario() {
		return \Eris\Generators::associative(
			array(
				'population' => self::population(),
				'repeats'    => \Eris\Generators::bind(
					\Eris\Generators::choose( 1, self::MAX_REPEATS ),
					function ( $count ) {
						return \Eris\Generators::vector( (int) $count, self::repeat() );
					}
				),
			)
		);
	}

	/**
	 * A population of 0 to MAX_CONTACTS contacts.
	 *
	 * @return \Eris\Generator
	 */
	protected static function population() {
		return \Eris\Generators::bind(
			\Eris\Generators::choose( 0, self::MAX_CONTACTS ),
			function ( $count ) {
				if ( $count < 1 ) {
					return \Eris\Generators::constant( array() );
				}

				return \Eris\Generators::vector( (int) $count, self::contact() );
			}
		);
	}

	/**
	 * One contact, as drawn rather than as seeded.
	 *
	 * The email is drawn as a slot into self::EMAILS, and `has_email` decides
	 * whether the contact carries it at all: a contact with no email is skipped
	 * by both a preview and a run, and so must be counted by neither.
	 *
	 * @return \Eris\Generator
	 */
	protected static function contact() {
		return \Eris\Generators::associative(
			array(
				'membership' => \Eris\Generators::elements( self::MEMBERSHIPS ),
				'slot'       => \Eris\Generators::choose( 0, count( self::EMAILS ) - 1 ),
				'has_email'  => \Eris\Generators::elements( array( true, true, true, false ) ),
				'legacy'     => \Eris\Generators::elements( self::LEGACY_STATUSES ),
				'created_at' => \Eris\Generators::elements( self::CREATED_TIMES ),
				'notes'      => \Eris\Generators::choose( 0, 2 ),
				'pre'        => \Eris\Generators::elements( self::PRE_STATES ),
			)
		);
	}

	/**
	 * One further run: how long after the previous one, and whether the ledger
	 * is lost first.
	 *
	 * @return \Eris\Generator
	 */
	protected static function repeat() {
		return \Eris\Generators::associative(
			array(
				'gap'  => \Eris\Generators::oneOf(
					\Eris\Generators::constant( 1 ),
					\Eris\Generators::choose( 1, 3600 ),
					\Eris\Generators::choose( 1, 30 * DAY_IN_SECONDS )
				),
				'wipe' => \Eris\Generators::elements( array( true, false ) ),
			)
		);
	}

	/**
	 * The same generator, with shrinking turned off.
	 *
	 * Eris shrinks a wide structure by taking the cartesian product of every
	 * element's shrink options, so the cost of one shrink step is exponential in
	 * the number of generated values. A scenario holds up to five contacts of
	 * seven drawn values each, plus up to two repeats, which puts that product
	 * beyond what fits in memory: a failing iteration would report an
	 * out-of-memory fatal instead of the counterexample. The `@eris-shrink` time
	 * limit does not help, because the explosion happens inside a single shrink
	 * call.
	 *
	 * Binding the drawn value to a constant generator makes shrinking a no-op, so
	 * a failure reports the scenario as generated, together with the assertion's
	 * own diff and the `ERIS_SEED` line that reproduces the run exactly.
	 *
	 * @param \Eris\Generator $generator Generator to draw from.
	 * @return \Eris\Generator
	 */
	protected static function unshrunk( \Eris\Generator $generator ) {
		return \Eris\Generators::bind(
			$generator,
			function ( $drawn ) {
				return \Eris\Generators::constant( $drawn );
			}
		);
	}

	/* ---------------------------------------------------------------------
	 * Fixtures
	 * ------------------------------------------------------------------ */

	/**
	 * Turn the drawn population into the contacts actually seeded.
	 *
	 * Subscriber identifiers come from the position in the population, so every
	 * contact holds a distinct one and the fake never has to invent any. A
	 * contact carrying no email cannot be pre-migrated, because there would be
	 * no enquiry to stand in for it, so its pre-state collapses to none.
	 *
	 * @param array $drawn Population as generated.
	 * @return array<int,array>
	 */
	private static function resolve( array $drawn ) {
		$population = array();

		foreach ( $drawn as $index => $contact ) {
			$email = $contact['has_email'] ? self::EMAILS[ (int) $contact['slot'] ] : '';
			$notes = array();

			for ( $note = 0; $note < (int) $contact['notes']; $note++ ) {
				$notes[] = array(
					'description' => sprintf( 'Note %d on contact %d.', $note + 1, (int) $index + 1 ),
					'created_at'  => sprintf( '2024-0%d-1%d 14:00:00', ( $note % 8 ) + 1, $note ),
				);
			}

			$population[] = array(
				'subscriber_id' => (int) $index + 1,
				'membership'    => (string) $contact['membership'],
				'email'         => $email,
				'legacy'        => (string) $contact['legacy'],
				'created_at'    => (string) $contact['created_at'],
				'notes'         => $notes,
				'pre'           => '' === $email ? 'none' : (string) $contact['pre'],
			);
		}

		return $population;
	}

	/**
	 * Seed the enquiries and ledger entries that predate the first run.
	 *
	 * @param array $population Resolved population.
	 * @return void
	 */
	private function seed_pre_migrated( array $population ) {
		$ledger = array();

		foreach ( $population as $contact ) {
			if ( 'none' === $contact['pre'] ) {
				continue;
			}

			$id = EnquiryStore::create(
				array(
					'first_name'              => 'Prior',
					'last_name'               => 'Contact',
					'email'                   => $contact['email'],
					'status'                  => 'new',
					'fluentcrm_subscriber_id' => (int) $contact['subscriber_id'],
					'crm_sync_state'          => ContactLinker::STATE_SYNCED,
					'created_at'              => self::SEEDED_AT,
					'updated_at'              => self::SEEDED_AT,
					'status_changed_at'       => self::SEEDED_AT,
					'source'                  => MigrationRunner::SOURCE,
				)
			);

			$this->assertIsInt( $id, 'Seeding an already-migrated enquiry should succeed.' );

			if ( 'stored_and_ledger' === $contact['pre'] ) {
				$ledger[] = (int) $contact['subscriber_id'];
			}
		}

		if ( $ledger ) {
			update_option( MigrationRunner::LEDGER_OPTION, $ledger );
		}
	}

	/**
	 * Seed the contact population into the fake's read universe.
	 *
	 * @param array $population Resolved population.
	 * @return void
	 */
	private function seed_contacts( array $population ) {
		foreach ( $population as $contact ) {
			$lists = array();
			$tags  = array();

			switch ( $contact['membership'] ) {
				case 'list':
					$lists = array( self::LIST_ID );
					break;
				case 'tag':
					$tags = array( self::TAG_ID );
					break;
				case 'both':
					$lists = array( self::LIST_ID );
					$tags  = array( self::TAG_ID );
					break;
				default:
					// In neither the configured list nor the configured tag.
					$lists = array( self::LIST_ID + 900 );
					break;
			}

			$this->crm->add_subscriber(
				array(
					'id'            => (int) $contact['subscriber_id'],
					'first_name'    => 'Test',
					'last_name'     => 'Contact',
					'email'         => $contact['email'],
					'phone'         => '01142551122',
					'created_at'    => $contact['created_at'],
					'lists'         => $lists,
					'tags'          => $tags,
					'custom_fields' => array( MigrationRunner::STATUS_FIELD => $contact['legacy'] ),
					'notes'         => $contact['notes'],
				)
			);
		}
	}

	/**
	 * Return the world to its pristine state between iterations.
	 *
	 * @return void
	 */
	private function reset_world() {
		global $wpdb;

		foreach ( array_keys( Schema::TABLES ) as $key ) {
			$table = Schema::table( $key );

			// `DELETE` rather than `TRUNCATE`, so identifiers keep climbing and a
			// stale identifier can never be mistaken for a fresh one.
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
		}

		delete_option( MigrationRunner::LEDGER_OPTION );
		delete_option( MigrationRunner::COMPLETED_OPTION );

		$this->crm->reset();
	}

	/* ---------------------------------------------------------------------
	 * Database helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Every column of every row of every Enquiry Store table.
	 *
	 * The comparison behind both halves of the property. A count would let a
	 * further run rewrite a row it had already written; this does not.
	 *
	 * @return array<string,array>
	 */
	private function tables() {
		global $wpdb;

		$snapshot = array();

		foreach ( array_keys( Schema::TABLES ) as $key ) {
			$table            = Schema::table( $key );
			$wpdb->last_error = '';

			// phpcs:ignore WordPress.DB
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );

			$this->assertSame( '', (string) $wpdb->last_error, 'The snapshot read should run without error.' );

			$snapshot[ $key ] = is_array( $rows ) ? $rows : array();
		}

		return $snapshot;
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
