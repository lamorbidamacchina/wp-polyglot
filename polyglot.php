<?php
/**
 * Plugin Name: Polyglot for Polylang
 * Description: Fills missing Polylang String Translation values using Google Cloud Translation API (Basic v2). Does not translate posts/pages.
 * Version: 1.2.0
 * Author: Simone Ricci
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: polyglot
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

define('POLYGLOT_FILE', __FILE__);
define('POLYGLOT_DIR', plugin_dir_path(__FILE__));

require_once POLYGLOT_DIR . 'includes/class-polyglot-translation-service.php';
require_once POLYGLOT_DIR . 'includes/class-polyglot-admin-page.php';
require_once POLYGLOT_DIR . 'includes/class-polyglot-plugin.php';

new Polyglot_Plugin();
