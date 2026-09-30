<?php
/**
 * Plugin Name: BimarStop
 * Description: BimarStop theme system and WordPress page rendering foundation.
 * Version: 0.7.4
 * Author: BimarStop
 * License: Proprietary
 */

if (!defined('ABSPATH')) exit;

define('BIMARSTOP_VERSION', '0.7.4');
define('BIMARSTOP_FILE', __FILE__);
define('BIMARSTOP_DIR', plugin_dir_path(__FILE__));
define('BIMARSTOP_URL', plugin_dir_url(__FILE__));

require_once BIMARSTOP_DIR . 'includes/class-bimarstop.php';
require_once BIMARSTOP_DIR . 'includes/class-bimarstop-notifications.php';

function bimarstop_bootstrap() {
    \BimarStop\Plugin::instance();
    \BimarStop\Notifications::instance();
}
add_action('plugins_loaded', 'bimarstop_bootstrap');

function bimarstop_create_notification_files() {
    if (!current_user_can('activate_plugins')) return;

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

    $manifest_path = ABSPATH . 'bimarstop-manifest.json';
    if (!file_exists($manifest_path) || is_writable($manifest_path)) {
        @file_put_contents($manifest_path, wp_json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    $sw_path = ABSPATH . 'bimarstop-sw.js';
    $sw = <<<'JS'
(function(){
'use strict';
var ajax='/wp-admin/admin-ajax.php', nonce='';
self.addEventListener('message',function(e){if(e.data&&e.data.nonce)nonce=e.data.nonce;});
async function poll(){
 try{
  if(!nonce)return;
  var u=new URL(ajax,self.location.origin);
  u.searchParams.set('action','bimarstop_get_notifications');
  u.searchParams.set('nonce',nonce);
  var r=await fetch(u,{credentials:'include',cache:'no-store'}),j=await r.json();
  if(!j.success)return;
  for(const n of (j.data.notifications||[])){
   await self.registration.showNotification(n.title||'بیمار استاپ',{
    body:n.body||'پیام جدید دارید.',
    tag:'bimarstop-'+n.id,
    dir:'rtl',lang:'fa',
    data:{url:n.url||'/wp-admin/'}
   });
  }
 }catch(e){}
}
self.addEventListener('install',function(e){e.waitUntil(self.skipWaiting());});
self.addEventListener('activate',function(e){e.waitUntil(self.clients.claim());});
self.addEventListener('notificationclick',function(e){
 e.notification.close();
 var u=(e.notification.data&&e.notification.data.url)||'/wp-admin/';
 e.waitUntil(clients.matchAll({type:'window',includeUncontrolled:true}).then(function(list){
  for(var i=0;i<list.length;i++){if('focus' in list[i]){list[i].focus();list[i].navigate(u);return;}}
  return clients.openWindow(u);
 }));
});
})();
JS;
    if (!file_exists($sw_path) || is_writable($sw_path)) {
        @file_put_contents($sw_path, $sw);
    }
}
add_action('init', 'bimarstop_create_notification_files');

register_activation_hook(BIMARSTOP_FILE, ['BimarStop\\Plugin', 'activate']);
register_activation_hook(BIMARSTOP_FILE, 'bimarstop_create_notification_files');
register_deactivation_hook(BIMARSTOP_FILE, ['BimarStop\\Plugin', 'deactivate']);
