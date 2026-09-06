<?php
/**
 * Unit tests for the lifecycle transition table and the settled set.
 *
 * The table, `can()`, `allowed_from()` and `is_settled()` read constants and
 * nothing else — no `$wpdb`, no WordPress function — so they belong in the
 * `pure` suite. `transition()` writes rows, so its behaviour is exercised by the
 * two property tests (Properties 20 and 21) against a real database.
 *
 * What is asserted here is the shape of the machine: the six statuses, every
 * permitted pair, the forward-only invariant, `closed` being terminal through
 * the ordinary lookup, and the settled set being `converted` and `lost` only.
 *
 * Covers Requirements 7.1, 7.2, 7.3, 7.4, 7.10, 7.11, 7.12.
 *
 * @package MarthrownEnquiryHub
 */

use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\Lifecycle;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

require_once dirname( __DIR__, 2 ) . '/includes/class-lifecycle.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-enquiry-store.php';

class LifecycleTransitionTableTest extends TestCase {

	/**
	 * Every permitted pair the acceptance criteria name, and nothing else.
	 *
	 * Written out longhand rather than derived from `Lifecycle::TRANSITIONS`, so
	 * a mistake in the table is a failure here rather than a tautology.
	 *
	 * @var array<string,string[]>
	 */
	const EXPECTED = array(
		'new'       => array( 'contacted', 'quoted', 'converted', 'lost' ),
		'contacted' => array( 'quoted', 'converted', 'lost' ),
		'quoted'    => array( 'converted', 'lost' ),
		'converted' => array( 'closed' ),
		'lost'      => array( 'closed' ),
		'closed'    => array(),
	);

	/**
	 * Requirement 7.1: exactly six statuses, in lifecycle order.
	 *
	 * @return void
	 */
	public function test_exactly_the_six_recognised_statuses() {
		$this->assertSame(
			array( 'new', 'contacted', 'quoted', 'converted', 'lost', 'closed' ),
			Lifecycle::STATUSES
		);

		$this->assertSame(
			Lifecycle::STATUSES,
			array_keys( Lifecycle::TRANSITIONS ),
			'Every status should have a row in the transition table, and no other key should.'
		);

		foreach ( Lifecycle::STATUSES as $status ) {
			$this->assertTrue( Lifecycle::is_status( $status ) );
		}

		foreach ( array( '', 'NEW', 'pending', 'won', 'archived', '0' ) as $unknown ) {
			$this->assertFalse( Lifecycle::is_status( $unknown ), sprintf( '"%s" is not a status.', $unknown ) );
		}
	}

	/**
	 * Requirements 7.2, 7.3, 7.4, 7.10: `allowed_from()` answers with exactly the
	 * permitted set for every status, and `can()` agrees with it on all 36 pairs.
	 *
	 * @return void
	 */
	public function test_every_pair_is_permitted_exactly_when_the_criteria_say_so() {
		foreach ( self::EXPECTED as $from => $allowed ) {
			$this->assertSame(
				$allowed,
				Lifecycle::allowed_from( $from ),
				sprintf( 'The permitted set from %s should be exactly what Requirement 7 names.', $from )
			);
		}

		foreach ( Lifecycle::STATUSES as $from ) {
			foreach ( Lifecycle::STATUSES as $to ) {
				$expected = in_array( $to, self::EXPECTED[ $from ], true );

				$this->assertSame(
					$expected,
					Lifecycle::can( $from, $to ),
					sprintf( '%s -> %s should be %s.', $from, $to, $expected ? 'permitted' : 'refused' )
				);
			}
		}
	}

	/**
	 * The lifecycle is forward-only: no permitted pair names a status at or
	 * before the current one in lifecycle order, so no enquiry can return to a
	 * status it has left.
	 *
	 * @return void
	 */
	public function test_no_transition_moves_backwards_or_to_itself() {
		$order = array_flip( Lifecycle::STATUSES );

		foreach ( Lifecycle::TRANSITIONS as $from => $allowed ) {
			foreach ( $allowed as $to ) {
				$this->assertArrayHasKey( $to, $order, sprintf( '%s names an unrecognised status.', $from ) );
				$this->assertGreaterThan(
					$order[ $from ],
					$order[ $to ],
					sprintf( '%s -> %s moves backwards or to itself.', $from, $to )
				);
			}
		}
	}

	/**
	 * Requirement 7.11: `closed` is terminal, and it is terminal through the same
	 * lookup that refuses any other unpermitted pair.
	 *
	 * @return void
	 */
	public function test_closed_is_terminal_through_the_ordinary_lookup() {
		$this->assertSame( array(), Lifecycle::allowed_from( 'closed' ) );

		foreach ( Lifecycle::STATUSES as $to ) {
			$this->assertFalse( Lifecycle::can( 'closed', $to ), sprintf( 'closed -> %s should be refused.', $to ) );
		}

		// The empty answer is the table's, not a special case: an unrecognised
		// current status is just as immovable, by the same missing-row route.
		$this->assertSame( array(), Lifecycle::allowed_from( 'no-such-status' ) );
		$this->assertFalse( Lifecycle::can( 'no-such-status', 'contacted' ) );
	}

	/**
	 * Requirement 7.12: the settled set is `converted` and `lost` only, so an
	 * enquiry holding `new`, `contacted` or `quoted` is never settled.
	 *
	 * @return void
	 */
	public function test_only_converted_and_lost_are_settled() {
		$this->assertSame( array( 'converted', 'lost' ), Lifecycle::SETTLED );

		foreach ( array( 'converted', 'lost' ) as $status ) {
			$this->assertTrue( Lifecycle::is_settled( $status ) );
		}

		foreach ( array( 'new', 'contacted', 'quoted', 'closed', '', 'settled' ) as $status ) {
			$this->assertFalse( Lifecycle::is_settled( $status ), sprintf( '%s should not be settled.', $status ) );
		}
	}

	/**
	 * The auto-closure job's status list comes from the same constant, so the job
	 * and the lifecycle cannot hold different opinions about what settled means
	 * (Requirement 7.12).
	 *
	 * @return void
	 */
	public function test_the_store_derives_its_settled_list_from_the_lifecycle() {
		$this->assertSame(
			Lifecycle::SETTLED,
			EnquiryStore::settled_statuses(),
			'settled_before() should read the lifecycle constant.'
		);

		// The store keeps its own list as the documented default for a partial
		// load. It must never say something different from the lifecycle.
		$this->assertSame(
			Lifecycle::SETTLED,
			EnquiryStore::SETTLED_STATUSES,
			'The store default should match the lifecycle constant exactly.'
		);
	}
}
