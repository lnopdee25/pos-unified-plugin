<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$tabs = array(
	'connection' => 'Connection',
	'stores'     => 'Store Mapping',
	'inventory'  => 'Inventory Sync',
	'orders'     => 'Order Sync',
	'logs'       => 'Status & Logs',
);
?>
<div class="wrap pos-unified-wrap">
	<h1>POS Unified &mdash; Diacos Integration</h1>

	<nav class="nav-tab-wrapper">
		<?php foreach ( $tabs as $slug => $label ) : ?>
			<a href="?page=pos-unified&tab=<?php echo esc_attr( $slug ); ?>"
			   class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
				<?php echo esc_html( $label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<form method="post" id="pos-unified-form">
		<?php wp_nonce_field( 'pos_unified_save_settings' ); ?>
		<input type="hidden" name="pos_unified_save" value="1" />

		<?php if ( $active_tab === 'connection' ) : ?>
			<!-- CONNECTION TAB -->
			<table class="form-table">
				<tr>
					<th>Diacos API URL</th>
					<td>
						<input type="url" name="pos_unified_api_url"
							   value="<?php echo esc_attr( get_option( 'pos_unified_api_url', '' ) ); ?>"
							   class="regular-text" placeholder="https://your-app.workers.dev" />
						<p class="description">The base URL of your Diacos POS instance.</p>
					</td>
				</tr>
				<tr>
					<th>API Key</th>
					<td>
						<input type="text" name="pos_unified_api_key"
							   value="<?php echo esc_attr( get_option( 'pos_unified_api_key', '' ) ); ?>"
							   class="regular-text" placeholder="dk_live_..." />
						<p class="description">Generate this in Diacos &gt; Settings &gt; Integrations &gt; API Keys.</p>
					</td>
				</tr>
				<tr>
					<th>Webhook Secret</th>
					<td>
						<input type="text" name="pos_unified_webhook_secret"
							   value="<?php echo esc_attr( get_option( 'pos_unified_webhook_secret', '' ) ); ?>"
							   class="regular-text" />
						<p class="description">Shared secret for webhook signature verification. Webhook URL: <code><?php echo esc_url( rest_url( 'pos-unified/v1/webhook' ) ); ?></code></p>
					</td>
				</tr>
				<tr>
					<th>Timeout (seconds)</th>
					<td>
						<input type="number" name="pos_unified_timeout"
							   value="<?php echo esc_attr( get_option( 'pos_unified_timeout', 30 ) ); ?>"
							   min="5" max="120" class="small-text" />
					</td>
				</tr>
				<tr>
					<th>Debug Logging</th>
					<td>
						<label>
							<input type="checkbox" name="pos_unified_debug" value="1"
								<?php checked( get_option( 'pos_unified_debug', 0 ), 1 ); ?> />
							Enable debug logging to <code>wp-content/debug.log</code>
						</label>
					</td>
				</tr>
				<tr>
					<th>Test Connection</th>
					<td>
						<button type="button" class="button" id="pos-unified-test-btn">Test Connection</button>
						<span id="pos-unified-test-result" style="margin-left: 10px;"></span>
					</td>
				</tr>
			</table>

		<?php elseif ( $active_tab === 'stores' ) : ?>
			<!-- STORE MAPPING TAB -->
			<?php
			$mapper       = POS_Unified_Store_Mapper::instance();
			$wc_locations = $mapper->get_wc_locations();
			$mappings     = $mapper->get_mappings();

			$map_index = array();
			foreach ( $mappings as $m ) {
				if ( isset( $m['wc_location_id'] ) ) {
					$map_index[ (string) $m['wc_location_id'] ] = $m;
				}
			}
			?>
			<p>Map your WooCommerce locations to Diacos stores. <button type="button" class="button" id="pos-unified-fetch-stores-btn">Refresh Diacos Stores</button></p>
			<?php
			$cached_stores = get_transient( 'pos_unified_diacos_stores' );
			if ( ! is_array( $cached_stores ) ) {
				$cached_stores = array();
			}
			?>
			<div id="pos-unified-stores-cache" style="display:none;" data-stores='<?php echo esc_attr( wp_json_encode( $cached_stores ) ); ?>'></div>

			<table class="widefat" id="pos-unified-store-map-table">
				<thead>
					<tr>
						<th>WC Location</th>
						<th>Source</th>
						<th>Diacos Store</th>
						<th>Enabled</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $wc_locations as $loc ) :
						$loc_id  = (string) $loc['id'];
						$current = isset( $map_index[ $loc_id ] ) ? $map_index[ $loc_id ] : array();
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( $loc['name'] ); ?></strong>
							<input type="hidden" name="pos_unified_store_map_wc[]" value="<?php echo esc_attr( $loc_id ); ?>" />
						</td>
						<td><code><?php echo esc_html( $loc['source'] ); ?></code></td>
						<td>
							<select name="pos_unified_store_map_diacos[]" class="diacos-store-select">
								<option value="">-- Not mapped --</option>
							</select>
							<input type="hidden" class="diacos-store-saved" value="<?php echo esc_attr( isset( $current['diacos_store_id'] ) ? $current['diacos_store_id'] : '' ); ?>" />
						</td>
						<td>
							<input type="checkbox" name="pos_unified_store_map_enabled[]" value="<?php echo esc_attr( $loc_id ); ?>"
								<?php checked( ! empty( $current['enabled'] ) ); ?> />
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

		<?php elseif ( $active_tab === 'inventory' ) : ?>
			<!-- INVENTORY TAB -->
			<table class="form-table">
				<tr>
					<th>Sync Direction</th>
					<td>
						<?php $direction = get_option( 'pos_unified_sync_direction', 'diacos_to_wc' ); ?>
						<select name="pos_unified_sync_direction">
							<option value="diacos_to_wc" <?php selected( $direction, 'diacos_to_wc' ); ?>>Diacos &rarr; WooCommerce (POS is source of truth)</option>
							<option value="wc_to_diacos" <?php selected( $direction, 'wc_to_diacos' ); ?>>WooCommerce &rarr; Diacos (WC is source of truth)</option>
							<option value="bidirectional" <?php selected( $direction, 'bidirectional' ); ?>>Bidirectional (last write wins)</option>
						</select>
					</td>
				</tr>
				<tr>
					<th>Manual Sync</th>
					<td>
						<button type="button" class="button" id="pos-unified-sync-inventory-btn">Run Inventory Sync Now</button>
						<span id="pos-unified-sync-inventory-result" style="margin-left: 10px;"></span>
					</td>
				</tr>
				<tr>
					<th>Last Sync</th>
					<td>
						<?php
						$last = get_option( 'pos_unified_last_inventory_sync', array() );
						if ( ! empty( $last ) && is_array( $last ) ) {
							printf( '%s &mdash; %d synced, %d errors',
								esc_html( isset( $last['time'] ) ? $last['time'] : 'Never' ),
								isset( $last['synced'] ) ? (int) $last['synced'] : 0,
								isset( $last['errors'] ) ? (int) $last['errors'] : 0
							);
						} else {
							echo 'Never';
						}
						?>
					</td>
				</tr>
			</table>

		<?php elseif ( $active_tab === 'orders' ) : ?>
			<!-- ORDERS TAB -->
			<table class="form-table">
				<tr>
					<th>Enable Order Sync</th>
					<td>
						<label>
							<input type="checkbox" name="pos_unified_order_sync_enabled" value="1"
								<?php checked( get_option( 'pos_unified_order_sync_enabled', 0 ), 1 ); ?> />
							Push WooCommerce orders to Diacos POS
						</label>
					</td>
				</tr>
				<tr>
					<th>Default Diacos Store</th>
					<td>
						<select name="pos_unified_default_store" class="diacos-store-select">
							<option value="">-- Auto (first mapped store) --</option>
						</select>
						<input type="hidden" class="diacos-store-saved" value="<?php echo esc_attr( get_option( 'pos_unified_default_store', '' ) ); ?>" />
						<p class="description">Which Diacos store receives online orders.</p>
					</td>
				</tr>
				<tr>
					<th>Manual Sync</th>
					<td>
						<button type="button" class="button" id="pos-unified-sync-orders-btn">Pull Order Status Updates</button>
						<span id="pos-unified-sync-orders-result" style="margin-left: 10px;"></span>
					</td>
				</tr>
				<tr>
					<th>Last Sync</th>
					<td><?php echo esc_html( get_option( 'pos_unified_last_order_sync', 'Never' ) ); ?></td>
				</tr>
			</table>

		<?php elseif ( $active_tab === 'logs' ) : ?>
			<!-- LOGS TAB -->
			<h2>Sync Status</h2>
			<table class="widefat">
				<tr>
					<td><strong>Plugin Version</strong></td>
					<td><?php echo esc_html( POS_UNIFIED_VERSION ); ?></td>
				</tr>
				<tr>
					<td><strong>API Connected</strong></td>
					<td>
						<?php
						$client = new POS_Unified_API_Client();
						echo $client->is_configured() ? 'Configured' : 'Not configured';
						?>
					</td>
				</tr>
				<tr>
					<td><strong>API URL</strong></td>
					<td><code><?php echo esc_html( get_option( 'pos_unified_api_url', 'Not set' ) ); ?></code></td>
				</tr>
				<tr>
					<td><strong>Mapped Stores</strong></td>
					<td><?php echo count( POS_Unified_Store_Mapper::instance()->get_enabled_store_ids() ); ?></td>
				</tr>
				<tr>
					<td><strong>Inventory Cron</strong></td>
					<td><?php echo wp_next_scheduled( 'pos_unified_inventory_sync' ) ? 'Scheduled' : 'Not scheduled'; ?></td>
				</tr>
				<tr>
					<td><strong>Order Cron</strong></td>
					<td><?php echo wp_next_scheduled( 'pos_unified_order_sync' ) ? 'Scheduled' : 'Not scheduled'; ?></td>
				</tr>
				<tr>
					<td><strong>Webhook URL</strong></td>
					<td><code><?php echo esc_url( rest_url( 'pos-unified/v1/webhook' ) ); ?></code></td>
				</tr>
				<tr>
					<td><strong>Last Inventory Sync</strong></td>
					<td>
						<?php
						$last = get_option( 'pos_unified_last_inventory_sync', array() );
						if ( ! empty( $last ) && is_array( $last ) ) {
							$time = isset( $last['time'] ) ? $last['time'] : 'Never';
							$synced_count = isset( $last['synced'] ) ? (int) $last['synced'] : 0;
							$error_count = isset( $last['errors'] ) ? (int) $last['errors'] : 0;
							echo esc_html( "{$time} - {$synced_count} synced, {$error_count} errors" );
						} else {
							echo 'Never';
						}
						?>
					</td>
				</tr>
				<tr>
					<td><strong>Last Order Sync</strong></td>
					<td><?php echo esc_html( get_option( 'pos_unified_last_order_sync', 'Never' ) ); ?></td>
				</tr>
			</table>
		<?php endif; ?>

		<?php if ( $active_tab !== 'logs' ) : ?>
			<?php submit_button( 'Save Changes' ); ?>
		<?php endif; ?>
	</form>
</div>
