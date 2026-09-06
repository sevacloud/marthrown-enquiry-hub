<?php
/**
 * Unit tests for the two intake guards (task 8.3).
 *
 * The worked examples of Requirement 4: duplicate identity is email plus the
 * exact candidate date set inside the window (4.2, 4.4), the same email with
 * the same dates outside the window is a new enquiry (4.5), and the request
 * count keys on the submitted email address rather than on a client IP that a
 * server-to-server webhook always presents as the site's own (4.6, 4.7).
 *
 * Properties 14 and 15 carry the quantified claims; these are the examples and
 * the boundaries.
 *
 * Like the other store-backed tests, this one works against real tables at its
 * own prefix: the WordPress test case rewrites `CREATE TABLE` into its
 * `TEMPORARY` form, and `Schema::install()` verifies each table through
 * `SHOW TABLES`, which cannot see a temporary table.
 *
 * @package MarthrownEnquiryHub
 */

use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\DuplicateDetector;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\Schema;

/**
 * Class DuplicateDetectorTest
 */
class DuplicateDetectorTest extends WP_UnitTestCase {

	/**
	 * Extra prefix segment, so these tables cannot collide with a real Enquiry
	 * Store table sitting in the same test database.
	 */
	const PREFIX_SEGMENT = 'mehdup_';

	/**
	 * The instant every test treats as "now".
	 */
	const NOW = '2025-06-01 12:00:00';

	/**
	 * The WordPress table prefix in force outside this test.
	 *
	 * @var string
	 */
	private $original_prefix = '';

	/**
	 * Addresses whose counters need clearing afterwards.
	 *
	 * @var string[]
	 */
	private $counted = array();

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
		require_once MEH_INCLUDES_DIR . 'class-duplicate-detector.php';
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

		Clock::freeze( self::NOW );
	}

	public function tear_down() {
		global $wpdb;

		foreach ( $this->counted as $email ) {
			DuplicateDetector::reset( $email );
		}

		$this->counted = array();

		remove_all_filters( DuplicateDetector::WINDOW_FILTER );
		remove_all_filters( DuplicateDetector::RATE_EMAIL_FILTER );

		Clock::unfreeze();
		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/* ---------------------------------------------------------------------
	 * find_duplicate()
	 * ------------------------------------------------------------------ */

	/**
	 * The same email and the same date set inside the window is a duplicate, and
	 * the existing identifier comes back so the rejection row can reference it
	 * (Requirements 4.2, 4.3).
	 *
	 * @return void
	 */
	public function test_same_email_and_same_dates_inside_the_window_is_a_duplicate() {
		$id = $this->seed( 'ada@example.com', array( '2025-08-16', '2025-08-23' ), 60 );

		$this->assertSame(
			$id,
			DuplicateDetector::find_duplicate( 'ada@example.com', array( '2025-08-16', '2025-08-23' ) )
		);
	}

	/**
	 * Date sets are compared as sets: order and repetition are irrelevant
	 * (Requirement 4.2).
	 *
	 * @return void
	 */
	public function test_date_order_and_repetition_do_not_matter() {
		$id = $this->seed( 'ada@example.com', array( '2025-08-16', '2025-08-23' ), 60 );

		$this->assertSame(
			$id,
			DuplicateDetector::find_duplicate(
				'ada@example.com',
				array( '2025-08-23', '2025-08-16', '2025-08-23' )
			)
		);
	}

	/**
	 * A subset and a superset are both different enquiries, about different days
	 * (Requirement 4.2).
	 *
	 * @return void
	 */
	public function test_a_subset_or_superset_of_the_dates_is_not_a_duplicate() {
		$this->seed( 'ada@example.com', array( '2025-08-16', '2025-08-23' ), 60 );

		$this->assertSame(
			0,
			DuplicateDetector::find_duplicate( 'ada@example.com', array( '2025-08-16' ) ),
			'A subset of the stored dates is not the same enquiry.'
		);

		$this->assertSame(
			0,
			DuplicateDetector::find_duplicate(
				'ada@example.com',
				array( '2025-08-16', '2025-08-23', '2025-08-30' )
			),
			'A superset of the stored dates is not the same enquiry.'
		);
	}

	/**
	 * A different email with the same dates is a different enquirer
	 * (Requirement 4.2).
	 *
	 * @return void
	 */
	public function test_a_different_email_is_not_a_duplicate() {
		$this->seed( 'ada@example.com', array( '2025-08-16', '2025-08-23' ), 60 );

		$this->assertSame(
			0,
			DuplicateDetector::find_duplicate( 'grace@example.com', array( '2025-08-16', '2025-08-23' ) )
		);
	}

	/**
	 * The window boundary: 899 seconds earlier is a duplicate, 900 seconds
	 * earlier is a genuine second enquiry (Requirements 4.2, 4.5).
	 *
	 * @return void
	 */
	public function test_the_window_boundary_is_strict() {
		$inside = $this->seed( 'ada@example.com', array( '2025-08-16' ), 899 );
		$this->seed( 'grace@example.com', array( '2025-08-16' ), 900 );

		$this->assertSame(
			$inside,
			DuplicateDetector::find_duplicate( 'ada@example.com', array( '2025-08-16' ) ),
			'A second short of the window is still a duplicate.'
		);

		$this->assertSame(
			0,
			DuplicateDetector::find_duplicate( 'grace@example.com', array( '2025-08-16' ) ),
			'An enquiry created a full window ago is no longer a duplicate.'
		);
	}

	/**
	 * Widening the window through the filter brings an older enquiry back into
	 * range (Requirement 4.4).
	 *
	 * @return void
	 */
	public function test_the_window_filter_changes_the_verdict() {
		$id = $this->seed( 'ada@example.com', array( '2025-08-16' ), 1800 );

		$this->assertSame(
			0,
			DuplicateDetector::find_duplicate( 'ada@example.com', array( '2025-08-16' ) )
		);

		add_filter(
			DuplicateDetector::WINDOW_FILTER,
			static function () {
				return 3600;
			}
		);

		$this->assertSame(
			$id,
			DuplicateDetector::find_duplicate( 'ada@example.com', array( '2025-08-16' ) )
		);
	}

	/**
	 * With more than one qualifying enquiry the most recent identifier comes
	 * back, since that is the one a reader of the rejection row wants
	 * (Requirement 4.3).
	 *
	 * @return void
	 */
	public function test_the_most_recent_match_is_returned() {
		$this->seed( 'ada@example.com', array( '2025-08-16' ), 600 );
		$recent = $this->seed( 'ada@example.com', array( '2025-08-16' ), 120 );

		$this->assertSame(
			$recent,
			DuplicateDetector::find_duplicate( 'ada@example.com', array( '2025-08-16' ) )
		);
	}

	/**
	 * A submission carrying no readable candidate date has no date set to be
	 * equal to, so it is never reported as a duplicate.
	 *
	 * @return void
	 */
	public function test_no_dates_and_no_email_are_never_duplicates() {
		$this->seed( 'ada@example.com', array( '2025-08-16' ), 60 );

		$this->assertSame( 0, DuplicateDetector::find_duplicate( 'ada@example.com', array() ) );
		$this->assertSame( 0, DuplicateDetector::find_duplicate( 'ada@example.com', array( 'soon' ) ) );
		$this->assertSame( 0, DuplicateDetector::find_duplicate( '', array( '2025-08-16' ) ) );
	}

	/* ---------------------------------------------------------------------
	 * is_rate_limited()
	 * ------------------------------------------------------------------ */

	/**
	 * The first five requests pass and the sixth is refused, counting the request
	 * being asked about (Requirement 4.6).
	 *
	 * @return void
	 */
	public function test_the_sixth_request_for_one_address_is_limited() {
		$email = $this->counted( 'ada@example.com' );

		for ( $attempt = 1; $attempt <= 5; $attempt++ ) {
			$this->assertFalse(
				DuplicateDetector::is_rate_limited( $email ),
				"Request {$attempt} should be within the allowance."
			);
		}

		$this->assertTrue( DuplicateDetector::is_rate_limited( $email ), 'The sixth request is refused.' );
		$this->assertTrue( DuplicateDetector::is_rate_limited( $email ), 'So is the seventh.' );
		$this->assertSame( 7, DuplicateDetector::hits( $email ) );
	}

	/**
	 * One address's count never affects another's (Requirement 4.6).
	 *
	 * @return void
	 */
	public function test_counts_are_per_address() {
		$ada   = $this->counted( 'ada@example.com' );
		$grace = $this->counted( 'grace@example.com' );

		for ( $attempt = 1; $attempt <= 6; $attempt++ ) {
			DuplicateDetector::is_rate_limited( $ada );
		}

		$this->assertTrue( DuplicateDetector::is_rate_limited( $ada ) );
		$this->assertFalse( DuplicateDetector::is_rate_limited( $grace ) );
		$this->assertSame( 1, DuplicateDetector::hits( $grace ) );
	}

	/**
	 * The count resets once the window has elapsed (Requirement 4.6).
	 *
	 * @return void
	 */
	public function test_the_count_resets_after_the_window() {
		$email = $this->counted( 'ada@example.com' );

		for ( $attempt = 1; $attempt <= 6; $attempt++ ) {
			DuplicateDetector::is_rate_limited( $email );
		}

		$this->assertSame( 6, DuplicateDetector::hits( $email ) );

		Clock::freeze( Clock::offset( 900, self::NOW ) );

		$this->assertSame( 0, DuplicateDetector::hits( $email ), 'A new window starts empty.' );
		$this->assertFalse( DuplicateDetector::is_rate_limited( $email ) );
	}

	/**
	 * The limit is filterable (Requirement 4.7).
	 *
	 * @return void
	 */
	public function test_the_rate_limit_filter_changes_the_allowance() {
		add_filter(
			DuplicateDetector::RATE_EMAIL_FILTER,
			static function () {
				return array( 2, 60 );
			}
		);

		$email = $this->counted( 'ada@example.com' );

		$this->assertFalse( DuplicateDetector::is_rate_limited( $email ) );
		$this->assertTrue( DuplicateDetector::is_rate_limited( $email ) );
	}

	/**
	 * Case and surrounding whitespace do not open a second bucket for one
	 * address.
	 *
	 * @return void
	 */
	public function test_the_bucket_ignores_case_and_whitespace() {
		$email = $this->counted( 'ada@example.com' );

		DuplicateDetector::is_rate_limited( ' Ada@Example.com ' );

		$this->assertSame( 1, DuplicateDetector::hits( $email ) );
	}

	/**
	 * A submission carrying no address is not counted: the validator rejects it
	 * moments later, and bucketing every such request together would throttle
	 * unrelated submissions.
	 *
	 * @return void
	 */
	public function test_an_empty_address_is_not_counted() {
		$this->assertFalse( DuplicateDetector::is_rate_limited( '' ) );
		$this->assertSame( 0, DuplicateDetector::hits( '' ) );
	}

	/* ---------------------------------------------------------------------
	 * Fixtures
	 * ------------------------------------------------------------------ */

	/**
	 * Store one enquiry a given number of seconds before "now".
	 *
	 * @param string $email       Email address.
	 * @param array  $dates       Candidate dates.
	 * @param int    $seconds_ago Age of the enquiry, in seconds.
	 * @return int Enquiry identifier.
	 */
	private function seed( $email, array $dates, $seconds_ago ) {
		$created = Clock::mysql( Clock::offset( -1 * (int) $seconds_ago, self::NOW ) );

		$id = EnquiryStore::create(
			array(
				'first_name'        => 'Ada',
				'last_name'         => 'Lovelace',
				'email'             => $email,
				'status'            => 'new',
				'created_at'        => $created,
				'updated_at'        => $created,
				'status_changed_at' => $created,
				'source'            => 'webhook:fixture',
			),
			$dates,
			array(),
			array( 'seeded' => true )
		);

		$this->assertIsInt( $id, 'Seeding an enquiry should succeed.' );

		return (int) $id;
	}

	/**
	 * Register an address for counter cleanup, and return it.
	 *
	 * @param string $email Email address.
	 * @return string
	 */
	private function counted( $email ) {
		$this->counted[] = $email;

		DuplicateDetector::reset( $email );

		return $email;
	}

	/**
	 * Remove the fixture tables.
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
