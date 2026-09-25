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
        add_filter('body_class', [$this, 'body_class']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public static function activate(): void {
        if (get_option('bimarstop_settings', false) === false) {
            add_option('bimarstop_settings', ['theme' => 'light-1']);
        }
        flush_rewrite_rules();
    }

    public static function deactivate(): void { flush_rewrite_rules(); }

    private function themes(): array {
        return [
            'light-1' => 'روشن ۱ — وزیرمتن',
            'light-2' => 'روشن ۲ — کلاسیک',
            'light-3' => 'روشن ۳ — آبی',
            'light-4' => 'روشن ۴ — سبز',
            'light-5' => 'روشن ۵ — صورتی',
            'light-6' => 'روشن ۶ — گرم',
            'light-7' => 'روشن ۷ — مینیمال',
            'light-8' => 'روشن ۸ — بنفش',
            'light-9' => 'روشن ۹ — دریایی',
            'light-10' => 'روشن ۱۰ — مدرن',
            'dark-1' => 'تاریک ۱ — وزیرمتن',
            'dark-2' => 'تاریک ۲ — زغالی',
            'dark-3' => 'تاریک ۳ — آبی شب',
            'dark-4' => 'تاریک ۴ — سبز شب',
            'dark-5' => 'تاریک ۵ — بنفش',
            'dark-6' => 'تاریک ۶ — قرمز',
            'dark-7' => 'تاریک ۷ — طلایی',
            'dark-8' => 'تاریک ۸ — نیلی',
            'dark-9' => 'تاریک ۹ — فیروزه‌ای',
            'dark-10' => 'تاریک ۱۰ — خاکستری',
        ];
    }

    public function register_settings(): void {
        register_setting('bimarstop_settings_group', 'bimarstop_settings', [
            'sanitize_callback' => function ($input) {
                $theme = sanitize_key($input['theme'] ?? 'light-1');
                return ['theme' => array_key_exists($theme, $this->themes()) ? $theme : 'light-1'];
            },
        ]);
    }

    public function enqueue_assets(): void {
        $settings = wp_parse_args(get_option('bimarstop_settings', []), ['theme' => 'light-1']);
        $theme = array_key_exists($settings['theme'], $this->themes()) ? $settings['theme'] : 'light-1';

        wp_enqueue_style('bimarstop-style', BIMARSTOP_URL . 'assets/bimarstop.css', [], BIMARSTOP_VERSION);
        wp_enqueue_script('bimarstop-theme', BIMARSTOP_URL . 'assets/bimarstop.js', [], BIMARSTOP_VERSION, true);

        wp_localize_script('bimarstop-theme', 'BimarStopSettings', [
            'theme' => $theme,
        ]);
    }

    public function admin_menu(): void {
        add_menu_page(
            'BimarStop',
            'BimarStop',
            'manage_options',
            'bimarstop',
            [$this, 'settings_page'],
            'dashicons-heart',
            25
        );
    }

    public function settings_page(): void {
        if (!current_user_can('manage_options')) return;

        $settings = wp_parse_args(get_option('bimarstop_settings', []), ['theme' => 'light-1']);
        $themes = $this->themes();
        ?>
        <div class="wrap" dir="rtl">
            <h1>BimarStop</h1>
            <p>تنظیمات ظاهری سایت BimarStop</p>

            <form method="post" action="options.php">
                <?php settings_fields('bimarstop_settings_group'); ?>

                <div class="card" style="max-width:900px;padding:24px">
                    <h2>🎨 تم سایت</h2>
                    <p>تم سایت فقط توسط مدیر تعیین می‌شود و برای تمام بازدیدکنندگان یکسان خواهد بود.</p>

                    <select name="bimarstop_settings[theme]" style="min-width:320px">
                        <?php foreach ($themes as $value => $label): ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($settings['theme'], $value); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <p><strong>۲۰ تم مستقل:</strong> ۱۰ روشن و ۱۰ تاریک.</p>
                    <p>فقط تم‌های روشن ۱ و تاریک ۱ از فونت وزیرمتن استفاده می‌کنند؛ سایر تم‌ها فونت و ظاهر اختصاصی خودشان را دارند.</p>
                </div>

                <?php submit_button('ذخیره تم سایت'); ?>
            </form>
        </div>
        <?php
    }

    public function body_class(array $classes): array {
        $settings = wp_parse_args(get_option('bimarstop_settings', []), ['theme' => 'light-1']);
        $theme = array_key_exists($settings['theme'], $this->themes()) ? $settings['theme'] : 'light-1';
        $classes[] = 'bimarstop-site';
        $classes[] = 'bimarstop-' . $theme;
        return $classes;
    }
}
