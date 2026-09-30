(function(){
'use strict';
if(!window.BimarStopNotifications)return;
var cfg=window.BimarStopNotifications, seen={};
try{seen=JSON.parse(sessionStorage.getItem('bimarstop_notified')||'{}');}catch(e){}
function remember(id){seen[id]=Date.now();try{sessionStorage.setItem('bimarstop_notified',JSON.stringify(seen));}catch(e){}}
function notify(item){
 if(!item||!item.id||seen[item.id])return;
 remember(item.id);
 if(!('serviceWorker' in navigator))return;
 navigator.serviceWorker.ready.then(function(reg){
   if(typeof reg.showNotification==='function'){
     reg.showNotification(item.title||'بیمار استاپ',{body:item.body||'پیام جدید دارید.',icon:'/wp-content/plugins/bimarstop/assets/icon-192.png',badge:'/wp-content/plugins/bimarstop/assets/icon-192.png',tag:'bimarstop-'+item.id,data:{url:item.url||window.location.href},dir:'rtl',lang:'fa'});
   }
 }).catch(function(){});
}
function poll(){
 var f=new FormData();f.append('action','bimarstop_get_notifications');f.append('nonce',cfg.nonce);
 fetch(cfg.ajax,{method:'POST',body:f,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(x){
   if(!x.success||!x.data)return;
   (x.data.notifications||[]).forEach(notify);
   var count=Number(x.data.count||0);
   document.querySelectorAll('[data-bimarstop-notification-count]').forEach(function(el){el.textContent=count?String(count):'';el.hidden=!count;});
 }).catch(function(){});
}
if('serviceWorker' in navigator){
 navigator.serviceWorker.register(cfg.sw).catch(function(){});
}
if('Notification' in window && Notification.permission==='default'){
 setTimeout(function(){Notification.requestPermission().catch(function(){});},2500);
}
poll();setInterval(poll,Number(cfg.interval)||5000);
})();
