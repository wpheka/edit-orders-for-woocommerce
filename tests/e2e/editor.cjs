/**
 * Browser test of the store-owner editor (M1), against the wp-env store.
 *
 * Run from the plugin folder, with the playwright package installed somewhere outside
 * the synced plugins folder and found through NODE_PATH:
 *   NODE_PATH=/path/to/node_modules node tests/e2e/editor.cjs [screenshot-dir]
 *
 * Uses the installed Chrome (channel "chrome"), so no browser download is needed.
 *
 * Scenario 1: quantity down. Order screen box > editor > quantity 3 to 1 > preview
 * shows "Refund $45.20" > apply > the order shows the refund.
 * Scenario 2: address Ontario to Alberta > preview shows "Customer pays $12.00" > apply >
 * the order screen box lists the balance order with its pay link.
 *
 * @package Edit_Orders_For_WooCommerce
 */

'use strict';

const { chromium } = require( 'playwright' );
const { execSync } = require( 'child_process' );
const path = require( 'path' );
const fs = require( 'fs' );

const BASE = 'http://localhost:8890';
const SHOTS = process.argv[ 2 ] || path.join( __dirname, '..', '..', '.e2e-screens' );
const FIXTURE = 'wp-content/plugins/edit-orders-for-woocommerce/tests/e2e/order-fixture.php';
const created = [];
let failures = 0;

function wp( args ) {
	return execSync( `npx -y @wordpress/env@10 run cli wp eval-file ${ FIXTURE } ${ args }`, {
		env: { ...process.env, MSYS_NO_PATHCONV: '1' },
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} );
}

function createOrder( qty ) {
	const match = wp( `create ${ qty }` ).match( /ORDER_ID=(\d+)/ );
	if ( ! match ) {
		throw new Error( 'Could not create the test order.' );
	}
	created.push( match[ 1 ] );
	return match[ 1 ];
}

function check( label, condition, detail ) {
	if ( condition ) {
		console.log( `  PASS  ${ label }` );
	} else {
		failures++;
		console.log( `  FAIL  ${ label }${ detail ? ` (${ detail })` : '' }` );
	}
}

( async () => {
	fs.mkdirSync( SHOTS, { recursive: true } );
	const browser = await chromium.launch( { channel: 'chrome', headless: true } );
	const page = await browser.newPage( { viewport: { width: 1400, height: 1000 } } );
	const errors = [];
	// Only errors from this plugin's own script count; other plugins' noise is reported, not failed.
	const foreign = [];
	page.on( 'pageerror', ( error ) => ( /edit-orders-for-woocommerce/.test( error.stack || '' ) ? errors : foreign ).push( error.message ) );
	page.on( 'console', ( message ) => {
		if ( 'error' !== message.type() || /favicon|wordpress\.org|Failed to load resource/i.test( message.text() ) ) {
			return;
		}
		const source = ( message.location() && message.location().url ) || '';
		( /edit-orders-for-woocommerce/.test( source ) ? errors : foreign ).push( `${ message.text() } [${ source || page.url() }]` );
	} );

	try {
		await page.goto( `${ BASE }/wp-login.php` );
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'password' );
		await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );

		// ----------------------------------------------------------------
		console.log( 'Scenario 1: quantity down from the order screen' );
		const first = createOrder( 3 );
		await page.goto( `${ BASE }/wp-admin/admin.php?page=wc-orders&action=edit&id=${ first }` );
		const box = page.locator( '#edit-orders-for-woocommerce' );
		await box.screenshot( { path: path.join( SHOTS, '1-order-box.png' ) } );
		check( 'order screen box shows the edit button', await box.locator( 'text=Edit items or address' ).count() === 1 );

		await Promise.all( [ page.waitForNavigation(), box.locator( 'text=Edit items or address' ).click() ] );
		check( 'editor opens', ( await page.textContent( 'h1' ) ).includes( `Edit order #${ first }` ) );
		await page.screenshot( { path: path.join( SHOTS, '2-editor.png' ), fullPage: true } );

		await page.fill( '.edit-orders-items input[type="number"]', '1' );
		await page.click( '#edit-orders-preview' );
		await page.waitForSelector( '.edit-orders-preview' );
		const refundText = await page.textContent( '.edit-orders-refund' );
		check( 'preview: refund $45.20 through the test gateway', /45\.20/.test( refundText ) && /Test payment/.test( refundText ), refundText.trim() );
		check( 'preview: stock line for 2 T-Shirts', ( await page.textContent( '.edit-orders-stock' ) ).includes( '2 x T-Shirt back to stock' ) );
		await page.screenshot( { path: path.join( SHOTS, '3-preview.png' ), fullPage: true } );

		await Promise.all( [ page.waitForNavigation(), page.click( '#edit-orders-apply' ) ] );
		const body = await page.textContent( '#woocommerce-order-items' );
		check( 'back on the order: refund of $45.20 recorded', /Refunded/.test( body ) && /45\.20/.test( body ) );
		await page.screenshot( { path: path.join( SHOTS, '4-order-after.png' ), fullPage: true } );

		// ----------------------------------------------------------------
		console.log( 'Scenario 2: address change Ontario to Alberta' );
		const second = createOrder( 1 );
		await page.goto( `${ BASE }/wp-admin/admin.php?page=edit-orders-for-woocommerce-editor&order_id=${ second }` );
		await page.click( '.nav-tab[data-mode="address"]' );
		const shipping = page.locator( '.edit-orders-address[data-type="shipping"]' );
		await shipping.locator( '[name="shipping[address_1]"]' ).fill( '100 8 Ave SW' );
		await shipping.locator( '[name="shipping[city]"]' ).fill( 'Calgary' );
		await shipping.locator( '[name="shipping[postcode]"]' ).fill( 'T2P 1B3' );
		await shipping.locator( 'select[name="shipping[state]"]' ).selectOption( 'AB' );
		await page.click( '#edit-orders-preview' );
		await page.waitForSelector( '.edit-orders-preview, .notice-error' );
		const balanceText = ( await page.locator( '.edit-orders-balance' ).count() ) ? await page.textContent( '.edit-orders-balance' ) : await page.textContent( '#edit-orders-preview-panel' );
		check( 'preview: customer pays $12.00 through a pay link', /12\.00/.test( balanceText ) && /pay link/.test( balanceText ), balanceText.trim() );
		check( 'preview: HST $3.90 refunded once the balance is paid', /3\.90/.test( await page.textContent( '.edit-orders-refund' ) ) );
		await page.screenshot( { path: path.join( SHOTS, '5-address-preview.png' ), fullPage: true } );

		await Promise.all( [ page.waitForNavigation(), page.click( '#edit-orders-apply' ) ] );
		const balances = page.locator( '#edit-orders-for-woocommerce .edit-orders-balances li' );
		check( 'order screen box lists the balance order', await balances.count() === 1 );
		check( 'with its pay link', /pay_for_order=true/.test( await balances.locator( '.edit-orders-pay-link' ).inputValue() ) );
		await page.locator( '#edit-orders-for-woocommerce' ).screenshot( { path: path.join( SHOTS, '6-balance-box.png' ) } );

		check( 'no JavaScript errors from this plugin', errors.length === 0, errors.join( ' | ' ) );
		if ( foreign.length ) {
			console.log( `  NOTE  ${ foreign.length } console error(s) from other code: ${ [ ...new Set( foreign ) ].join( ' | ' ) }` );
		}
	} catch ( error ) {
		failures++;
		console.log( `  FAIL  ${ error.message }` );
		await page.screenshot( { path: path.join( SHOTS, 'failure.png' ), fullPage: true } ).catch( () => {} );
	} finally {
		await browser.close();
		if ( created.length ) {
			wp( `clean ${ created.join( ' ' ) }` );
		}
	}

	console.log( failures ? `${ failures } failed` : 'Browser test passed.' );
	process.exit( failures ? 1 : 0 );
} )();
