/**
 * Laptop, queue 2026-10-14-c item 4: the hands-on checks a script in the suite does not cover,
 * on the wp-env development site (8890, PHP 8.2). Not part of the suite.
 *
 *   NODE_PATH=... node -r ./tests/e2e/lap-shim.cjs tests/e2e/lap-hands-on.cjs <shots-dir> [section ...]
 *
 * Sections (all by default): roles, colours, keyboard, leave, phone, second, rtl.
 * Users, the role, settings, the admin colour scheme, the second plugin and the site language
 * are put back at the end. Prints PASS/FAIL lines and exits non-zero on any FAIL.
 *
 * @package Edit_Orders_For_WooCommerce
 */

'use strict';

const { chromium } = require( 'playwright' );
const { execSync } = require( 'child_process' );
const crypto = require( 'crypto' );

const BASE = 'http://localhost:8890';
const PAGE = `${ BASE }/wp-admin/admin.php?page=edit-orders-for-woocommerce`;
const OPTION = 'edit_orders_for_woocommerce_settings';
const SHOTS = ( process.argv[ 2 ] || '.' ).replace( /\/?$/, '/' );
const ONLY = process.argv.slice( 3 );
const run = ( name ) => ! ONLY.length || ONLY.includes( name );
let failed = 0;

function wp( command ) {
	try {
		return execSync( `npx -y @wordpress/env@11 run cli wp ${ command }`, { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ] } ).trim().split( '\n' ).pop();
	} catch ( e ) {
		return 'ERROR ' + String( e.stderr || e.message ).split( '\n' ).filter( Boolean ).pop();
	}
}
function check( label, ok, detail = '' ) {
	if ( ! ok ) {
		failed++;
	}
	console.log( `${ ok ? 'PASS' : 'FAIL' }|${ label }${ detail ? ' -- ' + detail : '' }` );
}
async function login( browser, user, pass, viewport = { width: 1440, height: 900 } ) {
	const context = await browser.newContext( { viewport } );
	const page = await context.newPage();
	await page.goto( `${ BASE }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
	await page.fill( '#user_login', user );
	await page.fill( '#user_pass', pass );
	await Promise.all( [ page.waitForURL( /wp-admin/, { timeout: 60000 } ), page.click( '#wp-submit' ) ] );
	return page;
}
const tabs = ( page ) => page.locator( '.w-tab' ).allInnerTexts().then( ( t ) => t.map( ( s ) => s.trim() ) );

( async () => {
	const saved = wp( `option get ${ OPTION } --format=json` );
	const adminColor = wp( 'user meta get 1 admin_color' );
	const browser = await chromium.launch( { channel: 'chrome', headless: true } );
	const cleanup = [];
	try {
		// ------------------------------------------------------------------ roles
		if ( run( 'roles' ) ) {
			const pass = crypto.randomBytes( 10 ).toString( 'hex' );
			wp( `user create lap_shop lap_shop@example.invalid --role=shop_manager --user_pass=${ pass }` );
			cleanup.push( () => wp( 'user delete lap_shop --yes' ) );
			let page = await login( browser, 'lap_shop', pass );
			await page.goto( `${ PAGE }&tab=settings`, { waitUntil: 'networkidle' } );
			const menu = await page.locator( '#adminmenu li#toplevel_page_wpheka_plugin_panel' ).count();
			const sub = await page.locator( '#toplevel_page_wpheka_plugin_panel .wp-submenu a' ).allInnerTexts();
			check( 'shop manager: the WPHEKA menu shows with Edit Orders under it', 1 === menu && sub.some( ( s ) => /Edit Orders/.test( s ) ), JSON.stringify( sub ) );
			check( 'shop manager: the page has Settings, Activity and Help', JSON.stringify( await tabs( page ) ) === JSON.stringify( [ 'Settings', 'Activity', 'Help' ] ), JSON.stringify( await tabs( page ) ) );
			await page.fill( `input[name="${ OPTION }[window_minutes]"]`, '61' );
			await Promise.all( [ page.waitForNavigation(), page.click( '.w-savebar button[type="submit"]' ) ] );
			check( 'shop manager: a settings save works', '61' === wp( `option pluck ${ OPTION } window_minutes` ), wp( `option pluck ${ OPTION } window_minutes` ) );
			await page.context().close();

			// A custom role that can edit orders only.
			wp( 'role create lap_orders_only "Lap orders only"' );
			wp( 'cap add lap_orders_only read edit_shop_orders view_admin_dashboard' );
			cleanup.push( () => wp( 'role delete lap_orders_only' ) );
			const pass2 = crypto.randomBytes( 10 ).toString( 'hex' );
			wp( `user create lap_orders lap_orders@example.invalid --role=lap_orders_only --user_pass=${ pass2 }` );
			cleanup.unshift( () => wp( 'user delete lap_orders --yes' ) );
			page = await login( browser, 'lap_orders', pass2 );
			await page.goto( PAGE, { waitUntil: 'networkidle' } );
			const t = await tabs( page );
			check( 'edit_shop_orders only: sees Activity and Help, not Settings', t.includes( 'Activity' ) && t.includes( 'Help' ) && ! t.includes( 'Settings' ), JSON.stringify( t ) );
			await page.goto( `${ PAGE }&tab=settings`, { waitUntil: 'networkidle' } );
			check( 'edit_shop_orders only: asking for the Settings tab shows no settings form', 0 === ( await page.locator( 'form[data-w-form]' ).count() ), page.url() );
			// The real form fields, taken from an administrator's page, posted with this user's cookies.
			const admin = await login( browser, 'admin', 'password' );
			await admin.goto( `${ PAGE }&tab=settings`, { waitUntil: 'networkidle' } );
			const form = await admin.evaluate( () => {
				const f = document.querySelector( 'form[data-w-form]' );
				const data = {};
				new FormData( f ).forEach( ( v, k ) => {
					data[ k ] = v;
				} );
				// f.action would be the form's own <input name="action">: read the attribute.
				return { action: new URL( f.getAttribute( 'action' ) || location.href, location.href ).href, data };
			} );
			await admin.context().close();
			form.data[ `${ OPTION }[window_minutes]` ] = '999';
			const before = wp( `option pluck ${ OPTION } window_minutes` );
			console.log( 'INFO posting to', form.action, 'fields', Object.keys( form.data ).length );
			const res = await page.request.post( form.action, { form: form.data, maxRedirects: 0 } ).catch( ( e ) => ( { status: () => 'error ' + e.message } ) );
			const after = wp( `option pluck ${ OPTION } window_minutes` );
			check( 'edit_shop_orders only: a direct POST to the save action is refused', '999' !== after && before === after, `HTTP ${ res.status() }, window_minutes ${ before } -> ${ after }` );
			const res2 = await page.request.post( form.action, { form: Object.assign( {}, form.data, { _wpnonce: 'bogus' } ), maxRedirects: 0 } ).catch( ( e ) => ( { status: () => 'error ' + e.message } ) );
			check( 'edit_shop_orders only: the same POST without a valid nonce changes nothing', '999' !== wp( `option pluck ${ OPTION } window_minutes` ), `HTTP ${ res2.status() }` );
			await page.context().close();
		}

		// ---------------------------------------------------------------- colours
		if ( run( 'colours' ) ) {
			const page = await login( browser, 'admin', 'password' );
			for ( const scheme of [ 'fresh', 'light', 'modern', 'blue', 'midnight' ] ) {
				wp( `user meta update 1 admin_color ${ scheme }` );
				await page.goto( `${ PAGE }&tab=settings`, { waitUntil: 'networkidle' } );
				await page.waitForTimeout( 500 );
				const r = await page.evaluate( () => {
					const li = document.querySelector( '#toplevel_page_wpheka_plugin_panel' );
					const img = li && li.querySelector( '.wp-menu-image' );
					const bg = img ? getComputedStyle( img ).backgroundImage : '';
					const m = bg.match( /base64,([^")]+)/ );
					const svg = m ? atob( m[ 1 ] ) : decodeURIComponent( ( bg.match( /utf8,([^")]+)/ ) || [] )[ 1 ] || '' );
					const fills = Array.from( new Set( ( svg.match( /fill(?:=|:)\s*["']?(#[0-9a-fA-F]{3,6}|rgb\([^)]*\))/g ) || [] ).map( ( f ) => f.replace( /fill(?:=|:)\s*["']?/, '' ).toLowerCase() ) ) );
					const box = img ? img.getBoundingClientRect() : null;
					return { scheme: window._wpColorScheme && window._wpColorScheme.icons, fills, hasIcon: !! m || /svg/.test( svg ), box: box && { x: box.x, y: box.y, w: box.width, h: box.height }, menuBg: li ? getComputedStyle( li.querySelector( 'a.menu-top' ) ).backgroundColor : '' };
				} );
				const icons = r.scheme || {};
				const expected = [ icons.base, icons.focus, icons.current ].filter( Boolean ).map( ( c ) => c.toLowerCase() );
				const recoloured = r.fills.length > 0 && r.fills.every( ( f ) => expected.includes( f ) );
				check( `colour scheme ${ scheme }: menu icon present and recoloured to the scheme`, r.hasIcon && recoloured, `fills ${ JSON.stringify( r.fills ) }, scheme icons ${ JSON.stringify( icons ) }` );
				if ( r.box ) {
					await page.screenshot( { path: `${ SHOTS }scheme-${ scheme }-menu.png`, clip: { x: 0, y: Math.max( 0, r.box.y - 60 ), width: 220, height: 160 } } );
				}
			}
			await page.context().close();
		}

		// --------------------------------------------------------------- keyboard
		if ( run( 'keyboard' ) ) {
			const page = await login( browser, 'admin', 'password' );
			await page.goto( `${ PAGE }&tab=settings`, { waitUntil: 'networkidle' } );
			const targets = await page.evaluate( () => {
				const f = document.querySelector( 'form[data-w-form]' );
				const seen = new Set();
				const out = [];
				f.querySelectorAll( 'input, select, textarea, button, a[href]' ).forEach( ( el ) => {
					if ( 'hidden' === el.type || el.disabled || ! el.getClientRects().length && ! el.closest( '.w-switch, .w-chip, .w-option, .w-seg' ) ) {
						return;
					}
					if ( el.closest( '[hidden]' ) ) {
						return;
					}
					const key = 'radio' === el.type ? 'radio:' + el.name : ( el.name || el.textContent.trim().slice( 0, 30 ) || el.className ) + ':' + ( el.value || '' );
					if ( ! seen.has( key ) ) {
						seen.add( key );
						out.push( key );
					}
				} );
				return out;
			} );
			await page.locator( '.w-tab', { hasText: 'Settings' } ).focus();
			const reached = new Set();
			const blue = [];
			const noRing = [];
			for ( let i = 0; i < 160; i++ ) {
				await page.keyboard.press( 'Tab' );
				const info = await page.evaluate( () => {
					const el = document.activeElement;
					if ( ! el || ! el.closest( 'form[data-w-form]' ) ) {
						return { inForm: false };
					}
					const key = 'radio' === el.type ? 'radio:' + el.name : ( el.name || el.textContent.trim().slice( 0, 30 ) || el.className ) + ':' + ( el.value || '' );
					// The visible ring: the element itself, or the styled span after a hidden input.
					const shown = [ el, el.nextElementSibling, el.closest( 'label, .w-chip, .w-option, .w-seg label' ) ].filter( Boolean );
					const styles = shown.map( ( n ) => {
						const cs = getComputedStyle( n );
						return `${ cs.outlineStyle } ${ cs.outlineColor } | ${ cs.boxShadow }`;
					} );
					return { inForm: true, key, styles };
				} );
				if ( ! info.inForm ) {
					if ( reached.size ) {
						break;
					}
					continue;
				}
				reached.add( info.key );
				const all = info.styles.join( ' || ' );
				if ( /34, 113, 177|79, 148, 212|0, 115, 170/.test( all ) ) {
					blue.push( info.key );
				}
				// Violet: a colour with red and blue well above green, e.g. rgb(124, 58, 237).
				const violet = ( all.match( /rgba?\(\s*(\d+),\s*(\d+),\s*(\d+)/g ) || [] ).some( ( c ) => {
					const [ r, g, b ] = c.match( /\d+/g ).map( Number );
					return b > 150 && r > 80 && g < Math.min( r, b ) - 40;
				} );
				if ( ! violet ) {
					noRing.push( `${ info.key } [${ all.slice( 0, 140 ) }]` );
				}
			}
			const missed = targets.filter( ( t ) => ! reached.has( t ) );
			check( `keyboard: every control in the Settings form is reachable with Tab (${ targets.length } controls)`, 0 === missed.length, missed.join( ', ' ) );
			check( 'keyboard: a violet focus ring shows on every focused control', 0 === noRing.length, noRing.slice( 0, 6 ).join( ' ;; ' ) );
			check( 'keyboard: nothing shows the old blue WordPress focus outline', 0 === blue.length, blue.join( ', ' ) );
			await page.context().close();
		}

		// ------------------------------------------------------------------ leave
		if ( run( 'leave' ) ) {
			const page = await login( browser, 'admin', 'password' );
			await page.goto( `${ PAGE }&tab=settings`, { waitUntil: 'networkidle' } );
			await page.fill( `input[name="${ OPTION }[window_minutes]"]`, '77' );
			let asked = false;
			page.once( 'dialog', async ( d ) => {
				asked = 'beforeunload' === d.type();
				await d.dismiss();
			} );
			await page.click( '#menu-dashboard > a', { noWaitAfter: true } );
			await page.waitForTimeout( 1500 );
			check( 'leaving with unsaved changes: the browser asks to confirm', asked && /edit-orders-for-woocommerce/.test( page.url() ), page.url() );
			await page.click( '[data-w-discard]' );
			await page.waitForTimeout( 500 );
			let askedAgain = false;
			page.on( 'dialog', async ( d ) => {
				askedAgain = true;
				await d.accept();
			} );
			await Promise.all( [ page.waitForNavigation( { timeout: 30000 } ).catch( () => {} ), page.click( '#menu-dashboard > a' ) ] );
			check( 'after Discard: no question and the link opens', ! askedAgain && /\/wp-admin\/(index\.php)?$/.test( page.url() ), page.url() );
			await page.context().close();
		}

		// ------------------------------------------------------------------ phone
		if ( run( 'phone' ) ) {
			const page = await login( browser, 'admin', 'password', { width: 390, height: 844 } );
			for ( const tab of [ 'settings', 'activity', 'help' ] ) {
				await page.goto( `${ PAGE }&tab=${ tab }`, { waitUntil: 'networkidle' } );
				const r = await page.evaluate( () => ( { scroll: document.documentElement.scrollWidth, width: window.innerWidth, wide: Array.from( document.querySelectorAll( '#wpbody-content *' ) ).filter( ( e ) => e.getBoundingClientRect().right > window.innerWidth + 1 && getComputedStyle( e ).position !== 'fixed' && e.offsetParent ).slice( 0, 4 ).map( ( e ) => e.tagName.toLowerCase() + '.' + String( e.className ).split( ' ' )[ 0 ] ) } ) );
				check( `390px: no sideways scrolling on ${ tab }`, r.scroll <= r.width, `scrollWidth ${ r.scroll } of ${ r.width }; wider: ${ r.wide.join( ', ' ) }` );
				await page.screenshot( { path: `${ SHOTS }phone-390-${ tab }.png`, fullPage: true } );
			}
			await page.goto( `${ PAGE }&tab=settings`, { waitUntil: 'networkidle' } );
			const seg = await page.evaluate( ( opt ) => {
				const radio = document.querySelector( `input[name="${ opt }[cancel_mode]"]` );
				const group = radio && ( radio.closest( '.w-seg, [role="radiogroup"], .w-segmented' ) || radio.parentElement.parentElement );
				if ( ! group ) {
					return { found: false };
				}
				const labels = Array.from( group.querySelectorAll( 'label' ) ).map( ( l ) => l.getBoundingClientRect() );
				const g = group.getBoundingClientRect();
				return { found: true, right: Math.round( g.right ), width: window.innerWidth, tops: labels.map( ( l ) => Math.round( l.top ) ), rights: labels.map( ( l ) => Math.round( l.right ) ), cls: group.className };
			}, OPTION );
			check( '390px: the Cancellations choice fits the screen (wraps instead of overflowing)', seg.found && seg.right <= seg.width && seg.rights.every( ( r ) => r <= seg.width ), JSON.stringify( seg ) );
			await page.context().close();
		}

		// ------------------------------------------------------------ second plugin
		if ( run( 'second' ) ) {
			// LAP_SECOND: a released WPHEKA plugin's slug; LAP_SECOND_TEXT: its menu label (a regex).
			const second = process.env.LAP_SECOND || 'wc-backorder-split';
			const label = new RegExp( process.env.LAP_SECOND_TEXT || 'Backorder', 'i' );
			const was = wp( `plugin is-installed ${ second } && echo yes || echo no` );
			console.log( 'INFO', second, 'installed before:', was, wp( `plugin install ${ second } --activate` ), '| status:', wp( `plugin get ${ second } --field=status` ), wp( `plugin get ${ second } --field=version` ) );
			cleanup.push( () => wp( `plugin deactivate ${ second }` ) );
			const page = await login( browser, 'admin', 'password' );
			await page.goto( `${ PAGE }&tab=settings`, { waitUntil: 'networkidle' } );
			const menus = await page.evaluate( () => Array.from( document.querySelectorAll( '#adminmenu > li.menu-top' ) ).filter( ( li ) => /WPHEKA/i.test( li.querySelector( '.wp-menu-name' ) ? li.querySelector( '.wp-menu-name' ).textContent : '' ) ).map( ( li ) => ( { id: li.id, subs: Array.from( li.querySelectorAll( '.wp-submenu a' ) ).map( ( a ) => a.textContent.trim() ) } ) ) );
			check( `with ${ second } active: one WPHEKA menu with both plugins under it`, 1 === menus.length && menus[ 0 ].subs.some( ( s ) => /Edit Orders/.test( s ) ) && menus[ 0 ].subs.some( ( s ) => label.test( s ) ), JSON.stringify( menus ) );
			await page.hover( '#toplevel_page_wpheka_plugin_panel' );
			await page.screenshot( { path: `${ SHOTS }second-plugin-menu-${ second }.png`, clip: { x: 0, y: 0, width: 420, height: 900 } } );
			await page.context().close();
		}

		// -------------------------------------------------------------------- rtl
		if ( run( 'rtl' ) ) {
			console.log( 'INFO', wp( 'language core install ar' ), '|', wp( 'site switch-language ar' ) );
			cleanup.push( () => wp( 'site switch-language en_US' ) );
			const page = await login( browser, 'admin', 'password' );
			for ( const tab of [ 'settings', 'activity', 'help' ] ) {
				await page.goto( `${ PAGE }&tab=${ tab }`, { waitUntil: 'networkidle' } );
				const r = await page.evaluate( () => ( { dir: document.documentElement.dir || getComputedStyle( document.body ).direction, scroll: document.documentElement.scrollWidth, width: window.innerWidth } ) );
				check( `RTL (Arabic): ${ tab } tab is right-to-left with no sideways scrolling`, 'rtl' === r.dir && r.scroll <= r.width, JSON.stringify( r ) );
				await page.screenshot( { path: `${ SHOTS }rtl-ar-${ tab }.png`, fullPage: true } );
			}
			await page.context().close();
		}
	} finally {
		await browser.close();
		for ( const fn of cleanup.reverse() ) {
			fn();
		}
		if ( saved && ! saved.startsWith( 'ERROR' ) ) {
			wp( `option update ${ OPTION } '${ saved }' --format=json` );
		}
		if ( adminColor && ! adminColor.startsWith( 'ERROR' ) ) {
			wp( `user meta update 1 admin_color ${ adminColor }` );
		} else {
			wp( 'user meta delete 1 admin_color' );
		}
		console.log( 'RESTORED settings', wp( `option get ${ OPTION } --format=json` ) === saved ? 'equal to before' : 'DIFFER', '| admin_color', wp( 'user meta get 1 admin_color' ), '| site language', wp( 'option get WPLANG' ) || '(default)' );
	}
	process.exit( failed ? 1 : 0 );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
