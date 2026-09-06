<?php
/**
 * `AutoCloseJob` is the only component that closes an enquiry on an elapsed
 * interval.
 *
 * Requirement 8.4 is a claim about the whole plugin rather than about one
 * function: *no* component other than `AutoCloseJob` may decide, from the fact
 * that time has passed, that an enquiry is closed. A behavioural test cannot
 * establish that. It can only show that the paths it happens to call leave
 * statuses alone; a second closure path added next month would sit outside every
 * assertion it makes. So the check is a source scan, which covers every path at
 * once, including ones written later.
 *
 * The scan pins down four things:
 *
 * 1. **The interval is named in one place.** Only `AutoCloseJob` mentions the
 *    `meh_auto_close_days` filter, so no other component can read the configured
 *    closure interval to act on it.
 * 2. **The overdue set is read in one place.** `EnquiryStore::settled_before()`
 *    is the query that turns a cutoff into "these enquiries are due"; only the
 *    store declares it and only `AutoCloseJob` calls it.
 * 3. **Nothing else combines the two ideas.** For every other shipped file, the
 *    conjunction "computes an elapsed interval" and "closes an enquiry" is
 *    absent. The conjunction is what Requirement 8.4 forbids: computing a
 *    duration is fine on its own (the duplicate window does it), and closing an
 *    enquiry is fine on its own (a person may ask for it through the lifecycle),
 *    but doing both in one component is a second auto-closure.
 * 4. **The daily event has one listener**, and automatic-closure history has one
 *    author, so nothing else can piggy-back on the run or forge its trail.
 *
 * Comments are stripped before matching, using PHP's own tokeniser. Several
 * files discuss auto-closure in their docblocks — `Lifecycle` names
 * `settled_before()`, `Clock` mentions the closure interval — and a scan that
 * counted prose would either fail on documentation or have to be loosened until
 * it stopped proving anything.
 *
 * Each check also asserts the positive case against `AutoCloseJob` itself, so a
 * detector that has stopped matching anything fails here rather than passing
 * vacuously.
 *
 * It lives in the `pure` suite because reading the plugin's own files needs
 * neither WordPress nor a database.
 *
 * Covers Requirement 8.4.
 *
 * @package MarthrownEnquiryHub
 */

use PHPUnit\Framework\TestCase;

class AutoCloseSoleAuthorityTest extends TestCase {

	/**
	 * The one component permitted to close on an elapsed interval.
	 */
	const OWNER = 'includes/class-auto-close-job.php';

	/**
	 * The store, which declares the overdue-set read the job calls.
	 */
	const STORE = 'includes/class-enquiry-store.php';

	/**
	 * Expressions that compute or consume an elapsed interval.
	 *
	 * @var array<string,string> Description => pattern.
	 */
	const INTERVAL_PATTERNS = array(
		'the closure interval filter' => '/meh_auto_close_days/',
		'the overdue-set read'        => '/settled_before\s*\(/',
		'a relative date offset'      => '/->\s*modify\s*\(\s*[\'"@]?\s*[-+]|strtotime\s*\(\s*[\'"]\s*[-+]/',
		'a day-count subtraction'     => '/[-+]\s*(?:%d|\$?[a-z_]*days?)\s+days?\b/i',
		'SQL date arithmetic'         => '/\bDATE_SUB\b|\bDATE_ADD\b|\bINTERVAL\s+\d/i',
		'a raw day in seconds'        => '/\b86400\b/',
		'the job\'s own cutoff'       => '/AutoCloseJob::(?:cutoff|days|run)\s*\(/',
	);

	/**
	 * Expressions that close an enquiry.
	 *
	 * All of them name `closed` as a literal, which is the shape an
	 * interval-driven closure necessarily takes: it decides the target status
	 * itself rather than being handed one by a person.
	 *
	 * @var array<string,string> Description => pattern.
	 */
	const CLOSURE_PATTERNS = array(
		'a transition to closed'        => '/transition\s*\((?:[^;]*?)[\'"]closed[\'"]/s',
		'a status field set to closed'  => '/[\'"]status[\'"]\s*(?:=>|,)\s*[\'"]closed[\'"]/',
		'a status column set to closed' => '/status\s*=\s*[\'"]?%?s?[\'"]?\s*[\'"]closed[\'"]|status\s*=\s*[\'"]closed[\'"]/i',
		'the job\'s closure target'     => '/AutoCloseJob::TARGET_STATUS/',
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
	 * The descriptions of every pattern in a set that matches a file.
	 *
	 * @param array<string,string> $patterns Description => pattern.
	 * @param string               $code     Code to scan.
	 * @return string[] Descriptions of the matching patterns.
	 */
	protected function matching( array $patterns, $code ) {
		$found = array();

		foreach ( $patterns as $description => $pattern ) {
			if ( preg_match( $pattern, $code ) ) {
				$found[] = $description;
			}
		}

		return $found;
	}

	/**
	 * The scan sees the files it is supposed to scan.
	 *
	 * @return void
	 */
	public function test_the_scan_covers_the_shipped_source() {
		$files = $this->shipped_code();

		$this->assertNotEmpty( $files, 'The scan found the shipped PHP files.' );
		$this->assertArrayHasKey( self::OWNER, $files, 'The auto-closure job is among the scanned files.' );
		$this->assertArrayHasKey( self::STORE, $files, 'The store is among the scanned files.' );
		$this->assertGreaterThan( 10, count( $files ), 'The scan reaches the whole of includes/.' );
	}

	/**
	 * Requirement 8.4: only the job names the closure interval, so no other
	 * component can read the configured interval in order to act on it.
	 *
	 * @return void
	 */
	public function test_only_the_job_names_the_closure_interval() {
		$files = $this->shipped_code();

		$this->assertMatchesRegularExpression(
			'/meh_auto_close_days/',
			$files[ self::OWNER ],
			'The job declares the closure interval filter.'
		);

		foreach ( $files as $relative => $code ) {
			if ( self::OWNER === $relative ) {
				continue;
			}

			$this->assertDoesNotMatchRegularExpression(
				'/meh_auto_close_days/',
				$code,
				"{$relative} does not read the closure interval."
			);
		}
	}

	/**
	 * Requirement 8.4: only the job asks which settled enquiries are overdue.
	 *
	 * The store is exempt because it declares the read; the job is its only
	 * caller.
	 *
	 * @return void
	 */
	public function test_only_the_job_selects_the_overdue_settled_set() {
		$files = $this->shipped_code();

		$this->assertMatchesRegularExpression(
			'/EnquiryStore::settled_before\s*\(/',
			$files[ self::OWNER ],
			'The job reads the overdue set through the store.'
		);

		foreach ( $files as $relative => $code ) {
			if ( self::OWNER === $relative || self::STORE === $relative ) {
				continue;
			}

			$this->assertDoesNotMatchRegularExpression(
				'/settled_before\s*\(/',
				$code,
				"{$relative} does not select the overdue settled set."
			);
		}
	}

	/**
	 * Requirement 8.4: no other component both computes an elapsed interval and
	 * closes an enquiry.
	 *
	 * Either half alone is legitimate. The conjunction is a second auto-closure,
	 * and that is what this forbids.
	 *
	 * @return void
	 */
	public function test_no_other_component_closes_on_an_elapsed_interval() {
		$files = $this->shipped_code();
		$owner = $files[ self::OWNER ];

		// The detectors are live: the job itself trips both halves.
		$this->assertNotEmpty(
			$this->matching( self::INTERVAL_PATTERNS, $owner ),
			'The interval detector matches the job, so it is not looking for nothing.'
		);
		$this->assertNotEmpty(
			$this->matching( self::CLOSURE_PATTERNS, $owner ),
			'The closure detector matches the job, so it is not looking for nothing.'
		);

		foreach ( $files as $relative => $code ) {
			if ( self::OWNER === $relative ) {
				continue;
			}

			$intervals = $this->matching( self::INTERVAL_PATTERNS, $code );
			$closures  = $this->matching( self::CLOSURE_PATTERNS, $code );

			$this->assertTrue(
				array() === $intervals || array() === $closures,
				sprintf(
					'%s both computes an elapsed interval (%s) and closes an enquiry (%s). '
					. 'Requirement 8.4 makes AutoCloseJob the only component permitted to do both.',
					$relative,
					implode( ', ', $intervals ),
					implode( ', ', $closures )
				)
			);
		}
	}

	/**
	 * Requirement 8.4: the daily closure event has one listener.
	 *
	 * A second component hooked onto the same event would be closing enquiries on
	 * the elapsed interval too, whatever it called itself.
	 *
	 * @return void
	 */
	public function test_only_the_job_answers_the_daily_closure_event() {
		$files = $this->shipped_code();

		$this->assertMatchesRegularExpression(
			'/meh_cron_auto_close/',
			$files[ self::OWNER ],
			'The job declares the daily closure hook.'
		);

		foreach ( $files as $relative => $code ) {
			if ( self::OWNER === $relative ) {
				continue;
			}

			$this->assertDoesNotMatchRegularExpression(
				'/meh_cron_auto_close/',
				$code,
				"{$relative} neither listens on nor schedules the daily closure event."
			);
		}
	}

	/**
	 * Requirement 8.4, with 8.5: only the job records an automatic closure.
	 *
	 * The recorder is exempt because it lists `auto_closed` among the eight
	 * recognised entry types; listing a type is not writing one.
	 *
	 * @return void
	 */
	public function test_only_the_job_records_an_automatic_closure() {
		$files    = $this->shipped_code();
		$recorder = 'includes/class-history-recorder.php';

		$this->assertMatchesRegularExpression(
			'/auto_closed/',
			$files[ self::OWNER ],
			'The job records the automatic-closure entry type.'
		);

		foreach ( $files as $relative => $code ) {
			if ( self::OWNER === $relative || $recorder === $relative ) {
				continue;
			}

			$this->assertDoesNotMatchRegularExpression(
				'/auto_closed/',
				$code,
				"{$relative} records no automatic-closure history."
			);
		}
	}

	/**
	 * The job closes through the lifecycle rather than by writing rows.
	 *
	 * This is what keeps Requirement 8.4 honest in the other direction: were the
	 * job to write `status` itself it would become a second writer of the column
	 * alongside `Lifecycle`, and an automatic closure would stop being
	 * indistinguishable from a manual one downstream.
	 *
	 * @return void
	 */
	public function test_the_job_closes_through_the_lifecycle_and_touches_no_database() {
		$owner = $this->shipped_code()[ self::OWNER ];

		$this->assertMatchesRegularExpression(
			'/Lifecycle::transition\s*\(/',
			$owner,
			'The job closes by asking the lifecycle to transition.'
		);

		$this->assertDoesNotMatchRegularExpression(
			'/\$wpdb/',
			$owner,
			'The job touches no database directly.'
		);

		foreach ( array( 'UPDATE', 'INSERT', 'DELETE' ) as $statement ) {
			$this->assertDoesNotMatchRegularExpression(
				'/\b' . $statement . '\b/',
				$owner,
				"The job issues no {$statement} of its own."
			);
		}
	}
}
