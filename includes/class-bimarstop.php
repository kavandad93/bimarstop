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
        add_action('wp_ajax_bimarstop_send_message', [$this, 'ajax_send_message']);
        add_action('wp_ajax_bimarstop_get_messages', [$this, 'ajax_get_messages']);
        add_action('wp_ajax_bimarstop_set_operator_status', [$this, 'ajax_set_operator_status']);
        add_action('wp_ajax_bimarstop_private_send_message', [$this, 'ajax_private_send_message']);
        add_action('wp_ajax_bimarstop_private_get_messages', [$this, 'ajax_private_get_messages']);
        add_action('wp_ajax_bimarstop_download_file', [$this, 'ajax_download_file']);
        add_action('wp_ajax_bimarstop_get_documents', [$this, 'ajax_get_documents']);
        add_action('wp_ajax_bimarstop_send_document', [$this, 'ajax_send_document']);
        add_action('template_redirect', [$this, 'require_login']);
        add_filter('show_admin_bar', [$this, 'show_admin_bar']);
        add_filter('pre_user_role', [$this, 'force_patient_registration_role'], 10, 2);
        add_action('admin_menu', [$this, 'restrict_role_admin_menu'], 999);
    }

    public static function activate(): void {
        self::register_bimarstop_roles();
        self::create_chat_tables();
        self::create_private_chat_tables();
        if (get_option('bimarstop_settings', false) === false) {
            add_option('bimarstop_settings', ['theme' => 'light-1']);
        }
        flush_rewrite_rules();
    }

    public static function deactivate(): void { flush_rewrite_rules(); }

    public function register_roles(): void { self::register_bimarstop_roles(); self::create_chat_tables(); self::create_private_chat_tables(); self::create_report_and_document_tables(); }

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

    private static function create_chat_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $threads = $wpdb->prefix . 'bimarstop_chat_threads';
        $messages = $wpdb->prefix . 'bimarstop_chat_messages';

        dbDelta("CREATE TABLE {$threads} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            patient_id bigint(20) unsigned NOT NULL,
            operator_id bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'open',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY patient_id (patient_id),
            KEY operator_id (operator_id),
            KEY status (status)
        ) {$charset};");

        dbDelta("CREATE TABLE {$messages} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            thread_id bigint(20) unsigned NOT NULL,
            sender_id bigint(20) unsigned NOT NULL,
            message longtext NOT NULL,
            attachment_path text NULL,
            attachment_name varchar(255) NULL,
            attachment_size bigint(20) unsigned NULL,
            attachment_type varchar(100) NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY thread_id (thread_id),
            KEY sender_id (sender_id)
        ) {$charset};");
    }

    private static function create_private_chat_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $threads = $wpdb->prefix . 'bimarstop_private_threads';
        $messages = $wpdb->prefix . 'bimarstop_private_messages';

        dbDelta("CREATE TABLE {$threads} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_a_id bigint(20) unsigned NOT NULL,
            user_b_id bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'open',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY user_pair (user_a_id,user_b_id),
            KEY user_a_id (user_a_id),
            KEY user_b_id (user_b_id),
            KEY status (status)
        ) {$charset};");

        dbDelta("CREATE TABLE {$messages} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            thread_id bigint(20) unsigned NOT NULL,
            sender_id bigint(20) unsigned NOT NULL,
            message longtext NOT NULL,
            attachment_path text NULL,
            attachment_name varchar(255) NULL,
            attachment_size bigint(20) unsigned NULL,
            attachment_type varchar(100) NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY thread_id (thread_id),
            KEY sender_id (sender_id)
        ) {$charset};");
    }

    private static function create_report_and_document_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $reports = $wpdb->prefix . 'bimarstop_issue_reports';
        $cats = $wpdb->prefix . 'bimarstop_document_categories';
        $docs = $wpdb->prefix . 'bimarstop_documents';
        dbDelta("CREATE TABLE $reports (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            subject varchar(255) NOT NULL,
            description text NOT NULL,
            priority varchar(20) NOT NULL DEFAULT 'normal',
            status varchar(20) NOT NULL DEFAULT 'open',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY status (status)
        ) $charset;");
        dbDelta("CREATE TABLE $cats (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY name (name)
        ) $charset;");
        dbDelta("CREATE TABLE $docs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            category_id bigint(20) unsigned NOT NULL DEFAULT 0,
            path text NOT NULL,
            name varchar(255) NOT NULL,
            size bigint(20) unsigned NOT NULL DEFAULT 0,
            type varchar(100) NOT NULL DEFAULT 'application/octet-stream',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY path_hash (path(191)),
            KEY category_id (category_id)
        ) $charset;");
        $count=(int)$wpdb->get_var("SELECT COUNT(*) FROM $cats");
        if($count===0) $wpdb->insert($cats,['name'=>'عمومی','created_at'=>current_time('mysql')],['%s','%s']);
    }

    private function sync_documents(): void {
        global $wpdb;
        $uploads=wp_upload_dir();
        if(!empty($uploads['error']) || empty($uploads['basedir']) || !is_dir($uploads['basedir'])) return;
        $allowed=['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','doc'=>'application/msword','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        $table=$wpdb->prefix.'bimarstop_documents';
        try { $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads['basedir'], FilesystemIterator::SKIP_DOTS)); } catch(Throwable $e){ return; }
        foreach($it as $file){
            if(!$file->isFile()) continue;
            $path=$file->getPathname();
            $ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));
            if(!isset($allowed[$ext])) continue;
            $real=realpath($path); $base=realpath($uploads['basedir']);
            if(!$real||!$base||strpos($real,$base)!==0) continue;
            $exists=$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE path=%s",$real));
            if(!$exists) $wpdb->insert($table,['path'=>$real,'name'=>basename($real),'size'=>(int)$file->getSize(),'type'=>$allowed[$ext],'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')],['%s','%s','%d','%s','%s','%s']);
        }
    }

    private function can_use_documents(): bool {
        return current_user_can('manage_options') || in_array($this->current_role(),['bimarstop_operator','bimarstop_doctor'],true);
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
        if (isset($_GET['page']) && in_array(sanitize_key($_GET['page']), ['bimarstop-chat','bimarstop-doctor-chats','bimarstop-private-chats'], true)) {
            wp_enqueue_style('bimarstop-chat-ui', BIMARSTOP_URL . 'assets/bimarstop-chat.css', [], BIMARSTOP_VERSION);
        }
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
            add_menu_page('BimarStop', 'BimarStop', 'read', 'bimarstop', [$this, 'patient_dashboard'], 'dashicons-heart', 25);
            add_submenu_page('bimarstop', 'داشبورد', 'داشبورد', 'read', 'bimarstop', [$this, 'patient_dashboard']);
            add_submenu_page('bimarstop', 'چت با اپراتور', 'چت با اپراتور', 'read', 'bimarstop-chat', [$this, 'patient_chat_page']);
            return;
        }

        if ($role === 'bimarstop_doctor') {
            add_menu_page('BimarStop', 'BimarStop', 'read', 'bimarstop', [$this, 'doctor_dashboard'], 'dashicons-heart', 25);
            add_submenu_page('bimarstop', 'داشبورد', 'داشبورد', 'read', 'bimarstop', [$this, 'doctor_dashboard']);
            add_submenu_page('bimarstop', 'پرونده‌ها و اتاق‌ها', 'پرونده‌ها و اتاق‌ها', 'read', 'bimarstop-rooms', [$this, 'role_placeholder']);
            add_submenu_page('bimarstop', 'پیام‌ها', 'پیام‌ها', 'read', 'bimarstop-messages', [$this, 'role_placeholder']);
            add_submenu_page('bimarstop', 'مدارک', 'مدارک', 'read', 'bimarstop-documents', [$this, 'documents_page']);
            add_submenu_page('bimarstop', 'تماس‌ها', 'تماس‌ها', 'read', 'bimarstop-calls', [$this, 'role_placeholder']);
            add_submenu_page('bimarstop', 'گزارش مشکل', 'گزارش مشکل', 'read', 'bimarstop-report-issue', [$this, 'report_issue_page']);
            add_submenu_page('bimarstop', 'چت با اوپراتورها', 'چت با اوپراتورها', 'read', 'bimarstop-doctor-chats', [$this, 'doctor_private_chats_page']);
            return;
        }

        if ($role === 'bimarstop_operator') {
            add_menu_page('BimarStop', 'BimarStop', 'read', 'bimarstop', [$this, 'operator_dashboard'], 'dashicons-heart', 25);
            add_submenu_page('bimarstop', 'داشبورد', 'داشبورد', 'read', 'bimarstop', [$this, 'operator_dashboard']);
            add_submenu_page('bimarstop', 'صف ورودی', 'صف ورودی', 'read', 'bimarstop-queue', [$this, 'operator_chat_queue']);
            add_submenu_page('bimarstop', 'بیماران', 'بیماران', 'read', 'bimarstop-patients', [$this, 'patients_page']);
            add_submenu_page('bimarstop', 'پیام‌ها', 'پیام‌ها', 'read', 'bimarstop-messages', [$this, 'operator_chat_queue']);
            add_submenu_page('bimarstop', 'چت با مریض', 'چت با مریض', 'read', 'bimarstop-chat', [$this, 'operator_chat_page']);
            add_submenu_page('bimarstop', 'مدارک', 'مدارک', 'read', 'bimarstop-documents', [$this, 'role_placeholder']);
            add_submenu_page('bimarstop', 'اعلان‌ها', 'اعلان‌ها', 'read', 'bimarstop-notifications', [$this, 'role_placeholder']);
            add_submenu_page('bimarstop', 'گزارش مشکل', 'گزارش مشکل', 'read', 'bimarstop-report-issue', [$this, 'role_placeholder']);
            add_submenu_page('bimarstop', 'چت با پزشکان', 'چت با پزشکان', 'read', 'bimarstop-doctor-chats', [$this, 'operator_private_chats_page']);
            return;
        }

        if (!current_user_can('manage_options')) return;

        add_menu_page('BimarStop', 'BimarStop', 'manage_options', 'bimarstop', [$this, 'admin_dashboard'], 'dashicons-heart', 25);
        add_submenu_page('bimarstop', 'داشبورد', 'داشبورد', 'manage_options', 'bimarstop', [$this, 'admin_dashboard']);
        add_submenu_page('bimarstop', 'بیماران', 'بیماران', 'manage_options', 'bimarstop-patients', [$this, 'patients_page']);
        add_submenu_page('bimarstop', 'پزشکان', 'پزشکان', 'manage_options', 'bimarstop-doctors', [$this, 'doctors_page']);
        add_submenu_page('bimarstop', 'اوپراتورها', 'اوپراتورها', 'manage_options', 'bimarstop-operators', [$this, 'operators_page']);
        add_submenu_page('bimarstop', 'چت اپراتورها', 'چت اپراتورها', 'manage_options', 'bimarstop-chat', [$this, 'operator_chat_queue']);
        add_submenu_page('bimarstop', 'چت داخلی', 'چت داخلی', 'manage_options', 'bimarstop-private-chats', [$this, 'admin_private_chats_page']);
        add_submenu_page('bimarstop', 'پرونده‌ها و اتاق‌ها', 'پرونده‌ها و اتاق‌ها', 'manage_options', 'bimarstop-rooms', [$this, 'role_placeholder']);
        add_submenu_page('bimarstop', 'مدارک', 'مدارک', 'manage_options', 'bimarstop-documents', [$this, 'documents_page']);
        add_submenu_page('bimarstop', 'تماس‌ها', 'تماس‌ها', 'manage_options', 'bimarstop-calls', [$this, 'role_placeholder']);
        add_submenu_page('bimarstop', 'پرداخت‌ها', 'پرداخت‌ها', 'manage_options', 'bimarstop-payments', [$this, 'role_placeholder']);
        add_submenu_page('bimarstop', 'اعلان‌ها و پیامک', 'اعلان‌ها و پیامک', 'manage_options', 'bimarstop-notifications', [$this, 'role_placeholder']);
        add_submenu_page('bimarstop', 'هوش مصنوعی', 'هوش مصنوعی', 'manage_options', 'bimarstop-ai', [$this, 'role_placeholder']);
        add_submenu_page('bimarstop', 'گزارش مشکل', 'گزارش مشکل', 'manage_options', 'bimarstop-report-issue', [$this, 'report_issue_page']);
        add_submenu_page('bimarstop', 'گزارش‌ها و لاگ‌ها', 'گزارش‌ها و لاگ‌ها', 'manage_options', 'bimarstop-logs', [$this, 'role_placeholder']);
        add_submenu_page('bimarstop', 'تنظیمات', 'تنظیمات', 'manage_options', 'bimarstop-settings', [$this, 'settings_page']);
    }

    private function users_by_role(string $role): array {
        return get_users(['role' => $role, 'orderby' => 'registered', 'order' => 'DESC']);
    }

    public function admin_dashboard(): void {
        echo '<div class="wrap" dir="rtl"><h1>🏥 BimarStop</h1><p>مدیریت مرکزی سامانه.</p></div>';
    }

    public function patient_dashboard(): void {
        echo '<div class="wrap" dir="rtl"><h1>🏥 پنل مریض</h1><p>از منوی BimarStop می‌توانید با اپراتور گفتگو کنید.</p></div>';
    }

    public function doctor_dashboard(): void {
        echo '<div class="wrap" dir="rtl"><h1>🩺 پنل پزشک</h1><p>پرونده‌ها و اتاق‌های اختصاص داده‌شده از اینجا مدیریت می‌شوند.</p></div>';
    }

    public function operator_dashboard(): void {
        $online = (bool) get_user_meta(get_current_user_id(), 'bimarstop_operator_online', true);
        echo '<div class="wrap" dir="rtl"><h1>👨‍💻 پنل اوپراتور</h1>';
        echo '<p>وضعیت فعلی: <strong>' . ($online ? 'آنلاین 🟢' : 'آفلاین ⚪') . '</strong></p>';
        echo '<button type="button" class="button button-primary" id="bimarstop-toggle-status">' . ($online ? 'آفلاین شوم' : 'آنلاین شوم') . '</button>';
        echo '<div id="bimarstop-status-result" style="margin-top:12px"></div>';
        echo '<script>
        document.getElementById("bimarstop-toggle-status").addEventListener("click",function(){
            var b=this; b.disabled=true;
            var fd=new FormData(); fd.append("action","bimarstop_set_operator_status"); fd.append("nonce","' . esc_js(wp_create_nonce('bimarstop_operator')) . '");
            fd.append("online","' . ($online ? '0' : '1') . '");
            fetch("' . esc_url(admin_url('admin-ajax.php')) . '",{method:"POST",body:fd}).then(r=>r.json()).then(x=>{location.reload()});
        });
        </script></div>';
    }

    private function users_table(string $role, string $title): void {
        if (!current_user_can('manage_options')) return;
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bimarstop_create_user']) && check_admin_referer('bimarstop_create_user')) {
            $login = sanitize_user(wp_unslash($_POST['user_login'] ?? ''));
            $email = sanitize_email(wp_unslash($_POST['user_email'] ?? ''));
            $pass = (string) ($_POST['user_pass'] ?? '');
            $name = sanitize_text_field(wp_unslash($_POST['display_name'] ?? ''));
            if ($login && $email && strlen($pass) >= 8 && !username_exists($login) && !email_exists($email)) {
                $id = wp_create_user($login, $pass, $email);
                if (!is_wp_error($id)) {
                    wp_update_user(['ID'=>$id,'display_name'=>$name,'role'=>$role]);
                    echo '<div class="notice notice-success"><p>کاربر ساخته شد.</p></div>';
                }
            } else {
                echo '<div class="notice notice-error"><p>نام کاربری/ایمیل تکراری است یا رمز عبور کمتر از ۸ کاراکتر است.</p></div>';
            }
        }
        $users = $this->users_by_role($role);
        echo '<div class="wrap" dir="rtl"><h1>' . esc_html($title) . '</h1>';
        echo '<h2>ساخت کاربر</h2><form method="post"><input type="hidden" name="bimarstop_create_user" value="1">';
        wp_nonce_field('bimarstop_create_user');
        echo '<p><input required name="user_login" placeholder="نام کاربری"></p><p><input required type="email" name="user_email" placeholder="ایمیل"></p><p><input required type="password" name="user_pass" minlength="8" placeholder="رمز عبور"></p><p><input name="display_name" placeholder="نام نمایشی"></p>';
        submit_button('ساخت کاربر');
        echo '</form><h2>فهرست</h2>';
        echo '<table class="widefat striped"><thead><tr><th>نام کاربری</th><th>نام</th><th>ایمیل</th><th>تاریخ عضویت</th></tr></thead><tbody>';
        foreach ($users as $u) echo '<tr><td>'.esc_html($u->user_login).'</td><td>'.esc_html($u->display_name).'</td><td>'.esc_html($u->user_email).'</td><td>'.esc_html($u->user_registered).'</td></tr>';
        if (!$users) echo '<tr><td colspan="4">هنوز کاربری وجود ندارد.</td></tr>';
        echo '</tbody></table></div>';
    }

    public function patients_page(): void {
        if (!current_user_can('manage_options')) { if ($this->current_role() === 'bimarstop_operator') { $this->users_table('bimarstop_patient','🧑‍⚕️ بیماران'); } return; }
        $this->users_table('bimarstop_patient','🧑‍⚕️ بیماران');
    }
    public function doctors_page(): void { $this->users_table('bimarstop_doctor','🩺 پزشکان'); }
    public function operators_page(): void { $this->users_table('bimarstop_operator','👨‍💻 اوپراتورها'); }

    public function patient_chat_page(): void {
        if (!current_user_can('read')) return;
        $online = get_users(['role'=>'bimarstop_operator','meta_key'=>'bimarstop_operator_online','meta_value'=>'1','number'=>1]);
        $notice = $online ? '' : '<div class="bimar-chat-offline">فعلاً همه اوپراتورها آفلاین هستند؛ پیام شما ثبت می‌شود و پس از آنلاین شدن پاسخ داده می‌شود.</div>';
        echo '<div class="bimar-chat-page" dir="rtl">';
        echo '<div class="bimar-chat-header"><div><span class="bimar-chat-kicker">BimarStop • پشتیبانی</span><h1>💬 گفت‌وگو با اوپراتور</h1><p>پرسش، توضیح مشکل یا ارسال مدارک را همین‌جا انجام دهید.</p></div><div class="bimar-chat-live">'.($online ? '<i></i> اوپراتور آنلاین' : '<i class="off"></i> آفلاین').'</div></div>';
        echo $notice;
        echo '<div class="bimar-chat-shell">';
        echo '<aside class="bimar-chat-side"><div class="bimar-chat-side-title">گفت‌وگوی شما</div><div class="bimar-chat-tab active">💬 پشتیبانی</div><div class="bimar-chat-side-info">🔒 این گفتگو خصوصی است<br><span>فایل‌های PDF، JPG، PNG و DOCX تا ۱۰ مگابایت قابل ارسال‌اند.</span></div></aside>';
        echo '<main class="bimar-chat-main"><div id="bimarstop-chat-box" class="bimar-chat-box"><div class="bimar-chat-welcome"><div class="bimar-chat-welcome-icon">💙</div><h2>سلام! چطور می‌توانیم کمکتان کنیم؟</h2><p>پیامتان را بنویسید. اگر لازم است، مدارک را هم با 📎 پیوست کنید.</p></div></div>';
        echo '<div id="bimar-chat-attachment" class="bimar-chat-attachment" hidden><span>📎 <b id="bimar-file-name"></b></span><button type="button" id="bimar-file-remove">×</button></div>';
        echo '<div class="bimar-chat-composer"><label class="bimar-attach"><input id="bimarstop-chat-file" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx" hidden>📎</label>'.($this->can_use_documents() ? '<select id="bimarstop-chat-document" title="انتخاب مدرک موجود"><option value="">📚 مدرک موجود</option></select>' : '').'<textarea id="bimarstop-chat-input" rows="1" placeholder="پیام خود را بنویسید..."></textarea><button id="bimarstop-send" class="bimar-send">➤</button><div class="bimar-chat-hint">Enter برای ارسال • Shift+Enter برای خط جدید</div></div></main></div></div>';
        $this->chat_script();
    }

    public function operator_chat_page(): void {
        if ($this->current_role() !== 'bimarstop_operator' && !current_user_can('manage_options')) return;
        global $wpdb;
        $tid = absint($_GET['thread'] ?? 0);
        if ($tid && $this->current_role() === 'bimarstop_operator') {
            $wpdb->update($wpdb->prefix.'bimarstop_chat_threads',['operator_id'=>get_current_user_id(),'updated_at'=>current_time('mysql')],['id'=>$tid],['%d','%s'],['%d']);
        }
        $thread = $tid ? $wpdb->get_row($wpdb->prepare("SELECT t.*,u.display_name FROM {$wpdb->prefix}bimarstop_chat_threads t JOIN {$wpdb->users} u ON u.ID=t.patient_id WHERE t.id=%d",$tid)) : null;
        echo '<div class="bimar-chat-page" dir="rtl">';
        echo '<div class="bimar-chat-header"><div><span class="bimar-chat-kicker">BimarStop • پشتیبانی</span><h1>💬 گفت‌وگو با مریض</h1><p>'.($thread ? 'در حال پاسخ‌گویی به '.esc_html($thread->display_name) : 'یک گفتگو را از صف چت انتخاب کنید.').'</p></div><a class="bimar-chat-back" href="'.esc_url(admin_url('admin.php?page=bimarstop-queue')).'">← صف گفتگوها</a></div>';
        if (!$thread) { echo '<div class="bimar-chat-empty">یک گفتگو را از «صف ورودی» انتخاب کنید.</div></div>'; return; }
        echo '<div class="bimar-chat-shell"><aside class="bimar-chat-side"><div class="bimar-chat-side-title">مکالمه فعال</div><div class="bimar-chat-tab active">🧑 '.esc_html($thread->display_name).'</div><div class="bimar-chat-side-info">📎 ارسال فایل فعال است<br><span>فایل‌های PDF، JPG، PNG و DOCX تا ۱۰ مگابایت.</span></div></aside>';
        echo '<main class="bimar-chat-main"><div id="bimarstop-chat-box" class="bimar-chat-box"></div><div id="bimar-chat-attachment" class="bimar-chat-attachment" hidden><span>📎 <b id="bimar-file-name"></b></span><button type="button" id="bimar-file-remove">×</button></div><div class="bimar-chat-composer"><label class="bimar-attach"><input id="bimarstop-chat-file" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx" hidden>📎</label><textarea id="bimarstop-chat-input" rows="1" placeholder="پاسخ خود را بنویسید..."></textarea><button id="bimarstop-send" class="bimar-send">➤</button><div class="bimar-chat-hint">Enter برای ارسال • Shift+Enter برای خط جدید</div></div></main></div></div>';
        $this->chat_script();
    }

    public function operator_chat_queue(): void {
        if (!current_user_can('read')) return;
        global $wpdb;
        $threads=$wpdb->get_results("SELECT t.*, u.display_name FROM {$wpdb->prefix}bimarstop_chat_threads t LEFT JOIN {$wpdb->users} u ON u.ID=t.patient_id WHERE t.status='open' ORDER BY t.updated_at DESC");
        echo '<div class="wrap" dir="rtl"><h1>💬 صف چت</h1><table class="widefat striped"><thead><tr><th>مریض</th><th>وضعیت</th><th>آخرین بروزرسانی</th><th>عملیات</th></tr></thead><tbody>';
        foreach($threads as $t) echo '<tr><td>'.esc_html($t->display_name).'</td><td>'.($t->operator_id?'در حال پاسخ':'منتظر اوپراتور').'</td><td>'.esc_html($t->updated_at).'</td><td><a class="button" href="'.esc_url(admin_url('admin.php?page=bimarstop-chat&thread='.(int)$t->id)).'">باز کردن چت</a></td></tr>';
        if(!$threads) echo '<tr><td colspan="3">چتی در صف نیست.</td></tr>';
        echo '</tbody></table></div>';
    }

    private function chat_script(): void {
        $nonce=wp_create_nonce('bimarstop_chat');
        $ajax=admin_url('admin-ajax.php');
        echo '<script>
        (function(){
            var box=document.getElementById("bimarstop-chat-box"), input=document.getElementById("bimarstop-chat-input"),
                send=document.getElementById("bimarstop-send"), file=document.getElementById("bimarstop-chat-file"),
                attachment=document.getElementById("bimar-chat-attachment"), fileName=document.getElementById("bimar-file-name"),
                remove=document.getElementById("bimar-file-remove"), last=0;
            if(!box||!input||!send)return;
            function esc(t){var d=document.createElement("div");d.textContent=t;return d.innerHTML;}
            function render(m){
                var row=document.createElement("div"); row.className="bimar-msg "+(m.mine?"mine":"theirs");
                var bubble=document.createElement("div"); bubble.className="bimar-msg-bubble";
                var sender=document.createElement("div"); sender.className="bimar-msg-sender"; sender.textContent=m.sender;
                bubble.appendChild(sender);
                if(m.message){var body=document.createElement("div");body.className="bimar-msg-text";body.textContent=m.message;bubble.appendChild(body);}
                if(m.attachment){var a=document.createElement("a");a.className="bimar-file-card";a.href=m.attachment.url;a.target="_blank";a.rel="noopener";a.innerHTML="<span class=\"bimar-file-icon\">📎</span><span><b>"+esc(m.attachment.name)+"</b><small>"+esc(m.attachment.size)+"</small></span><strong>دانلود</strong>";bubble.appendChild(a);}
                var time=document.createElement("div");time.className="bimar-msg-time";time.textContent=m.time||"";bubble.appendChild(time);
                row.appendChild(bubble);box.appendChild(row);
            }
            function load(){
                var f=new FormData();f.append("action","bimarstop_get_messages");f.append("nonce","'.esc_js($nonce).'");f.append("last_id",last);
                fetch("'.esc_url($ajax).'",{method:"POST",body:f}).then(r=>r.json()).then(x=>{
                    if(!x.success)return;x.data.messages.forEach(function(m){render(m);last=Math.max(last,parseInt(m.id));});
                    if(x.data.messages.length)box.scrollTop=box.scrollHeight;
                });
            }
            function clearFile(){file.value="";attachment.hidden=true;fileName.textContent="";}
            file.addEventListener("change",function(){if(this.files[0]){fileName.textContent=this.files[0].name;attachment.hidden=false;}});
            remove.addEventListener("click",clearFile);
            function sendMessage(){
                var value=input.value.trim();
                if(!value && !file.files.length)return;
                var f=new FormData();f.append("action","bimarstop_send_message");f.append("nonce","'.esc_js($nonce).'");f.append("message",value);
                if(file.files[0])f.append("chat_file",file.files[0]); if(docSelect&&docSelect.value)f.append("document_id",docSelect.value);
                send.disabled=true;send.classList.add("loading");
                fetch("'.esc_url($ajax).'",{method:"POST",body:f}).then(r=>r.json()).then(function(x){
                    send.disabled=false;send.classList.remove("loading");
                    if(x.success){input.value="";clearFile();load();}else{alert((x.data&&x.data.message)?x.data.message:"ارسال پیام ناموفق بود.");}
                }).catch(function(){send.disabled=false;send.classList.remove("loading");alert("خطا در ارتباط با سرور.");});
            }
            send.onclick=sendMessage;
            input.addEventListener("keydown",function(e){if(e.key==="Enter"&&!e.shiftKey){e.preventDefault();sendMessage();}});
            if(docSelect){var df=new FormData();df.append("action","bimarstop_get_documents");df.append("nonce","'.esc_js($nonce).'");fetch("'.esc_url($ajax).'",{method:"POST",body:df}).then(r=>r.json()).then(function(x){if(x.success)x.data.documents.forEach(function(d){var o=document.createElement("option");o.value=d.id;o.textContent="📎 "+d.name+" — "+d.category+" — "+d.size;docSelect.appendChild(o);});});} load();setInterval(load,1000);
        })();
        </script>';
    }

    private function chat_user_can_access(int $thread_id, int $user_id): bool {
        global $wpdb; $t=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bimarstop_chat_threads WHERE id=%d",$thread_id));
        return $t && ((int)$t->patient_id===$user_id || (int)$t->operator_id===$user_id || current_user_can('manage_options'));
    }

    public function ajax_send_message(): void {
        check_ajax_referer('bimarstop_chat','nonce');
        if (!is_user_logged_in()) wp_send_json_error();
        global $wpdb;
        $uid=get_current_user_id();
        $msg=sanitize_textarea_field(wp_unslash($_POST['message']??''));
        $tname=$wpdb->prefix.'bimarstop_chat_threads';
        $thread=null;
        if($this->current_role()==='bimarstop_patient') $thread=$wpdb->get_row($wpdb->prepare("SELECT * FROM $tname WHERE patient_id=%d AND status='open' ORDER BY id DESC LIMIT 1",$uid));
        else $thread=$wpdb->get_row($wpdb->prepare("SELECT * FROM $tname WHERE operator_id=%d AND status='open' ORDER BY updated_at DESC LIMIT 1",$uid));
        if(!$thread && $this->current_role()==='bimarstop_patient'){
            $now=current_time('mysql');$wpdb->insert($tname,['patient_id'=>$uid,'operator_id'=>0,'status'=>'open','created_at'=>$now,'updated_at'=>$now],['%d','%d','%s','%s','%s']);
            $thread=(object)['id'=>$wpdb->insert_id,'patient_id'=>$uid,'operator_id'=>0,'status'=>'open'];
        }
        if(!$thread || !$this->chat_user_can_access((int)$thread->id,$uid)) wp_send_json_error(['message'=>'گفتگو پیدا نشد.']);
        if($this->current_role()==='bimarstop_operator' && (int)$thread->operator_id===0)$wpdb->update($tname,['operator_id'=>$uid,'updated_at'=>current_time('mysql')],['id'=>$thread->id],['%d','%s'],['%d']);

        $path='';$name='';$size=0;$type='';
        if(empty($_FILES['chat_file']) && !empty($_POST['document_id']) && $this->can_use_documents()){
            $did=absint($_POST['document_id']); global $wpdb;
            $doc=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bimarstop_documents WHERE id=%d",$did));
            $uploads=wp_upload_dir(); $real=$doc?realpath($doc->path):false; $base=!empty($uploads['basedir'])?realpath($uploads['basedir']):false;
            if($doc&&$real&&$base&&strpos($real,$base)===0&&is_file($real)){ $path=$real; $name=$doc->name; $size=(int)$doc->size; $type=$doc->type; }
        }
        if(!empty($_FILES['chat_file']) && is_array($_FILES['chat_file'])){
            if((int)$_FILES['chat_file']['size']>10*1024*1024) wp_send_json_error(['message'=>'حداکثر حجم فایل ۱۰ مگابایت است.']);
            require_once ABSPATH.'wp-admin/includes/file.php';
            $allowed=['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','doc'=>'application/msword','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
            $check=wp_check_filetype_and_ext($_FILES['chat_file']['tmp_name'],$_FILES['chat_file']['name'],$allowed);
            if(empty($check['ext'])||empty($check['type'])||!isset($allowed[$check['ext']])) wp_send_json_error(['message'=>'این نوع فایل مجاز نیست.']);
            $upload=wp_handle_upload($_FILES['chat_file'],['test_form'=>false,'mimes'=>$allowed]);
            if(isset($upload['error'])) wp_send_json_error(['message'=>$upload['error']]);
            $path=$upload['file'];$name=sanitize_file_name($_FILES['chat_file']['name']);$size=(int)$_FILES['chat_file']['size'];$type=$upload['type'];
        }
        if($msg==='' && !$path) wp_send_json_error(['message'=>'پیام یا فایل وارد کنید.']);
        $wpdb->insert($wpdb->prefix.'bimarstop_chat_messages',['thread_id'=>$thread->id,'sender_id'=>$uid,'message'=>$msg,'attachment_path'=>$path,'attachment_name'=>$name,'attachment_size'=>$size,'attachment_type'=>$type,'created_at'=>current_time('mysql')],['%d','%d','%s','%s','%s','%d','%s','%s']);
        $wpdb->update($tname,['updated_at'=>current_time('mysql')],['id'=>$thread->id],['%s'],['%d']);
        wp_send_json_success();
    }

    public function ajax_get_messages(): void {
        check_ajax_referer('bimarstop_chat','nonce');
        if (!is_user_logged_in()) wp_send_json_error();
        global $wpdb;$uid=get_current_user_id();$last=absint($_POST['last_id']??0);$tn=$wpdb->prefix.'bimarstop_chat_threads';$thread=null;
        if($this->current_role()==='bimarstop_patient')$thread=$wpdb->get_row($wpdb->prepare("SELECT * FROM $tn WHERE patient_id=%d AND status='open' ORDER BY id DESC LIMIT 1",$uid));
        else $thread=$wpdb->get_row($wpdb->prepare("SELECT * FROM $tn WHERE operator_id=%d AND status='open' ORDER BY updated_at DESC LIMIT 1",$uid));
        if(!$thread)wp_send_json_success(['messages'=>[]]);
        $rows=$wpdb->get_results($wpdb->prepare("SELECT m.id,m.message,m.attachment_name,m.attachment_size,m.attachment_type,u.display_name,m.created_at FROM {$wpdb->prefix}bimarstop_chat_messages m JOIN {$wpdb->users} u ON u.ID=m.sender_id WHERE m.thread_id=%d AND m.id>%d ORDER BY m.id ASC",$thread->id,$last));
        $out=[];
        foreach($rows as $r){
            $att=null;
            if(!empty($r->attachment_name))$att=['name'=>$r->attachment_name,'size'=>size_format((int)$r->attachment_size),'url'=>admin_url('admin-ajax.php?action=bimarstop_download_file&message_id='.(int)$r->id.'&nonce='.wp_create_nonce('bimarstop_download') )];
            $out[]=['id'=>(int)$r->id,'message'=>$r->message,'sender'=>$r->display_name,'mine'=>(int)$r->sender_id===$uid,'time'=>mysql2date('H:i', $r->created_at),'attachment'=>$att];
        }
        wp_send_json_success(['messages'=>$out]);
    }

    public function ajax_set_operator_status(): void {
        check_ajax_referer('bimarstop_operator','nonce');
        if($this->current_role()!=='bimarstop_operator') wp_send_json_error();
        update_user_meta(get_current_user_id(),'bimarstop_operator_online',!empty($_POST['online'])?'1':'0');
        wp_send_json_success();
    }

    private function private_pair(int $a, int $b): array {
        return $a < $b ? [$a, $b] : [$b, $a];
    }

    private function private_partner_allowed(int $current_id, int $partner_id): bool {
        if ($partner_id <= 0 || $current_id === $partner_id) return false;
        $current = get_userdata($current_id);
        $partner = get_userdata($partner_id);
        if (!$current || !$partner) return false;
        $cr = (array) $current->roles;
        $pr = (array) $partner->roles;
        return (($cr[0] ?? '') === 'bimarstop_operator' && ($pr[0] ?? '') === 'bimarstop_doctor')
            || (($cr[0] ?? '') === 'bimarstop_doctor' && ($pr[0] ?? '') === 'bimarstop_operator')
            || current_user_can('manage_options');
    }

    private function get_or_create_private_thread(int $a, int $b): ?object {
        global $wpdb;
        [$a, $b] = $this->private_pair($a, $b);
        $table = $wpdb->prefix . 'bimarstop_private_threads';
        $thread = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE user_a_id=%d AND user_b_id=%d LIMIT 1", $a, $b));
        if ($thread) return $thread;
        $now = current_time('mysql');
        $wpdb->insert($table, [
            'user_a_id' => $a, 'user_b_id' => $b, 'status' => 'open',
            'created_at' => $now, 'updated_at' => $now
        ], ['%d','%d','%s','%s','%s']);
        return $wpdb->insert_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $wpdb->insert_id)) : null;
    }

    private function private_thread_access(int $thread_id, int $user_id): bool {
        global $wpdb;
        $t = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bimarstop_private_threads WHERE id=%d", $thread_id));
        return $t && ((int)$t->user_a_id === $user_id || (int)$t->user_b_id === $user_id || current_user_can('manage_options'));
    }

    private function render_private_chat(int $partner_id, string $back_page): void {
        if (!is_user_logged_in()) return;
        $uid=get_current_user_id();
        if(!$this->private_partner_allowed($uid,$partner_id)){echo '<div class="wrap" dir="rtl"><div class="notice notice-error"><p>دسترسی به این گفت‌وگو مجاز نیست.</p></div></div>';return;}
        $partner=get_userdata($partner_id);$thread=$this->get_or_create_private_thread($uid,$partner_id);
        if(!$thread){echo '<div class="wrap" dir="rtl"><p>خطا در ساخت گفتگو.</p></div>';return;}
        echo '<div class="bimar-chat-page" dir="rtl"><div class="bimar-chat-header"><div><span class="bimar-chat-kicker">BimarStop • ارتباط داخلی</span><h1>💬 گفتگوی خصوصی</h1><p>با '.esc_html($partner->display_name?:$partner->user_login).'</p></div><a class="bimar-chat-back" href="'.esc_url(admin_url('admin.php?page='.$back_page)).'">← بازگشت</a></div>';
        echo '<div class="bimar-chat-shell"><aside class="bimar-chat-side"><div class="bimar-chat-side-title">گفتگوی داخلی</div><div class="bimar-chat-tab active">💬 '.esc_html($partner->display_name?:$partner->user_login).'</div><div class="bimar-chat-side-info">🔒 گفتگوی خصوصی پزشک و اوپراتور<br><span>فایل‌های PDF، JPG، PNG و DOCX تا ۱۰ مگابایت.</span></div></aside><main class="bimar-chat-main"><div id="bimarstop-private-box" class="bimar-chat-box"></div><div id="bimar-private-attachment" class="bimar-chat-attachment" hidden><span>📎 <b id="bimar-private-file-name"></b></span><button type="button" id="bimar-private-file-remove">×</button></div><div class="bimar-chat-composer"><label class="bimar-attach"><input id="bimarstop-private-file" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx" hidden>📎</label>'.($this->can_use_documents() ? '<select id="bimarstop-private-document" title="انتخاب مدرک موجود"><option value="">📚 مدرک موجود</option></select>' : '').'<textarea id="bimarstop-private-input" rows="1" placeholder="پیام خود را بنویسید..."></textarea><button type="button" id="bimarstop-private-send" class="bimar-send">➤</button><div class="bimar-chat-hint">Enter برای ارسال • Shift+Enter برای خط جدید</div></div></main></div></div>';
        $nonce=wp_create_nonce('bimarstop_private_chat');$ajax=admin_url('admin-ajax.php');
        echo '<script>(function(){var box=document.getElementById("bimarstop-private-box"),input=document.getElementById("bimarstop-private-input"),send=document.getElementById("bimarstop-private-send"),file=document.getElementById("bimarstop-private-file"),att=document.getElementById("bimar-private-attachment"),fn=document.getElementById("bimar-private-file-name"),rm=document.getElementById("bimar-private-file-remove"),docSelect=document.getElementById("bimarstop-private-document"),last=0;
        function esc(t){var d=document.createElement("div");d.textContent=t;return d.innerHTML;}function render(m){var row=document.createElement("div");row.className="bimar-msg "+(m.mine?"mine":"theirs");var b=document.createElement("div");b.className="bimar-msg-bubble";var s=document.createElement("div");s.className="bimar-msg-sender";s.textContent=m.sender;b.appendChild(s);if(m.message){var x=document.createElement("div");x.className="bimar-msg-text";x.textContent=m.message;b.appendChild(x);}if(m.attachment){var a=document.createElement("a");a.className="bimar-file-card";a.href=m.attachment.url;a.innerHTML="<span class=\"bimar-file-icon\">📎</span><span><b>"+esc(m.attachment.name)+"</b><small>"+esc(m.attachment.size)+"</small></span><strong>دانلود</strong>";b.appendChild(a);}var tm=document.createElement("div");tm.className="bimar-msg-time";tm.textContent=m.time||"";b.appendChild(tm);row.appendChild(b);box.appendChild(row);}
        function load(){var f=new FormData();f.append("action","bimarstop_private_get_messages");f.append("nonce","'.esc_js($nonce).'");f.append("thread_id","'.(int)$thread->id.'");f.append("last_id",last);fetch("'.esc_url($ajax).'",{method:"POST",body:f}).then(r=>r.json()).then(x=>{if(!x.success)return;x.data.messages.forEach(function(m){render(m);last=Math.max(last,parseInt(m.id));});if(x.data.messages.length)box.scrollTop=box.scrollHeight;});}
        function clearFile(){file.value="";att.hidden=true;fn.textContent="";}file.addEventListener("change",function(){if(this.files[0]){fn.textContent=this.files[0].name;att.hidden=false;}});rm.onclick=clearFile;
        function sendMsg(){var v=input.value.trim();var chosen=file.files[0]||null;if(!v&&!chosen)return;var f=new FormData();f.append("action","bimarstop_private_send_message");f.append("nonce","'.esc_js($nonce).'");f.append("thread_id","'.(int)$thread->id.'");f.append("message",v);if(chosen)f.append("chat_file",chosen);if(docSelect&&docSelect.value)f.append("document_id",docSelect.value);send.disabled=true;input.value="";file.value="";att.classList.remove("show");fn.textContent="";rm.style.display="none";fetch("'.esc_url($ajax).'",{method:"POST",body:f,keepalive:true}).then(function(r){return r.json();}).then(function(x){send.disabled=false;if(x.success){load();}else{alert((x.data&&x.data.message)?x.data.message:"ارسال ناموفق بود.");}}).catch(function(){send.disabled=false;alert("ارتباط با سرور برقرار نشد.");});}send.onclick=function(e){e.preventDefault();sendMsg();};
        input.addEventListener("keydown",function(e){if(e.key==="Enter"&&!e.shiftKey){e.preventDefault();sendMsg();}});
        load();setInterval(load,4000);})();</script>';
    }

    public function operator_private_chats_page(): void {
        if ($this->current_role() !== 'bimarstop_operator' && !current_user_can('manage_options')) return;
        $partner = absint($_GET['user'] ?? 0);
        if ($partner) { $this->render_private_chat($partner, 'bimarstop-doctor-chats'); return; }
        $doctors = $this->users_by_role('bimarstop_doctor');
        echo '<div class="wrap" dir="rtl"><h1>🩺 چت خصوصی با پزشکان</h1><table class="widefat striped"><thead><tr><th>پزشک</th><th>ایمیل</th><th>عملیات</th></tr></thead><tbody>';
        foreach ($doctors as $u) echo '<tr><td>'.esc_html($u->display_name ?: $u->user_login).'</td><td>'.esc_html($u->user_email).'</td><td><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=bimarstop-doctor-chats&user='.(int)$u->ID)).'">شروع / ادامه چت</a></td></tr>';
        if (!$doctors) echo '<tr><td colspan="3">پزشکی ثبت نشده است.</td></tr>';
        echo '</tbody></table></div>';
    }

    public function doctor_private_chats_page(): void {
        if ($this->current_role() !== 'bimarstop_doctor' && !current_user_can('manage_options')) return;
        $partner = absint($_GET['user'] ?? 0);
        if ($partner) { $this->render_private_chat($partner, 'bimarstop-doctor-chats'); return; }
        $operators = $this->users_by_role('bimarstop_operator');
        echo '<div class="wrap" dir="rtl"><h1>👨‍💻 چت خصوصی با اوپراتورها</h1><table class="widefat striped"><thead><tr><th>اوپراتور</th><th>وضعیت</th><th>ایمیل</th><th>عملیات</th></tr></thead><tbody>';
        foreach ($operators as $u) { $online = get_user_meta($u->ID, 'bimarstop_operator_online', true) === '1'; echo '<tr><td>'.esc_html($u->display_name ?: $u->user_login).'</td><td>'.($online?'آنلاین 🟢':'آفلاین ⚪').'</td><td>'.esc_html($u->user_email).'</td><td><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=bimarstop-doctor-chats&user='.(int)$u->ID)).'">شروع / ادامه چت</a></td></tr>'; }
        if (!$operators) echo '<tr><td colspan="4">اوپراتوری ثبت نشده است.</td></tr>';
        echo '</tbody></table></div>';
    }

    public function admin_private_chats_page(): void {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $thread_id = absint($_GET['thread'] ?? 0);
        if ($thread_id) {
            $t = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bimarstop_private_threads WHERE id=%d", $thread_id));
            if ($t) {
                $this->render_private_chat((int)$t->user_a_id === get_current_user_id() ? (int)$t->user_b_id : (int)$t->user_a_id, 'bimarstop-private-chats');
                return;
            }
        }
        $rows = $wpdb->get_results("SELECT t.*, a.display_name AS a_name, b.display_name AS b_name FROM {$wpdb->prefix}bimarstop_private_threads t LEFT JOIN {$wpdb->users} a ON a.ID=t.user_a_id LEFT JOIN {$wpdb->users} b ON b.ID=t.user_b_id ORDER BY t.updated_at DESC");
        echo '<div class="wrap" dir="rtl"><h1>💬 چت‌های داخلی</h1><p>گفتگوهای خصوصی پزشک و اوپراتور.</p><table class="widefat striped"><thead><tr><th>کاربر اول</th><th>کاربر دوم</th><th>وضعیت</th><th>آخرین بروزرسانی</th><th>عملیات</th></tr></thead><tbody>';
        foreach ($rows as $r) echo '<tr><td>'.esc_html($r->a_name).'</td><td>'.esc_html($r->b_name).'</td><td>'.esc_html($r->status).'</td><td>'.esc_html($r->updated_at).'</td><td><a class="button" href="'.esc_url(admin_url('admin.php?page=bimarstop-private-chats&thread='.(int)$r->id)).'">مشاهده</a></td></tr>';
        if (!$rows) echo '<tr><td colspan="5">هنوز چت داخلی‌ای وجود ندارد.</td></tr>';
        echo '</tbody></table></div>';
    }

    public function ajax_private_send_message(): void {
        check_ajax_referer('bimarstop_private_chat','nonce');
        if (!is_user_logged_in()) wp_send_json_error();
        global $wpdb;$uid=get_current_user_id();$thread_id=absint($_POST['thread_id']??0);$msg=sanitize_textarea_field(wp_unslash($_POST['message']??''));
        if(!$thread_id||!$this->private_thread_access($thread_id,$uid))wp_send_json_error();
        $path='';$name='';$size=0;$type='';
        if(empty($_FILES['chat_file']) && !empty($_POST['document_id']) && $this->can_use_documents()){
            $did=absint($_POST['document_id']); global $wpdb;
            $doc=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bimarstop_documents WHERE id=%d",$did));
            $uploads=wp_upload_dir(); $real=$doc?realpath($doc->path):false; $base=!empty($uploads['basedir'])?realpath($uploads['basedir']):false;
            if($doc&&$real&&$base&&strpos($real,$base)===0&&is_file($real)){ $path=$real; $name=$doc->name; $size=(int)$doc->size; $type=$doc->type; }
        }
        if(!empty($_FILES['chat_file'])&&is_array($_FILES['chat_file'])){
            if((int)$_FILES['chat_file']['size']>10*1024*1024)wp_send_json_error(['message'=>'حداکثر حجم فایل ۱۰ مگابایت است.']);
            require_once ABSPATH.'wp-admin/includes/file.php';
            $allowed=['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','doc'=>'application/msword','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
            $check=wp_check_filetype_and_ext($_FILES['chat_file']['tmp_name'],$_FILES['chat_file']['name'],$allowed);
            if(empty($check['ext'])||empty($check['type'])||!isset($allowed[$check['ext']]))wp_send_json_error(['message'=>'این نوع فایل مجاز نیست.']);
            $upload=wp_handle_upload($_FILES['chat_file'],['test_form'=>false,'mimes'=>$allowed]);
            if(isset($upload['error']))wp_send_json_error(['message'=>$upload['error']]);
            $path=$upload['file'];$name=sanitize_file_name($_FILES['chat_file']['name']);$size=(int)$_FILES['chat_file']['size'];$type=$upload['type'];
        }
        if($msg===''&&!$path)wp_send_json_error(['message'=>'پیام یا فایل وارد کنید.']);
        $wpdb->insert($wpdb->prefix.'bimarstop_private_messages',['thread_id'=>$thread_id,'sender_id'=>$uid,'message'=>$msg,'attachment_path'=>$path,'attachment_name'=>$name,'attachment_size'=>$size,'attachment_type'=>$type,'created_at'=>current_time('mysql')],['%d','%d','%s','%s','%s','%d','%s','%s']);
        $wpdb->update($wpdb->prefix.'bimarstop_private_threads',['updated_at'=>current_time('mysql')],['id'=>$thread_id],['%s'],['%d']);wp_send_json_success();
    }

    public function ajax_private_get_messages(): void {
        check_ajax_referer('bimarstop_private_chat','nonce');
        if(!is_user_logged_in())wp_send_json_error();
        global $wpdb;$uid=get_current_user_id();$thread_id=absint($_POST['thread_id']??0);$last=absint($_POST['last_id']??0);
        if(!$thread_id||!$this->private_thread_access($thread_id,$uid))wp_send_json_error();
        $rows=$wpdb->get_results($wpdb->prepare("SELECT m.id,m.message,m.sender_id,m.attachment_name,m.attachment_size,u.display_name,m.created_at FROM {$wpdb->prefix}bimarstop_private_messages m JOIN {$wpdb->users} u ON u.ID=m.sender_id WHERE m.thread_id=%d AND m.id>%d ORDER BY m.id ASC",$thread_id,$last));
        $out=[];foreach($rows as $r){$att=null;if(!empty($r->attachment_name))$att=['name'=>$r->attachment_name,'size'=>size_format((int)$r->attachment_size),'url'=>admin_url('admin-ajax.php?action=bimarstop_download_file&private_message_id='.(int)$r->id.'&nonce='.wp_create_nonce('bimarstop_download') )];$out[]=['id'=>(int)$r->id,'message'=>$r->message,'sender'=>$r->display_name,'mine'=>(int)$r->sender_id===$uid,'time'=>mysql2date('H:i',$r->created_at),'attachment'=>$att];}
        wp_send_json_success(['messages'=>$out]);
    }

    public function ajax_download_file(): void {
        if(!is_user_logged_in()||!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['nonce']??'')),'bimarstop_download'))wp_die('دسترسی غیرمجاز',403);
        global $wpdb;$uid=get_current_user_id();$path='';
        if(!empty($_GET['message_id'])){$id=absint($_GET['message_id']);$r=$wpdb->get_row($wpdb->prepare("SELECT t.patient_id,t.operator_id,m.attachment_path,m.attachment_name,m.attachment_type FROM {$wpdb->prefix}bimarstop_chat_messages m JOIN {$wpdb->prefix}bimarstop_chat_threads t ON t.id=m.thread_id WHERE m.id=%d",$id));if($r&&($r->patient_id==$uid||$r->operator_id==$uid||current_user_can('manage_options'))) $path=$r->attachment_path;$name=$r->attachment_name??'';$type=$r->attachment_type??'application/octet-stream';}
        elseif(!empty($_GET['private_message_id'])){$id=absint($_GET['private_message_id']);$r=$wpdb->get_row($wpdb->prepare("SELECT t.user_a_id,t.user_b_id,m.attachment_path,m.attachment_name,m.attachment_type FROM {$wpdb->prefix}bimarstop_private_messages m JOIN {$wpdb->prefix}bimarstop_private_threads t ON t.id=m.thread_id WHERE m.id=%d",$id));if($r&&($r->user_a_id==$uid||$r->user_b_id==$uid||current_user_can('manage_options'))) $path=$r->attachment_path;$name=$r->attachment_name??'';$type=$r->attachment_type??'application/octet-stream';}
        else wp_die('فایل پیدا نشد',404);
        if(!$path||!is_file($path))wp_die('فایل پیدا نشد',404);
        nocache_headers();header('Content-Type: '.sanitize_text_field($type));header('Content-Length: '.filesize($path));header('Content-Disposition: attachment; filename="'.str_replace('"','',wp_basename($name)).'"');readfile($path);exit;
    }

    public function report_issue_page(): void {
        if(!current_user_can('read')) return;
        global $wpdb; $table=$wpdb->prefix.'bimarstop_issue_reports'; $uid=get_current_user_id();
        if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['bimarstop_issue_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bimarstop_issue_nonce'])),'bimarstop_issue')){
            $wpdb->insert($table,['user_id'=>$uid,'subject'=>sanitize_text_field(wp_unslash($_POST['subject']??'')),'description'=>sanitize_textarea_field(wp_unslash($_POST['description']??'')),'priority'=>in_array($_POST['priority']??'normal',['low','normal','high'],true)?$_POST['priority']:'normal','status'=>'open','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')],['%d','%s','%s','%s','%s','%s']);
            echo '<div class="notice notice-success"><p>گزارش مشکل ثبت شد.</p></div>';
        }
        if(current_user_can('manage_options') && isset($_POST['bimarstop_issue_status_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bimarstop_issue_status_nonce'])),'bimarstop_issue_status')){
            $id=absint($_POST['issue_id']??0); $status=sanitize_key($_POST['status']??'open');
            if(in_array($status,['open','progress','closed'],true)) $wpdb->update($table,['status'=>$status,'updated_at'=>current_time('mysql')],['id'=>$id],['%s','%s'],['%d']);
        }
        echo '<div class="wrap" dir="rtl"><h1>🛠 گزارش مشکل</h1>';
        if(current_user_can('manage_options')){
            $rows=$wpdb->get_results("SELECT r.*,u.display_name FROM $table r LEFT JOIN {$wpdb->users} u ON u.ID=r.user_id ORDER BY r.id DESC");
            echo '<table class="widefat striped"><thead><tr><th>#</th><th>کاربر</th><th>موضوع</th><th>اولویت</th><th>وضعیت</th><th>تاریخ</th><th>تغییر</th></tr></thead><tbody>';
            foreach($rows as $r){ echo '<tr><td>'.(int)$r->id.'</td><td>'.esc_html($r->display_name).'</td><td>'.esc_html($r->subject).'<br><small>'.esc_html($r->description).'</small></td><td>'.esc_html($r->priority).'</td><td>'.esc_html($r->status).'</td><td>'.esc_html($r->created_at).'</td><td><form method="post">'.wp_nonce_field('bimarstop_issue_status','bimarstop_issue_status_nonce',true,false).'<input type="hidden" name="issue_id" value="'.(int)$r->id.'"><select name="status"><option value="open">باز</option><option value="progress">در حال بررسی</option><option value="closed">بسته</option></select> <button class="button">ذخیره</button></form></td></tr>'; }
            if(!$rows) echo '<tr><td colspan="7">گزارشی ثبت نشده است.</td></tr>';
            echo '</tbody></table>';
        } else {
            echo '<form method="post" style="max-width:760px;background:#fff;padding:24px;border:1px solid #ddd;border-radius:12px">'.wp_nonce_field('bimarstop_issue','bimarstop_issue_nonce',true,false).'<p><label>موضوع<br><input class="regular-text" name="subject" required></label></p><p><label>اولویت<br><select name="priority"><option value="normal">عادی</option><option value="high">زیاد</option><option value="low">کم</option></select></label></p><p><label>شرح مشکل<br><textarea name="description" rows="8" style="width:100%" required></textarea></label></p><p><button class="button button-primary">ثبت گزارش</button></p></form>';
        }
        echo '</div>';
    }

    public function documents_page(): void {
        if(!$this->can_use_documents()) return;
        global $wpdb; $this->sync_documents(); $dt=$wpdb->prefix.'bimarstop_documents'; $ct=$wpdb->prefix.'bimarstop_document_categories';
        if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['bimarstop_doc_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bimarstop_doc_nonce'])),'bimarstop_doc')){
            $action=sanitize_key($_POST['doc_action']??'');
            if($action==='add_category'){ $name=sanitize_text_field(wp_unslash($_POST['category_name']??'')); if($name!=='')$wpdb->insert($ct,['name'=>$name,'created_at'=>current_time('mysql')],['%s','%s']); }
            if($action==='set_category'){ $id=absint($_POST['doc_id']??0);$cat=absint($_POST['category_id']??0);$wpdb->update($dt,['category_id'=>$cat,'updated_at'=>current_time('mysql')],['id'=>$id],['%d','%s'],['%d']); }
        }
        $cats=$wpdb->get_results("SELECT * FROM $ct ORDER BY name ASC"); $rows=$wpdb->get_results("SELECT d.*,c.name AS category_name FROM $dt d LEFT JOIN $ct c ON c.id=d.category_id ORDER BY d.updated_at DESC");
        echo '<div class="wrap" dir="rtl"><h1>📚 مدارک</h1><p>فایل‌های موجود در پوشه uploads بدون آپلود مجدد قابل استفاده در پیام‌ها هستند.</p><form method="post" style="margin:16px 0">'.wp_nonce_field('bimarstop_doc','bimarstop_doc_nonce',true,false).'<input type="hidden" name="doc_action" value="add_category"><input name="category_name" placeholder="نام دسته جدید" required> <button class="button">افزودن دسته</button></form><table class="widefat striped"><thead><tr><th>فایل</th><th>حجم</th><th>نوع</th><th>دسته</th><th>مسیر</th></tr></thead><tbody>';
        foreach($rows as $r){ echo '<tr><td>📎 '.esc_html($r->name).'</td><td>'.size_format((int)$r->size).'</td><td>'.esc_html($r->type).'</td><td><form method="post">'.wp_nonce_field('bimarstop_doc','bimarstop_doc_nonce',true,false).'<input type="hidden" name="doc_action" value="set_category"><input type="hidden" name="doc_id" value="'.(int)$r->id.'"><select name="category_id" onchange="this.form.submit()">'; foreach($cats as $cat) echo '<option value="'.(int)$cat->id.'" '.selected((int)$r->category_id,(int)$cat->id,false).'>'.esc_html($cat->name).'</option>'; echo '</select></form></td><td><code>'.esc_html(str_replace(wp_upload_dir()['basedir'].'/','',$r->path)).'</code></td></tr>'; }
        if(!$rows) echo '<tr><td colspan="5">فایل مجازی پیدا نشد.</td></tr>';
        echo '</tbody></table></div>';
    }

    public function ajax_get_documents(): void {
        check_ajax_referer('bimarstop_chat','nonce');
        if(!$this->can_use_documents()) wp_send_json_error(['message'=>'دسترسی ندارید.']);
        global $wpdb; $this->sync_documents();
        $rows=$wpdb->get_results("SELECT d.id,d.name,d.size,d.type,c.name AS category_name FROM {$wpdb->prefix}bimarstop_documents d LEFT JOIN {$wpdb->prefix}bimarstop_document_categories c ON c.id=d.category_id ORDER BY c.name ASC,d.name ASC");
        $out=[]; foreach($rows as $r)$out[]=['id'=>(int)$r->id,'name'=>$r->name,'size'=>size_format((int)$r->size),'category'=>$r->category_name?:'عمومی'];
        wp_send_json_success(['documents'=>$out]);
    }

    public function ajax_send_document(): void {
        if(!$this->can_use_documents()) wp_send_json_error();
        check_ajax_referer('bimarstop_chat','nonce');
        $did=absint($_POST['document_id']??0);
        if(!$did)wp_send_json_error(['message'=>'مدرک انتخاب نشده است.']);
        wp_send_json_success();
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
