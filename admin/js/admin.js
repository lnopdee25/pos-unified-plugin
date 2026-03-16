(function ($) {
	'use strict';

	var diacosStores = [];

	// Fetch Diacos stores and populate all <select class="diacos-store-select">
	function loadDiacosStores(forceRefresh) {
		var cached = $('#pos-unified-stores-cache').data('stores');
		if (!forceRefresh && cached && cached.length) {
			diacosStores = cached;
			populateSelects();
			return;
		}

		$.post(posUnified.ajaxUrl, {
			action: 'pos_unified_fetch_stores',
			_ajax_nonce: posUnified.nonce,
		}, function (res) {
			if (res.success && res.data) {
				diacosStores = res.data.data || res.data || [];
				populateSelects();
			}
		});
	}

	function populateSelects() {
		$('.diacos-store-select').each(function () {
			var $select = $(this);
			var saved = $select.siblings('.diacos-store-saved').val() || '';
			var firstOpt = $select.find('option:first').clone();

			$select.empty().append(firstOpt);

			$.each(diacosStores, function (_, store) {
				var id = store.id || store.storeId;
				var name = store.name || store.storeName || id;
				var code = store.code || '';
				var label = code ? name + ' (' + code + ')' : name;
				$select.append(
					$('<option>').val(id).text(label).prop('selected', id === saved)
				);
			});
		});
	}

	// Test connection
	$('#pos-unified-test-btn').on('click', function () {
		var $btn = $(this);
		var $result = $('#pos-unified-test-result');
		$btn.prop('disabled', true);
		$result.text('Testing...');

		$.post(posUnified.ajaxUrl, {
			action: 'pos_unified_test_connection',
			_ajax_nonce: posUnified.nonce,
		}, function (res) {
			$btn.prop('disabled', false);
			if (res.success) {
				$result.html('<span style="color:green">✅ Connected to ' + (res.data.storeName || 'Diacos') + '</span>');
			} else {
				$result.html('<span style="color:red">❌ ' + (res.data || 'Failed') + '</span>');
			}
		}).fail(function () {
			$btn.prop('disabled', false);
			$result.html('<span style="color:red">❌ Request failed</span>');
		});
	});

	// Refresh Diacos stores
	$('#pos-unified-fetch-stores-btn').on('click', function () {
		loadDiacosStores(true);
	});

	// Trigger inventory sync
	$('#pos-unified-sync-inventory-btn').on('click', function () {
		var $btn = $(this);
		var $result = $('#pos-unified-sync-inventory-result');
		$btn.prop('disabled', true);
		$result.text('Running...');

		$.post(posUnified.ajaxUrl, {
			action: 'pos_unified_trigger_sync',
			sync_type: 'inventory',
			_ajax_nonce: posUnified.nonce,
		}, function (res) {
			$btn.prop('disabled', false);
			if (res.success && res.data) {
				$result.html('<span style="color:green">Done — ' + (res.data.synced || 0) + ' synced, ' + (res.data.errors || 0) + ' errors</span>');
			} else {
				$result.html('<span style="color:red">Sync failed</span>');
			}
		});
	});

	// Trigger order sync
	$('#pos-unified-sync-orders-btn').on('click', function () {
		var $btn = $(this);
		var $result = $('#pos-unified-sync-orders-result');
		$btn.prop('disabled', true);
		$result.text('Running...');

		$.post(posUnified.ajaxUrl, {
			action: 'pos_unified_trigger_sync',
			sync_type: 'orders',
			_ajax_nonce: posUnified.nonce,
		}, function (res) {
			$btn.prop('disabled', false);
			$result.html('<span style="color:green">Done</span>');
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
				enabled: enabled,
			});
		});

		// Inject as hidden JSON field
		if (mappings.length) {
			$('<input>').attr({
				type: 'hidden',
				name: 'pos_unified_store_map',
				value: JSON.stringify(mappings),
			}).appendTo(this);
		}
	});

	// Init: load stores on page load
	$(function () {
		if ($('.diacos-store-select').length) {
			loadDiacosStores(false);
		}
	});

})(jQuery);
