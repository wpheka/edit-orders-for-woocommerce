<?php
/**
 * WPHEKA > Edit Orders: the plugin's own page, with Settings, Activity and Help tabs.
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

use Edit_Orders_For_WooCommerce\AdminUI\Menu;
use Edit_Orders_For_WooCommerce\AdminUI\Page;
use Edit_Orders_For_WooCommerce\AdminUI\Icons;

/**
 * Edit_Orders_For_WooCommerce_Admin_Page Class.
 *
 * Opening the page needs edit_shop_orders, as the Activity page always did. The
 * Settings tab and its save need manage_woocommerce, as WooCommerce's settings do,
 * so a shop manager without it sees Activity and Help only.
 */
class Edit_Orders_For_WooCommerce_Admin_Page {

	/**
	 * Page slug (the Activity page's slug before 1.2.0, so old links still work).
	 */
	const PAGE = 'edit-orders-for-woocommerce';

	/**
	 * The admin-post action that hides the getting-started card.
	 */
	const DISMISS_ACTION = 'edit_orders_for_woocommerce_dismiss_start';

	/**
	 * User meta that records the getting-started card was hidden.
	 */
	const DISMISS_META = 'edit_orders_for_woocommerce_start_dismissed';

	/**
	 * The wordpress.org slug.
	 */
	const SLUG = 'wpheka-edit-orders-for-woocommerce';

	/**
	 * The page hook WordPress returned, for loading assets on this page only.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_' . Edit_Orders_For_WooCommerce_Settings_Form::SAVE_ACTION, array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( __CLASS__, 'handle_dismiss' ) );
	}

	/**
	 * URL of a tab.
	 *
	 * @param string $tab  settings, activity or help.
	 * @param array  $args Extra query args.
	 * @return string
	 */
	public static function url( $tab = 'settings', array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- short query args.
	}

	/**
	 * Register the page under the shared WPHEKA menu, with the waiting-requests count.
	 * Staff who can edit orders but cannot open the WPHEKA parent still see the menu:
	 * WordPress points the parent at the first page they may open.
	 */
	public static function register_page() {
		$pending = Edit_Orders_For_WooCommerce_Cancellation::pending_count();
		$label   = __( 'Edit Orders', 'wpheka-edit-orders-for-woocommerce' );
		if ( $pending ) {
			$label .= ' <span class="awaiting-mod"><span class="pending-count">' . absint( $pending ) . '</span></span>';
		}

		$hook = Menu::add_page( __( 'Edit Orders', 'wpheka-edit-orders-for-woocommerce' ), $label, Edit_Orders_For_WooCommerce_Admin::CAPABILITY, self::PAGE, array( __CLASS__, 'render' ), Icons::menu_icon() );

		self::$hook = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Load the kit on this page only.
	 *
	 * @param string $hook Page hook.
	 */
	public static function enqueue( $hook ) {
		if ( '' === self::$hook || self::$hook !== $hook ) {
			return;
		}
		Page::enqueue( 'edit-orders-for-woocommerce', EDIT_ORDERS_FOR_WOOCOMMERCE_URL . 'assets/wpheka-ui/' );
	}

	/**
	 * The tab being shown. Settings needs manage_woocommerce; without it Activity opens.
	 *
	 * @return string
	 */
	public static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which tab to show.
		if ( ! in_array( $tab, array( 'settings', 'activity', 'help' ), true ) ) {
			$tab = 'settings';
		}
		if ( 'settings' === $tab && ! current_user_can( Edit_Orders_For_WooCommerce_Settings_Form::CAPABILITY ) ) {
			$tab = 'activity';
		}

		return $tab;
	}

	/**
	 * Save the settings form (admin-post).
	 */
	public static function handle_save() {
		check_admin_referer( Edit_Orders_For_WooCommerce_Settings_Form::SAVE_ACTION );
		if ( ! current_user_can( Edit_Orders_For_WooCommerce_Settings_Form::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'wpheka-edit-orders-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		Edit_Orders_For_WooCommerce_Settings_Form::save( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every field is sanitised in save().

		wp_safe_redirect( self::url( 'settings', array( 'w-saved' => 1 ) ) );
		exit;
	}

	/**
	 * Hide the getting-started card for this user (admin-post, also called by fetch()).
	 */
	public static function handle_dismiss() {
		check_admin_referer( self::DISMISS_ACTION );
		if ( current_user_can( Edit_Orders_For_WooCommerce_Admin::CAPABILITY ) ) {
			update_user_meta( get_current_user_id(), self::DISMISS_META, 1 );
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Links for the header and the help card.
	 *
	 * @return array
	 */
	private static function links() {
		return array(
			'docs'    => 'https://wordpress.org/plugins/' . self::SLUG . '/#faq',
			'support' => 'https://wordpress.org/support/plugin/' . self::SLUG . '/',
			'changes' => 'https://wordpress.org/plugins/' . self::SLUG . '/#developers',
			'review'  => 'https://wordpress.org/support/plugin/' . self::SLUG . '/reviews/#new-post',
		);
	}

	/**
	 * The page.
	 */
	public static function render() {
		if ( ! current_user_can( Edit_Orders_For_WooCommerce_Admin::CAPABILITY ) ) {
			return;
		}

		$tab   = self::current_tab();
		$links = self::links();
		$can   = current_user_can( Edit_Orders_For_WooCommerce_Settings_Form::CAPABILITY );
		$tabs  = array();
		if ( $can ) {
			$tabs[] = array( __( 'Settings', 'wpheka-edit-orders-for-woocommerce' ), self::url( 'settings' ), 'settings' === $tab );
		}
		$tabs[] = array( __( 'Activity', 'wpheka-edit-orders-for-woocommerce' ), self::url( 'activity' ), 'activity' === $tab, Edit_Orders_For_WooCommerce_Cancellation::pending_count(), true );
		$tabs[] = array( __( 'Help', 'wpheka-edit-orders-for-woocommerce' ), self::url( 'help' ), 'help' === $tab );

		Page::open(
			array(
				'icon'     => EDIT_ORDERS_FOR_WOOCOMMERCE_URL . 'assets/wpheka-ui/icons/' . self::SLUG . '.png',
				'name'     => __( 'Edit Orders for WooCommerce', 'wpheka-edit-orders-for-woocommerce' ),
				'version'  => EDIT_ORDERS_FOR_WOOCOMMERCE_VERSION,
				'badges'   => array( array( __( 'Free', 'wpheka-edit-orders-for-woocommerce' ), 'mint' ) ),
				'subtitle' => __( 'Edit paid orders and settle the difference automatically.', 'wpheka-edit-orders-for-woocommerce' ),
				'crumb'    => __( 'Edit Orders', 'wpheka-edit-orders-for-woocommerce' ),
				'links'    => array(
					array( __( 'Docs', 'wpheka-edit-orders-for-woocommerce' ), $links['docs'], 'book' ),
					array( __( 'Support', 'wpheka-edit-orders-for-woocommerce' ), $links['support'], 'chat' ),
					array( __( "What's new", 'wpheka-edit-orders-for-woocommerce' ), $links['changes'], 'sparkle' ),
				),
				'cta'      => array( __( 'Rate on WordPress.org', 'wpheka-edit-orders-for-woocommerce' ), $links['review'], 'star', 'ghost' ),
				'tabs'     => $tabs,
			)
		);

		if ( 'help' !== $tab ) {
			self::render_stats();
		}

		if ( 'settings' === $tab ) {
			self::render_settings( $links );
		} elseif ( 'activity' === $tab ) {
			Edit_Orders_For_WooCommerce_Activity::render();
		} else {
			self::render_help( $links );
		}

		Page::close(
			array(
				'slug'       => self::SLUG,
				'icons_url'  => EDIT_ORDERS_FOR_WOOCOMMERCE_URL . 'assets/wpheka-ui/icons/',
				'short_name' => __( 'Edit Orders', 'wpheka-edit-orders-for-woocommerce' ),
				'review_url' => $links['review'],
				'form'       => 'settings' === $tab ? 'edit-orders-settings' : '',
				'saved'      => 'settings' === $tab && isset( $_GET['w-saved'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag after our own redirect.
			)
		);
	}

	/**
	 * The stats strip (last 30 days).
	 */
	private static function render_stats() {
		$n = Edit_Orders_For_WooCommerce_Settings_Form::stats();

		Page::stats(
			array(
				array(
					'label' => __( 'Orders edited', 'wpheka-edit-orders-for-woocommerce' ),
					'icon'  => 'pencil',
					'value' => number_format_i18n( $n['edited'] ),
					'meta'  => __( 'Last 30 days', 'wpheka-edit-orders-for-woocommerce' ),
				),
				array(
					'label' => __( 'Refunded', 'wpheka-edit-orders-for-woocommerce' ),
					'icon'  => 'refund',
					'mint'  => true,
					'value' => wp_strip_all_tags( wc_price( $n['refunded'] ) ),
					/* translators: %s: number of refunds. */
					'meta'  => sprintf( _n( '%s refund, made automatically', '%s refunds, made automatically', $n['refunds'], 'wpheka-edit-orders-for-woocommerce' ), number_format_i18n( $n['refunds'] ) ),
				),
				array(
					'label' => __( 'Pay links', 'wpheka-edit-orders-for-woocommerce' ),
					'icon'  => 'link',
					/* translators: %s: number of paid pay links. */
					'value' => number_format_i18n( $n['links'] ) . ' <small>' . esc_html( sprintf( __( '/ %s paid', 'wpheka-edit-orders-for-woocommerce' ), number_format_i18n( $n['links_paid'] ) ) ) . '</small>',
					/* translators: %s: amount collected. */
					'meta'  => sprintf( __( '%s collected', 'wpheka-edit-orders-for-woocommerce' ), wp_strip_all_tags( wc_price( $n['collected'] ) ) ),
					'up'    => $n['collected'] > 0,
				),
				array(
					'label' => __( 'Customer changes', 'wpheka-edit-orders-for-woocommerce' ),
					'icon'  => 'user',
					'mint'  => true,
					'value' => number_format_i18n( $n['customer'] ),
					'meta'  => __( 'Made by customers themselves', 'wpheka-edit-orders-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * The getting-started card, until every step is done or it is hidden.
	 */
	private static function render_start() {
		if ( get_user_meta( get_current_user_id(), self::DISMISS_META, true ) ) {
			return;
		}
		$steps = Edit_Orders_For_WooCommerce_Settings_Form::steps();
		$done  = count( wp_list_filter( $steps, array( 'done' => true ) ) );
		if ( count( $steps ) === $done ) {
			return;
		}
		$dash = round( 94.25 * $done / count( $steps ), 1 );

		echo '<div class="w-card w-start"><svg class="w-ring" viewBox="0 0 36 36" aria-hidden="true"><circle cx="18" cy="18" r="15" fill="none" stroke="#ede9fe" stroke-width="4"/>';
		echo '<circle cx="18" cy="18" r="15" fill="none" stroke="#7c3aed" stroke-width="4" stroke-dasharray="' . esc_attr( $dash . ' 94.25' ) . '" stroke-linecap="round" transform="rotate(-90 18 18)"/>';
		echo '<text x="18" y="21.5" text-anchor="middle" font-size="9" font-weight="700" fill="#0f172a">' . esc_html( $done . '/' . count( $steps ) ) . '</text></svg>';
		echo '<div><h3>' . esc_html__( 'Getting started', 'wpheka-edit-orders-for-woocommerce' ) . '</h3><ol class="w-steps">';
		foreach ( $steps as $step ) {
			echo '<li' . ( $step['done'] ? ' class="is-done"' : '' ) . '>' . esc_html( $step['label'] ) . '<span class="w-sr"> ' . esc_html( $step['done'] ? __( '(done)', 'wpheka-edit-orders-for-woocommerce' ) : __( '(to do)', 'wpheka-edit-orders-for-woocommerce' ) ) . '</span></li>';
		}
		echo '</ol></div><div class="w-start-actions">';
		$orders = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'admin.php?page=wc-orders&status=wc-processing' : 'edit.php?post_type=shop_order&post_status=wc-processing';
		echo '<a class="w-btn w-btn--secondary w-btn--sm" href="' . esc_url( admin_url( $orders ) ) . '">' . esc_html__( 'Open an order', 'wpheka-edit-orders-for-woocommerce' ) . '</a>';
		echo '<a class="w-close" data-w-dismiss=".w-start" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION ), self::DISMISS_ACTION ) ) . '" aria-label="' . esc_attr__( 'Hide getting started', 'wpheka-edit-orders-for-woocommerce' ) . '">' . Icons::get( 'xcircle' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
		echo '</div></div>';
	}

	/**
	 * The Settings tab.
	 *
	 * @param array $links Help links.
	 */
	private static function render_settings( array $links ) {
		echo '<div class="w-stack">';
		self::render_start();
		echo '<div class="w-layout"><div>';
		Page::nav(
			array(
				array( 'editing', 'pencil', __( 'Order editing', 'wpheka-edit-orders-for-woocommerce' ) ),
				array( 'customers', 'users', __( 'Customer changes', 'wpheka-edit-orders-for-woocommerce' ) ),
				array( 'cancellations', 'xcircle', __( 'Cancellations', 'wpheka-edit-orders-for-woocommerce' ) ),
				array( 'emails', 'mail', __( 'Emails', 'wpheka-edit-orders-for-woocommerce' ) ),
				array( 'advanced', 'sliders', __( 'Advanced', 'wpheka-edit-orders-for-woocommerce' ) ),
			)
		);
		echo '</div><form id="edit-orders-settings" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-w-form>';
		echo '<input type="hidden" name="action" value="' . esc_attr( Edit_Orders_For_WooCommerce_Settings_Form::SAVE_ACTION ) . '">';
		wp_nonce_field( Edit_Orders_For_WooCommerce_Settings_Form::SAVE_ACTION );
		Edit_Orders_For_WooCommerce_Settings_Form::render();
		// Without JavaScript the save bar never appears, so the form keeps a plain button.
		echo '<noscript><p><button type="submit" class="w-btn w-btn--primary">' . esc_html__( 'Save changes', 'wpheka-edit-orders-for-woocommerce' ) . '</button></p></noscript>';
		echo '</form><aside class="w-rail" aria-label="' . esc_attr__( 'Review and help', 'wpheka-edit-orders-for-woocommerce' ) . '">';
		Page::review_card( __( 'Enjoying Edit Orders?', 'wpheka-edit-orders-for-woocommerce' ), __( 'A quick review helps other store owners find it and keeps it improving.', 'wpheka-edit-orders-for-woocommerce' ), $links['review'] );
		Page::help_card(
			array(
				array( __( 'Read the documentation', 'wpheka-edit-orders-for-woocommerce' ), $links['docs'], 'book' ),
				array( __( 'Ask in the support forum', 'wpheka-edit-orders-for-woocommerce' ), $links['support'], 'chat' ),
			)
		);
		echo '</aside></div></div>';
	}

	/**
	 * The Help tab.
	 *
	 * @param array $links Help links.
	 */
	private static function render_help( array $links ) {
		$cards = array(
			array( 'book', __( 'Documentation', 'wpheka-edit-orders-for-woocommerce' ), __( 'How each setting works, with answers to common questions.', 'wpheka-edit-orders-for-woocommerce' ), $links['docs'] ),
			array( 'chat', __( 'Support forum', 'wpheka-edit-orders-for-woocommerce' ), __( 'Ask a question. The developers who built the plugin answer.', 'wpheka-edit-orders-for-woocommerce' ), $links['support'] ),
			array( 'sparkle', __( "What's new", 'wpheka-edit-orders-for-woocommerce' ), __( 'Every change in each version, in plain words.', 'wpheka-edit-orders-for-woocommerce' ), $links['changes'] ),
		);
		echo '<div class="w-flow">';
		foreach ( $cards as $card ) {
			echo '<a class="w-card w-flow-step" href="' . esc_url( $card[3] ) . '" target="_blank" rel="noopener"><span class="w-ico w-ico--lg">' . Icons::get( $card[0] ) . '</span><h3>' . esc_html( $card[1] ) . '</h3><p>' . esc_html( $card[2] ) . '<span class="w-sr"> ' . esc_html__( '(opens in a new tab)', 'wpheka-edit-orders-for-woocommerce' ) . '</span></p></a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
		}
		echo '</div>';
	}
}
