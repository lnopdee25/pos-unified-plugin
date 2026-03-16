<?php
/**
 * API Client — authenticates with Bearer token API keys.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class POS_Unified_API_Client {

	/** @var string */
	private $base_url;

	/** @var string */
	private $api_key;

	/** @var int */
	private $timeout;

	public function __construct( $base_url = null, $api_key = null ) {
		$this->base_url = rtrim( $base_url ? $base_url : get_option( 'pos_unified_api_url', '' ), '/' );
		$this->api_key  = $api_key ? $api_key : get_option( 'pos_unified_api_key', '' );
		$this->timeout  = (int) get_option( 'pos_unified_timeout', 30 );
	}

	/**
	 * Test the connection.
	 */
	public function test_connection() {
		return $this->get( '/api/integrations/wc/config' );
	}

	// ── Products / Inventory ──────────────────────────────────────

	public function get_products( $store_id, $params = array() ) {
		$params['storeId'] = $store_id;
		return $this->get( '/api/products', $params );
	}

	public function get_product_by_sku( $store_id, $sku ) {
		return $this->get( '/api/products', array(
			'storeId' => $store_id,
			'search'  => $sku,
			'limit'   => 1,
		) );
	}

	public function update_stock( $store_id, $product_id, $quantity, $reason = 'wc_sync' ) {
		return $this->post( '/api/inventory', array(
			'storeId'   => $store_id,
			'productId' => $product_id,
			'type'      => 'adjustment',
			'quantity'  => $quantity,
			'reference' => 'WooCommerce Sync',
			'notes'     => $reason,
		) );
	}

	// ── Orders ────────────────────────────────────────────────────

	public function get_orders( $store_id, $params = array() ) {
		$params['storeId'] = $store_id;
		return $this->get( '/api/orders', $params );
	}

	public function create_order( $store_id, $order_data ) {
		$order_data['storeId'] = $store_id;
		return $this->post( '/api/orders', $order_data );
	}

	public function update_order_status( $order_id, $status ) {
		return $this->put( "/api/orders/{$order_id}", array(
			'status' => $status,
		) );
	}

	// ── Stores ────────────────────────────────────────────────────

	public function get_stores() {
		return $this->get( '/api/integrations/wc/stores' );
	}

	// ── HTTP helpers ──────────────────────────────────────────────

	public function get( $endpoint, $query = array() ) {
		$url = $this->base_url . $endpoint;
		if ( ! empty( $query ) ) {
			$url .= '?' . http_build_query( $query );
		}
		return $this->request( 'GET', $url );
	}

	public function post( $endpoint, $body = array() ) {
		return $this->request( 'POST', $this->base_url . $endpoint, $body );
	}

	public function put( $endpoint, $body = array() ) {
		return $this->request( 'PUT', $this->base_url . $endpoint, $body );
	}

	private function request( $method, $url, $body = null ) {
		if ( empty( $this->base_url ) || empty( $this->api_key ) ) {
			return array(
				'success' => false,
				'error'   => 'API URL or API key not configured.',
			);
		}

		$args = array(
			'method'  => $method,
			'timeout' => $this->timeout,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
		);

		if ( $body !== null ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->log( 'error', $method . ' ' . $url, $response->get_error_message() );
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			$msg = '';
			if ( is_array( $data ) ) {
				if ( isset( $data['error']['message'] ) ) {
					$msg = $data['error']['message'];
				} elseif ( isset( $data['message'] ) ) {
					$msg = $data['message'];
				}
			}
			if ( empty( $msg ) ) {
				$msg = "HTTP {$code}";
			}
			$this->log( 'error', $method . ' ' . $url . " → {$code}", $msg );
			return array(
				'success' => false,
				'code'    => $code,
				'error'   => $msg,
				'data'    => $data,
			);
		}

		return array(
			'success' => true,
			'code'    => $code,
			'data'    => ( is_array( $data ) && isset( $data['data'] ) ) ? $data['data'] : $data,
		);
	}

	private function log( $level, $context, $message ) {
		if ( get_option( 'pos_unified_debug', false ) ) {
			error_log( "[POS Unified] [{$level}] {$context}: {$message}" );
		}
	}

	public function is_configured() {
		return ! empty( $this->base_url ) && ! empty( $this->api_key );
	}
}
