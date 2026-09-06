<?php
/**
 * Unit tests for the two filterable intake guard values (task 8.3).
 *
 * The window and the rate limit are resolved without touching a database or a
 * transient, so they belong in the `pure` suite: `ABSPATH` so the guard at the
 * top of the class file does not exit, and FakeFilters to stand in for the hook
 * system. The behaviour of the guards themselves — the duplicate query and the
 * per-email counter — needs a real WordPress and lives in the `wordpress` suite.
 *
 * Properties 14 and 15 cover the quantified claims; what is asserted here is the
 * defaults and the filter contract (Requirements 4.4, 4.7).
 *
 * @package MarthrownEnquiryHub
 */

use MarthrownEnquiryHub\DuplicateDetector;
use MarthrownEnquiryHub\Tests\Fakes\FakeFilters;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

require_once dirname( __DIR__ ) . '/fakes/FakeFilters.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-log.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-clock.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-schema.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-duplicate-detector.php';

/**
 * Class DuplicateDetectorConfigTest
 */
class DuplicateDetectorConfigTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		FakeFilters::install();
	}

	protected function tearDown(): void {
		FakeFilters::uninstall();

		parent::tearDown();
	}

	/**
	 * The duplicate window defaults to 900 seconds (Requirement 4.4).
	 *
	 * @return void
	 */
	public function test_window_defaults_to_fifteen_minutes() {
		$this->assertSame( 900, DuplicateDetector::window() );
		$this->assertSame( 900, DuplicateDetector::DEFAULT_WINDOW );
	}

	/**
	 * The window is filterable (Requirement 4.4).
	 *
	 * @return void
	 */
	public function test_window_is_filterable() {
		FakeFilters::set( DuplicateDetector::WINDOW_FILTER, 3600 );

		$this->assertSame( 3600, DuplicateDetector::window() );
	}

	/**
	 * A window of zero or less switches duplicate detection off; a nonsense value
	 * falls back to the default rather than to nothing.
	 *
	 * @return void
	 */
	public function test_window_handles_out_of_range_and_nonsense_values() {
		FakeFilters::set( DuplicateDetector::WINDOW_FILTER, 0 );
		$this->assertSame( 0, DuplicateDetector::window() );

		FakeFilters::set( DuplicateDetector::WINDOW_FILTER, -60 );
		$this->assertSame( 0, DuplicateDetector::window() );

		FakeFilters::set( DuplicateDetector::WINDOW_FILTER, 'soon' );
		$this->assertSame( 900, DuplicateDetector::window() );
	}

	/**
	 * The rate limit defaults to 6 requests per email per 900 seconds, sharing
	 * the duplicate window's time horizon (Requirement 4.7).
	 *
	 * @return void
	 */
	public function test_rate_limit_defaults_to_six_per_fifteen_minutes() {
		$this->assertSame(
			array(
				'max'     => 6,
				'seconds' => 900,
			),
			DuplicateDetector::rate_limit()
		);

		$this->assertSame(
			DuplicateDetector::DEFAULT_WINDOW,
			DuplicateDetector::DEFAULT_RATE_WINDOW,
			'The two intake guards should share one time horizon.'
		);
	}

	/**
	 * The rate limit is filterable positionally and by name (Requirement 4.7).
	 *
	 * @return void
	 */
	public function test_rate_limit_is_filterable_either_shape() {
		FakeFilters::set( DuplicateDetector::RATE_EMAIL_FILTER, array( 3, 60 ) );

		$this->assertSame(
			array(
				'max'     => 3,
				'seconds' => 60,
			),
			DuplicateDetector::rate_limit()
		);

		FakeFilters::set(
			DuplicateDetector::RATE_EMAIL_FILTER,
			array(
				'max'     => 10,
				'seconds' => 120,
			)
		);

		$this->assertSame(
			array(
				'max'     => 10,
				'seconds' => 120,
			),
			DuplicateDetector::rate_limit()
		);
	}

	/**
	 * A broken filter value falls back to the default for the half that is
	 * unusable, so intake is never left with an allowance of zero.
	 *
	 * @return void
	 */
	public function test_rate_limit_falls_back_for_unusable_values() {
		FakeFilters::set( DuplicateDetector::RATE_EMAIL_FILTER, 'six' );

		$this->assertSame(
			array(
				'max'     => 6,
				'seconds' => 900,
			),
			DuplicateDetector::rate_limit()
		);

		FakeFilters::set( DuplicateDetector::RATE_EMAIL_FILTER, array( 0, 45 ) );

		$this->assertSame(
			array(
				'max'     => 6,
				'seconds' => 45,
			),
			DuplicateDetector::rate_limit()
		);
	}

	/**
	 * The counting key carries a hash rather than the address itself, so no
	 * enquirer's email sits in the options table under a readable name.
	 *
	 * @return void
	 */
	public function test_transient_key_hashes_the_address_and_ignores_case() {
		$key = DuplicateDetector::transient_key( 'Ada@Example.com' );

		$this->assertStringStartsWith( DuplicateDetector::TRANSIENT_PREFIX, $key );
		$this->assertStringNotContainsStringIgnoringCase( 'ada@example.com', $key );
		$this->assertSame( $key, DuplicateDetector::transient_key( '  ada@example.com ' ) );
		$this->assertNotSame( $key, DuplicateDetector::transient_key( 'grace@example.com' ) );
	}
}
