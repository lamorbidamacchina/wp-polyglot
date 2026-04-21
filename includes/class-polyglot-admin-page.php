<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

if (!class_exists('Polyglot_Admin_Page')) {
	final class Polyglot_Admin_Page {
		private Polyglot_Translation_Service $translation_service;

		public function __construct(Polyglot_Translation_Service $translation_service) {
			$this->translation_service = $translation_service;
		}

		public function enqueue_assets(string $hook): void {
			if ($hook !== 'tools_page_' . Polyglot_Plugin::MENU_SLUG) {
				return;
			}
		}

		public function ajax_job_status(): void {
			if (!current_user_can('manage_options')) {
				wp_send_json_error(array('message' => __('Unauthorized.', 'polyglot')), 403);
			}

			check_ajax_referer('polyglot_status', 'nonce');

			$job = get_option(Polyglot_Plugin::OPTION_JOB, array());
			if (!is_array($job)) {
				$job = array();
			}

			// Fallback runner: when WP-Cron is disabled or not triggered on dev, process one batch per poll.
			$status = isset($job['status']) ? (string) $job['status'] : 'idle';
			$has_queue = isset($job['queue']) && is_array($job['queue']) && !empty($job['queue']);
			if ($has_queue && in_array($status, array('queued', 'running'), true)) {
				do_action(Polyglot_Plugin::CRON_HOOK);
				$job = get_option(Polyglot_Plugin::OPTION_JOB, array());
				if (!is_array($job)) {
					$job = array();
				}
			}

			$remaining = isset($job['queue']) && is_array($job['queue']) ? count($job['queue']) : 0;
			$totals = isset($job['totals']) && is_array($job['totals']) ? $job['totals'] : array();
			$status = isset($job['status']) ? (string) $job['status'] : 'idle';

			wp_send_json_success(
				array(
					'status' => $status,
					'remaining' => $remaining,
					'totals' => array(
						'scanned' => (int) ($totals['scanned'] ?? 0),
						'translated' => (int) ($totals['translated'] ?? 0),
						'skipped' => (int) ($totals['skipped'] ?? 0),
						'failed' => (int) ($totals['failed'] ?? 0),
					),
					'last_error' => isset($job['last_error']) ? (string) $job['last_error'] : '',
				)
			);
		}

		public function render(): void {
			if (!current_user_can('manage_options')) {
				wp_die(esc_html__('You do not have permission to access this page.', 'polyglot'));
			}

			$has_api_key = $this->has_saved_api_key();
			$notice = get_option(Polyglot_Plugin::OPTION_NOTICE, array());
			$languages = $this->translation_service->get_available_languages();
			$groups = $this->translation_service->get_polylang_group_map();
			$job = get_option(Polyglot_Plugin::OPTION_JOB, array());
			$tab = $this->get_current_tab();
			if (!$has_api_key && $tab !== 'configuration') {
				$tab = 'configuration';
			}

			delete_option(Polyglot_Plugin::OPTION_NOTICE);
			$logo_url = plugin_dir_url(POLYGLOT_FILE) . 'logo.png';
			?>
			<div class="wrap">
				<div style="display:flex; align-items:center; gap:12px; margin-bottom: 8px;">
					<img src="<?php echo esc_url($logo_url); ?>" alt="<?php esc_attr_e('Polyglot logo', 'polyglot'); ?>" style="width: 42px; height: 42px; border-radius: 8px;" />
					<h1 style="margin:0;"><?php esc_html_e('Polyglot for Polylang', 'polyglot'); ?></h1>
				</div>
				<?php if (!empty($notice['message'])) : ?>
					<div class="notice notice-<?php echo esc_attr($notice['type'] ?? 'info'); ?> is-dismissible"><p><?php echo esc_html((string) $notice['message']); ?></p></div>
				<?php endif; ?>

				<?php if (!$this->translation_service->is_polylang_ready()) : ?>
					<div class="notice notice-error"><p><?php esc_html_e('Polylang must be active to use this plugin.', 'polyglot'); ?></p></div>
				<?php endif; ?>

				<?php $this->render_tabs_nav($tab, $has_api_key); ?>
				<?php if ($tab === 'configuration') : ?>
					<?php $this->render_configuration_tab(); ?>
				<?php elseif ($tab === 'translation-strings') : ?>
					<?php $this->render_translation_strings_tab($languages, $groups, $job); ?>
				<?php else : ?>
					<?php $this->render_pages_posts_cpt_tab($languages, $job); ?>
				<?php endif; ?>
			</div>
			<?php
		}

		private function get_current_tab(): string {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab navigation from query string; no state mutation.
			$tab = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : 'configuration';
			$allowed_tabs = array('configuration', 'translation-strings', 'pages-posts-cpt');
			if (!in_array($tab, $allowed_tabs, true)) {
				return 'configuration';
			}

			return $tab;
		}

		private function render_tabs_nav(string $active_tab, bool $has_api_key): void {
			$tabs = array(
				'configuration' => __('Configuration', 'polyglot'),
				'translation-strings' => __('Translation Strings', 'polyglot'),
				'pages-posts-cpt' => __('Pages, Posts and CPT', 'polyglot'),
			);
			echo '<h2 class="nav-tab-wrapper">';
			foreach ($tabs as $tab => $label) {
				$is_disabled = !$has_api_key && $tab !== 'configuration';
				$url = add_query_arg(
					array(
						'page' => Polyglot_Plugin::MENU_SLUG,
						'tab' => $tab,
					),
					admin_url('tools.php')
				);
				$class = 'nav-tab' . ($tab === $active_tab ? ' nav-tab-active' : '') . ($is_disabled ? ' nav-tab-disabled' : '');
				if ($is_disabled) {
					printf(
						'<span class="%1$s" style="%2$s" aria-disabled="true" title="%3$s">%4$s</span>',
						esc_attr($class),
						esc_attr('cursor:not-allowed;opacity:0.6;'),
						esc_attr__('Save the Google API key in Configuration to unlock this tab.', 'polyglot'),
						esc_html($label)
					);
					continue;
				}
				printf('<a href="%1$s" class="%2$s">%3$s</a>', esc_url($url), esc_attr($class), esc_html($label));
			}
			echo '</h2>';
		}

		private function has_saved_api_key(): bool {
			$api_key = (string) get_option(Polyglot_Plugin::OPTION_API_KEY, '');
			return trim($api_key) !== '';
		}

		private function render_configuration_tab(): void {
			$has_api_key = $this->has_saved_api_key();
			?>
			<div class="card" style="max-width: 900px; padding: 20px; margin-top: 16px;">
				<h2><?php esc_html_e('Google Cloud Setup', 'polyglot'); ?></h2>
				<ol>
					<li><?php esc_html_e('Create a project in Google Cloud Console.', 'polyglot'); ?></li>
					<li><?php esc_html_e('Enable Cloud Translation API.', 'polyglot'); ?></li>
					<li><?php esc_html_e('Create an API key and paste it below.', 'polyglot'); ?></li>
				</ol>

				<form method="post" action="">
					<?php wp_nonce_field('polyglot_form', 'polyglot_nonce'); ?>
					<input type="hidden" name="polyglot_action" value="save_api_key" />
					<input type="hidden" name="polyglot_tab" value="configuration" />
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="polyglot_api_key"><?php esc_html_e('Google API Key', 'polyglot'); ?></label></th>
							<td>
								<input
									name="polyglot_api_key"
									id="polyglot_api_key"
									type="password"
									class="regular-text"
									value=""
									placeholder="<?php echo esc_attr($has_api_key ? __('API key is saved. Enter a new key to replace it.', 'polyglot') : ''); ?>"
									autocomplete="off"
								/>
								<p class="description"><?php esc_html_e('Stored encrypted in WordPress options. Keep this key private.', 'polyglot'); ?></p>
							</td>
						</tr>
					</table>
					<?php submit_button(__('Save API Key', 'polyglot')); ?>
				</form>
			</div>
			<?php
		}

		private function render_translation_strings_tab(array $languages, array $groups, array $job): void {
			$default_source_language = '';
			if (function_exists('pll_default_language')) {
				$default_source_language = (string) pll_default_language('slug');
			}
			if ($default_source_language === '' || !isset($languages[$default_source_language])) {
				$default_source_language = (string) array_key_first($languages);
			}
			?>
			<div class="card" style="max-width: 900px; padding: 20px; margin-top: 16px;">
				<h2><?php esc_html_e('Run Missing String Translations', 'polyglot'); ?></h2>
				<p class="description" style="margin-top:0;">
					<?php esc_html_e('This tool only translates entries in Polylang String Translations. It does not translate posts, pages, or other WordPress objects.', 'polyglot'); ?>
				</p>
				<form method="post" action="">
					<?php wp_nonce_field('polyglot_form', 'polyglot_nonce'); ?>
					<input type="hidden" name="polyglot_action" value="start_translation" />
					<input type="hidden" name="polyglot_tab" value="translation-strings" />
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="polyglot_group"><?php esc_html_e('Polylang Group', 'polyglot'); ?></label></th>
							<td>
								<select name="polyglot_group" id="polyglot_group" required>
									<option value=""><?php esc_html_e('Select a group', 'polyglot'); ?></option>
									<?php foreach ($groups as $group) : ?>
										<option value="<?php echo esc_attr($group); ?>"><?php echo esc_html($group); ?></option>
									<?php endforeach; ?>
								</select>
								<?php if (empty($groups)) : ?>
									<p class="description">
										<?php esc_html_e('No Polylang string groups were detected. Ensure your theme/plugins register strings with Polylang and that they are visible in Polylang > String translations.', 'polyglot'); ?>
									</p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="polyglot_source_language"><?php esc_html_e('Source Language', 'polyglot'); ?></label></th>
							<td>
								<select name="polyglot_source_language" id="polyglot_source_language" required>
									<option value=""><?php esc_html_e('Select source language', 'polyglot'); ?></option>
									<?php foreach ($languages as $slug => $label) : ?>
										<option value="<?php echo esc_attr($slug); ?>" <?php selected($slug, $default_source_language); ?>><?php echo esc_html($label); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e('Target Languages', 'polyglot'); ?></th>
							<td>
								<?php foreach ($languages as $slug => $label) : ?>
									<label style="display:block; margin-bottom: 6px;" data-language-slug="<?php echo esc_attr($slug); ?>">
										<input type="checkbox" name="polyglot_languages[]" value="<?php echo esc_attr($slug); ?>" />
										<?php echo esc_html($label); ?>
									</label>
								<?php endforeach; ?>
							</td>
						</tr>
					</table>
					<?php submit_button(__('Start Translation', 'polyglot'), 'primary', 'submit', true); ?>
				</form>
			</div>

			<div class="card" style="max-width: 900px; padding: 20px; margin-top: 16px;">
				<h2><?php esc_html_e('Job Progress', 'polyglot'); ?></h2>
				<div id="polyglot-progress">
					<?php if (is_array($job) && !empty($job)) : ?>
						<p><strong><?php esc_html_e('Status:', 'polyglot'); ?></strong> <span id="polyglot-status"><?php echo esc_html((string) ($job['status'] ?? 'idle')); ?></span></p>
						<p><strong><?php esc_html_e('Remaining:', 'polyglot'); ?></strong> <span id="polyglot-remaining"><?php echo esc_html((string) (is_array($job['queue'] ?? null) ? count($job['queue']) : 0)); ?></span></p>
						<p><strong><?php esc_html_e('Translated:', 'polyglot'); ?></strong> <span id="polyglot-translated"><?php echo esc_html((string) ((int) ($job['totals']['translated'] ?? 0))); ?></span></p>
						<p><strong><?php esc_html_e('Skipped:', 'polyglot'); ?></strong> <span id="polyglot-skipped"><?php echo esc_html((string) ((int) ($job['totals']['skipped'] ?? 0))); ?></span></p>
						<p><strong><?php esc_html_e('Failed:', 'polyglot'); ?></strong> <span id="polyglot-failed"><?php echo esc_html((string) ((int) ($job['totals']['failed'] ?? 0))); ?></span></p>
						<p style="color:#b32d2e;" id="polyglot-last-error"><?php echo esc_html((string) ($job['last_error'] ?? '')); ?></p>
					<?php else : ?>
						<p><?php esc_html_e('No translation job started yet.', 'polyglot'); ?></p>
					<?php endif; ?>
				</div>
			</div>
			<script>
				(function() {
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
						labels.forEach(function(label) {
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

					function refreshStatus() {
						var body = new URLSearchParams();
						body.append('action', 'polyglot_job_status');
						body.append('nonce', '<?php echo esc_js(wp_create_nonce('polyglot_status')); ?>');

						fetch(ajaxurl, {
							method: 'POST',
							headers: {
								'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
							},
							body: body.toString(),
							credentials: 'same-origin'
						})
							.then(function(response) {
								return response.json();
							})
							.then(function(response) {
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
							})
							.catch(function() {
								// Ignore polling errors; next interval will retry.
							});
					}

					if (statusNode) {
						window.setInterval(refreshStatus, 4000);
					}
				})();
			</script>
			<?php
		}

		private function render_pages_posts_cpt_tab(array $languages, array $job): void {
			$post_type_options = $this->get_post_type_options();
			$default_source_language = '';
			if (function_exists('pll_default_language')) {
				$default_source_language = (string) pll_default_language('slug');
			}
			if ($default_source_language === '' || !isset($languages[$default_source_language])) {
				$default_source_language = (string) array_key_first($languages);
			}
			?>
			<div class="card" style="max-width: 900px; padding: 20px; margin-top: 16px;">
				<h2><?php esc_html_e('Pages, Posts and CPT', 'polyglot'); ?></h2>
				<p class="description" style="margin-top:0;">
					<?php esc_html_e('Polyglot only translates items when both the source and target posts (or pages/CPT entries) already exist. Create the target-language items first, then run this translation process.', 'polyglot'); ?>
				</p>
				<form method="post" action="">
					<?php wp_nonce_field('polyglot_form', 'polyglot_nonce'); ?>
					<input type="hidden" name="polyglot_action" value="start_content_translation" />
					<input type="hidden" name="polyglot_tab" value="pages-posts-cpt" />
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="polyglot_content_type"><?php esc_html_e('Content Type', 'polyglot'); ?></label></th>
							<td>
								<select name="polyglot_content_type" id="polyglot_content_type" required>
									<option value=""><?php esc_html_e('Select content type', 'polyglot'); ?></option>
									<?php foreach ($post_type_options as $slug => $label) : ?>
										<option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($label); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php esc_html_e('Choose one content type to translate: pages, posts, or a custom post type.', 'polyglot'); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e('Translation Scope', 'polyglot'); ?></th>
							<td>
								<label style="display:block; margin-bottom: 6px;">
									<input type="radio" name="polyglot_content_scope" value="default_only" checked />
									<?php esc_html_e('Translate only default content (title, content, excerpt).', 'polyglot'); ?>
								</label>
								<label style="display:block; margin-bottom: 6px;">
									<input type="radio" name="polyglot_content_scope" value="with_custom_fields" />
									<?php esc_html_e('Translate default content and custom fields.', 'polyglot'); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="polyglot_content_source_language"><?php esc_html_e('Source Language', 'polyglot'); ?></label></th>
							<td>
								<select name="polyglot_content_source_language" id="polyglot_content_source_language" required>
									<option value=""><?php esc_html_e('Select source language', 'polyglot'); ?></option>
									<?php foreach ($languages as $slug => $label) : ?>
										<option value="<?php echo esc_attr($slug); ?>" <?php selected($slug, $default_source_language); ?>><?php echo esc_html($label); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e('Target Languages', 'polyglot'); ?></th>
							<td>
								<?php foreach ($languages as $slug => $label) : ?>
									<label style="display:block; margin-bottom: 6px;" data-content-language-slug="<?php echo esc_attr($slug); ?>">
										<input type="checkbox" name="polyglot_content_languages[]" value="<?php echo esc_attr($slug); ?>" />
										<?php echo esc_html($label); ?>
									</label>
								<?php endforeach; ?>
							</td>
						</tr>
						<tr id="polyglot-custom-fields-confirmation-row" style="display:none;">
							<th scope="row"><?php esc_html_e('Confirm Custom Fields', 'polyglot'); ?></th>
							<td>
								<label>
									<input type="checkbox" id="polyglot_confirm_meta_translation" name="polyglot_confirm_meta_translation" value="1" />
									<?php esc_html_e('I am aware that translating some custom fields could affect website behavior.', 'polyglot'); ?>
								</label>
								<p class="description">
									<?php esc_html_e('Polyglot will list detected custom fields in a warning notice. Confirm to proceed.', 'polyglot'); ?>
								</p>
							</td>
						</tr>
					</table>
					<div id="polyglot-content-run-summary" class="notice notice-info inline" style="margin: 12px 0 0;">
						<p id="polyglot-content-run-summary-text"><?php esc_html_e('Run summary will appear here.', 'polyglot'); ?></p>
					</div>
					<?php submit_button(__('Start Translation', 'polyglot'), 'primary', 'submit', true, array('id' => 'polyglot-content-submit')); ?>
				</form>
				<p class="description">
					<?php esc_html_e('Only existing translated target items are processed. Missing target posts are skipped.', 'polyglot'); ?>
				</p>
			</div>
			<div class="card" style="max-width: 900px; padding: 20px; margin-top: 16px;">
				<h2><?php esc_html_e('Job Progress', 'polyglot'); ?></h2>
				<div id="polyglot-content-progress">
					<?php if (is_array($job) && !empty($job) && (($job['job_type'] ?? '') === 'content')) : ?>
						<p><strong><?php esc_html_e('Status:', 'polyglot'); ?></strong> <span id="polyglot-content-status"><?php echo esc_html((string) ($job['status'] ?? 'idle')); ?></span></p>
						<p><strong><?php esc_html_e('Remaining:', 'polyglot'); ?></strong> <span id="polyglot-content-remaining"><?php echo esc_html((string) (is_array($job['queue'] ?? null) ? count($job['queue']) : 0)); ?></span></p>
						<p><strong><?php esc_html_e('Scanned:', 'polyglot'); ?></strong> <span id="polyglot-content-scanned"><?php echo esc_html((string) ((int) ($job['totals']['scanned'] ?? 0))); ?></span></p>
						<p><strong><?php esc_html_e('Translatable:', 'polyglot'); ?></strong> <span id="polyglot-content-translatable"><?php echo esc_html((string) ((int) ($job['totals']['translatable'] ?? 0))); ?></span></p>
						<p><strong><?php esc_html_e('Translated:', 'polyglot'); ?></strong> <span id="polyglot-content-translated"><?php echo esc_html((string) ((int) ($job['totals']['translated'] ?? 0))); ?></span></p>
						<p><strong><?php esc_html_e('Skipped:', 'polyglot'); ?></strong> <span id="polyglot-content-skipped"><?php echo esc_html((string) ((int) ($job['totals']['skipped'] ?? 0))); ?></span></p>
						<p><strong><?php esc_html_e('Failed:', 'polyglot'); ?></strong> <span id="polyglot-content-failed"><?php echo esc_html((string) ((int) ($job['totals']['failed'] ?? 0))); ?></span></p>
						<p style="color:#b32d2e;" id="polyglot-content-last-error"><?php echo esc_html((string) ($job['last_error'] ?? '')); ?></p>
					<?php else : ?>
						<p><?php esc_html_e('No content translation job started yet.', 'polyglot'); ?></p>
					<?php endif; ?>
				</div>
			</div>
			<script>
				(function() {
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
						targetInputs.forEach(function(input) {
							values.push(input.value);
						});
						return values;
					}

					function getSelectedOptionLabel(selectNode) {
						if (!selectNode) {
							return '';
						}
						var selectedOption = selectNode.options[selectNode.selectedIndex];
						return selectedOption ? selectedOption.text : '';
					}

					function renderRunSummary() {
						if (!summaryNode) {
							return;
						}

						var includeCustomFields = getScopeValue() === 'with_custom_fields';
						var targetLanguages = getCheckedTargetLanguages();
						if (includeCustomFields) {
							summaryNode.textContent = '<?php echo esc_js(__('Loading custom fields preview...', 'polyglot')); ?>';
							refreshMetaPreview(contentTypeSelect ? contentTypeSelect.value : '', sourceSelect.value, targetLanguages);
						} else {
							summaryNode.textContent = '<?php echo esc_js(__('Custom field translation is disabled for this run.', 'polyglot')); ?>';
						}
					}

					function refreshMetaPreview(contentTypeValue, sourceLanguageValue, targetLanguageValues) {
						if (!summaryNode) {
							return;
						}

						if (!contentTypeValue || !sourceLanguageValue || targetLanguageValues.length === 0) {
							summaryNode.textContent = '<?php echo esc_js(__('Select content type, source language, and target languages to preview custom fields.', 'polyglot')); ?>';
							return;
						}

						var body = new URLSearchParams();
						body.append('action', 'polyglot_content_meta_preview');
						body.append('nonce', '<?php echo esc_js(wp_create_nonce('polyglot_status')); ?>');
						body.append('content_type', contentTypeValue);
						body.append('source_language', sourceLanguageValue);
						targetLanguageValues.forEach(function(lang) {
							body.append('target_languages[]', lang);
						});

						fetch(ajaxurl, {
							method: 'POST',
							headers: {
								'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
							},
							body: body.toString(),
							credentials: 'same-origin'
						})
							.then(function(response) {
								return response.json();
							})
							.then(function(response) {
								var metaKeys = [];
								if (response && response.success && response.data && Array.isArray(response.data.meta_keys)) {
									metaKeys = response.data.meta_keys;
								}
								var metaKeysLabel = metaKeys.length > 0 ? metaKeys.join(', ') : '<?php echo esc_js(__('No eligible custom fields detected', 'polyglot')); ?>';
								summaryNode.textContent = '<?php echo esc_js(__('Polyglot will attempt to translate these custom fields:', 'polyglot')); ?> ' + metaKeysLabel;
							})
							.catch(function() {
								summaryNode.textContent = '<?php echo esc_js(__('Could not load custom fields preview.', 'polyglot')); ?>';
							});
					}

					function syncTargetLanguageOptions() {
						var sourceLanguage = sourceSelect.value;
						var labels = document.querySelectorAll('label[data-content-language-slug]');
						labels.forEach(function(label) {
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
					document.querySelectorAll('input[type="checkbox"][name="polyglot_content_languages[]"]').forEach(function(input) {
						input.addEventListener('change', renderRunSummary);
					});
					syncTargetLanguageOptions();
					scopeInputs.forEach(function(scopeInput) {
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

					function refreshStatus() {
						var body = new URLSearchParams();
						body.append('action', 'polyglot_job_status');
						body.append('nonce', '<?php echo esc_js(wp_create_nonce('polyglot_status')); ?>');

						fetch(ajaxurl, {
							method: 'POST',
							headers: {
								'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
							},
							body: body.toString(),
							credentials: 'same-origin'
						})
							.then(function(response) {
								return response.json();
							})
							.then(function(response) {
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
							})
							.catch(function() {
								// Ignore polling errors; next interval will retry.
							});
					}

					window.setInterval(refreshStatus, 4000);
				})();
			</script>
			<?php
		}

		private function get_post_type_options(): array {
			$options = array(
				'page' => __('Pages', 'polyglot'),
				'post' => __('Posts', 'polyglot'),
			);

			$custom_post_types = get_post_types(
				array(
					'public' => true,
					'_builtin' => false,
				),
				'objects'
			);

			if (!is_array($custom_post_types)) {
				return $options;
			}

			foreach ($custom_post_types as $post_type) {
				if (!($post_type instanceof WP_Post_Type)) {
					continue;
				}

				$options[$post_type->name] = (string) $post_type->labels->singular_name;
			}

			return $options;
		}
	}
}
