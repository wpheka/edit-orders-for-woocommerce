/**
 * Edit Orders for WooCommerce: customer panel conveniences.
 *
 * The panel works without this file. It adds the countdown, the "Other" reason box
 * and a state list that follows the chosen country.
 *
 * @package Edit_Orders_For_WooCommerce
 */

( function () {
	'use strict';

	var cfg   = window.editOrdersForWooCommerceFront || {};
	var panel = document.getElementById( 'edit-order' );

	if ( ! panel ) {
		return;
	}

	// Countdown. When it ends, the forms are hidden; the server refuses late changes anyway.
	var clock = panel.querySelector( '[data-eofw-seconds]' );
	if ( clock ) {
		var left = parseInt( clock.getAttribute( 'data-eofw-seconds' ), 10 ) || 0;
		var pad  = function ( n ) {
			return ( n < 10 ? '0' : '' ) + n;
		};
		var tick = function () {
			if ( left <= 0 ) {
				var note         = document.createElement( 'p' );
				note.className   = 'eofw-window';
				note.textContent = cfg.closed || '';
				panel.querySelectorAll( '.eofw-window, .eofw-close, .eofw-action' ).forEach(
					function ( element ) {
						element.hidden = true;
					}
				);
				panel.appendChild( note );
				return;
			}
			var days          = Math.floor( left / 86400 );
			var hours         = Math.floor( ( left % 86400 ) / 3600 );
			var minutes       = Math.floor( ( left % 3600 ) / 60 );
			var seconds       = left % 60;
			clock.textContent = ( days ? days + 'd ' : '' ) + hours + ':' + pad( minutes ) + ':' + pad( seconds );
			left--;
			window.setTimeout( tick, 1000 );
		};
		tick();
	}

	// "Other" reason box only when "Other" is chosen.
	var reason = panel.querySelector( '.eofw-reason' );
	var other  = panel.querySelector( '.eofw-reason-other' );
	if ( reason && other ) {
		var syncOther = function () {
			other.hidden = '__other' !== reason.value;
		};
		reason.addEventListener( 'change', syncOther );
		syncOther();
	}

	// One submit per form: a double click must not send the same change twice.
	panel.querySelectorAll( 'form' ).forEach(
		function ( form ) {
			form.addEventListener(
				'submit',
				function ( event ) {
					if ( form.getAttribute( 'data-eofw-sent' ) ) {
						event.preventDefault();
						return;
					}
					form.setAttribute( 'data-eofw-sent', '1' );
				}
			);
		}
	);
	// Coming back with the browser's Back button restores the page as it was: allow submitting again.
	window.addEventListener(
		'pageshow',
		function () {
			panel.querySelectorAll( 'form[data-eofw-sent]' ).forEach(
				function ( form ) {
					form.removeAttribute( 'data-eofw-sent' );
				}
			);
		}
	);

	// State: a list when the country has states, otherwise free text. Scoped to the
	// address form: the confirm form repeats shipping[state] as a hidden field.
	var country = panel.querySelector( '.eofw-address .eofw-country' );
	if ( country ) {
		var addressForm = country.form;
		var syncState   = function () {
			var current = addressForm.querySelector( '[name="shipping[state]"]' );
			var states  = ( cfg.states && cfg.states[ country.value ] ) || null;
			var replacement;

			if ( states && Object.keys( states ).length ) {
				replacement   = document.createElement( 'select' );
				var addOption = function ( value, label ) {
					var option         = document.createElement( 'option' );
					option.value       = value;
					option.textContent = label;
					replacement.appendChild( option );
				};
				// A blank first choice, and a stored value that isn't in the list kept
				// as it is, so the form never changes the state by itself.
				addOption( '', '' );
				if ( current.value && ! states[ current.value ] ) {
					addOption( current.value, current.value );
				}
				Object.keys( states ).forEach(
					function ( code ) {
						addOption( code, states[ code ] );
					}
				);
				replacement.value = current.value || '';
			} else {
				replacement           = document.createElement( 'input' );
				replacement.type      = 'text';
				replacement.className = 'input-text';
				replacement.value     = states ? '' : current.value;
			}
			replacement.name = 'shipping[state]';
			replacement.id   = current.id;
			current.parentNode.replaceChild( replacement, current );
		};
		country.addEventListener( 'change', syncState );
		syncState();
	}
}() );
