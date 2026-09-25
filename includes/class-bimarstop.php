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
            add_option('bimarstop_settings', [
                'font_enabled' => 1,
                'font_url' => 'https://cdn.jsdelivr.net/npm/@fontsource/vazirmatn@5.0.18/index.css',
                'default_theme' => 'light-1',
                'show_theme_picker' => 1,
            ]);
        }
        flush_rewrite_rules();
    }

    public static function deactivate(): void { flush_rewrite_rules(); }

    public function register_settings(): void {
        register_setting('bimarstop_settings_group', 'bimarstop_settings', [
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);
    }

    public function sanitize_settings($input): array {
        $themes = array_merge(
            array_map(fn($i) => 'light-' . $i, range(1, 10)),
            array_map(fn($i) => 'dark-' . $i, range(1, 10))
        );

        return [
            'font_enabled' => empty($input['font_enabled']) ? 0 : 1,
            'font_url' => !empty($input['font_url']) ? esc_url_raw($input['font_url']) : '',
            'default_theme' => in_array($input['default_theme'] ?? 'light-1', $themes, true) ? $input['default_theme'] : 'light-1',
            'show_theme_picker' => empty($input['show_theme_picker']) ? 0 : 1,
        ];
    }

    public function enqueue_assets(): void {
        $settings = wp_parse_args(get_option('bimarstop_settings', []), [
            'font_enabled' => 1,
            'font_url' => 'https://cdn.jsdelivr.net/npm/@fontsource/vazirmatn@5.0.18/index.css',
            'default_theme' => 'light-1',
            'show_theme_picker' => 1,
        ]);

        if (!empty($settings['font_enabled']) && !empty($settings['font_url'])) {
            wp_enqueue_style('bimarstop-vazirmatn', $settings['font_url'], [], BIMARSTOP_VERSION);
        }

        wp_enqueue_style('bimarstop-style', BIMARSTOP_URL . 'assets/bimarstop.css', ['bimarstop-vazirmatn'], BIMARSTOP_VERSION);
        wp_enqueue_script('bimarstop-theme', BIMARSTOP_URL . 'assets/bimarstop.js', [], BIMARSTOP_VERSION, true);

        wp_localize_script('bimarstop-theme', 'BimarStopSettings', [
            'defaultTheme' => $settings['default_theme'],
            'showPicker' => !empty($settings['show_theme_picker']),
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

        $settings = wp_parse_args(get_option('bimarstop_settings', []), [
            'font_enabled' => 1,
            'font_url' => 'https://cdn.jsdelivr.net/npm/@fontsource/vazirmatn@5.0.18/index.css',
            'default_theme' => 'light-1',
            'show_theme_picker' => 1,
        ]);

        $themes = [];
        foreach (range(1, 10) as $i) $themes['light-' . $i] = 'روشن ' . $i;
        foreach (range(1, 10) as $i) $themes['dark-' . $i] = 'تاریک ' . $i;
        ?>
        <div class="wrap" dir="rtl">
            <h1>BimarStop</h1>
            <p>تنظیمات اصلی ظاهر و تم سایت.</p>

            <form method="post" action="options.php">
                <?php settings_fields('bimarstop_settings_group'); ?>

                <div class="card" style="max-width:900px;padding:24px">
                    <h2>فونت سایت</h2>
                    <label>
                        <input type="checkbox" name="bimarstop_settings[font_enabled]" value="1" <?php checked($settings['font_enabled'], 1); ?>>
                        فعال بودن فونت وزیرمتن برای کل سایت
                    </label>

                    <p>
                        <label for="bimarstop-font-url"><strong>آدرس فایل فونت / CSS</strong></label><br>
                        <input id="bimarstop-font-url" type="url" class="regular-text" style="width:100%;max-width:700px"
                               name="bimarstop_settings[font_url]"
                               value="<?php echo esc_attr($settings['font_url']); ?>">
                    </p>
                </div>

                <div class="card" style="max-width:900px;padding:24px">
                    <h2>تم سایت</h2>
                    <p>
                        <label for="bimarstop-default-theme"><strong>تم پیش‌فرض</strong></label><br>
                        <select id="bimarstop-default-theme" name="bimarstop_settings[default_theme]">
                            <?php foreach ($themes as $value => $label): ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($settings['default_theme'], $value); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </p>

                    <label>
                        <input type="checkbox" name="bimarstop_settings[show_theme_picker]" value="1" <?php checked($settings['show_theme_picker'], 1); ?>>
                        نمایش انتخابگر تم برای کاربران
                    </label>

                    <p>۲۰ تم آماده وجود دارد: ۱۰ روشن و ۱۰ تاریک.</p>
                </div>

                <?php submit_button('ذخیره تنظیمات BimarStop'); ?>
            </form>
        </div>
        <?php
    }

    public function body_class(array $classes): array {
        $classes[] = 'bimarstop-site';
        return $classes;
    }
}
