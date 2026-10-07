/**
 * Browser test of customer self-service (M2), against the wp-env store.
 *
 *   NODE_PATH=/path/to/node_modules node tests/e2e/customer.cjs [screenshot-dir]
 *
 * 1. Guest, classic theme (Storefront): the panel on the thank-you page; ask to cancel.
 * 2. A forged request with a wrong order key gets a 404.
 * 3. Logged-in customer, My Account: "Change or cancel" in the orders list; an address
 *    change is previewed, then confirming goes to the pay page for the difference.
 * 4. Block theme (Twenty Twenty-Five): the panel on the order confirmation page (case 27).
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

function cli( command ) {
	return execSync( `npx -y @wordpress/env@11 run cli ${ command }`, {
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
	if ( ! order.id ) {
		throw new Error( 'Could not create the test order: ' + out );
	}
	created.push( order.id );
	return order;
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
	const errors = [];
	const watch = ( page ) => {
		page.on( 'pageerror', ( error ) => errors.push( error.message ) );
		page.on( 'console', ( message ) => {
			const source = ( message.location() && message.location().url ) || '';
			if ( 'error' === message.type() && /edit-orders-for-woocommerce/.test( source ) ) {
				errors.push( message.text() );
			}
		} );
	};

	try {
		// ----------------------------------------------------------------
		console.log( 'Scenario 1: guest on the thank-you page (Storefront)' );
		const guestContext = await browser.newContext( { viewport: { width: 1280, height: 1000 } } );
		const guest = await guestContext.newPage();
		watch( guest );
		const guestOrder = createOrder( 1, true );
		await guest.goto( guestOrder.received );
		const panel = guest.locator( '#edit-order' );
		check( 'panel shown on the thank-you page', await panel.count() === 1 );
		check( 'countdown shown', /\d+:\d\d:\d\d/.test( await panel.locator( '[data-eofw-seconds]' ).textContent() ) );
		await panel.screenshot( { path: path.join( SHOTS, 'c1-guest-panel.png' ) } );

		await panel.locator( 'summary', { hasText: 'Cancel this order' } ).click();
		await panel.locator( 'select[name="reason"]' ).selectOption( 'Ordered by mistake' );
		await Promise.all( [ guest.waitForNavigation(), panel.locator( '.eofw-cancel-button' ).click() ] );
		const after = await guest.locator( '#edit-order' ).textContent();
		check( 'request sent message after the post-redirect', /cancellation request has been sent/.test( after ), after.trim().slice( 0, 160 ) );
		check( 'panel now says the request is waiting', /waiting for the store/.test( after ) );
		await guest.locator( '#edit-order' ).screenshot( { path: path.join( SHOTS, 'c2-guest-requested.png' ) } );

		// ----------------------------------------------------------------
		console.log( 'Scenario 2: a forged request with a wrong key' );
		const forgedOrder = createOrder( 1, true );
		await guest.goto( forgedOrder.received );
		const nonce = await guest.locator( '#edit-order input[name="_eofw_nonce"]' ).first().inputValue();
		const forged = await guest.request.post( forgedOrder.received, {
			form: {
				_eofw_nonce: nonce,
				order_id: forgedOrder.id,
				order_key: 'wc_order_notthekey',
				edit_orders_for_woocommerce_action: 'note',
				customer_note: 'forged',
			},
			maxRedirects: 0,
		} );
		check( 'wrong key: 404', 404 === forged.status(), String( forged.status() ) );
		await guestContext.close();

		// ----------------------------------------------------------------
		console.log( 'Scenario 3: logged-in customer in My Account' );
		const context = await browser.newContext( { viewport: { width: 1280, height: 1000 } } );
		const page = await context.newPage();
		watch( page );
		const mine = createOrder( 1, false );
		await page.goto( `${ BASE }/my-account/` );
		await page.fill( '#username', 'customer' );
		await page.fill( '#password', 'password' );
		await Promise.all( [ page.waitForNavigation(), page.click( 'button[name="login"]' ) ] );
		await page.goto( `${ BASE }/my-account/orders/` );
		check( 'orders list offers "Change or cancel"', await page.locator( 'a.edit_order', { hasText: 'Change or cancel' } ).count() >= 1 );

		await page.goto( mine.view );
		const myPanel = page.locator( '#edit-order' );
		check( 'panel shown on the order page', await myPanel.count() === 1 );
		await myPanel.locator( 'summary', { hasText: 'Change the delivery address' } ).click();
		await myPanel.locator( '[name="shipping[address_1]"]' ).fill( '100 8 Ave SW' );
		await myPanel.locator( '[name="shipping[city]"]' ).fill( 'Calgary' );
		await myPanel.locator( '[name="shipping[postcode]"]' ).fill( 'T2P 1B3' );
		await myPanel.locator( 'select[name="shipping[state]"]' ).selectOption( 'AB' );
		await Promise.all( [ page.waitForNavigation(), myPanel.locator( '.eofw-address button[type="submit"]' ).click() ] );
		const preview = await page.locator( '.eofw-preview' ).textContent();
		check( 'preview: $12.00 more, $3.90 refunded once paid', /12\.00/.test( preview ) && /3\.90/.test( preview ), preview.replace( /\s+/g, ' ' ).trim() );
		check( 'the confirm box shows no form fields (the previewed values ride along hidden)', await page.locator( '.eofw-confirm select, .eofw-confirm textarea, .eofw-confirm input:not([type="hidden"])' ).count() === 0 );
		await page.locator( '#edit-order' ).screenshot( { path: path.join( SHOTS, 'c3-address-preview.png' ) } );
		await Promise.all( [ page.waitForNavigation(), page.locator( '.eofw-confirm button[type="submit"]' ).click() ] );
		check( 'confirm goes to the pay page for the difference', /order-pay/.test( page.url() ), page.url() );
		await page.screenshot( { path: path.join( SHOTS, 'c4-pay-page.png' ) } );
		await context.close();

		// ----------------------------------------------------------------
		console.log( 'Scenario 4: block theme order confirmation (case 27)' );
		cli( 'wp theme activate twentytwentyfive' );
		const blockContext = await browser.newContext( { viewport: { width: 1280, height: 1000 } } );
		const block = await blockContext.newPage();
		watch( block );
		const blockOrder = createOrder( 1, true, 'EO-HOODIE-S' );
		await block.goto( blockOrder.received );
		const blockPanel = block.locator( '#edit-order' );
		check( 'panel shown on the block order confirmation page', await blockPanel.count() === 1 );
		check( 'with the size swap for the hoodie', await blockPanel.locator( 'select[name^="swap["]' ).count() === 1 );
		await block.screenshot( { path: path.join( SHOTS, 'c5-block-theme.png' ), fullPage: true } );
		await blockContext.close();

		check( 'no JavaScript errors from this plugin', errors.length === 0, errors.join( ' | ' ) );
	} catch ( error ) {
		failures++;
		console.log( `  FAIL  ${ error.message }` );
	} finally {
		try {
			cli( 'wp theme activate storefront' );
		} catch ( e ) {
			console.log( '  NOTE  could not switch back to Storefront' );
		}
		await browser.close();
		if ( created.length ) {
			cli( `wp eval-file ${ FIXTURE } clean ${ created.join( ' ' ) }` );
		}
	}

	console.log( failures ? `${ failures } failed` : 'Customer browser test passed.' );
	process.exit( failures ? 1 : 0 );
} )();
