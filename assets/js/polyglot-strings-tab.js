/**
 * Polyglot admin: Translation Strings tab (target language sync + job status polling).
 */
(function () {
	'use strict';

	var data = window.polyglotData || {};
	var nonce = data.nonce || '';
	var ajaxUrl = data.ajaxUrl || (typeof ajaxurl !== 'undefined' ? ajaxurl : '');

	var sourceSelect = document.getElementById('polyglot_source_language');
	var statusNode = document.getElementById('polyglot-status');
	if (!sourceSelect && !statusNode) {
		return;
	}

	function syncTargetLanguageOptions() {
		if (!sourceSelect) {
			return;
		}

		var sourceLanguage = sourceSelect.value;
		var labels = document.querySelectorAll('label[data-language-slug]');
		labels.forEach(function (label) {
			var slug = label.getAttribute('data-language-slug');
			var checkbox = label.querySelector('input[type="checkbox"][name="polyglot_languages[]"]');
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

	if (sourceSelect) {
		sourceSelect.addEventListener('change', syncTargetLanguageOptions);
		syncTargetLanguageOptions();
	}

	function setText(id, value) {
		var node = document.getElementById(id);
		if (node) {
			node.textContent = String(value);
		}
	}

	function setErrorDetails(errors) {
		var panel = document.getElementById('polyglot-errors-panel');
		var list = document.getElementById('polyglot-errors-list');
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
				setText('polyglot-status', response.data.status || 'idle');
				setText('polyglot-remaining', response.data.remaining || 0);
				setText('polyglot-translated', totals.translated || 0);
				setText('polyglot-skipped', totals.skipped || 0);
				setText('polyglot-failed', totals.failed || 0);
				setText('polyglot-last-error', response.data.last_error || '');
				setErrorDetails(response.data.errors || []);
			})
			.catch(function () {
				// Ignore polling errors; next interval will retry.
			});
	}

	if (statusNode) {
		window.setInterval(refreshStatus, 4000);
	}
})();
