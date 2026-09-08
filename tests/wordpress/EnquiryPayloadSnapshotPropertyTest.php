<?php
/**
 * Property 2: Payload snapshot serialization round trip.
 *
 * Feature: enquiry-data-layer, Property 2: For any payload structure, including
 * nested structures, unicode keys and values, empty collections, and payloads
 * exceeding 65,535 characters, serializing it into the enquiry payload column
 * and deserializing it back produces a structure with the same keys, the same
 * value for each key and the same nesting depth, with no key added and none
 * removed.
 *
 * **Validates: Requirements 1.7, 1.8**
 *
 * Three notes on how the property is instantiated:
 *
 * - It runs against a real column rather than against `json_encode()` alone,
 *   because the claim is about the *stored* snapshot. Two of the ways it can
 *   fail live outside PHP's encoder: `wpdb` silently truncates a value to the
 *   column's capacity before writing it — 65,535 bytes for `TEXT`, which is why
 *   Requirement 1.7 needs `LONGTEXT` — and the column's charset decides which
 *   characters survive at all. A pure test would pass while a `TEXT` column
 *   quietly lost the tail of every large snapshot.
 * - The oversized case is generated, not assumed: a payload whose serialized
 *   form runs past 65,535 characters is drawn alongside the small ones, and for
 *   those cases the stored column is measured as well as decoded, so truncation
 *   is caught as a length as well as a difference.
 * - Values are confined to valid UTF-8 and to types JSON carries — string, int,
 *   bool, null and nested arrays. Invalid UTF-8 is not in the property's input
 *   space: `EnquiryStore` substitutes the offending characters and says so in
 *   the log, which is a documented lossy fallback rather than a round trip.
 *   Floats are left out for the same reason — their fidelity is a question about
 *   `serialize_precision`, not about the snapshot.
 *
 * @package MarthrownEnquiryHub
 */

use Eris\TestTrait;
use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;

/**
 * Class EnquiryPayloadSnapshotPropertyTest
 */
class EnquiryPayloadSnapshotPropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables can never collide with a real
	 * Enquiry Store table sitting in the same test database.
	 */
	const PREFIX_SEGMENT = 'mehpay_';

	/**
	 * Characters the payload column must be able to hold (Requirement 1.7).
	 */
	const MIN_CAPACITY = 65535;

	/**
	 * Length of the filler an oversized payload carries.
	 *
	 * Comfortably past the required capacity, so the serialized form is over the
	 * line whatever else the payload holds.
	 */
	const FILLER_LENGTH = 70000;

	/**
	 * Key the oversized filler is stored under.
	 */
	const FILLER_KEY = 'transcript';

	/**
	 * Keys a generated payload draws from: plain field names, unicode, an empty
	 * key, quote and backslash keys, and two that look like numbers.
	 *
	 * `'0'` is here knowing PHP turns it into the integer 0 on the way into the
	 * array — that is the interesting case, because JSON has no integer keys and
	 * the decoded structure has to come back with the integer again. `'007'`
	 * stays a string for the same reason: it must not arrive back as 7.
	 *
	 * @var string[]
	 */
	const KEYS = array(
		'first_name',
		'date_ranges',
		'event_type',
		'Zoë',
		'名前',
		'key with space',
		'0',
		'007',
		'party🎉',
		'',
		'a.b',
		'quote"key',
		"apos'key",
		'back\\slash',
		'%s',
	);

	/**
	 * Scalar values a generated payload draws from, beyond the adversarial set.
	 *
	 * @var array
	 */
	const VALUES = array(
		'',
		'Ada',
		"O'Brien",
		'Zoë Ó Séaghdha',
		'名前',
		'party🎉',
		'007',
		'true',
		'null',
		'2025-06-01',
		"line\nbreak",
		"tab\there",
		'a/b',
		'C:\\path\\to\\nowhere',
	);

	/**
	 * Deepest structure a generated payload nests to.
	 */
	const MAX_DEPTH = 3;

	/**
	 * The WordPress table prefix in force outside this test.
	 *
	 * @var string
	 */
	private $original_prefix = '';

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
	 * Feature: enquiry-data-layer, Property 2: Payload snapshot serialization
	 * round trip.
	 *
	 * **Validates: Requirements 1.7, 1.8**
	 *
	 * @eris-shrink 10
	 */
	public function test_payload_snapshot_round_trips_through_the_store() {
		$this->limitTo( Iterations::count( 60 ) )
			->forAll( self::scenario() )
			->then(
				function ( array $case ) {
					$payload = self::payload( $case );

					$this->clear();

					$id = EnquiryStore::create( self::enquiry(), array( Generators::date_at( 3 ) ), array(), $payload );

					$this->assertNotWPError( $id, 'Storing an enquiry carrying the payload snapshot should succeed.' );
					$this->assertGreaterThan( 0, (int) $id, 'The store should return an identifier.' );

					$enquiry = EnquiryStore::find( (int) $id );

					$this->assertIsArray( $enquiry, 'The stored enquiry should read back.' );
					$this->assertArrayHasKey( 'payload', $enquiry, 'The hydrated enquiry should carry its payload snapshot.' );

					$decoded = $enquiry['payload'];

					// Requirement 1.8: the same keys, the same value for each
					// key, no key added and none removed, at every level.
					$this->assert_structure_preserved( $payload, $decoded );

					// Requirement 1.8: the same nesting depth.
					$this->assertSame(
						self::depth( $payload ),
						self::depth( $decoded ),
						'The deserialized snapshot should nest to the same depth as the serialized one.'
					);

					// Everything above, stated once as a whole-structure
					// identity: same keys, same key order, same value types.
					$this->assertSame(
						$payload,
						$decoded,
						'The deserialized snapshot should equal the serialized structure.'
					);

					// Requirement 1.7: the column holds the whole snapshot, so a
					// serialized form past 65,535 characters is stored entire
					// rather than clipped to the column's capacity.
					$this->assert_column_holds_it_all( (int) $id, 'oversize' === $case['size'] );
				}
			);
	}

	/* ---------------------------------------------------------------------
	 * Generators
	 * ------------------------------------------------------------------ */

	/**
	 * A payload structure, and whether this case carries the oversized filler.
	 *
	 * The oversized case is one draw in four rather than every draw: it is a
	 * different failure — truncation at the column's capacity — than the
	 * structural one, and it costs a 70,000 character write to exercise.
	 *
	 * @return \Eris\Generator
	 */
	protected static function scenario() {
		return \Eris\Generators::associative(
			array(
				'structure' => self::top_level(),
				'size'      => \Eris\Generators::elements(
					array( 'small', 'small', 'small', 'oversize' )
				),
			)
		);
	}

	/**
	 * The top level of a payload: a field map, a list, or nothing at all.
	 *
	 * The empty payload is a boundary the store has to read back as an empty
	 * structure rather than as a null or a failure.
	 *
	 * @return \Eris\Generator
	 */
	protected static function top_level() {
		return \Eris\Generators::oneOf(
			self::map_of( self::node( self::MAX_DEPTH - 1 ) ),
			self::list_of( self::node( self::MAX_DEPTH - 1 ) ),
			\Eris\Generators::constant( array() )
		);
	}

	/**
	 * One value inside a payload: a scalar, an empty collection, a list or a map.
	 *
	 * @param int $depth Levels of nesting still permitted below this one.
	 * @return \Eris\Generator
	 */
	protected static function node( $depth ) {
		if ( (int) $depth < 1 ) {
			return self::leaf();
		}

		return \Eris\Generators::oneOf(
			self::leaf(),
			\Eris\Generators::constant( array() ),
			self::list_of( self::node( (int) $depth - 1 ) ),
			self::map_of( self::node( (int) $depth - 1 ) )
		);
	}

	/**
	 * One scalar payload value: a string, a whole number, a boolean or null.
	 *
	 * Every string is valid UTF-8 and every type is one JSON carries, so the
	 * round trip is lossless when the store is right and lossy only when it is
	 * wrong.
	 *
	 * @return \Eris\Generator
	 */
	protected static function leaf() {
		return \Eris\Generators::oneOf(
			\Eris\Generators::elements( self::VALUES ),
			Generators::adversarial_string(),
			\Eris\Generators::choose( -100000, 100000 ),
			\Eris\Generators::elements( array( true, false, null ) )
		);
	}

	/**
	 * A keyed map of zero to four entries.
	 *
	 * Two draws landing on the same key collapse to one entry, which is what a
	 * PHP array does; the collapsed array is the oracle, because it is what the
	 * store was handed.
	 *
	 * @param \Eris\Generator $value Generator for each value.
	 * @return \Eris\Generator
	 */
	protected static function map_of( \Eris\Generator $value ) {
		return \Eris\Generators::bind(
			\Eris\Generators::choose( 0, 4 ),
			function ( $count ) use ( $value ) {
				if ( (int) $count < 1 ) {
					return \Eris\Generators::constant( array() );
				}

				return \Eris\Generators::map(
					function ( array $pairs ) {
						$map = array();

						foreach ( $pairs as $pair ) {
							$map[ $pair[0] ] = $pair[1];
						}

						return $map;
					},
					\Eris\Generators::vector(
						(int) $count,
						\Eris\Generators::tuple( \Eris\Generators::elements( self::KEYS ), $value )
					)
				);
			}
		);
	}

	/**
	 * A list of zero to four values.
	 *
	 * @param \Eris\Generator $value Generator for each element.
	 * @return \Eris\Generator
	 */
	protected static function list_of( \Eris\Generator $value ) {
		return \Eris\Generators::bind(
			\Eris\Generators::choose( 0, 4 ),
			function ( $count ) use ( $value ) {
				if ( (int) $count < 1 ) {
					return \Eris\Generators::constant( array() );
				}

				return \Eris\Generators::vector( (int) $count, $value );
			}
		);
	}

	/**
	 * The payload a generated case submits.
	 *
	 * The oversized case is the generated structure plus one long value, so the
	 * structural claim and the capacity claim are exercised by the same payload
	 * rather than by two unrelated ones.
	 *
	 * @param array $case Generated case.
	 * @return array
	 */
	protected static function payload( array $case ) {
		$payload = (array) $case['structure'];

		if ( 'oversize' === $case['size'] ) {
			$payload[ self::FILLER_KEY ] = str_repeat( 'x', self::FILLER_LENGTH );
		}

		return $payload;
	}

	/**
	 * The scalar columns a fixture enquiry carries.
	 *
	 * Deliberately dull: this property is about the snapshot, and Property 1
	 * owns the fidelity of these.
	 *
	 * @return array
	 */
	protected static function enquiry() {
		$now = Clock::mysql();

		return array(
			'first_name'        => 'Ada',
			'last_name'         => 'Lovelace',
			'email'             => 'ada@example.com',
			'status'            => 'new',
			'crm_sync_state'    => 'pending',
			'created_at'        => $now,
			'updated_at'        => $now,
			'status_changed_at' => $now,
			'source'            => 'webhook:fixture',
		);
	}

	/* ---------------------------------------------------------------------
	 * Assertions
	 * ------------------------------------------------------------------ */

	/**
	 * Assert one level of a structure survived, then recurse (Requirement 1.8).
	 *
	 * `array_keys()` equality is the "no key added and none removed" clause and
	 * the key-order clause at once; the per-key comparison is strict, so a value
	 * arriving back as a different type — `'007'` as `7`, `null` as `''` — is a
	 * failure rather than a match.
	 *
	 * @param mixed  $expected What was serialized.
	 * @param mixed  $actual   What was deserialized.
	 * @param string $path     Where in the structure this level sits.
	 * @return void
	 */
	private function assert_structure_preserved( $expected, $actual, $path = 'payload' ) {
		if ( ! is_array( $expected ) ) {
			$this->assertSame( $expected, $actual, "The value at {$path} should survive the round trip." );

			return;
		}

		$this->assertIsArray( $actual, "The structure at {$path} should come back as a structure." );

		$this->assertSame(
			array_keys( $expected ),
			array_keys( $actual ),
			"The keys at {$path} should come back unchanged, none added and none removed."
		);

		foreach ( $expected as $key => $value ) {
			$this->assert_structure_preserved( $value, $actual[ $key ], $path . '[' . $key . ']' );
		}
	}

	/**
	 * Assert the stored column holds the snapshot entire (Requirement 1.7).
	 *
	 * The oversized case is the one that decides the column type: `wpdb` clips a
	 * value to the column's capacity before writing it, so a `TEXT` column would
	 * store the first 65,535 bytes and nothing more.
	 *
	 * @param int  $id        Enquiry identifier.
	 * @param bool $oversized Whether this case carries the oversized filler.
	 * @return void
	 */
	private function assert_column_holds_it_all( $id, $oversized ) {
		global $wpdb;

		$table = Schema::table( 'enquiries' );

		$stored = $wpdb->get_var(
			$wpdb->prepare( "SELECT payload FROM {$table} WHERE id = %d", (int) $id ) // phpcs:ignore WordPress.DB
		);

		$this->assertIsString( $stored, 'The payload column should hold the serialized snapshot.' );
		$this->assertNotNull(
			json_decode( (string) $stored, true ),
			'The stored snapshot should be readable as it stands, not as a clipped fragment.'
		);

		if ( ! $oversized ) {
			return;
		}

		$this->assertGreaterThan(
			self::MIN_CAPACITY,
			strlen( (string) $stored ),
			'The payload column should hold more than 65,535 characters rather than clipping the snapshot.'
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * How deeply a structure nests. A scalar is 0, an empty array is 1.
	 *
	 * @param mixed $value Structure or scalar.
	 * @return int
	 */
	private static function depth( $value ) {
		if ( ! is_array( $value ) ) {
			return 0;
		}

		$deepest = 0;

		foreach ( $value as $child ) {
			$deepest = max( $deepest, self::depth( $child ) );
		}

		return 1 + $deepest;
	}

	/**
	 * Empty the tables this property writes, between iterations.
	 *
	 * @return void
	 */
	private function clear() {
		global $wpdb;

		foreach ( array( 'enquiries', 'dates', 'terms' ) as $key ) {
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
