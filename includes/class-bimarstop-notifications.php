<?php
namespace BimarStop;
if (!defined('ABSPATH')) exit;
final class Notifications {
    private static ?self $instance = null;
    public static function instance(): self { return self::$instance ?? (self::$instance = new self()); }
    private function __construct() {
        add_action('wp_enqueue_scripts', [$this,'enqueue'], 110);
        add_action('admin_enqueue_scripts', [$this,'enqueue'], 110);
        add_action('wp_head', [$this,'manifest_link'], 5);
        add_action('admin_head', [$this,'manifest_link'], 5);
        add_action('wp_ajax_bimarstop_get_notifications', [$this,'ajax_get_notifications']);
        add_action('wp_ajax_bimarstop_private_get_messages', [$this,'mark_private_read'], 1);
        add_action('wp_ajax_bimarstop_get_messages', [$this,'mark_chat_read'], 1);
        add_action('wp_ajax_bimarstop_service_worker', [$this,'service_worker']);
    }
    public function manifest_link(): void {
        echo '<link rel="manifest" href="'.esc_url(BIMARSTOP_URL.'bimarstop-manifest.json').'">';
        echo '<meta name="theme-color" content="#2563eb">';
    }
    public function enqueue(): void {
        if (!is_user_logged_in()) return;
        wp_enqueue_script('bimarstop-notifications', BIMARSTOP_URL.'assets/bimarstop-notifications.js', [], BIMARSTOP_VERSION, true);
        wp_localize_script('bimarstop-notifications','BimarStopNotifications',[
            'ajax'=>admin_url('admin-ajax.php'),
            'nonce'=>wp_create_nonce('bimarstop_notifications'),
            'sw'=>admin_url('admin-ajax.php?action=bimarstop_service_worker&nonce='.wp_create_nonce('bimarstop_service_worker')),
            'interval'=>5000,
        ]);
    }
    private function role(int $id): string { $u=get_userdata($id); return ($u && !empty($u->roles))?(string)$u->roles[0]:''; }
    private function name(int $id): string { $u=get_userdata($id); return $u?($u->display_name?:$u->user_login):'کاربر'; }
    public function mark_private_read(): void {
        if(!is_user_logged_in()) return; global $wpdb; $uid=get_current_user_id(); $id=absint($_POST['thread_id']??0); if(!$id)return;
        $t=$wpdb->get_row($wpdb->prepare("SELECT user_a_id,user_b_id FROM {$wpdb->prefix}bimarstop_private_threads WHERE id=%d",$id));
        if($t && ((int)$t->user_a_id===$uid || (int)$t->user_b_id===$uid)) update_user_meta($uid,'bimarstop_private_seen_'.$id,current_time('mysql'));
    }
    public function mark_chat_read(): void {
        if(!is_user_logged_in()) return; global $wpdb; $uid=get_current_user_id(); $id=absint($_POST['thread_id']??0); if(!$id)return;
        $t=$wpdb->get_row($wpdb->prepare("SELECT patient_id,operator_id FROM {$wpdb->prefix}bimarstop_chat_threads WHERE id=%d",$id));
        if($t && ((int)$t->patient_id===$uid || (int)$t->operator_id===$uid)) update_user_meta($uid,'bimarstop_chat_seen_'.$id,current_time('mysql'));
    }
    public function ajax_get_notifications(): void {
        check_ajax_referer('bimarstop_notifications','nonce');
        if(!is_user_logged_in()) wp_send_json_error(['message'=>'وارد حساب شوید.'],401);
        global $wpdb; $uid=get_current_user_id(); $items=[];
        $rows=$wpdb->get_results($wpdb->prepare("SELECT m.id,m.thread_id,m.sender_id,m.created_at FROM {$wpdb->prefix}bimarstop_private_messages m JOIN {$wpdb->prefix}bimarstop_private_threads t ON t.id=m.thread_id WHERE m.sender_id<>%d AND (t.user_a_id=%d OR t.user_b_id=%d) ORDER BY m.id DESC LIMIT 30",$uid,$uid,$uid));
        foreach($rows as $m){
            $seen=(string)get_user_meta($uid,'bimarstop_private_seen_'.(int)$m->thread_id,true);
            if($seen!=='' && strtotime($m->created_at)<=strtotime($seen))continue;
            $items[]=['id'=>'p_'.(int)$m->id,'title'=>'پیام خصوصی جدید','body'=>'شما ۱ پیام خوانده‌نشده از '.$this->name((int)$m->sender_id).' دارید.','url'=>admin_url('admin.php?page=bimarstop-doctor-chats&user='.(int)$m->sender_id),'created'=>$m->created_at];
        }
        $threads=$wpdb->get_results($wpdb->prepare("SELECT t.id,(SELECT MAX(m.id) FROM {$wpdb->prefix}bimarstop_chat_messages m WHERE m.thread_id=t.id AND m.sender_id<>%d) AS last_id FROM {$wpdb->prefix}bimarstop_chat_threads t WHERE t.patient_id=%d OR t.operator_id=%d",$uid,$uid,$uid));
        foreach($threads as $t){
            if(!$t->last_id)continue; $m=$wpdb->get_row($wpdb->prepare("SELECT id,sender_id,created_at FROM {$wpdb->prefix}bimarstop_chat_messages WHERE id=%d",(int)$t->last_id)); if(!$m)continue;
            $seen=(string)get_user_meta($uid,'bimarstop_chat_seen_'.(int)$t->id,true); if($seen!=='' && strtotime($m->created_at)<=strtotime($seen))continue;
            $items[]=['id'=>'c_'.(int)$m->id,'title'=>'پیام جدید در چت','body'=>'شما ۱ پیام خوانده‌نشده از '.$this->name((int)$m->sender_id).' دارید.','url'=>admin_url('admin.php?page='.($this->role($uid)==='bimarstop_operator'?'bimarstop-chat&thread='.(int)$t->id:'bimarstop-chat')),'created'=>$m->created_at];
        }
        usort($items,static fn($a,$b)=>strcmp((string)$b['created'],(string)$a['created']));
        wp_send_json_success(['notifications'=>array_slice($items,0,20),'count'=>count($items)]);
    }
    public function service_worker(): void {
        if(!is_user_logged_in()){status_header(403);header('Content-Type: application/javascript; charset=utf-8');exit;}
        if(!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['nonce']??'')),'bimarstop_service_worker')){status_header(403);header('Content-Type: application/javascript; charset=utf-8');exit;}
        nocache_headers(); header('Content-Type: application/javascript; charset=utf-8');
        $nonce=wp_create_nonce('bimarstop_notifications');
        echo 'const BimarStopNonce='.wp_json_encode($nonce).';';
        echo "const P=5000;async function poll(){try{const u=new URL('/wp-admin/admin-ajax.php',self.location.origin);u.searchParams.set('action','bimarstop_get_notifications');u.searchParams.set('nonce',BimarStopNonce);const r=await fetch(u,{credentials:'include',cache:'no-store'}),j=await r.json();if(!j.success)return;const c=await caches.open('bimarstop-notifications-v1'),m=await c.match('/last');const last=m?parseInt(await m.text(),10)||0:0;let max=last;for(const n of (j.data.notifications||[]).slice().reverse()){const id=parseInt(String(n.id).replace(/\\D/g,''),10)||0;if(id<=last)continue;await self.registration.showNotification(n.title||'بیمار استاپ',{body:n.body||'پیام جدید دارید.',icon:'/wp-content/plugins/bimarstop/assets/icon-192.png',badge:'/wp-content/plugins/bimarstop/assets/icon-192.png',tag:'bimarstop-'+n.id,dir:'rtl',lang:'fa',data:{url:n.url||'/wp-admin/'}});if(id>max)max=id;}if(max>last)await c.put('/last',new Response(String(max)));}catch(e){}}self.addEventListener('install',e=>e.waitUntil(self.skipWaiting()));self.addEventListener('activate',e=>e.waitUntil(self.clients.claim().then(poll)));setInterval(poll,P);self.addEventListener('notificationclick',e=>{e.notification.close();const u=(e.notification.data&&e.notification.data.url)||'/wp-admin/';e.waitUntil(clients.openWindow?clients.openWindow(u):Promise.resolve());});poll();";
        exit;
    }
}
