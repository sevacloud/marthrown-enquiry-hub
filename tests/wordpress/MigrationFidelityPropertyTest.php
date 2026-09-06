<?php
/**
 * Property 35: Migration reproduces contacts faithfully without touching them.
 *
 * Feature: enquiry-data-layer, Property 35: For any population of FluentCRM
 * contacts, any list and tag configuration, and any per-contact set of
 * subscriber notes and legacy status values, a migration run creates exactly one
 * enquiry per eligible contact holding that contact's `first_name`, `last_name`,
 * `email`, `phone`, creation time and subscriber identifier, with the legacy
 * status mapped as `new`→`new`, `replied`→`contacted`, `quoted`→`quoted`,
 * `converted`→`converted`, `closed`→`closed` and any unrecognised or absent
 * value mapped to `new`, and one enquiry note per subscriber note holding that
 * note's body and creation time; the reported created and skipped counts sum to
 * the eligible contact count with a reason recorded for every skip; the
 * completion time and the migrated subscriber identifiers are recorded; and
 * every FluentCRM contact record, list membership and tag is unchanged.
 *
 * **Validates: Requirements 15.1, 15.2, 15.3, 15.4, 15.5, 15.6, 15.10, 15.11, 15.12**
 *
 * Four notes on how the property is instantiated:
 *
 * - It runs against real tables, because the claim is about what a run *stored*:
 *   one enquiry row per eligible contact, one note row per subscriber note, and
 *   the two recorded options. Counting what the runner returned would pass while
 *   a second row was quietly written beside the first.
 * - FluentCRM is the fake's read universe, seeded through `add_subscriber()`.
 *   "Without touching them" is checked as an identity: the whole subscriber
 *   snapshot is taken before the run and compared with the snapshot after, and
 *   the fake's separate *write* universe — the contacts and the call log
 *   `createOrUpdate()`, `attachLists()` and `attachTags()` record — is asserted
 *   to be empty. A write would show up in one or the other whatever shape it
 *   took.
 * - The status map is asserted against the test's own copy of it rather than
 *   against `MigrationRunner::STATUS_MAP`, so a change to the mapping in the
 *   code under test fails here instead of being mirrored into the oracle. The
 *   legacy values are drawn with their case and padding varied, and the absent
 *   custom field is one of the draws, which is the `new` default of Requirement
 *   15.5.
 * - The eligible population is quantified over as well as its contents: the list
 *   only, tag only, both and neither configurations are all reachable, and a
 *   contact may sit in the list, hold the tag, do both, or belong elsewhere. A
 *   contact carrying no email is eligible but unstorable, which is how the skip
 *   accounting of Requirement 15.10 is exercised.
 *
 * @package MarthrownEnquiryHub
 */

use Eris\TestTrait;
use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\MigrationRunner;
use MarthrownEnquiryHub\NoteService;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Fakes\FakeCrm;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;

/**
 * Class MigrationFidelityPropertyTest
 */
class MigrationFidelityPropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables can never collide with a real
	 * Enquiry Store table sitting in the same test database.
	 */
	const PREFIX_SEGMENT = 'mehmf_';

	/** The configured enquiry list identifier. */
	const LIST_ID = 7;

	/** The configured enquiry tag identifier. */
	const TAG_ID = 12;

	/** A list the migration is not configured to read. */
	const OTHER_LIST = 99;

	/** A tag the migration is not configured to read. */
	const OTHER_TAG = 98;

	/** The instant the clock is frozen at for every iteration. */
	const NOW = '2025-07-14 09:00:00';

	/** First subscriber identifier a population uses; one per contact from here. */
	const FIRST_SUBSCRIBER_ID = 41;

	/**
	 * Most contacts one population holds.
	 *
	 * Small on purpose: the interesting variable is the mix of membership, status
	 * and notes, and four contacts already reach every mix that matters while
	 * leaving the iteration count affordable against a real database.
	 */
	const MAX_CONTACTS = 4;

	/** Most subscriber notes one contact holds. */
	const MAX_NOTES = 2;

	/**
	 * The legacy status map, as this test believes it to be (Requirement 15.4).
	 *
	 * Deliberately its own copy rather than a read of
	 * `MigrationRunner::STATUS_MAP`: an oracle taking the mapping from the code
	 * under test could not catch that code changing it.
	 *
	 * @var array<string,string>
	 */
	const EXPECTED_MAP = array(
		'new'       => 'new',
		'replied'   => 'contacted',
		'quoted'    => 'quoted',
		'converted' => 'converted',
		'closed'    => 'closed',
	);

	/** The status an unrecognised or absent legacy value maps to (Requirement 15.5). */
	const DEFAULT_STATUS = 'new';

	/**
	 * Note bodies a generated note draws from, beyond the adversarial set.
	 *
	 * Every value is non-empty, carries no angle bracket and no leading or
	 * trailing whitespace, so wrapping it in markup and stripping the markup back
	 * off returns exactly the value.
	 *
	 * @var string[]
	 */
	const NOTE_BODIES = array(
		'Called back, wants the barn.',
		'Quote sent.',
		'Zoë asked about parking',
		'名前',
		'party🎉 confirmed for the Saturday',
		"O'Brien rang twice",
	);

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

		Clock::freeze( self::NOW );
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
	 * Feature: enquiry-data-layer, Property 35: Migration reproduces contacts
	 * faithfully without touching them.
	 *
	 * **Validates: Requirements 15.1, 15.2, 15.3, 15.4, 15.5, 15.6, 15.10, 15.11, 15.12**
	 *
	 * @eris-shrink 10
	 */
	public function test_migration_reproduces_contacts_without_touching_them() {
		$this->limitTo( Iterations::count( 100 ) )
			->forAll( self::unshrunk( self::scenario() ) )
			->then(
				function ( array $scenario ) {
					$this->reset_state();
					$this->configure( $scenario['config'] );

					$contacts = $this->seed_population( $scenario['contacts'], $scenario['config'] );

					// The whole CRM as it stands, before a run reads it.
					$before = $this->crm->subscribers();

					$result = MigrationRunner::run();

					$this->assert_counts_account_for_every_contact( $result, $contacts );
					$this->assert_each_contact_was_reproduced( $result, $contacts );
					$this->assert_ledger_and_completion_recorded( $result, $contacts );
					$this->assert_crm_untouched( $before );
				}
			);
	}

	/* ---------------------------------------------------------------------
	 * Assertions
	 * ------------------------------------------------------------------ */

	/**
	 * Created and skipped account for every eligible contact, with a reason and
	 * a detail per skip (Requirements 15.1, 15.10).
	 *
	 * @param array $result   What the run reported.
	 * @param array $contacts Seeded contacts, each carrying its expectation.
	 * @return void
	 */
	private function assert_counts_account_for_every_contact( array $result, array $contacts ) {
		$eligible = self::eligible( $contacts );
		$storable = self::storable( $contacts );

		// Requirement 15.1: the eligible population is the union of the
		// configured list and the configured tag, and nothing else.
		$this->assertSame(
			count( $eligible ),
			(int) $result['eligible'],
			'The run should read exactly the contacts in the configured list or holding the configured tag.'
		);

		$this->assertSame(
			count( $storable ),
			(int) $result['created'],
			'The run should create one enquiry per eligible contact that can be stored.'
		);

		// Requirement 15.10: every eligible contact is accounted for.
		$this->assertSame(
			(int) $result['eligible'],
			(int) $result['created'] + (int) $result['skipped'],
			'Created and skipped should sum to the eligible contact count.'
		);

		$this->assertCount(
			(int) $result['skipped'],
			$result['reasons'],
			'Every skip should be reported.'
		);

		foreach ( $result['reasons'] as $skip ) {
			$this->assertNotSame( '', (string) $skip['reason'], 'Every skip should carry a reason.' );
			$this->assertNotSame( '', (string) $skip['detail'], 'Every skip should carry a detail.' );
		}

		// The only skip this population can provoke is the emailless contact, so
		// the reasons are checkable individually rather than only in the count.
		$this->assertSame(
			self::subscriber_ids( self::unstorable( $contacts ) ),
			self::skipped_ids( $result['reasons'] ),
			'The skipped contacts should be exactly the eligible contacts carrying no email.'
		);

		foreach ( $result['reasons'] as $skip ) {
			$this->assertSame(
				MigrationRunner::SKIP_NO_EMAIL,
				$skip['reason'],
				'A contact carrying no email should be skipped for that reason.'
			);
		}

		$this->assertSame(
			count( $storable ),
			$this->enquiry_count(),
			'The store should hold exactly one enquiry per created contact and no other.'
		);
	}

	/**
	 * Every storable contact came back as one enquiry carrying its own values,
	 * and no other contact produced one at all (Requirements 15.2, 15.3, 15.4,
	 * 15.5, 15.6).
	 *
	 * @param array $result   What the run reported.
	 * @param array $contacts Seeded contacts, each carrying its expectation.
	 * @return void
	 */
	private function assert_each_contact_was_reproduced( array $result, array $contacts ) {
		$this->assertCount(
			(int) $result['created'],
			$result['enquiry_ids'],
			'The run should report one identifier per created enquiry.'
		);

		foreach ( $contacts as $contact ) {
			$ids = $this->enquiry_ids_for( $contact['subscriber_id'] );

			if ( ! $contact['storable'] ) {
				$this->assertSame(
					array(),
					$ids,
					'Contact ' . $contact['subscriber_id'] . ' should have produced no enquiry.'
				);

				continue;
			}

			// Requirement 15.2: exactly one enquiry, not two.
			$this->assertCount(
				1,
				$ids,
				'Contact ' . $contact['subscriber_id'] . ' should have produced exactly one enquiry.'
			);

			$this->assertContains( $ids[0], array_map( 'intval', $result['enquiry_ids'] ), 'The created enquiry should be reported.' );

			$enquiry = EnquiryStore::find( $ids[0] );

			$this->assertIsArray( $enquiry, 'The migrated enquiry should read back.' );

			// Requirement 15.2: the contact's own values and creation time.
			$this->assertSame( $contact['expected']['first_name'], $enquiry['first_name'], 'The given name should be reproduced.' );
			$this->assertSame( $contact['expected']['last_name'], $enquiry['last_name'], 'The family name should be reproduced.' );
			$this->assertSame( $contact['expected']['email'], $enquiry['email'], 'The email address should be reproduced.' );
			$this->assertSame( $contact['expected']['phone'], $enquiry['phone'], 'The telephone number should be reproduced.' );
			$this->assertSame( $contact['expected']['created_at'], $enquiry['created_at'], 'The creation time should be reproduced.' );

			// Requirement 15.3: the subscriber identifier is recorded.
			$this->assertSame(
				$contact['subscriber_id'],
				(int) $enquiry['fluentcrm_subscriber_id'],
				'The subscriber identifier should be recorded on the enquiry.'
			);

			// Requirements 15.4 and 15.5: the legacy status, mapped.
			$this->assertSame(
				$contact['expected']['status'],
				$enquiry['status'],
				'The legacy status should be mapped onto the enquiry status.'
			);

			// Requirement 15.6: one enquiry note per subscriber note, each
			// holding that note's body and its own creation time.
			$this->assertSame(
				$contact['expected']['notes'],
				self::note_pairs( NoteService::for_enquiry( $ids[0] ) ),
				'Each subscriber note should be reproduced with its body and creation time.'
			);
		}
	}

	/**
	 * The completion time and the migrated subscriber identifiers are recorded
	 * (Requirement 15.12).
	 *
	 * @param array $result   What the run reported.
	 * @param array $contacts Seeded contacts, each carrying its expectation.
	 * @return void
	 */
	private function assert_ledger_and_completion_recorded( array $result, array $contacts ) {
		$expected = self::subscriber_ids( self::storable( $contacts ) );

		$this->assertSame( self::NOW, $result['completed_at'], 'The run should report its completion time.' );
		$this->assertSame( self::NOW, MigrationRunner::completed_at(), 'The completion time should be recorded.' );

		$this->assertSame( $expected, MigrationRunner::ledger(), 'The migrated subscriber identifiers should be recorded.' );
		$this->assertSame( $expected, array_map( 'intval', $result['subscriber_ids'] ), 'The run should report the recorded ledger.' );
	}

	/**
	 * Every FluentCRM contact record, list membership and tag is unchanged, and
	 * nothing was written at all (Requirement 15.11).
	 *
	 * @param array $before The subscriber universe before the run.
	 * @return void
	 */
	private function assert_crm_untouched( array $before ) {
		$this->assertSame(
			$before,
			$this->crm->subscribers(),
			'Every contact record, list membership and tag should be exactly as it was.'
		);

		$this->assertSame( array(), $this->crm->create_or_update_calls(), 'No contact should be written.' );
		$this->assertSame( array(), $this->crm->list_calls(), 'No list should be attached.' );
		$this->assertSame( array(), $this->crm->tag_calls(), 'No tag should be attached.' );
		$this->assertSame( 0, $this->crm->contact_count(), 'The write universe should stay empty.' );
	}

	/* ---------------------------------------------------------------------
	 * Generators
	 * ------------------------------------------------------------------ */

	/**
	 * A configuration and a population of contacts to migrate.
	 *
	 * All four configurations are reachable, `neither` — which makes the whole
	 * population ineligible — less often than the three that read something, so
	 * the iterations are mostly spent on populations that migrate.
	 *
	 * @return \Eris\Generator
	 */
	protected static function scenario() {
		return \Eris\Generators::associative(
			array(
				'config'   => \Eris\Generators::elements(
					array( 'list', 'list', 'tag', 'tag', 'both', 'both', 'neither' )
				),
				'contacts' => self::population(),
			)
		);
	}

	/**
	 * A population of 0 to MAX_CONTACTS contacts.
	 *
	 * The empty population is reachable, which is the "nothing to migrate" case.
	 *
	 * @return \Eris\Generator
	 */
	protected static function population() {
		return \Eris\Generators::bind(
			\Eris\Generators::choose( 0, self::MAX_CONTACTS ),
			function ( $count ) {
				if ( (int) $count < 1 ) {
					return \Eris\Generators::constant( array() );
				}

				return \Eris\Generators::vector( (int) $count, self::contact() );
			}
		);
	}

	/**
	 * One FluentCRM contact: where it sits, what it holds, and its notes.
	 *
	 * @return \Eris\Generator
	 */
	protected static function contact() {
		return \Eris\Generators::associative(
			array(
				'membership' => \Eris\Generators::elements(
					array( 'list', 'list', 'tag', 'tag', 'both', 'both', 'elsewhere' )
				),
				'first_name' => Generators::first_name(),
				'last_name'  => Generators::last_name(),
				'email'      => self::contact_email(),
				'phone'      => Generators::phone_or_empty(),
				'created_at' => self::timestamp(),
				'status'     => self::legacy_status(),
				'notes'      => self::notes(),
			)
		);
	}

	/**
	 * A contact email: a valid address, or a value that is empty once trimmed.
	 *
	 * The blank cases are the eligible-but-unstorable contact, which is what
	 * makes the skip accounting of Requirement 15.10 non-trivial.
	 *
	 * @return \Eris\Generator
	 */
	protected static function contact_email() {
		return \Eris\Generators::oneOf(
			Generators::email(),
			Generators::email(),
			Generators::email(),
			\Eris\Generators::elements( array( '', '   ' ) )
		);
	}

	/**
	 * A legacy `meh_enquiry_status` value, or null for a contact holding no such
	 * custom field at all (Requirement 15.5).
	 *
	 * Case and padding are varied on purpose: the mapping is about the value, not
	 * about how it happens to be stored.
	 *
	 * @return \Eris\Generator
	 */
	protected static function legacy_status() {
		return \Eris\Generators::oneOf(
			\Eris\Generators::elements( array_keys( self::EXPECTED_MAP ) ),
			\Eris\Generators::elements( array( 'NEW', ' Quoted ', 'Replied', "closed\t" ) ),
			\Eris\Generators::elements( array( '', 'archived', 'on-hold', 'contacted-maybe', '0' ) ),
			\Eris\Generators::constant( null )
		);
	}

	/**
	 * A set of 0 to MAX_NOTES subscriber notes.
	 *
	 * @return \Eris\Generator
	 */
	protected static function notes() {
		return \Eris\Generators::bind(
			\Eris\Generators::choose( 0, self::MAX_NOTES ),
			function ( $count ) {
				if ( (int) $count < 1 ) {
					return \Eris\Generators::constant( array() );
				}

				return \Eris\Generators::vector( (int) $count, self::note() );
			}
		);
	}

	/**
	 * One subscriber note: its text, the shape it is stored in, and its own
	 * creation time.
	 *
	 * The four shapes are the ones FluentCRM actually holds: plain text in
	 * `description`, marked-up text in `description`, text in `title` with no
	 * description, and a note carrying neither.
	 *
	 * @return \Eris\Generator
	 */
	protected static function note() {
		return \Eris\Generators::associative(
			array(
				'shape'      => \Eris\Generators::elements( array( 'plain', 'markup', 'title', 'blank' ) ),
				'body'       => \Eris\Generators::oneOf(
					\Eris\Generators::elements( self::NOTE_BODIES ),
					Generators::adversarial_string()
				),
				'markup'     => Generators::markup(),
				'created_at' => self::timestamp(),
			)
		);
	}

	/**
	 * A MySQL `DATETIME` string, or the empty string for a record holding none.
	 *
	 * @return \Eris\Generator
	 */
	protected static function timestamp() {
		return \Eris\Generators::oneOf(
			\Eris\Generators::map(
				function ( $minutes ) {
					return self::datetime_at( (int) $minutes );
				},
				\Eris\Generators::choose( 0, 900000 )
			),
			\Eris\Generators::constant( '' )
		);
	}

	/**
	 * The same generator, with shrinking turned off.
	 *
	 * Eris shrinks a wide structure by taking the cartesian product of every
	 * element's shrink options, so the cost of one shrink step is exponential in
	 * the number of generated values. A population holds up to four contacts of
	 * eight drawn values each, two of them nested note sets, which puts that
	 * product far beyond what fits in memory: a failing iteration would report an
	 * out-of-memory fatal instead of the counterexample.
	 *
	 * Binding the drawn value to a constant generator makes shrinking a no-op, so
	 * a failure reports the population as generated, together with the assertion's
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
	 * Seed a whole population into the CRM's read universe, and work out what
	 * each contact should produce.
	 *
	 * @param array  $population Contacts as generated.
	 * @param string $config     Which of the list and the tag is configured.
	 * @return array<int,array> Seeded contacts, each carrying its expectation.
	 */
	private function seed_population( array $population, $config ) {
		$contacts = array();

		foreach ( array_values( $population ) as $index => $generated ) {
			// One identifier per contact, so a population never collapses two
			// contacts onto one subscriber and the dedup step never fires.
			$subscriber_id = self::FIRST_SUBSCRIBER_ID + $index;

			$lists = array();
			$tags  = array();

			if ( in_array( $generated['membership'], array( 'list', 'both' ), true ) ) {
				$lists[] = self::LIST_ID;
			}

			if ( in_array( $generated['membership'], array( 'tag', 'both' ), true ) ) {
				$tags[] = self::TAG_ID;
			}

			if ( 'elsewhere' === $generated['membership'] ) {
				$lists[] = self::OTHER_LIST;
				$tags[]  = self::OTHER_TAG;
			}

			$fields = null === $generated['status']
				? array()
				: array( 'meh_enquiry_status' => $generated['status'] );

			$this->crm->add_subscriber(
				array(
					'id'            => $subscriber_id,
					'first_name'    => $generated['first_name'],
					'last_name'     => $generated['last_name'],
					'email'         => $generated['email'],
					'phone'         => $generated['phone'],
					'created_at'    => $generated['created_at'],
					'lists'         => $lists,
					'tags'          => $tags,
					'custom_fields' => $fields,
					'notes'         => self::seeded_notes( $generated['notes'] ),
				)
			);

			$eligible = self::is_eligible( $generated['membership'], $config );
			$storable = $eligible && '' !== trim( (string) $generated['email'] );

			$contacts[] = array(
				'subscriber_id' => $subscriber_id,
				'eligible'      => $eligible,
				'storable'      => $storable,
				'expected'      => self::expectation( $generated ),
			);
		}

		return $contacts;
	}

	/**
	 * The subscriber notes one contact is seeded with.
	 *
	 * @param array $notes Notes as generated.
	 * @return array<int,array{description:string,title:string,created_at:string}>
	 */
	private static function seeded_notes( array $notes ) {
		$seeded = array();

		foreach ( $notes as $note ) {
			$seeded[] = array(
				'description' => self::note_description( $note ),
				'title'       => 'title' === $note['shape'] ? (string) $note['body'] : '',
				'created_at'  => (string) $note['created_at'],
			);
		}

		return $seeded;
	}

	/**
	 * The `description` a generated note is stored with.
	 *
	 * @param array $note Note as generated.
	 * @return string
	 */
	private static function note_description( array $note ) {
		if ( 'plain' === $note['shape'] ) {
			return (string) $note['body'];
		}

		if ( 'markup' === $note['shape'] ) {
			return sprintf( (string) $note['markup'], (string) $note['body'] );
		}

		// `title` keeps its text in the title, and `blank` carries nothing.
		return '';
	}

	/**
	 * What one contact should turn into.
	 *
	 * Every expectation is computed from the generated values alone: the trimmed
	 * text the enquiry should hold, the status the legacy value maps to under
	 * this test's own copy of the map, and the note bodies the markup shapes
	 * reduce to.
	 *
	 * @param array $generated Contact as generated.
	 * @return array
	 */
	private static function expectation( array $generated ) {
		return array(
			'first_name' => trim( (string) $generated['first_name'] ),
			'last_name'  => trim( (string) $generated['last_name'] ),
			'email'      => trim( (string) $generated['email'] ),
			'phone'      => trim( (string) $generated['phone'] ),
			'created_at' => Clock::mysql( trim( (string) $generated['created_at'] ) ),
			'status'     => self::expected_status( $generated['status'] ),
			'notes'      => self::expected_notes( $generated['notes'] ),
		);
	}

	/**
	 * The status a legacy value should map to (Requirements 15.4, 15.5).
	 *
	 * @param string|null $legacy Legacy value, or null when the field is absent.
	 * @return string
	 */
	private static function expected_status( $legacy ) {
		if ( null === $legacy ) {
			return self::DEFAULT_STATUS;
		}

		$key = strtolower( trim( (string) $legacy ) );

		return isset( self::EXPECTED_MAP[ $key ] ) ? self::EXPECTED_MAP[ $key ] : self::DEFAULT_STATUS;
	}

	/**
	 * The enquiry notes a contact's subscriber notes should become, as comparable
	 * body-and-time pairs.
	 *
	 * A note carrying no text at all reproduces nothing, because there is nothing
	 * of it to carry forward.
	 *
	 * @param array $notes Notes as generated.
	 * @return string[] Sorted, so the comparison is over the set rather than the order.
	 */
	private static function expected_notes( array $notes ) {
		$expected = array();

		foreach ( $notes as $note ) {
			if ( 'blank' === $note['shape'] ) {
				continue;
			}

			$expected[] = self::pair(
				(string) $note['body'],
				Clock::mysql( trim( (string) $note['created_at'] ) )
			);
		}

		sort( $expected );

		return $expected;
	}

	/**
	 * Stored notes as comparable body-and-time pairs.
	 *
	 * @param array $notes Notes as `NoteService::for_enquiry()` returned them.
	 * @return string[] Sorted, matching `self::expected_notes()`.
	 */
	private static function note_pairs( array $notes ) {
		$pairs = array();

		foreach ( $notes as $note ) {
			$pairs[] = self::pair( (string) $note['body'], (string) $note['created_at'] );
		}

		sort( $pairs );

		return $pairs;
	}

	/**
	 * One note as a single comparable string.
	 *
	 * @param string $body       Note body.
	 * @param string $created_at Creation time.
	 * @return string
	 */
	private static function pair( $body, $created_at ) {
		return $created_at . "\x00" . $body;
	}

	/**
	 * Whether a contact with this membership is read under this configuration
	 * (Requirement 15.1).
	 *
	 * @param string $membership Where the contact sits.
	 * @param string $config     Which of the list and the tag is configured.
	 * @return bool
	 */
	private static function is_eligible( $membership, $config ) {
		if ( 'neither' === $config || 'elsewhere' === $membership ) {
			return false;
		}

		if ( 'both' === $config || 'both' === $membership ) {
			return true;
		}

		return $membership === $config;
	}

	/**
	 * Point the runner at the list, the tag, both, or neither.
	 *
	 * @param string $config Configuration to apply.
	 * @return void
	 */
	private function configure( $config ) {
		if ( in_array( $config, array( 'list', 'both' ), true ) ) {
			update_option( 'meh_enquiry_list', self::LIST_ID );
		} else {
			delete_option( 'meh_enquiry_list' );
		}

		if ( in_array( $config, array( 'tag', 'both' ), true ) ) {
			update_option( 'meh_enquiry_tag', self::TAG_ID );
		} else {
			delete_option( 'meh_enquiry_tag' );
		}
	}

	/* ---------------------------------------------------------------------
	 * Population helpers
	 * ------------------------------------------------------------------ */

	/**
	 * The contacts a run should read.
	 *
	 * @param array $contacts Seeded contacts.
	 * @return array
	 */
	private static function eligible( array $contacts ) {
		return array_values(
			array_filter(
				$contacts,
				function ( array $contact ) {
					return $contact['eligible'];
				}
			)
		);
	}

	/**
	 * The contacts a run should turn into enquiries.
	 *
	 * @param array $contacts Seeded contacts.
	 * @return array
	 */
	private static function storable( array $contacts ) {
		return array_values(
			array_filter(
				$contacts,
				function ( array $contact ) {
					return $contact['storable'];
				}
			)
		);
	}

	/**
	 * The eligible contacts a run should skip.
	 *
	 * @param array $contacts Seeded contacts.
	 * @return array
	 */
	private static function unstorable( array $contacts ) {
		return array_values(
			array_filter(
				$contacts,
				function ( array $contact ) {
					return $contact['eligible'] && ! $contact['storable'];
				}
			)
		);
	}

	/**
	 * Subscriber identifiers of a contact set, ascending.
	 *
	 * @param array $contacts Seeded contacts.
	 * @return int[]
	 */
	private static function subscriber_ids( array $contacts ) {
		$ids = array();

		foreach ( $contacts as $contact ) {
			$ids[] = (int) $contact['subscriber_id'];
		}

		sort( $ids, SORT_NUMERIC );

		return $ids;
	}

	/**
	 * Subscriber identifiers named by a run's skip records, ascending.
	 *
	 * @param array $reasons Skip records.
	 * @return int[]
	 */
	private static function skipped_ids( array $reasons ) {
		$ids = array();

		foreach ( $reasons as $skip ) {
			$ids[] = (int) $skip['subscriber_id'];
		}

		sort( $ids, SORT_NUMERIC );

		return $ids;
	}

	/**
	 * A `DATETIME` this many minutes after a fixed base instant.
	 *
	 * @param int $minutes Minute offset.
	 * @return string
	 */
	private static function datetime_at( $minutes ) {
		$base = new \DateTimeImmutable( '2021-02-03 04:05:06' );

		return $base->modify( '+' . (int) $minutes . ' minutes' )->format( 'Y-m-d H:i:s' );
	}

	/* ---------------------------------------------------------------------
	 * Database helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Enquiry identifiers carrying one subscriber identifier.
	 *
	 * @param int $subscriber_id Subscriber identifier.
	 * @return int[] Ascending.
	 */
	private function enquiry_ids_for( $subscriber_id ) {
		global $wpdb;

		$table = Schema::table( 'enquiries' );

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE fluentcrm_subscriber_id = %d ORDER BY id ASC", // phpcs:ignore WordPress.DB
				(int) $subscriber_id
			)
		);

		return is_array( $rows ) ? array_map( 'intval', $rows ) : array();
	}

	/**
	 * Enquiries currently stored.
	 *
	 * @return int
	 */
	private function enquiry_count() {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'enquiries' ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Return the store, the ledger and the CRM to their starting state, between
	 * iterations.
	 *
	 * @return void
	 */
	private function reset_state() {
		global $wpdb;

		foreach ( array_keys( Schema::TABLES ) as $key ) {
			$table = Schema::table( $key );
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
		}

		delete_option( MigrationRunner::LEDGER_OPTION );
		delete_option( MigrationRunner::COMPLETED_OPTION );

		$this->crm->reset();
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
