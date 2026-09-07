<?php
/**
 * `EnquiryTaxonomies`, exercised without a WordPress boot.
 *
 * `sanitise_list()` is the one piece of this class that does real work with no
 * WordPress function it must guard: parsing whatever Settings' textarea or an
 * already-decoded option array hands it into a clean, ordered, de-duplicated
 * list. Everything else — `event_types()`, `site_exclusivity()`,
 * `configured_event_types()` — reads `get_option()`/`apply_filters()`, which
 * this class already guards with `function_exists()` checks, and is exercised
 * against a real WordPress instead, in the `wordpress` suite's own coverage of
 * the taxonomy vocabulary filter (`RejectedEditPropertyTest` and others already
 * install a vocabulary on the same two hooks this class supplies).
 *
 * @package MarthrownEnquiryHub
 */

use PHPUnit\Framework\TestCase;
use MarthrownEnquiryHub\EnquiryTaxonomies;

require_once dirname( __DIR__, 2 ) . '/includes/class-enquiry-taxonomies.php';

/**
 * Class EnquiryTaxonomiesTest
 */
class EnquiryTaxonomiesTest extends TestCase {

	/**
	 * A newline-delimited textarea submission parses to a clean list.
	 *
	 * @return void
	 */
	public function test_sanitise_list_parses_newline_delimited_text() {
		$this->assertSame(
			array( 'Wedding', 'Retreat', 'Party' ),
			EnquiryTaxonomies::sanitise_list( "Wedding\r\nRetreat\n\nParty" )
		);
	}

	/**
	 * Blank lines are dropped rather than stored as an empty term.
	 *
	 * @return void
	 */
	public function test_sanitise_list_drops_blank_lines() {
		$this->assertSame(
			array( 'Wedding' ),
			EnquiryTaxonomies::sanitise_list( "\n  \nWedding\n\t\n" )
		);
	}

	/**
	 * A repeated value is kept once, at its first position.
	 *
	 * @return void
	 */
	public function test_sanitise_list_de_duplicates() {
		$this->assertSame(
			array( 'Wedding', 'Party' ),
			EnquiryTaxonomies::sanitise_list( "Wedding\nParty\nWedding" )
		);
	}

	/**
	 * An already-decoded array (the shape `register_setting()` stores) is
	 * accepted as readily as a raw textarea string.
	 *
	 * @return void
	 */
	public function test_sanitise_list_accepts_an_array() {
		$this->assertSame(
			array( 'Wedding', 'Party' ),
			EnquiryTaxonomies::sanitise_list( array( 'Wedding', '', 'Party' ) )
		);
	}

	/**
	 * A non-scalar entry in the array form is skipped rather than fatalling.
	 *
	 * @return void
	 */
	public function test_sanitise_list_skips_non_scalar_entries() {
		$this->assertSame(
			array( 'Wedding' ),
			EnquiryTaxonomies::sanitise_list( array( 'Wedding', array( 'nested' ) ) )
		);
	}

	/**
	 * The fixed Site Exclusivity vocabulary is exactly the three values asked
	 * for, in that order — Full Site, Top Site, None — and is a plain constant
	 * rather than a stored option, so there is nothing an administrator could
	 * empty by mistake.
	 *
	 * @return void
	 */
	public function test_site_exclusivity_is_the_fixed_three_values() {
		$this->assertSame(
			array( 'Full Site', 'Top Site', 'None' ),
			EnquiryTaxonomies::SITE_EXCLUSIVITY
		);
	}

	/**
	 * The Event Type default list is exactly the five values asked for.
	 *
	 * @return void
	 */
	public function test_event_type_defaults_are_the_five_values_asked_for() {
		$this->assertSame(
			array( 'Wedding', 'Retreat', 'Mini Festival', 'Corporate', 'Party' ),
			EnquiryTaxonomies::EVENT_TYPE_DEFAULTS
		);
	}
}
