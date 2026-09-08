<?php
/**
 * The enquiries CSV export's header and rows.
 *
 * The export streams through `admin-post.php` and ends in `exit`, so what is
 * asserted here is the pair of methods that decide the content: `columns()` and
 * `row()`. Between them they are the whole of what a download contains, and the
 * defect they are being pinned against was one of omission — the export named
 * nine columns while the enquiry record held thirteen worth having, so an event
 * type, a site exclusivity, a guest count and a message were all reachable only
 * by opening each enquiry in the hub.
 *
 * @package MarthrownEnquiryHub
 */

use MarthrownEnquiryHub\ExportEnquiries;

class ExportEnquiriesTest extends WP_UnitTestCase {

	/**
	 * An enquiry as `EnquiryStore` hydrates one, carrying every exported field.
	 *
	 * @var array
	 */
	const ENQUIRY = array(
		'id'               => 12,
		'first_name'       => 'Ada',
		'last_name'        => 'Lovelace',
		'email'            => 'ada@example.com',
		'phone'            => '0114 496 0000',
		'total_guests'     => 120,
		'event_type'       => array( 'Wedding', 'Party' ),
		'site_exclusivity' => array( 'Full Site' ),
		'date_ranges'      => array(
			array(
				'start' => '2026-05-01',
				'end'   => '2026-05-03',
			),
			array(
				'start' => '2026-05-09',
				'end'   => '2026-05-09',
			),
		),
		'status'           => 'quoted',
		'source'           => 'kadence',
		'created_at'       => '2026-01-02 09:00:00',
		'message'          => "Two nights, marquee on the lawn.\nRing back after six.",
	);

	public function set_up() {
		parent::set_up();

		require_once MEH_INCLUDES_DIR . 'class-export-enquiries.php';
	}

	/**
	 * The four fields the export used to leave out are named in the header, and
	 * the header is exactly as wide as a row.
	 *
	 * @return void
	 */
	public function test_the_header_names_every_exported_field() {
		$columns = ExportEnquiries::columns();

		foreach ( array( 'Total guests', 'Event type', 'Site exclusivity', 'Message' ) as $label ) {
			$this->assertContains( $label, $columns, $label . ' should be a column.' );
		}

		// A header narrower or wider than the rows below it is a spreadsheet with
		// its values under the wrong headings, which is worse than a missing one.
		$this->assertCount(
			count( $columns ),
			ExportEnquiries::row( self::ENQUIRY ),
			'Every column should have a cell and every cell a column.'
		);
	}

	/**
	 * A populated enquiry, cell for cell.
	 *
	 * @return void
	 */
	public function test_a_row_carries_the_whole_enquiry() {
		$this->assertSame(
			array(
				'12',
				'Ada',
				'Lovelace',
				'ada@example.com',
				'0114 496 0000',
				120,
				'Wedding, Party',
				'Full Site',
				// The ideal range spans three days and reads as a span; the
				// alternative is a single day and reads as that one date.
				'2026-05-01 to 2026-05-03, 2026-05-09',
				'quoted',
				'kadence',
				'2026-01-02 09:00:00',
				"Two nights, marquee on the lawn.\nRing back after six.",
			),
			ExportEnquiries::row( self::ENQUIRY )
		);
	}

	/**
	 * The optional fields left unsupplied, which is a shape the Manual profile
	 * accepts and the export therefore meets.
	 *
	 * @return void
	 */
	public function test_unsupplied_optional_fields_export_as_empty_cells() {
		$enquiry = array_merge(
			self::ENQUIRY,
			array(
				// The store's "no number was given" value. `0` would read as a
				// party of nobody, which is not what an empty control means.
				'total_guests'     => null,
				'event_type'       => array(),
				'site_exclusivity' => array(),
				'message'          => '',
			)
		);

		$row = ExportEnquiries::row( $enquiry );

		$this->assertSame( '', $row[ 5 ], 'total_guests' );
		$this->assertSame( '', $row[ 6 ], 'event_type' );
		$this->assertSame( '', $row[ 7 ], 'site_exclusivity' );
		$this->assertSame( '', $row[ 12 ], 'message' );
	}
}
