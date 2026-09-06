<?php
/**
 * The Intake Secret is compared in constant time.
 *
 * Requirement 16.10 asks for a comparison whose execution time is independent of
 * the position of the first differing character. That is not something a
 * behavioural test can establish: `===` and `hash_equals()` return the same
 * booleans for every input, and timing a PHP string comparison inside PHPUnit
 * measures the machine's noise rather than the code. So the check reads the
 * source instead, which is exactly as strong as the claim — the claim is about
 * which comparison the code uses.
 *
 * Three things are pinned down inside `IntakeEndpoint::authenticate()`:
 *
 * 1. The presented secret and the stored one are handed to `hash_equals()`.
 * 2. Neither is compared to the other with `==`, `===`, `!=` or `!==`. The
 *    method does use `===` — against the empty string, to deny every request on
 *    a site with no secret configured — so a blanket ban on `===` would fail on
 *    code that is correct. What is banned is the two secrets being compared to
 *    each other.
 * 3. No character-by-character comparison function stands in for it: `strcmp()`
 *    and its relatives short-circuit at the first differing byte, which is the
 *    behaviour Requirement 16.10 rules out.
 *
 * The file is stripped of comments before matching, using PHP's own tokeniser,
 * so the docblock above `authenticate()` — which discusses `===` by name — is not
 * what the scan reads.
 *
 * It lives in the `pure` suite because reading the plugin's own files needs
 * neither WordPress nor a database.
 *
 * Covers Requirement 16.10.
 *
 * @package MarthrownEnquiryHub
 */

use PHPUnit\Framework\TestCase;

class IntakeSecretComparisonTest extends TestCase {

	/**
	 * The file that authenticates an Intake Webhook Request.
	 */
	const ENDPOINT = 'includes/class-intake-endpoint.php';

	/**
	 * The two locals holding the values being compared.
	 */
	const SECRETS = '\$(?:presented|stored)';

	/**
	 * The endpoint's source with comments and docblocks removed.
	 *
	 * @return string
	 */
	protected function endpoint_code() {
		$path = dirname( __DIR__, 2 ) . '/' . self::ENDPOINT;

		$this->assertFileExists( $path, 'The intake endpoint ships with the plugin.' );

		$code = '';

		foreach ( token_get_all( (string) file_get_contents( $path ) ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$code .= "\n";
				continue;
			}

			$code .= is_array( $token ) ? $token[1] : $token;
		}

		$this->assertNotSame( '', trim( $code ), 'The scan read the endpoint source.' );

		return $code;
	}

	/**
	 * The body of one method, braces included.
	 *
	 * @param string $code Source to read.
	 * @param string $name Method name.
	 * @return string
	 */
	protected function method_body( $code, $name ) {
		$declared = strpos( $code, 'function ' . $name );

		$this->assertNotFalse( $declared, "The endpoint declares {$name}()." );

		$open = strpos( $code, '{', $declared );

		$this->assertNotFalse( $open, "{$name}() has a body." );

		$depth  = 0;
		$length = strlen( $code );

		for ( $at = $open; $at < $length; $at++ ) {
			if ( '{' === $code[ $at ] ) {
				$depth++;
				continue;
			}

			if ( '}' === $code[ $at ] ) {
				$depth--;

				if ( 0 === $depth ) {
					return substr( $code, $open, $at - $open + 1 );
				}
			}
		}

		$this->fail( "{$name}() has an unbalanced body." );
	}

	/**
	 * Requirement 16.10: the two secrets are compared with `hash_equals()`.
	 *
	 * @return void
	 */
	public function test_the_secret_comparison_uses_hash_equals() {
		$authenticate = $this->method_body( $this->endpoint_code(), 'authenticate' );

		$this->assertMatchesRegularExpression(
			'/hash_equals\s*\(\s*' . self::SECRETS . '\s*,\s*' . self::SECRETS . '\s*\)/',
			$authenticate,
			'authenticate() hands the presented and stored secrets to hash_equals().'
		);
	}

	/**
	 * Requirement 16.10: the two secrets are not compared to each other with an
	 * equality operator, which short-circuits at the first differing character.
	 *
	 * @return void
	 */
	public function test_the_secrets_are_not_compared_with_an_equality_operator() {
		$authenticate = $this->method_body( $this->endpoint_code(), 'authenticate' );

		$this->assertDoesNotMatchRegularExpression(
			'/' . self::SECRETS . '\s*(?:===|==|!==|!=|<>)\s*' . self::SECRETS . '/',
			$authenticate,
			'authenticate() compares the two secrets with no equality operator.'
		);

		// The stored secret is read through a helper, so a comparison against the
		// option read directly would sidestep the pattern above.
		$this->assertDoesNotMatchRegularExpression(
			'/(?:===|==|!==|!=|<>)\s*(?:self::)?(?:stored_secret|presented_secret|get_option)\s*\(/',
			$authenticate,
			'authenticate() compares no secret-returning call with an equality operator.'
		);
	}

	/**
	 * Requirement 16.10: no short-circuiting comparison function stands in for
	 * `hash_equals()` anywhere in the endpoint.
	 *
	 * @return void
	 */
	public function test_no_short_circuiting_comparison_replaces_it() {
		$code = $this->endpoint_code();

		foreach ( array( 'strcmp', 'strcasecmp', 'strncmp', 'strncasecmp', 'substr_compare', 'similar_text' ) as $function ) {
			$this->assertDoesNotMatchRegularExpression(
				'/\b' . $function . '\s*\(/',
				$code,
				"The endpoint calls no {$function}()."
			);
		}
	}
}
