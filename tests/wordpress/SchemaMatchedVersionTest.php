<?php
/**
 * Property 5 of the enquiry-data-layer design: a matched schema version performs
 * no work.
 *
 * This one needs a real database, because the claim is about statements that are
 * *not* issued: it is only meaningful when there is a live `$wpdb` that would
 * have issued them. Every query the schema manager sends is captured through the
 * `query` filter, so the assertion is made against what actually reached the
 * database rather than against a stub standing in for it.
 *
 * @package MarthrownEnquiryHub
 */

use Eris\TestTrait;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;

/**
 * Class SchemaMatchedVersionTest
 */
class SchemaMatchedVersionTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Statements recorded while the schema manager runs.
	 *
	 * @var array<int,string>
	 */
	protected $recorded = array();

	/**
	 * Whether the `query` filter is currently recording.
	 *
	 * @var bool
	 */
	protected $recording = false;

	/**
	 * Statement types that create or alter storage.
	 *
	 * `DROP` and `TRUNCATE` are in here alongside `CREATE` and `ALTER` because a
	 * matched version issuing either would break the same requirement far more
	 * severely than an extra `CREATE` would.
	 */
	const DDL_PATTERN = '/^\s*(CREATE|ALTER|DROP|TRUNCATE|RENAME)\s/i';

	/**
	 * Fixed timestamp every seeded row carries, so a snapshot comparison turns
	 * only on what the schema manager did and never on the clock.
	 */
	const SEEDED_AT = '2025-05-01 09:00:00';

	/**
	 * Install the schema once, outside the per-test transaction.
	 *
	 * The plugin's own bootstrap does not run in the test environment, so the
	 * class files are required here. `dbDelta()` issues DDL, which MySQL commits
	 * implicitly, so it has to happen before the transaction each test runs in
	 * rather than inside one.
	 *
	 * @param mixed $factory WordPress fixture factory (unused).
	 * @return void
	 */
	public static function wpSetUpBeforeClass( $factory = null ) {
		unset( $factory );

		require_once MEH_INCLUDES_DIR . 'class-log.php';
		require_once MEH_INCLUDES_DIR . 'class-schema.php';

		delete_option( Schema::VERSION_OPTION );

		Schema::install();
	}

	/**
	 * Start capturing statements before each test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		add_filter( 'query', array( $this, 'record_query' ) );
	}

	/**
	 * Stop capturing and clear the Enquiry Store tables.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_filter( 'query', array( $this, 'record_query' ) );

		$this->clear_store();

		parent::tear_down();
	}

	/**
	 * Feature: enquiry-data-layer, Property 5: For any Enquiry Store state, when
	 * the stored schema version equals the current schema version, running the
	 * schema manager any number of times issues no table creation or alteration
	 * statement and leaves every row and the stored version unchanged.
	 *
	 * Validates: Requirements 1.17
	 *
	 * @eris-shrink 10
	 */
	public function test_matched_schema_version_performs_no_work() {
		// Every assertion below is about statements that are absent, which is
		// only evidence when a present statement would have been seen. This
		// checks the instrument before trusting it.
		$this->assert_capture_is_live();

		$this->limitTo( Iterations::count( 100 ) )
			->forAll( $this->store_state(), Eris\Generators::choose( 1, 4 ) )
			->then(
				function ( array $enquiries, $runs ) {
					$this->clear_store();
					$this->seed_store( $enquiries );

					update_option( Schema::VERSION_OPTION, Schema::CURRENT_VERSION, false );

					$before = $this->snapshot();

					$this->recorded  = array();
					$this->recording = true;

					for ( $run = 0; $run < (int) $runs; $run++ ) {
						// The guard latches after its first run, so it is
						// released here: "any number of times" has to mean the
						// version comparison is genuinely made each time, not
						// short-circuited by a flag.
						$this->release_upgrade_guard();

						$this->assertTrue(
							Schema::maybe_upgrade(),
							'A matched version should report the schema as current.'
						);

						$this->assertTrue(
							Schema::init(),
							'Booting the schema layer at a matched version should report it as current.'
						);
					}

					$this->recording = false;

					$this->assertSame(
						array(),
						$this->offending_statements( self::DDL_PATTERN ),
						'A matched version issued a table creation or alteration statement.'
					);

					$this->assertSame(
						array(),
						$this->statements_touching_store(),
						'A matched version issued a statement against an Enquiry Store table.'
					);

					$this->assertSame(
						$before,
						$this->snapshot(),
						'A matched version altered stored rows.'
					);

					$this->assertSame(
						Schema::CURRENT_VERSION,
						Schema::stored_version(),
						'A matched version altered the stored schema version.'
					);
				}
			);
	}

	/* -----------------------------------------------------------------------
	 * Generators
	 * -------------------------------------------------------------------- */

	/**
	 * An Enquiry Store state: 0 to 3 enquiries, the empty population included.
	 *
	 * Optional fields are drawn from the generator that may leave them empty or
	 * unsupplied, so an empty `phone`, an empty `message`, a null `total_guests`
	 * and an empty term set are all reachable states.
	 *
	 * @return Eris\Generator
	 */
	protected function store_state() {
		return Eris\Generators::bind(
			Eris\Generators::choose( 0, 3 ),
			function ( $count ) {
				if ( (int) $count < 1 ) {
					return Eris\Generators::constant( array() );
				}

				return Eris\Generators::vector(
					(int) $count,
					Generators::enquiry_with_empty_optionals()
				);
			}
		);
	}

	/* -----------------------------------------------------------------------
	 * Store fixtures
	 * -------------------------------------------------------------------- */

	/**
	 * Write the generated state into all six tables.
	 *
	 * Every table gets rows, not just `enquiries`: the property is about every
	 * row surviving, and a table nothing was written to could not witness that.
	 *
	 * @param array<int,array> $enquiries Generated enquiry field maps.
	 * @return void
	 */
	protected function seed_store( array $enquiries ) {
		global $wpdb;

		foreach ( $enquiries as $index => $fields ) {
			$inserted = $wpdb->insert(
				Schema::table( 'enquiries' ),
				array(
					'first_name'        => $fields['first_name'],
					'last_name'         => $fields['last_name'],
					'email'             => $fields['email'],
					'phone'             => $fields['phone'],
					'total_guests'      => $fields['total_guests'],
					'message'           => $fields['message'],
					'status'            => 'new',
					'crm_sync_state'    => 'pending',
					'created_at'        => self::SEEDED_AT,
					'updated_at'        => self::SEEDED_AT,
					'status_changed_at' => self::SEEDED_AT,
					'source'            => 'webhook:test-' . $index,
					'is_test'           => 1,
					'payload'           => wp_json_encode( $fields ),
				)
			);

			$this->assertNotFalse( $inserted, 'Seeding an enquiry row failed: ' . $wpdb->last_error );

			$enquiry_id = (int) $wpdb->insert_id;

			$position = 0;

			foreach ( $fields['date_ranges'] as $range ) {
				$wpdb->insert(
					Schema::table( 'dates' ),
					array(
						'enquiry_id' => $enquiry_id,
						'start_date' => $range['start'],
						'end_date'   => $range['end'],
						'position'   => $position,
					)
				);

				++$position;
			}

			foreach ( array( 'event_type', 'site_exclusivity' ) as $taxonomy ) {
				foreach ( $fields[ $taxonomy ] as $term ) {
					$wpdb->insert(
						Schema::table( 'terms' ),
						array(
							'enquiry_id' => $enquiry_id,
							'taxonomy'   => $taxonomy,
							'term'       => $term,
						)
					);
				}
			}

			$wpdb->insert(
				Schema::table( 'notes' ),
				array(
					'enquiry_id' => $enquiry_id,
					'body'       => 'Called back on ' . $fields['email'],
					'author_id'  => 0,
					'created_at' => '2025-05-01 10:00:00',
				)
			);

			$wpdb->insert(
				Schema::table( 'history' ),
				array(
					'enquiry_id'  => $enquiry_id,
					'entry_type'  => 'created',
					'description' => 'Enquiry received',
					'context'     => wp_json_encode( array( 'source' => 'webhook:test-' . $index ) ),
					'actor_id'    => 0,
					'created_at'  => self::SEEDED_AT,
				)
			);

			$wpdb->insert(
				Schema::table( 'rejections' ),
				array(
					'reason'     => 'duplicate',
					'detail'     => wp_json_encode( array( 'enquiry_id' => $enquiry_id ) ),
					'payload'    => wp_json_encode( $fields ),
					'email'      => $fields['email'],
					'source'     => 'webhook:test-' . $index,
					'is_test'    => 1,
					'created_at' => '2025-05-01 09:05:00',
				)
			);
		}
	}

	/**
	 * Remove every Enquiry Store row.
	 *
	 * Each Eris iteration seeds its own state, so rows are cleared between them
	 * rather than left to accumulate. `DELETE` is deliberate: `TRUNCATE` is DDL
	 * and would commit the surrounding transaction.
	 *
	 * @return void
	 */
	protected function clear_store() {
		global $wpdb;

		$recording       = $this->recording;
		$this->recording = false;

		foreach ( Schema::tables() as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema, not input.
			$wpdb->query( "DELETE FROM {$table}" );
		}

		$this->recording = $recording;
	}

	/**
	 * Every row of every Enquiry Store table, in a stable order.
	 *
	 * @return array<string,array>
	 */
	protected function snapshot() {
		global $wpdb;

		$recording       = $this->recording;
		$this->recording = false;

		$snapshot = array();

		foreach ( Schema::tables() as $key => $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema, not input.
			$snapshot[ $key ] = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );
		}

		$this->recording = $recording;

		return $snapshot;
	}

	/* -----------------------------------------------------------------------
	 * Statement capture
	 * -------------------------------------------------------------------- */

	/**
	 * Record a statement on its way to the database, unaltered.
	 *
	 * @param string $query SQL statement.
	 * @return string The same statement.
	 */
	public function record_query( $query ) {
		if ( $this->recording ) {
			$this->recorded[] = (string) $query;
		}

		return $query;
	}

	/**
	 * Recorded statements matching a pattern.
	 *
	 * @param string $pattern Regular expression.
	 * @return array<int,string>
	 */
	protected function offending_statements( $pattern ) {
		return array_values(
			array_filter(
				$this->recorded,
				function ( $query ) use ( $pattern ) {
					return 1 === preg_match( $pattern, $query );
				}
			)
		);
	}

	/**
	 * Recorded statements naming any Enquiry Store table.
	 *
	 * A matched version reads one option and stops, so it should reach none of
	 * these tables at all — the stronger half of "performs no work".
	 *
	 * @return array<int,string>
	 */
	protected function statements_touching_store() {
		$tables = array_values( Schema::tables() );

		return array_values(
			array_filter(
				$this->recorded,
				function ( $query ) use ( $tables ) {
					foreach ( $tables as $table ) {
						if ( false !== stripos( $query, $table ) ) {
							return true;
						}
					}

					return false;
				}
			)
		);
	}

	/* -----------------------------------------------------------------------
	 * Internals
	 * -------------------------------------------------------------------- */

	/**
	 * Confirm the capture actually sees a statement of each kind it is asked to
	 * rule out: one naming an Enquiry Store table, and one piece of DDL.
	 *
	 * Without this, a `query` filter that never fired would satisfy every
	 * assertion in the property while proving nothing.
	 *
	 * @return void
	 */
	private function assert_capture_is_live() {
		global $wpdb;

		$table = Schema::table( 'enquiries' );

		$this->recorded  = array();
		$this->recording = true;

		// A real read, so the capture is shown working on genuine `$wpdb`
		// traffic, and a `CREATE` sent down the same filter without being
		// executed. The `CREATE` is deliberately not run: the WordPress test
		// case rewrites table creation into its TEMPORARY form, and a temporary
		// table of the same name would shadow the real one for the rest of the
		// session, breaking every later snapshot.
		$wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB
		apply_filters( 'query', "CREATE TABLE {$table} (id bigint(20) unsigned NOT NULL)" );

		$this->recording = false;

		$this->assertNotSame(
			array(),
			$this->statements_touching_store(),
			'The statement capture is not recording statements against the Enquiry Store.'
		);

		$this->assertNotSame(
			array(),
			$this->offending_statements( self::DDL_PATTERN ),
			'The statement capture is not recognising table creation statements.'
		);

		$this->recorded = array();
	}

	/**
	 * Clear the once-per-request latch inside the schema manager.
	 *
	 * @return void
	 */
	protected function release_upgrade_guard() {
		$property = new \ReflectionProperty( Schema::class, 'checked' );
		$property->setAccessible( true );
		$property->setValue( null, false );
	}
}
