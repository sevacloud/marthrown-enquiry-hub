<?php
/**
 * Per-run wp-tests-config.php for the `wordpress` PHPUnit suite.
 *
 * The WordPress test library reinstalls WordPress on every invocation, and
 * installing begins by dropping the core tables. Two PHPUnit processes pointed
 * at one database therefore break each other: the second process's install
 * removes `wptests_options` from under the first. So each process runs against
 * its own database.
 *
 * `DB_NAME` cannot be changed after the fact — it is a constant, read by wpdb in
 * this process and again in the separate PHP process the library shells out to
 * for the install. This file gets in first: it defines `DB_NAME` from
 * MEH_TESTS_DB_NAME, then hands over to the developer's own config file for the
 * credentials, ABSPATH, salts and table prefix. PHP keeps the first definition
 * of a constant, so that file's own `DB_NAME` is a no-op.
 *
 * Nothing here duplicates the developer's settings, and their config file needs
 * no edit: tests/bootstrap.php passes its location in MEH_WP_TESTS_CONFIG.
 *
 * @package MarthrownEnquiryHub
 */

/*
 * Named apart from anything in tests/bootstrap.php on purpose. PHP includes
 * share the variable scope of the line that included them, so this file, that
 * bootstrap and the library's bootstrap all see one set of variables — and
 * `$table_prefix`, set by the config file below, reaching the library that way
 * is exactly how the WordPress test suite expects a config file to work.
 */
$meh_config_db_name = getenv( 'MEH_TESTS_DB_NAME' );

if ( is_string( $meh_config_db_name ) && '' !== $meh_config_db_name && ! defined( 'DB_NAME' ) ) {
	define( 'DB_NAME', $meh_config_db_name );
}

$meh_config_delegate = getenv( 'MEH_WP_TESTS_CONFIG' );

if ( ! $meh_config_delegate || ! is_readable( (string) $meh_config_delegate ) ) {
	fwrite(
		STDERR,
		"MEH_WP_TESTS_CONFIG must point at a readable wp-tests-config.php.\n"
		. "tests/bootstrap.php sets it; this file is not meant to be loaded on its own.\n"
	);
	exit( 1 );
}

/*
 * The file below re-defines the constants set above. That is expected and
 * harmless — the first definition stands — but PHP warns about it, so those
 * warnings, and only those, are swallowed for the duration of the include.
 */
set_error_handler(
	static function ( $errno, $errstr ) {
		return (bool) preg_match( '/Constant .* already defined/', (string) $errstr );
	}
);

require_once $meh_config_delegate;

restore_error_handler();

unset( $meh_config_db_name, $meh_config_delegate );
