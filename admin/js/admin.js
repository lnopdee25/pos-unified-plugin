(function ($) {
	'use strict';

	var diacosStores = [];

	function loadDiacosStores(forceRefresh) {
		var cached = $('#pos-unified-stores-cache').data('stores');
		if (!forceRefresh && cached && cached.length) {
			diacosStores = cached;
			populateSelects();
			return;
		}

		console.log('[POS Unified] Fetching Diacos stores...');

		$.ajax({
			url: posUnified.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'pos_unified_fetch_stores',
				_ajax_nonce: posUnified.nonce
			},
			success: function (res) {
				console.log('[POS Unified] Fetch stores response:', res);
				if (res.success && res.data) {
					diacosStores = Array.isArray(res.data) ? res.data : (res.data.data || []);
					populateSelects();
				} else {
					alert('Failed to fetch stores: ' + (res.data || 'Unknown error'));
				}
			},
			error: function (xhr, status, error) {
				console.error('[POS Unified] Fetch stores AJAX error:', status, error, xhr.responseText);
				alert('AJAX error fetching stores: ' + error + '\n\nCheck browser console for details.');
			}
		});
	}

	function populateSelects() {
		console.log('[POS Unified] Populating selects with', diacosStores.length, 'stores');

		$('.diacos-store-select').each(function () {
			var $select = $(this);
			var saved = $select.siblings('.diacos-store-saved').val() || '';
			var firstOpt = $select.find('option:first').clone();

			$select.empty().append(firstOpt);

			$.each(diacosStores, function (_, store) {
				var id = store.id || store.storeId || '';
				var name = store.name || store.storeName || id;
				var code = store.code || '';
				var label = code ? name + ' (' + code + ')' : name;
				$select.append(
					$('<option>').val(id).text(label).prop('selected', String(id) === String(saved))
				);
			});
		});
	}

	// Test connection
	$(document).on('click', '#pos-unified-test-btn', function () {
		var $btn = $(this);
		var $result = $('#pos-unified-test-result');
		$btn.prop('disabled', true);
		$result.text('Testing...');

		console.log('[POS Unified] Testing connection...');

		$.ajax({
			url: posUnified.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'pos_unified_test_connection',
				_ajax_nonce: posUnified.nonce
			},
			success: function (res) {
				console.log('[POS Unified] Test connection response:', res);
				$btn.prop('disabled', false);
				if (res.success) {
					$result.html('<span style="color:green">Connected successfully!</span>');
				} else {
					$result.html('<span style="color:red">Failed: ' + (res.data || 'Unknown error') + '</span>');
				}
			},
			error: function (xhr, status, error) {
				console.error('[POS Unified] Test connection AJAX error:', status, error, xhr.responseText);
				$btn.prop('disabled', false);
				$result.html('<span style="color:red">Request failed: ' + error + '</span>');
			}
		});
	});

	// Refresh Diacos stores
	$(document).on('click', '#pos-unified-fetch-stores-btn', function () {
		loadDiacosStores(true);
	});

	// Trigger inventory sync
	$(document).on('click', '#pos-unified-sync-inventory-btn', function () {
		var $btn = $(this);
		var $result = $('#pos-unified-sync-inventory-result');
		$btn.prop('disabled', true);
		$result.text('Running...');

		$.ajax({
			url: posUnified.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'pos_unified_trigger_sync',
				sync_type: 'inventory',
				_ajax_nonce: posUnified.nonce
			},
			success: function (res) {
				$btn.prop('disabled', false);
				if (res.success && res.data) {
					$result.html('<span style="color:green">Done: ' + (res.data.synced || 0) + ' synced, ' + (res.data.errors || 0) + ' errors</span>');
				} else {
					$result.html('<span style="color:red">Sync failed</span>');
				}
			},
			error: function (xhr, status, error) {
				$btn.prop('disabled', false);
				$result.html('<span style="color:red">Request failed: ' + error + '</span>');
			}
		});
	});

	// Trigger order sync
	$(document).on('click', '#pos-unified-sync-orders-btn', function () {
		var $btn = $(this);
		var $result = $('#pos-unified-sync-orders-result');
		$btn.prop('disabled', true);
		$result.text('Running...');

		$.ajax({
			url: posUnified.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'pos_unified_trigger_sync',
				sync_type: 'orders',
				_ajax_nonce: posUnified.nonce
			},
			success: function (res) {
				console.log('[POS Unified] Order sync response:', res);
				$btn.prop('disabled', false);
				if (res.success && res.data) {
					var pulled = res.data.pulled || 0;
					$result.html('<span style="color:green">Done — ' + pulled + ' new order(s) pulled</span>');
				} else {
					$result.html('<span style="color:red">Sync failed: ' + (res.data || 'Unknown error') + '</span>');
				}
			},
			error: function (xhr, status, error) {
				console.error('[POS Unified] Order sync error:', status, error, xhr.responseText);
				$btn.prop('disabled', false);
				$result.html('<span style="color:red">Request failed: ' + error + '</span>');
			}
		});
	});

	// Build store map JSON before form submit
	$('#pos-unified-form').on('submit', function () {
		var mappings = [];
		$('#pos-unified-store-map-table tbody tr').each(function () {
			var $row = $(this);
			var wcId = $row.find('input[name="pos_unified_store_map_wc[]"]').val();
			var diacosId = $row.find('select[name="pos_unified_store_map_diacos[]"]').val();
			var enabled = $row.find('input[name="pos_unified_store_map_enabled[]"]').is(':checked');
			mappings.push({
				wc_location_id: wcId,
				diacos_store_id: diacosId || '',
				enabled: enabled
			});
		});

		if (mappings.length) {
			$('<input>').attr({
				type: 'hidden',
				name: 'pos_unified_store_map',
				value: JSON.stringify(mappings)
			}).appendTo(this);
		}
	});

	// Init: load stores on page load
	$(function () {
		console.log('[POS Unified] Admin JS loaded. posUnified:', posUnified);
		if ($('.diacos-store-select').length) {
			loadDiacosStores(false);
		}
	});

})(jQuery);
