<?php
/**
 * Plugin Name: BimarStop
 * Plugin URI: https://bimarstop.com
 * Description: BimarStop medical platform — demo foundation for WordPress.
 * Version: 0.1.0
 * Author: BimarStop
 * License: Proprietary
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BIMARSTOP_VERSION', '0.1.0');
define('BIMARSTOP_FILE', __FILE__);
define('BIMARSTOP_DIR', plugin_dir_path(__FILE__));
define('BIMARSTOP_URL', plugin_dir_url(__FILE__));

require_once BIMARSTOP_DIR . 'includes/class-bimarstop.php';

function bimarstop_bootstrap() {
    return BimarStop\Plugin::instance();
}

add_action('plugins_loaded', 'bimarstop_bootstrap');
