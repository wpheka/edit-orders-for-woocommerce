/**
 * Laptop, upgrade check (queue 2026-10-14-c item 2): set every Edit Orders 1.1.0 setting to a
 * non-default value through WooCommerce > Settings > Edit Orders, the way a store owner would,
 * and turn one plugin email off in WooCommerce > Settings > Emails. Runs against the wp-env
 * tests site (8891) with 1.1.0 active. Not part of the suite.
 *
 *   NODE_PATH=... node tests/e2e/lap-upgrade-110-settings.cjs
 *
 * @package Edit_Orders_For_WooCommerce
 */

'use strict';

const { chromium } = require( 'playwright' );

const BASE = process.env.LAP_BASE || 'http://localhost:8891';
const TAB = `${ BASE }/wp-admin/admin.php?page=wc-settings&tab=edit_orders`;
const F = ( key ) => `[name="edit_orders_for_woocommerce_settings[${ key }]"]`;

async function save( page ) {
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( 'button.woocommerce-save-button, [name="save"]' ) ] );
	const notice = ( await page.locator( '#message, .updated, .notice-success' ).first().textContent().catch( () => '' ) ) || '';
	console.log( '  saved:', notice.trim().slice( 0, 60 ) );
}

async function setSelect( page, name, values ) {
	// The selects are select2 (wc-enhanced-select): set the real select and tell select2.
	await page.evaluate(
		( [ n, v ] ) => {
			const el = document.querySelector( `select[name="${ n }"]` );
			Array.from( el.options ).forEach( ( o ) => {
				o.selected = v.includes( o.value );
			} );
			el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			if ( window.jQuery ) {
				window.jQuery( el ).trigger( 'change' );
			}
		},
		[ name, values ]
	);
}

async function setCheck( page, key, on ) {
	const box = page.locator( F( key ) + '[type="checkbox"], [name="' + key + '"][type="checkbox"]' ).first();
	if ( on !== ( await box.isChecked() ) ) {
		await box.click();
	}
}

( async () => {
	const browser = await chromium.launch( { channel: 'chrome', headless: true } );
	const page = await ( await browser.newContext( { viewport: { width: 1400, height: 1000 } } ) ).newPage();
	await page.goto( `${ BASE }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await Promise.all( [ page.waitForURL( /wp-admin/ ), page.click( '#wp-submit' ) ] );

	console.log( 'customer changes off, save' );
	await page.goto( TAB, { waitUntil: 'load' } );
	await setCheck( page, 'customer_enabled', false );
	await save( page );

	console.log( 'customer changes on again, every other field to a non-default value, save' );
	await setCheck( page, 'customer_enabled', true );
	await setCheck( page, 'admin_enabled', false );
	await setSelect( page, 'edit_orders_for_woocommerce_settings[editable_statuses][]', [ 'on-hold' ] );
	await page.fill( F( 'window_minutes' ), '1440' );
	await setCheck( page, 'action_address', false );
	await setCheck( page, 'action_swap', false );
	await setSelect( page, 'edit_orders_for_woocommerce_settings[cancel_mode]', [ 'instant' ] );
	await page.fill( F( 'cancel_reasons' ), 'Changed my mind\nWrong size ordered\nFound it cheaper elsewhere' );
	await setCheck( page, 'cancel_reason_required', true );
	await page.fill( F( 'cancel_policy' ), 'Orders can be cancelled until they ship. Refunds go back to the original payment method.' );
	await page.fill( F( 'note_max_length' ), '300' );
	await setCheck( page, 'debug_log', true );
	await setCheck( page, 'edit_orders_for_woocommerce_delete_data', true );
	await save( page );

	console.log( 'turn the "Order updated" email off in WooCommerce > Settings > Emails' );
	await page.goto( `${ BASE }/wp-admin/admin.php?page=wc-settings&tab=email&section=edit_orders_for_woocommerce_email_order_updated`, { waitUntil: 'load' } );
	const enabled = page.locator( '#woocommerce_edit_orders_for_woocommerce_order_updated_enabled' );
	console.log( '  email enabled checkbox found:', await enabled.count() );
	if ( await enabled.isChecked() ) {
		await enabled.click();
	}
	await save( page );

	// What the 1.1.0 screen now shows, to compare with 1.2.0's page after the update.
	await page.goto( TAB, { waitUntil: 'load' } );
	const shown = await page.evaluate( () => {
		const out = {};
		document.querySelectorAll( '[name^="edit_orders_for_woocommerce_settings"], [name="edit_orders_for_woocommerce_delete_data"]' ).forEach( ( el ) => {
			if ( 'hidden' === el.type ) {
				return;
			}
			const key = el.name;
			if ( 'checkbox' === el.type ) {
				out[ key ] = el.checked;
			} else if ( el.multiple ) {
				out[ key ] = Array.from( el.selectedOptions ).map( ( o ) => o.value );
			} else {
				out[ key ] = el.value;
			}
		} );
		return out;
	} );
	console.log( 'SHOWN BY 1.1.0', JSON.stringify( shown, null, 1 ) );
	await browser.close();
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
