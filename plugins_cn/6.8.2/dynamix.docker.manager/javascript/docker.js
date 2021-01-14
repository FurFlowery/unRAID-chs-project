var eventURL = '/plugins/dynamix.docker.manager/include/Events.php';

function addDockerContainerContext(container, image, template, started, paused, update, autostart, webui, shell, id, Support, Project, Registry) {
  var opts = [];
  if (started && !paused) {
    if (webui !== '' && webui != '#') opts.push({text:'WebUI', icon:'fa-globe', href:webui, target:'_blank'});
    opts.push({text:'控制台', icon:'fa-terminal', action:function(e){e.preventDefault(); dockerTerminal(container,shell);}});
    opts.push({divider:true});
  }
  if (!update) {
    opts.push({text:'更新', icon:'fa-arrow-down', action:function(e){e.preventDefault(); execUpContainer(container);}});
    opts.push({divider:true});
  }
  if (started) {
    if (paused) {
      opts.push({text:'恢复', icon:'fa-play', action:function(e){e.preventDefault(); eventControl({action:'resume', container:id}, 'loadlist');}});
    } else {
      opts.push({text:'停止', icon:'fa-stop', action:function(e){e.preventDefault(); eventControl({action:'stop', container:id}, 'loadlist');}});
      opts.push({text:'暂停', icon:'fa-pause', action:function(e){e.preventDefault(); eventControl({action:'pause', container:id}, 'loadlist');}});
    }
    opts.push({text:'重启', icon:'fa-refresh', action:function(e){e.preventDefault(); eventControl({action:'restart', container:id}, 'loadlist');}});
  } else {
    opts.push({text:'启动', icon:'fa-play', action:function(e){e.preventDefault(); eventControl({action:'start', container:id}, 'loadlist');}});
  }
  opts.push({divider:true});
  if (location.pathname.indexOf('/Dashboard') === 0) {
    opts.push({text:'日志', icon:'fa-navicon', action:function(e){e.preventDefault(); containerLogs(container, id);}});
  }
  if (template) {
    opts.push({text:'编辑', icon:'fa-wrench', action:function(e){e.preventDefault(); editContainer(container, template);}});
  }
  opts.push({divider:true});
  opts.push({text:'删除', icon:'fa-trash', action:function(e){e.preventDefault(); rmContainer(container, image, id);}});
  if (Support) {
    opts.push({divider:true});
    opts.push({text:'支持', icon:'fa-question', href:Support, target:'_blank'});
  }
  if (Project) {
    opts.push({text:'项目页', icon:'fa-life-ring', href:Project, target:'_blank'});
  }
  if (Registry) {
    opts.push({text:'更多信息',icon:'fa-info-circle', href:Registry, target:'_blank'});
  }
  context.attach('#'+id, opts);
}
function addDockerImageContext(image, imageTag) {
  var opts = [];
  opts.push({text:'删除', icon:'fa-trash', action:function(e){e.preventDefault(); rmImage(image, imageTag);}});
  context.attach('#'+image, opts);
}
function dockerTerminal(container,shell) {
  var height = 600;
  var width = 900;
  var top = (screen.height-height)/2;
  var left = (screen.width-width)/2;
  var win = window.open('', container, 'resizeable=yes,scrollbars=yes,height='+height+',width='+width+',top='+top+',left='+left);
  $.get(eventURL,{action:'terminal',name:container,shell:shell},function(){win.location='/dockerterminal/'+container+'/'; win.focus();});
}
function execUpContainer(container) {
  var title = '更新这个容器: '+container;
  var address = '/plugins/dynamix.docker.manager/include/CreateDocker.php?updateContainer=true&ct[]='+encodeURIComponent(container);
  popupWithIframe(title, address, true, 'loadlist');
}
function popupWithIframe(title, cmd, reload, func) {
  pauseEvents();
  $('#iframe-popup').html('<iframe id="myIframe" frameborder="0" scrolling="yes" width="100%" height="99%"></iframe>');
  $('#iframe-popup').dialog({
    autoOpen:true,
    title:title,
    draggable:true,
    width:800,
    height:((screen.height / 5) * 4) || 0,
    resizable:true,
    modal:true,
    show:{effect:'fade', duration:250},
    hide:{effect:'fade', duration:250},
    open:function(ev, ui) {
      $('#myIframe').attr('src', cmd);
    },
    close:function(event, ui) {
      if (reload && !$('#myIframe').contents().find('#canvas').length) {
        if (func) setTimeout(func+'()',0); else location = window.location.href;
      } else {
        resumeEvents();
      }
    }
  });
  $('.ui-dialog .ui-dialog-titlebar').addClass('menu');
  $('.ui-dialog .ui-dialog-title').css('text-align', 'center').css('width', '100%');
  $('.ui-dialog .ui-dialog-content').css('padding', '12');
}
function addContainer() {
  var path = location.pathname;
  var x = path.indexOf('?');
  if (x!=-1) path = path.substring(0,x);
  location = path+'/AddContainer';
}
function editContainer(container, template) {
  var path = location.pathname;
  var x = path.indexOf('?');
  if (x!=-1) path = path.substring(0, x);
  location = path+'/UpdateContainer?xmlTemplate=edit:'+template;
}
function updateContainer(container) {
  var body = '更新容器: '+container;
  swal({
    title:'你确定吗?',
    text:body,
    type:'warning',
    showCancelButton:true,
    confirmButtonText:'是的, 更新吧!'
  },function(){
    execUpContainer(container);
  });
}
function rmContainer(container, image, id) {
  var body = '删除容器: '+container+'<br><br><label><input id="removeimagechk" type="checkbox" checked style="display:inline;width:unset;height:unset;margin-top:unset;margin-bottom:unset">同时删除镜像</label>';
  $('input[type=button]').prop('disabled',true);
  swal({
    title:'你确定吗?',
    text:body,
    type:'warning',
    html:true,
    showCancelButton:true,
    confirmButtonText:'是的, 删除!',
    showLoaderOnConfirm:true
  },function(c){
    if (!c) {setTimeout(loadlist,0); return;}
    if ($('#removeimagechk').prop('checked')) {
      eventControl({action:'remove_all', container:id, name:container, image:image},'loadlist');
    } else {
      eventControl({action:'remove_container', container:id, name:container},'loadlist');
    }
  });
}
function rmImage(image, imageName) {
  var body = '删除镜像: '+$('<textarea />').html(imageName).text();
  $('input[type=button]').prop('disabled',true);
  swal({
    title:'你确定吗?',
    text:body,
    type:'warning',
    showCancelButton:true,
    confirmButtonText:'是的, 删除!',
    showLoaderOnConfirm:true
  },function(c){
    if (!c) {setTimeout(loadlist,0); return;}
    eventControl({action:'remove_image', image:image},'loadlist');
  });
}
function eventControl(params, spin) {
  if (spin) $('#'+params['container']).parent().find('i').removeClass('fa-play fa-square fa-pause').addClass('fa-refresh fa-spin');
  $.post(eventURL, params, function(data) {
    if (data.success === true) {
      if (spin) setTimeout(spin+'()',500); else location=window.location.href;
    } else {
      swal({
        title:'执行错误', html:true,
        text:data.success, type:'error'
      },function(){
        if (spin) setTimeout(spin+'()',500); else location=window.location.href;
      });
    }
  },'json');
}
function startAll() {
  $('input[type=button]').prop('disabled',true);
  for (var i=0,ct; ct=docker[i]; i++) if (ct.state==0) $('#'+ct.id).parent().find('i').removeClass('fa-square').addClass('fa-refresh fa-spin');
  $.post('/plugins/dynamix.docker.manager/include/ContainerManager.php',{action:'start'},function(){loadlist();});
}
function stopAll() {
  $('input[type=button]').prop('disabled',true);
  for (var i=0,ct; ct=docker[i]; i++) if (ct.state==1) $('#'+ct.id).parent().find('i').removeClass('fa-play fa-pause').addClass('fa-refresh fa-spin');
  $.post('/plugins/dynamix.docker.manager/include/ContainerManager.php',{action:'stop'},function(){loadlist();});
}
function pauseAll() {
  $('input[type=button]').prop('disabled',true);
  for (var i=0,ct; ct=docker[i]; i++) if (ct.state==1 && ct.pause==0) $('#'+ct.id).parent().find('i').removeClass('fa-play').addClass('fa-refresh fa-spin');
  $.post('/plugins/dynamix.docker.manager/include/ContainerManager.php',{action:'pause'},function(){loadlist();});
}
function resumeAll() {
  $('input[type=button]').prop('disabled',true);
  for (var i=0,ct; ct=docker[i]; i++) if (ct.state==1 && ct.pause==1) $('#'+ct.id).parent().find('i').removeClass('fa-pause').addClass('fa-refresh fa-spin');
  $.post('/plugins/dynamix.docker.manager/include/ContainerManager.php',{action:'unpause'},function(){loadlist();});
}
function checkAll() {
  $('input[type=button]').prop('disabled',true);
  $('.updatecolumn').html('<span style="color:#267CA8"><i class="fa fa-refresh fa-spin"></i> 检查中...</span>');
  $.post('/plugins/dynamix.docker.manager/include/DockerUpdate.php',{},function(){loadlist();});
}
function updateAll() {
  $('input[type=button]').prop('disabled',true);
  $('#docker_list').append("<div class='spinner fixed'></div>");
  $('div.spinner').html(unraid_logo).show('slow');
  var list = '';
  for (var i=0,ct; ct=docker[i]; i++) if (ct.update=='false') list += '&ct[]='+encodeURI(ct.name);
  var address = '/plugins/dynamix.docker.manager/include/CreateDocker.php?updateContainer=true'+list;
  popupWithIframe('正在更新所有容器', address, true, 'loadlist');
}
function containerLogs(container, id) {
  var height = 600;
  var width = 900;
  var run = eventURL+'?action=log&container='+id+'&title=Log for: '+container;
  var top = (screen.height-height) / 2;
  var left = (screen.width-width) / 2;
  var options = 'resizeable=yes,scrollbars=yes,height='+height+',width='+width+',top='+top+',left='+left;
  window.open(run, 'log', options);
}
