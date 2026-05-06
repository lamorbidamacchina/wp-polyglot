/**
 * Polyglot admin: Pages/Posts/CPT tab (custom fields preview + job status polling).
 */
(function () {
	'use strict';

	var data = window.polyglotData || {};
	var nonce = data.nonce || '';
	var ajaxUrl = data.ajaxUrl || (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
	var i18n = data.i18n || {};

	var sourceSelect = document.getElementById('polyglot_content_source_language');
	var contentTypeSelect = document.getElementById('polyglot_content_type');
	var scopeInputs = document.querySelectorAll('input[name="polyglot_content_scope"]');
	var confirmRow = document.getElementById('polyglot-custom-fields-confirmation-row');
	var confirmCheckbox = document.getElementById('polyglot_confirm_meta_translation');
	var submitButton = document.getElementById('polyglot-content-submit');
	var summaryNode = document.getElementById('polyglot-content-run-summary-text');
	if (!sourceSelect) {
		return;
	}

	function getScopeValue() {
		var selected = document.querySelector('input[name="polyglot_content_scope"]:checked');
		return selected ? selected.value : 'default_only';
	}

	function syncCustomFieldConfirmationUi() {
		var includeCustomFields = getScopeValue() === 'with_custom_fields';
		if (confirmRow) {
			confirmRow.style.display = includeCustomFields ? 'table-row' : 'none';
		}

		if (!includeCustomFields && confirmCheckbox) {
			confirmCheckbox.checked = false;
		}

		if (submitButton) {
			submitButton.disabled = includeCustomFields && (!confirmCheckbox || !confirmCheckbox.checked);
		}

		renderRunSummary();
	}

	function getCheckedTargetLanguages() {
		var values = [];
		var targetInputs = document.querySelectorAll('input[type="checkbox"][name="polyglot_content_languages[]"]:checked');
		targetInputs.forEach(function (input) {
			values.push(input.value);
		});
		return values;
	}

	function renderRunSummary() {
		if (!summaryNode) {
			return;
		}

		var includeCustomFields = getScopeValue() === 'with_custom_fields';
		var targetLanguages = getCheckedTargetLanguages();
		if (includeCustomFields) {
			summaryNode.textContent = i18n.loadingCustomFields || '';
			refreshMetaPreview(contentTypeSelect ? contentTypeSelect.value : '', sourceSelect.value, targetLanguages);
		} else {
			summaryNode.textContent = i18n.customFieldDisabled || '';
		}
	}

	function refreshMetaPreview(contentTypeValue, sourceLanguageValue, targetLanguageValues) {
		if (!summaryNode) {
			return;
		}

		if (!contentTypeValue || !sourceLanguageValue || targetLanguageValues.length === 0) {
			summaryNode.textContent = i18n.selectForPreview || '';
			return;
		}

		var body = new URLSearchParams();
		body.append('action', 'polyglot_content_meta_preview');
		body.append('nonce', nonce);
		body.append('content_type', contentTypeValue);
		body.append('source_language', sourceLanguageValue);
		targetLanguageValues.forEach(function (lang) {
			body.append('target_languages[]', lang);
		});

		fetch(ajaxUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
			},
			body: body.toString(),
			credentials: 'same-origin',
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (response) {
				var metaKeys = [];
				if (response && response.success && response.data && Array.isArray(response.data.meta_keys)) {
					metaKeys = response.data.meta_keys;
				}
				var metaKeysLabel = metaKeys.length > 0 ? metaKeys.join(', ') : (i18n.noEligibleMeta || '');
				summaryNode.textContent = (i18n.willTranslatePrefix || '') + ' ' + metaKeysLabel;
			})
			.catch(function () {
				summaryNode.textContent = i18n.couldNotLoadPreview || '';
			});
	}

	function syncTargetLanguageOptions() {
		var sourceLanguage = sourceSelect.value;
		var labels = document.querySelectorAll('label[data-content-language-slug]');
		labels.forEach(function (label) {
			var slug = label.getAttribute('data-content-language-slug');
			var checkbox = label.querySelector('input[type="checkbox"][name="polyglot_content_languages[]"]');
			if (!checkbox) {
				return;
			}

			if (slug === sourceLanguage && sourceLanguage !== '') {
				checkbox.checked = false;
				checkbox.disabled = true;
				label.style.display = 'none';
				return;
			}

			checkbox.disabled = false;
			label.style.display = 'block';
		});
	}

	sourceSelect.addEventListener('change', syncTargetLanguageOptions);
	if (contentTypeSelect) {
		contentTypeSelect.addEventListener('change', renderRunSummary);
	}
	document.querySelectorAll('input[type="checkbox"][name="polyglot_content_languages[]"]').forEach(function (input) {
		input.addEventListener('change', renderRunSummary);
	});
	syncTargetLanguageOptions();
	scopeInputs.forEach(function (scopeInput) {
		scopeInput.addEventListener('change', syncCustomFieldConfirmationUi);
	});
	if (confirmCheckbox) {
		confirmCheckbox.addEventListener('change', syncCustomFieldConfirmationUi);
	}
	syncCustomFieldConfirmationUi();

	var statusNode = document.getElementById('polyglot-content-status');
	if (!statusNode) {
		return;
	}

	function setText(id, value) {
		var node = document.getElementById(id);
		if (node) {
			node.textContent = String(value);
		}
	}

	function setErrorDetails(errors) {
		var panel = document.getElementById('polyglot-content-errors-panel');
		var list = document.getElementById('polyglot-content-errors-list');
		if (!panel || !list) {
			return;
		}

		while (list.firstChild) {
			list.removeChild(list.firstChild);
		}

		if (!Array.isArray(errors) || errors.length === 0) {
			panel.style.display = 'none';
			return;
		}

		errors.forEach(function (item) {
			var li = document.createElement('li');
			li.textContent = String(item || '');
			list.appendChild(li);
		});
		panel.style.display = 'block';
	}

	function refreshStatus() {
		var body = new URLSearchParams();
		body.append('action', 'polyglot_job_status');
		body.append('nonce', nonce);

		fetch(ajaxUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
			},
			body: body.toString(),
			credentials: 'same-origin',
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (response) {
				if (!response || !response.success || !response.data) {
					return;
				}
				var totals = response.data.totals || {};
				setText('polyglot-content-status', response.data.status || 'idle');
				setText('polyglot-content-remaining', response.data.remaining || 0);
				setText('polyglot-content-scanned', totals.scanned || 0);
				setText('polyglot-content-translatable', totals.translatable || 0);
				setText('polyglot-content-translated', totals.translated || 0);
				setText('polyglot-content-skipped', totals.skipped || 0);
				setText('polyglot-content-failed', totals.failed || 0);
				setText('polyglot-content-last-error', response.data.last_error || '');
				setErrorDetails(response.data.errors || []);
			})
			.catch(function () {
				// Ignore polling errors; next interval will retry.
			});
	}

	window.setInterval(refreshStatus, 4000);
})();
