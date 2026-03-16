<?php
/**
 * Inventory Sync — bidirectional stock sync between WC and Diacos.
 *
 * Products matched by SKU. Stock synced per-location using store mapper.
 * Supports WC MLI plugin (wp_wc_mli_inventory table) and fallback to WC core stock.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class POS_Unified_Inventory_Sync {

	private static $instance = null;

	/** @var bool Prevents infinite loops during sync */
	private $is_syncing = false;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
			self::$instance->hooks();
		}
		return self::$instance;
	}

	private function hooks() {
		// Cron-based full sync
		add_action( 'pos_unified_inventory_sync', array( $this, 'run_sync' ) );

		// Real-time: WC stock change -> push to Diacos
		add_action( 'woocommerce_product_set_stock', array( $this, 'on_wc_stock_change' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'on_wc_stock_change' ) );
	}

	/**
	 * Cron: full inventory sync for all mapped stores.
	 */
	public function run_sync() {
		$client = new POS_Unified_API_Client();
		if ( ! $client->is_configured() ) {
			return;
		}

		$mapper    = POS_Unified_Store_Mapper::instance();
		$direction = get_option( 'pos_unified_sync_direction', 'diacos_to_wc' );
		$store_ids = $mapper->get_enabled_store_ids();

		if ( empty( $store_ids ) ) {
			$this->log( 'No enabled store mappings found. Skipping sync.' );
			return;
		}

		$synced = 0;
		$errors = 0;

		$this->is_syncing = true;

		foreach ( $store_ids as $diacos_store_id ) {
			$wc_location_id = $mapper->get_wc_location_id( $diacos_store_id );
			if ( ! $wc_location_id ) {
				continue;
			}

			$page = 1;
			do {
				$result = $client->get_products( $diacos_store_id, array(
					'page'  => $page,
					'limit' => 100,
				) );

				if ( ! $result['success'] ) {
					$errors++;
					$this->log( "Failed to fetch products for store {$diacos_store_id} page {$page}: " . $result['error'] );
					break;
				}

				$products = array();
				if ( is_array( $result['data'] ) ) {
					$products = isset( $result['data']['data'] ) ? $result['data']['data'] : $result['data'];
				}
				if ( ! is_array( $products ) ) {
					$products = array();
				}

				$pagination = isset( $result['data']['pagination'] ) ? $result['data']['pagination'] : null;

				foreach ( $products as $diacos_product ) {
					$sku = isset( $diacos_product['sku'] ) ? $diacos_product['sku'] : '';
					if ( empty( $sku ) ) {
						continue;
					}

					$wc_product_id = wc_get_product_id_by_sku( $sku );
					if ( ! $wc_product_id ) {
						continue;
					}

					$wc_product = wc_get_product( $wc_product_id );
					if ( ! $wc_product ) {
						continue;
					}

					$diacos_stock = (int) ( isset( $diacos_product['stockOnHand'] ) ? $diacos_product['stockOnHand'] : 0 );
					$wc_stock     = (int) $this->get_location_stock( $wc_product, $wc_location_id );

					if ( $diacos_stock === $wc_stock ) {
						continue;
					}

					if ( $direction === 'diacos_to_wc' || $direction === 'bidirectional' ) {
						$this->set_location_stock( $wc_product, $wc_location_id, $diacos_stock );
						$synced++;
					}

					if ( $direction === 'wc_to_diacos' ) {
						$diff = $wc_stock - $diacos_stock;
						$diacos_id = isset( $diacos_product['id'] ) ? $diacos_product['id'] : '';
						if ( ! empty( $diacos_id ) ) {
							$client->update_stock( $diacos_store_id, $diacos_id, $diff, 'WC inventory sync' );
							$synced++;
						}
					}
				}

				$page++;
				$has_more = $pagination && $page <= ( isset( $pagination['totalPages'] ) ? $pagination['totalPages'] : 1 );
			} while ( $has_more );
		}

		$this->is_syncing = false;

		update_option( 'pos_unified_last_inventory_sync', array(
			'time'   => current_time( 'mysql' ),
			'synced' => $synced,
			'errors' => $errors,
		) );

		$this->log( "Inventory sync complete: {$synced} synced, {$errors} errors" );
	}

	/**
	 * Real-time: push WC stock change to Diacos.
	 */
	public function on_wc_stock_change( $product ) {
		if ( $this->is_syncing ) {
			return;
		}

		$direction = get_option( 'pos_unified_sync_direction', 'diacos_to_wc' );
		if ( $direction === 'diacos_to_wc' ) {
			return;
		}

		$sku = $product->get_sku();
		if ( empty( $sku ) ) {
			return;
		}

		$client = new POS_Unified_API_Client();
		if ( ! $client->is_configured() ) {
			return;
		}

		$mapper    = POS_Unified_Store_Mapper::instance();
		$store_ids = $mapper->get_enabled_store_ids();

		foreach ( $store_ids as $diacos_store_id ) {
			$result = $client->get_product_by_sku( $diacos_store_id, $sku );
			if ( ! $result['success'] ) {
				continue;
			}

			$products = array();
			if ( is_array( $result['data'] ) ) {
				$products = isset( $result['data']['data'] ) ? $result['data']['data'] : $result['data'];
			}
			if ( empty( $products ) || ! is_array( $products ) ) {
				continue;
			}

			$diacos_product = $products[0];
			$wc_location_id = $mapper->get_wc_location_id( $diacos_store_id );
			$wc_stock       = (int) $this->get_location_stock( $product, $wc_location_id );
			$diacos_stock   = (int) ( isset( $diacos_product['stockOnHand'] ) ? $diacos_product['stockOnHand'] : 0 );
			$diff           = $wc_stock - $diacos_stock;

			if ( $diff === 0 ) {
				continue;
			}

			$diacos_id = isset( $diacos_product['id'] ) ? $diacos_product['id'] : '';
			if ( ! empty( $diacos_id ) ) {
				$client->update_stock( $diacos_store_id, $diacos_id, $diff, 'WC real-time stock change' );
			}
		}
	}

	/**
	 * Get stock for a specific WC location.
	 * Supports: WC MLI table, post meta, and fallback to core stock.
	 */
	private function get_location_stock( $product, $location_id ) {
		// Default/single location — use WC core stock
		if ( $location_id === 'default' ) {
			return (int) $product->get_stock_quantity();
		}

		// WC MLI plugin (location ID format: mli_123)
		if ( strpos( $location_id, 'mli_' ) === 0 ) {
			$mli_location_id = (int) str_replace( 'mli_', '', $location_id );
			return $this->get_mli_stock( $product->get_id(), $mli_location_id );
		}

		// Taxonomy-based location (post meta fallback)
		$location_stock = get_post_meta( $product->get_id(), "_stock_location_{$location_id}", true );
		if ( $location_stock !== '' && $location_stock !== false ) {
			return (int) $location_stock;
		}

		return (int) $product->get_stock_quantity();
	}

	/**
	 * Set stock for a specific WC location.
	 */
	private function set_location_stock( $product, $location_id, $quantity ) {
		$this->is_syncing = true;

		if ( $location_id === 'default' ) {
			wc_update_product_stock( $product, $quantity, 'set' );
			$this->is_syncing = false;
			return;
		}

		// WC MLI plugin
		if ( strpos( $location_id, 'mli_' ) === 0 ) {
			$mli_location_id = (int) str_replace( 'mli_', '', $location_id );
			$this->set_mli_stock( $product->get_id(), $mli_location_id, $quantity );
			$this->is_syncing = false;
			return;
		}

		// Taxonomy-based fallback
		update_post_meta( $product->get_id(), "_stock_location_{$location_id}", $quantity );
		$this->recalculate_total_stock( $product );

		$this->is_syncing = false;
	}

	/**
	 * Get stock from WC MLI inventory table.
	 */
	private function get_mli_stock( $product_id, $location_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'wc_mli_inventory';
		$stock = $wpdb->get_var( $wpdb->prepare(
			"SELECT stock_quantity FROM {$table} WHERE product_id = %d AND location_id = %d",
			$product_id,
			$location_id
		) );

		return $stock !== null ? (int) $stock : 0;
	}

	/**
	 * Set stock in WC MLI inventory table.
	 * Uses the WC_MLI_Inventory class if available, otherwise direct DB update.
	 */
	private function set_mli_stock( $product_id, $location_id, $quantity ) {
		// Use MLI's own method if class exists (handles stock status, caching, etc.)
		if ( class_exists( 'WC_MLI_Inventory' ) && method_exists( 'WC_MLI_Inventory', 'update_stock' ) ) {
			WC_MLI_Inventory::update_stock( $product_id, $location_id, $quantity, 'set' );
			return;
		}

		// Direct DB fallback
		global $wpdb;
		$table = $wpdb->prefix . 'wc_mli_inventory';

		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE product_id = %d AND location_id = %d",
			$product_id,
			$location_id
		) );

		if ( $exists ) {
			$wpdb->update(
				$table,
				array(
					'stock_quantity' => $quantity,
					'stock_status'   => $quantity > 0 ? 'instock' : 'outofstock',
					'updated_at'     => current_time( 'mysql' ),
				),
				array(
					'product_id'  => $product_id,
					'location_id' => $location_id,
				),
				array( '%d', '%s', '%s' ),
				array( '%d', '%d' )
			);
		} else {
			$wpdb->insert(
				$table,
				array(
					'product_id'     => $product_id,
					'location_id'    => $location_id,
					'stock_quantity' => $quantity,
					'stock_status'   => $quantity > 0 ? 'instock' : 'outofstock',
					'manage_stock'   => 1,
					'backorders'     => 'no',
					'updated_at'     => current_time( 'mysql' ),
				),
				array( '%d', '%d', '%d', '%s', '%d', '%s', '%s' )
			);
		}
	}

	/**
	 * Recalculate total stock from all location metas (taxonomy-based only).
	 */
	private function recalculate_total_stock( $product ) {
		global $wpdb;

		$total = $wpdb->get_var( $wpdb->prepare(
			"SELECT SUM(CAST(meta_value AS SIGNED)) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
			$product->get_id(),
			'_stock_location_%'
		) );

		if ( $total !== null ) {
			wc_update_product_stock( $product, (int) $total, 'set' );
		}
	}

	private function log( $message ) {
		if ( get_option( 'pos_unified_debug', false ) ) {
			error_log( '[POS Unified] [inventory] ' . $message );
		}
	}
}
