/**
 * Captures the wordpress.org screenshots listed in readme.txt into .wordpress-org/.
 *
 *   NODE_PATH=/path/to/node_modules node tests/e2e/screenshots.cjs
 *
 * 1. Editor preview  2. Order screen box with a balance order  3. Customer panel
 * 4. Customer address preview  5. Edit Orders page (request and log)  6. Settings
 *
 * Every order it creates is deleted afterwards.
 *
 * @package Edit_Orders_For_WooCommerce
 */

'use strict';

const { chromium } = require( 'playwright' );
const { execSync } = require( 'child_process' );
const path = require( 'path' );
const fs = require( 'fs' );

const BASE = 'http://localhost:8890';
const OUT = path.join( __dirname, '..', '..', '.wordpress-org' );
const FIXTURE = 'wp-content/plugins/edit-orders-for-woocommerce/tests/e2e/order-fixture.php';
const created = [];

function cli( command ) {
	return execSync( `npx -y @wordpress/env@10 run cli ${ command }`, {
		env: { ...process.env, MSYS_NO_PATHCONV: '1' },
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} );
}

function createOrder( qty, guest, sku ) {
	const out = cli( `wp eval-file ${ FIXTURE } create ${ qty } ${ guest ? 'guest' : 'account' } ${ sku || 'EO-TSHIRT' }` );
	const order = {
		id: ( out.match( /ORDER_ID=(\d+)/ ) || [] )[ 1 ],
		received: ( out.match( /RECEIVED=(\S+)/ ) || [] )[ 1 ],
		view: ( out.match( /VIEW=(\S+)/ ) || [] )[ 1 ],
	};
	created.push( order.id );
	return order;
}

async function shot( page, locator, name ) {
	await locator.scrollIntoViewIfNeeded();
	await locator.screenshot( { path: path.join( OUT, name ) } );
	console.log( `  saved ${ name }` );
}

( async () => {
	fs.mkdirSync( OUT, { recursive: true } );
	const browser = await chromium.launch( { channel: 'chrome', headless: true } );

	try {
		const admin = await ( await browser.newContext( { viewport: { width: 1280, height: 1000 } } ) ).newPage();
		await admin.goto( `${ BASE }/wp-login.php` );
		await admin.fill( '#user_login', 'admin' );
		await admin.fill( '#user_pass', 'password' );
		await Promise.all( [ admin.waitForNavigation(), admin.click( '#wp-submit' ) ] );
		// Hide core update nags so they don't sit in the screenshots.
		await admin.addStyleTag( { content: '.update-nag,.notice{display:none!important}' } ).catch( () => {} );

		// 1. Editor preview.
		const one = createOrder( 3, false, 'EO-HOODIE-S' );
		await admin.goto( `${ BASE }/wp-admin/admin.php?page=edit-orders-for-woocommerce-editor&order_id=${ one.id }` );
		await admin.addStyleTag( { content: '.update-nag,.notice:not(.inline){display:none!important}' } );
		await admin.fill( '.edit-orders-items input[type="number"]', '1' );
		await admin.click( '#edit-orders-preview' );
		await admin.waitForSelector( '.edit-orders-preview' );
		await shot( admin, admin.locator( '.wrap.edit-orders-editor' ), 'screenshot-1.png' );

		// 2. Order screen box with a balance order (quantity up, then back on the order).
		const two = createOrder( 1, false );
		await admin.goto( `${ BASE }/wp-admin/admin.php?page=edit-orders-for-woocommerce-editor&order_id=${ two.id }` );
		await admin.fill( '.edit-orders-items input[type="number"]', '3' );
		await admin.click( '#edit-orders-preview' );
		await admin.waitForSelector( '.edit-orders-preview' );
		await Promise.all( [ admin.waitForNavigation(), admin.click( '#edit-orders-apply' ) ] );
		await admin.addStyleTag( { content: '.update-nag,.notice:not(.inline){display:none!important}' } );
		// The box sits under WooCommerce's own side boxes: frame the page down to its bottom edge.
		await admin.evaluate( () => window.scrollTo( 0, 0 ) );
		const box = await admin.locator( '#edit-orders-for-woocommerce' ).boundingBox();
		const bottom = Math.ceil( box.y + box.height + 16 );
		const top = Math.max( 32, bottom - 900 );
		await admin.screenshot( { path: path.join( OUT, 'screenshot-2.png' ), fullPage: true, clip: { x: 160, y: top, width: 1120, height: bottom - top } } );
		console.log( '  saved screenshot-2.png' );

		// 3 and 4. Customer panel, then an address change preview.
		const customer = await ( await browser.newContext( { viewport: { width: 1100, height: 1000 } } ) ).newPage();
		const three = createOrder( 1, true, 'EO-HOODIE-S' );
		await customer.goto( three.received );
		await customer.locator( '#edit-order summary', { hasText: 'Change size or colour' } ).click();
		await shot( customer, customer.locator( '#edit-order' ), 'screenshot-3.png' );

		await customer.locator( '#edit-order summary', { hasText: 'Change the delivery address' } ).click();
		const form = customer.locator( '#edit-order .eofw-address' );
		await form.locator( '[name="shipping[address_1]"]' ).fill( '100 8 Ave SW' );
		await form.locator( '[name="shipping[city]"]' ).fill( 'Calgary' );
		await form.locator( '[name="shipping[postcode]"]' ).fill( 'T2P 1B3' );
		await form.locator( 'select[name="shipping[state]"]' ).selectOption( 'AB' );
		await Promise.all( [ customer.waitForNavigation(), form.locator( 'button[type="submit"]' ).click() ] );
		await shot( customer, customer.locator( '#edit-order .eofw-preview' ), 'screenshot-4.png' );

		// 5. A cancellation request (approval mode is the default), then the Edit Orders page.
		const five = createOrder( 1, true );
		await customer.goto( five.received );
		await customer.locator( '#edit-order summary', { hasText: 'Cancel this order' } ).click();
		await customer.locator( '#edit-order select[name="reason"]' ).selectOption( 'Delivery takes too long' );
		await Promise.all( [ customer.waitForNavigation(), customer.locator( '.eofw-cancel-button' ).click() ] );
		await admin.goto( `${ BASE }/wp-admin/admin.php?page=edit-orders-for-woocommerce` );
		await admin.addStyleTag( { content: '.update-nag,.notice{display:none!important}' } );
		await shot( admin, admin.locator( '.wrap.edit-orders-activity' ), 'screenshot-5.png' );

		// 6. Settings.
		await admin.goto( `${ BASE }/wp-admin/admin.php?page=wc-settings&tab=edit_orders` );
		await admin.addStyleTag( { content: '.update-nag,.notice{display:none!important}' } );
		await shot( admin, admin.locator( '#mainform' ), 'screenshot-6.png' );
	} finally {
		await browser.close();
		if ( created.length ) {
			cli( `wp eval-file ${ FIXTURE } clean ${ created.join( ' ' ) }` );
		}
	}
} )();
