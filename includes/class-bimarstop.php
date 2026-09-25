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
        add_action('template_redirect', [$this, 'require_login']);
        add_filter('show_admin_bar', [$this, 'show_admin_bar']);
        add_filter('pre_user_role', [$this, 'force_patient_registration_role'], 10, 2);
        add_action('admin_menu', [$this, 'restrict_role_admin_menu'], 999);
    }

    public static function activate(): void {
        self::register_bimarstop_roles();
        self::create_chat_tables();
        if (get_option('bimarstop_settings', false) === false) {
            add_option('bimarstop_settings', ['theme' => 'light-1']);
        }
        flush_rewrite_rules();
    }

    public static function deactivate(): void { flush_rewrite_rules(); }

    public function register_roles(): void { self::register_bimarstop_roles(); self::create_chat_tables(); }

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
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY thread_id (thread_id),
            KEY sender_id (sender_id)
        ) {$charset};");
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
            add_submenu_page('bimarstop', 'مدارک', 'مدارک', 'read', 'bimarstop-documents', [$this, 'role_placeholder']);
            add_submenu_page('bimarstop', 'تماس‌ها', 'تماس‌ها', 'read', 'bimarstop-calls', [$this, 'role_placeholder']);
            add_submenu_page('bimarstop', 'گزارش مشکل', 'گزارش مشکل', 'read', 'bimarstop-report-issue', [$this, 'role_placeholder']);
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
            return;
        }

        if (!current_user_can('manage_options')) return;

        add_menu_page('BimarStop', 'BimarStop', 'manage_options', 'bimarstop', [$this, 'admin_dashboard'], 'dashicons-heart', 25);
        add_submenu_page('bimarstop', 'داشبورد', 'داشبورد', 'manage_options', 'bimarstop', [$this, 'admin_dashboard']);
        add_submenu_page('bimarstop', 'بیماران', 'بیماران', 'manage_options', 'bimarstop-patients', [$this, 'patients_page']);
        add_submenu_page('bimarstop', 'پزشکان', 'پزشکان', 'manage_options', 'bimarstop-doctors', [$this, 'doctors_page']);
        add_submenu_page('bimarstop', 'اوپراتورها', 'اوپراتورها', 'manage_options', 'bimarstop-operators', [$this, 'operators_page']);
        add_submenu_page('bimarstop', 'چت اپراتورها', 'چت اپراتورها', 'manage_options', 'bimarstop-chat', [$this, 'operator_chat_queue']);
        add_submenu_page('bimarstop', 'پرونده‌ها و اتاق‌ها', 'پرونده‌ها و اتاق‌ها', 'manage_options', 'bimarstop-rooms', [$this, 'role_placeholder']);
        add_submenu_page('bimarstop', 'مدارک', 'مدارک', 'manage_options', 'bimarstop-documents', [$this, 'role_placeholder']);
        add_submenu_page('bimarstop', 'تماس‌ها', 'تماس‌ها', 'manage_options', 'bimarstop-calls', [$this, 'role_placeholder']);
        add_submenu_page('bimarstop', 'پرداخت‌ها', 'پرداخت‌ها', 'manage_options', 'bimarstop-payments', [$this, 'role_placeholder']);
        add_submenu_page('bimarstop', 'اعلان‌ها و پیامک', 'اعلان‌ها و پیامک', 'manage_options', 'bimarstop-notifications', [$this, 'role_placeholder']);
        add_submenu_page('bimarstop', 'هوش مصنوعی', 'هوش مصنوعی', 'manage_options', 'bimarstop-ai', [$this, 'role_placeholder']);
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
        $notice = $online ? '' : '<div class="notice notice-warning inline"><p>فعلاً همه اوپراتورها آفلاین هستند. ممکن است به پیام شما دیر پاسخ داده شود.</p></div>';
        echo '<div class="wrap" dir="rtl"><h1>💬 چت با اوپراتور</h1>' . $notice;
        echo '<div id="bimarstop-chat-box" style="background:#fff;border:1px solid #ccd0d4;padding:16px;max-width:800px;min-height:300px;overflow:auto"></div>';
        echo '<p><textarea id="bimarstop-chat-input" rows="3" style="width:100%;max-width:800px"></textarea></p>';
        echo '<button class="button button-primary" id="bimarstop-send">ارسال پیام</button></div>';
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
        echo '<div class="wrap" dir="rtl"><h1>💬 چت با مریض</h1>';
        if (!$thread) { echo '<p>یک گفتگو را از «صف چت» انتخاب کنید.</p></div>'; return; }
        echo '<h2>مریض: '.esc_html($thread->display_name).'</h2>';
        echo '<div id="bimarstop-chat-box" style="background:#fff;border:1px solid #ccd0d4;padding:16px;max-width:800px;min-height:300px;overflow:auto"></div>';
        echo '<p><textarea id="bimarstop-chat-input" rows="3" style="width:100%;max-width:800px"></textarea></p><button class="button button-primary" id="bimarstop-send">ارسال پیام</button></div>';
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
        (function(){var box=document.getElementById("bimarstop-chat-box"),input=document.getElementById("bimarstop-chat-input"),send=document.getElementById("bimarstop-send"),last=0;
        function load(){var f=new FormData();f.append("action","bimarstop_get_messages");f.append("nonce","'.esc_js($nonce).'");f.append("last_id",last);fetch("'.esc_url($ajax).'",{method:"POST",body:f}).then(r=>r.json()).then(x=>{if(!x.success)return;x.data.messages.forEach(function(m){var p=document.createElement("p");p.innerHTML="<strong>"+m.sender+"</strong>: "+m.message;box.appendChild(p);last=Math.max(last,parseInt(m.id));box.scrollTop=box.scrollHeight;});});}
        send.onclick=function(){if(!input.value.trim())return;var f=new FormData();f.append("action","bimarstop_send_message");f.append("nonce","'.esc_js($nonce).'");f.append("message",input.value);fetch("'.esc_url($ajax).'",{method:"POST",body:f}).then(r=>r.json()).then(function(){input.value="";load();});};load();setInterval(load,4000);})();
        </script>';
    }

    private function chat_user_can_access(int $thread_id, int $user_id): bool {
        global $wpdb; $t=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bimarstop_chat_threads WHERE id=%d",$thread_id));
        return $t && ((int)$t->patient_id===$user_id || (int)$t->operator_id===$user_id || current_user_can('manage_options'));
    }

    public function ajax_send_message(): void {
        check_ajax_referer('bimarstop_chat','nonce');
        if (!is_user_logged_in()) wp_send_json_error();
        global $wpdb; $uid=get_current_user_id(); $msg=sanitize_textarea_field(wp_unslash($_POST['message']??'')); if($msg==='') wp_send_json_error();
        $tname=$wpdb->prefix.'bimarstop_chat_threads';
        $thread=$wpdb->get_row($wpdb->prepare("SELECT * FROM $tname WHERE patient_id=%d AND status='open' ORDER BY id DESC LIMIT 1",$uid));
        if(!$thread && $this->current_role()==='bimarstop_patient'){
            $now=current_time('mysql'); $wpdb->insert($tname,['patient_id'=>$uid,'operator_id'=>0,'status'=>'open','created_at'=>$now,'updated_at'=>$now],['%d','%d','%s','%s','%s']); $thread=(object)['id'=>$wpdb->insert_id,'patient_id'=>$uid,'operator_id'=>0,'status'=>'open'];
        }
        if(!$thread || !$this->chat_user_can_access((int)$thread->id,$uid)) wp_send_json_error();
        if($this->current_role()==='bimarstop_operator' && (int)$thread->operator_id===0){$wpdb->update($tname,['operator_id'=>$uid,'updated_at'=>current_time('mysql')],['id'=>$thread->id],['%d','%s'],['%d']);}
        $wpdb->insert($wpdb->prefix.'bimarstop_chat_messages',['thread_id'=>$thread->id,'sender_id'=>$uid,'message'=>$msg,'created_at'=>current_time('mysql')],['%d','%d','%s','%s']);
        $wpdb->update($tname,['updated_at'=>current_time('mysql')],['id'=>$thread->id],['%s'],['%d']);
        wp_send_json_success();
    }

    public function ajax_get_messages(): void {
        check_ajax_referer('bimarstop_chat','nonce');
        if (!is_user_logged_in()) wp_send_json_error();
        global $wpdb; $uid=get_current_user_id(); $last=absint($_POST['last_id']??0); $tn=$wpdb->prefix.'bimarstop_chat_threads'; $thread=null;
        if($this->current_role()==='bimarstop_patient') $thread=$wpdb->get_row($wpdb->prepare("SELECT * FROM $tn WHERE patient_id=%d AND status='open' ORDER BY id DESC LIMIT 1",$uid));
        else $thread=$wpdb->get_row($wpdb->prepare("SELECT * FROM $tn WHERE operator_id=%d AND status='open' ORDER BY updated_at DESC LIMIT 1",$uid));
        if(!$thread) wp_send_json_success(['messages'=>[]]);
        $rows=$wpdb->get_results($wpdb->prepare("SELECT m.id,m.message,u.display_name FROM {$wpdb->prefix}bimarstop_chat_messages m JOIN {$wpdb->users} u ON u.ID=m.sender_id WHERE m.thread_id=%d AND m.id>%d ORDER BY m.id ASC",$thread->id,$last));
        $out=[]; foreach($rows as $r)$out[]=['id'=>(int)$r->id,'message'=>esc_html($r->message),'sender'=>esc_html($r->display_name)];
        wp_send_json_success(['messages'=>$out]);
    }

    public function ajax_set_operator_status(): void {
        check_ajax_referer('bimarstop_operator','nonce');
        if($this->current_role()!=='bimarstop_operator') wp_send_json_error();
        update_user_meta(get_current_user_id(),'bimarstop_operator_online',!empty($_POST['online'])?'1':'0');
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
