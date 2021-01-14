function ajaxVMDispatch(params, spin){
  if (spin) $('#vm-'+params['uuid']).parent().find('i').removeClass('fa-play fa-square fa-pause').addClass('fa-refresh fa-spin');
  $.post("/plugins/dynamix.vm.manager/include/VMajax.php", params, function(data) {
    if (data.error) {
      swal({
        title:"Execution error", html:true,
        text:data.error, type:"error"
      },function(){
        if (spin) setTimeout(spin+'()',500); else location=window.location.href;
      });
    } else {
      if (spin) setTimeout(spin+'()',500); else location=window.location.href;
    }
  },'json');
}
function addVMContext(name, uuid, template, state, vncurl, log){
  var opts = [];
  var path = location.pathname;
  var x = path.indexOf("?");
  if (x!=-1) path = path.substring(0,x);
  if (vncurl !== "") {
    opts.push({text:"VNC 远程连接", icon:"fa-desktop", action:function(e) {
      e.preventDefault();
      window.open(vncurl, '_blank', 'scrollbars=yes,resizable=yes');
    }});
    opts.push({divider:true});
  }
  if (state == "running") {
    opts.push({text:"停止", icon:"fa-stop", action:function(e) {
      e.preventDefault();
      ajaxVMDispatch({action:"domain-stop", uuid:uuid}, "loadlist");
    }});
    opts.push({text:"暂停", icon:"fa-pause", action:function(e) {
      e.preventDefault();
      ajaxVMDispatch({action:"domain-pause", uuid:uuid}, "loadlist");
    }});
    opts.push({text:"重启", icon:"fa-refresh", action:function(e) {
      e.preventDefault();
      ajaxVMDispatch({action:"domain-restart", uuid:uuid}, "loadlist");
    }});
    opts.push({text:"休眠", icon:"fa-bed", action:function(e) {
      e.preventDefault();
      ajaxVMDispatch({action:"domain-pmsuspend", uuid:uuid}, "loadlist");
    }});
    opts.push({text:"强制停止", icon:"fa-bomb", action:function(e) {
      e.preventDefault();
      ajaxVMDispatch( {action:"domain-destroy", uuid:uuid}, "loadlist");
    }});
  } else if (state == "pmsuspended") {
    opts.push({text:"继续", icon:"fa-play", action:function(e) {
      e.preventDefault();
      ajaxVMDispatch({action:"domain-pmwakeup", uuid:uuid}, "loadlist");
    }});
    opts.push({text:"强制停止", icon:"fa-bomb", action:function(e) {
      e.preventDefault();
      ajaxVMDispatch({action:"domain-destroy", uuid:uuid}, "loadlist");
    }});
  } else if (state == "paused" || state == "unknown") {
    opts.push({text:"继续", icon:"fa-play", action:function(e) {
      e.preventDefault();
      ajaxVMDispatch({action:"domain-resume", uuid:uuid}, "loadlist");
    }});
    opts.push({text:"强制停止", icon:"fa-bomb", action:function(e) {
      e.preventDefault();
      ajaxVMDispatch({action:"domain-destroy", uuid:uuid}, "loadlist");
    }});
  } else {
    opts.push({text:"启动", icon:"fa-play", action:function(e) {
      e.preventDefault();
      ajaxVMDispatch({action:"domain-start", uuid:uuid}, "loadlist");
    }});
  }
  opts.push({divider:true});
  if (log !== "") {
    opts.push({text:"日志", icon:"fa-navicon", action:function(e){e.preventDefault(); openWindow('/webGui/scripts/tail_log&arg1='+log, 'Log for:'+name, 600, 900);}});
  }
  opts.push({text:"编辑", icon:"fa-pencil", href:path+'/UpdateVM?uuid='+uuid});
  if (state == "shutoff") {
    opts.push({divider:true});
    opts.push({text:"删除虚拟机", icon:"fa-minus", action:function(e) {
      e.preventDefault();
      swal({
        title:"你确定吗?",
        text:"删除虚拟机配置:"+name,
        type:"warning",
        showCancelButton:true
      },function(){
        $('#vm-'+uuid).find('i').removeClass('fa-play fa-square fa-pause').addClass('fa-refresh fa-spin');
        ajaxVMDispatch({action:"domain-undefine",uuid:uuid}, "loadlist");
      });
    }});
    if (template != 'OpenELEC') {
      opts.push({text:"删除虚拟机 & 磁盘", icon:"fa-trash", action:function(e) {
        e.preventDefault();
        swal({
          title:"你确定吗?",
          text:"成功删除 "+name+" 硬盘镜像和虚拟机配置",
          type:"warning",
          showCancelButton:true
        },function(){
          $('#vm-'+uuid).find('i').removeClass('fa-play fa-square fa-pause').addClass('fa-refresh fa-spin');
          ajaxVMDispatch({action:"domain-delete",uuid:uuid}, "loadlist");
        });
      }});
    }
  }
  context.attach('#vm-'+uuid, opts);
}
function startAll() {
  $('input[type=button]').prop('disabled',true);
  for (var i=0,vm; vm=kvm[i]; i++) if (vm.state!='running') $('#vm-'+vm.id).parent().find('i').removeClass('fa-square').addClass('fa-refresh fa-spin');
  $.post('/plugins/dynamix.vm.manager/include/VMManager.php',{action:'start'}, function(){loadlist();});
}
function stopAll() {
  $('input[type=button]').prop('disabled',true);
  for (var i=0,vm; vm=kvm[i]; i++) if (vm.state=='running') $('#vm-'+vm.id).parent().find('i').removeClass('fa-play').addClass('fa-refresh fa-spin');
  $.post('/plugins/dynamix.vm.manager/include/VMManager.php',{action:'stop'}, function(){loadlist();});
}
function vncOpen() {
  $.post('/plugins/dynamix.vm.manager/include/vnc.php',{cmd:'open',root:'<?=$docroot?>',file:'<?=$docroot?>/plugins/dynamix.vm.manager/vncconnect.vnc'},function(data) {
    window.location.href = data;
  });
}
function toggle_id(itemID){
   var cookie = $.cookie('vmshow')||'';
   if ((document.getElementById(itemID).style.display == 'none')) {
      slideDownRows($('#'+itemID));
      if (cookie.indexOf(itemID)<0) $.cookie('vmshow',cookie+itemID+',',{path:'/'}); 
   } else {
      slideUpRows($('#'+itemID));
      if (cookie.indexOf(itemID)>=0) $.cookie('vmshow',cookie.replace(itemID+',',''),{path:'/'});
   }
   return false;
}
function showInput(){
  $(this).off('click');
  $(this).siblings('input').each(function(){$(this).show();});
  $(this).siblings('input').focus();
  $(this).hide();
}
function hideInput(){
  $(this).hide();
  $(this).siblings('span').show();
  $(this).siblings('span').click(showInput);
}
function addVM() {
  var path = location.pathname;
  var x = path.indexOf("?");
  if (x!=-1) path = path.substring(0,x);
  location = path+"/VMTemplates";
}
