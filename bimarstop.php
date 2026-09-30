<?php
/**
 * Plugin Name: BimarStop
 * Description: BimarStop theme system and WordPress page rendering foundation.
 * Version: 0.7.6
 * Author: BimarStop
 * License: Proprietary
 */

if (!defined('ABSPATH')) exit;

define('BIMARSTOP_VERSION', '0.7.6');
define('BIMARSTOP_FILE', __FILE__);
define('BIMARSTOP_DIR', plugin_dir_path(__FILE__));
define('BIMARSTOP_URL', plugin_dir_url(__FILE__));

require_once BIMARSTOP_DIR . 'includes/class-bimarstop.php';
require_once BIMARSTOP_DIR . 'includes/class-bimarstop-document-manager.php';
require_once BIMARSTOP_DIR . 'includes/class-bimarstop-notifications.php';

function bimarstop_bootstrap() {
    \BimarStop\Plugin::instance();
    \BimarStop\DocumentManager::instance();
    \BimarStop\Notifications::instance();
}
add_action('plugins_loaded', 'bimarstop_bootstrap');

function bimarstop_create_notification_files() {
    if (!defined('ABSPATH') || !is_dir(ABSPATH) || !is_writable(ABSPATH)) {
        return false;
    }

    $manifest = [
        'name' => 'بیمار استاپ',
        'short_name' => 'بیمار استاپ',
        'description' => 'سامانه مدیریت و ارتباط بیمار استاپ',
        'lang' => 'fa-IR',
        'dir' => 'rtl',
        'start_url' => '/wp-admin/',
        'scope' => '/wp-admin/',
        'display' => 'standalone',
        'background_color' => '#ffffff',
        'theme_color' => '#2563eb',
    ];

    $manifest_path = trailingslashit(ABSPATH) . 'bimarstop-manifest.json';
    if (!file_exists($manifest_path)) {
        @file_put_contents($manifest_path, wp_json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    $sw_path = trailingslashit(ABSPATH) . 'bimarstop-sw.js';
    if (!file_exists($sw_path)) {
        $sw = <<<'JS'
(function(){
'use strict';
var AJAX_URL='/wp-admin/admin-ajax.php', POLL_MS=5000, NONCE='', CACHE_NAME='bimarstop-notifications-v2';
self.addEventListener('message',function(e){
    if(e.data&&e.data.type==='bimarstop-init'&&e.data.nonce){NONCE=String(e.data.nonce);poll();}
});
async function poll(){
    try{
        if(!NONCE)return;
        var u=new URL(AJAX_URL,self.location.origin);
        u.searchParams.set('action','bimarstop_get_notifications');
        u.searchParams.set('nonce',NONCE);
        var r=await fetch(u.toString(),{credentials:'include',cache:'no-store'});
        var j=await r.json();
        if(!j.success)return;
        var c=await caches.open(CACHE_NAME);
        var old=await c.match('/last');
        var last=old?(parseInt(await old.text(),10)||0):0,max=last;
        var list=((j.data&&j.data.notifications)||[]).slice().reverse();
        for(var i=0;i<list.length;i++){
            var n=list[i], id=parseInt(String(n.id||'').replace(/\D/g,''),10)||0;
            if(id&&id<=last)continue;
            await self.registration.showNotification(n.title||'بیمار استاپ',{
                body:n.body||'پیام جدید دارید.',tag:'bimarstop-'+(n.id||Date.now()),dir:'rtl',lang:'fa',
                data:{url:n.url||'/wp-admin/'}
            });
            if(id>max)max=id;
        }
        if(max>last)await c.put('/last',new Response(String(max)));
    }catch(e){}
}
self.addEventListener('install',function(e){e.waitUntil(self.skipWaiting());});
self.addEventListener('activate',function(e){e.waitUntil(self.clients.claim());});
self.addEventListener('notificationclick',function(e){
    e.notification.close();
    var u=(e.notification.data&&e.notification.data.url)||'/wp-admin/';
    e.waitUntil(self.clients.matchAll({type:'window',includeUncontrolled:true}).then(function(list){
        for(var i=0;i<list.length;i++){
            if('focus' in list[i])return list[i].focus().then(function(){return list[i].navigate?list[i].navigate(u):undefined;});
        }
        return self.clients.openWindow(u);
    }));
});
setInterval(poll,POLL_MS);
})();
JS;
        @file_put_contents($sw_path, $sw, LOCK_EX);
    }

    return file_exists($manifest_path) && file_exists($sw_path);
}

register_activation_hook(BIMARSTOP_FILE, 'bimarstop_create_notification_files');

function bimarstop_ensure_notification_files() {
    if (current_user_can('manage_options')) bimarstop_create_notification_files();
}
add_action('admin_init', 'bimarstop_ensure_notification_files');

register_activation_hook(BIMARSTOP_FILE, ['BimarStop\\Plugin', 'activate']);
register_deactivation_hook(BIMARSTOP_FILE, ['BimarStop\\Plugin', 'deactivate']);
