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

	function showTextResult($target, ok, message) {
		$target
			.removeClass('oliit-success oliit-warning')
			.addClass(ok ? 'oliit-success' : 'oliit-warning')
			.text(message);
	}

	function importIdRow(value) {
		var $row = $('<div/>', { class: 'oliit-import-id-row' });
		$row.append(
			$('<input/>', {
				type: 'number',
				name: 'oliit_import_ids[]',
				value: value || '',
				min: 1,
				class: 'regular-text oliit-import-id-input'
			})
		);
		$row.append(
			$('<button/>', {
				type: 'button',
				class: 'button oliit-remove-import-id',
				text: i18n.remove || ''
			})
		);
		return $row;
	}

	$('#oliit_add_import_id').on('click', function () {
		$('#oliit_import_ids_container').append(importIdRow(''));
	});

	$(document).on('click', '.oliit-remove-import-id', function () {
		if ($('.oliit-import-id-row').length > 1) {
			$(this).closest('.oliit-import-id-row').remove();
		}
	});

	$('#oliit_detect_imports').on('click', function () {
		var $list = $('#oliit_available_imports');
		var $button = $(this);
		$list.toggleClass('is-visible');
		$button.text(
			$list.hasClass('is-visible')
				? i18n.hideImports
				: i18n.detectImports
		);
	});

	$(document).on('click', '.oliit-add-import', function () {
		var importId = $(this).data('import-id');
		$('#oliit_import_ids_container').append(importIdRow(importId));
		$(this)
			.closest('tr')
			.find('td:last')
			.empty()
			.append(
				$('<span/>', {
					class: 'oliit-tracked',
					text: i18n.tracked || ''
				})
			);
	});

	$('#oliit_test_email').on('click', function () {
		var $button = $(this);
		var originalText = $button.text();
		var email = $('#oliit_alert_email').val() || settings.adminEmail || '';
		var $result = $('#oliit_test_email_result');

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
				if (response.success) {
					window.setTimeout(function () {
						window.location.reload();
					}, 2000);
				}
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
				showNotice(
					$result,
					response.success ? 'success' : 'error',
					response.data || ''
				);
			})
			.fail(function () {
				showNotice($result, 'error', i18n.debugError || '');
			})
			.always(function () {
				$button.prop('disabled', false).text(originalText);
				window.setTimeout(function () {
					window.location.reload();
				}, 3000);
			});
	});

	$('#oliit-reset-notifications').on('click', function () {
		var $button = $(this);
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
				showTextResult(
					$result,
					!!response.success,
					response.data || ''
				);
				$button.text(i18n.resetLabel);
			})
			.fail(function () {
				showTextResult($result, false, i18n.resetError || '');
			})
			.always(function () {
				$button.prop('disabled', false);
			});
	});
})(jQuery);
