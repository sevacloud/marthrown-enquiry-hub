<?php
/**
 * `EnquiryCreator::create()` is the only way either creation route reaches the
 * store.
 *
 * The shared write path exists for one reason: Requirement 18 asks a manually
 * created enquiry to behave exactly as a webhook one does, and two
 * implementations of "store the enquiry, record the `created` entry, link the
 * contact" would drift apart at the first change. The behavioural half of that
 * claim is asserted in `SharedCreationPathTest`, which compares two enquiries
 * created by the two routes. What a behavioural comparison cannot establish is
 * that the sharing is structural: two paths can agree today and stop agreeing
 * the moment one of them grows an insert of its own. So this is a source scan,
 * which covers the code rather than one execution of it.
 *
 * Three things are pinned down:
 *
 * 1. **The shared path is the one that writes.** `EnquiryCreator::create()`
 *    calls `EnquiryStore::create()`, records the `created` history entry, links
 *    the contact and fires `meh_enquiry_created`. That is the positive half, and
 *    it is what stops the negative halves below from passing vacuously.
 * 2. **Intake reaches the store through it.** `IntakeHandler::receive()` calls
 *    `EnquiryCreator::create()`, and nothing reachable from `receive()`
 *    performs an enquiry insert, records a `created` entry, links a contact or
 *    fires the creation action itself.
 * 3. **The manual creation route reaches the store through it.**
 *    `RestEnquiries::create_enquiry()` calls `EnquiryCreator::create()`, and the
 *    same four absences hold across everything reachable from it.
 *
 * The scan is **method-scoped rather than file-scoped**, and that is deliberate
 * rather than a convenience. `RestEnquiries` legitimately calls
 * `HistoryRecorder::record()` and `ContactLinker::link()` from the re-raise
 * route, where a copied enquiry earns its own `duplicated` entries and its own
 * link (Requirements 9.7, 9.8). A whole-file scan would either fail on that or
 * have to drop the two most valuable detectors. So the closure is computed from
 * the entry point outward: the entry method's body, plus the body of every
 * `self::`/`static::` method it calls, transitively. What is asserted is
 * "unreachable from this route", which is the claim worth making.
 *
 * Comments and docblocks are stripped first, using PHP's own tokeniser, because
 * all three files discuss the calls they do not make — `IntakeHandler`'s
 * docblock names `ContactLinker::link()` precisely to say it happens elsewhere.
 * A scan that counted prose would fail on documentation or be loosened until it
 * proved nothing.
 *
 * Every detector is also asserted to match somewhere in the shipped source, so a
 * pattern that has stopped matching anything fails here rather than passing.
 *
 * It lives in the `pure` suite because reading the plugin's own files needs
 * neither WordPress nor a database.
 *
 * Covers Requirements 18.1, 18.8, 18.18.
 *
 * @package MarthrownEnquiryHub
 */

use PHPUnit\Framework\TestCase;

class SharedCreationPathSourceTest extends TestCase {

	/**
	 * The shared creation path.
	 */
	const CREATOR = 'includes/class-enquiry-creator.php';

	/**
	 * The webhook caller.
	 */
	const HANDLER = 'includes/class-intake-handler.php';

	/**
	 * The REST controller holding the manual creation route.
	 */
	const REST = 'includes/class-rest-enquiries.php';

	/**
	 * The store, which declares the enquiry insert both routes must reach
	 * through the shared path.
	 */
	const STORE = 'includes/class-enquiry-store.php';

	/**
	 * The shared path's own entry point.
	 */
	const CREATOR_ENTRY = 'create';

	/**
	 * The webhook entry point.
	 */
	const HANDLER_ENTRY = 'receive';

	/**
	 * The manual creation route's callback (Requirement 18.1).
	 */
	const REST_ENTRY = 'create_enquiry';

	/**
	 * The call that reaches the shared path.
	 */
	const SHARED_CALL = '/EnquiryCreator::create\s*\(/';

	/**
	 * Work only the shared path may do.
	 *
	 * Each of these is a step Requirement 18 asks manual creation to share with
	 * intake, so a second occurrence of any of them on either route is the
	 * duplication the shared service exists to prevent. `$wpdb` and a raw
	 * `INSERT` are in the list because reaching the enquiries table without
	 * naming `EnquiryStore::create()` would be the same drift by another route.
	 *
	 * @var array<string,string> Description => pattern.
	 */
	const CREATION_WORK = array(
		'its own enquiry insert'  => '/EnquiryStore::create\s*\(/',
		'its own database handle' => '/\$wpdb\b/',
		'its own raw INSERT'      => '/\bINSERT\b/i',
		'its own history entry'   => '/HistoryRecorder::record\s*\(/',
		'its own contact link'    => '/ContactLinker::link\s*\(/',
		'its own creation action' => '/meh_enquiry_created|CREATED_ACTION/',
	);

	/**
	 * Plugin root directory.
	 *
	 * @return string
	 */
	protected function plugin_dir() {
		return dirname( __DIR__, 2 );
	}

	/**
	 * One shipped file's code, with comments and docblocks removed.
	 *
	 * @param string $relative Path relative to the plugin root.
	 * @return string
	 */
	protected function code( $relative ) {
		$path = $this->plugin_dir() . '/' . $relative;

		$this->assertFileExists( $path, $relative . ' is a shipped file.' );

		return $this->strip_comments( (string) file_get_contents( $path ) );
	}

	/**
	 * Remove comments and docblocks, leaving the code otherwise intact.
	 *
	 * @param string $source PHP source.
	 * @return string Code only.
	 */
	protected function strip_comments( $source ) {
		$code = '';

		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$code .= "\n";
				continue;
			}

			$code .= is_array( $token ) ? $token[1] : $token;
		}

		return $code;
	}

	/**
	 * Every named method of a file, keyed by name, holding its body only.
	 *
	 * Braces are counted from the token stream rather than the raw text, so a
	 * brace inside a string literal cannot end a body early. Anonymous
	 * functions are skipped as declarations and stay part of the body they sit
	 * in, which is what keeps a callback's code inside the closure being scanned.
	 *
	 * @param string $code Comment-stripped PHP source.
	 * @return array<string,string> Method name => body.
	 */
	protected function methods( $code ) {
		$tokens  = token_get_all( $code );
		$count   = count( $tokens );
		$methods = array();

		for ( $index = 0; $index < $count; $index++ ) {
			if ( ! is_array( $tokens[ $index ] ) || T_FUNCTION !== $tokens[ $index ][0] ) {
				continue;
			}

			$name_at = $index + 1;

			while ( $name_at < $count && is_array( $tokens[ $name_at ] ) && T_WHITESPACE === $tokens[ $name_at ][0] ) {
				++$name_at;
			}

			// A closure or an arrow function has no name; it belongs to the body
			// it is written in.
			if ( $name_at >= $count || ! is_array( $tokens[ $name_at ] ) || T_STRING !== $tokens[ $name_at ][0] ) {
				continue;
			}

			$body = $this->body_from( $tokens, $name_at, $count );

			if ( null !== $body ) {
				$methods[ $tokens[ $name_at ][1] ] = $body;
			}
		}

		return $methods;
	}

	/**
	 * The body of the method whose name sits at the given token offset.
	 *
	 * @param array $tokens Token stream.
	 * @param int   $from   Offset of the method name.
	 * @param int   $count  Token count.
	 * @return string|null Body, or null for an abstract or interface declaration.
	 */
	protected function body_from( array $tokens, $from, $count ) {
		$depth   = 0;
		$started = false;
		$body    = '';

		for ( $index = $from; $index < $count; $index++ ) {
			$text = is_array( $tokens[ $index ] ) ? $tokens[ $index ][1] : $tokens[ $index ];

			if ( ! $started ) {
				// An abstract method or an interface declaration ends here.
				if ( ';' === $text ) {
					return null;
				}

				if ( '{' === $text ) {
					$started = true;
					$depth   = 1;
				}

				continue;
			}

			if ( '{' === $text ) {
				++$depth;
			} elseif ( '}' === $text ) {
				--$depth;

				if ( 0 === $depth ) {
					return $body;
				}
			}

			$body .= $text;
		}

		return $started ? $body : null;
	}

	/**
	 * Every method reachable from an entry point within its own class.
	 *
	 * @param array<string,string> $methods Method bodies by name.
	 * @param string               $entry   Entry method name.
	 * @return array<string,string> Reachable bodies, including the entry's own.
	 */
	protected function reachable( array $methods, $entry ) {
		$found = array();
		$queue = array( (string) $entry );

		while ( array() !== $queue ) {
			$name = array_shift( $queue );

			if ( isset( $found[ $name ] ) || ! isset( $methods[ $name ] ) ) {
				continue;
			}

			$found[ $name ] = $methods[ $name ];

			preg_match_all( '/(?:self|static|__CLASS__)\s*(?:::|,\s*[\'"])([a-z_][a-z0-9_]*)/i', $methods[ $name ], $matches );

			foreach ( $matches[1] as $callee ) {
				$queue[] = $callee;
			}
		}

		return $found;
	}

	/**
	 * The code reachable from one entry point, as a single string to scan.
	 *
	 * @param string $relative File to read.
	 * @param string $entry    Entry method name.
	 * @return string
	 */
	protected function closure( $relative, $entry ) {
		$methods = $this->methods( $this->code( $relative ) );

		$this->assertArrayHasKey(
			$entry,
			$methods,
			sprintf( '%s declares %s(), the entry point being scanned.', $relative, $entry )
		);

		return implode( "\n", $this->reachable( $methods, $entry ) );
	}

	/**
	 * Assert that no creation work of its own appears in a scanned closure.
	 *
	 * @param string $code  Code reachable from the entry point.
	 * @param string $route Description of the route, for the failure message.
	 * @return void
	 */
	protected function assert_performs_no_creation_work( $code, $route ) {
		foreach ( self::CREATION_WORK as $description => $pattern ) {
			$this->assertDoesNotMatchRegularExpression(
				$pattern,
				$code,
				sprintf(
					'%s performs %s. Requirement 18 has both creation routes reach the store '
					. 'through EnquiryCreator::create(), which is the only place this work belongs.',
					$route,
					$description
				)
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * The scan is looking at something
	 * ------------------------------------------------------------------ */

	/**
	 * The three files exist, and each declares the entry point being scanned.
	 *
	 * @return void
	 */
	public function test_the_scan_finds_all_three_entry_points() {
		$creator = $this->methods( $this->code( self::CREATOR ) );
		$handler = $this->methods( $this->code( self::HANDLER ) );
		$rest    = $this->methods( $this->code( self::REST ) );

		$this->assertArrayHasKey( self::CREATOR_ENTRY, $creator, 'EnquiryCreator declares create().' );
		$this->assertArrayHasKey( self::HANDLER_ENTRY, $handler, 'IntakeHandler declares receive().' );
		$this->assertArrayHasKey( self::REST_ENTRY, $rest, 'RestEnquiries declares create_enquiry() (Requirement 18.1).' );

		$this->assertNotSame( '', trim( $creator[ self::CREATOR_ENTRY ] ), 'create() has a body to scan.' );
		$this->assertNotSame( '', trim( $handler[ self::HANDLER_ENTRY ] ), 'receive() has a body to scan.' );
		$this->assertNotSame( '', trim( $rest[ self::REST_ENTRY ] ), 'create_enquiry() has a body to scan.' );
	}

	/**
	 * The closure walk is transitive, so "unreachable" means what it says.
	 *
	 * Asserted against the helpers each entry point delegates to: a walk that had
	 * stopped following `self::` calls would scan the entry body alone, and a
	 * violation moved one method deeper would go unnoticed.
	 *
	 * @return void
	 */
	public function test_the_scan_follows_calls_out_of_the_entry_method() {
		$manual = $this->reachable( $this->methods( $this->code( self::REST ) ), self::REST_ENTRY );
		$intake = $this->reachable( $this->methods( $this->code( self::HANDLER ) ), self::HANDLER_ENTRY );

		foreach ( array( 'submitted_fields', 'creation_error', 'present_single' ) as $helper ) {
			$this->assertArrayHasKey(
				$helper,
				$manual,
				sprintf( 'The manual route closure reaches %s().', $helper )
			);
		}

		foreach ( array( 'source', 'resolve', 'reject' ) as $helper ) {
			$this->assertArrayHasKey(
				$helper,
				$intake,
				sprintf( 'The intake closure reaches %s().', $helper )
			);
		}
	}

	/**
	 * Every detector matches somewhere, so none of them is looking for nothing.
	 *
	 * @return void
	 */
	public function test_every_detector_is_live() {
		$creator = $this->closure( self::CREATOR, self::CREATOR_ENTRY );
		$store   = $this->code( self::STORE );

		foreach ( self::CREATION_WORK as $description => $pattern ) {
			$this->assertTrue(
				1 === preg_match( $pattern, $creator ) || 1 === preg_match( $pattern, $store ),
				sprintf( 'The detector for %s matches the shared path or the store.', $description )
			);
		}

		$this->assertMatchesRegularExpression(
			self::SHARED_CALL,
			$this->code( self::HANDLER ),
			'The shared-call detector matches at least one caller.'
		);
	}

	/* ---------------------------------------------------------------------
	 * The shared path is the one that writes
	 * ------------------------------------------------------------------ */

	/**
	 * Requirement 18.1: `EnquiryCreator::create()` is where the enquiry is
	 * stored, the `created` entry recorded and the contact linked.
	 *
	 * @return void
	 */
	public function test_the_shared_path_holds_the_whole_creation_sequence() {
		$creator = $this->closure( self::CREATOR, self::CREATOR_ENTRY );

		$expected = array(
			'stores the enquiry through the store' => '/EnquiryStore::create\s*\(/',
			'records the created history entry'    => '/HistoryRecorder::record\s*\(/',
			'links the contact'                    => '/ContactLinker::link\s*\(/',
			'fires the creation action'            => '/meh_enquiry_created|CREATED_ACTION/',
		);

		foreach ( $expected as $description => $pattern ) {
			$this->assertMatchesRegularExpression(
				$pattern,
				$creator,
				sprintf( 'The shared creation path %s.', $description )
			);
		}
	}

	/**
	 * Requirements 18.8, 18.18: both differences the two routes are allowed are
	 * arguments of the shared call rather than branches inside it.
	 *
	 * `create()` takes `$source` and `$actor` from its caller and neither derives
	 * a source nor resolves the current user, so nothing inside the shared path
	 * can tell which route invoked it — which is what makes the two enquiries
	 * differ in `source` and in the `created` entry's `actor_id` and in nothing
	 * else.
	 *
	 * @return void
	 */
	public function test_the_shared_path_takes_source_and_actor_from_its_caller() {
		$code    = $this->code( self::CREATOR );
		$closure = $this->closure( self::CREATOR, self::CREATOR_ENTRY );

		$this->assertMatchesRegularExpression(
			'/function\s+create\s*\([^)]*\$source[^)]*\$at[^)]*\$actor/s',
			$code,
			'create() takes the source and the history actor as parameters (Requirements 18.8, 18.18).'
		);

		$this->assertDoesNotMatchRegularExpression(
			'/get_current_user_id\s*\(/',
			$closure,
			'The shared path resolves no current user of its own, so attribution is the caller\'s (Requirement 18.18).'
		);

		$this->assertDoesNotMatchRegularExpression(
			'/[\'"]manual:|[\'"]webhook:/',
			$closure,
			'The shared path derives no source of its own, so `source` is the caller\'s (Requirement 18.8).'
		);
	}

	/* ---------------------------------------------------------------------
	 * Both routes reach the store through it, and neither writes its own
	 * ------------------------------------------------------------------ */

	/**
	 * Requirement 18.1: intake reaches the store through the shared path.
	 *
	 * @return void
	 */
	public function test_intake_reaches_the_store_through_the_shared_path() {
		$closure = $this->closure( self::HANDLER, self::HANDLER_ENTRY );

		$this->assertMatchesRegularExpression(
			self::SHARED_CALL,
			$closure,
			'IntakeHandler::receive() reaches the store through EnquiryCreator::create().'
		);

		$this->assert_performs_no_creation_work( $closure, 'IntakeHandler::receive()' );
	}

	/**
	 * Requirement 18.1: the manual creation route reaches the store through the
	 * shared path.
	 *
	 * @return void
	 */
	public function test_the_manual_route_reaches_the_store_through_the_shared_path() {
		$closure = $this->closure( self::REST, self::REST_ENTRY );

		$this->assertMatchesRegularExpression(
			self::SHARED_CALL,
			$closure,
			'RestEnquiries::create_enquiry() reaches the store through EnquiryCreator::create().'
		);

		$this->assert_performs_no_creation_work( $closure, 'The manual creation route' );
	}

	/**
	 * Requirements 18.8, 18.18: the two routes differ in exactly the two
	 * arguments they are allowed to differ in.
	 *
	 * Each names its own `source` shape and its own actor, and neither names the
	 * other's, so a route that had come to share the other's attribution — or to
	 * derive it inside the shared path — fails here.
	 *
	 * @return void
	 */
	public function test_each_route_supplies_its_own_source_and_actor() {
		$intake = $this->closure( self::HANDLER, self::HANDLER_ENTRY );
		$manual = $this->closure( self::REST, self::REST_ENTRY );

		// Requirement 18.18: the webhook is the system, the manual route is the
		// submitting user.
		$this->assertMatchesRegularExpression(
			'/HistoryRecorder::SYSTEM_ACTOR/',
			$intake,
			'Intake attributes its enquiry to the system.'
		);
		$this->assertMatchesRegularExpression(
			'/get_current_user_id\s*\(/',
			$manual,
			'The manual route attributes its enquiry to the submitting user (Requirement 18.18).'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/get_current_user_id\s*\(/',
			$intake,
			'Intake resolves no WordPress user: no user sent the request.'
		);

		// Requirement 18.8: `manual:{user id}` on one side, `webhook:` on the
		// other, each named by the route that owns it.
		$this->assertMatchesRegularExpression(
			'/MANUAL_SOURCE_PREFIX/',
			$manual,
			'The manual route derives source from the manual prefix and the user (Requirement 18.8).'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/MANUAL_SOURCE_PREFIX|[\'"]manual:/',
			$intake,
			'Intake never labels an enquiry as manually created.'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/[\'"]webhook:/',
			$manual,
			'The manual route never labels an enquiry as a webhook one.'
		);
	}
}
