<?php
/**
 * Enquiry data is never destroyed by the plugin.
 *
 * Requirement 1.14 says an uninstall retains every Enquiry Store table, every
 * row in them and the stored schema version, and the design answers that by
 * adding no uninstall handler and exposing no drop method. Both are claims about
 * what the shipped source does *not* contain, so the check is a source scan
 * rather than a behavioural test: a behavioural test can only ever prove that
 * the paths it happens to call leave the tables alone, where the absence of a
 * `DROP TABLE` anywhere in `includes/` covers every path at once, including ones
 * added later.
 *
 * It lives in the `pure` suite because reading the plugin's own files needs
 * neither WordPress nor a database.
 *
 * Covers Requirements 1.14, 1.16.
 *
 * @package MarthrownEnquiryHub
 */

use PHPUnit\Framework\TestCase;

class SchemaNoDropTest extends TestCase {

	/**
	 * Plugin root directory.
	 *
	 * @return string
	 */
	protected function plugin_dir() {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Every shipped PHP file, keyed by its path relative to the plugin root.
	 *
	 * @return array<string,string> Relative path => file contents.
	 */
	protected function shipped_php() {
		$root  = $this->plugin_dir();
		$files = array();

		foreach ( (array) glob( $root . '/includes/*.php' ) as $path ) {
			$files[ 'includes/' . basename( $path ) ] = (string) file_get_contents( $path );
		}

		$files['marthrown-enquiry-hub.php'] = (string) file_get_contents( $root . '/marthrown-enquiry-hub.php' );

		return $files;
	}

	public function test_no_shipped_file_drops_or_truncates_a_table() {
		$files = $this->shipped_php();

		$this->assertNotEmpty( $files, 'The scan found the shipped PHP files.' );

		foreach ( $files as $relative => $source ) {
			$this->assertDoesNotMatchRegularExpression(
				'/\bDROP\s+TABLE\b/i',
				$source,
				"{$relative} issues no DROP TABLE."
			);

			// Matched narrowly: `truncate` is also the name of the Validator's
			// string-shortening helper (Requirement 3.7), which is not this.
			$this->assertDoesNotMatchRegularExpression(
				'/\bTRUNCATE\s+(?:TABLE\b|`)/i',
				$source,
				"{$relative} issues no TRUNCATE TABLE."
			);
		}
	}

	public function test_schema_exposes_no_method_that_could_drop_a_table() {
		$source = (string) file_get_contents( $this->plugin_dir() . '/includes/class-schema.php' );

		$this->assertNotSame( '', $source, 'The schema class was read.' );

		preg_match_all( '/function\s+([a-z0-9_]+)\s*\(/i', $source, $matches );

		$methods = isset( $matches[1] ) ? $matches[1] : array();

		$this->assertNotEmpty( $methods, 'The schema class declares methods.' );

		foreach ( $methods as $method ) {
			$this->assertDoesNotMatchRegularExpression(
				'/drop|truncate|destroy|uninstall|reset/i',
				$method,
				"Schema::{$method}() does not read as a destructive operation."
			);
		}
	}

	public function test_the_plugin_registers_no_uninstall_handler() {
		$root = $this->plugin_dir();

		$this->assertFileDoesNotExist( $root . '/uninstall.php', 'No uninstall.php ships with the plugin.' );

		foreach ( $this->shipped_php() as $relative => $source ) {
			$this->assertDoesNotMatchRegularExpression(
				'/register_uninstall_hook/i',
				$source,
				"{$relative} registers no uninstall hook."
			);
		}
	}
}
