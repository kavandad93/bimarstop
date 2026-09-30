<?php
namespace BimarStop;

if (!defined('ABSPATH')) exit;

final class DocumentManager {
    private static $instance = null;
    public static function instance(): self { if (self::$instance === null) self::$instance = new self(); return self::$instance; }
    public function __construct() {
        add_action('admin_init', [$this, 'ensure_tables']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('wp_ajax_bimarstop_docs_list', [$this, 'ajax_list']);
        add_action('wp_ajax_bimarstop_docs_upload', [$this, 'ajax_upload']);
        add_action('wp_ajax_bimarstop_docs_folder', [$this, 'ajax_folder']);
        add_action('wp_ajax_bimarstop_docs_move', [$this, 'ajax_move']);
        add_action('wp_ajax_bimarstop_docs_rename', [$this, 'ajax_rename']);
        add_action('wp_ajax_bimarstop_docs_delete', [$this, 'ajax_delete']);
        add_action('wp_ajax_bimarstop_docs_download', [$this, 'ajax_download']);
        add_action('wp_ajax_bimarstop_docs_case', [$this, 'ajax_case']);
        add_action('wp_ajax_bimarstop_docs_collapse', [$this, 'ajax_noop']);
    }

    private function role(): string {
        $u = wp_get_current_user();
        return !empty($u->roles) ? (string)$u->roles[0] : '';
    }

    private function allowed(): bool {
        return is_user_logged_in() && in_array($this->role(), ['bimarstop_patient','bimarstop_doctor','bimarstop_operator'], true);
    }

    public function ensure_tables(): void {
        if (!is_user_logged_in()) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        $f = $wpdb->prefix . 'bimarstop_personal_doc_folders';
        $d = $wpdb->prefix . 'bimarstop_personal_docs';
        $cases = $wpdb->prefix . 'bimarstop_doctor_cases';

        dbDelta("CREATE TABLE {$f} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            owner_user_id bigint(20) unsigned NOT NULL,
            owner_role varchar(30) NOT NULL,
            name varchar(190) NOT NULL,
            parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY owner_user_id (owner_user_id),
            KEY parent_id (parent_id)
        ) {$c};");

        dbDelta("CREATE TABLE {$d} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            owner_user_id bigint(20) unsigned NOT NULL,
            owner_role varchar(30) NOT NULL,
            folder_id bigint(20) unsigned NOT NULL DEFAULT 0,
            path text NOT NULL,
            name varchar(255) NOT NULL,
            size bigint(20) unsigned NOT NULL DEFAULT 0,
            type varchar(100) NOT NULL DEFAULT 'application/octet-stream',
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY owner_user_id (owner_user_id),
            KEY folder_id (folder_id)
        ) {$c};");

        dbDelta("CREATE TABLE {$cases} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            doctor_id bigint(20) unsigned NOT NULL,
            patient_id bigint(20) unsigned NOT NULL,
            operator_id bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY doctor_patient (doctor_id,patient_id),
            KEY doctor_id (doctor_id),
            KEY patient_id (patient_id),
            KEY status (status)
        ) {$c};");

        foreach (get_users(['role'=>'bimarstop_doctor','fields'=>'ID']) as $id) $this->ensure_owner_root((int)$id,'bimarstop_doctor');
        foreach (get_users(['role'=>'bimarstop_patient','fields'=>'ID']) as $id) $this->ensure_owner_root((int)$id,'bimarstop_patient');
    }

    private function ensure_owner_root(int $uid, string $role): int {
        global $wpdb;
        $t=$wpdb->prefix.'bimarstop_personal_doc_folders';
        $id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE owner_user_id=%d AND owner_role=%s AND parent_id=0 LIMIT 1",$uid,$role));
        if($id) return $id;
        $name=$role==='bimarstop_doctor'?'پوشه پزشک':'مدارک من';
        $wpdb->insert($t,['owner_user_id'=>$uid,'owner_role'=>$role,'name'=>$name,'parent_id'=>0,'created_by'=>get_current_user_id(),'created_at'=>current_time('mysql')],['%d','%s','%s','%d','%d','%s']);
        return (int)$wpdb->insert_id;
    }

    public function assets(): void {
        $page=sanitize_key($_GET['page']??'');
        if (!in_array($page,['bimarstop-documents','bimarstop-patient-documents'],true)) return;
        wp_add_inline_style('bimarstop-admin-font', $this->css());
        wp_add_inline_script('jquery-core', $this->js());
    }

    private function css(): string {
        return '.bimar-docs-app{max-width:1180px;box-sizing:border-box;padding:10px 0 40px}.bimar-docs-app *{box-sizing:border-box}.bimar-docs-hero{background:linear-gradient(135deg,#0f3d91,#2563eb 55%,#38bdf8);color:#fff;border-radius:24px;padding:25px;margin-bottom:18px}.bimar-docs-hero h1{margin:0 0 7px;font-size:28px}.bimar-docs-hero p{margin:0;opacity:.9}.bimar-docs-section{background:#fff;border:1px solid #e5e7eb;border-radius:20px;padding:18px;margin:16px 0;box-shadow:0 8px 28px rgba(15,23,42,.06)}.bimar-docs-section h2{margin:0 0 14px}.bimar-docs-toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px}.bimar-docs-toolbar>*{max-width:100%}.bimar-docs-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px}.bimar-doc-folder,.bimar-doc-file{border:1px solid #dbe3ee;border-radius:16px;background:#f8fafc;padding:15px;min-width:0}.bimar-doc-folder{cursor:pointer}.bimar-doc-folder.drag-over{outline:3px dashed #2563eb;background:#eff6ff}.bimar-doc-folder-head{display:flex;align-items:center;justify-content:space-between;gap:8px}.bimar-doc-folder-title,.bimar-doc-file b{overflow-wrap:anywhere;word-break:break-word}.bimar-doc-folder-sub,.bimar-doc-meta{font-size:12px;color:#64748b;margin-top:7px}.bimar-doc-file{background:#fff}.bimar-doc-file[draggable=true]{cursor:grab}.bimar-doc-actions{display:flex;gap:7px;flex-wrap:wrap;margin-top:12px}.bimar-doc-actions button{min-height:40px}.bimar-doc-empty{text-align:center;padding:28px;color:#64748b}.bimar-doc-case{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:13px;border:1px solid #e5e7eb;border-radius:14px;margin:8px 0}.bimar-doc-case>*{min-width:0}.bimar-doc-collapse{border:0;background:transparent;font-size:20px;cursor:pointer}.bimar-doc-content.bimar-doc-collapsed{display:none}@media(max-width:600px){.bimar-docs-app{padding:4px 0 30px}.bimar-docs-hero{border-radius:16px;padding:20px}.bimar-docs-hero h1{font-size:22px}.bimar-docs-section{border-radius:16px;padding:13px}.bimar-docs-grid{grid-template-columns:1fr}.bimar-doc-case{align-items:flex-start;flex-direction:column}.bimar-docs-toolbar input,.bimar-docs-toolbar select,.bimar-docs-toolbar button{width:100%;min-height:46px}.bimar-doc-actions button{flex:1}}';
    }

    private function js(): string {
        $nonce=wp_create_nonce('bimarstop_personal_docs');
        $ajax=admin_url('admin-ajax.php');
        return '(function(){if(window.__bimarDocs)return;window.__bimarDocs=1;window.BimarDocs={nonce:"'.esc_js($nonce).'",ajax:"'.esc_url_raw($ajax).'"};function q(s){return document.querySelector(s)}function call(action,data){var f=new FormData();f.append("action",action);f.append("nonce",window.BimarDocs.nonce);Object.keys(data||{}).forEach(function(k){f.append(k,data[k])});return fetch(window.BimarDocs.ajax,{method:"POST",body:f}).then(function(r){return r.json()})}window.BimarDocs.call=call;document.addEventListener("click",function(e){var b=e.target.closest("[data-collapse-folder]");if(b){var id=b.getAttribute("data-collapse-folder"),el=q("[data-folder-body=\""+id+"\"]");if(el){el.classList.toggle("bimar-doc-collapsed");try{localStorage.setItem("bimar-doc-folder-"+id,el.classList.contains("bimar-doc-collapsed")?"1":"0")}catch(x){}}}var d=e.target.closest("[data-doc-delete]");if(d&&confirm("این فایل حذف شود؟"))call("bimarstop_docs_delete",{doc_id:d.dataset.docDelete}).then(function(x){if(x.success)location.reload();else alert(x.data&&x.data.message||"حذف ناموفق بود.")});var r=e.target.closest("[data-doc-rename]");if(r){var n=prompt("نام جدید فایل",r.dataset.docRename||"");if(n)call("bimarstop_docs_rename",{doc_id:r.dataset.docId,name:n}).then(function(x){if(x.success)location.reload();else alert(x.data&&x.data.message||"تغییر نام ناموفق بود.")})}});document.addEventListener("change",function(e){var input=e.target;if(input.matches("[data-doc-upload]")&&input.files.length){var fd=new FormData();fd.append("action","bimarstop_docs_upload");fd.append("nonce",window.BimarDocs.nonce);fd.append("folder_id",input.dataset.folder||0);Array.from(input.files).forEach(function(f){fd.append("files[]",f)});input.disabled=true;fetch(window.BimarDocs.ajax,{method:"POST",body:fd}).then(function(r){return r.json()}).then(function(x){if(!x.success)alert(x.data&&x.data.message||"آپلود ناموفق بود.");location.reload()}).catch(function(){alert("خطا در ارتباط با سرور.");location.reload()})}});document.addEventListener("dragover",function(e){var f=e.target.closest("[data-drop-folder]");if(f){e.preventDefault();f.classList.add("drag-over")}});document.addEventListener("dragleave",function(e){var f=e.target.closest("[data-drop-folder]");if(f)f.classList.remove("drag-over")});document.addEventListener("drop",function(e){var f=e.target.closest("[data-drop-folder]");if(!f||!window.__bimarDragged)return;e.preventDefault();f.classList.remove("drag-over");var x=window.__bimarDragged;window.__bimarDragged=null;call("bimarstop_docs_move",{doc_id:x,folder_id:f.dataset.dropFolder}).then(function(y){if(y.success)location.reload();else alert(y.data&&y.data.message||"جابه‌جایی ناموفق بود.")})});document.addEventListener("dragstart",function(e){var d=e.target.closest("[data-drag-doc]");if(d)window.__bimarDragged=d.dataset.dragDoc});document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll("[data-folder-body]").forEach(function(el){try{if(localStorage.getItem("bimar-doc-folder-"+el.dataset.folderBody)==="1")el.classList.add("bimar-doc-collapsed")}catch(x){}})})})();';
    }

    private function check_nonce(): void { if(!$this->allowed()) wp_send_json_error(['message'=>'دسترسی غیرمجاز.'],403); check_ajax_referer('bimarstop_personal_docs','nonce'); }

    private function can_edit_owner(int $owner, string $ownerRole): bool {
        $r=$this->role();
        if($r==='bimarstop_operator') return $ownerRole==='bimarstop_doctor' || $ownerRole==='bimarstop_patient';
        return $r===$ownerRole && $owner===get_current_user_id();
    }

    public function ajax_list(): void { $this->check_nonce(); wp_send_json_success(['ok'=>true]); }

    public function ajax_noop(): void { $this->check_nonce(); wp_send_json_success(); }

    public function ajax_folder(): void {
        $this->check_nonce(); global $wpdb;
        $name=sanitize_text_field(wp_unslash($_POST['name']??'')); $parent=absint($_POST['parent_id']??0);
        $r=$this->role(); $uid=get_current_user_id();
        if($name==='') wp_send_json_error(['message'=>'نام پوشه را وارد کنید.']);
        if($r==='bimarstop_operator'){ $owner=absint($_POST['owner_id']??0); $or=sanitize_key($_POST['owner_role']??''); if(!$owner||!in_array($or,['bimarstop_doctor','bimarstop_patient'],true)) wp_send_json_error(['message'=>'مالک پوشه نامعتبر است.']); }
        else { $owner=$uid; $or=$r; }
        if($parent){$p=$wpdb->get_row($wpdb->prepare("SELECT owner_user_id,owner_role FROM {$wpdb->prefix}bimarstop_personal_doc_folders WHERE id=%d",$parent));if(!$p||((int)$p->owner_user_id!==$owner)||$p->owner_role!==$or)wp_send_json_error(['message'=>'پوشه مقصد نامعتبر است.']);}
        $wpdb->insert($wpdb->prefix.'bimarstop_personal_doc_folders',['owner_user_id'=>$owner,'owner_role'=>$or,'name'=>$name,'parent_id'=>$parent,'created_by'=>$uid,'created_at'=>current_time('mysql')],['%d','%s','%s','%d','%d','%s']);
        wp_send_json_success(['id'=>(int)$wpdb->insert_id]);
    }

    public function ajax_upload(): void {
        $this->check_nonce(); global $wpdb;
        $r=$this->role(); $uid=get_current_user_id(); $folder=absint($_POST['folder_id']??0);
        if(!in_array($r,['bimarstop_patient','bimarstop_operator','bimarstop_doctor'],true)) wp_send_json_error(['message'=>'دسترسی غیرمجاز.']);
        $f=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bimarstop_personal_doc_folders WHERE id=%d",$folder));
        if(!$f) wp_send_json_error(['message'=>'پوشه پیدا نشد.']);
        if(!$this->can_edit_owner((int)$f->owner_user_id,$f->owner_role)) wp_send_json_error(['message'=>'شما اجازه آپلود در این پوشه را ندارید.']);
        if(empty($_FILES['files'])) wp_send_json_error(['message'=>'فایلی انتخاب نشده است.']);
        require_once ABSPATH.'wp-admin/includes/file.php';
        $allowed=['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','doc'=>'application/msword','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        $files=$_FILES['files']; $n=is_array($files['name'])?count($files['name']):0; $ok=0;
        for($i=0;$i<$n;$i++){if((int)$files['size'][$i]>10*1024*1024)continue;$one=['name'=>$files['name'][$i],'type'=>$files['type'][$i],'tmp_name'=>$files['tmp_name'][$i],'error'=>$files['error'][$i],'size'=>$files['size'][$i]];$check=wp_check_filetype_and_ext($one['tmp_name'],$one['name'],$allowed);if(empty($check['ext'])||!isset($allowed[$check['ext']]))continue;$up=wp_handle_upload($one,['test_form'=>false,'mimes'=>$allowed]);if(isset($up['error']))continue;$wpdb->insert($wpdb->prefix.'bimarstop_personal_docs',['owner_user_id'=>(int)$f->owner_user_id,'owner_role'=>$f->owner_role,'folder_id'=>$folder,'path'=>$up['file'],'name'=>sanitize_file_name($one['name']),'size'=>(int)$one['size'],'type'=>$up['type'],'created_by'=>$uid,'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')],['%d','%s','%d','%s','%s','%d','%s','%d','%s','%s']);$ok++;}
        if(!$ok)wp_send_json_error(['message'=>'هیچ فایل مجازی آپلود نشد.']);
        wp_send_json_success(['uploaded'=>$ok]);
    }

    public function ajax_move(): void {
        $this->check_nonce(); global $wpdb; $id=absint($_POST['doc_id']??0);$folder=absint($_POST['folder_id']??0);
        $d=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bimarstop_personal_docs WHERE id=%d",$id));$f=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bimarstop_personal_doc_folders WHERE id=%d",$folder));
        if(!$d||!$f||$d->owner_user_id!=$f->owner_user_id||$d->owner_role!==$f->owner_role||!$this->can_edit_owner((int)$d->owner_user_id,$d->owner_role))wp_send_json_error(['message'=>'جابه‌جایی مجاز نیست.']);
        $wpdb->update($wpdb->prefix.'bimarstop_personal_docs',['folder_id'=>$folder,'updated_at'=>current_time('mysql')],['id'=>$id],['%d','%s'],['%d']);wp_send_json_success();
    }

    public function ajax_rename(): void {
        $this->check_nonce(); global $wpdb;$id=absint($_POST['doc_id']??0);$name=sanitize_file_name(wp_unslash($_POST['name']??''));
        $d=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bimarstop_personal_docs WHERE id=%d",$id));
        if(!$d||$name===''||!$this->can_edit_owner((int)$d->owner_user_id,$d->owner_role))wp_send_json_error(['message'=>'تغییر نام مجاز نیست.']);
        $ext=pathinfo($d->name,PATHINFO_EXTENSION);if($ext&&!pathinfo($name,PATHINFO_EXTENSION))$name.='.'.$ext;
        $wpdb->update($wpdb->prefix.'bimarstop_personal_docs',['name'=>$name,'updated_at'=>current_time('mysql')],['id'=>$id],['%s','%s'],['%d']);wp_send_json_success();
    }

    public function ajax_download(): void {
        if(!$this->allowed() || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['nonce']??'')),'bimarstop_personal_download')) wp_die('دسترسی غیرمجاز',403);
        global $wpdb; $id=absint($_GET['document_id']??0);
        $d=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bimarstop_personal_docs WHERE id=%d",$id));
        if(!$d || !is_file($d->path)) wp_die('فایل پیدا نشد',404);
        $r=$this->role(); $uid=get_current_user_id(); $ok=false;
        if($r==='bimarstop_operator') $ok=in_array($d->owner_role,['bimarstop_patient','bimarstop_doctor'],true);
        elseif($r===$d->owner_role && (int)$d->owner_user_id===$uid) $ok=true;
        elseif($r==='bimarstop_doctor' && $d->owner_role==='bimarstop_patient'){
            $ok=(bool)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}bimarstop_doctor_cases WHERE doctor_id=%d AND patient_id=%d AND status='active'",$uid,(int)$d->owner_user_id));
        }
        if(!$ok) wp_die('دسترسی غیرمجاز',403);
        nocache_headers(); header('Content-Type: '.sanitize_text_field($d->type)); header('Content-Length: '.filesize($d->path)); header('Content-Disposition: attachment; filename="'.str_replace('"','',wp_basename($d->name)).'"'); readfile($d->path); exit;
    }

    public function ajax_delete(): void {
        $this->check_nonce(); global $wpdb;$id=absint($_POST['doc_id']??0);$d=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bimarstop_personal_docs WHERE id=%d",$id));
        if(!$d||!$this->can_edit_owner((int)$d->owner_user_id,$d->owner_role))wp_send_json_error(['message'=>'حذف مجاز نیست.']);
        if(is_file($d->path))@unlink($d->path);$wpdb->delete($wpdb->prefix.'bimarstop_personal_docs',['id'=>$id],['%d']);wp_send_json_success();
    }

    public function ajax_case(): void {
        $this->check_nonce(); if($this->role()!=='bimarstop_operator')wp_send_json_error(['message'=>'فقط اپراتور می‌تواند کیس بسازد.']);
        global $wpdb;$doctor=absint($_POST['doctor_id']??0);$patient=absint($_POST['patient_id']??0);
        if(!$doctor||!$patient||!get_user_by('id',$doctor)||!get_user_by('id',$patient))wp_send_json_error(['message'=>'پزشک یا بیمار نامعتبر است.']);
        $wpdb->replace($wpdb->prefix.'bimarstop_doctor_cases',['doctor_id'=>$doctor,'patient_id'=>$patient,'operator_id'=>get_current_user_id(),'status'=>'active','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')],['%d','%d','%d','%s','%s','%s']);wp_send_json_success();
    }

    public function render_page(): void {
        if(!$this->allowed())return;
        $r=$this->role();
        if($r==='bimarstop_patient') $this->render_patient();
        elseif($r==='bimarstop_doctor') $this->render_doctor();
        else $this->render_operator();
    }

    private function user_name(int $id): string { $u=get_userdata($id);return $u?($u->display_name?:$u->user_login):'—'; }

    private function render_folder(int $folder,array $docs,array $children,bool $editable): void {
        $f=$children[$folder]??[];$mydocs=[];foreach($docs as $d)if((int)$d->folder_id===$folder)$mydocs[]=$d;
        foreach($f as $x){$id=(int)$x->id;echo '<section class="bimar-doc-folder" data-drop-folder="'.$id.'"><div class="bimar-doc-folder-head"><strong class="bimar-doc-folder-title">📁 '.esc_html($x->name).'</strong><button class="bimar-doc-collapse" type="button" data-collapse-folder="'.$id.'" aria-label="جمع کردن پوشه">⌃</button></div><div class="bimar-doc-content" data-folder-body="'.$id.'"><div class="bimar-doc-folder-sub">پوشه قابل جابه‌جایی و مرتب‌سازی</div>';if($editable)echo '<label class="button" style="display:inline-flex;align-items:center;gap:6px;margin:8px 0;cursor:pointer">📤 افزودن فایل<input data-doc-upload data-folder="'.$id.'" type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx" hidden></label>';foreach($mydocs as $d)echo '<div class="bimar-doc-file" draggable="'.($editable?'true':'false').'" data-drag-doc="'.(int)$d->id.'"><b>📎 '.esc_html($d->name).'</b><div class="bimar-doc-meta">'.size_format((int)$d->size).'</div><div class="bimar-doc-actions"><a class="button" target="_blank" href="'.esc_url(admin_url('admin-ajax.php?action=bimarstop_docs_download&document_id='.(int)$d->id.'&nonce='.wp_create_nonce('bimarstop_personal_download'))).'">دانلود</a>'.(($editable && $this->role()!=='bimarstop_patient')?'<button type="button" class="button" data-doc-id="'.(int)$d->id.'" data-doc-rename="'.esc_attr(pathinfo($d->name,PATHINFO_FILENAME)).'">تغییر نام</button><button type="button" class="button" data-doc-delete="'.(int)$d->id.'">حذف</button>':'').'</div></div>';if(empty($mydocs)&&empty($children[$id]))echo '<div class="bimar-doc-empty">این پوشه خالی است.</div>'; $this->render_folder($id,$docs,$children,$editable);echo '</div></section>'; }
    }

    private function render_owner_section(int $owner,string $role,string $title,bool $editable): void {
        global $wpdb;$ft=$wpdb->prefix.'bimarstop_personal_doc_folders';$dt=$wpdb->prefix.'bimarstop_personal_docs';$root=$this->ensure_owner_root($owner,$role);
        $folders=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$ft} WHERE owner_user_id=%d AND owner_role=%s ORDER BY parent_id,name",$owner,$role));
        $docs=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$dt} WHERE owner_user_id=%d AND owner_role=%s ORDER BY name",$owner,$role));
        $children=[];foreach($folders as $f)$children[(int)$f->parent_id][]=$f;
        echo '<section class="bimar-docs-section"><div class="bimar-doc-folder-head"><h2>'.esc_html($title).'</h2><button class="bimar-doc-collapse" type="button" data-collapse-folder="'.$root.'">⌃</button></div><div class="bimar-doc-content" data-folder-body="'.$root.'">';
        if($editable)echo '<div class="bimar-docs-toolbar"><form onsubmit="event.preventDefault();BimarDocs.call(\'bimarstop_docs_folder\',{name:this.name.value,parent_id:'.$root.',owner_id:'.$owner.',owner_role:\''.esc_js($role).'\'}).then(function(x){if(x.success)location.reload();else alert(x.data.message)});return false;"><input name="name" required placeholder="نام پوشه جدید"><button class="button button-primary">➕ ساخت پوشه</button></form></div>';
        echo '<div data-drop-folder="'.$root.'">';
        if($editable)echo '<label class="button" style="display:inline-flex;align-items:center;gap:6px;margin:8px 0;cursor:pointer">📤 افزودن فایل<input data-doc-upload data-folder="'.$root.'" type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx" hidden></label>';
        foreach($docs as $d)if((int)$d->folder_id===$root)echo '<div class="bimar-doc-file" draggable="'.($editable?'true':'false').'" data-drag-doc="p'.(int)$d->id.'"><b>📎 '.esc_html($d->name).'</b><div class="bimar-doc-meta">'.size_format((int)$d->size).'</div><div class="bimar-doc-actions"><a class="button" target="_blank" href="'.esc_url(admin_url('admin-ajax.php?action=bimarstop_docs_download&document_id='.(int)$d->id.'&nonce='.wp_create_nonce('bimarstop_personal_download'))).'">دانلود</a>'.(($editable&&$this->role()!=='bimarstop_patient')?'<button type="button" class="button" data-doc-id="'.(int)$d->id.'" data-doc-rename="'.esc_attr(pathinfo($d->name,PATHINFO_FILENAME)).'">تغییر نام</button><button type="button" class="button" data-doc-delete="'.(int)$d->id.'">حذف</button>':'').'</div></div>';
        $this->render_folder($root,$docs,$children,$editable);echo '</div></div></section>';
    }

    private function render_patient(): void {
        $uid=get_current_user_id();echo '<div class="bimar-docs-app" dir="rtl"><div class="bimar-docs-hero"><h1>📁 مدارک من</h1><p>فقط مدارک خودتان را می‌بینید و می‌توانید آن‌ها را مرتب کنید.</p></div>';
        $this->render_owner_section($uid,'bimarstop_patient','مدارک شخصی من',true);echo '</div>';
    }

    private function render_doctor(): void {
        global $wpdb;$uid=get_current_user_id();echo '<div class="bimar-docs-app" dir="rtl"><div class="bimar-docs-hero"><h1>🩺 مدارک پزشک</h1><p>مدارک اختصاصی شما و مدارک کیس‌های فعلی در یک نمای ساده.</p></div>';
        $this->render_owner_section($uid,'bimarstop_doctor','پوشه پزشک من',false);
        $cases=$wpdb->get_results($wpdb->prepare("SELECT c.*,u.display_name AS patient_name FROM {$wpdb->prefix}bimarstop_doctor_cases c JOIN {$wpdb->users} u ON u.ID=c.patient_id WHERE c.doctor_id=%d AND c.status='active' ORDER BY c.updated_at DESC",$uid));
        echo '<section class="bimar-docs-section"><h2>👤 مدارک کیس‌های فعلی</h2>';
        if(!$cases)echo '<div class="bimar-doc-empty">هنوز کیسی برای شما ثبت نشده است.</div>';
        foreach($cases as $c){echo '<div class="bimar-doc-case"><strong>👤 '.esc_html($c->patient_name).'</strong><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=bimarstop-patient-documents&patient='.(int)$c->patient_id)).'">مشاهده مدارک بیمار</a></div>';}
        echo '</section></div>';
    }

    private function render_operator(): void {
        $doctors=get_users(['role'=>'bimarstop_doctor','orderby'=>'display_name','order'=>'ASC']);$patients=get_users(['role'=>'bimarstop_patient','orderby'=>'display_name','order'=>'ASC']);
        echo '<div class="bimar-docs-app" dir="rtl"><div class="bimar-docs-hero"><h1>📚 مدیریت مدارک</h1><p>مدارک بیماران و پزشکان را ساده و مرتب مدیریت کنید.</p></div>';
        echo '<section class="bimar-docs-section"><h2>📄 مدارک</h2><p>مدارک عمومی قدیمی BimarStop از این بخش قابل دسترسی هستند.</p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=bimarstop-documents&legacy=1')).'">باز کردن مدیریت مدارک عمومی</a></section>';
        foreach($doctors as $u)$this->render_owner_section((int)$u->ID,'bimarstop_doctor','👨‍⚕️ مدارک دکتر '.($u->display_name?:$u->user_login),true);
        echo '<section class="bimar-docs-section"><h2>👥 مدارک بیماران</h2>';
        foreach($patients as $u){echo '<div class="bimar-doc-case"><strong>👤 '.esc_html($u->display_name?:$u->user_login).'</strong><a class="button" href="'.esc_url(admin_url('admin.php?page=bimarstop-patient-documents&patient='.(int)$u->ID)).'">باز کردن پوشه</a></div>';}
        if(!$patients)echo '<div class="bimar-doc-empty">بیماری وجود ندارد.</div>';
        echo '</section>';
        echo '<section class="bimar-docs-section"><h2>🩺 کیس‌های پزشکان</h2><p>برای اینکه پزشک مدارک بیمار فعلی را ببیند، بیمار را به پزشک متصل کنید.</p><form class="bimar-docs-toolbar" onsubmit="event.preventDefault();BimarDocs.call(\'bimarstop_docs_case\',{doctor_id:this.doctor.value,patient_id:this.patient.value}).then(function(x){if(x.success)location.reload();else alert(x.data.message)});return false;"><select name="doctor" required><option value="">پزشک</option>';foreach($doctors as $u)echo '<option value="'.(int)$u->ID.'">'.esc_html($u->display_name?:$u->user_login).'</option>';echo '</select><select name="patient" required><option value="">بیمار</option>';foreach($patients as $u)echo '<option value="'.(int)$u->ID.'">'.esc_html($u->display_name?:$u->user_login).'</option>';echo '</select><button class="button button-primary">➕ ثبت کیس</button></form></section></div>';
    }

    public function patient_folder_page(): void {
        if($this->role()!=='bimarstop_operator' && $this->role()!=='bimarstop_doctor' && $this->role()!=='bimarstop_patient')return;
        $patient=absint($_GET['patient']??0);
        if($this->role()==='bimarstop_patient')$patient=get_current_user_id();
        if(!$patient)return;
        if($this->role()==='bimarstop_doctor'){
            global $wpdb;$ok=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}bimarstop_doctor_cases WHERE doctor_id=%d AND patient_id=%d AND status='active'",get_current_user_id(),$patient));
            if(!$ok)wp_die('دسترسی به مدارک این بیمار مجاز نیست.',403);
        }
        $u=get_userdata($patient);if(!$u)return;
        $this->render_owner_section($patient,'bimarstop_patient','مدارک '.($u->display_name?:$u->user_login),false);
    }
}
