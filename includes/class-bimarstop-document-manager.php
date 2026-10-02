<?php
namespace BimarStop;

if (!defined('ABSPATH')) exit;

final class DocumentManager {
    private static $instance = null;

    public static function instance(): self {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    public function __construct() {
        add_action('admin_init', [$this, 'ensure_tables']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);

        add_action('wp_ajax_bimarstop_docs_upload', [$this, 'ajax_upload']);
        add_action('wp_ajax_bimarstop_docs_folder', [$this, 'ajax_folder']);
        add_action('wp_ajax_bimarstop_docs_copy_main', [$this, 'ajax_copy_main']);
        add_action('wp_ajax_bimarstop_docs_move', [$this, 'ajax_move']);
        add_action('wp_ajax_bimarstop_docs_delete', [$this, 'ajax_delete']);
        add_action('wp_ajax_bimarstop_docs_download', [$this, 'ajax_download']);
        add_action('wp_ajax_bimarstop_docs_folder_delete', [$this, 'ajax_folder_delete']);
        add_action('wp_ajax_bimarstop_docs_folder_hide', [$this, 'ajax_folder_hide']);
    }

    private function role(): string {
        $u = wp_get_current_user();
        return !empty($u->roles) ? (string) $u->roles[0] : '';
    }

    private function allowed(): bool {
        return is_user_logged_in() && in_array($this->role(), [
            'bimarstop_patient',
            'bimarstop_doctor',
            'bimarstop_operator'
        ], true);
    }

    private function is_operator(): bool {
        return $this->role() === 'bimarstop_operator';
    }

    public function ensure_tables(): void {
        if (!is_user_logged_in()) return;

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $folders = $wpdb->prefix . 'bimarstop_personal_doc_folders';
        $docs = $wpdb->prefix . 'bimarstop_personal_docs';

        dbDelta("CREATE TABLE {$folders} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            owner_user_id bigint(20) unsigned NOT NULL,
            owner_role varchar(30) NOT NULL,
            name varchar(190) NOT NULL,
            parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            is_hidden tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY owner_user_id (owner_user_id),
            KEY parent_id (parent_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$docs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            owner_user_id bigint(20) unsigned NOT NULL,
            owner_role varchar(30) NOT NULL,
            folder_id bigint(20) unsigned NOT NULL DEFAULT 0,
            path text NOT NULL,
            source_path text NOT NULL,
            name varchar(255) NOT NULL,
            size bigint(20) unsigned NOT NULL DEFAULT 0,
            type varchar(100) NOT NULL DEFAULT 'application/octet-stream',
            is_reference tinyint(1) NOT NULL DEFAULT 0,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY owner_user_id (owner_user_id),
            KEY folder_id (folder_id),
            KEY is_reference (is_reference)
        ) {$charset};");

        foreach (get_users(['role' => 'bimarstop_doctor', 'fields' => 'ID']) as $id) {
            $this->ensure_owner_root((int) $id, 'bimarstop_doctor');
        }
        foreach (get_users(['role' => 'bimarstop_patient', 'fields' => 'ID']) as $id) {
            $this->ensure_owner_root((int) $id, 'bimarstop_patient');
        }

        // Rows created by the old document manager may point directly into uploads.
        // Treat those legacy rows as references so deleting a person-folder entry
        // can never accidentally delete the real uploads file.
        $wpdb->query("UPDATE {$docs} SET source_path=path, is_reference=1 WHERE (source_path IS NULL OR source_path='')");
    }

    private function ensure_owner_root(int $uid, string $role): int {
        global $wpdb;
        $table = $wpdb->prefix . 'bimarstop_personal_doc_folders';
        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE owner_user_id=%d AND owner_role=%s AND parent_id=0 LIMIT 1",
            $uid,
            $role
        ));

        if ($id) return $id;

        $name = $role === 'bimarstop_doctor' ? 'پوشه پزشک' : 'مدارک من';
        $wpdb->insert($table, [
            'owner_user_id' => $uid,
            'owner_role' => $role,
            'name' => $name,
            'parent_id' => 0,
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql')
        ], ['%d','%s','%s','%d','%d','%s']);

        return (int) $wpdb->insert_id;
    }

    public function assets(): void {
        $page = sanitize_key($_GET['page'] ?? '');
        if (!in_array($page, ['bimarstop-documents', 'bimarstop-patient-documents'], true)) return;

        wp_add_inline_style('bimarstop-admin-font', $this->css());
        wp_add_inline_script('jquery-core', $this->js());
    }

    private function css(): string {
        return <<<'CSS'
.bimar-docs-app{max-width:1250px;box-sizing:border-box;padding:10px 0 45px;overflow-x:hidden}
.bimar-docs-app *{box-sizing:border-box}
.bimar-docs-hero{background:linear-gradient(135deg,#0f3d91,#2563eb 55%,#38bdf8);color:#fff;border-radius:22px;padding:24px;margin-bottom:18px}
.bimar-docs-hero h1{margin:0 0 7px;font-size:27px}.bimar-docs-hero p{margin:0;opacity:.92}
.bimar-docs-section{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:16px;margin:16px 0;box-shadow:0 7px 24px rgba(15,23,42,.06)}
.bimar-docs-section>h2{margin:0 0 13px;font-size:21px}
.bimar-docs-toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
.bimar-docs-toolbar input{min-height:43px;padding:8px 11px;border:1px solid #cbd5e1;border-radius:10px;min-width:220px}
.bimar-docs-toolbar button,.bimar-doc-actions button,.bimar-doc-actions a{min-height:40px}
.bimar-main-tree{border:1px solid #dbe3ee;border-radius:14px;padding:10px;background:#f8fafc}
.bimar-main-tree details{margin:3px 0}
.bimar-main-tree summary{cursor:pointer;padding:9px;border-radius:9px;font-weight:700}
.bimar-main-tree summary:hover{background:#eef4ff}
.bimar-main-folder{padding-right:20px}
.bimar-main-file,.bimar-person-file{display:flex;align-items:center;gap:8px;flex-wrap:wrap;min-width:0;padding:9px 10px;margin:4px 0;border:1px solid #e2e8f0;border-radius:10px;background:#fff;cursor:grab}
.bimar-main-file:hover,.bimar-person-file:hover{border-color:#93c5fd}
.bimar-main-file strong,.bimar-person-file strong{overflow-wrap:anywhere;word-break:break-word}
.bimar-main-file small,.bimar-person-file small{color:#64748b}
.bimar-person-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.bimar-person-card{border:1px solid #dbe3ee;border-radius:14px;background:#fff;overflow:hidden;min-width:0}
.bimar-person-card.drop-over{outline:3px dashed #2563eb;background:#eff6ff}
.bimar-person-head{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:11px 12px;cursor:pointer}
.bimar-person-head strong{overflow-wrap:anywhere;word-break:break-word}
.bimar-person-head button{border:0;background:transparent;font-size:18px;cursor:pointer;min-width:38px;min-height:38px}
.bimar-person-body{padding:0 10px 10px}
.bimar-person-body.collapsed{display:none}
.bimar-person-upload{display:inline-flex;align-items:center;justify-content:center;gap:6px;width:100%;min-height:42px;margin:4px 0 8px;border:1px dashed #94a3b8;border-radius:10px;background:#f8fafc;cursor:pointer}
.bimar-person-upload:hover{background:#eff6ff}
.bimar-person-empty{text-align:center;color:#64748b;padding:13px 6px;font-size:13px}
.bimar-person-subfolder{border:1px solid #e2e8f0;border-radius:10px;margin-top:8px;padding:8px}.bimar-folder-toolbar,.bimar-folder-tools{display:flex;gap:6px;flex-wrap:wrap;margin:7px 0}.bimar-folder-toolbar .button,.bimar-folder-tools .button{min-height:36px}.bimar-folder-head{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}.bimar-folder-head summary{flex:1}.bimar-folder-tools{margin:0}.bimar-folder-tools button{cursor:pointer}
.bimar-person-subfolder summary{cursor:pointer;font-weight:700}
.bimar-person-actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px}
.bimar-person-actions button{min-height:36px}
.bimar-doc-delete-ref{border:1px solid #fecaca!important;color:#b91c1c!important;background:#fff!important}
@media(max-width:1000px){.bimar-person-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:600px){.bimar-docs-app{padding:4px 0 30px}.bimar-docs-hero{border-radius:15px;padding:19px}.bimar-docs-hero h1{font-size:22px}.bimar-docs-section{border-radius:15px;padding:12px}.bimar-docs-section>h2{font-size:19px}.bimar-person-grid{grid-template-columns:1fr}.bimar-main-file,.bimar-person-file{align-items:flex-start;flex-direction:column}.bimar-person-actions{width:100%}.bimar-person-actions>*{flex:1}}
CSS;
    }

    private function js(): string {
        $nonce=wp_create_nonce('bimarstop_personal_docs'); $ajax=admin_url('admin-ajax.php');
        return '(function(){if(window.__bimarDocsV4)return;window.__bimarDocsV4=1;window.BimarDocs={nonce:"'.esc_js($nonce).'",ajax:"'.esc_url_raw($ajax).'"};function call(a,d){var f=new FormData();f.append("action",a);f.append("nonce",BimarDocs.nonce);Object.keys(d||{}).forEach(function(k){f.append(k,d[k])});return fetch(BimarDocs.ajax,{method:"POST",body:f}).then(function(r){return r.json()})}function reload(){location.reload()}document.addEventListener("click",function(e){var t=e.target.closest("[data-person-toggle]");if(t){var b=document.querySelector("[data-person-body=\\\""+t.dataset.personToggle+"\\\"]");if(b){b.classList.toggle("collapsed");t.textContent=b.classList.contains("collapsed")?"＋":"−";localStorage.setItem("bimar-doc-person-"+t.dataset.personToggle,b.classList.contains("collapsed")?"1":"0")}return}var c=e.target.closest("[data-folder-create-owner]");if(c){var n=prompt("نام پوشه جدید:");if(n)call("bimarstop_docs_folder",{name:n,owner_id:c.dataset.folderCreateOwner,owner_role:c.dataset.folderCreateRole,parent_id:c.dataset.folderParent||0}).then(function(x){x.success?reload():alert(x.data.message)});return}var h=e.target.closest("[data-folder-hide]");if(h){call("bimarstop_docs_folder_hide",{folder_id:h.dataset.folderHide,hidden:h.dataset.hidden==="1"?0:1}).then(function(x){x.success?reload():alert(x.data.message)});return}var f=e.target.closest("[data-folder-delete]");if(f&&confirm("پوشه حذف شود؟ فقط پوشه خالی قابل حذف است."))call("bimarstop_docs_folder_delete",{folder_id:f.dataset.folderDelete}).then(function(x){x.success?reload():alert(x.data.message)});var d=e.target.closest("[data-doc-delete]");if(d&&confirm("این فایل فقط از پوشه فرد حذف شود؟"))call("bimarstop_docs_delete",{doc_id:d.dataset.docDelete}).then(function(x){x.success?reload():alert(x.data.message)})});document.addEventListener("change",function(e){var i=e.target;if(!i.matches("[data-doc-upload]")||!i.files.length)return;var f=new FormData();f.append("action","bimarstop_docs_upload");f.append("nonce",BimarDocs.nonce);f.append("folder_id",i.dataset.folder);[].forEach.call(i.files,function(x){f.append("files[]",x)});fetch(BimarDocs.ajax,{method:"POST",body:f}).then(function(r){return r.json()}).then(function(x){x.success?reload():alert(x.data.message)})});document.addEventListener("dragover",function(e){var t=e.target.closest("[data-drop-folder]");if(t){e.preventDefault();t.classList.add("drop-over")}});document.addEventListener("dragleave",function(e){var t=e.target.closest("[data-drop-folder]");if(t&&(!e.relatedTarget||!t.contains(e.relatedTarget)))t.classList.remove("drop-over")});document.addEventListener("drop",function(e){var t=e.target.closest("[data-drop-folder]");if(!t)return;e.preventDefault();t.classList.remove("drop-over");var id=t.dataset.dropFolder;if(e.dataTransfer.files&&e.dataTransfer.files.length){var f=new FormData();f.append("action","bimarstop_docs_upload");f.append("nonce",BimarDocs.nonce);f.append("folder_id",id);[].forEach.call(e.dataTransfer.files,function(x){f.append("files[]",x)});fetch(BimarDocs.ajax,{method:"POST",body:f}).then(function(r){return r.json()}).then(function(x){x.success?reload():alert(x.data.message)});return}var raw=window.__bimarDragged;if(!raw)return;window.__bimarDragged=null;call(raw.indexOf("main:")===0?"bimarstop_docs_copy_main":"bimarstop_docs_move",raw.indexOf("main:")===0?{path:raw.slice(5),folder_id:id}:{doc_id:raw.slice(4),folder_id:id}).then(function(x){x.success?reload():alert(x.data.message)})});document.addEventListener("dragstart",function(e){var m=e.target.closest("[data-main-drag]"),d=e.target.closest("[data-doc-drag]");if(m)window.__bimarDragged="main:"+m.dataset.mainDrag;else if(d)window.__bimarDragged="doc:"+d.dataset.docDrag});document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll("[data-person-body]").forEach(function(b){if(localStorage.getItem("bimar-doc-person-"+b.dataset.personBody)==="1"){b.classList.add("collapsed");var t=document.querySelector("[data-person-toggle=\\\""+b.dataset.personBody+"\\\"]");if(t)t.textContent="＋"}})})()';
    }
    private function check_nonce(): void {
        if (!$this->allowed()) wp_send_json_error(['message' => 'دسترسی غیرمجاز.'], 403);
        check_ajax_referer('bimarstop_personal_docs', 'nonce');
    }

    private function can_edit_owner(int $owner, string $role): bool {
        $currentRole = $this->role();
        if ($currentRole === 'bimarstop_operator') {
            return in_array($role, ['bimarstop_doctor', 'bimarstop_patient'], true);
        }
        return $owner === get_current_user_id() && $role === $currentRole;
    }

    private function folder_row(int $folderId) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bimarstop_personal_doc_folders WHERE id=%d",
            $folderId
        ));
    }

    private function valid_upload_path(string $path): bool {
        $uploads = wp_upload_dir();
        if (!empty($uploads['error']) || empty($uploads['basedir'])) return false;

        $base = realpath($uploads['basedir']);
        $real = realpath($path);
        return $base && $real && ($real === $base || strpos($real, $base . DIRECTORY_SEPARATOR) === 0) && is_file($real);
    }

    private function allowed_extension(string $name): bool {
        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), [
            'pdf','jpg','jpeg','png','webp','doc','docx'
        ], true);
    }

    public function ajax_folder(): void {
        $this->check_nonce();
        global $wpdb;

        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        if ($name === '') wp_send_json_error(['message' => 'نام پوشه را وارد کنید.']);

        $role = $this->role();
        $owner = get_current_user_id();
        if ($this->is_operator()) {
            $owner = absint($_POST['owner_id'] ?? 0);
            $role = sanitize_key($_POST['owner_role'] ?? '');
            if (!$owner || !in_array($role, ['bimarstop_doctor','bimarstop_patient'], true)) {
                wp_send_json_error(['message' => 'مالک پوشه نامعتبر است.']);
            }
        }

        if (!$this->can_edit_owner($owner, $role)) wp_send_json_error(['message' => 'ساخت پوشه مجاز نیست.']);

        $parent = absint($_POST['parent_id'] ?? 0);
        if ($parent) {
            $p = $this->folder_row($parent);
            if (!$p || (int)$p->owner_user_id !== $owner || $p->owner_role !== $role) {
                wp_send_json_error(['message' => 'پوشه مقصد نامعتبر است.']);
            }
        }

        $wpdb->insert($wpdb->prefix.'bimarstop_personal_doc_folders', [
            'owner_user_id' => $owner,
            'owner_role' => $role,
            'name' => $name,
            'parent_id' => $parent,
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql')
        ], ['%d','%s','%s','%d','%d','%s']);

        wp_send_json_success(['id' => (int)$wpdb->insert_id]);
    }

    public function ajax_upload(): void {
        $this->check_nonce();
        global $wpdb;

        $folderId = absint($_POST['folder_id'] ?? 0);
        $folder = $this->folder_row($folderId);
        if (!$folder || !$this->can_edit_owner((int)$folder->owner_user_id, $folder->owner_role)) {
            wp_send_json_error(['message' => 'آپلود در این پوشه مجاز نیست.']);
        }
        if (empty($_FILES['files'])) wp_send_json_error(['message' => 'فایلی انتخاب نشده است.']);

        require_once ABSPATH . 'wp-admin/includes/file.php';
        $allowed = [
            'pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg',
            'png'=>'image/png','webp'=>'image/webp','doc'=>'application/msword',
            'docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ];

        $files = $_FILES['files'];
        $count = is_array($files['name']) ? count($files['name']) : 0;
        $uploaded = 0;

        for ($i=0; $i<$count; $i++) {
            if ((int)$files['error'][$i] !== UPLOAD_ERR_OK) continue;
            if ((int)$files['size'][$i] > 10 * 1024 * 1024) continue;
            if (!$this->allowed_extension($files['name'][$i])) continue;

            $one = [
                'name' => $files['name'][$i],
                'type' => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error' => $files['error'][$i],
                'size' => $files['size'][$i]
            ];

            $check = wp_check_filetype_and_ext($one['tmp_name'], $one['name'], $allowed);
            if (empty($check['ext']) || !isset($allowed[$check['ext']])) continue;

            $up = wp_handle_upload($one, ['test_form' => false, 'mimes' => $allowed]);
            if (isset($up['error'])) continue;

            $name = sanitize_file_name($one['name']);
            $now = current_time('mysql');
            $wpdb->insert($wpdb->prefix.'bimarstop_personal_docs', [
                'owner_user_id' => (int)$folder->owner_user_id,
                'owner_role' => $folder->owner_role,
                'folder_id' => $folderId,
                'path' => $up['file'],
                'source_path' => $up['file'],
                'name' => $name,
                'size' => (int)$one['size'],
                'type' => $up['type'],
                'is_reference' => 0,
                'created_by' => get_current_user_id(),
                'created_at' => $now,
                'updated_at' => $now
            ], ['%d','%s','%d','%s','%s','%s','%d','%s','%d','%d','%s','%s']);

            $uploaded++;
        }

        if (!$uploaded) wp_send_json_error(['message' => 'هیچ فایل مجازی آپلود نشد.']);
        wp_send_json_success(['uploaded' => $uploaded]);
    }

    public function ajax_copy_main(): void {
        $this->check_nonce();
        if (!$this->is_operator()) wp_send_json_error(['message' => 'فقط اپراتور می‌تواند فایل اصلی را به پوشه افراد اضافه کند.'], 403);

        global $wpdb;
        $folderId = absint($_POST['folder_id'] ?? 0);
        $folder = $this->folder_row($folderId);
        $path = wp_unslash($_POST['path'] ?? '');

        if (!$folder || !$this->can_edit_owner((int)$folder->owner_user_id, $folder->owner_role)) {
            wp_send_json_error(['message' => 'پوشه مقصد نامعتبر است.']);
        }

        $uploads = wp_upload_dir();
        $base = realpath($uploads['basedir'] ?? '');
        $real = realpath($path);
        if (!$base || !$real || !is_file($real) || strpos($real, $base . DIRECTORY_SEPARATOR) !== 0) {
            wp_send_json_error(['message' => 'فایل اصلی نامعتبر است.']);
        }

        $name = sanitize_file_name(wp_basename($real));
        $size = (int) filesize($real);
        $type = wp_check_filetype($name)['type'] ?: 'application/octet-stream';
        $now = current_time('mysql');

        $exists = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}bimarstop_personal_docs WHERE owner_user_id=%d AND owner_role=%s AND folder_id=%d AND source_path=%s LIMIT 1",
            (int)$folder->owner_user_id,
            $folder->owner_role,
            $folderId,
            $real
        ));
        if ($exists) wp_send_json_success(['id' => $exists, 'already' => true]);

        $wpdb->insert($wpdb->prefix.'bimarstop_personal_docs', [
            'owner_user_id' => (int)$folder->owner_user_id,
            'owner_role' => $folder->owner_role,
            'folder_id' => $folderId,
            'path' => $real,
            'source_path' => $real,
            'name' => $name,
            'size' => $size,
            'type' => $type,
            'is_reference' => 1,
            'created_by' => get_current_user_id(),
            'created_at' => $now,
            'updated_at' => $now
        ], ['%d','%s','%d','%s','%s','%s','%d','%s','%d','%d','%s','%s']);

        wp_send_json_success(['id' => (int)$wpdb->insert_id, 'copied' => true]);
    }

    public function ajax_move(): void {
        $this->check_nonce();
        global $wpdb;

        $docId = absint($_POST['doc_id'] ?? 0);
        $folderId = absint($_POST['folder_id'] ?? 0);
        $doc = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bimarstop_personal_docs WHERE id=%d",
            $docId
        ));
        $folder = $this->folder_row($folderId);

        if (!$doc || !$folder || !$this->can_edit_owner((int)$doc->owner_user_id, $doc->owner_role)) {
            wp_send_json_error(['message' => 'جابه‌جایی مجاز نیست.']);
        }
        if ((int)$doc->owner_user_id !== (int)$folder->owner_user_id || $doc->owner_role !== $folder->owner_role) {
            wp_send_json_error(['message' => 'فایل فقط داخل پوشه‌های همان فرد قابل جابه‌جایی است.']);
        }

        $wpdb->update($wpdb->prefix.'bimarstop_personal_docs', [
            'folder_id' => $folderId,
            'updated_at' => current_time('mysql')
        ], ['id' => $docId], ['%d','%s'], ['%d']);

        wp_send_json_success();
    }

    public function ajax_delete(): void {
        $this->check_nonce();
        global $wpdb;

        $docId = absint($_POST['doc_id'] ?? 0);
        $doc = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bimarstop_personal_docs WHERE id=%d",
            $docId
        ));
        if (!$doc || !$this->can_edit_owner((int)$doc->owner_user_id, $doc->owner_role)) {
            wp_send_json_error(['message' => 'حذف مجاز نیست.']);
        }

        if (!(int)$doc->is_reference && is_file($doc->path)) {
            @unlink($doc->path);
        }

        $wpdb->delete($wpdb->prefix.'bimarstop_personal_docs', ['id' => $docId], ['%d']);
        wp_send_json_success();
    }

    public function ajax_download(): void {
        if (!$this->allowed() || !wp_verify_nonce(
            sanitize_text_field(wp_unslash($_GET['nonce'] ?? '')),
            'bimarstop_personal_download'
        )) {
            wp_die('دسترسی غیرمجاز', 403);
        }

        global $wpdb;
        $docId = absint($_GET['document_id'] ?? 0);
        $doc = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bimarstop_personal_docs WHERE id=%d",
            $docId
        ));
        if (!$doc || !is_file($doc->path)) wp_die('فایل پیدا نشد', 404);

        $role = $this->role();
        $uid = get_current_user_id();
        $ok = false;

        if ($role === 'bimarstop_operator') {
            $ok = in_array($doc->owner_role, ['bimarstop_patient','bimarstop_doctor'], true);
        } elseif ($role === $doc->owner_role && (int)$doc->owner_user_id === $uid) {
            $ok = true;
        }

        if (!$ok) wp_die('دسترسی غیرمجاز', 403);

        nocache_headers();
        header('Content-Type: ' . sanitize_text_field($doc->type));
        header('Content-Length: ' . filesize($doc->path));
        header('Content-Disposition: attachment; filename="' . str_replace('"','',wp_basename($doc->name)) . '"');
        readfile($doc->path);
        exit;
    }

    public function ajax_folder_delete(): void {
        $this->check_nonce(); if(!$this->is_operator()) wp_send_json_error(['message'=>'فقط اپراتور می‌تواند پوشه‌ها را مدیریت کند.'],403);
        global $wpdb; $id=absint($_POST['folder_id']??0); $f=$this->folder_row($id);
        if(!$f||!$this->can_edit_owner((int)$f->owner_user_id,$f->owner_role)) wp_send_json_error(['message'=>'دسترسی غیرمجاز.'],403);
        $hasDocs=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}bimarstop_personal_docs WHERE folder_id=%d",$id));
        $hasChildren=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}bimarstop_personal_doc_folders WHERE parent_id=%d",$id));
        if($hasDocs||$hasChildren) wp_send_json_error(['message'=>'این پوشه خالی نیست. ابتدا فایل‌ها و زیرپوشه‌ها را جابه‌جا یا حذف کنید.']);
        $wpdb->delete($wpdb->prefix.'bimarstop_personal_doc_folders',['id'=>$id],['%d']); wp_send_json_success();
    }

    public function ajax_folder_hide(): void {
        $this->check_nonce(); if(!$this->is_operator()) wp_send_json_error(['message'=>'فقط اپراتور می‌تواند پوشه‌ها را مدیریت کند.'],403);
        global $wpdb; $id=absint($_POST['folder_id']??0); $f=$this->folder_row($id);
        if(!$f||!$this->can_edit_owner((int)$f->owner_user_id,$f->owner_role)) wp_send_json_error(['message'=>'دسترسی غیرمجاز.'],403);
        $hidden=absint($_POST['hidden']??0)?1:0;
        $wpdb->update($wpdb->prefix.'bimarstop_personal_doc_folders',['is_hidden'=>$hidden],['id'=>$id],['%d'],['%d']); wp_send_json_success();
    }

    private function person_label($user): string {
        return $user->display_name ?: $user->user_login;
    }

    private function render_main_file(string $path, string $relative): void {
        if (!$this->valid_upload_path($path)) return;
        $name = wp_basename($path);
        echo '<div class="bimar-main-file" draggable="true" data-main-drag="' . esc_attr($path) . '">';
        echo '<span>📄</span><strong>' . esc_html($name) . '</strong>';
        echo '<small>' . esc_html(size_format((int) filesize($path))) . '</small>';
        echo '</div>';
    }

    private function render_main_tree(string $dir, string $relative=''): void {
        if (!is_dir($dir) || !is_readable($dir)) return;

        $items = @scandir($dir);
        if (!$items) return;

        $dirs = [];
        $files = [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $full = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($full)) $dirs[] = $item;
            elseif (is_file($full)) $files[] = $item;
        }

        natcasesort($dirs);
        natcasesort($files);

        foreach ($dirs as $name) {
            $full = $dir . DIRECTORY_SEPARATOR . $name;
            echo '<details class="bimar-main-folder" open><summary>📁 ' . esc_html($name) . '</summary>';
            $this->render_main_tree($full, trim($relative . '/' . $name, '/'));
            echo '</details>';
        }

        foreach ($files as $name) {
            $full = $dir . DIRECTORY_SEPARATOR . $name;
            $this->render_main_file($full, trim($relative . '/' . $name, '/'));
        }
    }

    private function render_person_files(int $owner, string $role, int $folderId): void {
        global $wpdb;
        $docs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bimarstop_personal_docs WHERE owner_user_id=%d AND owner_role=%s AND folder_id=%d ORDER BY name ASC",
            $owner,
            $role,
            $folderId
        ));

        if (!$docs) {
            echo '<div class="bimar-person-empty">این پوشه خالی است.</div>';
            return;
        }

        foreach ($docs as $doc) {
            $reference = (int)$doc->is_reference === 1;
            echo '<div class="bimar-person-file" draggable="true" data-doc-drag="' . (int)$doc->id . '">';
            echo '<span>' . ($reference ? '📎' : '📄') . '</span>';
            echo '<strong>' . esc_html($doc->name) . '</strong>';
            echo '<small>' . esc_html(size_format((int)$doc->size)) . ($reference ? ' · فایل اصلی' : '') . '</small>';
            echo '<div class="bimar-person-actions">';
            echo '<a class="button" target="_blank" href="' . esc_url(admin_url(
                'admin-ajax.php?action=bimarstop_docs_download&document_id=' . (int)$doc->id .
                '&nonce=' . wp_create_nonce('bimarstop_personal_download')
            )) . '">دانلود</a>';
            echo '<button type="button" class="button bimar-doc-delete-ref" data-doc-delete="' . (int)$doc->id . '">حذف از پوشه</button>';
            echo '</div></div>';
        }
    }

    private function render_person_folder_tree(int $owner, string $role, int $parentId, array $children): void {
        foreach (($children[$parentId] ?? []) as $folder) {
            if ((int)$folder->is_hidden) continue;
            $fid=(int)$folder->id;
            echo '<details class="bimar-person-subfolder" open data-drop-folder="'.$fid.'">';
            echo '<summary>📁 '.esc_html($folder->name).'</summary>';
            echo '<div class="bimar-folder-tools">';
            echo '<button type="button" class="button" data-folder-create-owner="'.$owner.'" data-folder-create-role="'.$role.'" data-folder-parent="'.$fid.'">📁 زیرپوشه جدید</button>';
            echo '<button type="button" class="button" data-folder-hide="'.$fid.'" data-hidden="1">🙈 مخفی</button>';
            echo '<button type="button" class="button" data-folder-delete="'.$fid.'">🗑 حذف پوشه</button>';
            echo '</div>';
            echo '<label class="bimar-person-upload">📤 افزودن فایل<input type="file" hidden multiple data-doc-upload data-folder="'.$fid.'" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx"></label>';
            $this->render_person_files($owner,$role,$fid);
            $this->render_person_folder_tree($owner,$role,$fid,$children);
            echo '</details>';
        }
    }

    private function render_person_card($user, string $role): void {
        $owner=(int)$user->ID; $root=$this->ensure_owner_root($owner,$role);
        global $wpdb;
        $folders=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bimarstop_personal_doc_folders WHERE owner_user_id=%d AND owner_role=%s ORDER BY parent_id ASC,name ASC",$owner,$role));
        $children=[];
        foreach($folders as $folder){$children[(int)$folder->parent_id][]=$folder;}
        $name=$this->person_label($user); $collapsed=$this->is_operator()?'1':'0';
        echo '<div class="bimar-person-card" data-drop-folder="'.$root.'">';
        echo '<div class="bimar-person-head"><strong>'.($role==='bimarstop_doctor'?'👨‍⚕️ ':'👤 ').esc_html($name).'</strong><button type="button" data-person-toggle="'.$root.'">'.($collapsed?'＋':'−').'</button></div>';
        echo '<div class="bimar-person-body'.($collapsed?' collapsed':'').'" data-person-body="'.$root.'">';
        echo '<div class="bimar-folder-toolbar">';
        echo '<button type="button" class="button button-primary" data-folder-create-owner="'.$owner.'" data-folder-create-role="'.$role.'" data-folder-parent="0">📁 پوشه جدید</button>';
        echo '<button type="button" class="button" data-folder-create-owner="'.$owner.'" data-folder-create-role="'.$role.'" data-folder-parent="'.$root.'">📁 زیرپوشه در پوشه اصلی</button>';
        echo '</div>';
        echo '<label class="bimar-person-upload">📤 فایل را انتخاب کنید<input type="file" hidden multiple data-doc-upload data-folder="'.$root.'" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx"></label>';
        $this->render_person_files($owner,$role,$root);
        $this->render_person_folder_tree($owner,$role,$root,$children);
        $hidden=array_filter($folders,function($f){return (int)$f->is_hidden===1;});
        if($hidden){
            echo '<details class="bimar-person-subfolder" style="margin-top:10px"><summary>🙈 پوشه‌های مخفی ('.count($hidden).')</summary>';
            echo '<div class="bimar-folder-tools">';
            foreach($hidden as $hf){
                echo '<span><button type="button" class="button" data-folder-hide="'.(int)$hf->id.'" data-hidden="1">👁 نمایش «'.esc_html($hf->name).'»</button></span>';
            }
            echo '</div></details>';
        }
        echo '</div></div>';
    }

    private function render_operator(): void {
        $uploads = wp_upload_dir();
        $doctors = get_users(['role'=>'bimarstop_doctor','orderby'=>'display_name','order'=>'ASC']);
        $patients = get_users(['role'=>'bimarstop_patient','orderby'=>'display_name','order'=>'ASC']);

        echo '<div class="bimar-docs-app" dir="rtl">';
        echo '<div class="bimar-docs-hero"><h1>📁 مدیریت فایل‌ها</h1><p>فایل‌های اصلی در یک بخش هستند و پوشه پزشکان و بیماران در بخش جداگانه.</p></div>';

        echo '<section class="bimar-docs-section">';
        echo '<h2>📂 فایل‌های اصلی</h2>';
        echo '<p>همه فایل‌های مجاز موجود در <code>wp-content/uploads</code>. کشیدن یک فایل به پوشه پزشک یا بیمار فقط نام و مسیر فایل را در آن پوشه ثبت می‌کند؛ خود فایل اصلی جابه‌جا یا کپی نمی‌شود.</p>';
        echo '<div class="bimar-main-tree">';
        if (!empty($uploads['basedir']) && is_dir($uploads['basedir'])) {
            $this->render_main_tree($uploads['basedir']);
        } else {
            echo '<div class="bimar-person-empty">پوشه uploads پیدا نشد.</div>';
        }
        echo '</div></section>';

        echo '<section class="bimar-docs-section">';
        echo '<h2>👨‍⚕️👤 فایل‌های پزشک و بیمار</h2>';
        echo '<p>پوشه‌ها به‌صورت کوچک نمایش داده می‌شوند و می‌توانید آن‌ها را باز یا مینیمایز کنید. فایل اصلی را مستقیم روی هر پوشه رها کنید تا فقط به آن پوشه اضافه شود.</p>';

        echo '<h2 style="margin-top:22px">👨‍⚕️ پزشکان</h2>';
        echo '<div class="bimar-person-grid">';
        foreach ($doctors as $doctor) $this->render_person_card($doctor, 'bimarstop_doctor');
        if (!$doctors) echo '<div class="bimar-person-empty">پزشکی ثبت نشده است.</div>';
        echo '</div>';

        echo '<h2 style="margin-top:28px">👤 بیماران</h2>';
        echo '<div class="bimar-person-grid">';
        foreach ($patients as $patient) $this->render_person_card($patient, 'bimarstop_patient');
        if (!$patients) echo '<div class="bimar-person-empty">بیماری ثبت نشده است.</div>';
        echo '</div></section>';

        echo '</div>';
    }

    private function render_patient(): void {
        $uid = get_current_user_id();
        $user = wp_get_current_user();

        echo '<div class="bimar-docs-app" dir="rtl">';
        echo '<div class="bimar-docs-hero"><h1>📁 مدارک من</h1><p>فایل‌های شخصی خودتان را مدیریت کنید.</p></div>';
        echo '<section class="bimar-docs-section"><h2>📁 پوشه من</h2>';
        $this->render_person_card($user, 'bimarstop_patient');
        echo '</section></div>';
    }

    private function render_doctor(): void {
        $user = wp_get_current_user();

        echo '<div class="bimar-docs-app" dir="rtl">';
        echo '<div class="bimar-docs-hero"><h1>🩺 مدارک پزشک</h1><p>فایل‌های اختصاصی خودتان را مدیریت کنید.</p></div>';
        echo '<section class="bimar-docs-section"><h2>📁 پوشه پزشک</h2>';
        $this->render_person_card($user, 'bimarstop_doctor');
        echo '</section></div>';
    }

    public function render_page(): void {
        if (!$this->allowed()) return;

        $role = $this->role();
        if ($role === 'bimarstop_patient') {
            $this->render_patient();
        } elseif ($role === 'bimarstop_doctor') {
            $this->render_doctor();
        } else {
            $this->render_operator();
        }
    }

    public function patient_folder_page(): void {
        if (!$this->allowed()) return;

        $patient = absint($_GET['patient'] ?? 0);
        if ($this->role() === 'bimarstop_patient') {
            $patient = get_current_user_id();
        }

        if (!$patient) return;
        $user = get_userdata($patient);
        if (!$user || !in_array('bimarstop_patient', (array)$user->roles, true)) return;

        if ($this->role() === 'bimarstop_doctor') {
            wp_die('این صفحه دیگر با اتصال کیس مدیریت نمی‌شود.', 403);
        }

        echo '<div class="bimar-docs-app" dir="rtl">';
        echo '<div class="bimar-docs-hero"><h1>👤 مدارک بیمار</h1><p>' . esc_html($this->person_label($user)) . '</p></div>';
        echo '<section class="bimar-docs-section"><h2>📁 پوشه بیمار</h2>';
        $this->render_person_card($user, 'bimarstop_patient');
        echo '</section></div>';
    }
}
