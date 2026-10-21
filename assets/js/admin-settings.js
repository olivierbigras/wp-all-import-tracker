(function ($) {
	'use strict';

	var settings = window.oliitSettings || {};
	var i18n = settings.i18n || {};
	var nonces = settings.nonces || {};
	var ajaxUrl = settings.ajaxUrl || window.ajaxurl;

	function showNotice($target, type, message) {
		var $notice = $('<div/>', {
			class: 'notice notice-' + type + ' inline'
		});
		$notice.append($('<p/>').text(message));
		$target.empty().append($notice);
	}

	function toggleAlertFields() {
		var enabled = $('#oliit_enable_email_alerts').is(':checked');
		$('#oliit-alert-email-wrap, #oliit-alert-advanced-wrap').prop('hidden', !enabled);
	}

	function importIdRow(id) {
		var $label = $('<label/>', { class: 'oliit-import-choice' });
		$label.append(
			$('<input/>', {
				type: 'checkbox',
				name: 'oliit_import_ids[]',
				value: id,
				checked: true
			})
		);
		$label.append(
			document.createTextNode(
				' #' + id + ' — ' + (i18n.addId || '') + ' (' + (i18n.neverRun || '') + ')'
			)
		);
		return $label;
	}

	toggleAlertFields();
	$('#oliit_enable_email_alerts').on('change', toggleAlertFields);

	$('#oliit-import-filter').on('input', function () {
		var query = $(this).val().toLowerCase();
		$('#oliit-import-list .oliit-import-choice').each(function () {
			var text = $(this).text().toLowerCase();
			$(this).toggleClass('is-filtered-out', query !== '' && text.indexOf(query) === -1);
		});
	});

	$('#oliit-add-manual-id').on('click', function () {
		var id = parseInt($('#oliit-manual-import-id').val(), 10);
		if (!id) {
			return;
		}
		if ($('#oliit-import-list input[value="' + id + '"]').length) {
			$('#oliit-import-list input[value="' + id + '"]').prop('checked', true);
			return;
		}
		$('#oliit-import-list').append(importIdRow(id));
		$('#oliit-manual-import-id').val('');
	});

	$('#oliit-settings-form').on('submit', function (event) {
		var stale = parseInt($('#oliit_stale_threshold').val(), 10) || 0;
		var maximum = parseInt($('#oliit_max_execution_time').val(), 10) || 0;
		if ($('#oliit_enable_email_alerts').is(':checked') && maximum < stale) {
			event.preventDefault();
			window.alert(i18n.thresholdError || '');
		}
	});

	$('#oliit_test_email').on('click', function () {
		var $button = $(this);
		var originalText = $button.text();
		var email = $('#oliit_alert_email').val() || settings.adminEmail || '';
		var $result = $('#oliit_test_email_result');
		var $error = $('#oliit_alert_email_error');

		if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
			$error.text(i18n.invalidEmail || '').prop('hidden', false);
			return;
		}
		$error.prop('hidden', true).text('');

		$button.prop('disabled', true).text(i18n.sending);
		$result.empty();

		$.ajax({
			url: ajaxUrl,
			type: 'POST',
			data: {
				action: 'oliit_test_email',
				email: email,
				_wpnonce: nonces.testEmail
			}
		})
			.done(function (response) {
				showNotice(
					$result,
					response.success ? 'success' : 'error',
					response.data || ''
				);
			})
			.fail(function () {
				showNotice($result, 'error', i18n.testEmailError || '');
			})
			.always(function () {
				$button.prop('disabled', false).text(originalText);
			});
	});

	$('#oliit_refresh_cache').on('click', function () {
		var $button = $(this);
		var originalText = $button.text();
		var $result = $('#oliit_refresh_result');

		$button.prop('disabled', true).text(i18n.refreshing);
		$result.empty();

		$.ajax({
			url: ajaxUrl,
			type: 'POST',
			data: {
				action: 'oliit_refresh_cache',
				import_ids: settings.importIds || [],
				_wpnonce: nonces.refreshCache
			}
		})
			.done(function (response) {
				showNotice(
					$result,
					response.success ? 'success' : 'error',
					response.data || ''
				);
			})
			.fail(function () {
				showNotice($result, 'error', i18n.refreshError || '');
			})
			.always(function () {
				$button.prop('disabled', false).text(originalText);
			});
	});

	$('#oliit_debug_import').on('click', function () {
		var $button = $(this);
		var originalText = $button.text();
		var $result = $('#oliit_debug_result');

		$button.prop('disabled', true).text(i18n.checking);
		$result.empty();

		$.ajax({
			url: ajaxUrl,
			type: 'POST',
			data: {
				action: 'oliit_debug_import',
				import_ids: settings.importIds || [],
				_wpnonce: nonces.debugImport
			}
		})
			.done(function (response) {
				if (!response.success) {
					showNotice($result, 'error', response.data || '');
					return;
				}
				var rows = (response.data && response.data.rows) || [];
				var $table = $('<table/>', {
					class: 'widefat oliit-diagnose-table'
				});
				var $thead = $('<thead/>').append(
					$('<tr/>')
						.append($('<th/>').text(i18n.colImport || ''))
						.append($('<th/>').text(i18n.colSummary || ''))
						.append($('<th/>').text(i18n.colCount || ''))
						.append($('<th/>').text(i18n.colStatus || ''))
				);
				var $tbody = $('<tbody/>');
				rows.forEach(function (row) {
					var ok = row.status === 'ok';
					$tbody.append(
						$('<tr/>')
							.append($('<td/>').text('#' + row.id))
							.append($('<td/>').text(row.summary || ''))
							.append($('<td/>').text(String(row.count)))
							.append(
								$('<td/>', {
									class: ok ? 'oliit-status-ok' : 'oliit-status-bad'
								}).text(ok ? i18n.statusOk : i18n.statusBad)
							)
					);
				});
				$table.append($thead).append($tbody);
				$result.empty().append($table);
			})
			.fail(function () {
				showNotice($result, 'error', i18n.debugError || '');
			})
			.always(function () {
				$button.prop('disabled', false).text(originalText);
			});
	});

	$('#oliit-reset-notifications').on('click', function () {
		var $button = $(this);
		var originalText = $button.text();
		var $result = $('#oliit-reset-notifications-result');

		$button.prop('disabled', true).text(i18n.resetting);
		$result.empty();

		$.ajax({
			url: ajaxUrl,
			type: 'POST',
			data: {
				action: 'oliit_reset_notifications',
				_wpnonce: nonces.resetNotifications
			}
		})
			.done(function (response) {
				showNotice(
					$result,
					response.success ? 'success' : 'error',
					response.data || ''
				);
			})
			.fail(function () {
				showNotice($result, 'error', i18n.resetError || '');
			})
			.always(function () {
				$button.prop('disabled', false).text(originalText);
			});
	});
})(jQuery);
