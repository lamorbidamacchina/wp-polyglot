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

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab navigation for asset loading.
			$tab = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : 'configuration';

			$ajax_url = admin_url('admin-ajax.php');
			$nonce = wp_create_nonce('polyglot_status');

			$common = array(
				'nonce' => $nonce,
				'ajaxUrl' => $ajax_url,
			);

			if ($tab === 'translation-strings') {
				wp_enqueue_script(
					'polyglot-strings-tab',
					plugins_url('assets/js/polyglot-strings-tab.js', POLYGLOT_FILE),
					array(),
					POLYGLOT_VERSION,
					true
				);
				wp_localize_script('polyglot-strings-tab', 'polyglotData', $common);
				return;
			}

			if ($tab === 'pages-posts-cpt') {
				wp_enqueue_script(
					'polyglot-content-tab',
					plugins_url('assets/js/polyglot-content-tab.js', POLYGLOT_FILE),
					array(),
					POLYGLOT_VERSION,
					true
				);
				wp_localize_script(
					'polyglot-content-tab',
					'polyglotData',
					array_merge(
						$common,
						array(
							'i18n' => array(
								'loadingCustomFields' => __('Loading custom fields preview...', 'polyglot-for-polylang'),
								'customFieldDisabled' => __('Custom field translation is disabled for this run.', 'polyglot-for-polylang'),
								'selectForPreview' => __('Select content type, source language, and target languages to preview custom fields.', 'polyglot-for-polylang'),
								'noEligibleMeta' => __('No eligible custom fields detected', 'polyglot-for-polylang'),
								'willTranslatePrefix' => __('Polyglot will attempt to translate these custom fields:', 'polyglot-for-polylang'),
								'couldNotLoadPreview' => __('Could not load custom fields preview.', 'polyglot-for-polylang'),
							),
						)
					)
				);
			}
		}

		public function ajax_job_status(): void {
			if (!current_user_can('manage_options')) {
				wp_send_json_error(array('message' => __('Unauthorized.', 'polyglot-for-polylang')), 403);
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
					'errors' => $this->format_job_errors(isset($job['errors']) && is_array($job['errors']) ? $job['errors'] : array()),
				)
			);
		}

		public function render(): void {
			if (!current_user_can('manage_options')) {
				wp_die(esc_html__('You do not have permission to access this page.', 'polyglot-for-polylang'));
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
					<img src="<?php echo esc_url($logo_url); ?>" alt="<?php esc_attr_e('Polyglot logo', 'polyglot-for-polylang'); ?>" style="width: 42px; height: 42px; border-radius: 8px;" />
					<h1 style="margin:0;"><?php esc_html_e('Polyglot for Polylang', 'polyglot-for-polylang'); ?></h1>
				</div>
				<?php if (!empty($notice['message'])) : ?>
					<div class="notice notice-<?php echo esc_attr($notice['type'] ?? 'info'); ?> is-dismissible"><p><?php echo esc_html((string) $notice['message']); ?></p></div>
				<?php endif; ?>

				<?php if (!$this->translation_service->is_polylang_ready()) : ?>
					<div class="notice notice-error"><p><?php esc_html_e('Polylang must be active to use this plugin.', 'polyglot-for-polylang'); ?></p></div>
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
				'configuration' => __('Configuration', 'polyglot-for-polylang'),
				'translation-strings' => __('Translation Strings', 'polyglot-for-polylang'),
				'pages-posts-cpt' => __('Pages, Posts and CPT', 'polyglot-for-polylang'),
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
						esc_attr__('Save the Google API key in Configuration to unlock this tab.', 'polyglot-for-polylang'),
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
				<h2><?php esc_html_e('Google Cloud Setup', 'polyglot-for-polylang'); ?></h2>
				<ol>
					<li><?php esc_html_e('Create a project in Google Cloud Console.', 'polyglot-for-polylang'); ?></li>
					<li><?php esc_html_e('Enable Cloud Translation API.', 'polyglot-for-polylang'); ?></li>
					<li><?php esc_html_e('Create an API key and paste it below.', 'polyglot-for-polylang'); ?></li>
				</ol>

				<form method="post" action="">
					<?php wp_nonce_field('polyglot_form', 'polyglot_nonce'); ?>
					<input type="hidden" name="polyglot_action" value="save_api_key" />
					<input type="hidden" name="polyglot_tab" value="configuration" />
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="polyglot_api_key"><?php esc_html_e('Google API Key', 'polyglot-for-polylang'); ?></label></th>
							<td>
								<input
									name="polyglot_api_key"
									id="polyglot_api_key"
									type="password"
									class="regular-text"
									value=""
									placeholder="<?php echo esc_attr($has_api_key ? __('API key is saved. Enter a new key to replace it.', 'polyglot-for-polylang') : ''); ?>"
									autocomplete="off"
								/>
								<p class="description"><?php esc_html_e('Stored encrypted in WordPress options. Keep this key private.', 'polyglot-for-polylang'); ?></p>
							</td>
						</tr>
					</table>
					<?php submit_button(__('Save API Key', 'polyglot-for-polylang')); ?>
				</form>
				<hr style="margin: 18px 0;" />
				<form method="post" action="">
					<?php wp_nonce_field('polyglot_form', 'polyglot_nonce'); ?>
					<input type="hidden" name="polyglot_action" value="test_api_key" />
					<input type="hidden" name="polyglot_tab" value="configuration" />
					<p class="description" style="margin-top:0;">
						<?php esc_html_e('Run a live check against Google Cloud Translation API using the currently saved key.', 'polyglot-for-polylang'); ?>
					</p>
					<?php submit_button(__('Test Saved API Key', 'polyglot-for-polylang'), 'secondary', 'submit', false); ?>
				</form>
			</div>
			<?php
		}

		private function render_translation_strings_tab(array $languages, array $groups, array $job): void {
			$formatted_errors = $this->format_job_errors(isset($job['errors']) && is_array($job['errors']) ? $job['errors'] : array());
			$default_source_language = '';
			if (function_exists('pll_default_language')) {
				$default_source_language = (string) pll_default_language('slug');
			}
			if ($default_source_language === '' || !isset($languages[$default_source_language])) {
				$default_source_language = (string) array_key_first($languages);
			}
			?>
			<div class="card" style="max-width: 900px; padding: 20px; margin-top: 16px;">
				<h2><?php esc_html_e('Run Missing String Translations', 'polyglot-for-polylang'); ?></h2>
				<p class="description" style="margin-top:0;">
					<?php esc_html_e('This tool only translates entries in Polylang String Translations. It does not translate posts, pages, or other WordPress objects.', 'polyglot-for-polylang'); ?>
				</p>
				<form method="post" action="">
					<?php wp_nonce_field('polyglot_form', 'polyglot_nonce'); ?>
					<input type="hidden" name="polyglot_action" value="start_translation" />
					<input type="hidden" name="polyglot_tab" value="translation-strings" />
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="polyglot_group"><?php esc_html_e('Polylang Group', 'polyglot-for-polylang'); ?></label></th>
							<td>
								<select name="polyglot_group" id="polyglot_group" required>
									<option value=""><?php esc_html_e('Select a group', 'polyglot-for-polylang'); ?></option>
									<?php foreach ($groups as $group) : ?>
										<option value="<?php echo esc_attr($group); ?>"><?php echo esc_html($group); ?></option>
									<?php endforeach; ?>
								</select>
								<?php if (empty($groups)) : ?>
									<p class="description">
										<?php esc_html_e('No Polylang string groups were detected. Ensure your theme/plugins register strings with Polylang and that they are visible in Polylang > String translations.', 'polyglot-for-polylang'); ?>
									</p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="polyglot_source_language"><?php esc_html_e('Source Language', 'polyglot-for-polylang'); ?></label></th>
							<td>
								<select name="polyglot_source_language" id="polyglot_source_language" required>
									<option value=""><?php esc_html_e('Select source language', 'polyglot-for-polylang'); ?></option>
									<?php foreach ($languages as $slug => $label) : ?>
										<option value="<?php echo esc_attr($slug); ?>" <?php selected($slug, $default_source_language); ?>><?php echo esc_html($label); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e('Target Languages', 'polyglot-for-polylang'); ?></th>
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
					<?php submit_button(__('Start Translation', 'polyglot-for-polylang'), 'primary', 'submit', true); ?>
				</form>
			</div>

			<div class="card" style="max-width: 900px; padding: 20px; margin-top: 16px;">
				<h2><?php esc_html_e('Job Progress', 'polyglot-for-polylang'); ?></h2>
				<div id="polyglot-progress">
					<?php if (is_array($job) && !empty($job)) : ?>
						<p><strong><?php esc_html_e('Status:', 'polyglot-for-polylang'); ?></strong> <span id="polyglot-status"><?php echo esc_html((string) ($job['status'] ?? 'idle')); ?></span></p>
						<p><strong><?php esc_html_e('Remaining:', 'polyglot-for-polylang'); ?></strong> <span id="polyglot-remaining"><?php echo esc_html((string) (is_array($job['queue'] ?? null) ? count($job['queue']) : 0)); ?></span></p>
						<p><strong><?php esc_html_e('Translated:', 'polyglot-for-polylang'); ?></strong> <span id="polyglot-translated"><?php echo esc_html((string) ((int) ($job['totals']['translated'] ?? 0))); ?></span></p>
						<p><strong><?php esc_html_e('Skipped:', 'polyglot-for-polylang'); ?></strong> <span id="polyglot-skipped"><?php echo esc_html((string) ((int) ($job['totals']['skipped'] ?? 0))); ?></span></p>
						<p><strong><?php esc_html_e('Failed:', 'polyglot-for-polylang'); ?></strong> <span id="polyglot-failed"><?php echo esc_html((string) ((int) ($job['totals']['failed'] ?? 0))); ?></span></p>
						<p style="color:#b32d2e;" id="polyglot-last-error"><?php echo esc_html((string) ($job['last_error'] ?? '')); ?></p>
						<div id="polyglot-errors-panel" style="<?php echo empty($formatted_errors) ? 'display:none;' : ''; ?> margin-top: 12px;">
							<p><strong><?php esc_html_e('Failure details', 'polyglot-for-polylang'); ?></strong></p>
							<ol id="polyglot-errors-list" style="margin: 8px 0 0 18px;">
								<?php foreach ($formatted_errors as $error_item) : ?>
									<li><?php echo esc_html($error_item); ?></li>
								<?php endforeach; ?>
							</ol>
							<p class="description"><?php esc_html_e('Tip: you can inspect full raw payload with WP-CLI: wp option get polyglot_translation_job --format=json', 'polyglot-for-polylang'); ?></p>
						</div>
					<?php else : ?>
						<p><?php esc_html_e('No translation job started yet.', 'polyglot-for-polylang'); ?></p>
					<?php endif; ?>
				</div>
			</div>
			<?php
		}

		private function render_pages_posts_cpt_tab(array $languages, array $job): void {
			$formatted_errors = $this->format_job_errors(isset($job['errors']) && is_array($job['errors']) ? $job['errors'] : array());
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
				<h2><?php esc_html_e('Pages, Posts and CPT', 'polyglot-for-polylang'); ?></h2>
				<p class="description" style="margin-top:0;">
					<?php esc_html_e('Polyglot only translates items when both the source and target posts (or pages/CPT entries) already exist. Create the target-language items first, then run this translation process.', 'polyglot-for-polylang'); ?>
				</p>
				<form method="post" action="">
					<?php wp_nonce_field('polyglot_form', 'polyglot_nonce'); ?>
					<input type="hidden" name="polyglot_action" value="start_content_translation" />
					<input type="hidden" name="polyglot_tab" value="pages-posts-cpt" />
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="polyglot_content_type"><?php esc_html_e('Content Type', 'polyglot-for-polylang'); ?></label></th>
							<td>
								<select name="polyglot_content_type" id="polyglot_content_type" required>
									<option value=""><?php esc_html_e('Select content type', 'polyglot-for-polylang'); ?></option>
									<?php foreach ($post_type_options as $slug => $label) : ?>
										<option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($label); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php esc_html_e('Choose one content type to translate: pages, posts, or a custom post type.', 'polyglot-for-polylang'); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e('Translation Scope', 'polyglot-for-polylang'); ?></th>
							<td>
								<label style="display:block; margin-bottom: 6px;">
									<input type="radio" name="polyglot_content_scope" value="default_only" checked />
									<?php esc_html_e('Translate only default content (title, content, excerpt).', 'polyglot-for-polylang'); ?>
								</label>
								<label style="display:block; margin-bottom: 6px;">
									<input type="radio" name="polyglot_content_scope" value="with_custom_fields" />
									<?php esc_html_e('Translate default content and custom fields.', 'polyglot-for-polylang'); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="polyglot_content_source_language"><?php esc_html_e('Source Language', 'polyglot-for-polylang'); ?></label></th>
							<td>
								<select name="polyglot_content_source_language" id="polyglot_content_source_language" required>
									<option value=""><?php esc_html_e('Select source language', 'polyglot-for-polylang'); ?></option>
									<?php foreach ($languages as $slug => $label) : ?>
										<option value="<?php echo esc_attr($slug); ?>" <?php selected($slug, $default_source_language); ?>><?php echo esc_html($label); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e('Target Languages', 'polyglot-for-polylang'); ?></th>
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
							<th scope="row"><?php esc_html_e('Confirm Custom Fields', 'polyglot-for-polylang'); ?></th>
							<td>
								<label>
									<input type="checkbox" id="polyglot_confirm_meta_translation" name="polyglot_confirm_meta_translation" value="1" />
									<?php esc_html_e('I am aware that translating some custom fields could affect website behavior.', 'polyglot-for-polylang'); ?>
								</label>
								<p class="description">
									<?php esc_html_e('Polyglot will list detected custom fields in a warning notice. Confirm to proceed.', 'polyglot-for-polylang'); ?>
								</p>
							</td>
						</tr>
					</table>
					<div id="polyglot-content-run-summary" class="notice notice-info inline" style="margin: 12px 0 0;">
						<p id="polyglot-content-run-summary-text"><?php esc_html_e('Run summary will appear here.', 'polyglot-for-polylang'); ?></p>
					</div>
					<?php submit_button(__('Start Translation', 'polyglot-for-polylang'), 'primary', 'submit', true, array('id' => 'polyglot-content-submit')); ?>
				</form>
				<p class="description">
					<?php esc_html_e('Only existing translated target items are processed. Missing target posts are skipped.', 'polyglot-for-polylang'); ?>
				</p>
			</div>
			<div class="card" style="max-width: 900px; padding: 20px; margin-top: 16px;">
				<h2><?php esc_html_e('Job Progress', 'polyglot-for-polylang'); ?></h2>
				<div id="polyglot-content-progress">
					<?php if (is_array($job) && !empty($job) && (($job['job_type'] ?? '') === 'content')) : ?>
						<p><strong><?php esc_html_e('Status:', 'polyglot-for-polylang'); ?></strong> <span id="polyglot-content-status"><?php echo esc_html((string) ($job['status'] ?? 'idle')); ?></span></p>
						<p><strong><?php esc_html_e('Remaining:', 'polyglot-for-polylang'); ?></strong> <span id="polyglot-content-remaining"><?php echo esc_html((string) (is_array($job['queue'] ?? null) ? count($job['queue']) : 0)); ?></span></p>
						<p><strong><?php esc_html_e('Scanned:', 'polyglot-for-polylang'); ?></strong> <span id="polyglot-content-scanned"><?php echo esc_html((string) ((int) ($job['totals']['scanned'] ?? 0))); ?></span></p>
						<p><strong><?php esc_html_e('Translatable:', 'polyglot-for-polylang'); ?></strong> <span id="polyglot-content-translatable"><?php echo esc_html((string) ((int) ($job['totals']['translatable'] ?? 0))); ?></span></p>
						<p><strong><?php esc_html_e('Translated:', 'polyglot-for-polylang'); ?></strong> <span id="polyglot-content-translated"><?php echo esc_html((string) ((int) ($job['totals']['translated'] ?? 0))); ?></span></p>
						<p><strong><?php esc_html_e('Skipped:', 'polyglot-for-polylang'); ?></strong> <span id="polyglot-content-skipped"><?php echo esc_html((string) ((int) ($job['totals']['skipped'] ?? 0))); ?></span></p>
						<p><strong><?php esc_html_e('Failed:', 'polyglot-for-polylang'); ?></strong> <span id="polyglot-content-failed"><?php echo esc_html((string) ((int) ($job['totals']['failed'] ?? 0))); ?></span></p>
						<p style="color:#b32d2e;" id="polyglot-content-last-error"><?php echo esc_html((string) ($job['last_error'] ?? '')); ?></p>
						<div id="polyglot-content-errors-panel" style="<?php echo empty($formatted_errors) ? 'display:none;' : ''; ?> margin-top: 12px;">
							<p><strong><?php esc_html_e('Failure details', 'polyglot-for-polylang'); ?></strong></p>
							<ol id="polyglot-content-errors-list" style="margin: 8px 0 0 18px;">
								<?php foreach ($formatted_errors as $error_item) : ?>
									<li><?php echo esc_html($error_item); ?></li>
								<?php endforeach; ?>
							</ol>
							<div id="polyglot-content-error-guidance" class="notice notice-warning inline" style="margin: 10px 0 0;">
								<p><?php esc_html_e('If failures mention API access, verify API key restrictions, billing, and that Cloud Translation API is enabled for the same project.', 'polyglot-for-polylang'); ?></p>
							</div>
							<p class="description"><?php esc_html_e('Tip: you can inspect full raw payload with WP-CLI: wp option get polyglot_translation_job --format=json', 'polyglot-for-polylang'); ?></p>
						</div>
					<?php else : ?>
						<p><?php esc_html_e('No content translation job started yet.', 'polyglot-for-polylang'); ?></p>
					<?php endif; ?>
				</div>
			</div>
			<?php
		}

		private function get_post_type_options(): array {
			$options = array(
				'page' => __('Pages', 'polyglot-for-polylang'),
				'post' => __('Posts', 'polyglot-for-polylang'),
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

		private function format_job_errors(array $errors): array {
			$messages = array();
			foreach ($errors as $error) {
				if (!is_array($error)) {
					continue;
				}

				$message = isset($error['message']) ? trim((string) $error['message']) : '';
				if ($message === '') {
					$message = __('Unknown translation error.', 'polyglot-for-polylang');
				}

				$language = isset($error['language']) ? trim((string) $error['language']) : '';
				$field_key = isset($error['field_key']) ? trim((string) $error['field_key']) : '';
				$name = isset($error['name']) ? trim((string) $error['name']) : '';
				$source_post_id = isset($error['source_post_id']) ? (int) $error['source_post_id'] : 0;
				$target_post_id = isset($error['target_post_id']) ? (int) $error['target_post_id'] : 0;
				$kind = $this->classify_error_message($message);

				$parts = array();
				if ($kind !== '') {
					$parts[] = '[' . strtoupper($kind) . ']';
				}
				$parts[] = $message;

				if ($name !== '') {
					$parts[] = sprintf(__('name: %s', 'polyglot-for-polylang'), $name);
				}
				if ($field_key !== '') {
					$parts[] = sprintf(__('field: %s', 'polyglot-for-polylang'), $field_key);
				}
				if ($language !== '') {
					$parts[] = sprintf(__('lang: %s', 'polyglot-for-polylang'), $language);
				}
				if ($source_post_id > 0 || $target_post_id > 0) {
					$parts[] = sprintf(__('source/target: %1$d/%2$d', 'polyglot-for-polylang'), $source_post_id, $target_post_id);
				}

				$messages[] = implode(' | ', $parts);
			}

			return $messages;
		}

		private function classify_error_message(string $message): string {
			$normalized = strtolower($message);
			$api_markers = array(
				'permission_denied',
				'request_denied',
				'invalid argument',
				'api key',
				'quota',
				'billing',
				'google',
				'unauthenticated',
				'forbidden',
				'429',
			);
			foreach ($api_markers as $marker) {
				if (strpos($normalized, $marker) !== false) {
					return 'api';
				}
			}

			$save_markers = array(
				'wp_update_post',
				'update_post_meta',
				'unsupported',
				'invalid content translation task payload',
				'could not save',
				'save error',
			);
			foreach ($save_markers as $marker) {
				if (strpos($normalized, $marker) !== false) {
					return 'save';
				}
			}

			return 'other';
		}
	}
}
