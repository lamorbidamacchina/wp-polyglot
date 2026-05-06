<?php
/**
 * Uninstall cleanup for Polyglot for Polylang.
 *
 * @package Polyglot
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

/**
 * Delete all plugin options for a specific site.
 *
 * @return void
 */
function polyglot_delete_site_options(): void {
	delete_option('polyglot_google_api_key');
	delete_option('polyglot_translation_job');
	delete_option('polyglot_admin_notice');
}

if (is_multisite()) {
	$polyglot_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	if (is_array($polyglot_site_ids)) {
		$polyglot_original_blog_id = get_current_blog_id();

		foreach ($polyglot_site_ids as $polyglot_site_id) {
			switch_to_blog((int) $polyglot_site_id);
			polyglot_delete_site_options();
			restore_current_blog();
		}

		if (get_current_blog_id() !== $polyglot_original_blog_id) {
			switch_to_blog($polyglot_original_blog_id);
		}
	}
} else {
	polyglot_delete_site_options();
}
