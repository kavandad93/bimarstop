(function(){
"use strict";
window.BimarStopMarkdown={
  esc:function(v){var d=document.createElement("div");d.textContent=String(v==null?"":v);return d.innerHTML;},
  render:function(v){
    var s=this.esc(v).replace(/\r\n?/g,"\n"),codes=[];
    s=s.replace(/\`\`\`([\\s\\S]*?)\`\`\`/g,function(_,x){codes.push("<pre><code>"+x+"</code></pre>");return "\u0000C"+(codes.length-1)+"\u0000";});
    s=s.replace(/\`([^\`\n]+)\`/g,function(_,x){codes.push("<code>"+x+"</code>");return "\u0000C"+(codes.length-1)+"\u0000";});
    s=s.replace(/\[([^\]]+)\]\((https?:\\/\\/[^)\\s]+)\)/g,function(_,label,url){return '<a href="'+url.replace(/"/g,"&quot;")+'" target="_blank" rel="noopener noreferrer">'+label+"</a>";});
    s=s.replace(/^###### (.+)$/gm,"<h6>$1</h6>").replace(/^##### (.+)$/gm,"<h5>$1</h5>").replace(/^#### (.+)$/gm,"<h4>$1</h4>").replace(/^### (.+)$/gm,"<h3>$1</h3>").replace(/^## (.+)$/gm,"<h2>$1</h2>").replace(/^# (.+)$/gm,"<h1>$1</h1>");
    s=s.replace(/^> (.+)$/gm,"<blockquote>$1</blockquote>");
    s=s.replace(/^[-*] (.+)$/gm,"<li>$1</li>").replace(/(<li>.*<\/li>\n?)+/g,function(x){return "<ul>"+x+"</ul>";});
    s=s.replace(/^\d+\. (.+)$/gm,"<li>$1</li>").replace(/(<li>.*<\/li>\n?)+/g,function(x){return "<ol>"+x+"</ol>";});
    s=s.replace(/\*\*([^*\n]+)\*\*/g,"<strong>$1</strong>").replace(/__([^_\n]+)__/g,"<strong>$1</strong>").replace(/\*([^*\n]+)\*/g,"<em>$1</em>").replace(/_([^_\n]+)_/g,"<em>$1</em>").replace(/~~([^~\n]+)~~/g,"<del>$1</del>");
    s=s.split(/\n{2,}/).map(function(x){if(/^<(h[1-6]|ul|ol|blockquote|pre)/.test(x.trim()))return x;return "<p>"+x.replace(/\n/g,"<br>")+"</p>";}).join("");
    s=s.replace(/\u0000C(\d+)\u0000/g,function(_,i){return codes[Number(i)]||"";});
    return s;
  }
};
document.addEventListener("DOMContentLoaded",function(){
  var s=window.BimarStopSettings||{},t=s.theme||"light-1";
  document.body.classList.forEach(function(c){if(c.indexOf("bimarstop-light-")===0||c.indexOf("bimarstop-dark-")===0)document.body.classList.remove(c);});
  document.body.classList.add("bimarstop-"+t);
});
})();