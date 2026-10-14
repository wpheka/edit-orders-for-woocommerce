/**
 * The settings page (WPHEKA > Edit Orders) in a real browser, on the wp-env site.
 *
 *   NODE_PATH=/path/to/node_modules node tests/e2e/settings-page.cjs
 *
 * Checks the save bar, Discard, dependent rows, a save round trip, the old
 * WooCommerce tab's pointer, the tabs and that no width scrolls sideways. Settings
 * are put back as they were. Exits non-zero on any failure.
 *
 * @package Edit_Orders_For_WooCommerce
 */

'use strict';

const { chromium } = require( 'playwright' );
const { execSync } = require( 'child_process' );

const BASE = 'http://localhost:8890';
const PAGE = `${ BASE }/wp-admin/admin.php?page=edit-orders-for-woocommerce`;
const WINDOW = 'input[name="edit_orders_for_woocommerce_settings[window_minutes]"]';
let failed = 0;

function wp( command ) {
	try {
		return run( command );
	} catch ( e ) {
		return ''; // For example an option a fresh store has not saved yet.
	}
}

function run( command ) {
	return execSync( `npx -y @wordpress/env@11 run cli wp ${ command }`, {
		env: { ...process.env, MSYS_NO_PATHCONV: '1' },
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} )
		.trim()
		.split( '\n' )
		.pop();
}

function check( label, ok, detail = '' ) {
	if ( ! ok ) {
		failed++;
	}
	console.log( `${ ok ? 'PASS' : 'FAIL' }|${ label }${ ok || ! detail ? '' : ' -- ' + detail }` );
}

async function login( page, user, pass ) {
	// Poll the address rather than wait on a navigation event: the redirect after
	// wp-login.php can finish before a navigation listener attaches.
	for ( let attempt = 0; attempt < 3; attempt++ ) {
		await page.goto( `${ BASE }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
		await page.fill( '#user_login', user );
		await page.fill( '#user_pass', pass );
		await page.click( '#wp-submit', { noWaitAfter: true } );
		for ( let i = 0; i < 60; i++ ) {
			if ( /\/wp-admin\//.test( page.url() ) ) {
				await page.waitForLoadState( 'domcontentloaded' );
				return;
			}
			await page.waitForTimeout( 500 );
		}
	}
	throw new Error( `could not log in as ${ user }` );
}

async function save( page ) {
	await Promise.all( [ page.waitForNavigation(), page.click( '.w-savebar button[type="submit"]' ) ] );
	// Chrome restores the scroll position just after the page loads; let it finish so a
	// following click lands where it was aimed.
	await page.waitForLoadState( 'load' );
	await page.waitForTimeout( 600 );
}

( async () => {
	const browser = await chromium.launch( { channel: process.env.PW_CHANNEL || 'chrome', headless: true } );
	const errors = [];
	try {
		const page = await ( await browser.newContext( { viewport: { width: 1440, height: 900 } } ) ).newPage();
		page.on( 'pageerror', ( e ) => errors.push( e.message ) );
		await login( page, 'admin', 'password' );

		await page.goto( `${ PAGE }&tab=settings`, { waitUntil: 'networkidle' } );
		const before = await page.locator( WINDOW ).inputValue();
		const mode = wp( 'option pluck edit_orders_for_woocommerce_settings cancel_mode' ) || 'approval';

		check( 'save bar hidden before a change', ! ( await page.locator( '.w-savebar.is-shown' ).count() ) );
		await page.locator( WINDOW ).fill( '1440' );
		check( 'the minutes hint reads "= 1 day"', '= 1 day' === ( await page.locator( '.w-unit-hint' ).first().textContent() ) );
		check( 'save bar shows after a change', 1 === ( await page.locator( '.w-savebar.is-shown' ).count() ) );
		await page.click( '.w-savebar [data-w-discard]' );
		await page.waitForTimeout( 100 );
		check( 'Discard restores the value and hides the bar', before === ( await page.locator( WINDOW ).inputValue() ) && ! ( await page.locator( '.w-savebar.is-shown' ).count() ) );

		await page.locator( 'input[name="edit_orders_for_woocommerce_settings[customer_enabled]"][type="checkbox"]' ).click( { force: true } );
		check( 'customer rows hide while customer changes are off', 1 === ( await page.locator( '#edit-orders-customer-rows.is-off' ).count() ) );
		await page.click( '.w-savebar [data-w-discard]' );
		await page.waitForTimeout( 100 );
		check( 'Discard brings them back', 0 === ( await page.locator( '#edit-orders-customer-rows.is-off' ).count() ) );

		await page.locator( WINDOW ).fill( '90' );
		await page.click( '.w-seg label:has-text("Cancel and refund straight away")' );
		await save( page );
		check( 'the toast says Settings saved', ( await page.locator( '.w-toast.is-shown' ).textContent() ).includes( 'Settings saved' ) );
		check( 'the saved value shows after the reload', '90' === ( await page.locator( WINDOW ).inputValue() ) );
		check( 'stored in the settings option', '90' === wp( 'option pluck edit_orders_for_woocommerce_settings window_minutes' ) && 'instant' === wp( 'option pluck edit_orders_for_woocommerce_settings cancel_mode' ) );
		check( 'the saved flag leaves the address bar', ! page.url().includes( 'w-saved' ) );

		await page.locator( WINDOW ).fill( before );
		await page.click( `.w-seg label:has(input[value="${ mode }"])` );
		await save( page );
		check( 'settings put back', before === wp( 'option pluck edit_orders_for_woocommerce_settings window_minutes' ) && mode === wp( 'option pluck edit_orders_for_woocommerce_settings cancel_mode' ) );

		await page.goto( `${ BASE }/wp-admin/admin.php?page=wc-settings&tab=edit_orders`, { waitUntil: 'networkidle' } );
		check( 'the old WooCommerce tab points to the new page', 1 === ( await page.locator( '.edit-orders-moved' ).count() ) );
		check( 'the old WooCommerce tab has no Save button', 0 === ( await page.locator( '#mainform button[name="save"]' ).count() ) );
		await Promise.all( [ page.waitForNavigation(), page.click( '.edit-orders-moved a' ) ] );
		check( 'its button opens Settings', page.url().includes( 'page=edit-orders-for-woocommerce' ) && page.url().includes( 'tab=settings' ) );

		// Keyboard focus on an option card shows the violet ring (missing in 1.2.0, found on the laptop).
		await page.keyboard.press( 'Shift' );
		await page.locator( '.w-option input[type="checkbox"]' ).first().focus();
		await page.waitForTimeout( 350 );
		const ring = await page.locator( '.w-option' ).first().evaluate( ( e ) => getComputedStyle( e ).boxShadow );
		check( 'an option card shows the focus ring from the keyboard', /124, 58, 237/.test( ring ), ring );

		for ( const tab of [ 'activity', 'help' ] ) {
			await page.goto( `${ PAGE }&tab=${ tab }`, { waitUntil: 'networkidle' } );
			check( `the ${ tab } tab is marked current`, 1 === ( await page.locator( '.w-tab[aria-current="page"]' ).count() ) && ( await page.locator( '.w-tab[aria-current="page"]' ).textContent() ).toLowerCase().startsWith( tab ) );
		}
		check( 'More from WPHEKA lists the other six plugins', 6 === ( await page.locator( '.w-plugin' ).count() ) );
		check( 'the page sits under the WPHEKA menu', 1 === ( await page.locator( '#toplevel_page_wpheka_plugin_panel .wp-submenu a[href*="page=edit-orders-for-woocommerce"]' ).count() ) );
		check( 'and no longer under WooCommerce', 0 === ( await page.locator( '#toplevel_page_woocommerce a[href*="page=edit-orders-for-woocommerce"]' ).count() ) );
		check( 'the WPHEKA menu has no duplicate "WPHEKA" item', 0 === ( await page.locator( '#toplevel_page_wpheka_plugin_panel .wp-submenu a[href$="page=wpheka_plugin_panel"]' ).count() ) );

		for ( const width of [ 1440, 1024, 782, 390 ] ) {
			await page.setViewportSize( { width, height: 900 } );
			await page.goto( `${ PAGE }&tab=settings`, { waitUntil: 'networkidle' } );
			const sw = await page.evaluate( () => document.documentElement.scrollWidth );
			check( `no sideways scroll at ${ width }px`, sw <= width, `${ sw }px` );
		}
		check( 'no script errors', 0 === errors.length, errors.join( '; ' ) );
	} finally {
		await browser.close();
	}
	console.log( 'PASS|settings-page.cjs: ran to the end' );
	process.exit( failed ? 1 : 0 );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
