<?php

namespace BimarStop;

if (!defined('ABSPATH')) {
    exit;
}

final class Plugin {
    private static ?self $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action('init', [$this, 'init']);
        register_activation_hook(BIMARSTOP_FILE, [$this, 'activate']);
        register_deactivation_hook(BIMARSTOP_FILE, [$this, 'deactivate']);
    }

    public function init(): void {
        // Demo foundation. Feature modules will be added here.
    }

    public function activate(): void {
        // Database/schema setup will be added in the next implementation step.
        flush_rewrite_rules();
    }

    public function deactivate(): void {
        flush_rewrite_rules();
    }
}
