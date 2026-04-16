<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

if (!class_exists('Polyglot_Plugin')) {
	final class Polyglot_Plugin {
		public const OPTION_API_KEY = 'polyglot_google_api_key';
		public const OPTION_JOB = 'polyglot_translation_job';
		public const OPTION_NOTICE = 'polyglot_admin_notice';
		public const CRON_HOOK = 'polyglot_process_job';
		public const MENU_SLUG = 'polyglot';

		private const BATCH_SIZE = 25;
		private const API_KEY_ENCRYPTION_PREFIX = 'polyglot_enc_v1:';

		private Polyglot_Translation_Service $translation_service;
		private Polyglot_Admin_Page $admin_page;

		public function __construct() {
			$this->translation_service = new Polyglot_Translation_Service();
			$this->admin_page = new Polyglot_Admin_Page($this->translation_service);

			register_activation_hook(POLYGLOT_FILE, array($this, 'activate'));
			register_deactivation_hook(POLYGLOT_FILE, array($this, 'deactivate'));
			add_action('plugins_loaded', array($this, 'bootstrap'), 20);
		}

		public function bootstrap(): void {
			if (!$this->translation_service->is_polylang_ready()) {
				add_action('admin_notices', array($this, 'render_missing_polylang_notice'));
				return;
			}

			add_action('admin_menu', array($this, 'register_admin_page'));
			add_action('admin_init', array($this, 'handle_admin_post'));
			add_action('admin_enqueue_scripts', array($this->admin_page, 'enqueue_assets'));

			add_action('wp_ajax_polyglot_job_status', array($this->admin_page, 'ajax_job_status'));
			add_action('wp_ajax_polyglot_content_meta_preview', array($this, 'ajax_content_meta_preview'));
			add_action(self::CRON_HOOK, array($this, 'process_job_batch'));
		}

		public function activate(): void {
			if ($this->translation_service->is_polylang_ready()) {
				return;
			}

			if (!function_exists('deactivate_plugins')) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			deactivate_plugins(plugin_basename(POLYGLOT_FILE));

			wp_die(
				esc_html__('Polyglot requires Polylang to be installed and active before activation.', 'polyglot'),
				esc_html__('Plugin dependency missing', 'polyglot'),
				array(
					'response' => 200,
					'back_link' => true,
				)
			);
		}

		public function deactivate(): void {
			wp_clear_scheduled_hook(self::CRON_HOOK);
		}

		public function register_admin_page(): void {
			add_management_page(
				__('Polyglot', 'polyglot'),
				__('Polyglot', 'polyglot'),
				'manage_options',
				self::MENU_SLUG,
				array($this->admin_page, 'render')
			);
		}

		public function render_missing_polylang_notice(): void {
			if (!current_user_can('activate_plugins')) {
				return;
			}

			?>
			<div class="notice notice-error">
				<p><?php esc_html_e('Polyglot requires Polylang. Please install and activate Polylang first.', 'polyglot'); ?></p>
			</div>
			<?php
		}

		public function handle_admin_post(): void {
			if (!is_admin() || !current_user_can('manage_options')) {
				return;
			}

			$page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
			if ($page !== self::MENU_SLUG) {
				return;
			}

			if (!isset($_POST['polyglot_action'])) {
				return;
			}

			// Nonce gate for all admin form POST reads in this request lifecycle.
			check_admin_referer('polyglot_form', 'polyglot_nonce');

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_admin_post() above.
			$action = sanitize_key((string) wp_unslash($_POST['polyglot_action']));
			$tab = $this->get_posted_tab();

			if ($action === 'save_api_key') {
				$this->save_api_key($tab);
			} elseif ($action === 'start_translation') {
				$this->start_translation_job($tab);
			} elseif ($action === 'start_content_translation') {
				$this->start_content_translation_job($tab);
			}
		}

		private function save_api_key(string $tab): void {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_admin_post().
			$api_key = isset($_POST['polyglot_api_key']) ? sanitize_text_field((string) wp_unslash($_POST['polyglot_api_key'])) : '';
			if ($api_key === '') {
				update_option(self::OPTION_API_KEY, '', false);
				$this->set_notice('success', __('API key cleared.', 'polyglot'));
				$this->redirect_to_page($tab);
			}

			$encrypted_key = $this->encrypt_api_key($api_key);
			if ($encrypted_key === '') {
				$this->set_notice('error', __('Could not securely store the API key on this server.', 'polyglot'));
				$this->redirect_to_page($tab);
			}

			update_option(self::OPTION_API_KEY, $encrypted_key, false);
			$this->set_notice('success', __('API key saved.', 'polyglot'));
			$this->redirect_to_page($tab);
		}

		private function start_translation_job(string $tab): void {
			if (!$this->translation_service->is_polylang_ready()) {
				$this->set_notice('error', __('Polylang is not active or does not expose required APIs.', 'polyglot'));
				$this->redirect_to_page($tab);
			}

			$api_key = $this->get_api_key();
			if ($api_key === '') {
				$this->set_notice('error', __('Please save a Google API key first.', 'polyglot'));
				$this->redirect_to_page($tab);
			}

			// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_admin_post().
			$group = isset($_POST['polyglot_group']) ? sanitize_text_field((string) wp_unslash($_POST['polyglot_group'])) : '';
			$source_language = isset($_POST['polyglot_source_language']) ? sanitize_key((string) wp_unslash($_POST['polyglot_source_language'])) : '';
			$target_languages = $this->get_posted_sanitized_key_array('polyglot_languages');
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			$target_languages = array_values(array_unique(array_diff($target_languages, array($source_language))));

			if ($group === '' || $source_language === '' || empty($target_languages)) {
				$this->set_notice('error', __('Select a group, source language, and at least one target language.', 'polyglot'));
				$this->redirect_to_page($tab);
			}

			$queue = $this->translation_service->build_queue($group, $source_language, $target_languages);
			if (empty($queue)) {
				$this->set_notice('success', __('No missing translations found for the selected group/languages.', 'polyglot'));
				$this->redirect_to_page($tab);
			}

			$job = array(
				'job_type' => 'strings',
				'status' => 'queued',
				'created_at' => time(),
				'updated_at' => time(),
				'group' => $group,
				'source_language' => $source_language,
				'target_languages' => $target_languages,
				'queue' => $queue,
				'totals' => array(
					'scanned' => count($queue),
					'translated' => 0,
					'skipped' => 0,
					'failed' => 0,
				),
				'errors' => array(),
				'last_error' => '',
			);

			$this->queue_job_and_redirect($job, $tab, __('Translation job queued. Processing will continue in background.', 'polyglot'));
		}

		private function start_content_translation_job(string $tab): void {
			if (!$this->translation_service->is_polylang_ready()) {
				$this->set_notice('error', __('Polylang is not active or does not expose required APIs.', 'polyglot'));
				$this->redirect_to_page($tab);
			}

			$api_key = $this->get_api_key();
			if ($api_key === '') {
				$this->set_notice('error', __('Please save a Google API key first.', 'polyglot'));
				$this->redirect_to_page($tab);
			}

			// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_admin_post().
			$content_type = isset($_POST['polyglot_content_type']) ? sanitize_key((string) wp_unslash($_POST['polyglot_content_type'])) : '';
			$source_language = isset($_POST['polyglot_content_source_language']) ? sanitize_key((string) wp_unslash($_POST['polyglot_content_source_language'])) : '';
			$content_scope = isset($_POST['polyglot_content_scope']) ? sanitize_key((string) wp_unslash($_POST['polyglot_content_scope'])) : 'default_only';
			$target_languages = $this->get_posted_sanitized_key_array('polyglot_content_languages');
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			$target_languages = array_values(array_unique(array_diff($target_languages, array($source_language))));

			$allowed_content_types = $this->get_allowed_content_types();
			$is_valid_content_type = isset($allowed_content_types[$content_type]);
			$include_custom_fields = $content_scope === 'with_custom_fields';
			if (!in_array($content_scope, array('default_only', 'with_custom_fields'), true) || !$is_valid_content_type || $source_language === '' || empty($target_languages)) {
				$this->set_notice('error', __('Select a valid content type, source language, and at least one target language.', 'polyglot'));
				$this->redirect_to_page($tab);
			}

			$queue_data = $this->translation_service->build_content_queue($content_type, $source_language, $target_languages, $include_custom_fields);
			$queue = isset($queue_data['queue']) && is_array($queue_data['queue']) ? $queue_data['queue'] : array();
			$scanned = isset($queue_data['scanned']) ? (int) $queue_data['scanned'] : 0;
			$meta_keys = isset($queue_data['meta_keys']) && is_array($queue_data['meta_keys']) ? $queue_data['meta_keys'] : array();
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_admin_post().
			$confirm_meta_translation = isset($_POST['polyglot_confirm_meta_translation']) ? sanitize_key((string) wp_unslash($_POST['polyglot_confirm_meta_translation'])) : '';

			if (empty($queue)) {
				$this->set_notice('success', __('No eligible content fields found for the selected content type/languages.', 'polyglot'));
				$this->redirect_to_page($tab);
			}

			if ($include_custom_fields && $confirm_meta_translation !== '1') {
				$this->set_notice(
					'warning',
					sprintf(
						/* translators: %s: comma-separated meta keys */
						__('Custom fields selected for translation: %s. Review and confirm by checking the custom field confirmation box, then start again.', 'polyglot'),
						!empty($meta_keys) ? implode(', ', $meta_keys) : __('none detected for this selection', 'polyglot')
					)
				);
				$this->redirect_to_page($tab);
			}

			$job = array(
				'job_type' => 'content',
				'status' => 'queued',
				'created_at' => time(),
				'updated_at' => time(),
				'content_type' => $content_type,
				'source_language' => $source_language,
				'target_languages' => $target_languages,
				'queue' => $queue,
				'totals' => array(
					'scanned' => $scanned,
					'translatable' => count($queue),
					'translated' => 0,
					'skipped' => 0,
					'failed' => 0,
				),
				'errors' => array(),
				'last_error' => '',
			);

			$this->queue_job_and_redirect($job, $tab, __('Content translation job queued. Processing will continue in background.', 'polyglot'));
		}

		public function process_job_batch(): void {
			$job = get_option(self::OPTION_JOB, array());
			if (empty($job) || !is_array($job) || empty($job['queue']) || !is_array($job['queue'])) {
				return;
			}

			$job['status'] = 'running';
			$job['updated_at'] = time();

			$api_key = $this->get_api_key();
			if ($api_key === '') {
				$job['status'] = 'failed';
				$job['last_error'] = __('Missing Google API key.', 'polyglot');
				update_option(self::OPTION_JOB, $job, false);
				return;
			}

			$job_type = isset($job['job_type']) ? (string) $job['job_type'] : 'strings';
			$batch = array_splice($job['queue'], 0, self::BATCH_SIZE);
			if ($job_type === 'content') {
				$this->process_content_batch($job, $batch, $api_key);
			} else {
				$this->process_strings_batch($job, $batch, $api_key);
			}

			if (empty($job['queue'])) {
				$job['status'] = empty($job['errors']) ? 'done' : 'done_with_errors';
				$this->set_notice(
					$job['status'] === 'done' ? 'success' : 'warning',
					sprintf(
						/* translators: 1: translated, 2: skipped, 3: failed */
						__('Translation completed. Translated: %1$d, skipped: %2$d, failed: %3$d.', 'polyglot'),
						(int) $job['totals']['translated'],
						(int) $job['totals']['skipped'],
						(int) $job['totals']['failed']
					)
				);
			}

			$job['updated_at'] = time();
			update_option(self::OPTION_JOB, $job, false);

			if (!empty($job['queue']) && !wp_next_scheduled(self::CRON_HOOK)) {
				wp_schedule_single_event(time() + 5, self::CRON_HOOK);
			}
		}

		private function get_posted_tab(): string {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_admin_post().
			$tab = isset($_POST['polyglot_tab']) ? sanitize_key((string) wp_unslash($_POST['polyglot_tab'])) : 'configuration';
			if (!in_array($tab, array('configuration', 'translation-strings', 'pages-posts-cpt'), true)) {
				return 'configuration';
			}

			return $tab;
		}

		private function get_posted_sanitized_key_array(string $field): array {
			$values = filter_input(INPUT_POST, $field, FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
			if (!is_array($values)) {
				return array();
			}

			$result = array();
			foreach ($values as $value) {
				if (!is_scalar($value)) {
					continue;
				}

				$sanitized = sanitize_key((string) $value);
				if ($sanitized === '') {
					continue;
				}

				$result[] = $sanitized;
			}

			return array_values(array_unique($result));
		}

		private function set_notice(string $type, string $message): void {
			update_option(
				self::OPTION_NOTICE,
				array(
					'type' => $type,
					'message' => $message,
				),
				false
			);
		}

		private function redirect_to_page(string $tab = 'configuration'): void {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page' => self::MENU_SLUG,
						'tab' => $tab,
					),
					admin_url('tools.php')
				)
			);
			exit;
		}

		private function queue_job_and_redirect(array $job, string $tab, string $notice_message): void {
			update_option(self::OPTION_JOB, $job, false);
			if (!wp_next_scheduled(self::CRON_HOOK)) {
				wp_schedule_single_event(time() + 5, self::CRON_HOOK);
			}

			$this->set_notice('success', $notice_message);
			$this->redirect_to_page($tab);
		}

		private function process_strings_batch(array &$job, array $batch, string $api_key): void {
			foreach ($batch as $task) {
				$translation = $this->translation_service->translate_text($task['source_text'], $task['source_language'], $task['target_language'], $api_key);
				if (is_wp_error($translation)) {
					$job['totals']['failed']++;
					$job['errors'][] = array(
						'name' => $task['name'],
						'language' => $task['target_language'],
						'message' => $translation->get_error_message(),
					);
					$job['last_error'] = $translation->get_error_message();
					continue;
				}

				if ($translation === '') {
					$job['totals']['skipped']++;
					continue;
				}

				$saved = $this->translation_service->set_translation($task['source'], $task['target_language'], $translation, $task['name']);
				if ($saved) {
					$job['totals']['translated']++;
				} else {
					$job['totals']['failed']++;
					$save_error = $this->translation_service->get_last_save_error();
					if ($save_error === '') {
						$save_error = __('Unknown save error.', 'polyglot');
					}
					$job['last_error'] = sprintf(
						/* translators: 1: string name, 2: language slug, 3: save error detail */
						__('Could not save translation for "%1$s" in language "%2$s". Details: %3$s', 'polyglot'),
						(string) $task['name'],
						(string) $task['target_language'],
						$save_error
					);
					$job['errors'][] = array(
						'name' => $task['name'],
						'language' => $task['target_language'],
						'message' => $save_error,
					);
				}
			}
		}

		private function process_content_batch(array &$job, array $batch, string $api_key): void {
			foreach ($batch as $task) {
				$translation = $this->translation_service->translate_text($task['source_text'], $task['source_language'], $task['target_language'], $api_key);
				if (is_wp_error($translation)) {
					$job['totals']['failed']++;
					$job['errors'][] = array(
						'source_post_id' => (int) ($task['source_post_id'] ?? 0),
						'target_post_id' => (int) ($task['target_post_id'] ?? 0),
						'field_key' => (string) ($task['field_key'] ?? ''),
						'language' => (string) ($task['target_language'] ?? ''),
						'message' => $translation->get_error_message(),
					);
					$job['last_error'] = $translation->get_error_message();
					continue;
				}

				if ($translation === '') {
					$job['totals']['skipped']++;
					continue;
				}

				$saved = $this->translation_service->save_content_translation($task, $translation);
				if ($saved) {
					$job['totals']['translated']++;
					continue;
				}

				$job['totals']['failed']++;
				$save_error = $this->translation_service->get_last_save_error();
				if ($save_error === '') {
					$save_error = __('Unknown save error.', 'polyglot');
				}
				$job['errors'][] = array(
					'source_post_id' => (int) ($task['source_post_id'] ?? 0),
					'target_post_id' => (int) ($task['target_post_id'] ?? 0),
					'field_key' => (string) ($task['field_key'] ?? ''),
					'language' => (string) ($task['target_language'] ?? ''),
					'message' => $save_error,
				);
				$job['last_error'] = $save_error;
			}
		}

		private function get_allowed_content_types(): array {
			$content_types = array(
				'page' => true,
				'post' => true,
			);

			$custom_post_types = get_post_types(
				array(
					'public' => true,
					'_builtin' => false,
				),
				'names'
			);
			if (!is_array($custom_post_types)) {
				return $content_types;
			}

			foreach ($custom_post_types as $post_type) {
				$post_type = sanitize_key((string) $post_type);
				if ($post_type === '') {
					continue;
				}
				$content_types[$post_type] = true;
			}

			return $content_types;
		}

		public function ajax_content_meta_preview(): void {
			if (!current_user_can('manage_options')) {
				wp_send_json_error(array('message' => __('Unauthorized.', 'polyglot')), 403);
			}

			check_ajax_referer('polyglot_status', 'nonce');

			// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() above.
			$content_type = isset($_POST['content_type']) ? sanitize_key((string) wp_unslash($_POST['content_type'])) : '';
			$source_language = isset($_POST['source_language']) ? sanitize_key((string) wp_unslash($_POST['source_language'])) : '';
			$target_languages = $this->get_posted_sanitized_key_array('target_languages');
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			$target_languages = array_values(array_unique(array_diff($target_languages, array($source_language))));

			$allowed_content_types = $this->get_allowed_content_types();
			$is_valid_content_type = isset($allowed_content_types[$content_type]);
			if (!$is_valid_content_type || $source_language === '' || empty($target_languages)) {
				wp_send_json_success(
					array(
						'meta_keys' => array(),
					)
				);
			}

			$queue_data = $this->translation_service->build_content_queue($content_type, $source_language, $target_languages, true);
			$meta_keys = isset($queue_data['meta_keys']) && is_array($queue_data['meta_keys']) ? array_values($queue_data['meta_keys']) : array();
			wp_send_json_success(
				array(
					'meta_keys' => $meta_keys,
				)
			);
		}

		private function get_api_key(): string {
			$stored_value = (string) get_option(self::OPTION_API_KEY, '');
			$stored_value = trim($stored_value);
			if ($stored_value === '') {
				return '';
			}

			$decrypted = $this->decrypt_api_key($stored_value);
			if ($decrypted !== null) {
				return $decrypted;
			}

			// Backward compatibility with previously stored plaintext keys.
			return $stored_value;
		}

		private function encrypt_api_key(string $api_key): string {
			if (!function_exists('openssl_encrypt')) {
				return '';
			}

			$key_material = wp_salt('auth');
			if (!is_string($key_material) || $key_material === '') {
				return '';
			}

			$iv = function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16);
			if ($iv === false || strlen($iv) !== 16) {
				return '';
			}

			$key = hash('sha256', $key_material, true);
			$ciphertext = openssl_encrypt($api_key, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
			if (!is_string($ciphertext) || $ciphertext === '') {
				return '';
			}

			return self::API_KEY_ENCRYPTION_PREFIX . base64_encode($iv . $ciphertext);
		}

		private function decrypt_api_key(string $stored_value): ?string {
			if (strpos($stored_value, self::API_KEY_ENCRYPTION_PREFIX) !== 0) {
				return null;
			}

			if (!function_exists('openssl_decrypt')) {
				return '';
			}

			$payload = substr($stored_value, strlen(self::API_KEY_ENCRYPTION_PREFIX));
			$decoded = base64_decode($payload, true);
			if (!is_string($decoded) || strlen($decoded) <= 16) {
				return '';
			}

			$iv = substr($decoded, 0, 16);
			$ciphertext = substr($decoded, 16);
			$key_material = wp_salt('auth');
			if (!is_string($key_material) || $key_material === '') {
				return '';
			}

			$key = hash('sha256', $key_material, true);
			$plaintext = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
			if (!is_string($plaintext)) {
				return '';
			}

			return trim($plaintext);
		}
	}
}
