<?php
/**
 * The two intake guards are unreachable from the manual creation route.
 *
 * Requirement 18.12 confines duplicate detection and the rate limit to Intake
 * Webhook Requests, and 18.13, 18.14 and 18.15 spell out what that confinement
 * buys: a repeat submission inside the duplicate window creates a second
 * enquiry, the sixth request holding one address creates an enquiry, and a
 * manual creation writes no rejected intake attempt.
 *
 * `ManualCreationPropertyTest` already establishes those three behaviourally —
 * it creates the repeat, the burst and the success, and finds the enquiries
 * present and the rejections table untouched. What a behavioural test cannot
 * establish is that the guards are *absent* rather than merely quiet. A guard
 * that ran and happened to return "not a duplicate" for every generated case
 * would satisfy every one of those assertions, and so would a guard added to
 * the shared creation path next month behind a condition the generators do not
 * reach. This test is the complementary static claim: the code that runs a
 * guard or writes a rejection row is not reachable from the manual creation
 * route at all, so there is nothing to be quiet.
 *
 * "Reachable" is meant literally, and that is the point of doing this with a
 * call graph rather than a grep of one file. Grepping
 * `RestEnquiries::create_enquiry` would prove only that the route does not call
 * a guard *itself*; the route is four lines long and hands everything to
 * `EnquiryCreator::create()`, so a guard one level down would sail through. The
 * scan therefore starts at the route callback and follows every static call it
 * can resolve into the plugin's own classes, transitively — through
 * `EnquiryCreator` into `Validator`, `EnquiryStore`, `HistoryRecorder` and
 * `ContactLinker` and onward — then asserts the two guard shapes appear in none
 * of the method bodies it arrived at.
 *
 * How the graph is built:
 *
 * - Comments are stripped with PHP's own tokeniser first. `RestEnquiries` and
 *   `EnquiryCreator` both discuss the guards' deliberate absence at length in
 *   their docblocks, so a scan that counted prose would fail on the very
 *   documentation that explains why it passes.
 * - Method bodies are extracted by tokenising and brace-matching, keyed
 *   `Class::method`. Per-method granularity is what makes the negative claim
 *   worth making: `EnquiryStore` *declares* `record_rejection()` and is on the
 *   manual path, so a file-level scan would have to exempt the store wholesale
 *   and would then miss a call to it. Declaring a method is not reaching it.
 * - Edges are static calls (`Class::method(`, `self::method(`) plus array
 *   callables (`array( __CLASS__, 'method' )`), the latter included because
 *   over-collecting edges only widens the set the guards must be absent from.
 *
 * The one thing a static walk cannot follow is a hook: `meh_enquiry_created`
 * fires at the end of the shared path, and `do_action()` resolves its listeners
 * at run time. `test_only_the_webhook_path_names_the_guards()` closes that hole
 * from the other side, by fixing the complete set of shipped files permitted to
 * name either guard — all of them on the webhook path — so no listener,
 * however it is dispatched, can be a guard.
 *
 * Every check also asserts its positive case against the webhook path, where
 * both guards must be reachable, so a walk that has stopped resolving edges or
 * a pattern that has stopped matching fails here rather than passing vacuously.
 *
 * It lives in the `pure` suite because reading the plugin's own files needs
 * neither WordPress nor a database.
 *
 * Covers Requirements 18.12, 18.13, 18.14, 18.15.
 *
 * @package MarthrownEnquiryHub
 */

use PHPUnit\Framework\TestCase;

class ManualCreationGuardReachabilityTest extends TestCase {

	/**
	 * The manual creation route's callback: where the walk starts.
	 */
	const MANUAL_ENTRY = 'RestEnquiries::create_enquiry';

	/**
	 * The webhook path's entry point, used as the positive control.
	 */
	const WEBHOOK_ENTRY = 'IntakeHandler::receive';

	/**
	 * The shared creation path, which must run neither guard.
	 */
	const SHARED = 'includes/class-enquiry-creator.php';

	/**
	 * Guard shapes Requirement 18.12 confines to the webhook path.
	 *
	 * @var array<string,string> Description => pattern.
	 */
	const GUARD_PATTERNS = array(
		'a duplicate or rate-limit check' => '/\bDuplicateDetector\b/',
		'a rejected intake attempt'       => '/\brecord_rejection\s*\(/',
	);

	/**
	 * Files permitted to name a guard, and why.
	 *
	 * @var array<string,string> Relative path => the role that earns it.
	 */
	const GUARD_OWNERS = array(
		'includes/class-duplicate-detector.php' => 'declares the two guards',
		'includes/class-enquiry-store.php'      => 'declares the rejection write',
		'includes/class-intake-handler.php'     => 'runs both guards on the webhook path',
		'includes/class-intake-endpoint.php'    => 'records the webhook rejection a throwable earns',
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
	 * Every shipped PHP file with its comments removed, keyed by relative path.
	 *
	 * @return array<string,string> Relative path => code without comments.
	 */
	protected function shipped_code() {
		static $files = null;

		if ( null !== $files ) {
			return $files;
		}

		$root  = $this->plugin_dir();
		$files = array();

		foreach ( (array) glob( $root . '/includes/*.php' ) as $path ) {
			$files[ 'includes/' . basename( $path ) ] = $this->strip_comments( (string) file_get_contents( $path ) );
		}

		$files['marthrown-enquiry-hub.php'] = $this->strip_comments(
			(string) file_get_contents( $root . '/marthrown-enquiry-hub.php' )
		);

		return $files;
	}

	/**
	 * Remove comments and docblocks, leaving line structure intact.
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
	 * Whether a token opens a brace block.
	 *
	 * `"{$var}"` interpolation opens with `T_CURLY_OPEN` and closes with a plain
	 * `}`, so counting only the plain form would unbalance the match and swallow
	 * the rest of the class.
	 *
	 * @param array|string $token Token from `token_get_all()`.
	 * @return bool
	 */
	protected function opens_brace( $token ) {
		if ( is_string( $token ) ) {
			return '{' === $token;
		}

		return in_array( $token[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true );
	}

	/**
	 * Every method body in the shipped source, keyed `Class::method`.
	 *
	 * @return array<string,array{file:string,code:string}>
	 */
	protected function method_bodies() {
		static $bodies = null;

		if ( null !== $bodies ) {
			return $bodies;
		}

		$bodies = array();

		foreach ( $this->shipped_code() as $relative => $code ) {
			$tokens = token_get_all( $code );
			$count  = count( $tokens );
			$class  = '';

			for ( $i = 0; $i < $count; $i++ ) {
				$token = $tokens[ $i ];

				if ( ! is_array( $token ) ) {
					continue;
				}

				if ( T_CLASS === $token[0] ) {
					$class = $this->declared_name( $tokens, $i, $count );
					continue;
				}

				if ( T_FUNCTION !== $token[0] || '' === $class ) {
					continue;
				}

				$name = $this->declared_name( $tokens, $i, $count );

				// An unnamed `function` is a closure, and its body belongs to the
				// method already being read rather than to a node of its own.
				if ( '' === $name ) {
					continue;
				}

				$body = $this->read_body( $tokens, $i, $count );

				if ( null === $body ) {
					continue;
				}

				$bodies[ $class . '::' . $name ] = array(
					'file' => $relative,
					'code' => $body,
				);
			}
		}

		return $bodies;
	}

	/**
	 * The identifier a `class` or `function` token declares.
	 *
	 * @param array $tokens Token list.
	 * @param int   $from   Index of the keyword.
	 * @param int   $count  Token count.
	 * @return string Empty for an anonymous declaration.
	 */
	protected function declared_name( array $tokens, $from, $count ) {
		for ( $i = $from + 1; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( is_array( $token ) && T_WHITESPACE === $token[0] ) {
				continue;
			}

			return is_array( $token ) && T_STRING === $token[0] ? $token[1] : '';
		}

		return '';
	}

	/**
	 * The brace-matched body following a `function` token.
	 *
	 * @param array $tokens Token list.
	 * @param int   $from   Index of the `function` token. Advanced past the body.
	 * @param int   $count  Token count.
	 * @return string|null Body source, or null for a bodiless declaration.
	 */
	protected function read_body( array $tokens, &$from, $count ) {
		$depth   = 0;
		$started = false;
		$body    = '';

		for ( $i = $from + 1; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			$text  = is_array( $token ) ? $token[1] : $token;

			if ( $this->opens_brace( $token ) ) {
				++$depth;

				if ( ! $started ) {
					$started = true;
					continue;
				}
			} elseif ( is_string( $token ) && '}' === $token ) {
				--$depth;

				if ( 0 === $depth ) {
					$from = $i;

					return $body;
				}
			} elseif ( ! $started && is_string( $token ) && ';' === $token ) {
				// An abstract or interface declaration: no body to read.
				$from = $i;

				return null;
			}

			if ( $started ) {
				$body .= $text;
			}
		}

		return null;
	}

	/**
	 * The `Class::method` targets one body calls.
	 *
	 * @param string $body  Method body.
	 * @param string $class Declaring class, resolving `self` and `static`.
	 * @return string[] Call targets, not de-duplicated.
	 */
	protected function call_targets( $body, $class ) {
		$targets = array();

		// `Class::method(`, `self::method(`, `static::method(`. A constant read
		// carries no parenthesis, so `Validator::PROFILE_MANUAL` is not an edge.
		if ( preg_match_all( '/(?:\\\\)?([A-Za-z_][A-Za-z0-9_]*)\s*::\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $body, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$targets[] = $this->qualify( $match[1], $class ) . '::' . $match[2];
			}
		}

		// Array callables, in either bracket form. Over-collecting here can only
		// widen the set the guards have to be absent from.
		$callable = '/(?:array\s*\(|\[)\s*(__CLASS__|[A-Za-z_][A-Za-z0-9_]*::class|[\'"][A-Za-z_][A-Za-z0-9_]*[\'"])\s*,\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]\s*(?:\)|\])/';

		if ( preg_match_all( $callable, $body, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$owner = str_replace( array( '::class', "'", '"' ), '', $match[1] );

				$targets[] = $this->qualify( $owner, $class ) . '::' . $match[2];
			}
		}

		return $targets;
	}

	/**
	 * Resolve a call's owner against the declaring class.
	 *
	 * @param string $owner Owner as written.
	 * @param string $class Declaring class.
	 * @return string
	 */
	protected function qualify( $owner, $class ) {
		return in_array( $owner, array( 'self', 'static', 'parent', '__CLASS__' ), true ) ? $class : $owner;
	}

	/**
	 * Every method reachable from one entry point, the entry point included.
	 *
	 * @param string $entry `Class::method` to start from.
	 * @return array<string,array{file:string,code:string}> Reachable bodies.
	 */
	protected function reachable_from( $entry ) {
		$bodies  = $this->method_bodies();
		$seen    = array();
		$pending = array( $entry );

		while ( array() !== $pending ) {
			$node = array_shift( $pending );

			if ( isset( $seen[ $node ] ) || ! isset( $bodies[ $node ] ) ) {
				continue;
			}

			$seen[ $node ] = $bodies[ $node ];
			$class         = strstr( $node, '::', true );

			foreach ( $this->call_targets( $bodies[ $node ]['code'], $class ) as $target ) {
				if ( ! isset( $seen[ $target ] ) ) {
					$pending[] = $target;
				}
			}
		}

		return $seen;
	}

	/**
	 * The reachable methods whose bodies match a pattern.
	 *
	 * @param array<string,array{file:string,code:string}> $reachable Reachable bodies.
	 * @param string                                       $pattern   Pattern to match.
	 * @return string[] `Class::method` names, with their files.
	 */
	protected function matching_nodes( array $reachable, $pattern ) {
		$found = array();

		foreach ( $reachable as $node => $method ) {
			if ( preg_match( $pattern, $method['code'] ) ) {
				$found[] = $node . ' (' . $method['file'] . ')';
			}
		}

		return $found;
	}

	/**
	 * The walk starts where the manual creation route actually points.
	 *
	 * Asserted rather than assumed: were the route renamed or repointed, this
	 * test would otherwise go on walking a method nothing calls and passing.
	 *
	 * @return void
	 */
	public function test_the_manual_creation_route_points_at_the_entry_point() {
		$controller = $this->shipped_code()['includes/class-rest-enquiries.php'];

		$this->assertMatchesRegularExpression(
			'/[\'"]methods[\'"]\s*=>\s*[\'"]POST[\'"],\s*[\'"]callback[\'"]\s*=>\s*array\s*\(\s*__CLASS__\s*,\s*[\'"]create_enquiry[\'"]/',
			$controller,
			'The manual creation route registers `create_enquiry` as its POST callback.'
		);

		$bodies = $this->method_bodies();

		$this->assertArrayHasKey( self::MANUAL_ENTRY, $bodies, 'The route callback was found in the shipped source.' );
		$this->assertArrayHasKey( self::WEBHOOK_ENTRY, $bodies, 'The webhook entry point was found in the shipped source.' );
		$this->assertGreaterThan( 100, count( $bodies ), 'The scan reaches the whole of the shipped source.' );
	}

	/**
	 * The walk genuinely follows the call graph out of the route.
	 *
	 * The route body is four lines; everything Requirement 18 asks for happens
	 * further down. If the walk stopped at the route, or at the creator, the
	 * guard checks below would be scanning almost nothing and would pass for the
	 * wrong reason.
	 *
	 * @return void
	 */
	public function test_the_walk_follows_the_call_graph_through_the_shared_path() {
		$reachable = $this->reachable_from( self::MANUAL_ENTRY );

		foreach (
			array(
				'EnquiryCreator::create'   => 'the shared creation path',
				'Validator::validate'      => 'validation',
				'EnquiryStore::create'     => 'the store write',
				'HistoryRecorder::record'  => 'the created history entry',
				'ContactLinker::link'      => 'the contact link',
				'StagingMarker::is_staging' => 'the staging verdict',
			) as $node => $description
		) {
			$this->assertArrayHasKey(
				$node,
				$reachable,
				"The walk reaches {$description} ({$node}) from the manual creation route."
			);
		}

		$this->assertGreaterThan(
			20,
			count( $reachable ),
			'The walk reaches the depth of the creation path rather than stopping at the route.'
		);
	}

	/**
	 * The guard detectors are live: both are reachable from the webhook path.
	 *
	 * This is the control for everything below. A pattern that no longer matches,
	 * or a walk that no longer resolves edges, fails here.
	 *
	 * @return void
	 */
	public function test_both_guards_are_reachable_from_the_webhook_path() {
		$reachable = $this->reachable_from( self::WEBHOOK_ENTRY );

		foreach ( self::GUARD_PATTERNS as $description => $pattern ) {
			$this->assertNotEmpty(
				$this->matching_nodes( $reachable, $pattern ),
				"The webhook path reaches {$description}, so the detector is not looking for nothing."
			);
		}
	}

	/**
	 * Requirements 18.12, 18.13, 18.14: no duplicate or rate-limit check is
	 * reachable from the manual creation route.
	 *
	 * @return void
	 */
	public function test_no_duplicate_or_rate_limit_check_is_reachable_from_manual_creation() {
		$found = $this->matching_nodes(
			$this->reachable_from( self::MANUAL_ENTRY ),
			self::GUARD_PATTERNS['a duplicate or rate-limit check']
		);

		$this->assertSame(
			array(),
			$found,
			sprintf(
				'The manual creation route reaches a duplicate or rate-limit check in %s. '
				. 'Requirements 18.12, 18.13 and 18.14 confine both guards to the intake webhook: '
				. 'a repeat manual submission inside the window, and the sixth in it, each create an enquiry.',
				implode( ', ', $found )
			)
		);
	}

	/**
	 * Requirement 18.15: no rejected intake attempt is reachable from the manual
	 * creation route.
	 *
	 * Not even on the failure branches. The 400 carries the failing field names
	 * to a person who can correct them, which is what the rejections table
	 * substitutes for on the unattended path.
	 *
	 * @return void
	 */
	public function test_no_rejection_row_is_reachable_from_manual_creation() {
		$found = $this->matching_nodes(
			$this->reachable_from( self::MANUAL_ENTRY ),
			self::GUARD_PATTERNS['a rejected intake attempt']
		);

		$this->assertSame(
			array(),
			$found,
			sprintf(
				'The manual creation route reaches a rejection write in %s. '
				. 'Requirement 18.15 records no rejected intake attempt for a manual request.',
				implode( ', ', $found )
			)
		);
	}

	/**
	 * Requirement 18.12: the shared creation path itself runs neither guard.
	 *
	 * The reachability walk already covers this, and it is worth pinning
	 * separately: `EnquiryCreator` is the one file both routes share, so a guard
	 * placed in it would apply the webhook's rules to manual creation whatever
	 * the route did.
	 *
	 * @return void
	 */
	public function test_the_shared_creation_path_runs_neither_guard() {
		$shared = $this->shipped_code()[ self::SHARED ];

		foreach ( self::GUARD_PATTERNS as $description => $pattern ) {
			$this->assertDoesNotMatchRegularExpression(
				$pattern,
				$shared,
				'The shared creation path contains no ' . $description . '; both belong to the caller.'
			);
		}
	}

	/**
	 * Requirement 18.12: only the webhook path names either guard.
	 *
	 * This is the file-level complement to the walk, and it exists for the one
	 * edge a static walk cannot follow. `meh_enquiry_created` fires at the end of
	 * the shared path and its listeners are resolved at run time, so a listener
	 * running a guard would be invisible to the call graph. Fixing the complete
	 * set of files allowed to name a guard closes that: a new one has to appear
	 * in a file, and every file that may hold one is on the webhook path.
	 *
	 * @return void
	 */
	public function test_only_the_webhook_path_names_the_guards() {
		foreach ( $this->shipped_code() as $relative => $code ) {
			if ( isset( self::GUARD_OWNERS[ $relative ] ) ) {
				continue;
			}

			foreach ( self::GUARD_PATTERNS as $description => $pattern ) {
				$this->assertDoesNotMatchRegularExpression(
					$pattern,
					$code,
					sprintf(
						'%s contains no %s. Only the intake webhook path may: %s.',
						$relative,
						$description,
						implode( '; ', array_keys( self::GUARD_OWNERS ) )
					)
				);
			}
		}

		// The owners are owners, not a stale allowance list.
		foreach ( self::GUARD_OWNERS as $relative => $role ) {
			$owner = $this->shipped_code()[ $relative ];
			$found = array();

			foreach ( self::GUARD_PATTERNS as $description => $pattern ) {
				if ( preg_match( $pattern, $owner ) ) {
					$found[] = $description;
				}
			}

			$this->assertNotEmpty( $found, "{$relative} still {$role}." );
		}
	}
}
