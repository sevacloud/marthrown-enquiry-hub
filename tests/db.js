#!/usr/bin/env node
/**
 * Start or stop the local database the `wordpress` PHPUnit suite runs against.
 *
 * Wrapping `mysqld` and `mysqladmin` in a script rather than inlining them in
 * package.json buys two things: defaults, so a shell that never set MEH_DB_DATA
 * or MEH_DB_PORT still works, and one spelling of the arguments across shells
 * instead of cmd's `%VAR%` and POSIX's `$VAR`.
 *
 * Usage: node tests/db.js start|stop
 */

const { spawnSync } = require( 'child_process' );
const os = require( 'os' );
const path = require( 'path' );

const action = process.argv[ 2 ];

if ( action !== 'start' && action !== 'stop' ) {
	process.stderr.write( 'Usage: node tests/db.js start|stop\n' );
	process.exit( 2 );
}

const port = process.env.MEH_DB_PORT || '3307';
const dataDir =
	process.env.MEH_DB_DATA || path.join( os.homedir(), 'dev', 'mariadb-data' );

const [ command, args ] =
	action === 'start'
		? [
				'mysqld',
				[
					`--datadir=${ dataDir }`,
					`--port=${ port }`,
					'--bind-address=127.0.0.1',
					'--console',
				],
		  ]
		: [
				'mysqladmin',
				[
					'--protocol=tcp',
					'-h',
					'127.0.0.1',
					'-P',
					port,
					'-u',
					'root',
					'shutdown',
				],
		  ];

const result = spawnSync( command, args, { stdio: 'inherit', shell: true } );

if ( result.error ) {
	process.stderr.write(
		`Could not run ${ command }: ${ result.error.message }\n` +
			"Is the database server's bin directory on your PATH?\n"
	);
	process.exit( 1 );
}

process.exit( result.status === null ? 1 : result.status );
