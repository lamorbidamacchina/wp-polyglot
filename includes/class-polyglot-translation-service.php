<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

if (!class_exists('Polyglot_Translation_Service')) {
	final class Polyglot_Translation_Service {
		private string $last_save_error = '';

		public function get_last_save_error(): string {
			return $this->last_save_error;
		}

		public function is_polylang_ready(): bool {
			return function_exists('pll_languages_list') && function_exists('pll_translate_string');
		}

		public function build_queue(string $group, string $source_language, array $target_languages): array {
			$strings = $this->get_group_strings($group);
			$queue = array();

			foreach ($strings as $entry) {
				$source_text = $this->get_source_text($entry['source'], $source_language);
				if ($source_text === '') {
					continue;
				}

				foreach ($target_languages as $target_language) {
					$current = $this->get_translation($entry['source'], $target_language);
					if ($current !== '') {
						continue;
					}

					$queue[] = array(
						'name' => $entry['name'],
						'source' => $entry['source'],
						'target_language' => $target_language,
						'source_language' => $source_language,
						'source_text' => $source_text,
					);
				}
			}

			return $queue;
		}

		private function get_source_text(string $source, string $source_language): string {
			if (!function_exists('pll_translate_string')) {
				return $source;
			}

			$translated = pll_translate_string($source, $source_language);
			if (!is_string($translated)) {
				return $source;
			}

			$translated = trim($translated);
			if ($translated === '') {
				return $source;
			}

			return $translated;
		}

		public function translate_text(string $text, string $source_language, string $target_language, string $api_key) {
			$endpoint = add_query_arg(
				array(
					'key' => $api_key,
				),
				'https://translation.googleapis.com/language/translate/v2'
			);
			$response = wp_remote_post(
				$endpoint,
				array(
					'timeout' => 20,
					'headers' => array(
						'Content-Type' => 'application/json; charset=utf-8',
						'Referer' => home_url('/'),
					),
					'body' => wp_json_encode(
						array(
							'q' => $text,
							'source' => $source_language,
							'target' => $target_language,
							'format' => 'text',
						)
					),
				)
			);

			if (is_wp_error($response)) {
				return $response;
			}

			$status_code = (int) wp_remote_retrieve_response_code($response);
			$body = (string) wp_remote_retrieve_body($response);
			$data = json_decode($body, true);

			if ($status_code >= 400) {
				$message = isset($data['error']['message']) ? (string) $data['error']['message'] : __('Google Translate request failed.', 'polyglot-for-polylang');
				return new WP_Error('google_api_error', $message);
			}

			$translated = isset($data['data']['translations'][0]['translatedText']) ? (string) $data['data']['translations'][0]['translatedText'] : '';
			return wp_kses_post(html_entity_decode($translated, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		}

		public function validate_api_key(string $api_key) {
			$api_key = trim($api_key);
			if ($api_key === '') {
				return new WP_Error('missing_api_key', __('Missing API key.', 'polyglot-for-polylang'));
			}

			$endpoint = add_query_arg(
				array(
					'key' => $api_key,
				),
				'https://translation.googleapis.com/language/translate/v2/languages'
			);
			$response = wp_remote_get(
				$endpoint,
				array(
					'timeout' => 20,
					'headers' => array(
						'Accept' => 'application/json',
						'Referer' => home_url('/'),
					),
				)
			);
			if (is_wp_error($response)) {
				return $response;
			}

			$status_code = (int) wp_remote_retrieve_response_code($response);
			$body = (string) wp_remote_retrieve_body($response);
			$data = json_decode($body, true);

			if ($status_code >= 400) {
				$message = isset($data['error']['message']) ? (string) $data['error']['message'] : __('Google API key validation failed.', 'polyglot-for-polylang');
				return new WP_Error('google_api_key_validation_failed', $message);
			}

			$languages = isset($data['data']['languages']) && is_array($data['data']['languages']) ? $data['data']['languages'] : array();
			if (empty($languages)) {
				return new WP_Error('google_api_key_validation_unexpected_response', __('Google API key validation returned an unexpected response.', 'polyglot-for-polylang'));
			}

			return true;
		}

		public function get_available_languages(): array {
			if (!function_exists('pll_languages_list')) {
				return array();
			}

			$slugs = pll_languages_list(array('fields' => 'slug'));
			if (!is_array($slugs)) {
				return array();
			}

			$languages = array();
			foreach ($slugs as $slug) {
				$slug = (string) $slug;
				if ($slug === '') {
					continue;
				}

				$label = strtoupper($slug);
				if (function_exists('PLL') && PLL() && isset(PLL()->model) && method_exists(PLL()->model, 'get_language')) {
					$lang_obj = PLL()->model->get_language($slug);
					if ($lang_obj && isset($lang_obj->name) && is_string($lang_obj->name) && $lang_obj->name !== '') {
						$label = $lang_obj->name;
					}
				}

				$languages[$slug] = $label;
			}

			return $languages;
		}

		public function get_polylang_group_map(): array {
			$all_strings = $this->get_all_registered_strings();
			$groups = array();
			foreach ($all_strings as $string_data) {
				$context = isset($string_data['context']) ? trim((string) $string_data['context']) : '';
				if ($context === '') {
					$context = __('Default', 'polyglot-for-polylang');
				}
				$groups[$context] = $context;
			}

			natcasesort($groups);
			return $groups;
		}

		public function set_translation(string $source, string $lang, string $translation, string $name): bool {
			$this->last_save_error = '';

			if (function_exists('pll_add_string_translation')) {
				$result = pll_add_string_translation($name, $lang, $translation);
				if ($result !== false) {
					return true;
				}
				// If this API exists but fails, continue with other Polylang-compatible fallbacks.
				$this->last_save_error = 'pll_add_string_translation returned false';
			}

			// Polylang core fallback: write string translations through PLL_MO, like Polylang Strings screen.
			if (class_exists('PLL_MO') && function_exists('PLL') && PLL() && isset(PLL()->model) && method_exists(PLL()->model, 'get_language')) {
				$lang_obj = PLL()->model->get_language($lang);
				if ($lang_obj) {
					$mo = new PLL_MO();
					$mo->import_from_db($lang_obj);
					$mo->add_entry($mo->make_entry($source, $translation));
					$mo->export_to_db($lang_obj);
					return true;
				}
				$this->last_save_error = 'PLL_MO fallback could not resolve target language object';
			}

			if (function_exists('PLL') && PLL() && isset(PLL()->model) && method_exists(PLL()->model, 'get_language') && isset(PLL()->model->string_translations)) {
				$lang_obj = PLL()->model->get_language($lang);
				if ($lang_obj && method_exists(PLL()->model->string_translations, 'set_translation')) {
					$result = PLL()->model->string_translations->set_translation($source, $lang_obj, $translation);
					if ($result !== false) {
						return true;
					}
					$this->last_save_error = 'PLL()->model->string_translations->set_translation returned false';
				}
			}

			// Last resort: write directly to Polylang string translations term meta storage.
			$language_term_id = $this->resolve_language_term_id($lang);
			if ($language_term_id > 0) {
				$saved = $this->save_translation_in_term_meta($language_term_id, $source, $translation);
				if ($saved) {
					return true;
				}
				$this->last_save_error = 'Direct term meta fallback failed for _pll_strings_translations';
			} else {
				$this->last_save_error = 'Could not resolve Polylang language term for slug "' . $lang . '"';
			}

			if ($this->last_save_error === '') {
				$this->last_save_error = 'No compatible Polylang save path succeeded';
			}

			return false;
		}

		private function resolve_language_term_id(string $lang_slug): int {
			if ($lang_slug === '') {
				return 0;
			}

			if (function_exists('PLL') && PLL() && isset(PLL()->model) && method_exists(PLL()->model, 'get_language')) {
				$lang_obj = PLL()->model->get_language($lang_slug);
				if ($lang_obj && isset($lang_obj->term_id)) {
					return (int) $lang_obj->term_id;
				}
			}

			$term = get_term_by('slug', $lang_slug, 'language');
			if ($term instanceof WP_Term) {
				return (int) $term->term_id;
			}

			$terms = get_terms(
				array(
					'taxonomy' => 'language',
					'hide_empty' => false,
				)
			);
			if (is_array($terms)) {
				foreach ($terms as $language_term) {
					if (!($language_term instanceof WP_Term)) {
						continue;
					}
					if ((string) $language_term->slug === $lang_slug) {
						return (int) $language_term->term_id;
					}
				}
			}

			return 0;
		}

		private function save_translation_in_term_meta(int $language_term_id, string $source, string $translation): bool {
			if ($language_term_id <= 0 || $source === '') {
				return false;
			}

			$strings = get_term_meta($language_term_id, '_pll_strings_translations', true);
			if (!is_array($strings)) {
				$strings = array();
			}

			$found = false;
			foreach ($strings as $idx => $entry) {
				if (!is_array($entry) || !isset($entry[0])) {
					continue;
				}

				if ((string) $entry[0] !== $source) {
					continue;
				}

				$strings[$idx] = array(wp_slash($source), wp_slash($translation));
				$found = true;
				break;
			}

			if (!$found) {
				$strings[] = array(wp_slash($source), wp_slash($translation));
			}

			$updated = update_term_meta($language_term_id, '_pll_strings_translations', $strings);
			if ($updated !== false) {
				return true;
			}

			$current = get_term_meta($language_term_id, '_pll_strings_translations', true);
			if (!is_array($current)) {
				return false;
			}

			foreach ($current as $entry) {
				if (!is_array($entry) || !isset($entry[0], $entry[1])) {
					continue;
				}
				if ((string) $entry[0] === $source && (string) $entry[1] === $translation) {
					return true;
				}
			}

			return false;
		}

		private function get_group_strings(string $group): array {
			$all_strings = $this->get_all_registered_strings();
			$strings = array();
			foreach ($all_strings as $string_data) {
				$context = isset($string_data['context']) ? trim((string) $string_data['context']) : '';
				if ($context === '') {
					$context = __('Default', 'polyglot-for-polylang');
				}
				if ($context !== $group) {
					continue;
				}

				$source = isset($string_data['string']) ? (string) $string_data['string'] : '';
				if ($source === '') {
					continue;
				}

				$name = isset($string_data['name']) ? (string) $string_data['name'] : $source;
				$strings[] = array(
					'name' => $name,
					'source' => $source,
				);
			}

			return $strings;
		}

		private function get_all_registered_strings(): array {
			$strings = array();
			$seen = array();

			$from_polylang_api = $this->get_strings_from_polylang_api();
			foreach ($from_polylang_api as $item) {
				$key = $this->build_string_key($item);
				if (isset($seen[$key])) {
					continue;
				}
				$seen[$key] = true;
				$strings[] = $item;
			}

			$from_option = $this->get_strings_from_option();
			foreach ($from_option as $item) {
				$key = $this->build_string_key($item);
				if (isset($seen[$key])) {
					continue;
				}
				$seen[$key] = true;
				$strings[] = $item;
			}

			$from_tables = $this->get_strings_from_tables();
			foreach ($from_tables as $item) {
				$key = $this->build_string_key($item);
				if (isset($seen[$key])) {
					continue;
				}
				$seen[$key] = true;
				$strings[] = $item;
			}

			return $strings;
		}

		private function get_strings_from_polylang_api(): array {
			if (!class_exists('PLL_Admin_Strings') || !method_exists('PLL_Admin_Strings', 'get_strings')) {
				return array();
			}

			$registered = PLL_Admin_Strings::get_strings();
			if (!is_array($registered) || empty($registered)) {
				return array();
			}

			$items = array();
			foreach ($registered as $string_data) {
				if (!is_array($string_data)) {
					continue;
				}

				$context = isset($string_data['context']) ? (string) $string_data['context'] : '';
				$source = isset($string_data['string']) ? (string) $string_data['string'] : '';
				$name = isset($string_data['name']) ? (string) $string_data['name'] : $source;
				if ($source === '') {
					continue;
				}

				$items[] = array(
					'context' => $context,
					'name' => $name,
					'string' => $source,
				);
			}

			return $items;
		}

		private function get_strings_from_option(): array {
			$option = get_option('polylang_wpml_strings', array());
			if (!is_array($option)) {
				return array();
			}

			$items = array();
			foreach ($option as $string_data) {
				if (!is_array($string_data)) {
					continue;
				}

				$context = isset($string_data['context']) ? (string) $string_data['context'] : '';
				$source = isset($string_data['string']) ? (string) $string_data['string'] : '';
				$name = isset($string_data['name']) ? (string) $string_data['name'] : $source;
				if ($source === '') {
					continue;
				}

				$items[] = array(
					'context' => $context,
					'name' => $name,
					'string' => $source,
				);
			}

			return $items;
		}

		private function get_strings_from_tables(): array {
			global $wpdb;

			$candidates = array(
				$wpdb->prefix . 'icl_strings',
				$wpdb->prefix . 'pll_strings',
				$wpdb->prefix . 'polylang_strings',
			);

			$items = array();
			foreach ($candidates as $table_name) {
				$cache_group = 'polyglot_translation_service';
				$table_cache_key = 'table_exists_' . md5($table_name);
				$table_exists = wp_cache_get($table_cache_key, $cache_group);
				if (!is_string($table_exists)) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection query, cached via wp_cache_set() below.
					$table_exists = (string) $wpdb->get_var(
						$wpdb->prepare(
							'SHOW TABLES LIKE %s',
							$table_name
						)
					);
					wp_cache_set($table_cache_key, $table_exists, $cache_group, 600);
				}
				if ($table_exists !== $table_name) {
					continue;
				}

				if (!$this->is_safe_sql_identifier($table_name)) {
					continue;
				}

				$columns_cache_key = 'table_columns_' . md5($table_name);
				$columns_rows = wp_cache_get($columns_cache_key, $cache_group);
				if (!is_array($columns_rows)) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is validated and schema read is cached below.
					$columns_rows = $wpdb->get_results("SHOW COLUMNS FROM `{$table_name}`", ARRAY_A);
					if (!is_array($columns_rows)) {
						$columns_rows = array();
					}
					wp_cache_set($columns_cache_key, $columns_rows, $cache_group, 600);
				}
				if (!is_array($columns_rows) || empty($columns_rows)) {
					continue;
				}

				$columns = array();
				foreach ($columns_rows as $row) {
					if (isset($row['Field']) && is_string($row['Field'])) {
						$columns[] = $row['Field'];
					}
				}

				$available_columns = array_fill_keys($columns, true);
				$context_candidates = array('context', 'domain', 'group_name', 'grp');
				$name_candidates = array('name', 'title', 'slug');
				$string_candidates = array('string', 'value', 'original', 'text');

				$has_name_column = false;
				foreach ($name_candidates as $candidate_column) {
					if (isset($available_columns[$candidate_column])) {
						$has_name_column = true;
						break;
					}
				}

				$has_string_column = false;
				foreach ($string_candidates as $candidate_column) {
					if (isset($available_columns[$candidate_column])) {
						$has_string_column = true;
						break;
					}
				}

				if (!$has_name_column || !$has_string_column) {
					continue;
				}
				$context_column = $this->resolve_existing_column($available_columns, $context_candidates);
				$name_column = $this->resolve_existing_column($available_columns, $name_candidates);
				$string_column = $this->resolve_existing_column($available_columns, $string_candidates);
				if ($name_column === '' || $string_column === '') {
					continue;
				}

				$selected_columns = array($name_column, $string_column);
				if ($context_column !== '') {
					$selected_columns[] = $context_column;
				}

				$quoted_columns = array();
				foreach ($selected_columns as $selected_column) {
					if (!$this->is_safe_sql_identifier($selected_column)) {
						continue 2;
					}
					$quoted_columns[] = '`' . $selected_column . '`';
				}

				$query = sprintf(
					'SELECT %1$s FROM `%2$s`',
					implode(', ', $quoted_columns),
					$table_name
				);

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Identifiers are strict allowlisted/validated from table schema.
				$rows = $wpdb->get_results($query, ARRAY_A);
				if (!is_array($rows) || empty($rows)) {
					continue;
				}

				foreach ($rows as $row) {
					$source = isset($row[$string_column]) ? (string) $row[$string_column] : '';
					if ($source === '') {
						continue;
					}

					$name = isset($row[$name_column]) ? (string) $row[$name_column] : '';
					$context = $context_column !== '' && isset($row[$context_column]) ? (string) $row[$context_column] : '';

					$items[] = array(
						'context' => $context,
						'name' => $name !== '' ? $name : $source,
						'string' => $source,
					);
				}
			}

			return $items;
		}

		private function is_safe_sql_identifier(string $identifier): bool {
			if ($identifier === '') {
				return false;
			}

			return (bool) preg_match('/^[A-Za-z0-9_]+$/', $identifier);
		}

		private function resolve_existing_column(array $available_columns, array $candidates): string {
			foreach ($candidates as $candidate) {
				if (isset($available_columns[$candidate])) {
					return $candidate;
				}
			}

			return '';
		}

		private function build_string_key(array $item): string {
			$context = isset($item['context']) ? (string) $item['context'] : '';
			$name = isset($item['name']) ? (string) $item['name'] : '';
			$string = isset($item['string']) ? (string) $item['string'] : '';
			return md5($context . '|' . $name . '|' . $string);
		}

		private function get_translation(string $source, string $lang): string {
			if (!function_exists('pll_translate_string')) {
				return '';
			}

			$translated = pll_translate_string($source, $lang);
			if (!is_string($translated)) {
				return '';
			}

			$translated = trim($translated);
			if ($translated === '' || $translated === $source) {
				return '';
			}

			return $translated;
		}

		public function build_content_queue(string $content_type, string $source_language, array $target_languages, bool $include_custom_fields = true): array {
			$source_post_ids = $this->get_source_post_ids($content_type, $source_language);
			$queue = array();
			$scanned = 0;
			$meta_keys_used = array();

			foreach ($source_post_ids as $source_post_id) {
				foreach ($target_languages as $target_language) {
					$target_post_id = $this->get_target_post_id($source_post_id, $target_language);
					if ($target_post_id <= 0) {
						continue;
					}

					$source_post = get_post($source_post_id);
					$target_post = get_post($target_post_id);
					if (!($source_post instanceof WP_Post) || !($target_post instanceof WP_Post)) {
						continue;
					}

					$core_fields = array(
						'post_title' => array(
							'source' => (string) $source_post->post_title,
							'target' => (string) $target_post->post_title,
						),
						'post_content' => array(
							'source' => (string) $source_post->post_content,
							'target' => (string) $target_post->post_content,
						),
						'post_excerpt' => array(
							'source' => (string) $source_post->post_excerpt,
							'target' => (string) $target_post->post_excerpt,
						),
					);

					foreach ($core_fields as $field_key => $field_data) {
						$scanned++;
						$source_text = (string) $field_data['source'];
						$target_text = (string) $field_data['target'];
						if (!$this->should_translate_field($source_text, $target_text)) {
							continue;
						}

						$queue[] = array(
							'field_type' => 'core',
							'field_key' => $field_key,
							'source_post_id' => $source_post_id,
							'target_post_id' => $target_post_id,
							'source_language' => $source_language,
							'target_language' => $target_language,
							'source_text' => $source_text,
						);
					}

					if ($include_custom_fields) {
						$meta_queue_data = $this->build_meta_queue_items($source_post_id, $target_post_id, $source_language, $target_language);
						$scanned += (int) $meta_queue_data['scanned'];
						if (!empty($meta_queue_data['items'])) {
							$queue = array_merge($queue, $meta_queue_data['items']);
						}
						if (!empty($meta_queue_data['meta_keys']) && is_array($meta_queue_data['meta_keys'])) {
							$meta_keys_used = array_merge($meta_keys_used, $meta_queue_data['meta_keys']);
						}
					}
				}
			}

			return array(
				'queue' => $queue,
				'scanned' => $scanned,
				'meta_keys' => array_values(array_unique($meta_keys_used)),
			);
		}

		public function save_content_translation(array $task, string $translation): bool {
			$this->last_save_error = '';

			$field_type = isset($task['field_type']) ? (string) $task['field_type'] : '';
			$field_key = isset($task['field_key']) ? (string) $task['field_key'] : '';
			$target_post_id = isset($task['target_post_id']) ? (int) $task['target_post_id'] : 0;
			if ($target_post_id <= 0 || $field_key === '') {
				$this->last_save_error = 'Invalid content translation task payload';
				return false;
			}

			if ($field_type === 'core') {
				$allowed_core_fields = array('post_title', 'post_content', 'post_excerpt');
				if (!in_array($field_key, $allowed_core_fields, true)) {
					$this->last_save_error = 'Unsupported core field key';
					return false;
				}

				$result = wp_update_post(
					array(
						'ID' => $target_post_id,
						$field_key => $translation,
					),
					true
				);
				if (!is_wp_error($result)) {
					return true;
				}

				$this->last_save_error = $result->get_error_message();
				return false;
			}

			if ($field_type === 'meta') {
				$updated = update_post_meta($target_post_id, $field_key, $translation);
				if ($updated !== false) {
					return true;
				}

				$current_value = get_post_meta($target_post_id, $field_key, true);
				if ((string) $current_value === $translation) {
					return true;
				}

				$this->last_save_error = 'update_post_meta failed';
				return false;
			}

			$this->last_save_error = 'Unsupported field type';
			return false;
		}

		private function get_source_post_ids(string $content_type, string $source_language): array {
			if (!function_exists('pll_get_post_language')) {
				return array();
			}

			$post_ids = get_posts(
				array(
					'post_type' => $content_type,
					'post_status' => array('publish', 'draft', 'pending', 'future', 'private'),
					'posts_per_page' => -1,
					'fields' => 'ids',
					'no_found_rows' => true,
				)
			);

			if (!is_array($post_ids)) {
				return array();
			}

			$filtered_ids = array();
			foreach ($post_ids as $post_id) {
				$post_id = (int) $post_id;
				if ($post_id <= 0) {
					continue;
				}

				$post_language = pll_get_post_language($post_id, 'slug');
				if (!is_string($post_language) || $post_language !== $source_language) {
					continue;
				}

				$filtered_ids[] = $post_id;
			}

			return $filtered_ids;
		}

		private function get_target_post_id(int $source_post_id, string $target_language): int {
			if (function_exists('pll_get_post')) {
				return (int) pll_get_post($source_post_id, $target_language);
			}

			return 0;
		}

		private function build_meta_queue_items(int $source_post_id, int $target_post_id, string $source_language, string $target_language): array {
			$post_type = get_post_type($source_post_id);
			if (!is_string($post_type) || $post_type === '') {
				return array(
					'items' => array(),
					'scanned' => 0,
				);
			}

			$meta_keys = $this->get_translatable_meta_keys($source_post_id, $post_type);
			$items = array();
			$scanned = 0;
			$meta_keys_used = array();

			foreach ($meta_keys as $meta_key) {
				$source_value = get_post_meta($source_post_id, $meta_key, true);
				$target_value = get_post_meta($target_post_id, $meta_key, true);
				if (!is_scalar($source_value) || (!is_scalar($target_value) && $target_value !== '')) {
					continue;
				}

				$source_text = (string) $source_value;
				$target_text = (string) $target_value;
				if ($this->should_skip_meta_value($source_text) || $this->should_skip_meta_value($target_text)) {
					continue;
				}

				$scanned++;
				if (!$this->should_translate_field($source_text, $target_text)) {
					continue;
				}

				$items[] = array(
					'field_type' => 'meta',
					'field_key' => $meta_key,
					'source_post_id' => $source_post_id,
					'target_post_id' => $target_post_id,
					'source_language' => $source_language,
					'target_language' => $target_language,
					'source_text' => $source_text,
				);
				$meta_keys_used[] = $meta_key;
			}

			return array(
				'items' => $items,
				'scanned' => $scanned,
				'meta_keys' => array_values(array_unique($meta_keys_used)),
			);
		}

		private function get_translatable_meta_keys(int $source_post_id, string $post_type): array {
			$keys = get_post_custom_keys($source_post_id);
			if (!is_array($keys)) {
				return array();
			}

			$filtered = array();
			foreach ($keys as $key) {
				$key = (string) $key;
				if ($key === '') {
					continue;
				}

				// Hidden/protected meta keys are not visible in standard custom fields UI.
				if (is_protected_meta($key, 'post')) {
					continue;
				}
				if ($this->is_technical_meta_key($key)) {
					continue;
				}

				$filtered[] = $key;
			}

			/**
			 * Filter excluded meta keys for Polyglot content translation.
			 */
			$default_excluded = array(
				'item_author',
			);
			$excluded = apply_filters('polyglot_excluded_meta_keys', $default_excluded, $post_type, $source_post_id);
			if (is_array($excluded) && !empty($excluded)) {
				$excluded_lookup = array();
				foreach ($excluded as $excluded_key) {
					$excluded_key = (string) $excluded_key;
					if ($excluded_key === '') {
						continue;
					}
					$excluded_lookup[$excluded_key] = true;
				}

				$filtered = array_values(
					array_filter(
						$filtered,
						static function (string $key) use ($excluded_lookup): bool {
							return !isset($excluded_lookup[$key]);
						}
					)
				);
			}

			/**
			 * Filter auto-detected translatable post meta keys.
			 */
			$filtered = apply_filters('polyglot_translatable_meta_keys', $filtered, $post_type, $source_post_id);
			if (!is_array($filtered)) {
				return array();
			}

			$result = array();
			foreach ($filtered as $key) {
				$key = (string) $key;
				if ($key === '') {
					continue;
				}
				$result[] = $key;
			}

			return array_values(array_unique($result));
		}

		private function should_translate_field(string $source_text, string $target_text): bool {
			$normalized_source = trim($source_text);
			$normalized_target = trim($target_text);
			if ($normalized_source === '') {
				return false;
			}

			return $normalized_target === '' || $normalized_target === $normalized_source;
		}

		private function is_technical_meta_key(string $key): bool {
			$lower = strtolower(trim($key));
			if ($lower === '') {
				return true;
			}

			return (bool) preg_match('/(^|[_-])(id|ids|slug|url|uri|guid|token|nonce|hash|key|secret|password|pass|status|type|mime|timestamp|date|time|lat|lng|lon|longitude|latitude)($|[_-])/i', $lower);
		}

		private function should_skip_meta_value(string $value): bool {
			$value = trim($value);
			if ($value === '') {
				return false;
			}

			if (is_serialized($value)) {
				return true;
			}

			json_decode($value, true);
			if (json_last_error() === JSON_ERROR_NONE && (str_starts_with($value, '{') || str_starts_with($value, '['))) {
				return true;
			}

			if (filter_var($value, FILTER_VALIDATE_URL)) {
				return true;
			}

			if (is_numeric($value)) {
				return true;
			}

			if (preg_match('/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/i', $value)) {
				return true;
			}

			return false;
		}
	}
}
