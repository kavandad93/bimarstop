<?php
namespace BimarStop;

if (!defined('ABSPATH')) exit;

final class Plugin {
    private static ?self $instance = null;

    public static function instance(): self {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets'], 100);
        add_action('wp_footer', [$this, 'theme_selector'], 99);
        add_filter('body_class', [$this, 'body_class']);
    }

    public static function activate(): void { flush_rewrite_rules(); }
    public static function deactivate(): void { flush_rewrite_rules(); }

    public function enqueue_assets(): void {
        wp_enqueue_style(
            'bimarstop-vazirmatn',
            'https://cdn.jsdelivr.net/npm/@fontsource/vazirmatn@5.0.18/index.css',
            [],
            '5.0.18'
        );
        wp_enqueue_style(
            'bimarstop-style',
            BIMARSTOP_URL . 'assets/bimarstop.css',
            ['bimarstop-vazirmatn'],
            BIMARSTOP_VERSION
        );
        wp_enqueue_script(
            'bimarstop-theme',
            BIMARSTOP_URL . 'assets/bimarstop.js',
            [],
            BIMARSTOP_VERSION,
            true
        );
    }

    public function theme_selector(): void {
        $themes = [
            'light-1'=>'روشن ۱','light-2'=>'روشن ۲','light-3'=>'روشن ۳','light-4'=>'روشن ۴','light-5'=>'روشن ۵',
            'light-6'=>'روشن ۶','light-7'=>'روشن ۷','light-8'=>'روشن ۸','light-9'=>'روشن ۹','light-10'=>'روشن ۱۰',
            'dark-1'=>'تاریک ۱','dark-2'=>'تاریک ۲','dark-3'=>'تاریک ۳','dark-4'=>'تاریک ۴','dark-5'=>'تاریک ۵',
            'dark-6'=>'تاریک ۶','dark-7'=>'تاریک ۷','dark-8'=>'تاریک ۸','dark-9'=>'تاریک ۹','dark-10'=>'تاریک ۱۰',
        ];
        echo '<div class="bimarstop-theme-picker"><select id="bimarstop-theme-select" aria-label="انتخاب تم">';
        foreach ($themes as $value => $label) {
            echo '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
        }
        echo '</select></div>';
    }

    public function body_class(array $classes): array {
        $classes[] = 'bimarstop-site';
        return $classes;
    }
}
