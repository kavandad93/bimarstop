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
        add_action('wp_dashboard_setup', [$this, 'dashboard_widgets'], 20);
        add_action('wp_dashboard_setup', [$this, 'dashboard_setup'], 100);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('init', [$this, 'register_roles']);
        add_action('template_redirect', [$this, 'require_login']);
        add_filter('show_admin_bar', [$this, 'show_admin_bar']);
        add_filter('pre_user_role', [$this, 'force_patient_registration_role'], 10, 2);
        add_action('admin_menu', [$this, 'restrict_role_admin_menu'], 999);
    }

    public static function activate(): void {
        self::register_bimarstop_roles();
        if (get_option('bimarstop_settings', false) === false) {
            add_option('bimarstop_settings', ['theme' => 'light-1']);
        }
        flush_rewrite_rules();
    }

    public static function deactivate(): void { flush_rewrite_rules(); }

    public function register_roles(): void { self::register_bimarstop_roles(); }

    private static function register_bimarstop_roles(): void {
        $patient = get_role('bimarstop_patient');
        if (!$patient) add_role('bimarstop_patient', 'مریض', ['read' => true]);
        $doctor = get_role('bimarstop_doctor');
        if (!$doctor) add_role('bimarstop_doctor', 'پزشک', ['read' => true]);
        $operator = get_role('bimarstop_operator');
        if (!$operator) add_role('bimarstop_operator', 'اوپراتور', ['read' => true]);
        $admin = get_role('administrator');
        if ($admin) {
            // ادمین اصلی همان نقش استاندارد Administrator وردپرس است.
        }
    }

    public function require_login(): void {
        if (is_user_logged_in() || is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) return;
        $login_url = function_exists('wp_login_url') ? wp_login_url(home_url('/')) : wp_login_url();
        wp_safe_redirect($login_url);
        exit;
    }

    public function show_admin_bar($show): bool {
        return is_user_logged_in() ? (bool) $show : false;
    }

    public function force_patient_registration_role($role, $userdata) {
        return is_admin() ? $role : 'bimarstop_patient';
    }

    public function restrict_role_admin_menu(): void {
        if (!is_user_logged_in() || current_user_can('manage_options')) return;
        $role = $this->current_role();
        if ($role === 'bimarstop_patient' || $role === 'bimarstop_doctor' || $role === 'bimarstop_operator') {
            $menus = [
                'about.php',
                'edit.php',
                'upload.php',
                'edit.php?post_type=page',
                'edit-comments.php',
                'themes.php',
                'plugins.php',
                'users.php',
                'tools.php',
                'options-general.php',
                'profile.php',
            ];
            foreach ($menus as $menu) remove_menu_page($menu);
        }
    }

    private function current_role(): string {
        $user = wp_get_current_user();
        return $user && !empty($user->roles) ? (string) $user->roles[0] : '';
    }

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

    public function admin_assets(): void {
        wp_enqueue_style(
            'bimarstop-admin-font',
            'https://cdn.jsdelivr.net/npm/@fontsource/vazirmatn@5.0.18/index.css',
            [],
            BIMARSTOP_VERSION
        );
        wp_add_inline_style('bimarstop-admin-font', '
            body.wp-admin, body.wp-admin button, body.wp-admin input, body.wp-admin textarea,
            body.wp-admin select, body.wp-admin option, body.wp-admin .wrap,
            body.wp-admin #adminmenu, body.wp-admin #adminmenu .wp-submenu,
            body.wp-admin #wpadminbar {
                font-family: "Vazirmatn", Tahoma, Arial, sans-serif !important;
            }
        ');
    }

    public function admin_menu(): void {
        $role = $this->current_role();
        if ($role === 'bimarstop_patient') {
            add_menu_page('BimarStop', 'BimarStop', 'read', 'bimarstop', [$this, 'role_dashboard'], 'dashicons-heart', 25);
            add_submenu_page('bimarstop', 'داشبورد', 'داشبورد', 'read', 'bimarstop', [$this, 'role_dashboard']);
            add_submenu_page('bimarstop', 'پیام‌ها', 'پیام‌ها', 'read', 'bimarstop-messages', [$this, 'role_placeholder']);
            add_submenu_page('bimarstop', 'مدارک', 'مدارک', 'read', 'bimarstop-documents', [$this, 'role_placeholder']);
            return;
        }
        if ($role === 'bimarstop_doctor') {
            add_menu_page('BimarStop', 'BimarStop', 'read', 'bimarstop', [$this, 'role_dashboard'], 'dashicons-heart', 25);
            add_submenu_page('bimarstop', 'داشبورد', 'داشبورد', 'read', 'bimarstop', [$this, 'role_dashboard']);
            add_submenu_page('bimarstop', 'پرونده‌ها و اتاق‌ها', 'پرونده‌ها و اتاق‌ها', 'read', 'bimarstop-rooms', [$this, 'role_placeholder']);
            add_submenu_page('bimarstop', 'پیام‌ها', 'پیام‌ها', 'read', 'bimarstop-messages', [$this, 'role_placeholder']);
            add_submenu_page('bimarstop', 'مدارک', 'مدارک', 'read', 'bimarstop-documents', [$this, 'role_placeholder']);
            add_submenu_page('bimarstop', 'تماس‌ها', 'تماس‌ها', 'read', 'bimarstop-calls', [$this, 'role_placeholder']);
            add_submenu_page('bimarstop', 'گزارش مشکل', 'گزارش مشکل', 'read', 'bimarstop-report-issue', [$this, 'role_placeholder']);
            return;
        }
        if ($role === 'bimarstop_operator') {
            add_menu_page('BimarStop', 'BimarStop', 'read', 'bimarstop', [$this, 'role_dashboard'], 'dashicons-heart', 25);
            add_submenu_page('bimarstop', 'داشبورد', 'داشبورد', 'read', 'bimarstop', [$this, 'role_dashboard']);
            add_submenu_page('bimarstop', 'صف ورودی', 'صف ورودی', 'read', 'bimarstop-queue', [$this, 'role_placeholder']);
            add_submenu_page('bimarstop', 'بیماران', 'بیماران', 'read', 'bimarstop-patients', [$this, 'role_placeholder']);
            add_submenu_page('bimarstop', 'پرونده‌ها و اتاق‌ها', 'پرونده‌ها و اتاق‌ها', 'read', 'bimarstop-rooms', [$this, 'role_placeholder']);
            add_submenu_page('bimarstop', 'پیام‌ها', 'پیام‌ها', 'read', 'bimarstop-messages', [$this, 'role_placeholder']);
            add_submenu_page('bimarstop', 'مدارک', 'مدارک', 'read', 'bimarstop-documents', [$this, 'role_placeholder']);
            add_submenu_page('bimarstop', 'اعلان‌ها', 'اعلان‌ها', 'read', 'bimarstop-notifications', [$this, 'role_placeholder']);
            add_submenu_page('bimarstop', 'گزارش مشکل', 'گزارش مشکل', 'read', 'bimarstop-report-issue', [$this, 'role_placeholder']);
            return;
        }
        add_menu_page(
            'BimarStop',
            'BimarStop',
            'manage_options',
            'bimarstop',
            [$this, 'settings_page'],
            'dashicons-heart',
            25
        );

        add_submenu_page(
            'bimarstop',
            'داشبورد BimarStop',
            'داشبورد',
            'manage_options',
            'bimarstop',
            [$this, 'settings_page']
        );

        add_submenu_page(
            'bimarstop',
            'تنظیمات BimarStop',
            'تنظیمات',
            'manage_options',
            'bimarstop-settings',
            [$this, 'settings_page']
        );

        add_submenu_page(
            'bimarstop',
            'بیماران',
            'بیماران',
            'manage_options',
            'bimarstop-patients',
            [$this, 'placeholder_page']
        );

        add_submenu_page(
            'bimarstop',
            'پزشکان',
            'پزشکان',
            'manage_options',
            'bimarstop-doctors',
            [$this, 'placeholder_page']
        );

        add_submenu_page(
            'bimarstop',
            'اپراتورها',
            'اپراتورها',
            'manage_options',
            'bimarstop-operators',
            [$this, 'placeholder_page']
        );

        add_submenu_page(
            'bimarstop',
            'پرونده‌ها و اتاق‌ها',
            'پرونده‌ها و اتاق‌ها',
            'manage_options',
            'bimarstop-rooms',
            [$this, 'placeholder_page']
        );

        add_submenu_page(
            'bimarstop',
            'پیام‌ها',
            'پیام‌ها',
            'manage_options',
            'bimarstop-messages',
            [$this, 'placeholder_page']
        );

        add_submenu_page(
            'bimarstop',
            'مدارک',
            'مدارک',
            'manage_options',
            'bimarstop-documents',
            [$this, 'placeholder_page']
        );

        add_submenu_page(
            'bimarstop',
            'تماس‌ها',
            'تماس‌ها',
            'manage_options',
            'bimarstop-calls',
            [$this, 'placeholder_page']
        );

        add_submenu_page(
            'bimarstop',
            'جراحی و خدمات',
            'جراحی و خدمات',
            'manage_options',
            'bimarstop-services',
            [$this, 'placeholder_page']
        );

        add_submenu_page(
            'bimarstop',
            'پرداخت‌ها',
            'پرداخت‌ها',
            'manage_options',
            'bimarstop-payments',
            [$this, 'placeholder_page']
        );

        add_submenu_page(
            'bimarstop',
            'اعلان‌ها و پیامک',
            'اعلان‌ها و پیامک',
            'manage_options',
            'bimarstop-notifications',
            [$this, 'placeholder_page']
        );

        add_submenu_page(
            'bimarstop',
            'هوش مصنوعی',
            'هوش مصنوعی',
            'manage_options',
            'bimarstop-ai',
            [$this, 'placeholder_page']
        );

        add_submenu_page(
            'bimarstop',
            'گزارش‌ها و لاگ‌ها',
            'گزارش‌ها و لاگ‌ها',
            'manage_options',
            'bimarstop-logs',
            [$this, 'placeholder_page']
        );
    }

    public function role_dashboard(): void { if (!current_user_can('read')) return; echo '<div class="wrap" dir="rtl"><h1>🏥 BimarStop</h1><p>داشبورد اختصاصی شما.</p></div>'; }

    public function role_placeholder(): void { if (!current_user_can('read')) return; echo '<div class="wrap" dir="rtl"><h1>BimarStop</h1><p>این بخش در حال توسعه است.</p></div>'; }

    public function placeholder_page(): void {
        if (!current_user_can('manage_options')) return;
        echo '<div class="wrap" dir="rtl"><h1>BimarStop</h1><p>این بخش در حال توسعه است.</p></div>';
    }

    public function dashboard_widgets(): void {
        wp_add_dashboard_widget('bimarstop_overview', '🏥 BimarStop — نمای کلی', [$this, 'widget_overview']);
        wp_add_dashboard_widget('bimarstop_queue', '📥 BimarStop — صف ورودی', [$this, 'widget_queue']);
        wp_add_dashboard_widget('bimarstop_activity', '📋 BimarStop — فعالیت اخیر', [$this, 'widget_activity']);
        wp_add_dashboard_widget('bimarstop_alerts', '🔔 BimarStop — اعلان‌ها', [$this, 'widget_alerts']);
    }

    public function widget_overview(): void {
        echo '<p>ویجت نمای کلی BimarStop.</p>';
        echo '<p><strong>بیماران:</strong> — &nbsp; <strong>پزشکان:</strong> — &nbsp; <strong>اتاق‌های فعال:</strong> —</p>';
    }

    public function widget_queue(): void {
        echo '<p>در این بخش صف ورودی و موارد نیازمند بررسی نمایش داده می‌شود.</p>';
    }

    public function widget_activity(): void {
        echo '<p>فعالیت‌های اخیر BimarStop در اینجا نمایش داده می‌شود.</p>';
    }

    public function widget_alerts(): void {
        echo '<p>اعلان‌های مهم BimarStop در اینجا نمایش داده می‌شوند.</p>';
    }

    public function dashboard_setup(): void {
        if (!is_user_logged_in()) return;
        global $wp_meta_boxes;
        if (!isset($wp_meta_boxes['dashboard']['normal']['core'])) return;
        foreach ($wp_meta_boxes['dashboard']['normal']['core'] as $id => $box) {
            if (strpos($id, 'bimarstop_') !== 0) {
                unset($wp_meta_boxes['dashboard']['normal']['core'][$id]);
            }
        }
        if (isset($wp_meta_boxes['dashboard']['side']['core'])) {
            foreach ($wp_meta_boxes['dashboard']['side']['core'] as $id => $box) {
                if (strpos($id, 'bimarstop_') !== 0) {
                    unset($wp_meta_boxes['dashboard']['side']['core'][$id]);
                }
            }
        }
        if (isset($wp_meta_boxes['dashboard']['normal']['high'])) {
            foreach ($wp_meta_boxes['dashboard']['normal']['high'] as $id => $box) {
                if (strpos($id, 'bimarstop_') !== 0) {
                    unset($wp_meta_boxes['dashboard']['normal']['high'][$id]);
                }
            }
        }
        if (isset($wp_meta_boxes['dashboard']['side']['high'])) {
            foreach ($wp_meta_boxes['dashboard']['side']['high'] as $id => $box) {
                if (strpos($id, 'bimarstop_') !== 0) {
                    unset($wp_meta_boxes['dashboard']['side']['high'][$id]);
                }
            }
        }
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
