<?php
/**
 * Plugin Name: BimarStop
 * Description: BimarStop theme system and WordPress page rendering foundation.
 * Version: 0.4.0
 * Author: BimarStop
 * License: Proprietary
 */

if (!defined('ABSPATH')) exit;

define('BIMARSTOP_VERSION', '0.4.0');
define('BIMARSTOP_FILE', __FILE__);
define('BIMARSTOP_DIR', plugin_dir_path(__FILE__));
define('BIMARSTOP_URL', plugin_dir_url(__FILE__));

require_once BIMARSTOP_DIR . 'includes/class-bimarstop.php';

function bimarstop_bootstrap() {
    BimarStop\Plugin::instance();
}
add_action('plugins_loaded', 'bimarstop_bootstrap');

register_activation_hook(BIMARSTOP_FILE, ['BimarStop\Plugin', 'activate']);
register_deactivation_hook(BIMARSTOP_FILE, ['BimarStop\Plugin', 'deactivate']);
