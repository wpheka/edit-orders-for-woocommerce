<?php
/**
 * Store-owner editing: the order screen box, the editor and its AJAX actions (spec section 3).
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit_Orders_For_WooCommerce_Admin Class.
 *
 * The editor is its own admin page (WooCommerce > Edit order, hidden from the
 * menu) reached from a box on the order screen. Nothing is written until the
 * store owner has seen the preview and pressed Apply.
 */
class Edit_Orders_For_WooCommerce_Admin {

	/**
	 * Editor page slug.
	 */
	const PAGE = 'edit-orders-for-woocommerce-editor';

	/**
	 * Capability needed to edit orders.
	 */
	const CAPABILITY = 'edit_shop_orders';

	/**
	 * AJAX nonce action.
	 */
	const NONCE = 'edit_orders_for_woocommerce';

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_filter( 'woocommerce_get_settings_pages', array( __CLASS__, 'settings_page' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( EDIT_ORDERS_FOR_WOOCOMMERCE_FILE ), array( __CLASS__, 'plugin_links' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ), 30 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );

		foreach ( array( 'preview', 'apply', 'resend_pay_link', 'cancel_balance', 'release_lock' ) as $action ) {
			add_action( 'wp_ajax_edit_orders_for_woocommerce_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}
	}

	/**
	 * Add the WooCommerce > Settings > Edit Orders tab.
	 *
	 * @param WC_Settings_Page[] $pages Settings pages.
	 * @return WC_Settings_Page[]
	 */
	public static function settings_page( $pages ) {
		require_once EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'includes/admin/class-edit-orders-for-woocommerce-settings-page.php';
		$pages[] = new Edit_Orders_For_WooCommerce_Settings_Page();

		return $pages;
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function plugin_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=edit_orders' ) ) . '">' . esc_html__( 'Settings', 'edit-orders-for-woocommerce' ) . '</a>' );

		return $links;
	}

	/**
	 * Register the editor page under WooCommerce.
	 *
	 * It is hidden from the menu on admin_head, not here: WordPress decides who
	 * may open a page by looking it up in the submenu, so removing it right away
	 * locks everyone out.
	 */
	public static function register_page() {
		add_submenu_page( 'woocommerce', __( 'Edit order', 'edit-orders-for-woocommerce' ), __( 'Edit order', 'edit-orders-for-woocommerce' ), self::CAPABILITY, self::PAGE, array( __CLASS__, 'render_editor' ) );
		add_action( 'admin_head', array( __CLASS__, 'hide_page_from_menu' ) );
	}

	/**
	 * Hide the editor from the WooCommerce menu; it is opened from an order.
	 */
	public static function hide_page_from_menu() {
		remove_submenu_page( 'woocommerce', self::PAGE );
	}

	/**
	 * Editor URL for an order.
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	public static function editor_url( $order_id ) {
		return add_query_arg(
			array(
				'page'     => self::PAGE,
				'order_id' => absint( $order_id ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Add the box to the order screen (HPOS and legacy).
	 */
	public static function add_meta_box() {
		foreach ( array( wc_get_page_screen_id( 'shop-order' ), 'shop_order' ) as $screen ) {
			add_meta_box( 'edit-orders-for-woocommerce', __( 'Edit order', 'edit-orders-for-woocommerce' ), array( __CLASS__, 'render_meta_box' ), $screen, 'side', 'high' );
		}
	}

	/**
	 * The order screen box: edit button, manual refund flag and linked balance orders.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post (legacy) or order (HPOS).
	 */
	public static function render_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}

		if ( Edit_Orders_For_WooCommerce_Balance_Orders::is_balance_order( $order ) ) {
			$parent = wc_get_order( $order->get_parent_id() );
			echo '<p>';
			printf(
				/* translators: %s: link to the original order. */
				esc_html__( 'This is a balance order for changes to %s.', 'edit-orders-for-woocommerce' ),
				$parent ? '<a href="' . esc_url( $parent->get_edit_order_url() ) . '">#' . esc_html( $parent->get_order_number() ) . '</a>' : ''
			);
			echo '</p>';
			return;
		}

		$manual = $order->get_meta( Edit_Orders_For_WooCommerce_Refunds::MANUAL_REFUND_META );
		if ( $manual ) {
			echo '<p class="edit-orders-manual-refund"><strong>';
			/* translators: %s: amount. */
			printf( esc_html__( 'Manual refund needed: %s', 'edit-orders-for-woocommerce' ), wp_kses_post( wc_price( $manual, array( 'currency' => $order->get_currency() ) ) ) );
			echo '</strong></p>';
		}

		if ( 'pending' === $order->get_meta( Edit_Orders_For_WooCommerce_Cancellation::STATUS_META ) ) {
			$request = (array) $order->get_meta( Edit_Orders_For_WooCommerce_Cancellation::REQUEST_META );
			echo '<div class="edit-orders-cancel-request"><p><strong>' . esc_html__( 'The customer asked to cancel this order.', 'edit-orders-for-woocommerce' ) . '</strong>';
			if ( ! empty( $request['reason'] ) ) {
				/* translators: %s: reason. */
				echo '<br />' . esc_html( sprintf( __( 'Reason: %s', 'edit-orders-for-woocommerce' ), $request['reason'] ) );
			}
			echo '</p><p><a class="button button-primary" href="' . esc_url( Edit_Orders_For_WooCommerce_Activity::approve_url( $order, $order->get_edit_order_url() ) ) . '">' . esc_html__( 'Approve and refund', 'edit-orders-for-woocommerce' ) . '</a> ';
			echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . Edit_Orders_For_WooCommerce_Activity::PAGE ) ) . '">' . esc_html__( 'Decline with a message', 'edit-orders-for-woocommerce' ) . '</a></p></div>';
		}

		$eligible = Edit_Orders_For_WooCommerce_Eligibility::check_order( $order );
		if ( is_wp_error( $eligible ) ) {
			echo '<p class="description">' . esc_html( $eligible->get_error_message() ) . '</p>';
		} else {
			echo '<p><a class="button button-primary" href="' . esc_url( self::editor_url( $order->get_id() ) ) . '">' . esc_html__( 'Edit items or address', 'edit-orders-for-woocommerce' ) . '</a></p>';
		}

		$balances = wc_get_orders(
			array(
				'parent'      => $order->get_id(),
				'created_via' => Edit_Orders_For_WooCommerce_Balance_Orders::CREATED_VIA,
				'limit'       => -1,
				'status'      => array_keys( wc_get_order_statuses() ),
			)
		);
		if ( ! $balances ) {
			return;
		}

		echo '<h4>' . esc_html__( 'Balance orders', 'edit-orders-for-woocommerce' ) . '</h4><ul class="edit-orders-balances" data-order="' . esc_attr( $order->get_id() ) . '">';
		foreach ( $balances as $balance ) {
			$open = in_array( $balance->get_status(), Edit_Orders_For_WooCommerce_Balance_Orders::OPEN_STATUSES, true );
			echo '<li data-balance="' . esc_attr( $balance->get_id() ) . '">';
			echo '<a href="' . esc_url( $balance->get_edit_order_url() ) . '">#' . esc_html( $balance->get_order_number() ) . '</a> ';
			echo wp_kses_post( wc_price( $balance->get_total(), array( 'currency' => $balance->get_currency() ) ) ) . ' ';
			echo '<mark class="order-status status-' . esc_attr( $balance->get_status() ) . '"><span>' . esc_html( wc_get_order_status_name( $balance->get_status() ) ) . '</span></mark>';
			if ( $open && 'pending' === $balance->get_status() ) {
				echo '<input type="text" readonly class="widefat edit-orders-pay-link" value="' . esc_attr( $balance->get_checkout_payment_url() ) . '" onfocus="this.select();" />';
				echo '<button type="button" class="button-link edit-orders-resend">' . esc_html__( 'Resend pay link', 'edit-orders-for-woocommerce' ) . '</button> | ';
				echo '<button type="button" class="button-link edit-orders-cancel-balance">' . esc_html__( 'Cancel', 'edit-orders-for-woocommerce' ) . '</button>';
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Load the editor's and the box's scripts.
	 *
	 * @param string $hook Admin page hook.
	 */
	public static function enqueue( $hook ) {
		$screen    = get_current_screen();
		$is_editor = 'woocommerce_page_' . self::PAGE === $hook;
		$is_order  = $screen && in_array( $screen->id, array( wc_get_page_screen_id( 'shop-order' ), 'shop_order' ), true );

		if ( ! $is_editor && ! $is_order ) {
			return;
		}

		wp_enqueue_style( 'edit-orders-for-woocommerce-admin', EDIT_ORDERS_FOR_WOOCOMMERCE_URL . 'assets/css/admin.css', array(), EDIT_ORDERS_FOR_WOOCOMMERCE_VERSION );
		wp_enqueue_script( 'edit-orders-for-woocommerce-admin', EDIT_ORDERS_FOR_WOOCOMMERCE_URL . 'assets/js/admin.js', array( 'jquery', 'wc-enhanced-select' ), EDIT_ORDERS_FOR_WOOCOMMERCE_VERSION, true );

		if ( $is_editor ) {
			wp_enqueue_style( 'woocommerce_admin_styles' );
		}

		wp_localize_script(
			'edit-orders-for-woocommerce-admin',
			'editOrdersForWooCommerce',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'states'  => WC()->countries->get_states(),
				'i18n'    => array(
					'working'       => __( 'Working...', 'edit-orders-for-woocommerce' ),
					'error'         => __( 'Something went wrong. Reload the page and try again.', 'edit-orders-for-woocommerce' ),
					'confirmCancel' => __( 'Cancel this balance order? The changes waiting for it will not be made.', 'edit-orders-for-woocommerce' ),
					'sent'          => __( 'Pay link sent.', 'edit-orders-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * The editor page.
	 */
	public static function render_editor() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a GET link to a page that changes nothing by itself.
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;

		echo '<div class="wrap edit-orders-editor">';

		if ( ! $order || ! current_user_can( self::CAPABILITY ) ) {
			echo '<h1>' . esc_html__( 'Edit order', 'edit-orders-for-woocommerce' ) . '</h1><p>' . esc_html__( 'Order not found.', 'edit-orders-for-woocommerce' ) . '</p></div>';
			return;
		}

		/* translators: %s: order number. */
		echo '<h1>' . esc_html( sprintf( __( 'Edit order #%s', 'edit-orders-for-woocommerce' ), $order->get_order_number() ) ) . '</h1>';
		echo '<p><a href="' . esc_url( $order->get_edit_order_url() ) . '">&larr; ' . esc_html__( 'Back to the order', 'edit-orders-for-woocommerce' ) . '</a></p>';

		$eligible = Edit_Orders_For_WooCommerce_Eligibility::check_order( $order );
		if ( is_wp_error( $eligible ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $eligible->get_error_message() ) . '</p></div></div>';
			return;
		}

		Edit_Orders_For_WooCommerce_Lock::acquire( $order, get_current_user_id() );

		$on_delivery = Edit_Orders_For_WooCommerce_Eligibility::is_pay_on_delivery( $order );
		echo '<p class="description">';
		echo esc_html(
			$on_delivery
				? __( 'Paid on delivery: changes adjust the amount to collect.', 'edit-orders-for-woocommerce' )
				/* translators: %s: payment method. */
				: sprintf( __( 'Paid by %s. Decreases are refunded; increases are paid through a pay link for the difference.', 'edit-orders-for-woocommerce' ), $order->get_payment_method_title() )
		);
		echo '</p>';

		echo '<form id="edit-orders-form" data-order="' . esc_attr( $order->get_id() ) . '">';
		echo '<h2 class="nav-tab-wrapper"><a href="#items" class="nav-tab nav-tab-active" data-mode="items">' . esc_html__( 'Items', 'edit-orders-for-woocommerce' ) . '</a><a href="#address" class="nav-tab" data-mode="address">' . esc_html__( 'Address', 'edit-orders-for-woocommerce' ) . '</a></h2>';
		echo '<input type="hidden" name="mode" value="items" />';

		self::render_items_tab( $order );
		self::render_address_tab( $order );

		echo '<p class="edit-orders-actions"><button type="button" class="button button-primary" id="edit-orders-preview">' . esc_html__( 'Preview changes', 'edit-orders-for-woocommerce' ) . '</button> ';
		echo '<button type="button" class="button" id="edit-orders-discard" data-back="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html__( 'Discard', 'edit-orders-for-woocommerce' ) . '</button></p>';
		echo '</form>';
		echo '<div id="edit-orders-preview-panel" aria-live="polite"></div>';
		echo '</div>';
	}

	/**
	 * Items tab: quantities, variation swaps and added products.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function render_items_tab( WC_Order $order ) {
		echo '<div class="edit-orders-tab" data-tab="items">';
		echo '<table class="widefat striped edit-orders-items"><thead><tr><th>' . esc_html__( 'Item', 'edit-orders-for-woocommerce' ) . '</th><th>' . esc_html__( 'Paid each', 'edit-orders-for-woocommerce' ) . '</th><th>' . esc_html__( 'Option', 'edit-orders-for-woocommerce' ) . '</th><th>' . esc_html__( 'Quantity', 'edit-orders-for-woocommerce' ) . '</th></tr></thead><tbody>';

		foreach ( $order->get_items() as $item_id => $item ) {
			$check    = Edit_Orders_For_WooCommerce_Eligibility::check_item( $order, $item );
			$disabled = is_wp_error( $check );
			$qty      = (int) $item->get_quantity();
			$each     = $qty ? (float) $item->get_total() / $qty : 0;

			echo '<tr data-item="' . esc_attr( $item_id ) . '"' . ( $disabled ? ' class="is-locked"' : '' ) . '>';
			echo '<td>' . esc_html( $item->get_name() );
			if ( $disabled ) {
				echo '<br /><span class="description">' . esc_html( $check->get_error_message() ) . '</span>';
			}
			echo '</td>';
			echo '<td>' . wp_kses_post( wc_price( $each, array( 'currency' => $order->get_currency() ) ) ) . '</td><td>';

			$parent = $item->get_variation_id() ? wc_get_product( $item->get_product_id() ) : null;
			if ( $parent && ! $disabled ) {
				echo '<select name="items[' . esc_attr( $item_id ) . '][variation]">';
				foreach ( $parent->get_children() as $variation_id ) {
					$variation = wc_get_product( $variation_id );
					if ( ! $variation || ! $variation->exists() ) {
						continue;
					}
					$label = wc_get_formatted_variation( $variation, true, false, false ) . ' (' . wp_strip_all_tags( wc_price( Edit_Orders_For_WooCommerce_Pricing::current_price( $variation ), array( 'currency' => $order->get_currency() ) ) ) . ( $variation->is_in_stock() ? '' : ', ' . __( 'out of stock', 'edit-orders-for-woocommerce' ) ) . ')';
					echo '<option value="' . esc_attr( $variation_id ) . '"' . selected( (int) $item->get_variation_id(), (int) $variation_id, false ) . disabled( ! $variation->is_in_stock() && (int) $variation_id !== (int) $item->get_variation_id(), true, false ) . '>' . esc_html( html_entity_decode( $label ) ) . '</option>';
				}
				echo '</select>';
			} else {
				echo '&mdash;';
			}

			echo '</td><td><input type="number" min="0" step="1" name="items[' . esc_attr( $item_id ) . '][qty]" value="' . esc_attr( $qty ) . '" data-original="' . esc_attr( $qty ) . '"' . disabled( $disabled, true, false ) . ' class="small-text" /></td></tr>';
		}

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Set a quantity to 0 to remove the item.', 'edit-orders-for-woocommerce' ) . '</p>';

		echo '<h3>' . esc_html__( 'Add products', 'edit-orders-for-woocommerce' ) . '</h3>';
		echo '<div class="edit-orders-add"><select class="wc-product-search edit-orders-add-product" style="width: 320px;" data-placeholder="' . esc_attr__( 'Search for a product', 'edit-orders-for-woocommerce' ) . '" data-action="woocommerce_json_search_products_and_variations" data-exclude_type="variable"></select> ';
		echo '<input type="number" min="1" step="1" value="1" class="small-text edit-orders-add-qty" aria-label="' . esc_attr__( 'Quantity', 'edit-orders-for-woocommerce' ) . '" /> ';
		echo '<input type="text" class="short edit-orders-add-price" placeholder="' . esc_attr__( 'Catalogue price', 'edit-orders-for-woocommerce' ) . '" aria-label="' . esc_attr__( 'Price each, excluding tax (optional)', 'edit-orders-for-woocommerce' ) . '" /> ';
		echo '<button type="button" class="button edit-orders-add-button">' . esc_html__( 'Add', 'edit-orders-for-woocommerce' ) . '</button></div>';
		echo '<ul class="edit-orders-added"></ul>';
		echo '</div>';
	}

	/**
	 * Address tab: shipping and billing addresses. The billing email is not editable here.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function render_address_tab( WC_Order $order ) {
		echo '<div class="edit-orders-tab" data-tab="address" hidden>';
		echo '<p class="description">' . esc_html__( 'An address change is applied on its own: shipping is re-rated and tax recalculated for the new address.', 'edit-orders-for-woocommerce' ) . '</p>';
		echo '<div class="edit-orders-addresses">';

		$labels = array(
			'first_name' => __( 'First name', 'edit-orders-for-woocommerce' ),
			'last_name'  => __( 'Last name', 'edit-orders-for-woocommerce' ),
			'company'    => __( 'Company', 'edit-orders-for-woocommerce' ),
			'address_1'  => __( 'Address line 1', 'edit-orders-for-woocommerce' ),
			'address_2'  => __( 'Address line 2', 'edit-orders-for-woocommerce' ),
			'city'       => __( 'City', 'edit-orders-for-woocommerce' ),
			'postcode'   => __( 'Postcode / ZIP', 'edit-orders-for-woocommerce' ),
			'country'    => __( 'Country', 'edit-orders-for-woocommerce' ),
			'state'      => __( 'State / Province', 'edit-orders-for-woocommerce' ),
			'phone'      => __( 'Phone', 'edit-orders-for-woocommerce' ),
		);

		foreach ( array( 'shipping', 'billing' ) as $type ) {
			$address   = $order->get_address( $type );
			$countries = 'shipping' === $type ? WC()->countries->get_shipping_countries() : WC()->countries->get_allowed_countries();

			echo '<fieldset class="edit-orders-address" data-type="' . esc_attr( $type ) . '"><legend>' . esc_html( 'shipping' === $type ? __( 'Shipping address', 'edit-orders-for-woocommerce' ) : __( 'Billing address', 'edit-orders-for-woocommerce' ) ) . '</legend>';
			foreach ( $labels as $field => $label ) {
				$name  = $type . '[' . $field . ']';
				$value = isset( $address[ $field ] ) ? $address[ $field ] : '';
				echo '<p><label>' . esc_html( $label ) . '<br />';
				if ( 'country' === $field ) {
					echo '<select name="' . esc_attr( $name ) . '" class="edit-orders-country" data-original="' . esc_attr( $value ) . '">';
					// Keep a stored country the store no longer sells to (or none), so the form doesn't change it unasked.
					if ( ! isset( $countries[ $value ] ) ) {
						echo '<option value="' . esc_attr( $value ) . '" selected>' . esc_html( $value ) . '</option>';
					}
					foreach ( $countries as $code => $country ) {
						echo '<option value="' . esc_attr( $code ) . '"' . selected( $value, $code, false ) . '>' . esc_html( $country ) . '</option>';
					}
					echo '</select>';
				} else {
					echo '<input type="text" class="regular-text edit-orders-' . esc_attr( $field ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" data-original="' . esc_attr( $value ) . '" />';
				}
				echo '</label></p>';
			}
			echo '</fieldset>';
		}

		echo '</div><p class="edit-orders-rate-choice" hidden></p>';
		echo '</div>';
	}

	/**
	 * Turn the posted form into a change set.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $data  Posted data (already unslashed).
	 * @return array[]
	 */
	public static function changes_from_request( WC_Order $order, array $data ) {
		$changes = array();
		$mode    = isset( $data['mode'] ) && 'address' === $data['mode'] ? 'address' : 'items';

		if ( 'address' === $mode ) {
			$change = array( 'type' => 'address' );
			foreach ( array( 'shipping', 'billing' ) as $type ) {
				if ( empty( $data[ $type ] ) || ! is_array( $data[ $type ] ) ) {
					continue;
				}
				$current = $order->get_address( $type );
				$fields  = array();
				foreach ( Edit_Orders_For_WooCommerce_Address_Change::FIELDS as $field ) {
					if ( isset( $data[ $type ][ $field ] ) && (string) $data[ $type ][ $field ] !== (string) $current[ $field ] ) {
						$fields[ $field ] = sanitize_text_field( $data[ $type ][ $field ] );
					}
				}
				if ( $fields ) {
					$change[ $type ] = $fields;
				}
			}
			if ( ! empty( $data['shipping_method'] ) ) {
				$change['shipping_method'] = sanitize_text_field( $data['shipping_method'] );
			}
			if ( count( $change ) > 1 ) {
				$changes[] = $change;
			}
			return $changes;
		}

		foreach ( isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array() as $item_id => $fields ) {
			$item = $order->get_item( absint( $item_id ) );
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$qty       = isset( $fields['qty'] ) ? absint( $fields['qty'] ) : (int) $item->get_quantity();
			$variation = isset( $fields['variation'] ) ? absint( $fields['variation'] ) : (int) $item->get_variation_id();

			if ( 0 === $qty ) {
				$changes[] = array(
					'type'    => 'remove',
					'item_id' => $item->get_id(),
				);
			} elseif ( $variation && $variation !== (int) $item->get_variation_id() ) {
				// One change per line: a swap keeps the quantity. Change the quantity in a second edit.
				$changes[] = array(
					'type'         => 'swap',
					'item_id'      => $item->get_id(),
					'variation_id' => $variation,
				);
			} elseif ( $qty !== (int) $item->get_quantity() ) {
				$changes[] = array(
					'type'     => 'quantity',
					'item_id'  => $item->get_id(),
					'quantity' => $qty,
				);
			}
		}

		foreach ( isset( $data['add'] ) && is_array( $data['add'] ) ? $data['add'] : array() as $add ) {
			if ( empty( $add['product_id'] ) ) {
				continue;
			}
			$change = array(
				'type'       => 'add',
				'product_id' => absint( $add['product_id'] ),
				'quantity'   => isset( $add['qty'] ) ? max( 1, absint( $add['qty'] ) ) : 1,
			);
			if ( isset( $add['price'] ) && '' !== trim( (string) $add['price'] ) ) {
				$change['price'] = wc_format_decimal( sanitize_text_field( $add['price'] ) );
			}
			$changes[] = $change;
		}

		return $changes;
	}

	/**
	 * Build the preview for a request.
	 *
	 * @param WC_Order $order Order.
	 * @param array[]  $changes Change set.
	 * @return array { ok, html, rates }
	 */
	public static function preview( WC_Order $order, array $changes ) {
		$plan = Edit_Orders_For_WooCommerce_Settlement_Executor::preview( $order, $changes, 'staff' );

		if ( is_wp_error( $plan ) ) {
			return array(
				'ok'    => false,
				'html'  => '<div class="notice notice-error inline"><p>' . esc_html( $plan->get_error_message() ) . '</p></div>',
				// Address change where the current shipping method isn't offered: offer the others.
				'rates' => Edit_Orders_For_WooCommerce_Address_Change::rate_choices( $order, $changes ),
			);
		}

		return array(
			'ok'    => true,
			'html'  => self::render_preview( $order, $plan ),
			'rates' => array(),
		);
	}

	/**
	 * The preview: before and after, the money, the stock and the email options.
	 *
	 * @param WC_Order                                    $order Order.
	 * @param Edit_Orders_For_WooCommerce_Settlement_Plan $plan  Plan.
	 * @return string HTML.
	 */
	public static function render_preview( WC_Order $order, Edit_Orders_For_WooCommerce_Settlement_Plan $plan ) {
		$currency    = array( 'currency' => $order->get_currency() );
		$on_delivery = Edit_Orders_For_WooCommerce_Eligibility::is_pay_on_delivery( $order );
		$refund      = $plan->get_refund_amount();
		$balance     = $plan->get_balance_estimate( $order );
		$gateway     = wc_get_payment_gateway_by_order( $order );

		ob_start();
		echo '<div class="edit-orders-preview">';
		echo '<h2>' . esc_html__( 'Preview', 'edit-orders-for-woocommerce' ) . '</h2>';

		echo '<ul class="edit-orders-changes">';
		foreach ( $plan->get_descriptions() as $description ) {
			echo '<li>' . esc_html( $description ) . '</li>';
		}
		echo '</ul>';

		$rows = $plan->get_preview_rows( $order );
		if ( $rows ) {
			echo '<table class="widefat striped edit-orders-diff"><thead><tr><th>' . esc_html__( 'Item', 'edit-orders-for-woocommerce' ) . '</th><th>' . esc_html__( 'Before', 'edit-orders-for-woocommerce' ) . '</th><th>' . esc_html__( 'After', 'edit-orders-for-woocommerce' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				echo '<tr class="is-' . esc_attr( $row['state'] ) . '"><td>' . esc_html( $row['after_name'] !== $row['name'] ? $row['name'] . ' > ' . $row['after_name'] : $row['name'] ) . '</td>';
				echo '<td>' . esc_html( $row['before'] ? $row['before'] : '-' ) . '</td><td>' . esc_html( $row['after'] ? $row['after'] : __( 'removed', 'edit-orders-for-woocommerce' ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '<div class="edit-orders-money">';
		if ( $refund > 0 ) {
			echo '<p class="edit-orders-refund" data-amount="' . esc_attr( wc_format_decimal( $refund, wc_get_price_decimals() ) ) . '">';
			if ( $on_delivery ) {
				/* translators: %s: amount. */
				printf( esc_html__( 'Collect %s less on delivery.', 'edit-orders-for-woocommerce' ), wp_kses_post( wc_price( $refund, $currency ) ) );
			} elseif ( $gateway && $gateway->supports( 'refunds' ) ) {
				/* translators: 1: amount, 2: payment method. */
				printf( esc_html__( 'Refund %1$s through %2$s.', 'edit-orders-for-woocommerce' ), wp_kses_post( wc_price( $refund, $currency ) ), esc_html( $order->get_payment_method_title() ) );
			} else {
				/* translators: %s: amount. */
				printf( esc_html__( 'Manual refund needed: %s. This payment method cannot refund automatically; you will be reminded by email.', 'edit-orders-for-woocommerce' ), wp_kses_post( wc_price( $refund, $currency ) ) );
			}
			if ( $plan->needs_balance() && ! $on_delivery ) {
				echo ' ' . esc_html__( 'The refund is made once the balance is paid.', 'edit-orders-for-woocommerce' );
			}
			echo '</p>';
		}

		if ( $plan->needs_balance() ) {
			echo '<p class="edit-orders-balance" data-amount="' . esc_attr( wc_format_decimal( $balance, wc_get_price_decimals() ) ) . '">';
			if ( $on_delivery ) {
				/* translators: %s: amount. */
				printf( esc_html__( 'Collect %s more on delivery, on a linked order.', 'edit-orders-for-woocommerce' ), wp_kses_post( wc_price( $balance, $currency ) ) );
			} else {
				/* translators: %s: amount. */
				printf( esc_html__( 'Customer pays %s through a pay link. The changes are made when it is paid; until then the order ships as it is.', 'edit-orders-for-woocommerce' ), wp_kses_post( wc_price( $balance, $currency ) ) );
			}
			echo '</p>';
		}

		if ( $refund <= 0 && ! $plan->needs_balance() ) {
			echo '<p class="edit-orders-no-money">' . esc_html__( 'No money moves.', 'edit-orders-for-woocommerce' ) . '</p>';
		}
		echo '</div>';

		$stock = self::stock_lines( $order, $plan );
		if ( $stock ) {
			echo '<h3>' . esc_html__( 'Stock', 'edit-orders-for-woocommerce' ) . '</h3><ul class="edit-orders-stock">';
			foreach ( $stock as $line ) {
				echo '<li>' . esc_html( $line ) . '</li>';
			}
			echo '</ul>';
		}

		echo '<p class="edit-orders-options">';
		if ( $plan->needs_balance() && ! $on_delivery && self::balance_due_email() ) {
			echo '<label><input type="checkbox" name="send_pay_link" value="1" checked /> ' . esc_html__( 'Email the customer the pay link', 'edit-orders-for-woocommerce' ) . '</label><br />';
		} elseif ( $plan->needs_balance() && ! $on_delivery ) {
			echo esc_html__( 'The "Balance due" email is turned off in WooCommerce > Settings > Emails. Send the customer the pay link from the order screen yourself.', 'edit-orders-for-woocommerce' ) . '<br />';
		} else {
			echo '<label><input type="checkbox" name="notify_customer" value="1" checked /> ' . esc_html__( 'Email the customer that the order was updated', 'edit-orders-for-woocommerce' ) . '</label><br />';
		}
		echo '</p>';
		echo '<input type="hidden" name="expect" value="' . esc_attr( $plan->fingerprint() ) . '" />';
		echo '<p><button type="button" class="button button-primary" id="edit-orders-apply">' . esc_html__( 'Apply changes', 'edit-orders-for-woocommerce' ) . '</button></p>';
		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * What happens to stock, for the preview.
	 *
	 * @param WC_Order                                    $order Order.
	 * @param Edit_Orders_For_WooCommerce_Settlement_Plan $plan  Plan.
	 * @return string[]
	 */
	private static function stock_lines( WC_Order $order, Edit_Orders_For_WooCommerce_Settlement_Plan $plan ) {
		$lines = array();

		foreach ( $plan->get_refund_lines() as $item_id => $line ) {
			$item = $order->get_item( $item_id );
			if ( $line['qty'] > 0 && $item instanceof WC_Order_Item_Product && $item->get_meta( '_reduced_stock', true ) ) {
				/* translators: 1: quantity, 2: product name. */
				$lines[] = sprintf( __( '%1$d x %2$s back to stock.', 'edit-orders-for-woocommerce' ), $line['qty'], $item->get_name() );
			}
		}

		foreach ( $plan->get_repoints() as $repoint ) {
			$item      = $order->get_item( $repoint['item_id'] );
			$variation = wc_get_product( $repoint['variation_id'] );
			if ( ! $item instanceof WC_Order_Item_Product || ! $variation || ! $order->get_order_stock_reduced() ) {
				continue;
			}
			// Same rules as Edit_Orders_For_WooCommerce_Stock::repoint().
			$old = $item->get_product();
			if ( (int) $item->get_meta( '_reduced_stock', true ) > 0 && $old && $old->managing_stock() ) {
				/* translators: 1: quantity, 2: product name. */
				$lines[] = sprintf( __( '%1$d x %2$s back to stock.', 'edit-orders-for-woocommerce' ), (int) $item->get_meta( '_reduced_stock', true ), $item->get_name() );
			}
			if ( $variation->managing_stock() ) {
				/* translators: 1: quantity, 2: product name. */
				$lines[] = sprintf( __( '%1$d x %2$s taken.', 'edit-orders-for-woocommerce' ), (int) $item->get_quantity(), $variation->get_name() );
			}
		}

		foreach ( $plan->get_balance_items() as $line ) {
			if ( 'product' === $line['type'] ) {
				$product = wc_get_product( $line['product_id'] );
				/* translators: 1: quantity, 2: product name. */
				$lines[] = sprintf( __( '%1$d x %2$s taken when the balance is paid.', 'edit-orders-for-woocommerce' ), $line['quantity'], $product ? $product->get_name() : '' );
			}
		}

		return $lines;
	}

	/**
	 * Check the nonce and capability, and load the order, for an AJAX request.
	 *
	 * @return WC_Order Exits with an error response otherwise.
	 */
	private static function ajax_order() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit orders.', 'edit-orders-for-woocommerce' ) ), 403 );
		}

		$order = isset( $_POST['order_id'] ) ? wc_get_order( absint( wp_unslash( $_POST['order_id'] ) ) ) : null;
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'edit-orders-for-woocommerce' ) ), 404 );
		}

		return $order;
	}

	/**
	 * The posted form, unslashed. Each field is sanitised where it is used.
	 *
	 * @return array
	 */
	private static function posted_form() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in ajax_order(); fields sanitised in changes_from_request().
		$form = isset( $_POST['form'] ) ? wp_unslash( $_POST['form'] ) : array();

		return is_array( $form ) ? $form : array();
	}

	/**
	 * AJAX: preview.
	 */
	public static function ajax_preview() {
		$order = self::ajax_order();
		Edit_Orders_For_WooCommerce_Lock::acquire( $order, get_current_user_id() );

		wp_send_json_success( self::preview( $order, self::changes_from_request( $order, self::posted_form() ) ) );
	}

	/**
	 * Apply a request's changes as a store owner.
	 *
	 * @param WC_Order $order   Order.
	 * @param array    $form    Posted form.
	 * @param array    $options send_pay_link, notify_customer, expect (fingerprint of the preview).
	 * @return array|WP_Error Executor result.
	 */
	public static function apply( WC_Order $order, array $form, array $options ) {
		$result = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
			$order,
			self::changes_from_request( $order, $form ),
			'staff',
			array(
				'send_pay_link'   => ! empty( $options['send_pay_link'] ),
				'notify_customer' => ! empty( $options['notify_customer'] ),
				'expect'          => isset( $options['expect'] ) ? (string) $options['expect'] : '',
			)
		);

		if ( ! is_wp_error( $result ) ) {
			Edit_Orders_For_WooCommerce_Lock::release( wc_get_order( $order->get_id() ), get_current_user_id() );
		}

		return $result;
	}

	/**
	 * AJAX: apply.
	 */
	public static function ajax_apply() {
		$order = self::ajax_order();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in ajax_order().
		$options = array(
			'send_pay_link'   => ! empty( $_POST['send_pay_link'] ),
			'notify_customer' => ! empty( $_POST['notify_customer'] ),
			'expect'          => isset( $_POST['expect'] ) ? sanitize_key( wp_unslash( $_POST['expect'] ) ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = self::apply( $order, self::posted_form(), $options );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'status'   => $result['status'],
				'redirect' => wc_get_order( $order->get_id() )->get_edit_order_url(),
			)
		);
	}

	/**
	 * Load a balance order from an AJAX request, checking it belongs to the order.
	 *
	 * @param WC_Order $order Original order.
	 * @return WC_Order Exits with an error otherwise.
	 */
	private static function ajax_balance( WC_Order $order ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in ajax_order().
		$balance = isset( $_POST['balance_id'] ) ? wc_get_order( absint( wp_unslash( $_POST['balance_id'] ) ) ) : null;
		if ( ! $balance || ! Edit_Orders_For_WooCommerce_Balance_Orders::is_balance_order( $balance ) || (int) $balance->get_parent_id() !== $order->get_id() ) {
			wp_send_json_error( array( 'message' => __( 'Balance order not found.', 'edit-orders-for-woocommerce' ) ), 404 );
		}

		// Only an unpaid balance order can be resent or cancelled; a paid one is refunded from its own screen.
		if ( 'pending' !== $balance->get_status() ) {
			wp_send_json_error( array( 'message' => __( 'This balance order is no longer waiting for payment. Reload the page.', 'edit-orders-for-woocommerce' ) ), 409 );
		}

		return $balance;
	}

	/**
	 * The "Balance due" email, when it is turned on in WooCommerce > Settings > Emails.
	 *
	 * @return WC_Email|null
	 */
	private static function balance_due_email() {
		$emails = WC()->mailer()->get_emails();
		$email  = isset( $emails['Edit_Orders_For_WooCommerce_Email_Balance_Due'] ) ? $emails['Edit_Orders_For_WooCommerce_Email_Balance_Due'] : null;

		return ( $email && $email->is_enabled() ) ? $email : null;
	}

	/**
	 * AJAX: resend the pay link.
	 */
	public static function ajax_resend_pay_link() {
		$order   = self::ajax_order();
		$balance = self::ajax_balance( $order );
		$plan    = $balance->get_meta( Edit_Orders_For_WooCommerce_Balance_Orders::PLAN_META );
		$email   = self::balance_due_email();

		if ( ! $email ) {
			wp_send_json_error( array( 'message' => __( 'The "Balance due" email is turned off in WooCommerce > Settings > Emails, so nothing was sent. Copy the pay link instead.', 'edit-orders-for-woocommerce' ) ) );
		}

		$email->trigger( $balance->get_id(), $balance, is_array( $plan ) && isset( $plan['descriptions'] ) ? $plan['descriptions'] : array() );
		/* translators: %s: balance order number. */
		$order->add_order_note( sprintf( __( 'Pay link for balance order #%s sent again.', 'edit-orders-for-woocommerce' ), $balance->get_order_number() ) );

		wp_send_json_success();
	}

	/**
	 * AJAX: cancel a balance order.
	 */
	public static function ajax_cancel_balance() {
		$order   = self::ajax_order();
		$balance = self::ajax_balance( $order );
		$balance->update_status( 'cancelled', __( 'Cancelled by the store; the changes waiting for it were not made.', 'edit-orders-for-woocommerce' ) );

		wp_send_json_success();
	}

	/**
	 * AJAX: release the edit lock (Discard).
	 */
	public static function ajax_release_lock() {
		$order = self::ajax_order();
		Edit_Orders_For_WooCommerce_Lock::release( $order, get_current_user_id() );

		wp_send_json_success();
	}
}
