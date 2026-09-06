<?php
/**
 * PHPUnit bootstrap for the Marthrown Enquiry Hub.
 *
 * Serves both suites declared in phpunit.xml:
 *
 *  - `pure` needs only the Composer autoloader, so this file loads WordPress
 *    only when the WordPress PHPUnit test library is actually available.
 *  - `wordpress` needs that library. Inside wp-env it lives at the path in
 *    WP_TESTS_DIR; the fallbacks cover a hand-rolled local setup.
 *
 * The `wordpress` suite also gets a database of its own per PHPUnit process, so
 * several processes can run at once — see meh_tests_run_database() below and
 * tests/wp-tests-config.php.
 *
 * @package MarthrownEnquiryHub
 */

declare( strict_types = 1 );

$meh_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! file_exists( $meh_autoload ) ) {
	fwrite( STDERR, "Composer dependencies are missing. Run `composer install` first.\n" );
	exit( 1 );
}

require_once $meh_autoload;

/**
 * Locate the WordPress PHPUnit test library.
 *
 * @return string|null Absolute path to the library, or null when absent.
 */
function meh_locate_wp_tests_dir() {
	$home = getenv( 'USERPROFILE' );

	if ( ! $home ) {
		$home = getenv( 'HOME' );
	}

	$candidates = array(
		getenv( 'WP_TESTS_DIR' ),
		getenv( 'WP_PHPUNIT__DIR' ),
		'/wordpress-phpunit',
		'/tmp/wordpress-tests-lib',
		// Default location the readme's local route suggests, so a shell that
		// lost WP_TESTS_DIR still finds a standard checkout.
		$home ? rtrim( $home, '/\\' ) . '/dev/wordpress-develop/tests/phpunit' : null,
	);

	foreach ( $candidates as $candidate ) {
		if ( ! $candidate ) {
			continue;
		}

		$candidate = rtrim( $candidate, '/\\' );

		if ( file_exists( $candidate . '/includes/functions.php' ) ) {
			return $candidate;
		}
	}

	return null;
}

/**
 * The suite PHPUnit was asked to run, read from the command line.
 *
 * @return string 'pure', 'wordpress', or '' when no suite was named.
 */
function meh_requested_suite() {
	$argv = isset( $_SERVER['argv'] ) ? array_values( (array) $_SERVER['argv'] ) : array();

	foreach ( $argv as $index => $arg ) {
		if ( '--testsuite' === $arg && isset( $argv[ $index + 1 ] ) ) {
			return (string) $argv[ $index + 1 ];
		}

		if ( 0 === strpos( (string) $arg, '--testsuite=' ) ) {
			return substr( (string) $arg, strlen( '--testsuite=' ) );
		}
	}

	return '';
}

$meh_suite = meh_requested_suite();

/*
 * The `pure` suite must not boot WordPress even when the test library is sitting
 * right there. Its whole value is that the code it covers depends on no
 * WordPress function: if WordPress were loaded anyway, a pure test could come to
 * rely on one silently and the suite would stop proving anything. Skipping the
 * boot also keeps it fast, because booting WordPress reinstalls the test
 * database.
 */
$meh_wp_tests_dir = 'pure' === $meh_suite ? null : meh_locate_wp_tests_dir();

define( 'MEH_TESTS_WP_LOADED', null !== $meh_wp_tests_dir );

if ( ! MEH_TESTS_WP_LOADED ) {
	if ( 'wordpress' === $meh_suite ) {
		fwrite(
			STDERR,
			"The `wordpress` suite needs the WordPress PHPUnit test library and a running database.\n"
			. "\n"
			. "Local route (no Docker): set WP_TESTS_DIR to a wordpress-develop checkout's\n"
			. "tests/phpunit directory, start the database with `npm run db:start`, then\n"
			. "`npm run test:php:wp`.\n"
			. "\n"
			. "Docker route: `npm run env:start` then `npm run test:php:wp-env`.\n"
			. "\n"
			. "See the Testing section of readme.md for the full setup.\n"
		);
		exit( 1 );
	}

	return;
}

// The WordPress test suite needs the PHPUnit polyfills; point it at ours.
if ( ! getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	$meh_polyfills = dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills';

	if ( is_dir( $meh_polyfills ) ) {
		putenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH=' . $meh_polyfills );
	}
}

/**
 * Locate the wp-tests-config.php the WordPress test library would load.
 *
 * Mirrors the resolution the library's own bootstrap performs: the file sits
 * either inside the test library directory (where wp-env puts it) or two levels
 * up, at the root of a wordpress-develop checkout.
 *
 * @param string $wp_tests_dir Test library directory.
 * @return string|null Absolute path with forward slashes, or null when absent.
 */
function meh_locate_wp_tests_config( $wp_tests_dir ) {
	$candidates = array(
		getenv( 'MEH_WP_TESTS_CONFIG' ),
		$wp_tests_dir . '/wp-tests-config.php',
		dirname( $wp_tests_dir, 2 ) . '/wp-tests-config.php',
	);

	foreach ( $candidates as $candidate ) {
		if ( $candidate && is_readable( $candidate ) ) {
			return str_replace( '\\', '/', (string) $candidate );
		}
	}

	return null;
}

/**
 * Name of the database this PHPUnit process owns.
 *
 * Every invocation installs WordPress from scratch, and installing drops the
 * core tables first. Two processes sharing one database therefore pull the
 * tables out from under each other. Each process gets its own database instead,
 * named from something process-unique.
 *
 * MEH_TESTS_DB_NAME set in the environment wins and is left alone — that is the
 * escape hatch back to a single shared database (`MEH_TESTS_DB_NAME=meh_tests`)
 * and the way CI can name the database itself.
 *
 * @return array{name:string,owned:bool} Database name, and whether this process
 *                                       created it and so must remove it.
 */
function meh_tests_run_database() {
	$given = getenv( 'MEH_TESTS_DB_NAME' );

	if ( is_string( $given ) && '' !== $given ) {
		return array(
			'name'  => $given,
			'owned' => false,
		);
	}

	$run_id = getenv( 'MEH_TESTS_RUN_ID' );

	if ( ! is_string( $run_id ) || '' === $run_id ) {
		$run_id = (string) getmypid();
	}

	$run_id = preg_replace( '/[^A-Za-z0-9_]/', '', $run_id );

	return array(
		'name'  => 'meh_tests_' . $run_id,
		'owned' => true,
	);
}

/**
 * Connect to the test database server, without selecting a database.
 *
 * @return mysqli
 */
function meh_tests_db_connect() {
	$host = (string) DB_HOST;
	$port = 3306;

	if ( preg_match( '/^(.+):(\d+)$/', $host, $matches ) ) {
		$host = $matches[1];
		$port = (int) $matches[2];
	}

	try {
		$link = new mysqli( $host, DB_USER, DB_PASSWORD, '', $port );
	} catch ( Throwable $error ) {
		fwrite( STDERR, sprintf( "Cannot reach the test database at %s: %s\n", DB_HOST, $error->getMessage() ) );
		exit( 1 );
	}

	return $link;
}

/**
 * Create this process's database, empty.
 *
 * @param string $name Database name.
 * @return void
 */
function meh_tests_create_run_database( $name ) {
	$link = meh_tests_db_connect();

	$link->query( sprintf( 'DROP DATABASE IF EXISTS `%s`', $name ) );

	$charset = defined( 'DB_CHARSET' ) && DB_CHARSET ? DB_CHARSET : 'utf8mb4';

	$created = $link->query( sprintf( 'CREATE DATABASE `%s` DEFAULT CHARACTER SET %s', $name, $charset ) );

	if ( ! $created ) {
		fwrite(
			STDERR,
			sprintf(
				"Cannot create the per-run test database `%s`: %s\n"
				. "The test database user needs rights over the meh_tests_%% name pattern:\n"
				. "  GRANT ALL PRIVILEGES ON `meh\\_tests\\_%%`.* TO '%s'@'127.0.0.1';\n"
				. "Or set MEH_TESTS_DB_NAME to a database it already owns, which gives up\n"
				. "the ability to run several PHPUnit processes at once.\n",
				$name,
				$link->error,
				DB_USER
			)
		);

		$link->close();
		exit( 1 );
	}

	$link->close();
}

/**
 * Remove this process's database at the end of the run.
 *
 * Only ever called for a database this process created, and refuses any name
 * outside the per-run pattern, so the shared `meh_tests` database and anything
 * else on the server is out of reach.
 *
 * @param string $name Database name.
 * @return void
 */
function meh_tests_drop_run_database( $name ) {
	if ( ! preg_match( '/^meh_tests_[A-Za-z0-9_]+$/', $name ) ) {
		return;
	}

	$link = meh_tests_db_connect();
	$link->query( sprintf( 'DROP DATABASE IF EXISTS `%s`', $name ) );
	$link->close();
}

/*
 * Point the WordPress test library at tests/wp-tests-config.php, which sets the
 * per-run database name and then defers to the developer's own config file for
 * everything else. The library re-reads that path in the separate PHP process it
 * shells out to for the install, which is why the choice travels as a constant
 * and an environment variable rather than as a variable in this file.
 *
 * With no config file to defer to there is nothing to isolate, so the run falls
 * back to the library's own resolution and its single shared database.
 */
$meh_base_config = meh_locate_wp_tests_config( $meh_wp_tests_dir );

if ( null === $meh_base_config ) {
	fwrite(
		STDERR,
		"Warning: no wp-tests-config.php found next to the WordPress test library.\n"
		. "Falling back to the library's own lookup. Concurrent PHPUnit processes will\n"
		. "share one database and interfere with each other.\n"
	);
} else {
	$meh_run_database = meh_tests_run_database();

	putenv( 'MEH_WP_TESTS_CONFIG=' . $meh_base_config );
	putenv( 'MEH_TESTS_DB_NAME=' . $meh_run_database['name'] );

	define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );

	// Loading it here, rather than leaving it to the library, is what makes the
	// database constants available in time to create the database below.
	require_once WP_TESTS_CONFIG_FILE_PATH;

	if ( $meh_run_database['owned'] ) {
		meh_tests_create_run_database( $meh_run_database['name'] );
		register_shutdown_function( 'meh_tests_drop_run_database', $meh_run_database['name'] );
	}
}

require_once $meh_wp_tests_dir . '/includes/functions.php';

/**
 * Load the plugin under test before WordPress finishes booting.
 *
 * Loading the main file defines the constants and registers the activation
 * hooks; `meh_bootstrap()` then runs on `plugins_loaded` as it does on the live
 * site, loading includes/ and installing the Enquiry Store schema, because the
 * enquiry layer no longer depends on FluentCRM Pro being present.
 *
 * Tests still require the class files they exercise directly rather than relying
 * on that, so each test class states its own dependencies, and they use the fakes
 * in tests/fakes/ for FluentCRM and WP Booking System.
 */
function meh_manually_load_plugin() {
	require dirname( __DIR__ ) . '/marthrown-enquiry-hub.php';
}
tests_add_filter( 'muplugins_loaded', 'meh_manually_load_plugin' );

require $meh_wp_tests_dir . '/includes/bootstrap.php';
