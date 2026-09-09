<?php
/**
 * Plugin Name: Polyglot for Polylang
 * Description: Automatically translate Polylang strings and content using Google Cloud Translation API (Basic v2).
 * Version: 1.3.2
 * Author: Simone Ricci
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: polyglot-for-polylang
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

define('POLYGLOT_VERSION', '1.3.2');
define('POLYGLOT_FILE', __FILE__);
define('POLYGLOT_DIR', plugin_dir_path(__FILE__));

require_once POLYGLOT_DIR . 'includes/class-polyglot-translation-service.php';
require_once POLYGLOT_DIR . 'includes/class-polyglot-admin-page.php';
require_once POLYGLOT_DIR . 'includes/class-polyglot-plugin.php';

new Polyglot_Plugin();
