<?php
/**
 * Worked examples for `NoteService`.
 *
 * Property 26 quantifies the behaviour over arbitrary bodies; task 6.7 owns it.
 * What is asserted here is one worked example of each outcome against real rows:
 * an accepted note stores body, author and time and appends one `note_added`
 * entry; several notes coexist and read back most recent first; a whitespace-only
 * body and an over-long body are both refused with 400, the length message naming
 * the 5000 character limit, and neither leaves a row or an entry behind.
 *
 * The over-long case is the contrast worth holding on to: the Validator truncates
 * an over-long enquiry `message`, whereas an over-long note is rejected outright,
 * so the stored note is always the whole of what its author wrote.
 *
 * Like the other store tests, this one works against real tables at its own
 * prefix, because `Schema::install()` verifies each table through `SHOW TABLES`,
 * which cannot see the test case's temporary tables.
 *
 * @package MarthrownEnquiryHub
 */

use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\HistoryRecorder;
use MarthrownEnquiryHub\NoteService;
use MarthrownEnquiryHub\Schema;

/**
 * Class NoteServiceTest
 */
class NoteServiceTest extends WP_UnitTestCase {

	/**
	 * Extra prefix segment, so these tables cannot collide with a real Enquiry
	 * Store table sitting in the same test database.
	 */
	const PREFIX_SEGMENT = 'mehns_';

	/**
	 * A frozen instant used by the single-note examples.
	 */
	const AT = '2025-07-04 14:30:00';

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
		require_once MEH_INCLUDES_DIR . 'class-enquiry-query.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-store.php';
		require_once MEH_INCLUDES_DIR . 'class-history-recorder.php';
		require_once MEH_INCLUDES_DIR . 'class-note-service.php';
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
	}

	public function tear_down() {
		global $wpdb;

		Clock::unfreeze();
		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/**
	 * Requirements 10.2, 10.6: an accepted note stores body, author and creation
	 * time against the enquiry, and appends one `note_added` history entry.
	 *
	 * @return void
	 */
	public function test_an_accepted_note_is_stored_and_recorded() {
		$id = $this->seed_enquiry();

		Clock::freeze( self::AT );

		$note_id = NoteService::add( $id, "  Rang back, happy with the quote.  \n", 7 );

		$this->assertIsInt( $note_id );
		$this->assertGreaterThan( 0, $note_id );

		$notes = NoteService::for_enquiry( $id );

		$this->assertCount( 1, $notes );
		$this->assertSame( $note_id, $notes[0]['id'] );
		$this->assertSame( $id, $notes[0]['enquiry_id'] );
		$this->assertSame( 'Rang back, happy with the quote.', $notes[0]['body'] );
		$this->assertSame( 7, $notes[0]['author_id'] );
		$this->assertSame( self::AT, $notes[0]['created_at'] );

		$history = HistoryRecorder::for_enquiry( $id );

		$this->assertCount( 1, $history );
		$this->assertSame( 'note_added', $history[0]['entry_type'] );
		$this->assertSame( $note_id, $history[0]['context']['note_id'] );
		$this->assertSame( 7, $history[0]['actor_id'] );
	}

	/**
	 * Requirement 10.2: a note added with no authenticated user is attributed to
	 * the system rather than refused.
	 *
	 * @return void
	 */
	public function test_an_unattributed_note_is_stored_against_the_system() {
		$id = $this->seed_enquiry();

		wp_set_current_user( 0 );

		$this->assertIsInt( NoteService::add( $id, 'Left a voicemail.' ) );
		$this->assertSame( 0, NoteService::for_enquiry( $id )[0]['author_id'] );
	}

	/**
	 * Requirements 10.3, 10.7: several notes coexist on one enquiry and read back
	 * most recent first, including notes written within the same second.
	 *
	 * @return void
	 */
	public function test_notes_accumulate_and_read_back_newest_first() {
		$id = $this->seed_enquiry();

		Clock::freeze( '2025-07-01 09:00:00' );
		$first = NoteService::add( $id, 'First contact.', 7 );

		Clock::freeze( '2025-07-03 11:15:00' );
		$second = NoteService::add( $id, 'Quote sent.', 7 );

		// Same second as the second note: the identifier breaks the tie.
		$third = NoteService::add( $id, 'Quote acknowledged.', 8 );

		$notes = NoteService::for_enquiry( $id );

		$this->assertSame(
			array( $third, $second, $first ),
			array_column( $notes, 'id' ),
			'Notes should read back most recent first.'
		);

		$this->assertSame(
			array( 'Quote acknowledged.', 'Quote sent.', 'First contact.' ),
			array_column( $notes, 'body' )
		);

		// Requirement 10.6 holds per note, not per enquiry.
		$this->assertCount( 3, HistoryRecorder::for_enquiry( $id ) );

		// A second enquiry's notes are its own.
		$other = $this->seed_enquiry();

		$this->assertSame( array(), NoteService::for_enquiry( $other ) );
	}

	/**
	 * Requirement 10.4: a body holding nothing but whitespace once tags are
	 * stripped is refused with 400 and stores nothing.
	 *
	 * @return void
	 */
	public function test_a_whitespace_only_body_is_refused() {
		$id = $this->seed_enquiry();

		foreach ( array( '', '   ', "\n\t ", '<p></p>', '<br><br />', '<p>   </p>' ) as $body ) {
			$result = NoteService::add( $id, $body, 7 );

			$this->assertWPError( $result, sprintf( 'Body "%s" should be refused.', $body ) );
			$this->assertSame( 400, $result->get_error_data()['status'] );
		}

		$this->assertSame( array(), NoteService::for_enquiry( $id ) );
		$this->assertSame( array(), HistoryRecorder::for_enquiry( $id ) );
	}

	/**
	 * Requirement 10.5: an over-long body is refused with 400 and a message
	 * naming the 5000 character limit — rejected, never truncated.
	 *
	 * @return void
	 */
	public function test_an_over_long_body_is_refused_naming_the_limit() {
		$id = $this->seed_enquiry();

		$result = NoteService::add( $id, str_repeat( 'a', NoteService::MAX_LENGTH + 1 ), 7 );

		$this->assertWPError( $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertStringContainsString( '5000', $result->get_error_message() );

		$this->assertSame( array(), NoteService::for_enquiry( $id ), 'Nothing should be stored.' );
		$this->assertSame( array(), HistoryRecorder::for_enquiry( $id ) );

		// The limit itself is accepted, and stored whole rather than clipped.
		$body = str_repeat( 'b', NoteService::MAX_LENGTH );

		$this->assertIsInt( NoteService::add( $id, $body, 7 ) );
		$this->assertSame( $body, NoteService::for_enquiry( $id )[0]['body'] );
	}

	/**
	 * Length is counted in characters rather than bytes, so a multi-byte note at
	 * the limit is accepted whole.
	 *
	 * @return void
	 */
	public function test_the_limit_counts_characters_not_bytes() {
		$id   = $this->seed_enquiry();
		$body = str_repeat( 'é', NoteService::MAX_LENGTH );

		$this->assertIsInt( NoteService::add( $id, $body, 7 ) );
		$this->assertSame( $body, NoteService::for_enquiry( $id )[0]['body'] );
		$this->assertWPError( NoteService::add( $id, $body . 'é', 7 ) );
	}

	/**
	 * A note against an enquiry that does not exist is refused with 404 rather
	 * than stored as an orphan.
	 *
	 * @return void
	 */
	public function test_a_note_for_an_unknown_enquiry_is_refused() {
		$result = NoteService::add( 987654, 'Nobody to attach this to.', 7 );

		$this->assertWPError( $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );

		$this->assertSame( array(), NoteService::for_enquiry( 987654 ) );

		$invalid = NoteService::add( 0, 'No identifier at all.', 7 );

		$this->assertWPError( $invalid );
		$this->assertSame( 400, $invalid->get_error_data()['status'] );
	}

	/* ---------------------------------------------------------------------
	 * Fixtures
	 * ------------------------------------------------------------------ */

	/**
	 * Write one enquiry through the store and return its identifier.
	 *
	 * @param array $fields Column overrides.
	 * @return int
	 */
	private function seed_enquiry( array $fields = array() ) {
		$defaults = array(
			'first_name'        => 'Ada',
			'last_name'         => 'Lovelace',
			'email'             => 'ada@example.com',
			'status'            => 'new',
			'created_at'        => '2025-06-01 10:00:00',
			'updated_at'        => '2025-06-01 10:00:00',
			'status_changed_at' => '2025-06-01 10:00:00',
			'source'            => 'webhook:fixture',
		);

		$id = EnquiryStore::create( array_merge( $defaults, $fields ), array( '2025-08-16' ) );

		$this->assertIsInt( $id, 'Seeding an enquiry should succeed.' );

		return (int) $id;
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
