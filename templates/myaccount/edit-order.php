<?php
/**
 * Customer panel: change or cancel an order (thank-you page and My Account order page).
 *
 * Override by copying to yourtheme/edit-orders-for-woocommerce/myaccount/edit-order.php.
 * Works without JavaScript; assets/js/frontend.js adds the countdown and conveniences.
 *
 * @package Edit_Orders_For_WooCommerce
 * @var WC_Order   $order
 * @var string     $order_key       Order key for guests, empty for account holders.
 * @var string[]   $actions         Actions available now: cancel, address, swap, note.
 * @var int        $seconds_left    Seconds left in the window.
 * @var bool       $cancel_pending  A cancellation request is waiting for the store.
 * @var string     $cancel_mode     instant or approval.
 * @var string     $refund_method   How a cancel refunds: gateway, on_delivery or store.
 * @var string[]   $reasons         Cancel reasons.
 * @var bool       $reason_required A reason must be given.
 * @var string     $policy          Policy note shown with cancellation.
 * @var int        $note_max        Order note length limit.
 * @var array[]    $swap_options    Item ID => { name, current, options }.
 * @var array|null $state           Error or preview from a request on this page; an address
 *                                  error may carry rates (rate ID => label) and fields to re-post.
 * @var string     $message         Success message after a change.
 */

defined( 'ABSPATH' ) || exit;

$edit_orders_for_woocommerce_currency = array( 'currency' => $order->get_currency() );
?>
<section class="eofw-panel" id="edit-order">
	<h2><?php esc_html_e( 'Need to change something?', 'wpheka-edit-orders-for-woocommerce' ); ?></h2>

	<?php if ( '' !== $message ) : ?>
		<div class="woocommerce-message" role="status"><?php echo esc_html( $message ); ?></div>
	<?php endif; ?>

	<?php if ( $state && 'error' === $state['type'] ) : ?>
		<div class="woocommerce-error" role="alert"><?php echo esc_html( $state['message'] ); ?></div>
		<?php if ( ! empty( $state['rates'] ) ) : ?>
			<form method="post" class="eofw-rates">
				<?php echo wp_kses( Edit_Orders_For_WooCommerce_Frontend::form_fields( $order, $order_key, 'address' ), Edit_Orders_For_WooCommerce_Frontend::FORM_HTML ); ?>
				<?php echo wp_kses( Edit_Orders_For_WooCommerce_Frontend::hidden_fields( $state['fields'] ), Edit_Orders_For_WooCommerce_Frontend::FORM_HTML ); ?>
				<fieldset>
					<legend><?php esc_html_e( 'Shipping to the new address', 'wpheka-edit-orders-for-woocommerce' ); ?></legend>
					<?php $edit_orders_for_woocommerce_first = true; ?>
					<?php foreach ( $state['rates'] as $edit_orders_for_woocommerce_rate_id => $edit_orders_for_woocommerce_rate_label ) : ?>
						<label><input type="radio" name="shipping_method" value="<?php echo esc_attr( $edit_orders_for_woocommerce_rate_id ); ?>" <?php checked( $edit_orders_for_woocommerce_first ); ?> /> <?php echo esc_html( $edit_orders_for_woocommerce_rate_label ); ?></label><br />
						<?php $edit_orders_for_woocommerce_first = false; ?>
					<?php endforeach; ?>
				</fieldset>
				<button type="submit" class="button wp-element-button"><?php esc_html_e( 'Review the change', 'wpheka-edit-orders-for-woocommerce' ); ?></button>
			</form>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( $cancel_pending ) : ?>
		<div class="woocommerce-info"><?php esc_html_e( 'Your cancellation request is waiting for the store. We will email you their answer.', 'wpheka-edit-orders-for-woocommerce' ); ?></div>
	<?php endif; ?>

	<?php if ( $state && 'preview' === $state['type'] ) : ?>
		<?php
		$edit_orders_for_woocommerce_preview     = $state['preview'];
		$edit_orders_for_woocommerce_on_delivery = ! empty( $edit_orders_for_woocommerce_preview['on_delivery'] );
		// Paid on delivery: what changes is the amount to pay at the door, whatever mix of lines went up or down.
		$edit_orders_for_woocommerce_door = round( (float) $edit_orders_for_woocommerce_preview['balance'] - (float) $edit_orders_for_woocommerce_preview['refund'], wc_get_price_decimals() );
		$edit_orders_for_woocommerce_pays = $edit_orders_for_woocommerce_on_delivery ? 0 : $edit_orders_for_woocommerce_preview['balance'];
		?>
		<div class="eofw-preview">
			<h3><?php esc_html_e( 'Please confirm your change', 'wpheka-edit-orders-for-woocommerce' ); ?></h3>
			<ul>
				<?php foreach ( $edit_orders_for_woocommerce_preview['changes'] as $edit_orders_for_woocommerce_change ) : ?>
					<li><?php echo esc_html( $edit_orders_for_woocommerce_change ); ?></li>
				<?php endforeach; ?>
			</ul>
			<?php if ( $edit_orders_for_woocommerce_on_delivery && $edit_orders_for_woocommerce_door > 0 ) : ?>
				<?php /* translators: %s: amount. */ ?>
				<p class="eofw-money"><?php printf( esc_html__( 'You will pay %s more on delivery.', 'wpheka-edit-orders-for-woocommerce' ), wp_kses_post( wc_price( $edit_orders_for_woocommerce_door, $edit_orders_for_woocommerce_currency ) ) ); ?></p>
			<?php elseif ( $edit_orders_for_woocommerce_on_delivery && $edit_orders_for_woocommerce_door < 0 ) : ?>
				<?php /* translators: %s: amount. */ ?>
				<p class="eofw-money"><?php printf( esc_html__( 'You will pay %s less on delivery.', 'wpheka-edit-orders-for-woocommerce' ), wp_kses_post( wc_price( abs( $edit_orders_for_woocommerce_door ), $edit_orders_for_woocommerce_currency ) ) ); ?></p>
			<?php elseif ( $edit_orders_for_woocommerce_on_delivery ) : ?>
				<p class="eofw-money"><?php esc_html_e( 'The price does not change.', 'wpheka-edit-orders-for-woocommerce' ); ?></p>
			<?php elseif ( $edit_orders_for_woocommerce_preview['balance'] > 0 ) : ?>
				<?php /* translators: %s: amount. */ ?>
				<p class="eofw-money"><?php printf( esc_html__( 'This change costs %s more. You will pay the difference on the next page; until then your order stays as it is.', 'wpheka-edit-orders-for-woocommerce' ), wp_kses_post( wc_price( $edit_orders_for_woocommerce_preview['balance'], $edit_orders_for_woocommerce_currency ) ) ); ?></p>
				<?php if ( $edit_orders_for_woocommerce_preview['refund'] > 0 ) : ?>
					<?php /* translators: %s: amount. */ ?>
					<p class="eofw-money"><?php printf( esc_html__( 'Once it is paid, we refund %s.', 'wpheka-edit-orders-for-woocommerce' ), wp_kses_post( wc_price( $edit_orders_for_woocommerce_preview['refund'], $edit_orders_for_woocommerce_currency ) ) ); ?></p>
				<?php endif; ?>
			<?php elseif ( $edit_orders_for_woocommerce_preview['refund'] > 0 ) : ?>
				<?php /* translators: %s: amount. */ ?>
				<p class="eofw-money"><?php printf( esc_html__( 'We will refund %s.', 'wpheka-edit-orders-for-woocommerce' ), wp_kses_post( wc_price( $edit_orders_for_woocommerce_preview['refund'], $edit_orders_for_woocommerce_currency ) ) ); ?></p>
			<?php else : ?>
				<p class="eofw-money"><?php esc_html_e( 'The price does not change.', 'wpheka-edit-orders-for-woocommerce' ); ?></p>
			<?php endif; ?>
			<form method="post" class="eofw-confirm">
				<?php echo wp_kses( Edit_Orders_For_WooCommerce_Frontend::form_fields( $order, $order_key, $state['action'] ), Edit_Orders_For_WooCommerce_Frontend::FORM_HTML ); ?>
				<?php echo wp_kses( Edit_Orders_For_WooCommerce_Frontend::hidden_fields( $edit_orders_for_woocommerce_preview['fields'] ), Edit_Orders_For_WooCommerce_Frontend::FORM_HTML ); ?>
				<input type="hidden" name="step" value="confirm" />
				<button type="submit" class="button wp-element-button"><?php echo esc_html( $edit_orders_for_woocommerce_pays > 0 ? __( 'Confirm and pay the difference', 'wpheka-edit-orders-for-woocommerce' ) : __( 'Confirm the change', 'wpheka-edit-orders-for-woocommerce' ) ); ?></button>
				<a href="#edit-order" class="eofw-back"><?php esc_html_e( 'Make a different change', 'wpheka-edit-orders-for-woocommerce' ); ?></a>
			</form>
		</div>
	<?php endif; ?>

	<?php if ( $actions ) : ?>
		<p class="eofw-window">
			<?php
			printf(
				/* translators: %s: time left, such as 0:42:10. */
				esc_html__( 'You can change or cancel this order for another %s.', 'wpheka-edit-orders-for-woocommerce' ),
				'<strong data-eofw-seconds="' . esc_attr( $seconds_left ) . '">' . esc_html( gmdate( $seconds_left >= DAY_IN_SECONDS ? 'z\d H:i' : 'H:i:s', $seconds_left ) ) . '</strong>'
			);
			?>
		</p>
		<form method="post" class="eofw-close">
			<?php echo wp_kses( Edit_Orders_For_WooCommerce_Frontend::form_fields( $order, $order_key, 'close' ), Edit_Orders_For_WooCommerce_Frontend::FORM_HTML ); ?>
			<button type="submit" class="button wp-element-button eofw-done"><?php esc_html_e( 'All good, no changes needed', 'wpheka-edit-orders-for-woocommerce' ); ?></button>
		</form>

		<?php if ( in_array( 'swap', $actions, true ) && $swap_options ) : ?>
			<details class="eofw-action">
				<summary><?php esc_html_e( 'Change size or colour', 'wpheka-edit-orders-for-woocommerce' ); ?></summary>
				<form method="post">
					<?php echo wp_kses( Edit_Orders_For_WooCommerce_Frontend::form_fields( $order, $order_key, 'swap' ), Edit_Orders_For_WooCommerce_Frontend::FORM_HTML ); ?>
					<?php foreach ( $swap_options as $edit_orders_for_woocommerce_item_id => $edit_orders_for_woocommerce_line ) : ?>
						<p>
							<label for="eofw-swap-<?php echo esc_attr( $edit_orders_for_woocommerce_item_id ); ?>"><?php echo esc_html( $edit_orders_for_woocommerce_line['name'] ); ?></label>
							<select id="eofw-swap-<?php echo esc_attr( $edit_orders_for_woocommerce_item_id ); ?>" name="swap[<?php echo esc_attr( $edit_orders_for_woocommerce_item_id ); ?>]">
								<?php foreach ( $edit_orders_for_woocommerce_line['options'] as $edit_orders_for_woocommerce_variation_id => $edit_orders_for_woocommerce_label ) : ?>
									<option value="<?php echo esc_attr( $edit_orders_for_woocommerce_variation_id ); ?>" <?php selected( $edit_orders_for_woocommerce_line['current'], (int) $edit_orders_for_woocommerce_variation_id ); ?>><?php echo esc_html( $edit_orders_for_woocommerce_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
					<?php endforeach; ?>
					<button type="submit" class="button wp-element-button"><?php esc_html_e( 'Review the change', 'wpheka-edit-orders-for-woocommerce' ); ?></button>
				</form>
			</details>
		<?php endif; ?>

		<?php if ( in_array( 'address', $actions, true ) ) : ?>
			<details class="eofw-action">
				<summary><?php esc_html_e( 'Change the delivery address', 'wpheka-edit-orders-for-woocommerce' ); ?></summary>
				<form method="post" class="eofw-address">
					<?php echo wp_kses( Edit_Orders_For_WooCommerce_Frontend::form_fields( $order, $order_key, 'address' ), Edit_Orders_For_WooCommerce_Frontend::FORM_HTML ); ?>
					<?php
					$edit_orders_for_woocommerce_address   = $order->get_address( 'shipping' );
					$edit_orders_for_woocommerce_countries = WC()->countries->get_shipping_countries();
					$edit_orders_for_woocommerce_fields    = array(
						'first_name' => __( 'First name', 'wpheka-edit-orders-for-woocommerce' ),
						'last_name'  => __( 'Last name', 'wpheka-edit-orders-for-woocommerce' ),
						'company'    => __( 'Company', 'wpheka-edit-orders-for-woocommerce' ),
						'address_1'  => __( 'Street address', 'wpheka-edit-orders-for-woocommerce' ),
						'address_2'  => __( 'Apartment, suite, etc.', 'wpheka-edit-orders-for-woocommerce' ),
						'city'       => __( 'Town / City', 'wpheka-edit-orders-for-woocommerce' ),
						'country'    => __( 'Country', 'wpheka-edit-orders-for-woocommerce' ),
						'state'      => __( 'State / Province', 'wpheka-edit-orders-for-woocommerce' ),
						'postcode'   => __( 'Postcode / ZIP', 'wpheka-edit-orders-for-woocommerce' ),
					);
					foreach ( $edit_orders_for_woocommerce_fields as $edit_orders_for_woocommerce_field => $edit_orders_for_woocommerce_label ) :
						$edit_orders_for_woocommerce_value = isset( $edit_orders_for_woocommerce_address[ $edit_orders_for_woocommerce_field ] ) ? $edit_orders_for_woocommerce_address[ $edit_orders_for_woocommerce_field ] : '';
						?>
						<p>
							<label for="eofw-shipping-<?php echo esc_attr( $edit_orders_for_woocommerce_field ); ?>"><?php echo esc_html( $edit_orders_for_woocommerce_label ); ?></label>
							<?php if ( 'country' === $edit_orders_for_woocommerce_field ) : ?>
								<select id="eofw-shipping-country" name="shipping[country]" class="eofw-country">
									<?php if ( ! isset( $edit_orders_for_woocommerce_countries[ $edit_orders_for_woocommerce_value ] ) ) : ?>
										<option value="<?php echo esc_attr( $edit_orders_for_woocommerce_value ); ?>" selected><?php echo esc_html( $edit_orders_for_woocommerce_value ); ?></option>
									<?php endif; ?>
									<?php foreach ( $edit_orders_for_woocommerce_countries as $edit_orders_for_woocommerce_code => $edit_orders_for_woocommerce_country ) : ?>
										<option value="<?php echo esc_attr( $edit_orders_for_woocommerce_code ); ?>" <?php selected( $edit_orders_for_woocommerce_value, $edit_orders_for_woocommerce_code ); ?>><?php echo esc_html( $edit_orders_for_woocommerce_country ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php else : ?>
								<input type="text" class="input-text" id="eofw-shipping-<?php echo esc_attr( $edit_orders_for_woocommerce_field ); ?>" name="shipping[<?php echo esc_attr( $edit_orders_for_woocommerce_field ); ?>]" value="<?php echo esc_attr( $edit_orders_for_woocommerce_value ); ?>" />
							<?php endif; ?>
						</p>
					<?php endforeach; ?>
					<p class="eofw-hint"><?php esc_html_e( 'Shipping and tax are worked out again for the new address. You will see any difference before anything changes.', 'wpheka-edit-orders-for-woocommerce' ); ?></p>
					<button type="submit" class="button wp-element-button"><?php esc_html_e( 'Review the change', 'wpheka-edit-orders-for-woocommerce' ); ?></button>
				</form>
			</details>
		<?php endif; ?>

		<?php if ( in_array( 'note', $actions, true ) ) : ?>
			<details class="eofw-action">
				<summary><?php esc_html_e( 'Edit your order note', 'wpheka-edit-orders-for-woocommerce' ); ?></summary>
				<form method="post">
					<?php echo wp_kses( Edit_Orders_For_WooCommerce_Frontend::form_fields( $order, $order_key, 'note' ), Edit_Orders_For_WooCommerce_Frontend::FORM_HTML ); ?>
					<p>
						<label for="eofw-note"><?php esc_html_e( 'Note for the store, for example delivery instructions', 'wpheka-edit-orders-for-woocommerce' ); ?></label>
						<textarea id="eofw-note" name="customer_note" rows="3" maxlength="<?php echo esc_attr( $note_max ); ?>"><?php echo esc_textarea( $order->get_customer_note() ); ?></textarea>
					</p>
					<button type="submit" class="button wp-element-button"><?php esc_html_e( 'Save note', 'wpheka-edit-orders-for-woocommerce' ); ?></button>
				</form>
			</details>
		<?php endif; ?>

		<?php if ( in_array( 'cancel', $actions, true ) ) : ?>
			<details class="eofw-action eofw-cancel">
				<summary><?php esc_html_e( 'Cancel this order', 'wpheka-edit-orders-for-woocommerce' ); ?></summary>
				<form method="post">
					<?php echo wp_kses( Edit_Orders_For_WooCommerce_Frontend::form_fields( $order, $order_key, 'cancel' ), Edit_Orders_For_WooCommerce_Frontend::FORM_HTML ); ?>
					<p>
						<label for="eofw-reason"><?php echo esc_html( $reason_required ? __( 'Why do you want to cancel?', 'wpheka-edit-orders-for-woocommerce' ) : __( 'Why do you want to cancel? (optional)', 'wpheka-edit-orders-for-woocommerce' ) ); ?></label>
						<select id="eofw-reason" name="reason" class="eofw-reason" <?php echo $reason_required ? 'required' : ''; ?>>
							<option value=""><?php esc_html_e( 'Choose a reason', 'wpheka-edit-orders-for-woocommerce' ); ?></option>
							<?php foreach ( $reasons as $edit_orders_for_woocommerce_reason ) : ?>
								<option value="<?php echo esc_attr( $edit_orders_for_woocommerce_reason ); ?>"><?php echo esc_html( $edit_orders_for_woocommerce_reason ); ?></option>
							<?php endforeach; ?>
							<option value="__other"><?php esc_html_e( 'Other', 'wpheka-edit-orders-for-woocommerce' ); ?></option>
						</select>
					</p>
					<p class="eofw-reason-other">
						<label for="eofw-reason-other"><?php esc_html_e( 'Tell us more', 'wpheka-edit-orders-for-woocommerce' ); ?></label>
						<textarea id="eofw-reason-other" name="reason_other" rows="2" maxlength="500"></textarea>
					</p>
					<?php if ( '' !== $policy ) : ?>
						<p class="eofw-policy"><?php echo esc_html( $policy ); ?></p>
					<?php endif; ?>
					<p class="eofw-hint">
						<?php
						$edit_orders_for_woocommerce_refund_method = isset( $refund_method ) ? $refund_method : 'gateway';
						if ( 'instant' !== $cancel_mode ) {
							esc_html_e( 'Your request goes to the store, and we email you when they have answered.', 'wpheka-edit-orders-for-woocommerce' );
						} elseif ( 'on_delivery' === $edit_orders_for_woocommerce_refund_method ) {
							esc_html_e( 'Your order is cancelled straight away. There is nothing to pay.', 'wpheka-edit-orders-for-woocommerce' );
						} elseif ( 'store' === $edit_orders_for_woocommerce_refund_method ) {
							esc_html_e( 'Your order is cancelled straight away, and the store refunds what you paid.', 'wpheka-edit-orders-for-woocommerce' );
						} else {
							esc_html_e( 'Your order is cancelled straight away and refunded to your original payment method.', 'wpheka-edit-orders-for-woocommerce' );
						}
						?>
					</p>
					<button type="submit" class="button wp-element-button eofw-cancel-button"><?php echo esc_html( 'instant' === $cancel_mode ? __( 'Cancel my order', 'wpheka-edit-orders-for-woocommerce' ) : __( 'Ask to cancel', 'wpheka-edit-orders-for-woocommerce' ) ); ?></button>
				</form>
			</details>
		<?php endif; ?>
	<?php endif; ?>
</section>
