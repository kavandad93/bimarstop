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
        add_shortcode('bimarstop_register', [$this, 'auth_shortcode']);
        add_shortcode('bimarstop_login', [$this, 'auth_shortcode']);
        add_shortcode('bimarstop_auth', [$this, 'auth_shortcode']);
        add_action('wp_ajax_nopriv_bimarstop_send_otp', [$this, 'ajax_send_otp']);
        add_action('wp_ajax_nopriv_bimarstop_verify_otp', [$this, 'ajax_verify_otp']);
        add_action('wp_ajax_nopriv_bimarstop_send_auth_otp', [$this, 'ajax_send_auth_otp']);
        add_action('wp_ajax_nopriv_bimarstop_verify_auth_otp', [$this, 'ajax_verify_auth_otp']);
        add_action('login_init', [$this, 'redirect_wp_login']);
        add_filter('login_url', [$this, 'bimarstop_login_url'], 10, 3);
        add_shortcode('bimarstop_complete_profile', [$this, 'complete_profile_shortcode']);
        add_action('admin_init', [$this, 'enforce_profile_completion']);
        add_action('user_new_form', [$this, 'admin_new_user_mobile_field']);
        add_action('user_profile_update_errors', [$this, 'validate_admin_mobile_field'], 10, 3);
        add_action('personal_options_update', [$this, 'save_admin_mobile_field']);
        add_action('edit_user_profile_update', [$this, 'save_admin_mobile_field']);
        add_action('user_register', [$this, 'save_admin_mobile_field']);
        add_filter('option_users_can_register', '__return_false');
        add_action('wp_ajax_bimarstop_send_message', [$this, 'ajax_send_message']);
        add_action('wp_ajax_bimarstop_get_messages', [$this, 'ajax_get_messages']);
        add_action('wp_ajax_bimarstop_set_operator_status', [$this, 'ajax_set_operator_status']);
        add_action('wp_ajax_bimarstop_private_send_message', [$this, 'ajax_private_send_message']);
        add_action('wp_ajax_bimarstop_private_get_messages', [$this, 'ajax_private_get_messages']);
        add_action('wp_ajax_bimarstop_download_file', [$this, 'ajax_download_file']);
        add_action('wp_ajax_bimarstop_get_documents', [$this, 'ajax_get_documents']);
        add_action('wp_ajax_bimarstop_send_document', [$this, 'ajax_send_document']);
        add_action('wp_ajax_bimarstop_move_document', [$this, 'ajax_move_document']);
        add_action('wp_ajax_bimarstop_rename_document', [$this, 'ajax_rename_document']);
        add_action('wp_ajax_bimarstop_delete_document', [$this, 'ajax_delete_document']);
        add_action('wp_ajax_bimarstop_toggle_document_hidden', [$this, 'ajax_toggle_document_hidden']);
        add_action('wp_ajax_bimarstop_add_document_folder', [$this, 'ajax_add_document_folder']);
        add_action('wp_ajax_bimarstop_move_document_folder', [$this, 'ajax_move_document_folder']);
        add_action('wp_ajax_bimarstop_delete_document_folder', [$this, 'ajax_delete_document_folder']);
        add_action('wp_ajax_bimarstop_share_document', [$this, 'ajax_share_document']);
        add_action('template_redirect', [$this, 'require_login']);
        add_filter('show_admin_bar', [$this, 'show_admin_bar']);
        add_filter('pre_user_role', [$this, 'force_patient_registration_role'], 10, 2);
        add_action('admin_menu', [$this, 'restrict_role_admin_menu'], 999);
        add_action('admin_init', [$this, 'redirect_patient_dashboard']);
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

    public function register_roles(): void {
        self::register_bimarstop_roles();
        self::create_chat_tables();
        self::create_private_chat_tables();
        self::create_report_and_document_tables();
        $this->ensure_registration_page();
        $this->ensure_login_page();
        $this->ensure_profile_page();
    }

    private function ensure_registration_page(): void {
        $page = get_page_by_path('bimarstop-register', OBJECT, 'page');
        if ($page) return;
        wp_insert_post([
            'post_title' => 'ثبت‌نام BimarStop',
            'post_name' => 'bimarstop-register',
            'post_content' => '[bimarstop_register]',
            'post_status' => 'publish',
            'post_type' => 'page',
        ]);
    }

    private function ensure_login_page(): void {
        $page=get_page_by_path('bimarstop-login',OBJECT,'page');
        if(!$page){ wp_insert_post(['post_title'=>'ورود به BimarStop','post_name'=>'bimarstop-login','post_content'=>'[bimarstop_login]','post_status'=>'publish','post_type'=>'page']); flush_rewrite_rules(false); }
    }

    private function ensure_profile_page(): void {
        $page=get_page_by_path('bimarstop-complete-profile',OBJECT,'page');
        if(!$page){
            wp_insert_post([
                'post_title'=>'تکمیل اطلاعات BimarStop',
                'post_name'=>'bimarstop-complete-profile',
                'post_content'=>'[bimarstop_complete_profile]',
                'post_status'=>'publish',
                'post_type'=>'page'
            ]);
            flush_rewrite_rules(false);
        }
    }

    private static function register_bimarstop_roles(): void {
        $patient = get_role('bimarstop_patient');
        if (!$patient) add_role('bimarstop_patient', 'بیمار', ['read' => true]);
        $doctor = get_role('bimarstop_doctor');
        if (!$doctor) add_role('bimarstop_doctor', 'پزشک', ['read' => true]);
        $operator = get_role('bimarstop_operator');
        if (!$operator) add_role('bimarstop_operator', 'اپراتور', ['read' => true]);
        $admin = get_role('administrator');
        if ($admin) {
            // ادمین اصلی همان نقش استاندارد Administrator وردپرس است.
        }
    }

    private function profile_is_complete(int $user_id): bool {
        $national_code=(string)get_user_meta($user_id,'bimarstop_national_code',true);
        $first_name=(string)get_user_meta($user_id,'bimarstop_first_name',true);
        $last_name=(string)get_user_meta($user_id,'bimarstop_last_name',true);
        $birth_date=(string)get_user_meta($user_id,'bimarstop_birth_date_shamsi',true);
        return preg_match('/^\\d{10}$/',$national_code) && $first_name!=='' && $last_name!=='' && preg_match('/^\\d{4}\\/\\d{2}\\/\\d{2}$/',$birth_date);
    }

    private function normalize_profile_digits(string $value): string {
        return strtr($value,[
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9'
        ]);
    }

    private function valid_national_code(string $code): bool {
        $code=$this->normalize_profile_digits($code);
        if(!preg_match('/^\\d{10}$/',$code)) return false;
        if(preg_match('/^(\\d)\\1{9}$/',$code)) return false;
        $sum=0;
        for($i=0;$i<9;$i++) $sum+=(int)$code[$i]*(10-$i);
        $remainder=$sum%11;
        $check=(int)$code[9];
        return $remainder<2 ? $check===$remainder : $check===(11-$remainder);
    }

    public function enforce_profile_completion(): void {
        if(!is_user_logged_in()) return;
        if(!in_array('bimarstop_patient', (array)wp_get_current_user()->roles, true)) return;
        if(wp_doing_ajax()) return;
        if(isset($_GET['action']) && $_GET['action']==='logout') return;
        if($this->profile_is_complete(get_current_user_id())) return;
        if(!empty($GLOBALS['pagenow']) && $GLOBALS['pagenow']==='profile.php') return;
        wp_safe_redirect(home_url('/bimarstop-complete-profile/'));
        exit;
    }

    public function complete_profile_shortcode(): string {
        if(!is_user_logged_in()){
            wp_safe_redirect(home_url('/bimarstop-login/'));
            exit;
        }

        $user_id=get_current_user_id();
        if($this->profile_is_complete($user_id)){
            wp_safe_redirect(admin_url());
            exit;
        }

        $message='';
        $error='';
        if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['bimarstop_profile_submit'])){
            if(!isset($_POST['bimarstop_profile_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bimarstop_profile_nonce'])),'bimarstop_complete_profile')){
                $error='درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
            } else {
                $national_code=$this->normalize_profile_digits(preg_replace('/\\D+/','',(string)wp_unslash($_POST['national_code']??'')));
                $first_name=sanitize_text_field(wp_unslash($_POST['first_name']??''));
                $last_name=sanitize_text_field(wp_unslash($_POST['last_name']??''));
                $birth_year=(int)wp_unslash($_POST['birth_year']??0);
                $birth_month=(int)wp_unslash($_POST['birth_month']??0);
                $birth_day=(int)wp_unslash($_POST['birth_day']??0);
                $birth_date=sprintf('%04d/%02d/%02d',$birth_year,$birth_month,$birth_day);

                if(!$this->valid_national_code($national_code)) $error='کد ملی معتبر نیست.';
                elseif($first_name==='' || $last_name==='') $error='نام و نام خانوادگی را کامل وارد کنید.';
                elseif($birth_year<1300 || $birth_year>1500 || $birth_month<1 || $birth_month>12 || $birth_day<1 || $birth_day>31) $error='تاریخ تولد شمسی معتبر نیست.';
                else {
                    update_user_meta($user_id,'bimarstop_national_code',$national_code);
                        update_user_meta($user_id,'bimarstop_first_name',$first_name);
                        update_user_meta($user_id,'bimarstop_last_name',$last_name);
                        update_user_meta($user_id,'bimarstop_birth_date_shamsi',$birth_date);
                        wp_update_user(['ID'=>$user_id,'first_name'=>$first_name,'last_name'=>$last_name,'display_name'=>trim($first_name.' '.$last_name)]);
                        wp_safe_redirect(admin_url());
                        exit;
                    }
                }
            }

        ob_start(); ?>
        <div class="bimarstop-auth-card" dir="rtl">
            <div class="bimarstop-auth-brand">
                <div class="bimarstop-auth-logo">🏥</div>
                <div>
                    <h1 class="bimarstop-auth-title">تکمیل اطلاعات</h1>
                    <p class="bimarstop-auth-subtitle">برای ادامه ورود به BimarStop، اطلاعات زیر را کامل کنید.</p>
                </div>
            </div>
            <?php if($error): ?><div class="bimarstop-auth-status" style="display:block"><?php echo esc_html($error); ?></div><?php endif; ?>
            <form method="post">
                <?php wp_nonce_field('bimarstop_complete_profile','bimarstop_profile_nonce'); ?>
                <label class="bimarstop-auth-label">کد ملی</label>
                <input class="bimarstop-auth-field" name="national_code" type="tel" inputmode="numeric" maxlength="10" required value="<?php echo esc_attr($_POST['national_code']??''); ?>" placeholder="۰۰۱۲۳۴۵۶۷۸" dir="ltr" oninput="this.value=this.value.replace(/[۰-۹٠-٩]/g,function(d){return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)>-1?'۰۱۲۳۴۵۶۷۸۹'.indexOf(d):'۰۱۲۳۴۵۶۷۸۹'.indexOf(d);})">
                <label class="bimarstop-auth-label">نام</label>
                <input class="bimarstop-auth-field" name="first_name" type="text" required value="<?php echo esc_attr($_POST['first_name']??''); ?>" placeholder="نام">
                <label class="bimarstop-auth-label">نام خانوادگی</label>
                <input class="bimarstop-auth-field" name="last_name" type="text" required value="<?php echo esc_attr($_POST['last_name']??''); ?>" placeholder="نام خانوادگی">
                <label class="bimarstop-auth-label">تاریخ تولد (شمسی)</label>
                <div class="bimarstop-birthdate-picker" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px">
                    <select class="bimarstop-auth-field" name="birth_year" required aria-label="سال تولد"><option value="">سال</option><?php for($y=1300;$y<=1500;$y++): ?><option value="<?php echo $y; ?>"><?php echo $this->normalize_profile_digits((string)$y); ?></option><?php endfor; ?></select>
                    <select class="bimarstop-auth-field" name="birth_month" required aria-label="ماه تولد"><option value="">ماه</option><?php for($m=1;$m<=12;$m++): ?><option value="<?php echo $m; ?>"><?php echo $this->normalize_profile_digits(sprintf('%02d',$m)); ?></option><?php endfor; ?></select>
                    <select class="bimarstop-auth-field" name="birth_day" required aria-label="روز تولد"><option value="">روز</option><?php for($d=1;$d<=31;$d++): ?><option value="<?php echo $d; ?>"><?php echo $this->normalize_profile_digits(sprintf('%02d',$d)); ?></option><?php endfor; ?></select>
                </div>
                <button class="bimarstop-auth-btn" type="submit" name="bimarstop_profile_submit">ذخیره و ورود به پنل</button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    public function require_login(): void {
        if ((is_front_page() || is_home()) && !is_admin()) { wp_redirect(admin_url(), 301); exit; }
        if (is_user_logged_in()) {
            if(!$this->profile_is_complete(get_current_user_id()) && !is_page('bimarstop-complete-profile')){
                wp_safe_redirect(home_url('/bimarstop-complete-profile/')); exit;
            }
            return;
        }
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) return;
        if (is_page('bimarstop-register') || is_page('bimarstop-login')) return;
        wp_safe_redirect(home_url('/bimarstop-login/')); exit;
    }

    public function redirect_wp_login(): void {
        if(isset($_GET['action']) && $_GET['action']==='logout') return;
        if(is_user_logged_in()){ wp_safe_redirect(admin_url()); exit; }

        // wp-login.php remains available for administrators and other staff.
        // The BimarStop OTP page is still the primary login flow for patients.
        return;
    }

    public function bimarstop_login_url($login_url,$redirect='',$force_reauth=false): string { return home_url('/bimarstop-login/'); }

    public function show_admin_bar($show): bool {
        return is_user_logged_in() ? (bool) $show : false;
    }

    public function force_patient_registration_role($role, $userdata) {
        return is_admin() ? $role : 'bimarstop_patient';
    }

    public function redirect_patient_dashboard(): void {
        if (!is_user_logged_in() || $this->current_role() !== 'bimarstop_patient') return;
        global $pagenow;
        if ($pagenow === 'index.php') {
            wp_safe_redirect(admin_url('admin.php?page=bimarstop-chat'));
            exit;
        }
    }

    public function restrict_role_admin_menu(): void {
        if (!is_user_logged_in() || current_user_can('manage_options')) return;
        $role = $this->current_role();
        if ($role === 'bimarstop_patient' || $role === 'bimarstop_doctor' || $role === 'bimarstop_operator') {
            if ($role === 'bimarstop_patient') {
                remove_menu_page('index.php');
            }
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
        $shares = $wpdb->prefix . 'bimarstop_document_shares';
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
            parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY name (name),
            KEY parent_id (parent_id)
        ) $charset;");
        dbDelta("CREATE TABLE $docs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            category_id bigint(20) unsigned NOT NULL DEFAULT 0,
            path text NOT NULL,
            name varchar(255) NOT NULL,
            size bigint(20) unsigned NOT NULL DEFAULT 0,
            type varchar(100) NOT NULL DEFAULT 'application/octet-stream',
            hidden tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY path_hash (path(191)),
            KEY category_id (category_id)
        ) $charset;");
        dbDelta("CREATE TABLE $shares (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            document_id bigint(20) unsigned NOT NULL,
            sender_id bigint(20) unsigned NOT NULL,
            recipient_id bigint(20) unsigned NOT NULL,
            note text NULL,
            status varchar(20) NOT NULL DEFAULT 'sent',
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY document_id (document_id),
            KEY recipient_id (recipient_id),
            KEY sender_id (sender_id)
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
        $cat_table=$wpdb->prefix.'bimarstop_document_categories';
        $default_category=(int)$wpdb->get_var("SELECT id FROM $cat_table ORDER BY id ASC LIMIT 1");
        if(!$default_category){
            $wpdb->insert($cat_table,['name'=>'عمومی','parent_id'=>0,'created_at'=>current_time('mysql')],['%s','%d','%s']);
            $default_category=(int)$wpdb->insert_id;
        }
        try { $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($uploads['basedir'], \FilesystemIterator::SKIP_DOTS)); } catch(\Throwable $e){ return; }
        $base=realpath($uploads['basedir']);
        foreach($it as $file){
            if(!$file->isFile()) continue;
            $path=$file->getPathname();
            $ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));
            if(!isset($allowed[$ext])) continue;
            $real=realpath($path);
            if(!$real||!$base||strpos($real,$base)!==0) continue;
            $exists=$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE path=%s",$real));
            if(!$exists) {
                $wpdb->insert($table,['category_id'=>$default_category,'path'=>$real,'name'=>basename($real),'size'=>(int)$file->getSize(),'type'=>$allowed[$ext],'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')],['%d','%s','%s','%d','%s','%s','%s']);
            } elseif((int)$wpdb->get_var($wpdb->prepare("SELECT category_id FROM $table WHERE id=%d",$exists))===0) {
                $wpdb->update($table,['category_id'=>$default_category,'updated_at'=>current_time('mysql')],['id'=>(int)$exists],['%d','%s'],['%d']);
            }
        }
    }

    private function can_use_documents(): bool {
        return $this->current_role() === 'bimarstop_operator';
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
                $api_key = sanitize_text_field($input['sms_api_key'] ?? '');
                $line_number = sanitize_text_field($input['sms_line_number'] ?? '');
                return [
                    'theme' => array_key_exists($theme, $this->themes()) ? $theme : 'light-1',
                    'sms_api_key' => $api_key,
                    'sms_line_number' => $line_number,
                ];
            },
        ]);
    }

    public function enqueue_assets(): void {
        $settings = wp_parse_args(get_option('bimarstop_settings', []), ['theme' => 'light-1', 'sms_api_key' => '', 'sms_line_number' => '']);
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

        // بیمار فقط چت با اپراتور و گزارش مشکل را می‌بیند.
        if ($role === 'bimarstop_patient') {
            add_menu_page('بیمار استاپ', 'بیمار استاپ', 'read', 'bimarstop-chat', [$this, 'patient_chat_page'], 'dashicons-format-chat', 25);
            add_submenu_page('bimarstop-chat', 'چت با اپراتور', 'چت با اپراتور', 'read', 'bimarstop-chat', [$this, 'patient_chat_page']);
            add_submenu_page('bimarstop-chat', 'گزارش مشکل', 'گزارش مشکل', 'read', 'bimarstop-report-issue', [$this, 'report_issue_page']);
            return;
        }

        if ($role === 'bimarstop_doctor') {
            add_menu_page('BimarStop', 'BimarStop', 'read', 'bimarstop', [$this, 'doctor_dashboard'], 'dashicons-heart', 25);
            add_submenu_page('bimarstop', 'داشبورد', 'داشبورد', 'read', 'bimarstop', [$this, 'doctor_dashboard']);
            add_submenu_page('bimarstop', 'گزارش مشکل', 'گزارش مشکل', 'read', 'bimarstop-report-issue', [$this, 'report_issue_page']);
            add_submenu_page('bimarstop', 'چت با اپراتورها', 'چت با اپراتورها', 'read', 'bimarstop-doctor-chats', [$this, 'doctor_private_chats_page']);
            return;
        }

        if ($role === 'bimarstop_operator') {
            add_menu_page('BimarStop', 'BimarStop', 'read', 'bimarstop', [$this, 'operator_dashboard'], 'dashicons-heart', 25);
            add_submenu_page('bimarstop', 'داشبورد', 'داشبورد', 'read', 'bimarstop', [$this, 'operator_dashboard']);
            add_submenu_page('bimarstop', 'صف ورودی', 'صف ورودی', 'read', 'bimarstop-queue', [$this, 'operator_chat_queue']);
            add_submenu_page('bimarstop', 'بیماران', 'بیماران', 'read', 'bimarstop-patients', [$this, 'patients_page']);
            add_submenu_page('bimarstop', 'پزشکان', 'پزشکان', 'read', 'bimarstop-doctors', [$this, 'doctors_page']);
            add_submenu_page('bimarstop', 'چت با بیمار', 'چت با بیمار', 'read', 'bimarstop-chat', [$this, 'operator_chat_page']);
            add_submenu_page('bimarstop', 'مدارک', 'مدارک', 'read', 'bimarstop-documents', [$this, 'documents_page']);
            add_submenu_page('bimarstop', 'گزارش مشکل', 'گزارش مشکل', 'read', 'bimarstop-report-issue', [$this, 'report_issue_page']);
            add_submenu_page('bimarstop', 'چت با پزشکان', 'چت با پزشکان', 'read', 'bimarstop-doctor-chats', [$this, 'operator_private_chats_page']);
            return;
        }

        if (!current_user_can('manage_options')) return;

        add_menu_page('BimarStop', 'BimarStop', 'manage_options', 'bimarstop', [$this, 'admin_dashboard'], 'dashicons-heart', 25);
        add_submenu_page('bimarstop', 'داشبورد', 'داشبورد', 'manage_options', 'bimarstop', [$this, 'admin_dashboard']);
        add_submenu_page('bimarstop', 'بیماران', 'بیماران', 'manage_options', 'bimarstop-patients', [$this, 'patients_page']);
        add_submenu_page('bimarstop', 'پزشکان', 'پزشکان', 'manage_options', 'bimarstop-doctors', [$this, 'doctors_page']);
        add_submenu_page('bimarstop', 'اپراتورها', 'اپراتورها', 'manage_options', 'bimarstop-operators', [$this, 'operators_page']);
        add_submenu_page('bimarstop', 'چت اپراتورها', 'چت اپراتورها', 'manage_options', 'bimarstop-chat', [$this, 'operator_chat_queue']);
        add_submenu_page('bimarstop', 'چت داخلی', 'چت داخلی', 'manage_options', 'bimarstop-private-chats', [$this, 'admin_private_chats_page']);
        add_submenu_page('bimarstop', 'مدارک', 'مدارک', 'manage_options', 'bimarstop-documents', [$this, 'documents_page']);
        add_submenu_page('bimarstop', 'گزارش مشکل', 'گزارش مشکل', 'manage_options', 'bimarstop-report-issue', [$this, 'report_issue_page']);
        add_submenu_page('bimarstop', 'تنظیمات', 'تنظیمات', 'manage_options', 'bimarstop-settings', [$this, 'settings_page']);
    }

    private function users_by_role(string $role): array {
        return get_users(['role' => $role, 'orderby' => 'registered', 'order' => 'DESC']);
    }

    public function admin_dashboard(): void {
        echo '<div class="wrap" dir="rtl"><h1>🏥 BimarStop</h1><p>مدیریت مرکزی سامانه.</p></div>';
    }

    public function patient_dashboard(): void {
        echo '<div class="wrap" dir="rtl"><h1>🏥 پنل بیمار</h1><p>از منوی BimarStop می‌توانید با اپراتور گفتگو کنید.</p></div>';
    }

    public function doctor_dashboard(): void {
        echo '<div class="wrap" dir="rtl"><h1>🩺 پنل پزشک</h1><p>پرونده‌ها و اتاق‌های اختصاص داده‌شده از اینجا مدیریت می‌شوند.</p></div>';
    }

    public function operator_dashboard(): void {
        $online = (bool) get_user_meta(get_current_user_id(), 'bimarstop_operator_online', true);
        echo '<div class="wrap" dir="rtl"><h1>👨‍💻 پنل اپراتور</h1>';
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
        $is_admin = current_user_can('manage_options');
        $is_operator = $this->current_role() === 'bimarstop_operator';

        // فقط ادمین می‌تواند اپراتور بسازد؛ ادمین و اپراتور می‌توانند بیمار/پزشک بسازند.
        $allowed = in_array($role, ['bimarstop_patient', 'bimarstop_doctor'], true)
            ? ($is_admin || $is_operator)
            : ($role === 'bimarstop_operator' && $is_admin);
        if (!$allowed) return;

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bimarstop_create_user'])) {
            if (!check_admin_referer('bimarstop_create_user')) {
                echo '<div class="notice notice-error"><p>درخواست نامعتبر است.</p></div>';
            } else {
                $mobile = $this->normalize_mobile(sanitize_text_field(wp_unslash($_POST['user_mobile'] ?? '')));
                $name = sanitize_text_field(wp_unslash($_POST['display_name'] ?? ''));

                if (!$this->valid_mobile($mobile)) {
                    echo '<div class="notice notice-error"><p>شماره موبایل معتبر نیست. نمونه: 09123456789</p></div>';
                } elseif (get_users(['meta_key'=>'bimarstop_mobile','meta_value'=>$mobile,'number'=>1,'fields'=>'ID'])) {
                    echo '<div class="notice notice-error"><p>این شماره موبایل قبلاً برای یک حساب استفاده شده است.</p></div>';
                } else {
                    $login = 'mobile_' . substr($mobile, 1);
                    $base_login = $login;
                    $suffix = 1;
                    while (username_exists($login)) {
                        $login = $base_login . '_' . $suffix++;
                    }

                    $email = $mobile . '@bimarstop.local';
                    while (email_exists($email)) {
                        $email = $mobile . '_' . $suffix++ . '@bimarstop.local';
                    }

                    $id = wp_create_user($login, wp_generate_password(32, true, true), $email);
                    if (is_wp_error($id)) {
                        echo '<div class="notice notice-error"><p>ساخت حساب ناموفق بود: ' . esc_html($id->get_error_message()) . '</p></div>';
                    } else {
                        $display_name = $name !== '' ? $name : $mobile;
                        wp_update_user([
                            'ID' => $id,
                            'display_name' => $display_name,
                            'role' => $role,
                        ]);
                        update_user_meta($id, 'bimarstop_mobile', $mobile);

                        echo '<div class="notice notice-success"><p>حساب ' . esc_html($role === 'bimarstop_doctor' ? 'پزشک' : 'بیمار') . ' با موفقیت ساخته شد. ورود با شماره موبایل و کد پیامکی انجام می‌شود.</p></div>';
                    }
                }
            }
        }

        $users = $this->users_by_role($role);
        echo '<div class="wrap" dir="rtl"><h1>' . esc_html($title) . '</h1>';
        echo '<div class="card" style="max-width:700px;padding:20px;margin-top:20px">';
        echo '<h2>➕ ساخت ' . esc_html($role === 'bimarstop_doctor' ? 'پزشک' : ($role === 'bimarstop_patient' ? 'بیمار' : 'اپراتور')) . '</h2>';
        echo '<p>برای این حساب فقط شماره موبایل لازم است؛ رمز عبور و ایمیل وارد نمی‌شود.</p>';
        echo '<form method="post">';
        wp_nonce_field('bimarstop_create_user');
        echo '<input type="hidden" name="bimarstop_create_user" value="1">';
        echo '<p><label>شماره موبایل<br><input required name="user_mobile" type="tel" inputmode="tel" class="regular-text" maxlength="13" placeholder="09123456789"></label></p>';
        echo '<p><label>نام و نام خانوادگی<br><input name="display_name" type="text" class="regular-text" placeholder="مثلاً علی رضایی"></label></p>';
        submit_button('ساخت حساب');
        echo '</form></div>';

        echo '<h2 style="margin-top:28px">فهرست</h2>';
        echo '<table class="widefat striped"><thead><tr><th>شماره موبایل</th><th>نام</th><th>نام کاربری داخلی</th><th>تاریخ عضویت</th></tr></thead><tbody>';
        foreach ($users as $u) {
            $mobile = (string) get_user_meta($u->ID, 'bimarstop_mobile', true);
            echo '<tr><td>' . esc_html($mobile ?: '—') . '</td><td>' . esc_html($u->display_name) . '</td><td>' . esc_html($u->user_login) . '</td><td>' . esc_html($u->user_registered) . '</td></tr>';
        }
        if (!$users) echo '<tr><td colspan="4">هنوز کاربری وجود ندارد.</td></tr>';
        echo '</tbody></table></div>';
    }

    public function patients_page(): void {
        if (!current_user_can('manage_options')) { if ($this->current_role() === 'bimarstop_operator') { $this->users_table('bimarstop_patient','🧑‍⚕️ بیماران'); } return; }
        $this->users_table('bimarstop_patient','🧑‍⚕️ بیماران');
    }
    public function doctors_page(): void { $this->users_table('bimarstop_doctor','🩺 پزشکان'); }
    public function operators_page(): void { $this->users_table('bimarstop_operator','👨‍💻 اپراتورها'); }

    public function patient_chat_page(): void {
        if (!current_user_can('read')) return;
        $online = get_users(['role'=>'bimarstop_operator','meta_key'=>'bimarstop_operator_online','meta_value'=>'1','number'=>1]);
        $notice = $online ? '' : '<div class="bimar-chat-offline">فعلاً همه اپراتورها آفلاین هستند؛ پیام شما ثبت می‌شود و پس از آنلاین شدن پاسخ داده می‌شود.</div>';
        echo '<div class="bimar-chat-page" dir="rtl">';
        echo '<div class="bimar-chat-header"><div><span class="bimar-chat-kicker">BimarStop • پشتیبانی</span><h1>💬 گفت‌وگو با اپراتور</h1><p>پرسش، توضیح مشکل یا ارسال مدارک را همین‌جا انجام دهید.</p></div><div class="bimar-chat-live">'.($online ? '<i></i> اپراتور آنلاین' : '<i class="off"></i> آفلاین').'</div></div>';
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
        echo '<div class="bimar-chat-header"><div><span class="bimar-chat-kicker">BimarStop • پشتیبانی</span><h1>💬 گفت‌وگو با بیمار</h1><p>'.($thread ? 'در حال پاسخ‌گویی به '.esc_html($thread->display_name) : 'یک گفتگو را از صف چت انتخاب کنید.').'</p></div><a class="bimar-chat-back" href="'.esc_url(admin_url('admin.php?page=bimarstop-queue')).'">← صف گفتگوها</a></div>';
        if (!$thread) { echo '<div class="bimar-chat-empty">یک گفتگو را از «صف ورودی» انتخاب کنید.</div></div>'; return; }
        echo '<div class="bimar-chat-shell"><aside class="bimar-chat-side"><div class="bimar-chat-side-title">مکالمه فعال</div><div class="bimar-chat-tab active">🧑 '.esc_html($thread->display_name).'</div><div class="bimar-chat-side-info">📎 ارسال فایل فعال است<br><span>فایل‌های PDF، JPG، PNG و DOCX تا ۱۰ مگابایت.</span></div></aside>';
        echo '<main class="bimar-chat-main"><div id="bimarstop-chat-box" class="bimar-chat-box"></div><div id="bimar-chat-attachment" class="bimar-chat-attachment" hidden><span>📎 <b id="bimar-file-name"></b></span><button type="button" id="bimar-file-remove">×</button></div><div class="bimar-chat-composer"><label class="bimar-attach"><input id="bimarstop-chat-file" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx" hidden>📎</label><textarea id="bimarstop-chat-input" rows="1" placeholder="پاسخ خود را بنویسید..."></textarea><button id="bimarstop-send" class="bimar-send">➤</button><div class="bimar-chat-hint">Enter برای ارسال • Shift+Enter برای خط جدید</div></div></main></div></div>';
        $this->chat_script();
    }

    public function operator_chat_queue(): void {
        if (!current_user_can('read')) return;
        global $wpdb;
        $threads=$wpdb->get_results("SELECT t.*, u.display_name FROM {$wpdb->prefix}bimarstop_chat_threads t LEFT JOIN {$wpdb->users} u ON u.ID=t.patient_id WHERE t.status='open' ORDER BY t.updated_at DESC");
        echo '<div class="wrap" dir="rtl"><h1>💬 صف چت</h1><table class="widefat striped"><thead><tr><th>بیمار</th><th>وضعیت</th><th>آخرین بروزرسانی</th><th>عملیات</th></tr></thead><tbody>';
        foreach($threads as $t) echo '<tr><td>'.esc_html($t->display_name).'</td><td>'.($t->operator_id?'در حال پاسخ':'منتظر اپراتور').'</td><td>'.esc_html($t->updated_at).'</td><td><a class="button" href="'.esc_url(admin_url('admin.php?page=bimarstop-chat&thread='.(int)$t->id)).'">باز کردن چت</a></td></tr>';
        if(!$threads) echo '<tr><td colspan="3">چتی در صف نیست.</td></tr>';
        echo '</tbody></table></div>';
    }

    private function chat_script(): void {
        $nonce=wp_create_nonce('bimarstop_chat');
        $ajax=admin_url('admin-ajax.php');
        echo '<script>
        (function(){
            if(window.__bimarstopChatInitialized)return;
            window.__bimarstopChatInitialized=true;
            var box=document.getElementById("bimarstop-chat-box"), input=document.getElementById("bimarstop-chat-input"),
                send=document.getElementById("bimarstop-send"), file=document.getElementById("bimarstop-chat-file"),
                attachment=document.getElementById("bimar-chat-attachment"), fileName=document.getElementById("bimar-file-name"),
                docSelect=document.getElementById("bimarstop-chat-document"),
                remove=document.getElementById("bimar-file-remove"), last=0, loading=false, seen={}, sent={};
            if(!box||!input||!send)return;
            function esc(t){var d=document.createElement("div");d.textContent=t;return d.innerHTML;}
            function render(m){
                var id=String(m.id);
                if(seen[id] || document.querySelector("[data-message-id=\""+id+"\"]"))return;
                seen[id]=true;
                var row=document.createElement("div"); row.className="bimar-msg "+(m.mine?"mine":"theirs"); row.setAttribute("data-message-id",id);
                var bubble=document.createElement("div"); bubble.className="bimar-msg-bubble";
                var sender=document.createElement("div"); sender.className="bimar-msg-sender"; sender.textContent=m.sender;
                bubble.appendChild(sender);
                if(m.message){var body=document.createElement("div");body.className="bimar-msg-text";body.textContent=m.message;bubble.appendChild(body);}
                if(m.attachment){var a=document.createElement("a");a.className="bimar-file-card";a.href=m.attachment.url;a.target="_blank";a.rel="noopener";a.innerHTML="<span class=\"bimar-file-icon\">📎</span><span><b>"+esc(m.attachment.name)+"</b><small>"+esc(m.attachment.size)+"</small></span><strong>دانلود</strong>";bubble.appendChild(a);}
                var time=document.createElement("div");time.className="bimar-msg-time";time.textContent=m.time||"";bubble.appendChild(time);
                row.appendChild(bubble);box.appendChild(row);
            }
            function load(){
                if(loading)return;
                loading=true;
                var f=new FormData();f.append("action","bimarstop_get_messages");f.append("nonce","'.esc_js($nonce).'");f.append("last_id",last);
                fetch("'.esc_url($ajax).'",{method:"POST",body:f}).then(r=>r.json()).then(x=>{
                    if(!x.success)return;
                    x.data.messages.forEach(function(m){render(m);last=Math.max(last,parseInt(m.id)||0);});
                    if(x.data.messages.length)box.scrollTop=box.scrollHeight;
                }).catch(function(){}).finally(function(){loading=false;});
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
        if($this->current_role()==='bimarstop_patient') {
            $thread=$wpdb->get_row($wpdb->prepare("SELECT * FROM $tname WHERE patient_id=%d AND status='open' ORDER BY id DESC LIMIT 1",$uid));
        } else {
            $thread=$wpdb->get_row($wpdb->prepare("SELECT * FROM $tname WHERE operator_id=%d AND status='open' ORDER BY updated_at DESC LIMIT 1",$uid));
            // اگر اپراتور هنوز چتی را به خودش اختصاص نداده، اولین گفت‌وگوی منتظر را بردار.
            if(!$thread && $this->current_role()==='bimarstop_operator'){
                $thread=$wpdb->get_row("SELECT * FROM $tname WHERE operator_id=0 AND status='open' ORDER BY updated_at ASC, id ASC LIMIT 1");
                if($thread){
                    $wpdb->update($tname,['operator_id'=>$uid,'updated_at'=>current_time('mysql')],['id'=>$thread->id],['%d','%s'],['%d']);
                    $thread->operator_id=$uid;
                }
            }
        }
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
        // فقط ارسال دوباره دقیقاً همان پیامِ قبلی ممنوع است.
        // تکرار پیام بعد از یک پیام متفاوت کاملاً مجاز است:
        // سلام → خوبی → سلام → خوبی
        $previous=$wpdb->get_row($wpdb->prepare(
            "SELECT message,attachment_path FROM {$wpdb->prefix}bimarstop_chat_messages WHERE thread_id=%d AND sender_id=%d ORDER BY id DESC LIMIT 1",
            $thread->id,$uid
        ));
        if($previous && (string)$previous->message===$msg && (string)$previous->attachment_path===$path){
            wp_send_json_error(['message'=>'پیام تکراری پشت‌سرهم مجاز نیست.']);
        }
        $wpdb->insert($wpdb->prefix.'bimarstop_chat_messages',['thread_id'=>$thread->id,'sender_id'=>$uid,'message'=>$msg,'attachment_path'=>$path,'attachment_name'=>$name,'attachment_size'=>$size,'attachment_type'=>$type,'created_at'=>current_time('mysql')],['%d','%d','%s','%s','%s','%d','%s','%s']);
        $wpdb->update($tname,['updated_at'=>current_time('mysql')],['id'=>$thread->id],['%s'],['%d']);
        wp_send_json_success(['message_id'=>(int)$wpdb->insert_id]);
    }

    public function ajax_get_messages(): void {
        check_ajax_referer('bimarstop_chat','nonce');
        if (!is_user_logged_in()) wp_send_json_error();
        global $wpdb;$uid=get_current_user_id();$last=absint($_POST['last_id']??0);$tn=$wpdb->prefix.'bimarstop_chat_threads';$thread=null;
        if($this->current_role()==='bimarstop_patient'){
            $thread=$wpdb->get_row($wpdb->prepare("SELECT * FROM $tn WHERE patient_id=%d AND status='open' ORDER BY id DESC LIMIT 1",$uid));
        } else {
            $thread=$wpdb->get_row($wpdb->prepare("SELECT * FROM $tn WHERE operator_id=%d AND status='open' ORDER BY updated_at DESC LIMIT 1",$uid));
            if(!$thread && $this->current_role()==='bimarstop_operator'){
                $thread=$wpdb->get_row("SELECT * FROM $tn WHERE operator_id=0 AND status='open' ORDER BY updated_at ASC, id ASC LIMIT 1");
                if($thread){
                    $wpdb->update($tn,['operator_id'=>$uid,'updated_at'=>current_time('mysql')],['id'=>$thread->id],['%d','%s'],['%d']);
                    $thread->operator_id=$uid;
                }
            }
        }
        if(!$thread)wp_send_json_success(['messages'=>[]]);
        $rows=$wpdb->get_results($wpdb->prepare("SELECT m.id,m.message,m.sender_id,m.attachment_name,m.attachment_size,m.attachment_type,u.display_name,m.created_at FROM {$wpdb->prefix}bimarstop_chat_messages m JOIN {$wpdb->users} u ON u.ID=m.sender_id WHERE m.thread_id=%d AND m.id>%d ORDER BY m.id ASC",$thread->id,$last));
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
        echo '<div class="bimar-chat-shell"><aside class="bimar-chat-side"><div class="bimar-chat-side-title">گفتگوی داخلی</div><div class="bimar-chat-tab active">💬 '.esc_html($partner->display_name?:$partner->user_login).'</div><div class="bimar-chat-side-info">🔒 گفتگوی خصوصی پزشک و اپراتور<br><span>فایل‌های PDF، JPG، PNG و DOCX تا ۱۰ مگابایت.</span></div></aside><main class="bimar-chat-main"><div id="bimarstop-private-box" class="bimar-chat-box"></div><div id="bimar-private-attachment" class="bimar-chat-attachment" hidden><span>📎 <b id="bimar-private-file-name"></b></span><button type="button" id="bimar-private-file-remove">×</button></div><div class="bimar-chat-composer"><label class="bimar-attach"><input id="bimarstop-private-file" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx" hidden>📎</label>'.($this->can_use_documents() ? '<select id="bimarstop-private-document" title="انتخاب مدرک موجود"><option value="">📚 مدرک موجود</option></select>' : '').'<textarea id="bimarstop-private-input" rows="1" placeholder="پیام خود را بنویسید..."></textarea><button type="button" id="bimarstop-private-send" class="bimar-send">➤</button><div class="bimar-chat-hint">Enter برای ارسال • Shift+Enter برای خط جدید</div></div></main></div></div>';
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
        echo '<div class="wrap" dir="rtl"><h1>👨‍💻 چت خصوصی با اپراتورها</h1><table class="widefat striped"><thead><tr><th>اپراتور</th><th>وضعیت</th><th>ایمیل</th><th>عملیات</th></tr></thead><tbody>';
        foreach ($operators as $u) { $online = get_user_meta($u->ID, 'bimarstop_operator_online', true) === '1'; echo '<tr><td>'.esc_html($u->display_name ?: $u->user_login).'</td><td>'.($online?'آنلاین 🟢':'آفلاین ⚪').'</td><td>'.esc_html($u->user_email).'</td><td><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=bimarstop-doctor-chats&user='.(int)$u->ID)).'">شروع / ادامه چت</a></td></tr>'; }
        if (!$operators) echo '<tr><td colspan="4">اپراتوری ثبت نشده است.</td></tr>';
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
        echo '<div class="wrap" dir="rtl"><h1>💬 چت‌های داخلی</h1><p>گفتگوهای خصوصی پزشک و اپراتور.</p><table class="widefat striped"><thead><tr><th>کاربر اول</th><th>کاربر دوم</th><th>وضعیت</th><th>آخرین بروزرسانی</th><th>عملیات</th></tr></thead><tbody>';
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
        elseif(!empty($_GET['private_message_id'])){$id=absint($_GET['private_message_id']);$r=$wpdb->get_row($wpdb->prepare("SELECT t.user_a_id,t.user_b_id,m.attachment_path,m.attachment_name,m.attachment_type FROM {$wpdb->prefix}bimarstop_private_messages m JOIN {$wpdb->prefix}bimarstop_private_threads t ON t.id=m.thread_id WHERE m.id=%d",$id));if($r&&($r->user_a_id==$uid||$r->user_b_id==$uid||current_user_can('manage_options'))) $path=$r->attachment_path;$name=$r->attachment_name??'';$type=$r->attachment_type??'application/octet-stream';} else if(!empty($_GET['document_id']) && !empty($_GET['share_id'])){ $did=absint($_GET['document_id']);$sid=absint($_GET['share_id']);$r=$wpdb->get_row($wpdb->prepare("SELECT d.path,d.name,d.type,s.recipient_id FROM {$wpdb->prefix}bimarstop_documents d JOIN {$wpdb->prefix}bimarstop_document_shares s ON s.document_id=d.id WHERE d.id=%d AND s.id=%d",$did,$sid));if($r&&((int)$r->recipient_id===$uid||current_user_can('manage_options'))){$path=$r->path;$name=$r->name;$type=$r->type;}}
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
        $report_manager = current_user_can('manage_options') || $this->current_role() === 'bimarstop_operator';
        if($report_manager && isset($_POST['bimarstop_issue_status_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bimarstop_issue_status_nonce'])),'bimarstop_issue_status')){
            $id=absint($_POST['issue_id']??0); $status=sanitize_key($_POST['status']??'open');
            if(in_array($status,['open','progress','closed'],true)) $wpdb->update($table,['status'=>$status,'updated_at'=>current_time('mysql')],['id'=>$id],['%s','%s'],['%d']);
        }
        echo '<div class="wrap" dir="rtl"><h1>🛠 گزارش مشکل</h1>';
        if($report_manager){
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
        global $wpdb;
        $this->sync_documents();
        $dt=$wpdb->prefix.'bimarstop_documents';
        $ct=$wpdb->prefix.'bimarstop_document_categories';
        $st=$wpdb->prefix.'bimarstop_document_shares';
        $uid=get_current_user_id();
        $manager=$this->current_role()==='bimarstop_operator';

        if($manager && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['bimarstop_doc_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bimarstop_doc_nonce'])),'bimarstop_doc')){
            $action=sanitize_key($_POST['doc_action']??'');
            if($action==='add_category'){
                $name=sanitize_text_field(wp_unslash($_POST['category_name']??''));
                $parent=absint($_POST['parent_id']??0);
                if($name!==''){
                    $exists=$wpdb->get_var($wpdb->prepare("SELECT id FROM $ct WHERE name=%s",$name));
                    if(!$exists) $wpdb->insert($ct,['name'=>$name,'parent_id'=>$parent,'created_at'=>current_time('mysql')],['%s','%d','%s']);
                }
            }
        }

        $cats=$wpdb->get_results("SELECT * FROM $ct ORDER BY parent_id ASC,name ASC");
        if(!$cats && $manager){
            $wpdb->insert($ct,['name'=>'عمومی','parent_id'=>0,'created_at'=>current_time('mysql')],['%s','%d','%s']);
            $cats=$wpdb->get_results("SELECT * FROM $ct ORDER BY parent_id ASC,name ASC");
        }

        $received=$wpdb->get_results($wpdb->prepare("SELECT s.*,d.name,d.size,d.type,u.display_name AS sender_name FROM $st s JOIN $dt d ON d.id=s.document_id LEFT JOIN {$wpdb->users} u ON u.ID=s.sender_id WHERE s.recipient_id=%d ORDER BY s.id DESC",$uid));
        $rows=$manager?$wpdb->get_results("SELECT d.*,COALESCE(c.name,'عمومی') AS category_name FROM $dt d LEFT JOIN $ct c ON c.id=d.category_id WHERE d.hidden=0 ORDER BY d.name ASC"):[];

        $children=[];
        foreach($cats as $cat) $children[(int)$cat->parent_id][]=$cat;

        echo '<div class="wrap" dir="rtl"><h1>📚 مدارک</h1>';
        echo '<style>
        .bimar-doc-toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:14px 0}
        .bimar-doc-board{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;align-items:start;padding:12px 0}
        .bimar-doc-col{background:#f6f7f9;border:1px solid #dcdcde;border-radius:14px;min-width:0;padding:10px;transition:.15s}
        .bimar-doc-col h3{margin:4px 6px 10px;display:flex;align-items:center;gap:7px}.bimar-doc-list{min-height:54px}
        .bimar-doc-card{background:#fff;border:1px solid #ddd;border-radius:12px;padding:12px;margin:8px 0;cursor:grab;box-shadow:0 2px 6px #0000000b}
        .bimar-doc-card:active{cursor:grabbing}.bimar-doc-card b{display:block;margin-bottom:5px;word-break:break-word}.bimar-doc-meta{font-size:12px;color:#666}
        .bimar-doc-actions{display:flex;gap:6px;margin-top:10px;flex-wrap:wrap}.bimar-doc-drop{outline:2px dashed #2271b1;outline-offset:-4px;background:#eef6ff}
        .bimar-folder{cursor:grab}.bimar-folder:active{cursor:grabbing}.bimar-folder.is-drop{outline:2px dashed #2271b1;outline-offset:-4px}
        .bimar-folder-head{display:flex;align-items:center;justify-content:space-between;gap:8px}.bimar-folder-title{font-weight:800;word-break:break-word}
        .bimar-folder-sub{font-size:11px;color:#666;margin:2px 6px 8px}.bimar-folder-empty{color:#777;font-size:12px;padding:10px 4px}
        .bimar-doc-rename{max-width:180px}.bimar-doc-received{background:#fff;border:1px solid #ddd;border-radius:12px;padding:14px;margin:8px 0}
        .bimar-doc-child{margin:10px 0 0 0;padding-right:12px;border-right:2px solid #dcdcde}
        </style>';

        if($manager){
            echo '<p>📁 پوشه‌ها و فایل‌ها را با کشیدن و رها کردن جابه‌جا کنید. پوشه را روی پوشه دیگری بیندازید تا زیرپوشه شود؛ پوشه‌ها می‌توانند چند سطح تو در تو داشته باشند.</p>';
            echo '<div class="bimar-doc-toolbar">';
            echo '<form method="post">'.wp_nonce_field('bimarstop_doc','bimarstop_doc_nonce',true,false).'<input type="hidden" name="doc_action" value="add_category"><input name="category_name" placeholder="نام پوشه جدید" required><select name="parent_id"><option value="0">📁 ریشه</option>';
            foreach($cats as $cat) echo '<option value="'.(int)$cat->id.'">📂 '.esc_html($cat->name).'</option>';
            echo '</select> <button class="button button-primary">➕ ساخت پوشه</button></form>';
            echo '</div>';
            echo '<div class="bimar-doc-board" id="bimar-doc-board">';

            $renderFolder=function($parent=0) use (&$renderFolder,$children,$rows){
                foreach(($children[(int)$parent]??[]) as $cat){
                    $cid=(int)$cat->id;
                    echo '<section class="bimar-doc-col bimar-folder" draggable="true" data-category="'.$cid.'">';
                    echo '<div class="bimar-folder-head"><h3>📁 <span class="bimar-folder-title">'.esc_html($cat->name).'</span></h3><button type="button" class="button bimar-folder-delete" data-folder="'.$cid.'">🗑️ حذف</button></div>';
                    echo '<div class="bimar-folder-sub">این پوشه می‌تواند زیرپوشه و فایل داشته باشد.</div>';
                    echo '<div class="bimar-doc-list">';
                    $has=false;
                    foreach($rows as $r) if((int)$r->category_id===$cid){
                        $has=true;
                        $ext=strtolower(pathinfo($r->name,PATHINFO_EXTENSION));
                        $baseName=$ext!==''?substr($r->name,0,-(strlen($ext)+1)):$r->name;
                        echo '<article class="bimar-doc-card" draggable="true" data-doc="'.(int)$r->id.'"><b>📎 '.esc_html($r->name).'</b><div class="bimar-doc-meta">'.size_format((int)$r->size).' • '.esc_html($r->type).'</div><div class="bimar-doc-actions"><button type="button" class="button bimar-doc-rename" data-doc="'.(int)$r->id.'" data-name="'.esc_attr($baseName).'">✏️ تغییر نام</button><button type="button" class="button bimar-doc-hide" data-doc="'.(int)$r->id.'">🙈 مخفی</button><button type="button" class="button bimar-doc-delete" data-doc="'.(int)$r->id.'">🗑️ حذف</button><button type="button" class="button bimar-doc-send" data-doc="'.(int)$r->id.'">📤 فرستادن</button></div></article>';
                    }
                    if(!$has && empty($children[$cid])) echo '<div class="bimar-folder-empty">این پوشه خالی است.</div>';
                    foreach(($children[$cid]??[]) as $child){
                        // رندر زیرپوشه‌ها داخل همین پوشه
                        echo '<div class="bimar-doc-child">';
                        $renderFolder($cid);
                        echo '</div>';
                        break;
                    }
                    echo '</div></section>';
                }
            };
            $renderFolder(0);
            echo '</div>';

            echo '<div id="bimar-doc-send-box" style="display:none;background:#fff;border:1px solid #ddd;border-radius:14px;padding:18px;max-width:650px"><h2>📤 ارسال مدرک</h2><input type="hidden" id="bimar-share-doc"><p><label>گیرنده<br><select id="bimar-share-recipient" style="min-width:320px"><option value="">انتخاب بیمار یا پزشک</option>';
            $recipients=get_users(['role__in'=>['bimarstop_patient','bimarstop_doctor'],'orderby'=>'display_name','order'=>'ASC']);
            foreach($recipients as $u) echo '<option value="'.(int)$u->ID.'">'.esc_html($u->display_name?:$u->user_login).' — '.esc_html(in_array('bimarstop_doctor',$u->roles,true)?'پزشک':'بیمار').'</option>';
            echo '</select></label></p><p><label>توضیح (اختیاری)<br><textarea id="bimar-share-note" rows="3" style="width:100%"></textarea></label></p><p><button type="button" class="button button-primary" id="bimar-share-submit">ارسال</button> <button type="button" class="button" id="bimar-share-cancel">انصراف</button></p></div>';

            $nonce=wp_create_nonce('bimarstop_chat');$ajax=admin_url('admin-ajax.php');
            echo '<script>(function(){var board=document.getElementById("bimar-doc-board"),sendBox=document.getElementById("bimar-doc-send-box"),doc=document.getElementById("bimar-share-doc"),recipient=document.getElementById("bimar-share-recipient"),note=document.getElementById("bimar-share-note"),ajax="'.esc_url($ajax).'",nonce="'.esc_js($nonce).'";if(!board)return;
            var dragged=null;
            function wire(){
                board.querySelectorAll(".bimar-doc-card").forEach(function(card){card.addEventListener("dragstart",function(e){dragged={type:"doc",id:card.dataset.doc,el:card};e.stopPropagation();});});
                board.querySelectorAll(".bimar-folder").forEach(function(folder){
                    folder.addEventListener("dragstart",function(e){dragged={type:"folder",id:folder.dataset.category,el:folder};e.stopPropagation();});
                    folder.addEventListener("dragover",function(e){e.preventDefault();folder.classList.add("is-drop");});
                    folder.addEventListener("dragleave",function(){folder.classList.remove("is-drop");});
                    folder.addEventListener("drop",function(e){
                        e.preventDefault();folder.classList.remove("is-drop");if(!dragged)return;
                        var target=folder.dataset.category;
                        if(dragged.type==="doc"){
                            folder.querySelector(".bimar-doc-list").appendChild(dragged.el);
                            var f=new FormData();f.append("action","bimarstop_move_document");f.append("nonce",nonce);f.append("document_id",dragged.id);f.append("category_id",target);
                            fetch(ajax,{method:"POST",body:f});
                        }else if(dragged.type==="folder" && dragged.id!==target && !dragged.el.contains(folder)){
                            var f=new FormData();f.append("action","bimarstop_move_document_folder");f.append("nonce",nonce);f.append("folder_id",dragged.id);f.append("parent_id",target);
                            fetch(ajax,{method:"POST",body:f}).then(function(r){return r.json();}).then(function(x){if(x.success)location.reload();else alert((x.data&&x.data.message)||"جابه‌جایی پوشه ناموفق بود.");});
                        }
                        dragged=null;
                    });
                });
                board.querySelectorAll(".bimar-folder-delete").forEach(function(btn){btn.addEventListener("click",function(e){e.stopPropagation();if(!confirm("این پوشه حذف شود؟ فقط پوشه خالی قابل حذف است."))return;var f=new FormData();f.append("action","bimarstop_delete_document_folder");f.append("nonce",nonce);f.append("folder_id",btn.dataset.folder);fetch(ajax,{method:"POST",body:f}).then(function(r){return r.json();}).then(function(x){if(x.success)location.reload();else alert((x.data&&x.data.message)||"حذف پوشه ناموفق بود.");});});});
                board.querySelectorAll(".bimar-doc-rename").forEach(function(btn){btn.addEventListener("click",function(){
                    var name=prompt("نام جدید فایل را وارد کنید:",btn.dataset.name);if(name===null)return;name=name.trim();if(!name)return;
                    var f=new FormData();f.append("action","bimarstop_rename_document");f.append("nonce",nonce);f.append("document_id",btn.dataset.doc);f.append("name",name);
                    fetch(ajax,{method:"POST",body:f}).then(function(r){return r.json();}).then(function(x){if(x.success)location.reload();else alert((x.data&&x.data.message)||"تغییر نام فایل ناموفق بود.");});
                });});
                board.querySelectorAll(".bimar-doc-hide").forEach(function(btn){btn.addEventListener("click",function(){if(!confirm("این فایل مخفی شود؟"))return;var f=new FormData();f.append("action","bimarstop_toggle_document_hidden");f.append("nonce",nonce);f.append("document_id",btn.dataset.doc);fetch(ajax,{method:"POST",body:f}).then(function(r){return r.json();}).then(function(x){if(x.success)location.reload();else alert((x.data&&x.data.message)||"عملیات ناموفق بود.");});});});
                board.querySelectorAll(".bimar-doc-delete").forEach(function(btn){btn.addEventListener("click",function(){if(!confirm("فایل واقعاً از سرور حذف شود؟ این عملیات برگشت‌پذیر نیست."))return;var f=new FormData();f.append("action","bimarstop_delete_document");f.append("nonce",nonce);f.append("document_id",btn.dataset.doc);fetch(ajax,{method:"POST",body:f}).then(function(r){return r.json();}).then(function(x){if(x.success)location.reload();else alert((x.data&&x.data.message)||"حذف فایل ناموفق بود.");});});});
                board.querySelectorAll(".bimar-doc-send").forEach(function(btn){btn.addEventListener("click",function(){doc.value=btn.dataset.doc;sendBox.style.display="block";sendBox.scrollIntoView({behavior:"smooth",block:"center"});recipient.focus();});});
            }
            wire();
            document.getElementById("bimar-share-cancel").onclick=function(){sendBox.style.display="none";};
            document.getElementById("bimar-share-submit").onclick=function(){if(!recipient.value){alert("گیرنده را انتخاب کنید.");return;}var f=new FormData();f.append("action","bimarstop_share_document");f.append("nonce",nonce);f.append("document_id",doc.value);f.append("recipient_id",recipient.value);f.append("note",note.value);fetch(ajax,{method:"POST",body:f}).then(function(r){return r.json();}).then(function(x){if(x.success){alert("مدرک برای گیرنده ارسال شد.");sendBox.style.display="none";note.value="";recipient.value="";}else alert((x.data&&x.data.message)||"ارسال ناموفق بود.");});};
            })();</script>';
        }

        echo '<hr><h2>📥 مدارک دریافت‌شده</h2>';
        if(!$received) echo '<p>هنوز مدرکی برای شما ارسال نشده است.</p>';
        foreach($received as $r){
            $url=admin_url('admin-ajax.php?action=bimarstop_download_file&message_id=0&document_id='.(int)$r->document_id.'&nonce='.wp_create_nonce('bimarstop_download').'&share_id='.(int)$r->id);
            echo '<div class="bimar-doc-received"><strong>📎 '.esc_html($r->name).'</strong> — '.size_format((int)$r->size).'<br><small>از طرف: '.esc_html($r->sender_name).'</small>'.(!empty($r->note)?'<p>'.esc_html($r->note).'</p>':'').' <a class="button" href="'.esc_url($url).'">دانلود / مشاهده</a></div>';
        }
        echo '</div>';
    }

    public function ajax_delete_document(): void {
        if($this->current_role()!=='bimarstop_operator' && !current_user_can('manage_options')) wp_send_json_error(['message'=>'دسترسی ندارید.'],403);
        check_ajax_referer('bimarstop_chat','nonce');
        global $wpdb; $id=absint($_POST['document_id']??0); $table=$wpdb->prefix.'bimarstop_documents';
        $doc=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$id));
        if(!$doc) wp_send_json_error(['message'=>'فایل پیدا نشد.']);
        $uploads=wp_upload_dir(); $base=realpath($uploads['basedir']); $path=realpath($doc->path);
        if(!$base||!$path||strpos(wp_normalize_path($path),wp_normalize_path($base))!==0) wp_send_json_error(['message'=>'مسیر فایل معتبر نیست.']);
        if(is_file($path) && !@unlink($path)) wp_send_json_error(['message'=>'حذف فایل انجام نشد.']);
        $wpdb->delete($table,['id'=>$id],['%d']); wp_send_json_success();
    }

    public function ajax_toggle_document_hidden(): void {
        if($this->current_role()!=='bimarstop_operator' && !current_user_can('manage_options')) wp_send_json_error(['message'=>'دسترسی ندارید.'],403);
        check_ajax_referer('bimarstop_chat','nonce');
        global $wpdb; $id=absint($_POST['document_id']??0); $table=$wpdb->prefix.'bimarstop_documents';
        $doc=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$id));
        if(!$doc) wp_send_json_error(['message'=>'فایل پیدا نشد.']);
        $wpdb->update($table,['hidden'=>empty($doc->hidden)?1:0,'updated_at'=>current_time('mysql')],['id'=>$id],['%d','%s'],['%d']);
        wp_send_json_success();
    }

    public function ajax_rename_document(): void {
        if($this->current_role()!=='bimarstop_operator') wp_send_json_error(['message'=>'فقط اپراتور می‌تواند نام فایل را تغییر دهد.']);
        check_ajax_referer('bimarstop_chat','nonce');
        global $wpdb;
        $id=absint($_POST['document_id']??0);
        $new_name=sanitize_file_name(wp_unslash($_POST['name']??''));
        if(!$id||$new_name==='') wp_send_json_error(['message'=>'نام فایل معتبر نیست.']);
        $table=$wpdb->prefix.'bimarstop_documents';
        $doc=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$id));
        if(!$doc) wp_send_json_error(['message'=>'فایل پیدا نشد.']);
        $uploads=wp_upload_dir();
        $old=realpath($doc->path);
        $base=!empty($uploads['basedir'])?realpath($uploads['basedir']):false;
        if(!$old||!$base||strpos($old,$base)!==0||!is_file($old)) wp_send_json_error(['message'=>'مسیر فایل معتبر نیست.']);
        $ext=strtolower(pathinfo($old,PATHINFO_EXTENSION));
        $new_base=pathinfo($new_name,PATHINFO_FILENAME);
        if($new_base==='') wp_send_json_error(['message'=>'نام فایل معتبر نیست.']);
        $final=$new_base.($ext!==''?'.'.$ext:'');
        $dir=dirname($old);
        $target=$dir.DIRECTORY_SEPARATOR.$final;
        if($target!==$old && file_exists($target)){
            wp_send_json_error(['message'=>'فایلی با این نام از قبل وجود دارد.']);
        }
        if($target!==$old && !@rename($old,$target)) wp_send_json_error(['message'=>'تغییر نام فایل روی سرور ناموفق بود.']);
        $real_target=realpath($target);
        if(!$real_target) wp_send_json_error(['message'=>'فایل پس از تغییر نام پیدا نشد.']);
        $ok=$wpdb->update($table,['path'=>$real_target,'name'=>basename($real_target),'size'=>(int)filesize($real_target),'updated_at'=>current_time('mysql')],['id'=>$id],['%s','%s','%d','%s'],['%d']);
        if($ok===false) wp_send_json_error(['message'=>'نام فایل تغییر کرد اما ثبت اطلاعات آن ناموفق بود.']);
        wp_send_json_success(['name'=>basename($real_target)]);
    }

    public function ajax_add_document_folder(): void {
        if($this->current_role()!=='bimarstop_operator') wp_send_json_error(['message'=>'فقط اپراتور می‌تواند پوشه بسازد.']);
        check_ajax_referer('bimarstop_chat','nonce');
        global $wpdb;
        $name=sanitize_text_field(wp_unslash($_POST['name']??''));
        $parent=absint($_POST['parent_id']??0);
        if($name==='') wp_send_json_error(['message'=>'نام پوشه را وارد کنید.']);
        $table=$wpdb->prefix.'bimarstop_document_categories';
        if($parent && !$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE id=%d",$parent))) wp_send_json_error(['message'=>'پوشه والد پیدا نشد.']);
        if($wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE name=%s",$name))) wp_send_json_error(['message'=>'پوشه‌ای با این نام از قبل وجود دارد.']);
        $ok=$wpdb->insert($table,['name'=>$name,'parent_id'=>$parent,'created_at'=>current_time('mysql')],['%s','%d','%s']);
        if(!$ok) wp_send_json_error(['message'=>'ساخت پوشه ناموفق بود.']);
        wp_send_json_success(['id'=>(int)$wpdb->insert_id]);
    }

    public function ajax_delete_document_folder(): void {
        if($this->current_role()!=='bimarstop_operator' && !current_user_can('manage_options')) wp_send_json_error(['message'=>'دسترسی ندارید.'],403);
        check_ajax_referer('bimarstop_chat','nonce');
        global $wpdb; $id=absint($_POST['folder_id']??0); $table=$wpdb->prefix.'bimarstop_document_categories';
        if(!$id) wp_send_json_error(['message'=>'پوشه نامعتبر است.']);
        // حذف پوشه فقط دسته‌بندی را حذف می‌کند؛ فایل‌ها باقی می‌مانند.
        // فایل‌های داخل این پوشه به حالت «بدون پوشه» منتقل می‌شوند و
        // زیرپوشه‌ها نیز به ریشه منتقل می‌شوند.
        $wpdb->update(
            $wpdb->prefix.'bimarstop_documents',
            ['category_id'=>0],
            ['category_id'=>$id],
            ['%d'],
            ['%d']
        );
        $wpdb->update(
            $table,
            ['parent_id'=>0],
            ['parent_id'=>$id],
            ['%d'],
            ['%d']
        );
        if(!$wpdb->delete($table,['id'=>$id],['%d'])) wp_send_json_error(['message'=>'حذف پوشه ناموفق بود.']);
        wp_send_json_success();
    }

    public function ajax_move_document_folder(): void {
        if($this->current_role()!=='bimarstop_operator') wp_send_json_error(['message'=>'فقط اپراتور می‌تواند پوشه‌ها را جابه‌جا کند.']);
        check_ajax_referer('bimarstop_chat','nonce');
        global $wpdb;
        $id=absint($_POST['folder_id']??0);
        $parent=absint($_POST['parent_id']??0);
        $table=$wpdb->prefix.'bimarstop_document_categories';
        if(!$id) wp_send_json_error(['message'=>'پوشه نامعتبر است.']);
        if($id===$parent) wp_send_json_error(['message'=>'پوشه نمی‌تواند والد خودش باشد.']);
        if($parent && !$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE id=%d",$parent))) wp_send_json_error(['message'=>'پوشه والد پیدا نشد.']);
        $cursor=$parent;
        $guard=0;
        while($cursor && $guard++<100){
            if($cursor===$id) wp_send_json_error(['message'=>'نمی‌توان یک پوشه را داخل یکی از زیرپوشه‌های خودش قرار داد.']);
            $cursor=(int)$wpdb->get_var($wpdb->prepare("SELECT parent_id FROM $table WHERE id=%d",$cursor));
        }
        $ok=$wpdb->update($table,['parent_id'=>$parent],['id'=>$id],['%d'],['%d']);
        $ok===false?wp_send_json_error(['message'=>'جابه‌جایی پوشه ناموفق بود.']):wp_send_json_success();
    }

    public function ajax_get_documents(): void {
        check_ajax_referer('bimarstop_chat','nonce');
        if(!$this->can_use_documents()) wp_send_json_error(['message'=>'دسترسی ندارید.']);
        global $wpdb; $this->sync_documents();
        $rows=$wpdb->get_results("SELECT d.id,d.name,d.size,d.type,c.name AS category_name FROM {$wpdb->prefix}bimarstop_documents d LEFT JOIN {$wpdb->prefix}bimarstop_document_categories c ON c.id=d.category_id WHERE d.hidden=0 ORDER BY c.name ASC,d.name ASC");
        $out=[]; foreach($rows as $r)$out[]=['id'=>(int)$r->id,'name'=>$r->name,'size'=>size_format((int)$r->size),'category'=>$r->category_name?:'عمومی'];
        wp_send_json_success(['documents'=>$out]);
    }

    public function ajax_send_document(): void {
        if(!$this->can_use_documents()) wp_send_json_error();
        check_ajax_referer('bimarstop_chat','nonce');
        wp_send_json_success();
    }

    public function ajax_move_document(): void {
        if($this->current_role()!=='bimarstop_operator') wp_send_json_error(['message'=>'فقط اپراتور به مدارک دسترسی دارد.']);
        check_ajax_referer('bimarstop_chat','nonce');
        global $wpdb;
        $id=absint($_POST['document_id']??0);$cat=absint($_POST['category_id']??0);
        if(!$id||!$cat)wp_send_json_error(['message'=>'اطلاعات ناقص است.']);
        $ok=$wpdb->update($wpdb->prefix.'bimarstop_documents',['category_id'=>$cat,'updated_at'=>current_time('mysql')],['id'=>$id],['%d','%s'],['%d']);
        $ok===false?wp_send_json_error(['message'=>'ذخیره دسته‌بندی ناموفق بود.']):wp_send_json_success();
    }

    public function ajax_share_document(): void {
        if($this->current_role()!=='bimarstop_operator') wp_send_json_error(['message'=>'فقط اپراتور می‌تواند مدرک ارسال کند.']);
        check_ajax_referer('bimarstop_chat','nonce');
        global $wpdb;

        $did=absint($_POST['document_id']??0);
        $rid=absint($_POST['recipient_id']??0);
        $note=sanitize_textarea_field(wp_unslash($_POST['note']??''));
        $uid=get_current_user_id();

        $doc=$wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bimarstop_documents WHERE id=%d",
            $did
        ));
        $recipient=get_userdata($rid);

        if(!$doc || !$recipient || (!in_array('bimarstop_doctor',$recipient->roles,true) && !in_array('bimarstop_patient',$recipient->roles,true))){
            wp_send_json_error(['message'=>'گیرنده معتبر نیست.']);
        }

        $uploads=wp_upload_dir();
        $real=realpath($doc->path);
        $base=!empty($uploads['basedir'])?realpath($uploads['basedir']):false;
        if(!$real || !$base || strpos($real,$base)!==0 || !is_file($real)){
            wp_send_json_error(['message'=>'فایل مدرک پیدا نشد.']);
        }

        $message_text=$note;
        $now=current_time('mysql');

        // بیمار: مدرک دقیقاً داخل همان چت بیمار ↔ اپراتور ارسال می‌شود.
        if(in_array('bimarstop_patient',$recipient->roles,true)){
            $threads=$wpdb->prefix.'bimarstop_chat_threads';
            $thread=$wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $threads WHERE patient_id=%d AND operator_id=%d AND status='open' ORDER BY updated_at DESC LIMIT 1",
                $rid,$uid
            ));

            if(!$thread){
                $wpdb->insert($threads,[
                    'patient_id'=>$rid,
                    'operator_id'=>$uid,
                    'status'=>'open',
                    'created_at'=>$now,
                    'updated_at'=>$now
                ],['%d','%d','%s','%s','%s']);
                $thread=$wpdb->insert_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $threads WHERE id=%d",$wpdb->insert_id)) : null;
            }

            if(!$thread) wp_send_json_error(['message'=>'گفتگوی بیمار پیدا یا ایجاد نشد.']);

            $ok=$wpdb->insert($wpdb->prefix.'bimarstop_chat_messages',[
                'thread_id'=>(int)$thread->id,
                'sender_id'=>$uid,
                'message'=>$message_text,
                'attachment_path'=>$real,
                'attachment_name'=>$doc->name,
                'attachment_size'=>(int)$doc->size,
                'attachment_type'=>$doc->type,
                'created_at'=>$now
            ],['%d','%d','%s','%s','%s','%d','%s','%s']);

            if(!$ok) wp_send_json_error(['message'=>'ارسال مدرک به چت بیمار ناموفق بود.']);
            $wpdb->update($threads,['updated_at'=>$now],['id'=>(int)$thread->id],['%s'],['%d']);

            $chat_message_id=(int)$wpdb->insert_id;
        } else {
            // پزشک: مدرک دقیقاً داخل همان چت خصوصی پزشک ↔ اپراتور ارسال می‌شود.
            $thread=$this->get_or_create_private_thread($uid,$rid);
            if(!$thread) wp_send_json_error(['message'=>'گفتگوی پزشک پیدا یا ایجاد نشد.']);

            $ok=$wpdb->insert($wpdb->prefix.'bimarstop_private_messages',[
                'thread_id'=>(int)$thread->id,
                'sender_id'=>$uid,
                'message'=>$message_text,
                'attachment_path'=>$real,
                'attachment_name'=>$doc->name,
                'attachment_size'=>(int)$doc->size,
                'attachment_type'=>$doc->type,
                'created_at'=>$now
            ],['%d','%d','%s','%s','%s','%d','%s','%s']);

            if(!$ok) wp_send_json_error(['message'=>'ارسال مدرک به چت پزشک ناموفق بود.']);
            $wpdb->update($wpdb->prefix.'bimarstop_private_threads',['updated_at'=>$now],['id'=>(int)$thread->id],['%s'],['%d']);

            $chat_message_id=(int)$wpdb->insert_id;
        }

        // نگه‌داشتن سابقه ارسال مدرک نیز انجام می‌شود.
        $wpdb->insert($wpdb->prefix.'bimarstop_document_shares',[
            'document_id'=>$did,
            'sender_id'=>$uid,
            'recipient_id'=>$rid,
            'note'=>$note,
            'status'=>'sent',
            'created_at'=>$now
        ],['%d','%d','%d','%s','%s','%s']);

        wp_send_json_success([
            'share_id'=>(int)$wpdb->insert_id,
            'chat_message_id'=>$chat_message_id
        ]);
    }

    private function normalize_mobile(string $mobile): string {
        $mobile = trim($mobile);
        $mobile = preg_replace('/[\s\-\(\)]/', '', $mobile);
        if (strpos($mobile, '+98') === 0) $mobile = '0' . substr($mobile, 3);
        if (strpos($mobile, '0098') === 0) $mobile = '0' . substr($mobile, 4);
        return $mobile;
    }

    private function valid_mobile(string $mobile): bool {
        return (bool) preg_match('/^09\d{9}$/', $mobile);
    }

    public function auth_shortcode(): string {
        if (is_user_logged_in()) {
            return '<script>location.href=' . wp_json_encode(admin_url()) . ';</script>';
        }

        $ajax = home_url('/wp-admin/admin-ajax.php');
        $nonce = wp_create_nonce('bimarstop_auth');

        ob_start(); ?>
        <div class="bimarstop-auth-card" dir="rtl">
            <div class="bimarstop-auth-brand">
                <div class="bimarstop-auth-logo">🏥</div>
                <div>
                    <h1 class="bimarstop-auth-title">ورود به BimarStop</h1>
                    <p class="bimarstop-auth-subtitle">فقط شماره موبایلتان را وارد کنید؛ ورود و ثبت‌نام خودکار است.</p>
                </div>
            </div>

            <label class="bimarstop-auth-label" for="bimarstop-auth-mobile">شماره موبایل</label>
            <input class="bimarstop-auth-field" id="bimarstop-auth-mobile" type="tel" inputmode="numeric" autocomplete="tel" placeholder="0912 345 6789">

            <button class="bimarstop-auth-btn" type="button" id="bimarstop-auth-send">دریافت کد ورود</button>

            <div class="bimarstop-auth-otp" id="bimarstop-auth-otp-wrap" style="display:none">
                <p class="bimarstop-auth-hint">کد ۶ رقمی پیامک‌شده را وارد کنید.</p>
                <input class="bimarstop-auth-field" id="bimarstop-auth-code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="••••••">
                <button class="bimarstop-auth-btn" type="button" id="bimarstop-auth-verify">تأیید و ورود</button>
            </div>

            <div class="bimarstop-auth-status" id="bimarstop-auth-status"></div>
            <div class="bimarstop-auth-footer">با تأیید کد، اگر حسابی نداشته باشید حساب بیمار برایتان ساخته می‌شود.</div>
        </div>

        <script>
        (function(){
            var ajax=<?php echo wp_json_encode($ajax); ?>;
            var nonce=<?php echo wp_json_encode($nonce); ?>;
            var mobile=document.getElementById('bimarstop-auth-mobile');
            var code=document.getElementById('bimarstop-auth-code');
            var wrap=document.getElementById('bimarstop-auth-otp-wrap');
            var status=document.getElementById('bimarstop-auth-status');
            var send=document.getElementById('bimarstop-auth-send');
            var verify=document.getElementById('bimarstop-auth-verify');

            function message(t){ status.textContent=t; }

            async function request(action, includeCode){
                var form=new FormData();
                form.append('action',action);
                form.append('nonce',nonce);
                form.append('mobile',mobile.value);
                if(includeCode) form.append('code',code.value);

                var response=await fetch(ajax,{method:'POST',body:form,credentials:'same-origin',headers:{'Accept':'application/json'}});
                var text=await response.text();
                var data;
                try { data=JSON.parse(text); }
                catch(e) {
                    console.error('BimarStop AJAX response:', response.status, text);
                    throw new Error('server_response_' + response.status);
                }
                return data;
            }

            send.onclick=async function(){
                send.disabled=true;
                message('در حال ارسال کد...');
                try {
                    var result=await request('bimarstop_send_auth_otp',false);
                    if(result.success){
                        wrap.style.display='block';
                        message('کد تأیید ارسال شد.');
                        code.focus();
                        send.textContent='ارسال دوباره کد';
                    } else {
                        message((result.data&&result.data.message)||'ارسال کد ناموفق بود.');
                    }
                } catch(e) {
                    console.error('BimarStop send OTP error:', e);
                    message(e.message === 'server_response_403' ? 'دسترسی به سرور رد شد (403).' : e.message === 'server_response_500' ? 'خطای داخلی سرور (500).' : 'خطا در ارتباط با سرور. لطفاً دوباره تلاش کنید.');
                } finally {
                    send.disabled=false;
                }
            };

            verify.onclick=async function(){
                verify.disabled=true;
                message('در حال بررسی کد...');
                try {
                    var result=await request('bimarstop_verify_auth_otp',true);
                    if(result.success){
                        message('تأیید شد؛ در حال ورود...');
                        window.location.href=result.data.redirect;
                    } else {
                        message((result.data&&result.data.message)||'کد تأیید نادرست است.');
                    }
                } catch(e) {
                    message('خطا در ارتباط با سرور. لطفاً دوباره تلاش کنید.');
                } finally {
                    verify.disabled=false;
                }
            };

            code.addEventListener('input',function(){
                this.value=this.value.replace(/\D/g,'').slice(0,6);
            });
            mobile.addEventListener('keydown',function(e){ if(e.key==='Enter') send.click(); });
            code.addEventListener('keydown',function(e){ if(e.key==='Enter') verify.click(); });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    public function registration_shortcode(): string {
        return $this->auth_shortcode();
    }

    public function login_shortcode(): string {
        return $this->auth_shortcode();
    }

    public function ajax_send_auth_otp(): void {
        check_ajax_referer('bimarstop_auth','nonce');

        $mobile=$this->normalize_mobile(sanitize_text_field(wp_unslash($_POST['mobile']??'')));
        if(!$this->valid_mobile($mobile)) {
            wp_send_json_error(['message'=>'شماره موبایل معتبر نیست.']);
        }

        $settings=wp_parse_args(get_option('bimarstop_settings',[]),['sms_api_key'=>'','sms_line_number'=>'']);
        if(empty($settings['sms_api_key'])||empty($settings['sms_line_number'])) {
            wp_send_json_error(['message'=>'تنظیمات SMS.ir هنوز تکمیل نشده است.']);
        }

        if(get_transient('bimarstop_otp_rate_'.md5($mobile))) {
            wp_send_json_error(['message'=>'لطفاً کمی صبر کنید و دوباره تلاش کنید.']);
        }

        if(!$this->send_plain_otp($mobile)) {
            wp_send_json_error(['message'=>'ارسال پیامک ناموفق بود.']);
        }

        wp_send_json_success(['message'=>'کد تأیید ارسال شد.']);
    }

    public function ajax_verify_auth_otp(): void {
        $stage = 'شروع';

        try {
            $stage = 'بررسی nonce';
            check_ajax_referer('bimarstop_auth','nonce');

            $stage = 'خواندن شماره و کد';
            $mobile=$this->normalize_mobile(sanitize_text_field(wp_unslash($_POST['mobile']??'')));
            $code=preg_replace('/\D+/','',(string)wp_unslash($_POST['code']??''));

            $stage = 'اعتبارسنجی شماره و کد';
            if(!$this->valid_mobile($mobile)||strlen($code)!==6) {
                wp_send_json_error(['message'=>'شماره موبایل یا کد تأیید معتبر نیست.']);
            }

            $stage = 'بررسی کد ذخیره‌شده';
            $stored=get_transient('bimarstop_otp_'.md5($mobile));
            if(!is_string($stored)||!hash_equals($stored,(string)wp_hash($code.'|'.$mobile))) {
                wp_send_json_error(['message'=>'کد تأیید نادرست یا منقضی شده است.']);
            }

            $stage = 'جستجوی حساب کاربری';
            $users=get_users([
                'meta_key'=>'bimarstop_mobile',
                'meta_value'=>$mobile,
                'number'=>1,
                'fields'=>'ID'
            ]);

            if($users) {
                $stage = 'ورود به حساب موجود';
                $user_id=(int)$users[0];
            } else {
                $stage = 'ساخت نام کاربری';
                $login='mobile_'.substr($mobile,1);
                $base_login=$login;
                $i=1;
                while(username_exists($login)) $login=$base_login.'_'.$i++;

                $stage = 'ساخت حساب جدید';
                $user_id=wp_create_user($login,wp_generate_password(32,true,true));
                if(is_wp_error($user_id)) {
                    wp_send_json_error(['message'=>'ایجاد حساب کاربری ناموفق بود.']);
                }

                $stage = 'تنظیم مشخصات حساب';
                $updated=wp_update_user([
                    'ID'=>$user_id,
                    'role'=>'bimarstop_patient',
                    'display_name'=>$mobile,
                    'user_email'=>$mobile.'@bimarstop.local'
                ]);
                if(is_wp_error($updated)) {
                    wp_delete_user($user_id);
                    wp_send_json_error(['message'=>'تنظیم حساب کاربری ناموفق بود: '.$updated->get_error_message()]);
                }

                $stage = 'ذخیره شماره موبایل';
                update_user_meta($user_id,'bimarstop_mobile',$mobile);
            }

            $stage = 'پاک‌کردن کد ورود';
            delete_transient('bimarstop_otp_'.md5($mobile));
            delete_transient('bimarstop_otp_rate_'.md5($mobile));

            $stage = 'ورود به وردپرس';
            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id,true,is_ssl());

            $stage = 'ارسال پاسخ';
            $redirect=$this->profile_is_complete($user_id)?admin_url():home_url('/bimarstop-complete-profile/');
            wp_send_json_success(['redirect'=>$redirect]);
        } catch (\Throwable $e) {
            error_log('[BimarStop OTP] '.$stage.' | '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
            wp_send_json_error([
                'message'=>'خطای داخلی در مرحله «'.$stage.'» رخ داد. لطفاً دوباره تلاش کنید.'
            ]);
        }
    }

    private function send_plain_otp(string $mobile): bool {
        $settings=wp_parse_args(get_option('bimarstop_settings',[]),['sms_api_key'=>'','sms_line_number'=>'']);
        if(empty($settings['sms_api_key'])||empty($settings['sms_line_number'])) return false;
        if(get_transient('bimarstop_otp_rate_'.md5($mobile))) return false;

        $code=(string)wp_rand(100000,999999);
        $otp_key='bimarstop_otp_'.md5($mobile);
        $rate_key='bimarstop_otp_rate_'.md5($mobile);

        set_transient($otp_key,wp_hash($code.'|'.$mobile),5*MINUTE_IN_SECONDS);

        $response=wp_remote_post('https://api.sms.ir/v1/send/bulk',[
            'timeout'=>15,
            'headers'=>[
                'Content-Type'=>'application/json',
                'Accept'=>'application/json',
                'X-API-KEY'=>$settings['sms_api_key']
            ],
            'body'=>wp_json_encode([
                'LineNumber'=>$settings['sms_line_number'],
                'MessageText'=>"سلام، به بیماراستاپ خوش آمدید\nرمز شما: {$code} میباشد.\n#{$code}",
                'Mobiles'=>[$mobile]
            ])
        ]);

        if(is_wp_error($response)) {
            delete_transient($otp_key);
            error_log('[BimarStop SMS] WP error: '.$response->get_error_message());
            return false;
        }

        $status=wp_remote_retrieve_response_code($response);
        $raw_body=wp_remote_retrieve_body($response);
        $body=json_decode($raw_body,true);

        if($status<200||$status>=300) {
            delete_transient($otp_key);
            error_log('[BimarStop SMS] HTTP '.$status.' response: '.$raw_body);
            return false;
        }

        if(is_array($body)) {
            if(isset($body['status']) && !$body['status']) {
                delete_transient($otp_key);
                error_log('[BimarStop SMS] API error: '.$raw_body);
                return false;
            }
            if(isset($body['IsSuccessful']) && !$body['IsSuccessful']) {
                delete_transient($otp_key);
                error_log('[BimarStop SMS] API error: '.$raw_body);
                return false;
            }
        }

        set_transient($rate_key,1,MINUTE_IN_SECONDS);
        return true;
    }

    public function admin_new_user_mobile_field($form_type): void {
        if($form_type!=='add-new-user') return;
        ?><script>(function(){document.addEventListener('DOMContentLoaded',function(){var e=document.getElementById('email');if(e){e.required=false;e.value='';e.closest('tr')&&(e.closest('tr').style.display='none');var t=document.createElement('tr');t.innerHTML='<th><label for="bimarstop_admin_mobile">شماره موبایل</label></th><td><input name="bimarstop_admin_mobile" id="bimarstop_admin_mobile" type="tel" class="regular-text" required placeholder="09123456789"><p class="description">برای ورود پیامکی استفاده می‌شود.</p></td>';e.closest('tr').parentNode.insertBefore(t,e.closest('tr'));var p=document.getElementById('bimarstop_admin_mobile');if(p){p.addEventListener('input',function(){e.value=p.value.replace(/\D/g,'')+'@bimarstop.local';});p.form.addEventListener('submit',function(){e.value=p.value.replace(/\D/g,'')+'@bimarstop.local';});}}});})();</script><?php
    }

    public function validate_admin_mobile_field($errors,$update,$user): void {
        if(!$update && isset($_POST['bimarstop_admin_mobile'])){
            $mobile=$this->normalize_mobile(sanitize_text_field(wp_unslash($_POST['bimarstop_admin_mobile'])));
            if(!$this->valid_mobile($mobile)) $errors->add('bimarstop_mobile','شماره موبایل معتبر نیست.');
            $exists=get_users(['meta_key'=>'bimarstop_mobile','meta_value'=>$mobile,'number'=>1,'exclude'=>[$user->ID??0],'fields'=>'ID']);
            if($exists) $errors->add('bimarstop_mobile_exists','این شماره موبایل قبلاً استفاده شده است.');
        }
    }

    public function save_admin_mobile_field($user_id): void {
        if(!isset($_POST['bimarstop_admin_mobile'])) return;
        $mobile=$this->normalize_mobile(sanitize_text_field(wp_unslash($_POST['bimarstop_admin_mobile'])));
        if(!$this->valid_mobile($mobile)) return;
        update_user_meta($user_id,'bimarstop_mobile',$mobile);
        wp_update_user(['ID'=>$user_id,'user_email'=>$mobile.'@bimarstop.local']);
    }

    public function ajax_verify_otp(): void {
        check_ajax_referer('bimarstop_register','nonce');

        $mobile = $this->normalize_mobile(sanitize_text_field(wp_unslash($_POST['mobile'] ?? '')));
        $code = preg_replace('/\D+/', '', (string) wp_unslash($_POST['code'] ?? ''));
        if (!$this->valid_mobile($mobile) || strlen($code) !== 6) {
            wp_send_json_error(['message'=>'شماره موبایل یا کد تأیید معتبر نیست.']);
        }

        $stored = get_transient('bimarstop_otp_' . md5($mobile));
        if (!$stored || !wp_hash_equals($stored, wp_hash($code . '|' . $mobile))) {
            wp_send_json_error(['message'=>'کد تأیید نادرست یا منقضی شده است.']);
        }

        $existing = get_users([
            'meta_key' => 'bimarstop_mobile',
            'meta_value' => $mobile,
            'number' => 1,
            'fields' => 'ID',
        ]);
        if ($existing) {
            delete_transient('bimarstop_otp_' . md5($mobile));
            wp_send_json_error(['message'=>'این شماره موبایل قبلاً ثبت‌نام شده است.']);
        }

        $login = 'mobile_' . substr($mobile, 1);
        $base_login = $login;
        $i = 1;
        while (username_exists($login)) $login = $base_login . '_' . $i++;

        $user_id = wp_create_user($login, wp_generate_password(32, true, true));
        if (is_wp_error($user_id)) wp_send_json_error(['message'=>'ایجاد حساب کاربری ناموفق بود.']);

        wp_update_user([
            'ID' => $user_id,
            'role' => 'bimarstop_patient',
            'display_name' => $mobile,
        ]);
        update_user_meta($user_id, 'bimarstop_mobile', $mobile);

        delete_transient('bimarstop_otp_' . md5($mobile));
        delete_transient('bimarstop_otp_rate_' . md5($mobile));

        wp_set_auth_cookie($user_id, true, is_ssl());
        wp_set_current_user($user_id);

        wp_send_json_success(['redirect'=>admin_url()]);
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

        $settings = wp_parse_args(get_option('bimarstop_settings', []), ['theme' => 'light-1', 'sms_api_key' => '', 'sms_line_number' => '']);
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

                <div class="card" style="max-width:900px;padding:24px;margin-top:20px">
                    <h2>📱 ثبت‌نام با پیامک</h2>
                    <p>ثبت‌نام بیماران فقط با شماره موبایل و کد تأیید پیامکی انجام می‌شود. ارسال کد از API سرویس SMS.ir استفاده می‌کند.</p>
                    <table class="form-table">
                        <tr>
                            <th><label for="bimarstop_sms_api_key">API Key</label></th>
                            <td><input type="password" id="bimarstop_sms_api_key" name="bimarstop_settings[sms_api_key]" value="<?php echo esc_attr($settings['sms_api_key']); ?>" class="regular-text" autocomplete="new-password"></td>
                        </tr>
                        <tr>
                            <th><label for="bimarstop_sms_line_number">شماره خط ارسال</label></th>
                            <td><input type="text" id="bimarstop_sms_line_number" name="bimarstop_settings[sms_line_number]" value="<?php echo esc_attr($settings['sms_line_number']); ?>" class="regular-text" placeholder="مثلاً 3000xxxx"></td>
                        </tr>
                    </table>
                    <p class="description">پیامک بدون قالب ارسال می‌شود و متن آن ثابت است: «سلام، به بیماراستاپ خوش آمدید / رمز شما: {code} میباشد. / #{code}»</p>
                </div>

                <?php submit_button('ذخیره تنظیمات'); ?>
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
