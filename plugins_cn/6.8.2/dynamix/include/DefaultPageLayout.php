<?PHP
/* Copyright 2005-2020, Lime Technology
 * Copyright 2012-2020, Bergware International.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */
?>
<?
$display['font'] = $_COOKIE['fontSize'] ?? $display['font'];
$theme   = strtok($display['theme'],'-');
$header  = $display['header'];
$backgnd = $display['background'];
$themes1 = in_array($theme,['black','white']);
$themes2 = in_array($theme,['gray','azure']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title><?=$var['NAME']?>/<?=$myPage['name']?></title>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="format-detection" content="telephone=no">
<meta name="viewport" content="width=1440">
<meta name="robots" content="noindex, nofollow">
<meta http-equiv="Content-Security-Policy" content="block-all-mixed-content">
<link type="image/png" rel="shortcut icon" href="/webGui/images/<?=$var['mdColor']?>.png">
<link type="text/css" rel="stylesheet" href="<?autov("/webGui/styles/default-fonts.css")?>">
<link type="text/css" rel="stylesheet" href="<?autov("/webGui/styles/default-cases.css")?>">
<link type="text/css" rel="stylesheet" href="<?autov("/webGui/styles/font-awesome.css")?>">
<link type="text/css" rel="stylesheet" href="<?autov("/webGui/styles/context.standalone.css")?>">
<link type="text/css" rel="stylesheet" href="<?autov("/webGui/styles/jquery.sweetalert.css")?>">
<link type="text/css" rel="stylesheet" href="<?autov("/webGui/styles/default-{$display['theme']}.css")?>">
<link type="text/css" rel="stylesheet" href="<?autov("/webGui/styles/dynamix-{$display['theme']}.css")?>">

<style>

<?if ($display['font']):?>
html{font-size:<?=$display['font']?>}
<?endif;?>
<?if ($header):?>
#header,#header .logo,#header .text-right a{color:#<?=$header?>}
#header .block{background-color:transparent}
<?endif;?>
<?if ($backgnd):?>
#header{background-color:#<?=$backgnd?>}
<?if ($themes1):?>
#menu{background-color:#<?=$backgnd?>}
#nav-block #nav-item a{color:#<?=$header?>}
#nav-block #nav-item.active:after{background-color:#<?=$header?>}
<?endif;?>
<?endif;?>
.inline_help{display:none}
.upgrade_notice{position:fixed;top:1px;left:0;width:100%;height:40px;line-height:40px;color:#e68a00;background:#feefb3;border-bottom:#e68a00 1px solid;text-align:center;font-size:1.4rem;z-index:999}
.upgrade_notice i{margin:14px;float:right;cursor:pointer}
.back_to_top{display:none;position:fixed;bottom:30px;right:12px;color:#e22828;font-size:2.5rem;z-index:999}
<?
$safemode = strpos(file_get_contents('/proc/cmdline'),'unraidsafemode')!==false;
$tasks = find_pages('Tasks');
$buttons = find_pages('Buttons');
$banner = '/boot/config/plugins/dynamix/banner.png';
echo "#header.image{background-image:url(";
echo file_exists($banner) ? autov($banner) : '/webGui/images/banner.png';
echo ")}\n";
if ($themes2) {
  foreach ($tasks as $button) if ($button['Code']) echo "#nav-item a[href='/{$button['name']}']:before{content:'\\{$button['Code']}'}\n";
  foreach ($buttons as $button) if ($button['Code']) echo "#nav-item.{$button['name']} a:before{content:'\\{$button['Code']}'}\n";
}
$notes = '/var/tmp/unRAIDServer.txt';
if (!file_exists($notes)) file_put_contents($notes,shell_exec("$docroot/plugins/dynamix.plugin.manager/scripts/plugin changes $docroot/plugins/unRAIDServer/unRAIDServer.plg"));
$notes = "&nbsp;<a href='#' title='查看发行说明' onclick=\"openBox('/plugins/dynamix.plugin.manager/include/ShowChanges.php?tmp=1&file=$notes','发行说明',600,900);return false\"><span class='fa fa-info-circle fa-fw'></span></a>"
?>
</style>

<script src="<?autov('/webGui/javascript/dynamix.js')?>"></script>
<script>
Shadowbox.init({skipSetup:true});

// server uptime
var uptime = <?=strtok(exec("cat /proc/uptime"),' ')?>;
var expiretime = <?=$var['regTy']=='Trial'||strstr($var['regTy'],'expired')?$var['regTm2']:0?>;
var before = new Date();

// page timer events
var timers = {};

function pauseEvents(id){
  $.each(timers, function(i, timer) {
    if (!id || i==id) clearTimeout(timer);
  });
}
function resumeEvents(id,delay){
  var startDelay = delay||50;
  $.each(timers, function(i, timer) {
    if (!id || i==id) timers[i] = setTimeout(i+'()', startDelay);
    startDelay += 50;
  });
}
function plus(value, label, last) {
  return value>0 ? (value+' '+label+(value!=1?'':'')+(last?'':', ')) : '';
}
function updateTime() {
  var now = new Date();
  var days = parseInt(uptime/86400);
  var hour = parseInt(uptime/3600%24);
  var mins = parseInt(uptime/60%60);
  $('span.uptime').html(((days|hour|mins)?plus(days,'天',(hour|mins)==0)+plus(hour,'小时',mins==0)+plus(mins,'分钟',true):'不到一分钟'));
  uptime += Math.round((now.getTime() - before.getTime())/1000);
  before = now;
  if (expiretime > 0) {
    var remainingtime = expiretime - now.getTime()/1000;
    if (remainingtime > 0) {
      days = parseInt(remainingtime/86400);
      hour = parseInt(remainingtime/3600%24);
      mins = parseInt(remainingtime/60%60);
      if (days) {
        $('#licenseexpire').html(plus(days,'天',true)+' 剩余');
      } else if (hour) {
        $('#licenseexpire').html(plus(hour,'小时',true)+' 剩余').addClass('orange-text');
      } else if (mins) {
        $('#licenseexpire').html(plus(mins,'分钟',true)+' 剩余').addClass('red-text');
      } else {
        $('#licenseexpire').html('还剩不到一分钟').addClass('red-text');
      }
    } else {
      $('#licenseexpire').addClass('red-text');
    }
  }
  setTimeout(updateTime,1000);
}
function refresh(top) {
  if (typeof top === 'undefined') {
    for (var i=0,element; element=document.querySelectorAll('input,button,select')[i]; i++) { element.disabled = true; }
    for (var i=0,link; link=document.getElementsByTagName('a')[i]; i++) { link.style.color = "gray"; } //fake disable
    location = location;
  } else {
    $.cookie('top',top,{path:'/'});
    location = location;
  }
}
function initab() {
  $.removeCookie('one',{path:'/'});
  $.removeCookie('tab',{path:'/'});
}
function settab(tab) {
<?switch ($myPage['name']):?>
<?case'Main':?>
  $.cookie('tab',tab,{path:'/'});
<?if ($var['fsState']=='Started'):?>
  $.cookie('one','tab1',{path:'/'});
<?endif;?>
<?break;?>
<?case'Cache':case'Data':case'Flash':case'Parity':?>
  $.cookie('one',tab,{path:'/'});
<?break;?>
<?default:?>
  $.cookie(($.cookie('one')==null?'tab':'one'),tab,{path:'/'});
<?endswitch;?>
}
function done(key) {
  var url = location.pathname.split('/');
  var path = '/'+url[1];
  if (key) for (var i=2; i<url.length; i++) if (url[i]==key) break; else path += '/'+url[i];
  $.removeCookie('one',{path:'/'});
  location.replace(path);
}
function chkDelete(form, button) {
  button.value = form.confirmDelete.checked ? 'Delete' : 'Apply';
  button.disabled = false;
}
function openBox(cmd,title,height,width,load,func,id) {
  // open shadowbox window (run in foreground)
  var run = cmd.split('?')[0].substr(-4)=='.php' ? cmd : '/logging.htm?cmd='+cmd+'&csrf_token=<?=$var['csrf_token']?>';
  var options = load ? (func ? {modal:true,onClose:function(){setTimeout(func+'('+'"'+(id||'')+'")',0);}} : {modal:true,onClose:function(){location=location;}}) : {modal:false};
  Shadowbox.open({content:run, player:'iframe', title:title, height:Math.min(height,screen.availHeight), width:Math.min(width,screen.availWidth), options:options});
}
function openWindow(cmd,title,height,width) {
  // open regular window (run in background)
  var window_name = title.replace(/ /g,"_");
  var form_html =
  '<form action="/logging.htm" method="post" target="' + window_name + '">' +
  '<input type="hidden" name="csrf_token" value="<?=$var['csrf_token']?>" />' +
  '<input type="hidden" name="title" value="' + title + '" />';
  var vars = cmd.split('&');
  form_html += '<input type="hidden" name="cmd" value="' + vars[0] + '" />';
  for (var i = 1; i < vars.length; i++) {
    var pair = vars[i].split('=');
    form_html += '<input type="hidden" name="' + pair[0] + '" value="' + pair[1] + '" />';
  }
  form_html += '</form>';
  var form = $(form_html);
  $('body').append(form);
  var top = (screen.availHeight-height)/2;
  if (top < 0) {top = 0; height = screen.availHeight;}
  var left = (screen.availWidth-width)/2;
  if (left < 0) {left = 0; width = screen.availWidth;}
  var options = 'resizeable=yes,scrollbars=yes,height='+height+',width='+width+',top='+top+',left='+left;
  window.open('', window_name, options);
  form.submit();
}
function showStatus(name,plugin,job) {
  $.post('/webGui/include/ProcessStatus.php',{name:name,plugin:plugin,job:job},function(status){$(".tabs").append(status);});
}
function showFooter(data, id) {
  if (id !== undefined) $('#'+id).remove();
  $('#copyright').prepend(data);
}
function showNotice(data) {
  $('#user-notice').html(data.replace(/<a>(.*)<\/a>/,"<a href='/Plugins'>$1</a>"));
}

// Banner warning system

var bannerWarnings = [];
var currentBannerWarning = 0;
var bannerWarningInterval = false;
var osUpgradeWarning = false;

function addBannerWarning(text,warning=true,noDismiss=false) {
  var cookieText = text.replace(/[^a-z0-9]/gi,'');
  if ( $.cookie(cookieText) == "true" ) { return false; }
  
  if ( warning ) {
    text = "<i class='fa fa-warning' style='float:initial;'></i> "+text;
  }
  var arrayEntry = bannerWarnings.push("placeholder") - 1;
  if ( ! noDismiss ) {
    text = text + "<a class='bannerDismiss' onclick='dismissBannerWarning("+arrayEntry+",&quot;"+cookieText+"&quot;)'></a>";
  }
  bannerWarnings[arrayEntry] = text;
  if ( ! bannerWarningInterval ) {
    showBannerWarnings();
    bannerWarningInterval = setInterval(function() {
      showBannerWarnings()
    },10000);
  }
  return arrayEntry;
}

function dismissBannerWarning(entry,cookieText) {
  $.cookie(cookieText,"true");
  removeBannerWarning(entry);
}

function removeBannerWarning(entry) {
  bannerWarnings[entry] = false;
  showBannerWarnings();
}

function bannerFilterArray(array) {
  var newArray = [];
  array.filter(function(value,index,arr) {
    if ( value ) {
      newArray.push(value);
    }
  }); 
  return newArray;
}

function showBannerWarnings() {
  var allWarnings = bannerFilterArray(Object.values(bannerWarnings));
  if ( allWarnings.length == 0 ) {
    $(".upgrade_notice").hide();
    clearInterval(bannerWarningInterval);
    bannerWarningInterval = false;
    return;
  }
  if ( currentBannerWarning >= allWarnings.length ) {
    currentBannerWarning = 0;
  }
  $(".upgrade_notice").show().html(allWarnings[currentBannerWarning]);
  currentBannerWarning++;
}

function showUpgrade(data,noDismiss=false) {
  if ($.cookie('os_upgrade')==null) {
    if (osUpgradeWarning)
      removeBannerWarning(osUpgradeWarning);
    osUpgradeWarning = addBannerWarning(data.replace(/<a>(.*)<\/a>/,"<a href='#' onclick='hideUpgrade();openUpgrade();'>$1</a>"),false,noDismiss);
  }
}
function hideUpgrade(set) {
  removeBannerWarning(osUpgradeWarning);
  if (set)
    $.cookie('os_upgrade','true',{path:'/'});
  else
    $.removeCookie('os_upgrade',{path:'/'});
}
function openUpgrade() {
  swal({title:'更新 Unraid OS',text:'您要更新到新版本吗?',type:'warning',showCancelButton:true},function(){
    openBox('/plugins/dynamix.plugin.manager/scripts/plugin&arg1=update&arg2=unRAIDServer.plg','Update Unraid OS',600,900,true);
  });
}
function notifier() {
  var tub1 = 0, tub2 = 0, tub3 = 0;
  $.post('/webGui/include/Notify.php',{cmd:'get'},function(json) {
    var data = $.parseJSON(json);
    $.each(data, function(i, notify) {
<?if ($notify['display']):?>
      switch (notify.importance) {
        case 'alert'  : tub1++; break;
        case 'warning': tub2++; break;
        case 'normal' : tub3++; break;
      }
<?else:?>
      $.jGrowl(notify.subject+'<br>'+notify.description, {
        group: notify.importance,
        header: notify.event+': '+notify.timestamp,
        theme: notify.file,
        beforeOpen: function(e,m,o){if ($('div.jGrowl-notify').hasClass(notify.file)) return(false);},
        beforeClose: function(e,m,o){$.post('/webGui/include/Notify.php',{cmd:'archive',file:notify.file});}
      });
<?endif;?>
    });
<?if ($notify['display']):?>
    $('#txt-tub1').removeClass('one two three').addClass(digits(tub1)).text(tub1);
    $('#txt-tub2').removeClass('one two three').addClass(digits(tub2)).text(tub2);
    $('#txt-tub3').removeClass('one two three').addClass(digits(tub3)).text(tub3);
    if (tub1) $('#box-tub1').removeClass('grey-text').addClass('red-text'); else $('#box-tub1').removeClass('red-text').addClass('grey-text');
    if (tub2) $('#box-tub2').removeClass('grey-text').addClass('orange-text'); else $('#box-tub2').removeClass('orange-text').addClass('grey-text');
    if (tub3) $('#box-tub3').removeClass('grey-text').addClass('green-text'); else $('#box-tub3').removeClass('green-text').addClass('grey-text');
<?endif;?>
    timers.notifier = setTimeout(notifier,5000);
  });
}
function digits(number) {
  if (number < 10) return 'one';
  if (number < 100) return 'two';
  return 'three';
}
function openNotifier(filter) {
  $.post('/webGui/include/Notify.php',{cmd:'get'},function(json) {
    var data = $.parseJSON(json);
    $.each(data, function(i, notify) {
      if (notify.importance == filter) {
        $.jGrowl(notify.subject+'<br>'+notify.description, {
          group: notify.importance,
          header: notify.event+': '+notify.timestamp,
          theme: notify.file,
          beforeOpen: function(e,m,o){if ($('div.jGrowl-notify').hasClass(notify.file)) return(false);},
          beforeClose: function(e,m,o){$.post('/webGui/include/Notify.php',{cmd:'archive',file:notify.file});}
        });
      }
    });
  });
}
function closeNotifier(filter) {
  clearTimeout(timers.notifier);
  $.post('/webGui/include/Notify.php',{cmd:'get'},function(json) {
    var data = $.parseJSON(json);
    $.each(data, function(i, notify) {
      if (notify.importance == filter) $.post('/webGui/include/Notify.php',{cmd:'archive',file:notify.file});
    });
    $('div.jGrowl').find('.'+filter).find('div.jGrowl-close').trigger('click');
    setTimeout(notifier,100);
  });
}
function viewHistory(filter) {
  location.replace('/Tools/NotificationsArchive?filter='+filter);
}
$(function() {
  var tab = $.cookie('one')||$.cookie('tab')||'tab1';
  if (tab=='tab0') tab = 'tab'+$('input[name$="tabs"]').length; else if ($('#'+tab).length==0) {initab(); tab = 'tab1';}
  if ($.cookie('help')=='help') {$('.inline_help').show(); $('#nav-item.HelpButton').addClass('active');}
  $('#'+tab).attr('checked', true);
  updateTime();
  $.jGrowl.defaults.closeTemplate = '<i class="fa fa-close"></i>';
  $.jGrowl.defaults.closerTemplate = '<?=$notify['position'][0]=='b' ? '<div>':'<div class="top">'?> [关闭所有通知] </div>';
  $.jGrowl.defaults.sticky = true;
  $.jGrowl.defaults.check = 100;
  $.jGrowl.defaults.position = '<?=$notify['position']?>';
  $.jGrowl.defaults.themeState = '';
  Shadowbox.setup('a.sb-enable', {modal:true});
});
var mobiles=['ipad','iphone','ipod','android'];
var device=navigator.platform.toLowerCase();
for (var i=0,mobile; mobile=mobiles[i]; i++) {
  if (device.indexOf(mobile)>=0) {$('#footer').css('position','static'); break;}
}
$.ajaxPrefilter(function(s, orig, xhr){
  if (s.type.toLowerCase() == "post" && !s.crossDomain) {
    s.data = s.data || "";
    s.data += s.data?"&":"";
    s.data += "csrf_token=<?=$var['csrf_token']?>";
  }
});
</script>
</head>
<body>
 <div id="template">
  <div class="upgrade_notice" style="display:none"></div>
  <div id="header" class="<?=$display['banner']?>">
   <div class="logo">
   <a href="https://unraid.net" target="_blank"><?readfile("$docroot/webGui/images/UN-logotype-gradient.svg")?></a>
   版本: <?=$var['version']?><?=$notes?>
   </div>
   <div class="block">
    <span class="text-left">服务器<br/>描述<br/>注册版本<br/>运行时间</span>
    <span class="text-right"><?=$var['NAME']." &bullet; ".$eth0['IPADDR:0']?><br/><?=$var['COMMENT']?><br/>
    <a href="/Tools/Registration" title="转到注册页面">Unraid OS <span id="licensetype"><?=$var['regTy']?></span><span id="licenseexpire"></span></a><br/>
    <span class="uptime"></span></span>
   </div>
  </div>
  <a href="#" class="back_to_top" title="回到顶部"><i class="fa fa-arrow-circle-up"></i></a>
<?
// Build page menus
echo "<div id='menu'><div id='nav-block'><div id='nav-left'>";
foreach ($tasks as $button) {
  $page = $button['name'];
  echo "<div id='nav-item'";
  echo $task==$page ? " class='active'>" : ">";
  echo "<a href='/$page' onclick='initab()'>$page</a></div>";
}
unset($tasks);
if ($display['usage']) my_usage();
echo "</div>";
echo "<div id='nav-right'>";
foreach ($buttons as $button) {
  eval("?>{$button['text']}");
  if (empty($button['Link'])) {
    $icon = $button['Icon'];
    if (substr($icon,-4)=='.png') {
      $icon = "<img src='/{$button['root']}/icons/$icon' class='system'>";
    } elseif (substr($icon,0,5)=='icon-') {
      $icon = "<i class='$icon system'></i>";
    } else {
      if (substr($icon,0,3)!='fa-') $icon = "fa-$icon";
      $icon = "<i class='fa $icon system'></i>";
    }
    $title = $themes2 ? "" : " title='{$button['Title']}'";
    echo "<div id='nav-item' class='{$button['name']} util'><a href='".($button['Href'] ?? '#')."' onclick='{$button['name']}();return false;'{$title}>$icon<span>{$button['Title']}</span></a></div>";
  } else
    echo "<div id='{$button['Link']}'></div>";
}
unset($buttons,$button);
if ($notify['display']) {
  echo "<span id='nav-tub1' class='tub'><i id='box-tub1' class='fa fa-square grey-text'></i><span id='txt-tub1' class='score one'>0</span></span>";
  echo "<span id='nav-tub2' class='tub'><i id='box-tub2' class='fa fa-square grey-text'></i><span id='txt-tub2' class='score one'>0</span></span>";
  echo "<span id='nav-tub3' class='tub'><i id='box-tub3' class='fa fa-square grey-text'></i><span id='txt-tub3' class='score one'>0</span></span>";
}
echo "</div></div></div>";

// Build page content
echo "<div class='tabs'>";
$tab = 1;
$view = $myPage['name'];
$pages = [];
if ($myPage['text']) $pages[$view] = $myPage;
if ($myPage['Type']=='xmenu') $pages = array_merge($pages, find_pages($view));
if (isset($myPage['Tabs'])) $display['tabs'] = strtolower($myPage['Tabs'])=='true' ? 0 : 1;
$tabbed = $display['tabs']==0 && count($pages)>1;

foreach ($pages as $page) {
  $close = false;
  if (isset($page['Title'])) {
    eval("\$title=\"".htmlspecialchars($page['Title'])."\";");
    if ($tabbed) {
      echo "<div class='tab'><input type='radio' id='tab{$tab}' name='tabs' onclick='settab(this.id)'><label for='tab{$tab}'>";
      echo tab_title($title,$page['root'],$page['Tag']??false);
      echo "</label><div class='content'>";
      $close = true;
    } else {
      if ($tab==1) echo "<div class='tab'><input type='radio' id='tab{$tab}' name='tabs'><div class='content shift'>";
      echo "<div id='title'><span class='left'>";
      echo tab_title($title,$page['root'],$page['Tag']??false);
      echo "</span></div>";
    }
    $tab++;
  }
  if (isset($page['Type']) && $page['Type']=='menu') {
    $pgs = find_pages($page['name']);
    foreach ($pgs as $pg) {
      @eval("\$title=\"".htmlspecialchars($pg['Title'])."\";");
      $link = "$path/{$pg['name']}";
      $icon = $pg['Icon'] ?? "<i class='icon-app PanelIcon'></i>";
      if (substr($icon,-4)=='.png') {
        $root = $pg['root'];
        if (file_exists("$docroot/$root/images/$icon")) {
          $icon = "<img src='/$root/images/$icon' class='PanelImg'>";
        } elseif (file_exists("$docroot/$root/$icon")) {
          $icon = "<img src='/$root/$icon' class='PanelImg'>";
        } else {
          $icon = "<i class='icon-app PanelIcon'></i>";
        }
      } elseif (substr($icon,0,5)=='icon-') {
        $icon = "<i class='$icon PanelIcon'></i>";
      } elseif ($icon[0]!='<') {
        if (substr($icon,0,3)!='fa-') $icon = "fa-$icon";
        $icon = "<i class='fa $icon PanelIcon'></i>";
      }
      echo "<div class=\"Panel\"><a href=\"$link\" onclick=\"$.cookie('one','tab1',{path:'/'})\"><span>$icon</span><div class=\"PanelText\">$title</div></a></div>";
    }
  }
  empty($page['Markdown']) || $page['Markdown']=='true' ? eval('?>'.Markdown($page['text'])) : eval('?>'.$page['text']);
  if ($close) echo "</div></div>";
}
unset($pages,$page,$pgs,$pg,$icon);
?>
</div></div>
<div class="spinner fixed"></div>
<iframe id="progressFrame" name="progressFrame" frameborder="0"></iframe>
<?
// Build footer
echo '<div id="footer"><span id="statusraid"><span id="statusbar">';
$progress = ($var['fsProgress']!='')? "&bullet;<span class='blue strong'>{$var['fsProgress']}</span>" : '';
switch ($var['fsState']) {
case 'Stopped':
  echo "<span class='red strong'><i class='fa fa-stop-circle'></i> 阵列已停止</span>$progress"; break;
case 'Starting':
  echo "<span class='orange strong'><i class='fa fa-pause-circle'></i> 阵列启动中</span>$progress"; break;
case 'Stopping':
  echo "<span class='orange strong'><i class='fa fa-pause-circle'></i> 阵列停止中</span>$progress"; break;
default:
  echo "<span class='green strong'><i class='fa fa-play-circle'></i> 阵列已启动</span>$progress"; break;
}
echo "</span></span><span id='countdown'></span><span id='user-notice' class='red-text'></span>";
echo "<span id='copyright'>Unraid&reg; webGui &copy;2020, Lime Technology, Inc. 简体中文 by KleinerSource";
echo " <a href='http://lime-technology.com/wiki/index.php/Official_Documentation' target='_blank' title='在线手册'><i class='fa fa-book'></i> 手册</a>";
echo "</span></div>";
?>
<script>
// Firefox specific workaround
if (typeof InstallTrigger!=='undefined') $('#nav-block').addClass('mozilla');

function parseINI(data){
  var regex = {
    section: /^\s*\[\s*\"*([^\]]*)\s*\"*\]\s*$/,
    param: /^\s*([^=]+?)\s*=\s*\"*(.*?)\s*\"*$/,
    comment: /^\s*;.*$/
  };
  var value = {};
  var lines = data.split(/[\r\n]+/);
  var section = null;
  lines.forEach(function(line) {
    if (regex.comment.test(line)) {
      return;
    } else if (regex.param.test(line)) {
      var match = line.match(regex.param);
      if (section) {
        value[section][match[1]] = match[2];
      } else {
        value[match[1]] = match[2];
      }
    } else if (regex.section.test(line)) {
      var match = line.match(regex.section);
      value[match[1]] = {};
      section = match[1];
    } else if (line.length==0 && section) {
      section = null;
    };
  });
  return value;
}
// unraid animated logo
var unraid_logo = '<?readfile("$docroot/webGui/images/animated-logo.svg")?>';

var watchdog = new NchanSubscriber('/sub/var');
watchdog.on('message', function(data) {
  var ini = parseINI(data);
  var state = ini['fsState'];
  var progress = ini['fsProgress'];
  var status;
  if (state=='Stopped') {
    status = "<span class='red strong'><i class='fa fa-stop-circle'></i> 阵列已停止</span>";
  } else if (state=='Started') {
    status = "<span class='green strong'><i class='fa fa-play-circle'></i> 阵列已启动</span>";
  } else if (state=='Formatting') {
    status = "<span class='green strong'><i class='fa fa-play-circle'></i> 阵列已启动</span>&bullet;<span class='orange strong'>正在格式化设备</span>";
  } else {
    status = "<span class='orange strong'><i class='fa fa-pause-circle'></i> 阵列 "+state+"</span>";
  }
  if (ini['mdResyncPos']>0) {
    var action;
    if (ini['mdResyncAction'].indexOf("recon")>=0) action = "奇偶同步/数据重建";
    else if (ini['mdResyncAction'].indexOf("clear")>=0) action = "清理中";
    else if (ini['mdResyncAction']=="check") action = "读取检查";
    else if (ini['mdResyncAction'].indexOf("check")>=0) action = "奇偶校验";
    action += " "+(ini['mdResyncPos']/(ini['mdResyncSize']/100+1)).toFixed(1)+" %";
    status += "&bullet;<span class='orange strong'>"+action.replace('.','<?=$display['number'][0]?>')+"</span>";
    if (ini['mdResync']==0) status += "(已暂停)";
  }
  if (progress) status += "&bullet;<span class='blue strong'>"+progress+"</span>";
  $('#statusbar').html(status);
});
var backtotopoffset = 250;
var backtotopduration = 500;
$(window).scroll(function() {
  if ($(this).scrollTop() > backtotopoffset) {
    $('.back_to_top').fadeIn(backtotopduration);
  } else {
    $('.back_to_top').fadeOut(backtotopduration);
  }
<?if ($themes1):?>
  var top = $('div#header').height() - 1; // header height has 1 extra pixel to cover overlap
  $('div#menu').css($(this).scrollTop() > top ? {position:'fixed',top:'0'} : {position:'absolute',top:top+'px'});
<?endif;?>
});
$('.back_to_top').click(function(event) {
  event.preventDefault();
  $('html,body').animate({scrollTop:0},backtotopduration);
  return false;
});
$(function() {
  shortcut.add('F1',function(){HelpButton();});
<?if ($var['regTy']=='unregistered'):?>
  $('#licensetype').addClass('orange-text');
<?elseif (!in_array($var['regTy'],['Trial','Basic','Plus','Pro'])):?>
  $('#licensetype').addClass('red-text');
<?endif;?>
<?if ($notify['entity'] & 1 == 1):?>
  $.post('/webGui/include/Notify.php',{cmd:'init'},function(){timers.notifier = setTimeout(notifier,0);});
<?endif;?>
  $('input[value="Apply"],input[name="cmdEditShare"],input[name="cmdUserEdit"]').prop('disabled',true);
  $('form').find('select,input[type=text],input[type=number],input[type=password],input[type=checkbox],input[type=radio],input[type=file],textarea').each(function(){$(this).on('input change',function() {
    var form = $(this).parentsUntil('form').parent();
    form.find('input[value="Apply"],input[name="cmdEditShare"],input[name="cmdUserEdit"]').not('input.lock').prop('disabled',false);
    form.find('input[value="Done"]').not('input.lock').val('Reset').prop('onclick',null).off('click').click(function(){refresh(form.offset().top)});
  });});

  var top = ($.cookie('top')||0) - $('.tabs').offset().top - 75;
  if (top>0) {$('html,body').scrollTop(top);}
  $.removeCookie('top',{path:'/'});
<?if ($safemode):?>
  showNotice('系统运行在 <b>安全模式</b> 下');
<?else:?>
<?$readme = @file_get_contents("$docroot/plugins/unRAIDServer/README.md",false,null,0,20);?>
<?if (strpos($readme,'REBOOT REQUIRED')!==false):?>
  showUpgrade('<b>需要重新启动</b> 应用 Unraid OS 更新',true);
<?elseif (strpos($readme,'DOWNGRADE')!==false):?>
  showUpgrade('<b>需要重新启动</b> 降级 Unraid OS',true);
<?elseif ($version = plugin_update_available('unRAIDServer',true)):?>
  showUpgrade('Unraid OS v<?=$version?> 现已发行. <a>立即更新</a>');
<?endif;?>
<?if (!$notify['system']):?>
  addBannerWarning('系统通知被 <b>禁用</b>. 点击 <a href="/Settings/Notifications" style="cursor:pointer">这里</a> 更改通知设定.',true,true);
<?endif;?>
<?endif;?>
<?if ($notify['display']):?>
  var opts = [];
  context.init({preventDoubleContext:false,left:true,above:false});
  opts.push({text:'查看',icon:'fa-folder-open-o',action:function(e){e.preventDefault();openNotifier('alert');}});
  opts.push({divider:true});
  opts.push({text:'历史',icon:'fa-file-text-o',action:function(e){e.preventDefault();viewHistory('alert');}});
  opts.push({divider:true});
  opts.push({text:'确认',icon:'fa-check-square-o',action:function(e){e.preventDefault();closeNotifier('alert');}});
  context.attach('#nav-tub1',opts);

  var opts = [];
  context.init({preventDoubleContext:false,left:true,above:false});
  opts.push({text:'查看',icon:'fa-folder-open-o',action:function(e){e.preventDefault();openNotifier('warning');}});
  opts.push({divider:true});
  opts.push({text:'历史',icon:'fa-file-text-o',action:function(e){e.preventDefault();viewHistory('warning');}});
  opts.push({divider:true});
  opts.push({text:'确认',icon:'fa-check-square-o',action:function(e){e.preventDefault();closeNotifier('warning');}});
  context.attach('#nav-tub2',opts);

  var opts = [];
  context.init({preventDoubleContext:false,left:true,above:false});
  opts.push({text:'查看',icon:'fa-folder-open-o',action:function(e){e.preventDefault();openNotifier('normal');}});
  opts.push({divider:true});
  opts.push({text:'历史',icon:'fa-file-text-o',action:function(e){e.preventDefault();viewHistory('normal');}});
  opts.push({divider:true});
  opts.push({text:'确认',icon:'fa-check-square-o',action:function(e){e.preventDefault();closeNotifier('normal');}});
  context.attach('#nav-tub3',opts);
<?endif;?>
  if (location.pathname.search(/\/(AddVM|UpdateVM|AddContainer|UpdateContainer)/)==-1) {
    $('blockquote.inline_help').each(function(i) {
      $(this).attr('id','helpinfo'+i);
      var pin = $(this).prev();
      if (!pin.prop('nodeName')) pin = $(this).parent().prev();
      while (pin.prop('nodeName') && pin.prop('nodeName').search(/(table|dl)/i)==-1) pin = pin.prev();
      pin.find('tr:first,dt:last').each(function() {
        var node = $(this);
        var name = node.prop('nodeName').toLowerCase();
        if (name=='dt') {
          while (!node.html() || node.html().search(/(<input|<select|nbsp;)/i)>=0 || name!='dt') {
            if (name=='dt' && node.is(':first-of-type')) break;
            node = node.prev();
            name = node.prop('nodeName').toLowerCase();
          }
          node.css('cursor','help').click(function(){$('#helpinfo'+i).toggle('slow');});
        } else {
          if (node.html() && (name!='tr' || node.children('td:first').html())) node.css('cursor','help').click(function(){$('#helpinfo'+i).toggle('slow');});
        }
      });
    });
  }
  $('form').append($('<input>').attr({type:'hidden', name:'csrf_token', value:'<?=$var['csrf_token']?>'}));
  setTimeout(function(){$('div.spinner').each(function(){$(this).html(unraid_logo);});},150); // display animation if page loading takes longer than 150ms
  watchdog.start();
});
</script>
</body>
</html>
