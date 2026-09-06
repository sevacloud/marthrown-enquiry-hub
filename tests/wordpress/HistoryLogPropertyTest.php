<?php
/**
 * Property 27: History is an append-only, attributed, ordered log.
 *
 * Feature: enquiry-data-layer, Property 27: For any enquiry and any sequence of
 * operations upon it — creation, status transitions, notes, conversion,
 * duplication, auto-closure and field corrections — the history list after each
 * operation begins with the unchanged history list from before that operation,
 * every appended entry carries the enquiry identifier, an entry type drawn from
 * the eight recognised types `created`, `status_changed`, `auto_closed`,
 * `note_added`, `crm_linked`, `booking_linked`, `duplicated` and
 * `fields_edited`, an acting user identifier, a creation time and a
 * description, that identifier is the system attribution exactly when no
 * WordPress user is authenticated, and the single-enquiry route returns the
 * entries ordered by creation time, oldest first.
 *
 * **Validates: Requirements 11.1, 11.2, 11.3, 11.4, 11.5**
 *
 * Five things about how the property is instantiated are worth stating plainly:
 *
 * - An "operation" is quantified over as the history entry it appends. The
 *   components that will perform those operations — `Lifecycle`, `NoteService`,
 *   `BookingCreator`, `AutoCloseJob`, `EnquiryEditor` — are later tasks, and
 *   each one appends through `HistoryRecorder::record()`. The eight recognised
 *   types are exactly the eight kinds of operation the property names, so a
 *   sequence drawn from those types is the sequence of operations, expressed at
 *   the only layer that exists yet. When those components land, their own
 *   properties assert that each fires the right type; this one asserts what the
 *   log does with whatever is fired at it.
 * - Append-only is asserted as *prefix stability*: the whole list read before an
 *   operation must be identical, entry for entry and field for field, to the
 *   opening slice of the list read after it, and the list must have grown by
 *   exactly one. Comparing hydrated entries with `assertSame()` covers the
 *   identifier, the description, the context, the attribution and the
 *   timestamp, so a rewritten field of an older entry fails here just as a
 *   removed entry does. Requirement 11.2's other half — that no code path
 *   *could* modify or remove an entry — is a claim about the surface rather than
 *   about behaviour, so it is asserted once, before the quantified run, by
 *   pinning the public method list.
 * - The clock is frozen and advanced by a generated gap that may be zero, so
 *   entries sharing a second are routine rather than rare. "Oldest first" is
 *   then asserted as two claims at once: creation times come back
 *   non-decreasing, and the order matches the order the entries were written.
 *   A tie broken the wrong way fails the second even though it satisfies the
 *   first, which is what makes the identifier tie-break observable.
 * - Attribution is exercised through a real WordPress user, and `record()` is
 *   always called asking it to resolve the current user rather than being handed
 *   an identifier. The claim is an "exactly when": the stored value is 0 when no
 *   user is authenticated and the authenticated user's own identifier when one
 *   is, so neither direction can pass by accident.
 * - Every generated sequence interleaves operations against a second enquiry.
 *   "Every appended entry carries the enquiry identifier" is only meaningful if
 *   an entry belonging to one enquiry can never surface in another's log, and a
 *   read filtered on the wrong column would otherwise pass every other
 *   assertion here.
 *
 * @package MarthrownEnquiryHub
 */

use Eris\TestTrait;
use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\HistoryRecorder;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;

/**
 * Class HistoryLogPropertyTest
 */
class HistoryLogPropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables can never collide with a real
	 * Enquiry Store table sitting in the same test database.
	 */
	const PREFIX_SEGMENT = 'mehh_';

	/**
	 * The instant every generated sequence starts from.
	 */
	const START = '2025-06-01 09:00:00';

	/**
	 * The eight recognised entry types (Requirement 11.3).
	 *
	 * Written out rather than read from `HistoryRecorder`, so a type quietly
	 * dropped from the class is a failure here rather than a silently narrower
	 * generator.
	 *
	 * @var string[]
	 */
	const RECOGNISED_TYPES = array(
		'created',
		'status_changed',
		'auto_closed',
		'note_added',
		'crm_linked',
		'booking_linked',
		'duplicated',
		'fields_edited',
	);

	/**
	 * The only methods an insert-only log may expose (Requirement 11.2).
	 *
	 * @var string[]
	 */
	const PUBLIC_SURFACE = array( 'for_enquiry', 'is_recognised', 'record' );

	/**
	 * Description cores that must survive tag removal intact.
	 *
	 * Each one is free of `<`, `>` and of any whitespace run, so stripping,
	 * whitespace collapsing and trimming leave it untouched and the stored
	 * description can be asserted to still contain it. The adversarial entries
	 * are here because a description is a bound value like any other: quotes,
	 * backslashes, comment markers and printf placeholders must reach the column
	 * as themselves.
	 *
	 * @var string[]
	 */
	const DESCRIPTION_CORES = array(
		"O'Brien corrected the guest count",
		'Zoë Ó Séaghdha added a note',
		'100% _ %s %d',
		'C:\\path\\to\\nowhere',
		"Robert'); DROP TABLE wp_meh_enquiry_history; --",
		'status moved new; then quoted',
	);

	/**
	 * The user whose identifier stands in for an authenticated actor.
	 *
	 * @var int
	 */
	protected static $user_id = 0;

	/**
	 * The WordPress table prefix in force outside this test.
	 *
	 * @var string
	 */
	private $original_prefix = '';

	/**
	 * Load the classes under test and create the acting user.
	 *
	 * @param mixed $factory WordPress fixture factory (unused).
	 * @return void
	 */
	public static function wpSetUpBeforeClass( $factory = null ) {
		unset( $factory );

		require_once MEH_INCLUDES_DIR . 'class-log.php';
		require_once MEH_INCLUDES_DIR . 'class-clock.php';
		require_once MEH_INCLUDES_DIR . 'class-schema.php';
		require_once MEH_INCLUDES_DIR . 'class-history-recorder.php';

		self::$user_id = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Remove the acting user.
	 *
	 * @return void
	 */
	public static function wpTearDownAfterClass() {
		self::delete_user( self::$user_id );
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
	}

	public function tear_down() {
		global $wpdb;

		Clock::unfreeze();
		wp_set_current_user( 0 );

		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/**
	 * Feature: enquiry-data-layer, Property 27: History is an append-only,
	 * attributed, ordered log.
	 *
	 * **Validates: Requirements 11.1, 11.2, 11.3, 11.4, 11.5**
	 *
	 * @eris-shrink 10
	 */
	public function test_history_is_an_append_only_attributed_ordered_log() {
		// Requirements 11.2, 11.3, asserted once about the surface rather than
		// once per generated sequence.
		$this->assert_surface_is_insert_only();

		$this->limitTo( Iterations::count( 100 ) )
			->forAll( self::operations() )
			->then(
				function ( array $operations ) {
					$this->clear();

					$this->assertTrue( Clock::freeze( self::START ), 'The clock should freeze under the test harness.' );

					$enquiry = $this->seed_enquiry( 'subject@example.com' );
					$other   = $this->seed_enquiry( 'bystander@example.com' );

					$this->assertSame(
						array(),
						HistoryRecorder::for_enquiry( $enquiry ),
						'An enquiry with no operations against it should have an empty history.'
					);

					$written = array();
					$at      = Clock::now();

					foreach ( $operations as $operation ) {
						$at = Clock::offset( $operation['gap'], $at );
						$this->assertTrue( Clock::freeze( $at ), 'The clock should advance under the test harness.' );

						$written = $this->apply( $operation, $enquiry, $other, $written );
					}

					$entries = HistoryRecorder::for_enquiry( $enquiry );

					// Requirement 11.4: oldest first, ties resolved as written.
					$this->assert_oldest_first( $entries, $written );

					// Requirement 11.1: an entry belongs to exactly one enquiry.
					$this->assert_logs_are_separate( $enquiry, $other, $entries );
				}
			);
	}

	/* ---------------------------------------------------------------------
	 * One operation
	 * ------------------------------------------------------------------ */

	/**
	 * Record one operation and assert everything the property claims about it.
	 *
	 * @param array $operation Generated operation.
	 * @param int   $enquiry   Enquiry whose log the property is about.
	 * @param int   $other     Second enquiry, whose log must stay separate.
	 * @param array $written   Entries appended to $enquiry so far, in write order.
	 * @return array $written, extended when this operation targeted $enquiry.
	 */
	private function apply( array $operation, $enquiry, $other, array $written ) {
		$target      = 'other' === $operation['target'] ? $other : $enquiry;
		$actor       = $operation['authenticated'] ? self::$user_id : 0;
		$description = sprintf( $operation['markup'], $operation['core'] );

		wp_set_current_user( $actor );

		$before = HistoryRecorder::for_enquiry( $enquiry );

		// Null actor: `record()` resolves the current user, which is what makes
		// the attribution claim of Requirement 11.5 observable.
		$id = HistoryRecorder::record( $target, $operation['type'], $description, $operation['context'], null );

		$this->assertGreaterThan(
			0,
			$id,
			'Recording a ' . $operation['type'] . ' entry should append a row.'
		);

		$after = HistoryRecorder::for_enquiry( $enquiry );

		if ( $target !== $enquiry ) {
			// An operation on another enquiry appends nothing to this log and
			// changes nothing already in it.
			$this->assertSame(
				$before,
				$after,
				'An operation against another enquiry should leave this history untouched.'
			);

			return $written;
		}

		// Requirement 11.2: the list before this operation is the unchanged
		// opening slice of the list after it, and exactly one entry was added.
		$this->assertCount( count( $before ) + 1, $after, 'One operation should append exactly one entry.' );
		$this->assertSame(
			$before,
			array_slice( $after, 0, count( $before ) ),
			'The history before an operation should be the unchanged prefix of the history after it.'
		);

		$entry = $after[ count( $before ) ];

		$this->assertSame( $id, $entry['id'], 'The appended entry should be the last one in the log.' );
		$this->assert_entry( $entry, $operation, $enquiry, $actor );

		$written[] = $entry;

		return $written;
	}

	/**
	 * Everything one appended entry has to carry (Requirements 11.1, 11.3, 11.5).
	 *
	 * @param array $entry     Entry as read back.
	 * @param array $operation Generated operation that produced it.
	 * @param int   $enquiry   Enquiry the operation concerned.
	 * @param int   $actor     Identifier of the user authenticated at the time, 0 for none.
	 * @return void
	 */
	private function assert_entry( array $entry, array $operation, $enquiry, $actor ) {
		// Requirement 11.1: the enquiry identifier.
		$this->assertSame( (int) $enquiry, $entry['enquiry_id'], 'An entry should carry its enquiry identifier.' );

		// Requirements 11.1, 11.3: an entry type, drawn from the eight.
		$this->assertSame( $operation['type'], $entry['entry_type'], 'An entry should carry the type recorded.' );
		$this->assertContains(
			$entry['entry_type'],
			self::RECOGNISED_TYPES,
			'An entry type should be one of the eight recognised types.'
		);

		// Requirement 11.1: a description of the change, tags removed, the
		// submitted text intact.
		$this->assertNotSame( '', $entry['description'], 'An entry should carry a description.' );
		$this->assertStringContainsString(
			$operation['core'],
			$entry['description'],
			'The stored description should still hold the submitted text.'
		);
		$this->assertStringNotContainsString( '<', $entry['description'], 'A stored description should hold no markup.' );

		// Requirement 11.1: a creation time, at the instant of the change.
		$this->assertSame( Clock::mysql(), $entry['created_at'], 'An entry should carry the time of the change.' );

		// Requirement 11.5: the system attribution exactly when no user is
		// authenticated, and the authenticated user's own identifier otherwise.
		$this->assertSame( (int) $actor, $entry['actor_id'], 'An entry should carry the acting user identifier.' );

		if ( $operation['authenticated'] ) {
			$this->assertNotSame( 0, $entry['actor_id'], 'An entry made by a user should not be attributed to the system.' );
		} else {
			$this->assertSame( 0, $entry['actor_id'], 'An entry made with no user authenticated should be attributed to the system.' );
		}

		// The structured detail an entry carries — for a `fields_edited` entry,
		// the changed fields with the previous and new value of each — survives
		// the round trip through the JSON column.
		$this->assertSame( $operation['context'], $entry['context'], 'An entry should read back the context recorded.' );
	}

	/* ---------------------------------------------------------------------
	 * Whole-log assertions
	 * ------------------------------------------------------------------ */

	/**
	 * The log reads back oldest first, ties resolved in write order
	 * (Requirement 11.4).
	 *
	 * Two claims, because either alone can pass while the other fails: creation
	 * times must be non-decreasing, and entries sharing a creation time must
	 * still come back in the order they were written.
	 *
	 * @param array $entries Entries as read back.
	 * @param array $written Entries in the order they were appended.
	 * @return void
	 */
	private function assert_oldest_first( array $entries, array $written ) {
		$this->assertSame( $written, $entries, 'The log should read back in the order it was written.' );

		$previous_time = '';
		$previous_id   = 0;

		foreach ( $entries as $entry ) {
			$this->assertGreaterThanOrEqual(
				$previous_time,
				$entry['created_at'],
				'Entries should be ordered by creation time, oldest first.'
			);
			$this->assertGreaterThan(
				$previous_id,
				$entry['id'],
				'An appended entry should always carry a new, higher identifier.'
			);

			$previous_time = $entry['created_at'];
			$previous_id   = $entry['id'];
		}
	}

	/**
	 * One enquiry's log never holds another enquiry's entries
	 * (Requirement 11.1).
	 *
	 * @param int   $enquiry Enquiry the property is about.
	 * @param int   $other   Second enquiry.
	 * @param array $entries Entries read back for $enquiry.
	 * @return void
	 */
	private function assert_logs_are_separate( $enquiry, $other, array $entries ) {
		global $wpdb;

		$table = Schema::table( 'history' );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB

		foreach ( HistoryRecorder::for_enquiry( $other ) as $entry ) {
			$this->assertSame( (int) $other, $entry['enquiry_id'], 'An entry should carry its own enquiry identifier.' );
		}

		$this->assertSame(
			$total,
			count( $entries ) + count( HistoryRecorder::for_enquiry( $other ) ),
			'Every stored entry should belong to exactly one of the two logs.'
		);
	}

	/**
	 * The recorder exposes no way to modify or remove an entry
	 * (Requirement 11.2), and recognises exactly the eight types
	 * (Requirement 11.3).
	 *
	 * @return void
	 */
	private function assert_surface_is_insert_only() {
		$methods = get_class_methods( HistoryRecorder::class );
		sort( $methods );

		$expected = self::PUBLIC_SURFACE;
		sort( $expected );

		$this->assertSame(
			$expected,
			$methods,
			'HistoryRecorder should expose only reads and one append: any other public method could modify or remove an entry.'
		);

		$this->assertSame(
			self::RECOGNISED_TYPES,
			HistoryRecorder::TYPES,
			'The recorder should recognise exactly the eight entry types.'
		);
	}

	/* ---------------------------------------------------------------------
	 * Generators
	 * ------------------------------------------------------------------ */

	/**
	 * A sequence of one to six operations against an enquiry.
	 *
	 * @return \Eris\Generator
	 */
	protected static function operations() {
		return \Eris\Generators::bind(
			\Eris\Generators::choose( 1, 6 ),
			function ( $count ) {
				return \Eris\Generators::vector( (int) $count, self::operation() );
			}
		);
	}

	/**
	 * One operation: which of the eight it is, which enquiry it concerns,
	 * whether a user is authenticated when it happens, how long after the
	 * previous one it happens, and the description and context it carries.
	 *
	 * A `gap` of 0 puts two entries in the same second, which is the case the
	 * ordering tie-break exists for, so it is a deliberate draw rather than a
	 * rarity. `other` appears once in four, often enough that most sequences
	 * interleave the second enquiry.
	 *
	 * @return \Eris\Generator
	 */
	protected static function operation() {
		return \Eris\Generators::associative(
			array(
				'type'          => \Eris\Generators::elements( self::RECOGNISED_TYPES ),
				'target'        => \Eris\Generators::elements( array( 'enquiry', 'enquiry', 'enquiry', 'other' ) ),
				'authenticated' => \Eris\Generators::elements( array( true, false ) ),
				'gap'           => \Eris\Generators::oneOf(
					\Eris\Generators::constant( 0 ),
					\Eris\Generators::choose( 0, 90 )
				),
				'core'          => self::description_core(),
				'markup'        => \Eris\Generators::elements(
					array_merge( array( '%s' ), Generators::MARKUP )
				),
				'context'       => self::context(),
			)
		);
	}

	/**
	 * The plain text a description carries, before any markup is wrapped round
	 * it.
	 *
	 * @return \Eris\Generator
	 */
	protected static function description_core() {
		return \Eris\Generators::oneOf(
			\Eris\Generators::map(
				function ( $index ) {
					return 'operation ' . (int) $index . ' recorded';
				},
				\Eris\Generators::choose( 1, 9999 )
			),
			\Eris\Generators::elements( self::DESCRIPTION_CORES )
		);
	}

	/**
	 * The structured detail an operation carries.
	 *
	 * The empty context is a valid state and its own case: nothing is stored for
	 * it, and it has to read back as an empty array rather than as a null or a
	 * `{}`. The `from`/`to` shapes are what a `status_changed` and a
	 * `fields_edited` entry actually carry.
	 *
	 * Values are strings and whole numbers only, so the JSON round trip is
	 * value-preserving and the comparison can be an identity one.
	 *
	 * @return \Eris\Generator
	 */
	protected static function context() {
		return \Eris\Generators::oneOf(
			\Eris\Generators::constant( array() ),
			\Eris\Generators::map(
				function ( $id ) {
					return array( 'note_id' => (int) $id );
				},
				\Eris\Generators::choose( 1, 9999 )
			),
			\Eris\Generators::constant(
				array(
					'from' => 'new',
					'to'   => 'contacted',
				)
			),
			\Eris\Generators::map(
				function ( $number ) {
					return array(
						'phone'   => array(
							'from' => '',
							'to'   => '07700 ' . str_pad( (string) (int) $number, 6, '0', STR_PAD_LEFT ),
						),
						'message' => array(
							'from' => "100% _ %s %d '\\",
							'to'   => 'Zoë Ó Séaghdha',
						),
					);
				},
				\Eris\Generators::choose( 0, 999999 )
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Database helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Write one enquiry row and return its identifier.
	 *
	 * @param string $email Enquiry email.
	 * @return int
	 */
	private function seed_enquiry( $email ) {
		global $wpdb;

		$now = Clock::mysql();

		$inserted = $wpdb->insert(
			Schema::table( 'enquiries' ),
			array(
				'first_name'        => 'Ada',
				'last_name'         => 'Lovelace',
				'email'             => $email,
				'status'            => 'new',
				'crm_sync_state'    => 'pending',
				'created_at'        => $now,
				'updated_at'        => $now,
				'status_changed_at' => $now,
				'source'            => 'webhook:fixture',
				'is_test'           => 0,
			)
		);

		$this->assertNotFalse( $inserted, 'Seeding an enquiry should succeed: ' . $wpdb->last_error );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Empty the two tables this property reads, between iterations.
	 *
	 * `DELETE` rather than `TRUNCATE`: the identifiers keep climbing, which is
	 * closer to a live table and means a stale identifier can never be mistaken
	 * for a fresh one.
	 *
	 * @return void
	 */
	private function clear() {
		global $wpdb;

		foreach ( array( 'history', 'enquiries' ) as $key ) {
			$table = Schema::table( $key );
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
		}
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
