/**
 * Windows only (laptop): lets the browser tests run their WP-CLI commands with the same
 * quoting as on macOS. Not part of the suite; load it with `node -r`.
 *
 *   NODE_PATH=... node -r ./tests/e2e/lap-shim.cjs tests/e2e/settings-flows.cjs
 *
 * The tests run `npx -y @wordpress/env@11 run cli <command>` through execSync. On
 * Windows npx is a .cmd file, so cmd.exe splits <command> again and single quotes do not
 * group words: `wp eval '...'` and `wp option update x '{...}'` arrive broken. This file
 * runs <command> in the wp-env container with bash instead (docker exec, same user and
 * folder as `wp-env run`), which parses the quotes as the macOS shell does. Any other
 * command goes through unchanged.
 *
 * @package Edit_Orders_For_WooCommerce
 */

'use strict';

const cp = require( 'child_process' );

const original = cp.execSync;
const PATTERN = /^npx -y @wordpress\/env@11 run (cli|tests-cli) ([\s\S]+)$/;
const containers = {};

function container( service ) {
	if ( ! containers[ service ] ) {
		const names = cp.execFileSync( 'docker', [ 'ps', '--format', '{{.Names}}' ], { encoding: 'utf8' } ).split( /\r?\n/ );
		const name = names.find( ( n ) => n.endsWith( `-${ service }-1` ) && ( 'cli' !== service || ! n.endsWith( '-tests-cli-1' ) ) );
		if ( ! name ) {
			throw new Error( `lap-shim: no running wp-env ${ service } container` );
		}
		containers[ service ] = name;
	}
	return containers[ service ];
}

cp.execSync = function ( command, options ) {
	const match = 'string' === typeof command && command.match( PATTERN );
	if ( ! match ) {
		return original.apply( this, arguments );
	}
	const opts = Object.assign( {}, options || {} );
	delete opts.shell;
	return cp.execFileSync( 'docker', [ 'exec', '-w', '/var/www/html', container( match[ 1 ] ), 'bash', '-c', match[ 2 ] ], opts );
};
