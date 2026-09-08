<?php
/**
 * Property 1: Enquiry storage round trip.
 *
 * Feature: enquiry-data-layer, Property 1: For any valid enquiry — including
 * values at the stated field capacities, unicode and quote characters, 1 to 3
 * candidate date ranges, and 0 to 20 values per multi-select field, each taxonomy
 * drawn independently — writing it to the Enquiry Store and reading it back by
 * identifier returns every scalar field character-for-character equal to the
 * written value, the three timestamps equal to the written site-local times to
 * the nearest second, the candidate date ranges equal as a list in the written
 * rank order, and the `event_type` and `site_exclusivity` values equal as sets
 * irrespective of row order, an empty
 * multi-select reading back as an empty set rather than as an error or a null;
 * for any enquiry in which `phone`, `total_guests` or `message` is empty, in any
 * combination of the three, the read-back value of each empty field is the same
 * empty value that was written — `''` for `phone` and `message`, and a null
 * `total_guests` distinguishable from a stored `0` — while every populated field
 * is unaffected; and for any sequence of writes the assigned identifiers are
 * positive, pairwise distinct, and never reused after a deletion.
 *
 * **Validates: Requirements 1.1, 1.2, 1.3, 1.6, 1.18, 1.19**
 *
 * Four things about how the property is instantiated are worth stating plainly:
 *
 * - Only a real database can answer this. Character-for-character equality is a
 *   claim about column types, charset and strict-mode truncation, and
 *   "irrespective of row order" is a claim about what the child-row queries
 *   return. So the test works against real tables at its own prefix, which it
 *   installs and drops itself.
 * - Row-order independence is exercised by writing each generated enquiry
 *   twice, the second time with both term lists in the opposite order, and
 *   requiring the two hydrated reads to be identical apart from the identifier.
 *   The second write also supplies the three timestamps as Unix timestamps rather
 *   than as strings, so "equal to the nearest second in the site timezone" is
 *   asserted over two input forms of the same instant rather than over the
 *   formatting of one string.
 * - The candidate ranges are deliberately *not* mirrored with the term lists.
 *   Their order is the rank the enquirer gave them — the ideal range first, then
 *   the alternatives — so it is part of the value rather than an artefact of how
 *   rows came back. A third write puts the same ranges in the opposite order and
 *   requires the read to come back in that opposite order, which is what pins the
 *   store to preserving rank rather than to normalising it away.
 * - The `total_guests` clause needs a companion row to mean anything: an
 *   unsupplied count reading back as null only matters if a supplied `0` reads
 *   back as `0`. Each iteration therefore writes a control enquiry holding `0`
 *   and compares the two.
 * - Identifiers accumulate across every iteration deliberately: the tables are
 *   not emptied between iterations, so "positive, pairwise distinct and never
 *   reused after a deletion" is quantified over the whole run rather than over
 *   one write. Each iteration deletes one enquiry it wrote and then writes
 *   another, which is the sequence the claim is about.
 * - The generated case is drawn unshrunk, for the reason given on
 *   `self::unshrunk()`.
 *
 * @package MarthrownEnquiryHub
 */

use Eris\TestTrait;
use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\EnquiryQuery;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;

/**
 * Class EnquiryRoundTripPropertyTest
 */
class EnquiryRoundTripPropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables can never collide with a real
	 * Enquiry Store table sitting in the same test database.
	 */
	const PREFIX_SEGMENT = 'mehr_';

	/**
	 * The `crm_sync_state` values an enquiry can hold, the empty one included
	 * (Requirement 5.6: a store failure sets none).
	 *
	 * @var string[]
	 */
	const CRM_STATES = array( '', 'pending', 'synced' );

	/**
	 * `source` values, one per creation route.
	 *
	 * @var string[]
	 */
	const SOURCES = array( 'webhook:unidentified', 'webhook:kadence-form-3', 'manual', 'migration:fluentcrm' );

	/**
	 * Times of day the generated timestamps land on, both day boundaries
	 * included.
	 *
	 * @var string[]
	 */
	const TIMES = array( '00:00:00', '09:30:00', '12:00:01', '23:59:59' );

	/**
	 * How an iteration treats the three fields Requirement 1.19 permits to be
	 * empty.
	 *
	 * `drawn` leaves each one to its own generator, `empty` empties all three at
	 * once and `supplied` populates all three, so the all-empty and all-populated
	 * combinations are reached deliberately rather than by chance.
	 *
	 * @var string[]
	 */
	const OPTIONAL_MODES = array( 'drawn', 'empty', 'supplied' );

	/**
	 * Enquiry columns holding a nullable identifier.
	 *
	 * @var string[]
	 */
	const REFERENCE_COLUMNS = array(
		'fluentcrm_subscriber_id',
		'booking_id',
		'duplicated_from_id',
		'duplicated_to_id',
	);

	/**
	 * The WordPress table prefix in force outside this test.
	 *
	 * @var string
	 */
	private $original_prefix = '';

	/**
	 * Every identifier the store has assigned during this run.
	 *
	 * @var int[]
	 */
	private $assigned_ids = array();

	/**
	 * Every identifier whose enquiry this test has deleted.
	 *
	 * @var int[]
	 */
	private $deleted_ids = array();

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

		$this->assigned_ids = array();
		$this->deleted_ids  = array();

		$this->drop_tables();
		$this->assertTrue( Schema::install(), 'The fixture install should succeed.' );
	}

	public function tear_down() {
		global $wpdb;

		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/**
	 * Feature: enquiry-data-layer, Property 1: Enquiry storage round trip.
	 *
	 * **Validates: Requirements 1.1, 1.2, 1.3, 1.6, 1.18, 1.19**
	 */
	public function test_enquiry_storage_round_trip() {
		$this->limitTo( Iterations::count( 100 ) )
			->forAll( self::unshrunk( self::enquiry_case() ) )
			->then(
				function ( array $case ) {
					$case    = self::apply_optional_mode( $case );
					$written = self::scalars( $case );
					$ranges  = $case['ranges'];
					$terms   = self::terms( $case );

					// Requirements 1.1, 1.2, 1.3: the enquiry, its candidate
					// ranges and both term lists, written as one enquiry.
					$id = $this->write( $written, $ranges, $terms );

					$read = EnquiryStore::find( $id );

					$this->assertIsArray( $read, 'A stored enquiry should be readable by identifier.' );
					$this->assert_scalars( $written, $read );
					$this->assert_sets( $ranges, $terms, $read );

					/*
					 * The same enquiry again, its term rows written in the
					 * opposite order and its timestamps supplied as Unix
					 * timestamps rather than as strings. Both reads must be the
					 * same enquiry (Requirement 1.6).
					 */
					$mirrored = $this->write(
						self::as_timestamps( $written ),
						$ranges,
						array_map( 'array_reverse', $terms )
					);

					$this->assertSame(
						self::comparable( $read ),
						self::comparable( EnquiryStore::find( $mirrored ) ),
						'Term row order and timestamp input form should not change what reads back.'
					);

					// The ranges the other way about: rank is the value, so the
					// read follows the write rather than settling on one order.
					$this->assert_rank_is_preserved( $written, $ranges, $terms );

					// Requirement 1.19: an unsupplied guest count is not a
					// stored zero.
					$this->assert_unsupplied_is_not_zero( $written['total_guests'], $read['total_guests'] );

					// Requirement 1.1: identifiers are never reused, deletion
					// included.
					$this->assert_identifiers_are_not_reused( $mirrored );
				}
			);
	}

	/* ---------------------------------------------------------------------
	 * Generators
	 * ------------------------------------------------------------------ */

	/**
	 * One enquiry: every stored scalar, 1 to 3 candidate date ranges, and 0 to 20
	 * values per multi-select field with each taxonomy drawn independently.
	 *
	 * @return \Eris\Generator
	 */
	protected static function enquiry_case() {
		return \Eris\Generators::associative(
			array(
				'first_name'              => Generators::first_name(),
				'last_name'               => Generators::last_name(),
				'email'                   => Generators::email(),
				'phone'                   => Generators::phone_or_empty(),
				'total_guests'            => Generators::total_guests_or_unsupplied(),
				'message'                 => Generators::message_or_empty(),
				'optionals'               => \Eris\Generators::elements( self::OPTIONAL_MODES ),
				'status'                  => \Eris\Generators::elements( EnquiryQuery::statuses() ),
				'crm_sync_state'          => \Eris\Generators::elements( self::CRM_STATES ),
				'source'                  => \Eris\Generators::elements( self::SOURCES ),
				'is_test'                 => \Eris\Generators::elements( array( true, false ) ),
				'fluentcrm_subscriber_id' => self::reference_id(),
				'booking_id'              => self::reference_id(),
				'duplicated_from_id'      => self::reference_id(),
				'duplicated_to_id'        => self::reference_id(),
				'created_at'              => self::stamp(),
				'updated_at'              => self::stamp(),
				'status_changed_at'       => self::stamp(),
				'ranges'                  => Generators::candidate_ranges( Generators::RANGES_MIN, Generators::RANGES_MAX ),
				'event_type'              => Generators::term_set( 0, Generators::TERMS_MAX ),
				'site_exclusivity'        => Generators::term_set( 0, Generators::TERMS_MAX ),
			)
		);
	}

	/**
	 * The same generator, with shrinking turned off.
	 *
	 * Eris shrinks a wide structure by taking the cartesian product of every
	 * element's shrink options, so the cost of one shrink step is exponential in
	 * the number of generated values. This case holds twenty drawn fields, one of
	 * them a set of up to twenty values, which puts that product past a billion
	 * options: a failing iteration exhausts memory building them and reports an
	 * out-of-memory fatal instead of the counterexample. The `@eris-shrink` time
	 * limit does not help, because the explosion happens inside a single shrink
	 * call, before the limit is next checked.
	 *
	 * Binding the drawn value to a constant generator makes shrinking a no-op, so
	 * a failure reports the case as generated, together with the assertion's own
	 * expected-versus-actual diff and the `ERIS_SEED` line that reproduces the
	 * run exactly. An unshrunk counterexample is worth having; an out-of-memory
	 * fatal is not.
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

	/**
	 * A nullable identifier column value: unset, or a positive whole number.
	 *
	 * @return \Eris\Generator
	 */
	protected static function reference_id() {
		return \Eris\Generators::oneOf(
			\Eris\Generators::constant( null ),
			\Eris\Generators::choose( 1, 999999 )
		);
	}

	/**
	 * A site-local `DATETIME` string, on a day boundary or between.
	 *
	 * @return \Eris\Generator
	 */
	protected static function stamp() {
		return \Eris\Generators::map(
			function ( array $parts ) {
				list( $offset, $time ) = $parts;

				return Generators::date_at( (int) $offset ) . ' ' . $time;
			},
			\Eris\Generators::tuple(
				\Eris\Generators::choose( -400, 400 ),
				\Eris\Generators::elements( self::TIMES )
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Case shaping
	 * ------------------------------------------------------------------ */

	/**
	 * Apply the drawn treatment of the three fields Requirement 1.19 permits to
	 * be empty, so the all-empty and all-populated combinations are both
	 * reached.
	 *
	 * @param array $case Generated case.
	 * @return array
	 */
	protected static function apply_optional_mode( array $case ) {
		if ( 'empty' === $case['optionals'] ) {
			$case['phone']        = '';
			$case['message']      = '';
			$case['total_guests'] = null;

			return $case;
		}

		if ( 'supplied' === $case['optionals'] ) {
			$case['phone']        = '' === $case['phone'] ? '07700 900123' : $case['phone'];
			$case['message']      = '' === $case['message'] ? 'A June weekend, if either date is free.' : $case['message'];
			$case['total_guests'] = null === $case['total_guests'] ? Generators::TOTAL_GUESTS_MAX : $case['total_guests'];
		}

		return $case;
	}

	/**
	 * The scalar column values of a case, in the shape `create()` takes.
	 *
	 * @param array $case Generated case.
	 * @return array<string,mixed>
	 */
	protected static function scalars( array $case ) {
		$scalars = array(
			'first_name'        => $case['first_name'],
			'last_name'         => $case['last_name'],
			'email'             => $case['email'],
			'phone'             => $case['phone'],
			'total_guests'      => $case['total_guests'],
			'message'           => $case['message'],
			'status'            => $case['status'],
			'crm_sync_state'    => $case['crm_sync_state'],
			'source'            => $case['source'],
			'is_test'           => $case['is_test'],
			'created_at'        => $case['created_at'],
			'updated_at'        => $case['updated_at'],
			'status_changed_at' => $case['status_changed_at'],
		);

		foreach ( self::REFERENCE_COLUMNS as $column ) {
			$scalars[ $column ] = $case[ $column ];
		}

		return $scalars;
	}

	/**
	 * The two term lists of a case, keyed by taxonomy.
	 *
	 * @param array $case Generated case.
	 * @return array<string,string[]>
	 */
	protected static function terms( array $case ) {
		return array(
			'event_type'       => $case['event_type'],
			'site_exclusivity' => $case['site_exclusivity'],
		);
	}

	/**
	 * The same scalars with the three timestamps expressed as Unix timestamps.
	 *
	 * @param array $written Scalar column values.
	 * @return array
	 */
	protected static function as_timestamps( array $written ) {
		foreach ( EnquiryStore::DATETIME_COLUMNS as $column ) {
			$written[ $column ] = (int) Clock::at( $written[ $column ] )->format( 'U' );
		}

		return $written;
	}

	/**
	 * A hydrated enquiry reduced to what two writes of the same enquiry must
	 * share: everything but the identifier, with the three sets compared as
	 * sets.
	 *
	 * @param array|null $read Hydrated enquiry.
	 * @return array
	 */
	protected static function comparable( $read ) {
		$comparable = is_array( $read ) ? $read : array();

		unset( $comparable['id'] );

		foreach ( array( 'event_type', 'site_exclusivity' ) as $key ) {
			$values = isset( $comparable[ $key ] ) ? (array) $comparable[ $key ] : array();

			sort( $values, SORT_STRING );

			$comparable[ $key ] = $values;
		}

		ksort( $comparable );

		return $comparable;
	}

	/* ---------------------------------------------------------------------
	 * Assertions
	 * ------------------------------------------------------------------ */

	/**
	 * Every scalar reads back as it was written (Requirements 1.6, 1.18, 1.19).
	 *
	 * @param array $written Scalar column values that were written.
	 * @param array $read    Hydrated enquiry.
	 * @return void
	 */
	private function assert_scalars( array $written, array $read ) {
		$text = array( 'first_name', 'last_name', 'email', 'phone', 'message', 'status', 'crm_sync_state', 'source' );

		foreach ( $text as $column ) {
			$this->assertSame(
				$written[ $column ],
				$read[ $column ],
				sprintf( '`%s` should read back character-for-character equal.', $column )
			);
		}

		// Null stays null and a count stays that count; neither becomes the
		// other (Requirement 1.19).
		$this->assertSame(
			$written['total_guests'],
			$read['total_guests'],
			'`total_guests` should read back as the value that was written.'
		);

		foreach ( self::REFERENCE_COLUMNS as $column ) {
			$this->assertSame(
				$written[ $column ],
				$read[ $column ],
				sprintf( '`%s` should read back as the value that was written.', $column )
			);
		}

		$this->assertSame( (bool) $written['is_test'], $read['is_test'], '`is_test` should read back as it was written.' );

		// Requirement 1.6: to the nearest second, in the site timezone.
		foreach ( EnquiryStore::DATETIME_COLUMNS as $column ) {
			$this->assertSame(
				Clock::mysql( $written[ $column ] ),
				$read[ $column ],
				sprintf( '`%s` should read back equal to the written second.', $column )
			);
		}
	}

	/**
	 * The candidate ranges read back as the written list and both term lists as
	 * the written sets, an empty multi-select as an empty array
	 * (Requirements 1.2, 1.3, 1.6).
	 *
	 * @param array $ranges Candidate date ranges that were written.
	 * @param array $terms  Term lists that were written.
	 * @param array $read   Hydrated enquiry.
	 * @return void
	 */
	private function assert_sets( array $ranges, array $terms, array $read ) {
		$this->assertSame(
			array_values( $ranges ),
			$read['date_ranges'],
			'The candidate ranges should read back as the written list, in the written order.'
		);

		foreach ( $terms as $taxonomy => $values ) {
			$this->assertIsArray(
				$read[ $taxonomy ],
				sprintf( '`%s` should read back as an array, empty or otherwise.', $taxonomy )
			);

			$this->assertSame(
				self::set( $values ),
				self::set( $read[ $taxonomy ] ),
				sprintf( '`%s` should read back as the written set.', $taxonomy )
			);
		}
	}

	/**
	 * The same ranges written the other way about read back the other way about
	 * (Requirement 1.2).
	 *
	 * The companion to assert_sets(): that one shows the written order surviving,
	 * this one shows it is the written order that survives rather than an order
	 * the store happens to settle on. A single range makes no claim either way, so
	 * it is skipped.
	 *
	 * @param array $scalars Scalar fields to write.
	 * @param array $ranges  Candidate date ranges as first written.
	 * @param array $terms   Term lists to write.
	 * @return void
	 */
	private function assert_rank_is_preserved( array $scalars, array $ranges, array $terms ) {
		if ( count( $ranges ) < 2 ) {
			return;
		}

		$reversed = array_reverse( array_values( $ranges ) );
		$read     = EnquiryStore::find( $this->write( $scalars, $reversed, $terms ) );

		$this->assertSame(
			$reversed,
			$read['date_ranges'],
			'Reversing the written ranges should reverse the ranges that read back.'
		);
	}

	/**
	 * An unsupplied guest count reads back as null, and a supplied `0` reads
	 * back as `0`, so the two are never confused (Requirement 1.19).
	 *
	 * @param int|null $written Guest count that was written.
	 * @param int|null $read    Guest count that read back.
	 * @return void
	 */
	private function assert_unsupplied_is_not_zero( $written, $read ) {
		$control = $this->write(
			array(
				'email'        => 'control@example.com',
				'total_guests' => 0,
			)
		);

		$this->assertSame(
			0,
			EnquiryStore::find( $control )['total_guests'],
			'A supplied guest count of 0 should read back as 0.'
		);

		if ( null !== $written ) {
			return;
		}

		$this->assertNull( $read, 'An unsupplied guest count should read back as null.' );
		$this->assertNotSame( 0, $read, 'An unsupplied guest count should not read back as a stored 0.' );
	}

	/**
	 * Identifiers are positive, pairwise distinct, and not reused after the
	 * enquiry holding one is deleted (Requirement 1.1).
	 *
	 * @param int $doomed Identifier of an enquiry to delete.
	 * @return void
	 */
	private function assert_identifiers_are_not_reused( $doomed ) {
		$this->delete( $doomed );

		$this->assertNull( EnquiryStore::find( $doomed ), 'A deleted enquiry should no longer be readable.' );

		// Every write from here on is checked against the deleted identifiers
		// too, so it is enough to make one and let the run accumulate.
		$this->write( array( 'email' => 'after-deletion@example.com' ) );
	}

	/* ---------------------------------------------------------------------
	 * Database helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Write one enquiry, asserting the identifier it was given is usable and
	 * fresh.
	 *
	 * @param array $scalars Scalar column values.
	 * @param array $ranges  Candidate date ranges.
	 * @param array $terms   Term lists keyed by taxonomy.
	 * @return int Enquiry identifier.
	 */
	private function write( array $scalars, array $ranges = array(), array $terms = array() ) {
		$id = EnquiryStore::create( $scalars, $ranges, $terms, array( 'email' => isset( $scalars['email'] ) ? $scalars['email'] : '' ) );

		$this->assertNotWPError( $id, 'Storing a valid enquiry should succeed.' );

		$id = (int) $id;

		$this->assertGreaterThan( 0, $id, 'An assigned identifier should be a positive whole number.' );
		$this->assertNotContains( $id, $this->assigned_ids, 'An assigned identifier should never repeat.' );
		$this->assertNotContains( $id, $this->deleted_ids, 'An assigned identifier should never be reused after a deletion.' );

		$this->assigned_ids[] = $id;

		return $id;
	}

	/**
	 * Delete one enquiry and its child rows, recording the identifier so no
	 * later write may take it.
	 *
	 * @param int $id Enquiry identifier.
	 * @return void
	 */
	private function delete( $id ) {
		global $wpdb;

		$id = (int) $id;

		$wpdb->delete( Schema::table( 'dates' ), array( 'enquiry_id' => $id ), array( '%d' ) );
		$wpdb->delete( Schema::table( 'terms' ), array( 'enquiry_id' => $id ), array( '%d' ) );
		$wpdb->delete( Schema::table( 'enquiries' ), array( 'id' => $id ), array( '%d' ) );

		$this->deleted_ids[] = $id;
	}

	/**
	 * A list of values as a comparable set: deduplicated and ordered.
	 *
	 * @param array $values Values.
	 * @return array
	 */
	private static function set( array $values ) {
		$set = array_values( array_unique( array_map( 'strval', $values ) ) );

		sort( $set, SORT_STRING );

		return $set;
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
