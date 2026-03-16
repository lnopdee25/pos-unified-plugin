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
		add_action( 'admin_init', array( $this, 'register_settings' ) );
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

	public function register_settings() {
		register_setting( 'pos_unified_settings', 'pos_unified_api_url', array(
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
		) );
		register_setting( 'pos_unified_settings', 'pos_unified_api_key', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		) );
		register_setting( 'pos_unified_settings', 'pos_unified_timeout', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 30,
		) );
		register_setting( 'pos_unified_settings', 'pos_unified_webhook_secret', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		) );
		register_setting( 'pos_unified_settings', 'pos_unified_sync_direction', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'diacos_to_wc',
		) );
		register_setting( 'pos_unified_settings', 'pos_unified_order_sync_enabled', array(
			'type'              => 'boolean',
			'sanitize_callback' => array( $this, 'sanitize_bool' ),
		) );
		register_setting( 'pos_unified_settings', 'pos_unified_default_store', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		) );
		register_setting( 'pos_unified_settings', 'pos_unified_debug', array(
			'type'              => 'boolean',
			'sanitize_callback' => array( $this, 'sanitize_bool' ),
		) );
		register_setting( 'pos_unified_settings', 'pos_unified_store_map', array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize_store_map' ),
		) );
	}

	/**
	 * Sanitize boolean values safely.
	 */
	public function sanitize_bool( $value ) {
		return (bool) $value;
	}

	/**
	 * Sanitize store map — accepts JSON string or array.
	 */
	public function sanitize_store_map( $value ) {
		if ( is_string( $value ) ) {
			$value = json_decode( $value, true );
		}
		return is_array( $value ) ? $value : array();
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
		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'connection';
		include POS_UNIFIED_PATH . 'admin/views/settings-page.php';
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
			wp_send_json_error( 'API not configured.' );
		}

		$result = $client->get_stores();

		if ( $result['success'] ) {
			// Cache for transient use in settings page
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
