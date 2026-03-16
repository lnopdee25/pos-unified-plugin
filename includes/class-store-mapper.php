<?php
/**
 * Maps WC Multi-Location Inventory locations to Diacos stores.
 *
 * Supports:
 *   - WC MLI plugin (custom table: wp_wc_mli_locations)
 *   - WC Multi-Location taxonomy (taxonomy: location)
 *   - ATUM Multi-Inventory (taxonomy: atum_location)
 *   - Fallback: single default location
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class POS_Unified_Store_Mapper {

	private static $instance = null;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Get the full mapping array.
	 */
	public function get_mappings() {
		$mappings = get_option( 'pos_unified_store_map', array() );
		return is_array( $mappings ) ? $mappings : array();
	}

	/**
	 * Save mappings.
	 */
	public function save_mappings( $mappings ) {
		update_option( 'pos_unified_store_map', $mappings );
	}

	/**
	 * Get the Diacos store ID for a WC location.
	 */
	public function get_diacos_store_id( $wc_location_id ) {
		foreach ( $this->get_mappings() as $map ) {
			if ( (string) $map['wc_location_id'] === (string) $wc_location_id && ! empty( $map['enabled'] ) ) {
				return $map['diacos_store_id'];
			}
		}
		return null;
	}

	/**
	 * Get the WC location ID for a Diacos store.
	 */
	public function get_wc_location_id( $diacos_store_id ) {
		foreach ( $this->get_mappings() as $map ) {
			if ( isset( $map['diacos_store_id'] ) && $map['diacos_store_id'] === $diacos_store_id && ! empty( $map['enabled'] ) ) {
				return isset( $map['wc_location_id'] ) ? $map['wc_location_id'] : null;
			}
		}
		return null;
	}

	/**
	 * Get all enabled Diacos store IDs.
	 */
	public function get_enabled_store_ids() {
		$ids = array();
		foreach ( $this->get_mappings() as $map ) {
			if ( ! empty( $map['enabled'] ) && ! empty( $map['diacos_store_id'] ) ) {
				$ids[] = $map['diacos_store_id'];
			}
		}
		return $ids;
	}

	/**
	 * Detect WC locations from all supported sources.
	 */
	public function get_wc_locations() {
		$locations = array();

		// 1. Check for WC Multi-Location Inventory plugin (custom DB table)
		$locations = array_merge( $locations, $this->get_wc_mli_locations() );

		// 2. Check for WC Multi-Location taxonomy
		if ( taxonomy_exists( 'location' ) ) {
			$terms = get_terms( array(
				'taxonomy'   => 'location',
				'hide_empty' => false,
			) );
			if ( ! is_wp_error( $terms ) && is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$locations[] = array(
						'id'     => 'tax_' . $term->term_id,
						'name'   => $term->name,
						'slug'   => $term->slug,
						'source' => 'wc-taxonomy',
					);
				}
			}
		}

		// 3. Check for ATUM Multi-Inventory locations
		if ( taxonomy_exists( 'atum_location' ) ) {
			$terms = get_terms( array(
				'taxonomy'   => 'atum_location',
				'hide_empty' => false,
			) );
			if ( ! is_wp_error( $terms ) && is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$locations[] = array(
						'id'     => 'atum_' . $term->term_id,
						'name'   => $term->name,
						'slug'   => $term->slug,
						'source' => 'atum',
					);
				}
			}
		}

		// 4. Fallback: single-location mode
		if ( empty( $locations ) ) {
			$locations[] = array(
				'id'     => 'default',
				'name'   => get_bloginfo( 'name' ) . ' (Default)',
				'slug'   => 'default',
				'source' => 'single',
			);
		}

		return $locations;
	}

	/**
	 * Get locations from WC Multi-Location Inventory plugin (wp_wc_mli_locations table).
	 */
	private function get_wc_mli_locations() {
		global $wpdb;

		$locations = array();
		$table     = $wpdb->prefix . 'wc_mli_locations';

		// Check if the table exists
		$table_exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(1) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
			DB_NAME,
			$table
		) );

		if ( ! $table_exists ) {
			return $locations;
		}

		$results = $wpdb->get_results(
			"SELECT id, name, slug, city, state, is_active FROM {$table} WHERE is_active = 1 ORDER BY sort_order ASC, id ASC",
			ARRAY_A
		);

		if ( ! empty( $results ) && is_array( $results ) ) {
			foreach ( $results as $row ) {
				$display_name = $row['name'];
				if ( ! empty( $row['city'] ) ) {
					$display_name .= ' (' . $row['city'];
					if ( ! empty( $row['state'] ) ) {
						$display_name .= ', ' . $row['state'];
					}
					$display_name .= ')';
				}

				$locations[] = array(
					'id'     => 'mli_' . $row['id'],
					'name'   => $display_name,
					'slug'   => $row['slug'],
					'source' => 'wc-mli',
				);
			}
		}

		return $locations;
	}
}
