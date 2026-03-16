<?php
/**
 * Admin UI — settings page under WooCommerce > POS Unified.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class POS_Unified_Admin {

	private static $instance = null;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
			self::$instance->hooks();
		}
		return self::$instance;
	}

	private function hooks() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_pos_unified_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_pos_unified_fetch_stores', array( $this, 'ajax_fetch_stores' ) );
		add_action( 'wp_ajax_pos_unified_trigger_sync', array( $this, 'ajax_trigger_sync' ) );
	}

	public function add_menu() {
		add_submenu_page(
			'woocommerce',
			'POS Unified',
			'POS Unified',
			'manage_woocommerce',
			'pos-unified',
			array( $this, 'render_page' )
		);
	}

	public function enqueue_assets( $hook ) {
		if ( strpos( $hook, 'pos-unified' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'pos-unified-admin',
			POS_UNIFIED_URL . 'admin/css/admin.css',
			array(),
			POS_UNIFIED_VERSION
		);

		wp_enqueue_script(
			'pos-unified-admin',
			POS_UNIFIED_URL . 'admin/js/admin.js',
			array( 'jquery' ),
			POS_UNIFIED_VERSION,
			true
		);

		wp_localize_script( 'pos-unified-admin', 'posUnified', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'pos_unified_nonce' ),
		) );
	}

	public function render_page() {
		// Handle form save
		if ( isset( $_POST['pos_unified_save'] ) && check_admin_referer( 'pos_unified_save_settings' ) ) {
			$this->save_settings();
			echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'connection';
		include POS_UNIFIED_PATH . 'admin/views/settings-page.php';
	}

	/**
	 * Save all settings manually.
	 */
	private function save_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Connection settings
		if ( isset( $_POST['pos_unified_api_url'] ) ) {
			update_option( 'pos_unified_api_url', esc_url_raw( wp_unslash( $_POST['pos_unified_api_url'] ) ) );
		}
		if ( isset( $_POST['pos_unified_api_key'] ) ) {
			update_option( 'pos_unified_api_key', sanitize_text_field( wp_unslash( $_POST['pos_unified_api_key'] ) ) );
		}
		if ( isset( $_POST['pos_unified_webhook_secret'] ) ) {
			update_option( 'pos_unified_webhook_secret', sanitize_text_field( wp_unslash( $_POST['pos_unified_webhook_secret'] ) ) );
		}
		if ( isset( $_POST['pos_unified_timeout'] ) ) {
			$timeout = absint( $_POST['pos_unified_timeout'] );
			update_option( 'pos_unified_timeout', $timeout > 0 ? $timeout : 30 );
		}

		// Debug
		update_option( 'pos_unified_debug', isset( $_POST['pos_unified_debug'] ) ? 1 : 0 );

		// Sync direction
		if ( isset( $_POST['pos_unified_sync_direction'] ) ) {
			update_option( 'pos_unified_sync_direction', sanitize_text_field( wp_unslash( $_POST['pos_unified_sync_direction'] ) ) );
		}

		// Order sync
		update_option( 'pos_unified_order_sync_enabled', isset( $_POST['pos_unified_order_sync_enabled'] ) ? 1 : 0 );
		update_option( 'pos_unified_pull_pos_orders', isset( $_POST['pos_unified_pull_pos_orders'] ) ? 1 : 0 );

		if ( isset( $_POST['pos_unified_default_store'] ) ) {
			update_option( 'pos_unified_default_store', sanitize_text_field( wp_unslash( $_POST['pos_unified_default_store'] ) ) );
		}

		// Store map
		if ( isset( $_POST['pos_unified_store_map'] ) ) {
			$map_data = wp_unslash( $_POST['pos_unified_store_map'] );
			if ( is_string( $map_data ) ) {
				$map_data = json_decode( $map_data, true );
			}
			if ( is_array( $map_data ) ) {
				update_option( 'pos_unified_store_map', $map_data );
			}
		}
	}

	// ── AJAX Handlers ─────────────────────────────────────────────

	public function ajax_test_connection() {
		check_ajax_referer( 'pos_unified_nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$client = new POS_Unified_API_Client();

		if ( ! $client->is_configured() ) {
			wp_send_json_error( 'API URL or API key not configured. Please save settings first.' );
		}

		$result = $client->test_connection();

		if ( $result['success'] ) {
			wp_send_json_success( $result['data'] );
		} else {
			wp_send_json_error( isset( $result['error'] ) ? $result['error'] : 'Connection failed.' );
		}
	}

	public function ajax_fetch_stores() {
		check_ajax_referer( 'pos_unified_nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$client = new POS_Unified_API_Client();

		if ( ! $client->is_configured() ) {
			wp_send_json_error( 'API not configured. Save your API URL and Key on the Connection tab first.' );
		}

		$result = $client->get_stores();

		if ( $result['success'] ) {
			set_transient( 'pos_unified_diacos_stores', $result['data'], HOUR_IN_SECONDS );
			wp_send_json_success( $result['data'] );
		} else {
			wp_send_json_error( isset( $result['error'] ) ? $result['error'] : 'Failed to fetch stores.' );
		}
	}

	public function ajax_trigger_sync() {
		check_ajax_referer( 'pos_unified_nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$type = isset( $_POST['sync_type'] ) ? sanitize_text_field( wp_unslash( $_POST['sync_type'] ) ) : 'inventory';

		if ( $type === 'inventory' ) {
			POS_Unified_Inventory_Sync::instance()->run_sync();
		} elseif ( $type === 'orders' ) {
			POS_Unified_Order_Sync::instance()->pull_status_updates();
		}

		$last_sync = get_option( "pos_unified_last_{$type}_sync", array() );
		wp_send_json_success( $last_sync );
	}
}
