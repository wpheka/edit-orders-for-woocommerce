/**
 * Laptop, upgrade check (queue 2026-10-14-c item 2): after updating 1.1.0 to 1.2.0 on the
 * wp-env tests site (8891), read what the new WPHEKA > Edit Orders page shows, save it once
 * without changes, and check the Activity tab, the old WooCommerce tab and the plugins list
 * link. Prints what it finds; the comparison with the database is done outside.
 *
 *   NODE_PATH=... node tests/e2e/lap-upgrade-120-check.cjs [shots-dir]
 *
 * @package Edit_Orders_For_WooCommerce
 */

'use strict';

const { chromium } = require( 'playwright' );

const BASE = process.env.LAP_BASE || 'http://localhost:8891';
const PAGE = `${ BASE }/wp-admin/admin.php?page=edit-orders-for-woocommerce`;
const SHOTS = process.argv[ 2 ] ? process.argv[ 2 ].replace( /\/?$/, '/' ) : '';

async function shot( page, name ) {
	if ( SHOTS ) {
		await page.screenshot( { path: SHOTS + name, fullPage: true } );
	}
}

( async () => {
	const browser = await chromium.launch( { channel: 'chrome', headless: true } );
	const page = await ( await browser.newContext( { viewport: { width: 1440, height: 1000 } } ) ).newPage();
	const errors = [];
	page.on( 'pageerror', ( e ) => errors.push( e.message ) );
	await page.goto( `${ BASE }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await Promise.all( [ page.waitForURL( /wp-admin/ ), page.click( '#wp-submit' ) ] );

	// 1. The Settings tab.
	await page.goto( `${ PAGE }&tab=settings`, { waitUntil: 'networkidle' } );
	await shot( page, 'upgrade-1-settings.png' );
	const shown = await page.evaluate( () => {
		const out = {};
		const form = document.querySelector( 'form[data-w-form]' );
		if ( ! form ) {
			return { error: 'no settings form' };
		}
		form.querySelectorAll( 'input, select, textarea' ).forEach( ( el ) => {
			if ( ! el.name || 'hidden' === el.type || 'submit' === el.type || /nonce|_wp_http_referer|^action$/.test( el.name ) ) {
				return;
			}
			if ( 'checkbox' === el.type ) {
				if ( el.name.endsWith( '[]' ) ) {
					out[ el.name ] = out[ el.name ] || [];
					if ( el.checked ) {
						out[ el.name ].push( el.value );
					}
				} else {
					out[ el.name ] = el.checked;
				}
			} else if ( 'radio' === el.type ) {
				if ( el.checked ) {
					out[ el.name ] = el.value;
				}
			} else {
				out[ el.name ] = el.value;
			}
		} );
		return out;
	} );
	console.log( 'SHOWN BY 1.2.0', JSON.stringify( shown, null, 1 ) );

	// 2. Save without changes, as the save shortcut would.
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.evaluate( () => document.querySelector( 'form[data-w-form]' ).requestSubmit() ) ] );
	const toast = ( await page.locator( '.w-toast, .w-notice' ).first().textContent().catch( () => '' ) ) || '';
	console.log( 'SAVED WITHOUT CHANGES, message:', toast.trim().slice( 0, 80 ), '| url:', page.url().replace( BASE, '' ) );

	// 3. Activity.
	await page.goto( `${ PAGE }&tab=activity`, { waitUntil: 'networkidle' } );
	await shot( page, 'upgrade-2-activity.png' );
	const log = await page.locator( '.edit-orders-log' ).first().innerText().catch( () => '(no .edit-orders-log)' );
	console.log( 'ACTIVITY LOG TEXT\n' + log.slice( 0, 1500 ) );
	const stats = await page.locator( '.w-stat, .w-stats, [class*="stat"]' ).allInnerTexts().catch( () => [] );
	console.log( 'ACTIVITY STATS', JSON.stringify( stats.map( ( s ) => s.replace( /\s+/g, ' ' ).trim() ).filter( Boolean ).slice( 0, 12 ) ) );

	// 4. The old WooCommerce tab.
	await page.goto( `${ BASE }/wp-admin/admin.php?page=wc-settings&tab=edit_orders`, { waitUntil: 'networkidle' } );
	await shot( page, 'upgrade-3-old-tab.png' );
	const old = await page.evaluate( () => {
		const main = document.querySelector( '#mainform' ) || document.querySelector( '#wpbody-content' );
		const fields = main ? main.querySelectorAll( '[name^="edit_orders_for_woocommerce"]' ).length : -1;
		const links = main ? Array.from( main.querySelectorAll( 'a' ) ).filter( ( a ) => /edit-orders-for-woocommerce/.test( a.href ) ).map( ( a ) => ( { text: a.textContent.trim(), href: a.href } ) ) : [];
		const save = main ? main.querySelectorAll( '.woocommerce-save-button' ).length : -1;
		const text = main ? main.innerText.replace( /\s+/g, ' ' ).trim() : '';
		return { fields, links, save, text: text.slice( text.indexOf( 'Edit Orders' ), text.indexOf( 'Edit Orders' ) + 400 ) };
	} );
	console.log( 'OLD TAB', JSON.stringify( old, null, 1 ) );
	if ( old.links.length ) {
		await page.goto( old.links[ 0 ].href, { waitUntil: 'networkidle' } );
		console.log( 'OLD TAB LINK OPENS', page.url().replace( BASE, '' ), '| settings form:', await page.locator( 'form[data-w-form]' ).count() );
	}

	// 5. The plugins list Settings link.
	await page.goto( `${ BASE }/wp-admin/plugins.php`, { waitUntil: 'networkidle' } );
	const row = page.locator( 'tr[data-plugin$="/wpheka-edit-orders-for-woocommerce.php"]:not(.inactive)' );
	const link = row.locator( '.row-actions a', { hasText: 'Settings' } );
	console.log( 'PLUGINS LIST settings links:', await link.count() );
	if ( await link.count() ) {
		const href = await link.first().getAttribute( 'href' );
		await link.first().click();
		await page.waitForLoadState( 'networkidle' );
		console.log( 'PLUGINS LIST LINK', href, '-> opens', page.url().replace( BASE, '' ), '| settings form:', await page.locator( 'form[data-w-form]' ).count() );
	}

	console.log( 'SCRIPT ERRORS', JSON.stringify( errors ) );
	await browser.close();
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
