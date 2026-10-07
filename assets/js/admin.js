/**
 * Edit Orders for WooCommerce: the editor page and the order screen box.
 *
 * Nothing is saved until the store owner previews the change and presses Apply;
 * the server builds the preview, so what is shown is what will be applied.
 *
 * @package Edit_Orders_For_WooCommerce
 */

( function ( $ ) {
	'use strict';

	var cfg = window.editOrdersForWooCommerce || {};

	/**
	 * POST to admin-ajax with the nonce.
	 *
	 * @param {string} action Action suffix.
	 * @param {Object|string} data Extra data, or an encoded query string.
	 * @return {jqXHR}
	 */
	function post( action, data ) {
		var base  = 'action=edit_orders_for_woocommerce_' + action + '&nonce=' + encodeURIComponent( cfg.nonce );
		var extra = typeof data === 'string' ? data : $.param( data || {} );
		return $.post( cfg.ajaxUrl, base + ( extra ? '&' + extra : '' ) );
	}

	function notice( type, message ) {
		return $( '<div class="notice inline"><p></p></div>' ).addClass( 'notice-' + type ).find( 'p' ).text( message ).end();
	}

	/**
	 * The server's message from a JSON error response (including 403 and 404), or the generic one.
	 *
	 * @param {jqXHR|Object} response Response or failed request.
	 * @return {string}
	 */
	function errorMessage( response ) {
		var body = response && response.responseJSON ? response.responseJSON : response;
		return ( body && body.data && body.data.message ) || cfg.i18n.error;
	}

	/* ------------------------------------------------------------------ */
	/* Editor page                                                          */
	/* ------------------------------------------------------------------ */

	var $form  = $( '#edit-orders-form' );
	var $panel = $( '#edit-orders-preview-panel' );

	if ( $form.length ) {
		var orderId = $form.data( 'order' );
		var added   = 0;

		// The form, with every field nested under form[...], as the server expects.
		var formData = function () {
			return $.map(
				$form.serializeArray(),
				function ( field ) {
					return encodeURIComponent( field.name.replace( /^([^\[]+)/, 'form[$1]' ) ) + '=' + encodeURIComponent( field.value );
				}
			).join( '&' );
		};

		var clearPreview = function () {
			$panel.empty();
		};

		// Tabs: items or address. Only the visible tab is sent.
		$form.on(
			'click',
			'.nav-tab',
			function ( event ) {
				event.preventDefault();
				var mode = $( this ).data( 'mode' );
				$form.find( '.nav-tab' ).removeClass( 'nav-tab-active' );
				$( this ).addClass( 'nav-tab-active' );
				$form.find( 'input[name="mode"]' ).val( mode );
				$form.find( '.edit-orders-tab' ).each(
					function () {
						var active  = $( this ).data( 'tab' ) === mode;
						this.hidden = ! active;
						$( this ).find( 'input, select' ).prop( 'disabled', ! active || $( this ).closest( 'tr' ).hasClass( 'is-locked' ) );
					}
				);
				clearPreview();
			}
		);
		$form.find( '.edit-orders-tab[data-tab="address"]' ).find( 'input, select' ).prop( 'disabled', true );

		$form.on( 'change input', 'input, select', clearPreview );

		// Add a product to the list of additions.
		$form.on(
			'click',
			'.edit-orders-add-button',
			function () {
				var $select   = $form.find( '.edit-orders-add-product' );
				var productId = $select.val();
				if ( ! productId ) {
					return;
				}
				var qty   = parseInt( $form.find( '.edit-orders-add-qty' ).val(), 10 ) || 1;
				var price = $.trim( $form.find( '.edit-orders-add-price' ).val() );
				var index = added++;
				var $li   = $( '<li />' ).text( qty + ' x ' + $select.find( 'option:selected' ).text() + ( price ? ' @ ' + price : '' ) + ' ' );

				$li.append( $( '<input type="hidden" />' ).attr( 'name', 'add[' + index + '][product_id]' ).val( productId ) );
				$li.append( $( '<input type="hidden" />' ).attr( 'name', 'add[' + index + '][qty]' ).val( qty ) );
				$li.append( $( '<input type="hidden" />' ).attr( 'name', 'add[' + index + '][price]' ).val( price ) );
				$li.append( $( '<button type="button" class="button-link edit-orders-remove-added" />' ).text( '×' ).attr( 'aria-label', 'Remove' ) );
				$form.find( '.edit-orders-added' ).append( $li );

				$select.val( null ).trigger( 'change' );
				$form.find( '.edit-orders-add-qty' ).val( 1 );
				$form.find( '.edit-orders-add-price' ).val( '' );
				clearPreview();
			}
		);

		$form.on(
			'click',
			'.edit-orders-remove-added',
			function () {
				$( this ).closest( 'li' ).remove();
				clearPreview();
			}
		);

		// State: a select when the country has states, otherwise free text.
		var syncState = function ( $fieldset ) {
			var country = $fieldset.find( '.edit-orders-country' ).val();
			var $state  = $fieldset.find( '[name$="[state]"]' );
			var states  = ( cfg.states && cfg.states[ country ] ) || null;
			var name    = $state.attr( 'name' );
			var value   = $state.val();
			var $replacement;

			if ( states && ! $.isEmptyObject( states ) ) {
				// A blank first choice, and the stored value kept as it is when it isn't
				// in the list (a blank optional state, or a name such as "California"),
				// so loading the page never changes the address by itself.
				$replacement = $( '<select />' ).attr( 'name', name ).append( $( '<option />' ).val( '' ) );
				if ( value && ! states[ value ] ) {
					$replacement.append( $( '<option />' ).val( value ).text( value ) );
				}
				$.each(
					states,
					function ( code, label ) {
						$replacement.append( $( '<option />' ).val( code ).text( label ) );
					}
				);
				$replacement.val( value || '' );
			} else {
				$replacement = $( '<input type="text" class="regular-text" />' ).attr( 'name', name ).val( states ? '' : value );
			}
			$replacement.prop( 'disabled', $state.prop( 'disabled' ) ).attr( 'data-original', $state.attr( 'data-original' ) );
			$state.replaceWith( $replacement );
		};

		$form.find( '.edit-orders-address' ).each(
			function () {
				syncState( $( this ) );
			}
		);
		$form.on(
			'change',
			'.edit-orders-country',
			function () {
				syncState( $( this ).closest( '.edit-orders-address' ) );
			}
		);

		// A shipping option chosen for one address may not exist at the next: choose again after previewing.
		$form.on(
			'change',
			'.edit-orders-address :input',
			function () {
				$form.find( '.edit-orders-rate-choice' ).empty().prop( 'hidden', true );
			}
		);

		// Preview.
		$form.on(
			'click',
			'#edit-orders-preview',
			function () {
				$panel.html( notice( 'info', cfg.i18n.working ) );
				post( 'preview', 'order_id=' + encodeURIComponent( orderId ) + '&' + formData() ).done(
					function ( response ) {
						if ( ! response || ! response.success ) {
								$panel.html( notice( 'error', errorMessage( response ) ) );
								return;
						}
						$panel.html( response.data.html );

						// The current shipping method isn't offered at the new address: choose another.
						// No rates back means the choice (if any) was accepted, so keep it for Apply.
						var $choice = $form.find( '.edit-orders-rate-choice' );
						if ( response.data.rates && ! $.isEmptyObject( response.data.rates ) ) {
							$choice.empty();
							$.each(
								response.data.rates,
								function ( id, label ) {
									$choice.append( $( '<label />' ).append( $( '<input type="radio" name="shipping_method" />' ).val( id ), ' ', document.createTextNode( label ) ), '<br />' );
								}
							);
							$choice.prop( 'hidden', false );
						}
					}
				).fail(
					function ( xhr ) {
						$panel.html( notice( 'error', errorMessage( xhr ) ) );
					}
				);
			}
		);

		// Apply.
		$panel.on(
			'click',
			'#edit-orders-apply',
			function () {
				var $button = $( this ).prop( 'disabled', true );
				var options = {
					order_id: orderId,
					send_pay_link: $panel.find( 'input[name="send_pay_link"]' ).is( ':checked' ) ? 1 : '',
					notify_customer: $panel.find( 'input[name="notify_customer"]' ).is( ':checked' ) ? 1 : '',
					// The server refuses the apply if the plan no longer matches this preview.
					expect: $panel.find( 'input[name="expect"]' ).val() || ''
				};
				post( 'apply', $.param( options ) + '&' + formData() ).done(
					function ( response ) {
						if ( response && response.success ) {
								window.location = response.data.redirect;
								return;
						}
						$button.prop( 'disabled', false );
						$panel.prepend( notice( 'error', errorMessage( response ) ) );
					}
				).fail(
					function ( xhr ) {
						$button.prop( 'disabled', false );
						$panel.prepend( notice( 'error', errorMessage( xhr ) ) );
					}
				);
			}
		);

		// Discard: release the lock and go back to the order.
		$form.on(
			'click',
			'#edit-orders-discard',
			function () {
				var back = $( this ).data( 'back' );
				post( 'release_lock', { order_id: orderId } ).always(
					function () {
						window.location = back;
					}
				);
			}
		);
	}

	/* ------------------------------------------------------------------ */
	/* Order screen box: balance orders                                     */
	/* ------------------------------------------------------------------ */

	$( document ).on(
		'click',
		'.edit-orders-resend, .edit-orders-cancel-balance',
		function () {
			var $button = $( this );
			var $li     = $button.closest( 'li' );
			var cancel  = $button.hasClass( 'edit-orders-cancel-balance' );

			if ( cancel && ! window.confirm( cfg.i18n.confirmCancel ) ) {
				return;
			}

			$button.prop( 'disabled', true );
			post(
				cancel ? 'cancel_balance' : 'resend_pay_link',
				{
					order_id: $li.closest( '.edit-orders-balances' ).data( 'order' ),
					balance_id: $li.data( 'balance' )
				}
			).done(
				function ( response ) {
					if ( cancel && response && response.success ) {
							window.location.reload();
							return;
					}
					$button.prop( 'disabled', false );
					$li.append( notice( response && response.success ? 'success' : 'error', response && response.success ? cfg.i18n.sent : errorMessage( response ) ) );
				}
			).fail(
				function ( xhr ) {
					$button.prop( 'disabled', false );
					$li.append( notice( 'error', errorMessage( xhr ) ) );
				}
			);
		}
	);
}( jQuery ) );
