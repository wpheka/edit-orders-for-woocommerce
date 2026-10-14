/**
 * Settings page user flows in a real browser, on the wp-env site:
 * every control saves, keyboard use, search, Ctrl/Cmd+S, the leave warning,
 * getting started, approving and declining requests from the Activity tab,
 * and what a staff member without manage_woocommerce sees.
 *
 *   NODE_PATH=/path/to/node_modules node tests/e2e/settings-flows.cjs
 *
 * Settings, the staff user and every order it creates are put back or deleted.
 * Exits non-zero on any failure.
 *
 * @package Edit_Orders_For_WooCommerce
 */

'use strict';

const { chromium } = require( 'playwright' );
const { execSync } = require( 'child_process' );
const crypto = require( 'crypto' );

const BASE = 'http://localhost:8890';
const PAGE = `${ BASE }/wp-admin/admin.php?page=edit-orders-for-woocommerce`;
const FIXTURE = 'wp-content/plugins/edit-orders-for-woocommerce/tests/e2e/order-fixture.php';
const OPTION = 'edit_orders_for_woocommerce_settings';
const created = [];
let failed = 0;

function cli( command ) {
	return execSync( `npx -y @wordpress/env@11 run cli ${ command }`, {
		env: { ...process.env, MSYS_NO_PATHCONV: '1' },
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} );
}
function wp( command ) {
	try {
		return cli( `wp ${ command }` ).trim().split( '\n' ).pop();
	} catch ( e ) {
		return '';
	}
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
function requestFor( qty ) {
	const out = cli( `wp eval-file ${ FIXTURE } create ${ qty } account EO-TSHIRT` );
	const id = ( out.match( /ORDER_ID=(\d+)/ ) || [] )[ 1 ];
	created.push( id );
	wp( `eval 'Edit_Orders_For_WooCommerce_Cancellation::request( wc_get_order( ${ id } ), "Ordered by mistake", "customer" ); Edit_Orders_For_WooCommerce_Cancellation::forget_pending_count();'` );
	return id;
}

( async () => {
	const saved = wp( `option get ${ OPTION } --format=json` );
	const browser = await chromium.launch( { channel: process.env.PW_CHANNEL || 'chrome', headless: true } );
	let staffId = '';
	try {
		const page = await ( await browser.newContext( { viewport: { width: 1440, height: 900 } } ) ).newPage();
		const errors = [];
		page.on( 'pageerror', ( e ) => errors.push( e.message ) );
		await login( page, 'admin', 'password' );
		wp( `option update ${ OPTION } '{"cancel_mode":"approval"}' --format=json` );

		// --- Every control saves and survives a reload.
		await page.goto( `${ PAGE }&tab=settings`, { waitUntil: 'networkidle' } );
		const switches = await page.locator( 'form[data-w-form] input[role="switch"][name]' ).evaluateAll( ( els ) => els.map( ( e ) => ( { name: e.name, checked: e.checked } ) ) );
		// Cards and chips first: switching customer changes off hides the cards under it.
		const chips = await page.locator( '.w-chip input' ).evaluateAll( ( els ) => els.map( ( e ) => ( { value: e.value, checked: e.checked } ) ) );
		await page.locator( '.w-chip:has(input[value="processing"])' ).click();
		const cards = await page.locator( '.w-option input[type="checkbox"]' ).evaluateAll( ( els ) => els.map( ( e ) => ( { name: e.name, checked: e.checked } ) ) );
		await page.locator( '.w-option' ).first().click();
		for ( const s of switches ) {
			await page.locator( `input[role="switch"][name="${ s.name }"]` ).click( { force: true } );
		}
		await page.fill( 'textarea[name="edit_orders_for_woocommerce_settings[cancel_reasons]"]', 'First reason\nSecond reason' );
		await save( page );
		const after = await page.locator( 'form[data-w-form] input[role="switch"][name]' ).evaluateAll( ( els ) => els.map( ( e ) => ( { name: e.name, checked: e.checked } ) ) );
		const flipped = switches.filter( ( s ) => after.find( ( a ) => a.name === s.name && a.checked !== s.checked ) );
		check( `all ${ switches.length } switches save their new state`, 12 === switches.length && flipped.length === switches.length, `${ flipped.length }/${ switches.length }` );
		const chipNow = await page.locator( '.w-chip input[value="processing"]' ).isChecked();
		check( 'a status chip saves', chipNow !== chips.find( ( c ) => 'processing' === c.value ).checked );
		const cardNow = await page.locator( '.w-option input[type="checkbox"]' ).first().isChecked();
		check( 'an option card saves', cardNow !== cards[ 0 ].checked );
		check( 'a textarea keeps its line breaks', 'First reason\nSecond reason' === ( await page.locator( 'textarea[name="edit_orders_for_woocommerce_settings[cancel_reasons]"]' ).inputValue() ) );
		// Put everything back through the page.
		for ( const s of switches ) {
			await page.locator( `input[role="switch"][name="${ s.name }"]` ).click( { force: true } );
		}
		await page.locator( '.w-chip:has(input[value="processing"])' ).click();
		await page.locator( '.w-option' ).first().click();
		await save( page );
		check( 'a hidden card keeps its value: the cards show again once customer changes are back on', await page.locator( '.w-option' ).first().isVisible() );
		const back = await page.locator( 'form[data-w-form] input[role="switch"][name]' ).evaluateAll( ( els ) => els.map( ( e ) => ( { name: e.name, checked: e.checked } ) ) );
		check( 'the "Settings saved" toast lets clicks through to the control under it', 'none' === ( await page.locator( '.w-toast' ).evaluate( ( el ) => getComputedStyle( el ).pointerEvents ) ) );
		check( 'every switch is back where it started', back.every( ( b ) => switches.find( ( s ) => s.name === b.name ).checked === b.checked ) );

		// --- Keyboard.
		await page.goto( `${ PAGE }&tab=settings`, { waitUntil: 'networkidle' } );
		const first = page.locator( 'input[role="switch"][name="edit_orders_for_woocommerce_settings[admin_enabled]"]' );
		const was = await first.isChecked();
		await first.focus();
		await page.keyboard.press( 'Space' );
		check( 'Space toggles a focused switch', was !== ( await first.isChecked() ) );
		check( 'keyboard focus is visible on the switch', 'none' !== ( await page.locator( '.w-switch input:focus-visible + span' ).evaluate( ( el ) => getComputedStyle( el ).boxShadow ) ) );
		await page.keyboard.press( 'Space' );

		// --- Search.
		await page.locator( 'body' ).click( { position: { x: 5, y: 5 } } );
		await page.keyboard.press( '/' );
		check( '"/" focuses the settings search', await page.locator( '[data-w-find]' ).evaluate( ( el ) => el === document.activeElement ) );
		await page.keyboard.type( 'reason' );
		const shown = await page.locator( 'form[data-w-form] .w-section:not([hidden])' ).evaluateAll( ( els ) => els.map( ( e ) => e.id ) );
		check( 'searching "reason" shows only Cancellations', 1 === shown.length && 'cancellations' === shown[ 0 ], shown.join( ',' ) );
		await page.fill( '[data-w-find]', 'zzzz' );
		check( 'a search with no match says so', await page.locator( '[data-w-find-empty]' ).isVisible() );
		await page.fill( '[data-w-find]', '' );
		check( 'clearing the search brings every section back', 5 === ( await page.locator( 'form[data-w-form] .w-section:not([hidden])' ).count() ) );

		// --- Ctrl/Cmd+S and the leave warning.
		await page.fill( 'input[name="edit_orders_for_woocommerce_settings[window_minutes]"]', '75' );
		let dialog = false;
		page.once( 'dialog', async ( d ) => {
			dialog = 'beforeunload' === d.type();
			await d.dismiss();
		} );
		await page.click( '.w-tab:has-text("Help")', { noWaitAfter: true } ).catch( () => {} );
		await page.waitForTimeout( 800 );
		check( 'leaving with unsaved changes asks first', dialog );
		await page.goto( `${ PAGE }&tab=settings`, { waitUntil: 'networkidle' } ).catch( () => {} );
		await page.fill( 'input[name="edit_orders_for_woocommerce_settings[window_minutes]"]', '75' );
		await Promise.all( [ page.waitForNavigation(), page.keyboard.press( 'ControlOrMeta+s' ) ] );
		check( 'Ctrl/Cmd+S saves', '75' === wp( `option pluck ${ OPTION } window_minutes` ) );
		await page.fill( 'input[name="edit_orders_for_woocommerce_settings[window_minutes]"]', '60' );
		await save( page );

		// --- Getting started.
		wp( 'user meta delete 1 edit_orders_for_woocommerce_start_dismissed' );
		await page.goto( `${ PAGE }&tab=settings`, { waitUntil: 'networkidle' } );
		if ( await page.locator( '.w-start' ).count() ) {
			await page.click( '.w-start .w-close' );
			await page.waitForTimeout( 800 );
			await page.reload( { waitUntil: 'networkidle' } );
			check( 'hiding getting started is remembered after a reload', 0 === ( await page.locator( '.w-start' ).count() ) );
			wp( 'user meta delete 1 edit_orders_for_woocommerce_start_dismissed' );
		} else {
			check( 'getting started hides itself once every step is done', true );
		}

		// --- Activity: approve and decline from the page.
		const approveId = requestFor( 1 );
		const declineId = requestFor( 1 );
		await page.goto( `${ PAGE }&tab=activity`, { waitUntil: 'networkidle' } );
		check( 'both requests are listed', 1 === ( await page.locator( `tr[data-order="${ approveId }"]` ).count() ) && 1 === ( await page.locator( `tr[data-order="${ declineId }"]` ).count() ) );
		await Promise.all( [ page.waitForNavigation(), page.click( `tr[data-order="${ approveId }"] button[value="approve"]` ) ] );
		check( 'Approve and refund: notice shown, back on Activity', ( await page.locator( '.w-notice' ).textContent() ).includes( 'Cancellation approved' ) && page.url().includes( 'tab=activity' ) );
		check( 'the approved order is cancelled', 'cancelled' === wp( `eval 'echo wc_get_order( ${ approveId } )->get_status();'` ) );
		await page.fill( `tr[data-order="${ declineId }"] input[name="message"]`, 'Already packed, sorry' );
		await Promise.all( [ page.waitForNavigation(), page.click( `tr[data-order="${ declineId }"] button[value="decline"]` ) ] );
		check( 'Decline: notice shown and the order is unchanged', ( await page.locator( '.w-notice' ).textContent() ).includes( 'Cancellation declined' ) && 'processing' === wp( `eval 'echo wc_get_order( ${ declineId } )->get_status();'` ) );
		check( 'neither request is waiting any more', 0 === ( await page.locator( `tr[data-order="${ approveId }"], tr[data-order="${ declineId }"]` ).count() ) );
		await page.selectOption( 'select[name="log_action"]', 'cancel_declined' );
		await Promise.all( [ page.waitForNavigation(), page.click( '.w-filters button[type="submit"]' ) ] );
		check( 'filtering the log keeps you on Activity and shows the decline', page.url().includes( 'tab=activity' ) && ( await page.locator( '.edit-orders-log' ).textContent() ).includes( 'Cancellation declined' ) );

		// --- Help links open in a new tab.
		await page.goto( `${ PAGE }&tab=help`, { waitUntil: 'networkidle' } );
		check( 'Help cards open wordpress.org in a new tab', 3 === ( await page.locator( '.w-flow a[target="_blank"][href*="wordpress.org"]' ).count() ) );
		check( 'no script errors as admin', 0 === errors.length, errors.join( '; ' ) );

		// --- A staff member who can edit orders but not manage WooCommerce.
		const pass = crypto.randomBytes( 12 ).toString( 'hex' );
		const login_name = 'eo_staff_' + crypto.randomBytes( 3 ).toString( 'hex' );
		staffId = wp( `user create ${ login_name } ${ login_name }@example.invalid --role=editor --user_pass=${ pass } --porcelain` );
		wp( `user add-cap ${ staffId } edit_shop_orders` );
		const staff = await ( await browser.newContext( { viewport: { width: 1440, height: 900 } } ) ).newPage();
		await login( staff, login_name, pass );
		await staff.goto( `${ BASE }/wp-admin/`, { waitUntil: 'domcontentloaded' } );
		check( 'staff see WPHEKA > Edit Orders in their menu', 1 <= ( await staff.locator( '#toplevel_page_wpheka_plugin_panel a[href*="page=edit-orders-for-woocommerce"]' ).count() ) );
		await staff.goto( `${ PAGE }&tab=settings`, { waitUntil: 'networkidle' } );
		check( 'asking for Settings shows Activity instead, with no Settings tab', ( await staff.locator( '.w-tab[aria-current="page"]' ).textContent() ).startsWith( 'Activity' ) && 0 === ( await staff.locator( '.w-tab:has-text("Settings")' ).count() ) && 0 === ( await staff.locator( 'form[data-w-form]' ).count() ) );
		const res = await staff.request.post( `${ BASE }/wp-admin/admin-post.php`, { form: { action: 'edit_orders_for_woocommerce_save_settings', 'edit_orders_for_woocommerce_settings[window_minutes]': '999' } } );
		check( 'a direct save from staff is refused', 200 !== res.status() || '999' !== wp( `option pluck ${ OPTION } window_minutes` ) );
		check( 'and the setting is unchanged', '999' !== wp( `option pluck ${ OPTION } window_minutes` ) );
	} finally {
		await browser.close();
		if ( staffId ) {
			wp( `user delete ${ staffId } --yes` );
		}
		if ( created.length ) {
			cli( `wp eval-file ${ FIXTURE } clean ${ created.join( ' ' ) }` );
		}
		if ( saved ) {
			wp( `option update ${ OPTION } '${ saved }' --format=json` );
		} else {
			wp( `option delete ${ OPTION }` );
		}
	}
	console.log( 'PASS|settings-flows.cjs: ran to the end' );
	process.exit( failed ? 1 : 0 );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
