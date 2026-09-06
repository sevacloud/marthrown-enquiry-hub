<?php
/**
 * Property 12: Adversarial values are bound, not interpolated.
 *
 * Feature: enquiry-data-layer, Property 12: For any string containing
 * SQL-significant or placeholder characters (single and double quotes,
 * backslashes, `--`, `;`, `%`, `_`, `%s`, `%d`), storing it in any text field
 * and then reading it back by identifier and searching for it returns the value
 * unchanged and matches the correct enquiries, and every Enquiry Store table and
 * row count survives the operation.
 *
 * **Validates: Requirements 3.10**
 *
 * Four things about how the property is instantiated are worth stating plainly:
 *
 * - The claim is about the database, not about the shape of a string in PHP. A
 *   value interpolated into SQL rather than bound does not come back mangled in
 *   some detectable way — it either changes the statement's meaning or ends the
 *   statement early — so nothing short of a real MySQL connection can observe
 *   it. This test therefore works against real tables at its own prefix, the way
 *   `EnquiryListFilterPropertyTest` does, and cleans them up itself.
 * - Every write goes through `EnquiryStore`, never through a hand-composed
 *   statement. `create()` covers the parent insert, the multi-row candidate date
 *   and term inserts it composes itself, and the payload snapshot; a following
 *   `update_fields()` covers the update path. Those are the four statements
 *   Requirement 3.10 is about, and each one is reached with the adversarial value
 *   in it.
 * - "Matches the correct enquiries" needs more than one enquiry to be a claim at
 *   all, so each case seeds four: the subject carrying the value in the field
 *   under test, a twin carrying the same value in a searchable field, and two
 *   decoys carrying benign values. A search returning only the subject, or every
 *   row, fails against the reference.
 * - "Survives the operation" is checked as three separate facts, because
 *   injection has three separate signatures: every table still exists (a `DROP`
 *   got through), the enquiry table's column list is unchanged (an `ALTER` got
 *   through), and every row count and every previously stored row is exactly as
 *   it was (a `DELETE`, `UPDATE` or extra `INSERT` got through).
 *
 * Field capacities are respected when drawing the value: `phone` holds 32
 * characters, so the longer injection strings are not offered for it. A value
 * MySQL would truncate on the way in is a test artefact rather than a binding
 * failure, and would fail the round trip for the wrong reason.
 *
 * @package MarthrownEnquiryHub
 */

use Eris\TestTrait;
use MarthrownEnquiryHub\EnquiryQuery;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;

/**
 * Class EnquiryStoreBindingPropertyTest
 */
class EnquiryStoreBindingPropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables can never collide with a real
	 * Enquiry Store table sitting in the same test database.
	 */
	const PREFIX_SEGMENT = 'mehb_';

	/**
	 * Text fields the adversarial value is stored in, one per case.
	 *
	 * `source` is here as well as the five submitted scalars because it is
	 * derived from the request payload, so it too is a submitted value under
	 * Requirement 3.10.
	 *
	 * @var string[]
	 */
	const TARGET_FIELDS = array( 'first_name', 'last_name', 'email', 'phone', 'message', 'source' );

	/**
	 * Fields a search term is matched against (Requirement 12.4).
	 *
	 * Written out rather than read from `EnquiryQuery`, so the reference and the
	 * builder share no constant.
	 *
	 * @var string[]
	 */
	const SEARCH_FIELDS = array( 'first_name', 'last_name', 'email', 'phone', 'message' );

	/**
	 * Searchable fields the twin enquiry may carry the value in.
	 *
	 * All three hold at least 100 characters, so any drawn value fits whichever
	 * field the subject used.
	 *
	 * @var string[]
	 */
	const TWIN_FIELDS = array( 'first_name', 'last_name', 'message' );

	/**
	 * Stored capacity of each target field, in characters. 0 means unbounded.
	 *
	 * Repeated here rather than read from the schema on purpose: a generator
	 * taking its bounds from the code under test could not catch that code
	 * narrowing them.
	 *
	 * @var array<string,int>
	 */
	const CAPACITIES = array(
		'first_name' => 100,
		'last_name'  => 100,
		'email'      => 254,
		'phone'      => 32,
		'message'    => 0,
		'source'     => 191,
	);

	/**
	 * Field values of a decoy enquiry.
	 *
	 * Plain ASCII carrying none of the adversarial tokens, so a decoy that turns
	 * up in a search result means the term was interpolated rather than bound.
	 *
	 * @var array<string,string>
	 */
	const DECOY = array(
		'first_name' => 'Ada',
		'last_name'  => 'Lovelace',
		'email'      => 'ada.decoy@example.com',
		'phone'      => '07700 900123',
		'message'    => 'A plain enquiry with nothing remarkable about it.',
		'source'     => 'webhook:fixture',
	);

	/** The instant every seeded row is written at. */
	const WRITTEN_AT = '2025-06-01 09:00:00';

	/** Guest count every seeded row carries. */
	const GUESTS = 42;

	/**
	 * The WordPress table prefix in force outside this test.
	 *
	 * @var string
	 */
	private $originalPrefix = '';

	/**
	 * The enquiry table's column names, as installed.
	 *
	 * @var string[]
	 */
	private $columns = array();

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
		require_once MEH_INCLUDES_DIR . 'class-enquiry-query.php';
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

		$this->originalPrefix = $wpdb->prefix;
		$wpdb->prefix         = $this->originalPrefix . self::PREFIX_SEGMENT;

		$this->dropTables();
		$this->assertTrue( Schema::install(), 'The fixture install should succeed.' );

		$this->columns = $this->columnNames();
		$this->assertNotEmpty( $this->columns, 'The installed enquiry table should report its columns.' );
	}

	public function tear_down() {
		global $wpdb;

		$this->dropTables();

		$wpdb->prefix = $this->originalPrefix;

		parent::tear_down();
	}

	/**
	 * Feature: enquiry-data-layer, Property 12: Adversarial values are bound, not
	 * interpolated.
	 *
	 * **Validates: Requirements 3.10**
	 *
	 * @eris-shrink 10
	 */
	public function test_adversarial_values_are_bound_not_interpolated() {
		$this->limitTo( Iterations::count( 100 ) )
			->forAll( self::scenario() )
			->then(
				function ( array $case ) {
					$rows = $this->seed( $case );

					// Every stored value reads back as written, including the two
					// decoys, which nothing in this case should have touched.
					$this->assertRowsIntact( $rows, 'after create' );
					$this->assertStoreSurvives( $rows, 'after create' );
					$this->assertSearchMatches( $rows, $case['value'], 'after create' );
					$this->assertSearchDiscriminates( $rows, $case['value'] );

					// The update path composes its own statement, so the same
					// claim has to hold for it.
					$this->applyEdit( $rows, $case['edit'] );

					$this->assertRowsIntact( $rows, 'after update' );
					$this->assertStoreSurvives( $rows, 'after update' );
					$this->assertSearchMatches( $rows, $case['edit'], 'after update' );
					$this->assertSearchMatches( $rows, $case['value'], 'after update, original term' );
				}
			);
	}

	/* ---------------------------------------------------------------------
	 * Generators
	 * ------------------------------------------------------------------ */

	/**
	 * One case: the field under test, the adversarial value it carries, the
	 * searchable field the twin carries the same value in, and a second
	 * adversarial value for the update path.
	 *
	 * The value is drawn after the target field so it can be confined to what
	 * that field holds; drawing the two independently would offer `phone` a
	 * 44-character injection string that MySQL truncates on the way in.
	 *
	 * @return \Eris\Generator
	 */
	protected static function scenario() {
		return \Eris\Generators::bind(
			\Eris\Generators::elements( self::TARGET_FIELDS ),
			function ( $target ) {
				return \Eris\Generators::associative(
					array(
						'target' => \Eris\Generators::constant( $target ),
						'value'  => \Eris\Generators::elements( self::valuesFor( $target ) ),
						'twin'   => \Eris\Generators::elements( self::TWIN_FIELDS ),
						'edit'   => \Eris\Generators::elements( self::valuesFor( 'message' ) ),
					)
				);
			}
		);
	}

	/**
	 * The adversarial values that fit a field's stored capacity.
	 *
	 * @param string $field Target field name.
	 * @return string[]
	 */
	protected static function valuesFor( $field ) {
		$capacity = isset( self::CAPACITIES[ $field ] ) ? self::CAPACITIES[ $field ] : 0;
		$values   = Generators::adversarial_values();

		if ( $capacity < 1 ) {
			return array_values( $values );
		}

		$fitting = array();

		foreach ( $values as $value ) {
			// Characters, not bytes: the columns are utf8mb4 and the capacity is
			// declared in characters. Every adversarial token is ASCII, so the
			// two agree here, and counting characters is the honest measure.
			if ( mb_strlen( (string) $value ) <= $capacity ) {
				$fitting[] = $value;
			}
		}

		return $fitting;
	}

	/* ---------------------------------------------------------------------
	 * Seeding
	 * ------------------------------------------------------------------ */

	/**
	 * Write the four enquiries of one case through `EnquiryStore::create()`.
	 *
	 * @param array $case Generated case.
	 * @return array<int,array> Written values keyed by the identifier the store assigned.
	 */
	private function seed( array $case ) {
		$this->clear();

		$value = (string) $case['value'];
		$rows  = array();

		// The subject: the adversarial value in the field under test, in a term
		// value, and in the payload snapshot as both a key and a value.
		$subject                    = self::DECOY;
		$subject['email']           = 'subject@example.com';
		$subject[ $case['target'] ] = $value;

		$rows[] = $this->store(
			'subject',
			$subject,
			array( Generators::date_at( 1 ), Generators::date_at( 2 ) ),
			array(
				'event_type'       => array( $value, 'wedding' ),
				'site_exclusivity' => array( 'exclusive-use' ),
			),
			array(
				'raw'    => $value,
				$value   => $value,
				'nested' => array( 'k' => $value, 'list' => array( $value, 'plain' ) ),
			)
		);

		// The twin: the same value, in a searchable field, on a different row.
		$twin                  = self::DECOY;
		$twin['email']         = 'twin@example.com';
		$twin[ $case['twin'] ] = $value;

		$rows[] = $this->store( 'twin', $twin, array( Generators::date_at( 3 ) ), array(), array( 'twin' => true ) );

		// Two decoys: nothing adversarial anywhere, so any appearance of one in a
		// search result, or any change to one, is the failure this property is for.
		foreach ( array( 'one', 'two' ) as $index => $suffix ) {
			$decoy          = self::DECOY;
			$decoy['email'] = 'decoy.' . $suffix . '@example.com';

			$rows[] = $this->store(
				'decoy',
				$decoy,
				array( Generators::date_at( 10 + $index ) ),
				array(
					'event_type'       => array( 'birthday' ),
					'site_exclusivity' => array( 'shared-use' ),
				),
				array( 'decoy' => $suffix )
			);
		}

		$keyed = array();

		foreach ( $rows as $row ) {
			$keyed[ $row['id'] ] = $row;
		}

		$this->assertCount( 4, $keyed, 'Each seeded enquiry should receive a distinct identifier.' );

		return $keyed;
	}

	/**
	 * Store one enquiry and return what was written, carrying its identifier.
	 *
	 * @param string $role    Role this enquiry plays: `subject`, `twin` or `decoy`.
	 * @param array  $scalars Scalar field values.
	 * @param array  $dates   Candidate dates, distinct and ascending.
	 * @param array  $terms   Term lists keyed by taxonomy.
	 * @param array  $payload Payload snapshot.
	 * @return array
	 */
	private function store( $role, array $scalars, array $dates, array $terms, array $payload ) {
		global $wpdb;

		$wpdb->last_error = '';

		$enquiry = array_merge(
			$scalars,
			array(
				'total_guests'      => self::GUESTS,
				'status'            => 'new',
				'crm_sync_state'    => 'pending',
				'created_at'        => self::WRITTEN_AT,
				'updated_at'        => self::WRITTEN_AT,
				'status_changed_at' => self::WRITTEN_AT,
				'is_test'           => false,
			)
		);

		$id = EnquiryStore::create( $enquiry, $dates, $terms, $payload );

		$this->assertNotWPError( $id, 'Storing an enquiry carrying adversarial values should succeed.' );
		$this->assertGreaterThan( 0, $id, 'The store should assign a positive identifier.' );
		$this->assertSame( '', (string) $wpdb->last_error, 'Storing an enquiry should raise no database error.' );

		return array(
			'id'               => (int) $id,
			'role'             => (string) $role,
			'first_name'       => $scalars['first_name'],
			'last_name'        => $scalars['last_name'],
			'email'            => $scalars['email'],
			'phone'            => $scalars['phone'],
			'message'          => $scalars['message'],
			'source'           => $scalars['source'],
			'selected_dates'   => $dates,
			'event_type'       => isset( $terms['event_type'] ) ? $terms['event_type'] : array(),
			'site_exclusivity' => isset( $terms['site_exclusivity'] ) ? $terms['site_exclusivity'] : array(),
			'payload'          => $payload,
		);
	}

	/**
	 * Write a second adversarial value over the subject's `message` through the
	 * update path, and record it as the expected stored value.
	 *
	 * @param array  $rows  Written values, by identifier; updated in place.
	 * @param string $value Adversarial value to write.
	 * @return void
	 */
	private function applyEdit( array &$rows, $value ) {
		global $wpdb;

		$id = 0;

		foreach ( $rows as $candidate => $row ) {
			if ( 'subject' === $row['role'] ) {
				$id = (int) $candidate;
				break;
			}
		}

		$this->assertGreaterThan( 0, $id, 'The seeded population should hold a subject enquiry.' );

		$wpdb->last_error = '';

		$result = EnquiryStore::update_fields( $id, array( 'message' => $value ) );

		$this->assertNotWPError( $result, 'Updating a field to an adversarial value should succeed.' );
		$this->assertSame( '', (string) $wpdb->last_error, 'Updating a field should raise no database error.' );

		$rows[ $id ]['message'] = (string) $value;
	}

	/* ---------------------------------------------------------------------
	 * Assertions
	 * ------------------------------------------------------------------ */

	/**
	 * Every stored enquiry reads back by identifier exactly as it was written.
	 *
	 * This carries two clauses of the property at once: the subject's value round
	 * trips unchanged, and no other row was altered on the way.
	 *
	 * @param array  $rows    Written values, by identifier.
	 * @param string $context Stage name, for the failure message.
	 * @return void
	 */
	private function assertRowsIntact( array $rows, $context ) {
		foreach ( $rows as $id => $expected ) {
			$stored = EnquiryStore::find( $id );

			$this->assertIsArray( $stored, 'Enquiry ' . $id . ' should still exist ' . $context . '.' );

			foreach ( array( 'first_name', 'last_name', 'email', 'phone', 'message', 'source' ) as $field ) {
				$this->assertSame(
					$expected[ $field ],
					$stored[ $field ],
					sprintf(
						'%s of enquiry %d should read back character-for-character %s. Written: %s',
						$field,
						$id,
						$context,
						wp_json_encode( $expected[ $field ] )
					)
				);
			}

			$this->assertSame(
				$expected['selected_dates'],
				$stored['selected_dates'],
				'The candidate dates of enquiry ' . $id . ' should be unchanged ' . $context . '.'
			);

			foreach ( array( 'event_type', 'site_exclusivity' ) as $taxonomy ) {
				$this->assertSame(
					self::sorted( $expected[ $taxonomy ] ),
					self::sorted( $stored[ $taxonomy ] ),
					'The ' . $taxonomy . ' values of enquiry ' . $id . ' should be unchanged ' . $context . '.'
				);
			}

			$this->assertSame(
				$expected['payload'],
				$stored['payload'],
				'The payload snapshot of enquiry ' . $id . ' should be unchanged ' . $context . '.'
			);

			$this->assertSame(
				self::GUESTS,
				$stored['total_guests'],
				'The guest count of enquiry ' . $id . ' should be unchanged ' . $context . '.'
			);

			$this->assertSame(
				self::WRITTEN_AT,
				$stored['created_at'],
				'The creation time of enquiry ' . $id . ' should be unchanged ' . $context . '.'
			);
		}
	}

	/**
	 * Every Enquiry Store table, the enquiry table's columns, and every row count
	 * survive the operation.
	 *
	 * @param array  $rows    Written values, by identifier.
	 * @param string $context Stage name, for the failure message.
	 * @return void
	 */
	private function assertStoreSurvives( array $rows, $context ) {
		global $wpdb;

		foreach ( array_keys( Schema::TABLES ) as $key ) {
			$table = Schema::table( $key );

			$this->assertSame(
				$table,
				$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), // phpcs:ignore WordPress.DB
				'The ' . $key . ' table should still exist ' . $context . '.'
			);
		}

		$this->assertSame(
			$this->columns,
			$this->columnNames(),
			'The enquiry table columns should be unchanged ' . $context . '.'
		);

		$dates = 0;
		$terms = 0;

		foreach ( $rows as $row ) {
			$dates += count( $row['selected_dates'] );
			$terms += count( $row['event_type'] ) + count( $row['site_exclusivity'] );
		}

		$expected = array(
			'enquiries'  => count( $rows ),
			'dates'      => $dates,
			'terms'      => $terms,
			'notes'      => 0,
			'history'    => 0,
			'rejections' => 0,
		);

		foreach ( $expected as $key => $count ) {
			$this->assertSame(
				$count,
				$this->rowCount( $key ),
				'The ' . $key . ' table should hold ' . $count . ' rows ' . $context . '.'
			);
		}
	}

	/**
	 * Searching for the value returns exactly the enquiries that hold it.
	 *
	 * @param array  $rows    Written values, by identifier.
	 * @param string $term    Search term.
	 * @param string $context Stage name, for the failure message.
	 * @return void
	 */
	private function assertSearchMatches( array $rows, $term, $context ) {
		$this->assertSame(
			self::reference( $rows, $term ),
			$this->matchingIds( $term ),
			sprintf(
				'Searching for %s should match exactly the enquiries holding it (%s).',
				wp_json_encode( $term ),
				$context
			)
		);
	}

	/**
	 * The search separates the rows holding the value from the rows that do not.
	 *
	 * Stated directly rather than left to the reference comparison, because the
	 * reference agreeing with the query proves nothing if both answer with the
	 * empty set. The twin always carries the value in a searchable field, so it
	 * must come back; neither decoy carries any adversarial token anywhere, so a
	 * decoy coming back means the term reached SQL as text rather than as a
	 * binding.
	 *
	 * @param array  $rows  Written values, by identifier.
	 * @param string $value Adversarial value the case stored.
	 * @return void
	 */
	private function assertSearchDiscriminates( array $rows, $value ) {
		$matched = $this->matchingIds( $value );

		foreach ( $rows as $id => $row ) {
			if ( 'twin' === $row['role'] ) {
				$this->assertContains(
					(int) $id,
					$matched,
					'The twin holds the value in a searchable field, so a search for '
						. wp_json_encode( $value ) . ' should match it.'
				);
			}

			if ( 'decoy' === $row['role'] ) {
				$this->assertNotContains(
					(int) $id,
					$matched,
					'A decoy holds no adversarial token, so a search for '
						. wp_json_encode( $value ) . ' should not match it.'
				);
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * The reference implementation
	 * ------------------------------------------------------------------ */

	/**
	 * The identifiers a search for the term should return, by plain PHP.
	 *
	 * Every character of the term counts as itself: `stripos()` has no wildcards,
	 * which is exactly the behaviour Requirement 12.4 asks of the query and the
	 * behaviour an interpolated term would lose.
	 *
	 * @param array  $rows Written values, by identifier.
	 * @param string $term Search term.
	 * @return int[] Ascending identifiers.
	 */
	private static function reference( array $rows, $term ) {
		$ids = array();

		foreach ( $rows as $id => $row ) {
			foreach ( self::SEARCH_FIELDS as $field ) {
				if ( false !== stripos( (string) $row[ $field ], (string) $term ) ) {
					$ids[] = (int) $id;
					break;
				}
			}
		}

		sort( $ids, SORT_NUMERIC );

		return $ids;
	}

	/* ---------------------------------------------------------------------
	 * Database helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Run the search the list route will run, and return the identifiers.
	 *
	 * The composition `EnquiryStore` owns: the builder's `where` with its
	 * bindings through `$wpdb->prepare()`, and the enquiry table aliased as the
	 * builder expects.
	 *
	 * @param string $term Search term.
	 * @return int[] Ascending identifiers.
	 */
	private function matchingIds( $term ) {
		global $wpdb;

		$built = EnquiryQuery::build( array( 's' => $term ), array( 'dates' => Schema::table( 'dates' ) ) );
		$alias = EnquiryQuery::ALIAS;

		$this->assertNotEmpty( $built['bindings'], 'A search term should travel as a binding, not as SQL.' );

		$sql = 'SELECT ' . $alias . '.id FROM ' . Schema::table( 'enquiries' ) . ' ' . $alias
			. ' WHERE ' . $built['where']
			. ' ' . $built['order']
			. ' ' . $built['limit'];

		$wpdb->last_error = '';
		$ids              = (array) $wpdb->get_col( $wpdb->prepare( $sql, $built['bindings'] ) ); // phpcs:ignore WordPress.DB

		$this->assertSame(
			'',
			(string) $wpdb->last_error,
			'The composed search should run without error. Term: ' . wp_json_encode( $term )
		);

		$ids = array_map( 'intval', $ids );
		sort( $ids, SORT_NUMERIC );

		return $ids;
	}

	/**
	 * Rows currently in one Enquiry Store table.
	 *
	 * @param string $key Table key.
	 * @return int
	 */
	private function rowCount( $key ) {
		global $wpdb;

		$table = Schema::table( $key );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * The enquiry table's column names, in declaration order.
	 *
	 * @return string[]
	 */
	private function columnNames() {
		global $wpdb;

		$table = Schema::table( 'enquiries' );

		return array_map( 'strval', (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Empty every Enquiry Store table between iterations.
	 *
	 * `DELETE` rather than `TRUNCATE`: the identifiers keep climbing, which is
	 * closer to a live table and means a stale identifier can never be mistaken
	 * for a fresh one.
	 *
	 * @return void
	 */
	private function clear() {
		global $wpdb;

		foreach ( array_keys( Schema::TABLES ) as $key ) {
			$table = Schema::table( $key );
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * Drop every Enquiry Store table at the test prefix.
	 *
	 * @return void
	 */
	private function dropTables() {
		global $wpdb;

		foreach ( array_keys( Schema::TABLES ) as $key ) {
			$table = Schema::table( $key );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * A copy of a list, sorted, so two term sets compare as sets.
	 *
	 * @param array $values Term values.
	 * @return array
	 */
	private static function sorted( array $values ) {
		sort( $values );

		return $values;
	}
}
