<?php
/**
 * Property 26: Notes storage, validation and ordering.
 *
 * Feature: enquiry-data-layer, Property 26: For any enquiry and any sequence of
 * note bodies, each body whose content after HTML tag removal is non-whitespace
 * and at most 5000 characters is stored with its body, authoring user identifier
 * and creation time, is returned for that enquiry, and produces one history entry
 * of type `note_added`; each body that is whitespace-only after tag removal, or
 * exceeds 5000 characters, is rejected with HTTP 400 (the length rejection naming
 * the 5000 character limit) and stores nothing; and the returned notes are ordered
 * by creation time, most recent first, and contain every accepted note.
 *
 * **Validates: Requirements 10.2, 10.3, 10.4, 10.5, 10.6, 10.7**
 *
 * How the property is instantiated, and why in this shape:
 *
 * - A "sequence of note bodies" is generated as one to six submissions, each of
 *   which is accepted, whitespace-only or over-long. Mixing the three kinds in
 *   one sequence is the point: a rejection has to leave the notes already stored
 *   exactly as they were, which a test submitting only rejections could not see.
 * - The accepted bodies are wrapped in the shared markup templates, and the
 *   generated cores carry no angle brackets and no surrounding whitespace, so the
 *   body that must come back is *exactly* the core. That makes the storage claim
 *   an identity comparison rather than a containment one, and it is what pins
 *   "after HTML tag removal": a body of 5000 characters inside `<b>` tags is
 *   longer than the limit as submitted and accepted all the same, and a `<p></p>`
 *   is empty rather than seven characters of content.
 * - Both rejections are asserted the same way — a `WP_Error` carrying HTTP 400,
 *   with the notes list and the history list identical to the ones read
 *   immediately before — so "stores nothing" covers the history entry as well as
 *   the note row. The length rejection additionally has to name the 5000
 *   character limit, because "too long" alone tells the author nothing about how
 *   much to cut.
 * - The clock is frozen and advanced by a generated gap that may be zero, so
 *   notes sharing a second are routine rather than rare. "Most recent first" is
 *   then asserted as two claims: creation times come back non-increasing, and the
 *   list is the exact reverse of the order the accepted notes were written, which
 *   is what makes the identifier tie-break observable within one second.
 * - Every accepted submission is asserted to arrive at the *head* of the list
 *   with the whole previous list unchanged behind it, which is "contains every
 *   accepted note" and "nothing already stored was rewritten" in one comparison.
 * - Attribution is exercised through a real WordPress user and `add()` is always
 *   handed a null actor, so the stored author is whatever the service resolves:
 *   the authenticated user's identifier when one is authenticated, the system
 *   attribution when none is.
 * - Every generated sequence interleaves submissions against a second enquiry.
 *   "Is returned for that enquiry" is only meaningful if one enquiry's notes can
 *   never surface in another's list.
 *
 * Like the other store-backed tests, this one works against real tables at its
 * own prefix, because `Schema::install()` verifies each table through
 * `SHOW TABLES`, which cannot see the test case's temporary tables.
 *
 * @package MarthrownEnquiryHub
 */

use Eris\TestTrait;
use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\HistoryRecorder;
use MarthrownEnquiryHub\NoteService;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;

/**
 * Class NoteStoragePropertyTest
 */
class NoteStoragePropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables can never collide with a real
	 * Enquiry Store table sitting in the same test database.
	 */
	const PREFIX_SEGMENT = 'mehnp_';

	/**
	 * The instant every generated sequence starts from.
	 */
	const START = '2025-06-01 09:00:00';

	/**
	 * The character limit a note body may not exceed (Requirement 10.5).
	 *
	 * Written out rather than read from `NoteService::MAX_LENGTH`, so a limit
	 * quietly widened in the class is a failure here rather than a silently
	 * looser generator.
	 */
	const MAX_LENGTH = 5000;

	/**
	 * Bodies holding nothing but whitespace once HTML tags are removed
	 * (Requirement 10.4).
	 *
	 * The `<script>` and `<style>` entries are the strong cases: their bodies
	 * must not survive tag removal as text, which is more than plain tag
	 * stripping would give.
	 *
	 * @var string[]
	 */
	const BLANK_BODIES = array(
		'',
		'   ',
		"\n\t ",
		'<p></p>',
		'<br><br />',
		'<p>   </p>',
		'<div><span> </span></div>',
		'<script>alert("x")</script>',
		'<style>p{color:red}</style>',
		"<p>\n\t</p>\n",
	);

	/**
	 * Note bodies that must survive tag removal and trimming intact.
	 *
	 * Each one is free of `<` and `>` and carries no leading or trailing
	 * whitespace, so the stored body can be asserted to equal the core exactly.
	 * The adversarial entries are here because a note body is a bound value like
	 * any other: quotes, backslashes, comment markers and printf placeholders
	 * must reach the column as themselves.
	 *
	 * @var string[]
	 */
	const BODY_CORES = array(
		"Rang back — O'Brien is happy with the quote.",
		"Left a voicemail.\nWill try again Tuesday morning.",
		'Zoë Ó Séaghdha asked about parking for 40 cars.',
		'100% _ %s %d',
		'C:\\path\\to\\the\\quote.pdf',
		"Robert'); DROP TABLE wp_meh_enquiry_notes; --",
		'Deposit chased; awaiting reply.',
	);

	/**
	 * The user whose identifier stands in for an authenticated author.
	 *
	 * @var int
	 */
	protected static $user_id = 0;

	/**
	 * The WordPress table prefix in force outside this test.
	 *
	 * @var string
	 */
	private $original_prefix = '';

	/**
	 * Load the classes under test and create the authoring user.
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

		self::$user_id = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Remove the authoring user.
	 *
	 * @return void
	 */
	public static function wpTearDownAfterClass() {
		self::delete_user( self::$user_id );
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

		Clock::unfreeze();
		wp_set_current_user( 0 );

		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/**
	 * Feature: enquiry-data-layer, Property 26: Notes storage, validation and
	 * ordering.
	 *
	 * **Validates: Requirements 10.2, 10.3, 10.4, 10.5, 10.6, 10.7**
	 *
	 * @eris-shrink 10
	 */
	public function test_notes_are_stored_validated_and_ordered() {
		$this->assertSame(
			self::MAX_LENGTH,
			NoteService::MAX_LENGTH,
			'The note limit should be the 5000 characters Requirement 10.5 names.'
		);

		$this->limitTo( Iterations::count( 100 ) )
			->forAll( self::unshrunk( self::submissions() ) )
			->then(
				function ( array $submissions ) {
					$this->clear();

					$this->assertTrue( Clock::freeze( self::START ), 'The clock should freeze under the test harness.' );

					$enquiry = $this->seed_enquiry( 'subject@example.com' );
					$other   = $this->seed_enquiry( 'bystander@example.com' );

					$this->assertSame(
						array(),
						NoteService::for_enquiry( $enquiry ),
						'An enquiry with no notes against it should read back an empty list.'
					);

					$accepted = array();
					$at       = Clock::now();

					foreach ( $submissions as $submission ) {
						$at = Clock::offset( $submission['gap'], $at );
						$this->assertTrue( Clock::freeze( $at ), 'The clock should advance under the test harness.' );

						$accepted = $this->apply( $submission, $enquiry, $other, $accepted );
					}

					$notes = NoteService::for_enquiry( $enquiry );

					// Requirement 10.7: most recent first, and every accepted note present.
					$this->assert_newest_first( $notes, $accepted );

					// Requirement 10.6: one history entry per stored note, and no
					// entry for a rejected one.
					$this->assert_history_matches( $enquiry, $accepted );

					// Requirement 10.2: a note belongs to exactly one enquiry.
					$this->assert_lists_are_separate( $other, $notes );
				}
			);
	}

	/* ---------------------------------------------------------------------
	 * One submission
	 * ------------------------------------------------------------------ */

	/**
	 * Submit one body and assert everything the property claims about it.
	 *
	 * @param array $submission Generated submission.
	 * @param int   $enquiry    Enquiry whose notes the property is about.
	 * @param int   $other      Second enquiry, whose notes must stay separate.
	 * @param array $accepted   Notes accepted against $enquiry so far, in write order.
	 * @return array $accepted, extended when this submission was stored.
	 */
	private function apply( array $submission, $enquiry, $other, array $accepted ) {
		$target = 'other' === $submission['target'] ? $other : $enquiry;
		$author = $submission['authenticated'] ? self::$user_id : 0;

		wp_set_current_user( $author );

		$before         = NoteService::for_enquiry( $enquiry );
		$before_history = HistoryRecorder::for_enquiry( $enquiry );

		// Null actor: `add()` resolves the current user, which is what makes the
		// attribution half of Requirement 10.2 observable in both directions.
		$result = NoteService::add( $target, $submission['body'], null );

		$after         = NoteService::for_enquiry( $enquiry );
		$after_history = HistoryRecorder::for_enquiry( $enquiry );

		if ( 'accepted' !== $submission['kind'] ) {
			$this->assert_rejected( $result, $submission );

			// Requirements 10.4, 10.5: a rejected body stores nothing at all,
			// and disturbs nothing already stored.
			$this->assertSame( $before, $after, 'A rejected body should leave the notes exactly as they were.' );
			$this->assertSame(
				$before_history,
				$after_history,
				'A rejected body should append no history entry.'
			);

			return $accepted;
		}

		$this->assertIsInt( $result, 'An acceptable body should be stored: ' . $this->describe( $submission ) );
		$this->assertGreaterThan( 0, $result, 'A stored note should carry a new identifier.' );

		if ( $target !== $enquiry ) {
			// A note against another enquiry appends nothing to this list and
			// changes nothing already in it.
			$this->assertSame( $before, $after, "A note against another enquiry should leave this enquiry's notes untouched." );
			$this->assertSame( $before_history, $after_history, "A note against another enquiry should leave this enquiry's history untouched." );

			return $accepted;
		}

		// Requirements 10.3, 10.7: the stored note arrives at the head of the
		// list, with the whole previous list unchanged behind it.
		$this->assertCount( count( $before ) + 1, $after, 'One accepted body should store exactly one note.' );
		$this->assertSame(
			$before,
			array_slice( $after, 1 ),
			'The notes stored before an accepted body should be its unchanged tail, most recent first.'
		);

		$note = $after[0];

		$this->assertSame( $result, $note['id'], 'The newest note should be the one just stored.' );

		// Requirement 10.2: the body, the authoring user and the creation time,
		// against the enquiry identifier.
		$this->assertSame( (int) $enquiry, $note['enquiry_id'], 'A note should carry its enquiry identifier.' );
		$this->assertSame(
			$submission['core'],
			$note['body'],
			'A stored note should be its submitted body with tags removed and nothing else changed: ' . $this->describe( $submission )
		);
		$this->assertSame( (int) $author, $note['author_id'], 'A note should carry the authoring user identifier.' );
		$this->assertSame( Clock::mysql(), $note['created_at'], 'A note should carry the time it was written.' );

		if ( $submission['authenticated'] ) {
			$this->assertNotSame( 0, $note['author_id'], 'A note written by a user should not be attributed to the system.' );
		} else {
			$this->assertSame( 0, $note['author_id'], 'A note written with no user authenticated should be attributed to the system.' );
		}

		// Requirement 10.6: exactly one `note_added` entry, naming this note.
		$this->assertCount( count( $before_history ) + 1, $after_history, 'One stored note should append exactly one history entry.' );

		$entry = $after_history[ count( $before_history ) ];

		$this->assertSame( 'note_added', $entry['entry_type'], 'A stored note should append a `note_added` entry.' );
		$this->assertSame( $result, $entry['context']['note_id'], 'The entry should name the note it concerns.' );
		$this->assertSame( (int) $author, $entry['actor_id'], 'The entry should carry the same author as the note.' );

		$accepted[] = $note;

		return $accepted;
	}

	/**
	 * Both rejections: HTTP 400, the length one naming the limit
	 * (Requirements 10.4, 10.5).
	 *
	 * @param mixed $result     What `add()` answered.
	 * @param array $submission Generated submission.
	 * @return void
	 */
	private function assert_rejected( $result, array $submission ) {
		$this->assertWPError( $result, 'This body should be refused: ' . $this->describe( $submission ) );

		$data = $result->get_error_data();

		$this->assertIsArray( $data, 'A refusal should carry an HTTP status.' );
		$this->assertSame( 400, $data['status'], 'Both note refusals are 400: ' . $this->describe( $submission ) );

		if ( 'too_long' === $submission['kind'] ) {
			$this->assertStringContainsString(
				(string) self::MAX_LENGTH,
				$result->get_error_message(),
				'The length refusal should name the 5000 character limit.'
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Whole-list assertions
	 * ------------------------------------------------------------------ */

	/**
	 * The list reads back most recent first and holds every accepted note
	 * (Requirement 10.7).
	 *
	 * Two claims, because either alone can pass while the other fails: creation
	 * times must be non-increasing, and notes sharing a creation time must still
	 * come back in the reverse of the order they were written.
	 *
	 * @param array $notes    Notes as read back.
	 * @param array $accepted Accepted notes, in the order they were written.
	 * @return void
	 */
	private function assert_newest_first( array $notes, array $accepted ) {
		$this->assertSame(
			array_reverse( $accepted ),
			$notes,
			'The notes should read back as the exact reverse of the order they were written.'
		);

		$previous_time = '9999-12-31 23:59:59';
		$previous_id   = PHP_INT_MAX;

		foreach ( $notes as $note ) {
			$this->assertLessThanOrEqual(
				$previous_time,
				$note['created_at'],
				'Notes should be ordered by creation time, most recent first.'
			);
			$this->assertLessThan(
				$previous_id,
				$note['id'],
				'A note written later should always carry a higher identifier.'
			);

			$previous_time = $note['created_at'];
			$previous_id   = $note['id'];
		}
	}

	/**
	 * One `note_added` entry per stored note, and nothing else in the log
	 * (Requirement 10.6).
	 *
	 * @param int   $enquiry  Enquiry the property is about.
	 * @param array $accepted Accepted notes, in write order.
	 * @return void
	 */
	private function assert_history_matches( $enquiry, array $accepted ) {
		$entries = HistoryRecorder::for_enquiry( $enquiry );

		$this->assertCount(
			count( $accepted ),
			$entries,
			'The log should hold one entry per stored note and none for a refused body.'
		);

		$this->assertSame(
			array_column( $accepted, 'id' ),
			array_map(
				function ( array $entry ) {
					return $entry['context']['note_id'];
				},
				$entries
			),
			'Each entry should name the note it was appended for.'
		);
	}

	/**
	 * One enquiry's notes never hold another enquiry's notes
	 * (Requirement 10.2).
	 *
	 * @param int   $other Second enquiry.
	 * @param array $notes Notes read back for the enquiry the property is about.
	 * @return void
	 */
	private function assert_lists_are_separate( $other, array $notes ) {
		global $wpdb;

		$table = Schema::table( 'notes' );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB

		$theirs = NoteService::for_enquiry( $other );

		foreach ( $theirs as $note ) {
			$this->assertSame( (int) $other, $note['enquiry_id'], 'A note should carry its own enquiry identifier.' );
		}

		$this->assertSame(
			$total,
			count( $notes ) + count( $theirs ),
			'Every stored note should belong to exactly one of the two enquiries.'
		);
	}

	/* ---------------------------------------------------------------------
	 * Generators
	 * ------------------------------------------------------------------ */

	/**
	 * A sequence of one to six note submissions against an enquiry.
	 *
	 * @return \Eris\Generator
	 */
	protected static function submissions() {
		return \Eris\Generators::bind(
			\Eris\Generators::choose( 1, 6 ),
			function ( $count ) {
				return \Eris\Generators::vector( (int) $count, self::submission() );
			}
		);
	}

	/**
	 * The same generator, with shrinking turned off.
	 *
	 * Eris shrinks a wide structure by taking the cartesian product of every
	 * element's shrink options, so one shrink step costs exponentially in the
	 * number of drawn values. A sequence holds up to six submissions of eight
	 * drawn values each, two of them multi-thousand-character strings, which puts
	 * that product far past what fits in memory: a failing iteration reports an
	 * out-of-memory fatal instead of the counterexample, and the `@eris-shrink`
	 * time limit cannot help because the explosion happens inside a single shrink
	 * call.
	 *
	 * Binding the drawn value to a constant generator makes shrinking a no-op, so
	 * a failure reports the sequence as generated, together with the assertion's
	 * own diff and the `ERIS_SEED` line that reproduces the run exactly.
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
	 * One submission: which of the three kinds it is, which enquiry it concerns,
	 * whether a user is authenticated when it is written, how long after the
	 * previous one it is written, and the body it carries.
	 *
	 * A `gap` of 0 puts two notes in the same second, which is the case the
	 * ordering tie-break exists for, so it is a deliberate draw rather than a
	 * rarity. Acceptable bodies are drawn three times in five, so most sequences
	 * accumulate several notes (Requirement 10.3) while still meeting refusals.
	 *
	 * @return \Eris\Generator
	 */
	protected static function submission() {
		return \Eris\Generators::map(
			function ( array $draw ) {
				return array_merge( $draw, array( 'body' => self::body( $draw ) ) );
			},
			\Eris\Generators::associative(
				array(
					'kind'          => \Eris\Generators::elements(
						array( 'accepted', 'accepted', 'accepted', 'blank', 'too_long' )
					),
					'target'        => \Eris\Generators::elements( array( 'enquiry', 'enquiry', 'enquiry', 'other' ) ),
					'authenticated' => \Eris\Generators::elements( array( true, false ) ),
					'gap'           => \Eris\Generators::oneOf(
						\Eris\Generators::constant( 0 ),
						\Eris\Generators::choose( 0, 90 )
					),
					'core'          => self::acceptable_core(),
					'long'          => self::over_long_core(),
					'markup'        => \Eris\Generators::elements(
						array_merge( array( '%s' ), Generators::MARKUP )
					),
					'blank'         => \Eris\Generators::elements( self::BLANK_BODIES ),
				)
			)
		);
	}

	/**
	 * The body one submission actually presents.
	 *
	 * An acceptable body is its core wrapped in markup, so the submitted string
	 * may well be longer than the limit while the content after tag removal is
	 * not: that is the whole point of measuring the stripped body. A blank body is
	 * presented as drawn, since it is markup and whitespace already.
	 *
	 * @param array $draw Generated submission, before its body is built.
	 * @return string
	 */
	protected static function body( array $draw ) {
		if ( 'blank' === $draw['kind'] ) {
			return $draw['blank'];
		}

		$core = 'too_long' === $draw['kind'] ? $draw['long'] : $draw['core'];

		// sprintf() reads the markup as the format, so a core carrying `%s` or
		// `%d` is passed through as itself.
		return sprintf( $draw['markup'], $core );
	}

	/**
	 * The plain text an acceptable body carries.
	 *
	 * Every value is free of `<` and `>` and of surrounding whitespace, so tag
	 * removal and trimming leave it untouched and the stored body can be compared
	 * for identity. The last two sit at exactly the limit, in ASCII and in a
	 * multibyte character, so a limit counted in bytes rather than characters
	 * refuses a note it should accept and fails here.
	 *
	 * @return \Eris\Generator
	 */
	protected static function acceptable_core() {
		return \Eris\Generators::oneOf(
			\Eris\Generators::elements( self::BODY_CORES ),
			\Eris\Generators::map(
				function ( $index ) {
					return 'Note ' . (int) $index . ' on the file.';
				},
				\Eris\Generators::choose( 1, 9999 )
			),
			\Eris\Generators::constant( str_repeat( 'a', self::MAX_LENGTH ) ),
			\Eris\Generators::constant( str_repeat( 'é', self::MAX_LENGTH ) )
		);
	}

	/**
	 * The plain text an over-long body carries: one character or more beyond the
	 * limit, in ASCII or in a multibyte character.
	 *
	 * @return \Eris\Generator
	 */
	protected static function over_long_core() {
		return \Eris\Generators::map(
			function ( array $parts ) {
				list( $extra, $unicode ) = $parts;

				return str_repeat( $unicode ? 'é' : 'b', self::MAX_LENGTH + (int) $extra );
			},
			\Eris\Generators::tuple(
				\Eris\Generators::oneOf(
					\Eris\Generators::constant( 1 ),
					\Eris\Generators::choose( 1, 200 )
				),
				\Eris\Generators::elements( array( true, false ) )
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * A short description of a submission, for failure messages.
	 *
	 * @param array $submission Generated submission.
	 * @return string
	 */
	private function describe( array $submission ) {
		$body = $submission['body'];

		if ( function_exists( 'mb_substr' ) && mb_strlen( $body, 'UTF-8' ) > 60 ) {
			$body = mb_substr( $body, 0, 60, 'UTF-8' ) . '…(' . mb_strlen( $body, 'UTF-8' ) . ' characters)';
		}

		return sprintf( '%s body %s', $submission['kind'], var_export( $body, true ) );
	}

	/**
	 * Write one enquiry through the store and return its identifier.
	 *
	 * @param string $email Enquiry email.
	 * @return int
	 */
	private function seed_enquiry( $email ) {
		$now = Clock::mysql();

		$id = EnquiryStore::create(
			array(
				'first_name'        => 'Ada',
				'last_name'         => 'Lovelace',
				'email'             => $email,
				'status'            => 'new',
				'created_at'        => $now,
				'updated_at'        => $now,
				'status_changed_at' => $now,
				'source'            => 'webhook:fixture',
			),
			array( '2025-08-16' )
		);

		$this->assertIsInt( $id, 'Seeding an enquiry should succeed.' );

		return (int) $id;
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

		foreach ( array( 'notes', 'history', 'dates', 'terms', 'rejections', 'enquiries' ) as $key ) {
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
