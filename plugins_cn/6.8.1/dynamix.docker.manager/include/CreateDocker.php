<?PHP
/* Copyright 2005-2019, Lime Technology
 * Copyright 2015-2019, Guilherme Jardim, Eric Schultz, Jon Panozzo.
 * Copyright 2012-2019, Bergware International.
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
$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
require_once "$docroot/plugins/dynamix.docker.manager/include/DockerClient.php";
require_once "$docroot/plugins/dynamix.docker.manager/include/Helpers.php";
require_once "$docroot/webGui/include/Helpers.php";

libxml_use_internal_errors(false); # Enable xml errors

$var = parse_ini_file('state/var.ini');
ignore_user_abort(true);

$DockerClient = new DockerClient();
$DockerUpdate = new DockerUpdate();
$DockerTemplates = new DockerTemplates();

#   ███████╗██╗   ██╗███╗   ██╗ ██████╗████████╗██╗ ██████╗ ███╗   ██╗███████╗
#   ██╔════╝██║   ██║████╗  ██║██╔════╝╚══██╔══╝██║██╔═══██╗████╗  ██║██╔════╝
#   █████╗  ██║   ██║██╔██╗ ██║██║        ██║   ██║██║   ██║██╔██╗ ██║███████╗
#   ██╔══╝  ██║   ██║██║╚██╗██║██║        ██║   ██║██║   ██║██║╚██╗██║╚════██║
#   ██║     ╚██████╔╝██║ ╚████║╚██████╗   ██║   ██║╚██████╔╝██║ ╚████║███████║
#   ╚═╝      ╚═════╝ ╚═╝  ╚═══╝ ╚═════╝   ╚═╝   ╚═╝ ╚═════╝ ╚═╝  ╚═══╝╚══════╝

$custom = DockerUtil::custom();
$subnet = DockerUtil::network($custom);
$cpus   = cpu_list();

function cpu_pinning() {
  global $xml,$cpus;
  $vcpu = explode(',',$xml['CPUset'] ?? '');
  $total = count($cpus);
  $loop = floor(($total-1)/16)+1;
  for ($c = 0; $c < $loop; $c++) {
    $row1 = $row2 = [];
    $max = ($c == $loop-1 ? ($total%16?:16) : 16);
    for ($n = 0; $n < $max; $n++) {
      unset($cpu1,$cpu2);
      list($cpu1, $cpu2) = preg_split('/[,-]/',$cpus[$c*16+$n]);
      $check1 = in_array($cpu1, $vcpu) ? ' checked':'';
      $check2 = $cpu2 ? (in_array($cpu2, $vcpu) ? ' checked':''):'';
      $row1[] = "<label id='cpu$cpu1' class='checkbox'>$cpu1<input type='checkbox' id='box$cpu1'$check1><span class='checkmark'></span></label>";
      if ($cpu2) $row2[] = "<label id='cpu$cpu2' class='checkbox'>$cpu2<input type='checkbox' id='box$cpu2'$check2><span class='checkmark'></span></label>";
    }
    if ($c) echo '<hr>';
    echo "<span class='cpu'>CPU:</span>".implode($row1);
    if ($row2) echo "<br><span class='cpu'>HT:</span>".implode($row2);
  }
}

#    ██████╗ ██████╗ ██████╗ ███████╗
#   ██╔════╝██╔═══██╗██╔══██╗██╔════╝
#   ██║     ██║   ██║██║  ██║█████╗
#   ██║     ██║   ██║██║  ██║██╔══╝
#   ╚██████╗╚██████╔╝██████╔╝███████╗
#    ╚═════╝ ╚═════╝ ╚═════╝ ╚══════╝

##########################
##   CREATE CONTAINER   ##
##########################

if (isset($_POST['contName'])) {
  $postXML = postToXML($_POST, true);
  $dry_run = $_POST['dryRun']=='true' ? true : false;
  $existing = $_POST['existingContainer'] ?? false;
  $create_paths = $dry_run ? false : true;
  // Get the command line
  list($cmd, $Name, $Repository) = xmlToCommand($postXML, $create_paths);
  readfile("$docroot/plugins/dynamix.docker.manager/log.htm");
  @flush();
  // Saving the generated configuration file.
  $userTmplDir = $dockerManPaths['templates-user'];
  if (!is_dir($userTmplDir)) mkdir($userTmplDir, 0777, true);
  if ($Name) {
    $filename = sprintf('%s/my-%s.xml', $userTmplDir, $Name);
    if ( is_file($filename) ) {
      $oldXML = simplexml_load_file($filename);
      if ($oldXML->Icon != $_POST['contIcon']) {
        if (! strpos($Repository,":")) {
          $Repository .= ":latest";
        }
        $iconPath = $DockerTemplates->getIcon($Repository);
        @unlink("$docroot/$iconPath");
        @unlink("{$dockerManPaths['images']}/".basename($iconPath));
      }
    }
    file_put_contents($filename, $postXML);
  }
  // Run dry
  if ($dry_run) {
    echo "<h2>XML</h2>";
    echo "<pre>".htmlspecialchars($postXML)."</pre>";
    echo "<h2>命令:</h2>";
    echo "<pre>".htmlspecialchars($cmd)."</pre>";
    echo "<div style='text-align:center'><button type='button' onclick='window.location=window.location.pathname+window.location.hash+\"?xmlTemplate=edit:${filename}\"'>返回</button>";
    echo "<button type='button' onclick='done()'>完成</button></div><br>";
    goto END;
  }
  // Will only pull image if it's absent
  if (!$DockerClient->doesImageExist($Repository)) {
    // Pull image
    if (!pullImage($Name, $Repository)) {
      echo '<div style="text-align:center"><button type="button" onclick="done()">完成</button></div><br>';
      goto END;
    }
  }
  $startContainer = true;
  // Remove existing container
  if ($DockerClient->doesContainerExist($Name)) {
    // attempt graceful stop of container first
    $oldContainerInfo = $DockerClient->getContainerDetails($Name);
    if (!empty($oldContainerInfo) && !empty($oldContainerInfo['State']) && !empty($oldContainerInfo['State']['Running'])) {
      // attempt graceful stop of container first
      stopContainer($Name);
    }
    // force kill container if still running after 10 seconds
    removeContainer($Name);
  }
  // Remove old container if renamed
  if ($existing && $DockerClient->doesContainerExist($existing)) {
    // determine if the container is still running
    $oldContainerInfo = $DockerClient->getContainerDetails($existing);
    if (!empty($oldContainerInfo) && !empty($oldContainerInfo['State']) && !empty($oldContainerInfo['State']['Running'])) {
      // attempt graceful stop of container first
      stopContainer($existing);
    } else {
      // old container was stopped already, ensure newly created container doesn't start up automatically
      $startContainer = false;
    }
    // force kill container if still running after 10 seconds
    removeContainer($existing,1);
    // remove old template
    if (strtolower($filename) != strtolower("$userTmplDir/my-$existing.xml")) {
      @unlink("$userTmplDir/my-$existing.xml");
    }
  }
  if ($startContainer) $cmd = str_replace('/docker create ', '/docker run -d ', $cmd);
  execCommand($cmd);

  echo '<div style="text-align:center"><button type="button" onclick="done()">完成</button></div><br>';
  goto END;
}

##########################
##   UPDATE CONTAINER   ##
##########################

if ($_GET['updateContainer']){
  readfile("$docroot/plugins/dynamix.docker.manager/log.htm");
  @flush();
  foreach ($_GET['ct'] as $value) {
    $tmpl = $DockerTemplates->getUserTemplate(urldecode($value));
    if (!$tmpl) {
      echo "<script>addLog('<p>找不到配置. 这个容器是使用此插件创建的吗?</p>');</script>";
      @flush();
      continue;
    }
    $xml = file_get_contents($tmpl);
    list($cmd, $Name, $Repository) = xmlToCommand($tmpl);
    $Registry = getXmlVal($xml, "Registry");
    $oldImageID = $DockerClient->getImageID($Repository);
    // Pull image
    if (!pullImage($Name, $Repository)) continue;
    $oldContainerInfo = $DockerClient->getContainerDetails($Name);
    // determine if the container is still running
    if (!empty($oldContainerInfo) && !empty($oldContainerInfo['State']) && !empty($oldContainerInfo['State']['Running'])) {
      // since container was already running, put it back it to a running state after update
      $cmd = str_replace('/plugins/dynamix.docker.manager/scripts/docker create ', '/plugins/dynamix.docker.manager/scripts/docker run -d ', $cmd);
      // attempt graceful stop of container first
      stopContainer($Name);
    }
    // force kill container if still running after 10 seconds
    if ( ! $_GET['communityApplications'] ) {
      removeContainer($Name);
    }
    execCommand($cmd);
    $DockerClient->flushCaches();
    $newImageID = $DockerClient->getImageID($Repository);
    if ($oldImageID && $oldImageID != $newImageID) {
      // remove old orphan image since it's no longer used by this container
      removeImage($oldImageID);
    }
  }
  echo '<div style="text-align:center"><button type="button" onclick="window.parent.jQuery(\'#iframe-popup\').dialog(\'close\')">完成</button></div><br>';
  goto END;
}

#########################
##   REMOVE TEMPLATE   ##
#########################

if ($_GET['rmTemplate']) {
  unlink($_GET['rmTemplate']);
}

#########################
##    LOAD TEMPLATE    ##
#########################

if ($_GET['xmlTemplate']) {
  list($xmlType, $xmlTemplate) = explode(':', urldecode($_GET['xmlTemplate']));
  if (is_file($xmlTemplate)) {
    $xml = xmlToVar($xmlTemplate);
    $templateName = $xml['Name'];
    if ($xmlType == 'default') {
      if (!empty($dockercfg['DOCKER_APP_CONFIG_PATH']) && file_exists($dockercfg['DOCKER_APP_CONFIG_PATH'])) {
        // override /config
        foreach ($xml['Config'] as &$arrConfig) {
          if ($arrConfig['Type'] == 'Path' && strtolower($arrConfig['Target']) == '/config') {
            $arrConfig['Default'] = $arrConfig['Value'] = realpath($dockercfg['DOCKER_APP_CONFIG_PATH']).'/'.$xml['Name'];
            if (empty($arrConfig['Display']) || preg_match("/^Host Path\s\d/", $arrConfig['Name'])) {
              $arrConfig['Display'] = 'advanced-hide';
            }
            if (empty($arrConfig['Name']) || preg_match("/^Host Path\s\d/", $arrConfig['Name'])) {
              $arrConfig['Name'] = 'AppData 配置路径';
            }
          }
        }
      }
      if (!empty($dockercfg['DOCKER_APP_UNRAID_PATH']) && file_exists($dockercfg['DOCKER_APP_UNRAID_PATH'])) {
        // override /unraid
        $boolFound = false;
        foreach ($xml['Config'] as &$arrConfig) {
          if ($arrConfig['Type'] == 'Path' && strtolower($arrConfig['Target']) == '/unraid') {
            $arrConfig['Default'] = $arrConfig['Value'] = realpath($dockercfg['DOCKER_APP_UNRAID_PATH']);
            $arrConfig['Display'] = 'hidden';
            $arrConfig['Name'] = 'Unraid 共享路径';
            $boolFound = true;
          }
        }
        if (!$boolFound) {
          $xml['Config'][] = [
            'Name'        => 'Unraid 共享路径',
            'Target'      => '/unraid',
            'Default'     => realpath($dockercfg['DOCKER_APP_UNRAID_PATH']),
            'Value'       => realpath($dockercfg['DOCKER_APP_UNRAID_PATH']),
            'Mode'        => 'rw',
            'Description' => '',
            'Type'        => 'Path',
            'Display'     => 'hidden',
            'Required'    => 'false',
            'Mask'        => 'false'
          ];
        }
      }
    }
    $xml['Description'] = str_replace(['[', ']'], ['<', '>'], $xml['Overview']);
    echo "<script>var Settings=".json_encode($xml).";</script>";
  }
}
echo "<script>var Allocations=".json_encode(getAllocations()).";</script>";
$authoringMode = $dockercfg['DOCKER_AUTHORING_MODE'] == "yes" ? true : false;
$authoring     = $authoringMode ? 'advanced' : 'noshow';
$disableEdit   = $authoringMode ? 'false' : 'true';
$showAdditionalInfo = '';
$bgcolor = strstr('white,azure',$display['theme']) ? '#f2f2f2' : '#1c1c1c';
?>
<link type="text/css" rel="stylesheet" href="<?autov("/webGui/styles/jquery.ui.css")?>">
<link type="text/css" rel="stylesheet" href="<?autov("/webGui/styles/jquery.switchbutton.css")?>">
<link type="text/css" rel="stylesheet" href="<?autov("/webGui/styles/jquery.filetree.css")?>">
<link rel="stylesheet" type="text/css" href="<?autov("/plugins/dynamix.docker.manager/styles/style-{$display['theme']}.css")?>">
<style>
option.list{padding:0 0 0 7px}
optgroup.bold{font-weight:bold;margin-top:5px}
optgroup.title{background-color:#625D5D;color:#f2f2f2;text-align:center;margin-top:10px}
.textTemplate{width:60%}
.fileTree{width:240px;max-height:200px;overflow-y:scroll;overflow-x:hidden;position:absolute;z-index:100;display:none;background:<?=$bgcolor?>}
.show{display:block}
.basic{display:table-row}
.advanced{display:none}
.noshow{display:none}
.required:after{content:" *";color:#E80000}
.inline_help{font-weight:normal}
.switch-wrapper{display:inline-block;position:relative;top:3px;vertical-align:middle;margin-top:-30px}
.switch-button-label.off{color:inherit;}
.selectVariable{width:320px}
.fa.button{color:maroon;font-size:2.4rem;position:relative;top:4px;cursor:pointer}
.spacer{padding:16px 0}
span.cpu,label.checkbox{display:inline-block;width:32px}
button[type=button]{margin:0 20px 0 0}
</style>
<script src="<?autov('/webGui/javascript/jquery.switchbutton.js')?>"></script>
<script src="<?autov('/webGui/javascript/jquery.filetree.js')?>"></script>
<script src="<?autov('/plugins/dynamix.vm.manager/javascript/dynamix.vm.manager.js')?>"></script>
<script type="text/javascript">
  var this_tab = $('input[name$="tabs"]').length;
  $(function() {
    var content= "<div class='switch-wrapper'><input type='checkbox' class='advanced-switch'></div>";
    <?if (!$tabbed):?>
    $("#docker_tabbed").html(content);
    <?else:?>
    var last = $('input[name$="tabs"]').length;
    var elementId = "normalAdvanced";
    $('.tabs').append("<span id='"+elementId+"' class='status vhshift' style='display:none;'>"+content+"&nbsp;</span>");
    if ($('#tab'+this_tab).is(':checked')) {
      $('#'+elementId).show();
    }
    $('#tab'+this_tab).bind({click:function(){$('#'+elementId).show();}});
    for (var x=1; x<=last; x++) if(x != this_tab) $('#tab'+x).bind({click:function(){$('#'+elementId).hide();}});
    <?endif;?>
    $('.advanced-switch').switchButton({labels_placement: "left", on_label: '高级视图', off_label: '基本视图'});
    $('.advanced-switch').change(function () {
      var status = $(this).is(':checked');
      toggleRows('advanced', status, 'basic');
      load_contOverview();
      $("#catSelect").dropdownchecklist("destroy");
      $("#catSelect").dropdownchecklist({emptyText:'选择类别...', maxDropHeight:200, width:300, explicitClose:'...关闭'});
    });
  });

  var confNum = 0;

  if (!Array.prototype.forEach) {
    Array.prototype.forEach = function(fn, scope) {
      for (var i = 0, len = this.length; i < len; ++i) {
        fn.call(scope, this[i], i, this);
      }
    };
  }

  if (!String.prototype.format) {
    String.prototype.format = function() {
      var args = arguments;
      return this.replace(/{(\d+)}/g, function(match, number) {
        return typeof args[number] != 'undefined' ? args[number] : match;
      });
    };
  }
  if (!String.prototype.replaceAll) {
    String.prototype.replaceAll = function(str1, str2, ignore) {
      return this.replace(new RegExp(str1.replace(/([\/\,\!\\\^\$\{\}\[\]\(\)\.\*\+\?\|\<\>\-\&])/g,"\\$&"),(ignore?"gi":"g")),(typeof(str2)=="string")?str2.replace(/\$/g,"$$$$"):str2);
    };
  }
  // Create config nodes using templateDisplayConfig
  function makeConfig(opts) {
    confNum += 1;
    var newConfig = $("#templateDisplayConfig").html();
    newConfig = newConfig.format(opts.Name,
                                 opts.Target,
                                 opts.Default,
                                 opts.Mode,
                                 opts.Description,
                                 opts.Type,
                                 opts.Display,
                                 opts.Required,
                                 opts.Mask,
                                 escapeQuote(opts.Value),
                                 opts.Buttons,
                                 (opts.Required == "true") ? "required" : ""
                                );
    newConfig = "<div id='ConfigNum"+opts.Number+"' class='config_"+opts.Display+"'' >"+newConfig+"</div>";
    newConfig = $($.parseHTML(newConfig));
    value     = newConfig.find("input[name='confValue[]']");
    if (opts.Type == "Path") {
      value.attr("onclick", "openFileBrowser(this,$(this).val(),'',true,false);");
    } else if (opts.Type == "Device") {
      value.attr("onclick", "openFileBrowser(this,$(this).val()||'/dev','',false,true);")
    } else if (opts.Type == "Variable" && opts.Default.split("|").length > 1) {
      var valueOpts = opts.Default.split("|");
      var newValue = "<select name='confValue[]' class='selectVariable' default='"+valueOpts[0]+"'>";
      for (var i = 0; i < valueOpts.length; i++) {
        newValue += "<option value='"+valueOpts[i]+"' "+(opts.Value == valueOpts[i] ? "selected" : "")+">"+valueOpts[i]+"</option>";
      }
      newValue += "</select>";
      value.replaceWith(newValue);
    } else if (opts.Type == "Port") {
      value.addClass("numbersOnly");
    }
    if (opts.Mask == "true") {
      value.prop("type", "password");
    }
    return newConfig.prop('outerHTML');
  }
  
  function escapeQuote(string) {
    return string.replace(new RegExp('"','g'),"&quot;");
  }
  
  function makeAllocations(container,current) {
    var html = [];
    for (var i=0,ct; ct=container[i]; i++) {
      var highlight = ct.Name.toLowerCase()==current.toLowerCase() ? "font-weight:bold" : "";
      html.push($("#templateAllocations").html().format(highlight,ct.Name,ct.Port));
    }
    return html.join('');
  }

  function getVal(el, name) {
    var el = $(el).find("*[name="+name+"]");
    if (el.length) {
      return ( $(el).attr('type') == 'checkbox' ) ? ($(el).is(':checked') ? "on" : "off") : $(el).val();
    } else {
      return "";
    }
  }

  function addConfigPopup() {
    var title = '添加配置';
    var popup = $( "#dialogAddConfig" );

    // Load popup the popup with the template info
    popup.html($("#templatePopupConfig").html());

    // Add switchButton to checkboxes
    popup.find(".switch").switchButton({labels_placement:"right",on_label:'YES',off_label:'NO'});
    popup.find(".switch-button-background").css("margin-top", "6px");

    // Load Mode field if needed and enable field
    toggleMode(popup.find("*[name=Type]:first"),false);

    // Start Dialog section
    popup.dialog({
      title: title,
      resizable: false,
      width: 900,
      modal: true,
      show : {effect: 'fade' , duration: 250},
      hide : {effect: 'fade' , duration: 250},
      buttons: {
        "Add": function() {
          $(this).dialog("close");
          confNum += 1;
          var Opts = Object;
          var Element = this;
          ["Name","Target","Default","Mode","Description","Type","Display","Required","Mask","Value"].forEach(function(e){
            Opts[e] = getVal(Element, e);
          });
          if (! Opts.Name ){
            Opts.Name = makeName(Opts.Type);
          }
          if (! Opts.Description ) {
            Opts.Description = "容器 "+Opts.Type+": "+Opts.Target;
          }
          if (Opts.Required == "true") {
            Opts.Buttons  = "<span class='advanced'><button type='button' onclick='editConfigPopup("+confNum+",false)'>编辑</button>";
            Opts.Buttons += "<button type='button' onclick='removeConfig("+confNum+")'>删除</button></span>";
          } else {
            Opts.Buttons  = "<button type='button' onclick='editConfigPopup("+confNum+",false)'>编辑</button>";
            Opts.Buttons += "<button type='button' onclick='removeConfig("+confNum+")'>删除</button>";
          }
          Opts.Number = confNum;
          newConf = makeConfig(Opts);
          $("#configLocation").append(newConf);
          reloadTriggers();
          $('input[name="contName"]').trigger('change'); // signal change
        },
        Cancel: function() {
          $(this).dialog("close");
        }
      }
    });
    $(".ui-dialog .ui-dialog-titlebar").addClass('menu');
    $(".ui-dialog .ui-dialog-title").css('text-align','center').css( 'width', "100%");
    $(".ui-dialog .ui-dialog-content").css('padding-top','15px').css('vertical-align','bottom');
    $(".ui-button-text").css('padding','0px 5px');
  }

  function editConfigPopup(num,disabled) {
    var title = '编辑配置';
    var popup = $("#dialogAddConfig");

    // Load popup the popup with the template info
    popup.html($("#templatePopupConfig").html());

    // Load existing config info
    var config = $("#ConfigNum"+num);
    config.find("input").each(function(){
      var name = $(this).attr("name").replace("conf", "").replace("[]", "");
      popup.find("*[name='"+name+"']").val($(this).val());
    });

    // Hide passwords if needed
    if (popup.find("*[name='Mask']").val() == "true") {
      popup.find("*[name='Value']").prop("type", "password");
    }

    // Load Mode field if needed
    var mode = config.find("input[name='confMode[]']").val();
    toggleMode(popup.find("*[name=Type]:first"),disabled);
    popup.find("*[name=Mode]:first").val(mode);

    // Add switchButton to checkboxes
    popup.find(".switch").switchButton({labels_placement:"right",on_label:'YES',off_label:'NO'});

    // Start Dialog section
    popup.find(".switch-button-background").css("margin-top", "6px");
    popup.dialog({
      title: title,
      resizable: false,
      width: 900,
      modal: true,
      show : {effect: 'fade' , duration: 250},
      hide : {effect: 'fade' , duration: 250},
      buttons: {
        "Save": function() {
          $(this).dialog("close");
          var Opts = Object;
          var Element = this;
          ["Name","Target","Default","Mode","Description","Type","Display","Required","Mask","Value"].forEach(function(e){
            Opts[e] = getVal(Element, e);
          });
          if (Opts.Display == "always-hide" || Opts.Display == "advanced-hide") {
            Opts.Buttons  = "<span class='advanced'><button type='button' onclick='editConfigPopup("+num+",<?=$disableEdit?>)'>编辑</button>";
            Opts.Buttons += "<button type='button' onclick='removeConfig("+num+")'>删除</button></span>";
          } else {
            Opts.Buttons  = "<button type='button' onclick='editConfigPopup("+num+",<?=$disableEdit?>)'>编辑</button>";
            Opts.Buttons += "<button type='button' onclick='removeConfig("+num+")'>删除</button>";
          }
          if (! Opts.Name ){
            Opts.Name = makeName(Opts.Type);
          }
          if (! Opts.Description ) {
            Opts.Description = "容器 "+Opts.Type+": "+Opts.Target;
          }
          Opts.Number = num;
          newConf = makeConfig(Opts);
          if (config.hasClass("config_"+Opts.Display)) {
            config.html(newConf);
            config.removeClass("config_always config_always-hide config_advanced config_advanced-hide").addClass("config_"+Opts.Display);
          } else {
            config.remove();
            if (Opts.Display == 'advanced' || Opts.Display == 'advanced-hide') {
              $("#configLocationAdvanced").append(newConf);
            } else {
              $("#configLocation").append(newConf);
            }
          }
         reloadTriggers();
          $('input[name="contName"]').trigger('change'); // signal change
        },
        Cancel: function() {
          $(this).dialog("close");
        }
      }
    });
    $(".ui-dialog .ui-dialog-titlebar").addClass('menu');
    $(".ui-dialog .ui-dialog-title").css('text-align','center').css( 'width', "100%");
    $(".ui-dialog .ui-dialog-content").css('padding-top','15px').css('vertical-align','bottom');
    $(".ui-button-text").css('padding','0px 5px');
    $('.desc_readmore').readmore({maxHeight:10});
  }

  function removeConfig(num) {
    $('#ConfigNum'+num).fadeOut("fast", function() {$(this).remove();});
    $('input[name="contName"]').trigger('change'); // signal change
  }

  function prepareConfig(form) {
    var types = [], values = [], targets = [];
    if ($('select[name="contNetwork"]').val()=='host') {
      $(form).find('input[name="confType[]"]').each(function(){types.push($(this).val());});
      $(form).find('input[name="confValue[]"]').each(function(){values.push($(this));});
      $(form).find('input[name="confTarget[]"]').each(function(){targets.push($(this));});
      for (var i=0; i < types.length; i++) if (types[i]=='Port') $(targets[i]).val($(values[i]).val());
    }
    var vcpu = [];
    $(form).find('input[id^="box"]').each(function(){if ($(this).prop('checked')) vcpu.push($('#'+$(this).prop('id').replace('box','cpu')).text());});
    form.contCPUset.value = vcpu.join(',');
  }

  function makeName(type) {
    i = $("#configLocation input[name^='confType'][value='"+type+"']").length+1;
    return "Host "+type.replace('Variable','Key')+" "+i;
  }

  function toggleMode(el,disabled) {
    var mode       = $(el).parent().siblings('#Mode');
    var valueDiv   = $(el).parent().siblings('#Value');
    var defaultDiv = $(el).parent().siblings('#Default');
    var targetDiv  = $(el).parent().siblings('#Target');

    var value      = valueDiv.find('input[name=Value]');
    var target     = targetDiv.find('input[name=Target]');
    var driver     = drivers[$('select[name="contNetwork"]')[0].value];

    value.unbind();
    target.unbind();

    valueDiv.css('display', '');
    defaultDiv.css('display', '');
    targetDiv.css('display', '');
    mode.html('');

    $(el).prop('disabled',disabled);
    switch ($(el)[0].selectedIndex) {
    case 0: // Path
      mode.html("<dt>访问模式:</dt><dd><select name='Mode'><option value='rw'>读/写</option><option value='rw,slave'>读写/从属</option><option value='rw,shared'>读写/共享</option><option value='ro'>只读</option><option value='ro,slave'>只读/从属</option><option value='ro,shared'>只读/共享</option></select></dd>");
      value.bind("click", function(){openFileBrowser(this,$(this).val(), 'sh', true, false);});
      targetDiv.find('#dt1').text('容器路径:');
      valueDiv.find('#dt2').text('主机路径:');
      break;
    case 1: // Port
      mode.html("<dt>连接类型:</dt><dd><select name='Mode'><option value='tcp'>TCP</option><option value='udp'>UDP</option></select></dd>");
      value.addClass("numbersOnly");
      if (driver=='bridge') {
        if (target.val()) target.prop('disabled',<?=$disableEdit?>); else target.addClass("numbersOnly");
        targetDiv.find('#dt1').text('容器端口:');
        targetDiv.show();
      } else {
        targetDiv.hide();
      }
      if (driver!='null') {
        valueDiv.find('#dt2').text('主机端口:');
        valueDiv.show();
      } else {
        valueDiv.hide();
        mode.html('');
      }
      break;
    case 2: // Variable
      targetDiv.find('#dt1').text('密钥:');
      valueDiv.find('#dt2').text('值:');
      break;
    case 3: // Label
      targetDiv.find('#dt1').text('密钥:');
      valueDiv.find('#dt2').text('值:');
      break;
    case 4: // Device
      targetDiv.hide();
      defaultDiv.hide();
      valueDiv.find('#dt2').text('值:');
      value.bind("click", function(){openFileBrowser(this,$(this).val()||'/dev', '', true, true);});
      break;
    }
    reloadTriggers();
  }

  function loadTemplate(el) {
    var template = $(el).val();
    if (template.length) {
      $('#formTemplate').find("input[name='xmlTemplate']").val(template);
      $('#formTemplate').submit();
    }
  }

  function rmTemplate(tmpl) {
    var name = tmpl.split(/[\/]+/).pop();
    swal({title:"你确定吗?",text:"删除模板: "+name,type:"warning",showCancelButton:true},function(){$("#rmTemplate").val(tmpl);$("#formTemplate").submit();});
  }

  function openFileBrowser(el, root, filter, on_folders, on_files, close_on_select) {
    if (on_folders === undefined) on_folders = true;
    if (on_files   === undefined) on_files = true;
    if (!filter && !on_files) filter = 'HIDE_FILES_FILTER';
    if (!root.trim()) root = "/mnt/user/";
    p = $(el);
    // Skip is fileTree is already open
    if (p.next().hasClass('fileTree')) return null;
    // create a random id
    var r = Math.floor((Math.random()*1000)+1);
    // Add a new span and load fileTree
    p.after("<span id='fileTree"+r+"' class='textarea fileTree'></span>");
    var ft = $('#fileTree'+r);
    ft.fileTree({
      root: root,
      filter: filter,
      allowBrowsing: true
    },
    function(file){if(on_files){p.val(file);p.trigger('change');if(close_on_select){ft.slideUp('fast',function(){ft.remove();});}}},
    function(folder){if(on_folders){p.val(folder);p.trigger('change');if(close_on_select){$(ft).slideUp('fast',function (){$(ft).remove();});}}}
    );
    // Format fileTree according to parent position, height and width
    ft.css({'left':p.position().left,'top':(p.position().top+p.outerHeight()),'width':(p.width())});
    // close if click elsewhere
    $(document).mouseup(function(e){if(!ft.is(e.target) && ft.has(e.target).length === 0){ft.slideUp('fast',function (){$(ft).remove();});}});
    // close if parent changed
    p.bind("keydown", function(){ft.slideUp('fast', function (){$(ft).remove();});});
    // Open fileTree
    ft.slideDown('fast');
  }

  function resetField(el) {
    var target = $(el).prev();
    reset = target.attr("default");
    if (reset.length) {
      target.val(reset);
    }
  }

  function prepareCategory() {
    var values = $.map($('#catSelect option') ,function(option) {
      if ($(option).is(":selected")) {
        return option.value;
      }
    });
    $("input[name='contCategory']").val(values.join(" "));
  }
  var drivers = {};
  <?foreach ($driver as $d => $v) echo "drivers['$d']='$v';\n";?>
</script>
<div id="docker_tabbed" style="float:right;margin-top:-47px"></div>
<div id="dialogAddConfig" style="display:none"></div>
<form method="GET" id="formTemplate">
  <input type="hidden" id="xmlTemplate" name="xmlTemplate" value="" />
  <input type="hidden" id="rmTemplate" name="rmTemplate" value="" />
</form>

<div id="canvas">
  <form method="POST" autocomplete="off" onsubmit="prepareConfig(this)">
    <input type="hidden" name="csrf_token" value="<?=$var['csrf_token']?>">
    <input type="hidden" name="contCPUset" value="">
    <table class="settings">
      <? if ($xmlType == 'edit'):
      if ($DockerClient->doesContainerExist($templateName)): echo "<input type='hidden' name='existingContainer' value='${templateName}'>\n"; endif;
      else:?>
      <tr class='TemplateDropDown'>
        <td>模板:</td>
        <td>
          <select id="TemplateSelect" size="1" onchange="loadTemplate(this);">
            <option value="">选择一个模板</option>
            <?
            $rmadd = '';
            $templates = [];
            $templates['default'] = $DockerTemplates->getTemplates('default');
            $templates['user'] = $DockerTemplates->getTemplates('user');
            foreach ($templates as $section => $template) {
              $title = ucfirst($section)." templates";
              printf("<optgroup class='title bold' label='[ %s ]'>", htmlspecialchars($title));
              foreach ($template as $value){
                $name = str_replace('my-', '', $value['name']);
                $selected = (isset($xmlTemplate) && $value['path']==$xmlTemplate) ? ' selected ' : '';
                if ($selected && $section=='default') $showAdditionalInfo = 'class="advanced"';
                if ($selected && $section=='user') $rmadd = $value['path'];
                printf("<option class='list' value='%s:%s' $selected>%s</option>", htmlspecialchars($section), htmlspecialchars($value['path']), htmlspecialchars($name));
              }
              if (!$template) echo("<option class='list' disabled>&lt;none&gt;</option>");
              printf("</optgroup>");
            }
            ?>
          </select>
          <?if ($rmadd) {
            echo "<i class='fa fa-window-close button' title=\"".htmlspecialchars($rmadd)."\" onclick='rmTemplate(\"".addslashes(htmlspecialchars($rmadd))."\")'></i>";
          }?>
        </td>
      </tr>
      <tr>
        <td colspan="2">
          <blockquote class="inline_help">
            <p>模板是在 Unraid 服务器上设置 Docker 容器更快捷的方法. 有两种类型的模板:</p>

            <p>
              <b>默认模板</b><br>
              将有效存储库添加到 Docker Repositories 页面时, 它们将显示在此下拉列表中供您选择 (按作者分类的主存储库, 然后按应用程序模板分类).
              选择默认模板后, 页面将在 '说明' 字段中填充有关应用程序的新信息, 并通常提供有关如何设置容器的说明.
              第一次配置此应用程序时, 请选择默认模板.
            </p>

            <p>
              <b>用户定义的模板</b><br>
              Once you've added an application to your system through a Default template,
              the settings you specified are saved to your USB flash device to make it easy to rebuild your applications in the event an upgrade were to fail or if another issue occurred.
              To rebuild, simply select the previously loaded application from the User-defined list and all the settings for the container will appear populated from your previous setup.
              Clicking create will redownload the necessary files for the application and should restore you to a working state.
              To delete a User-defined template, select it from the list above and click the red X to the right of it.
            </p>
          </blockquote>
        </td>
      </tr>
      <?endif;?>
      <tr <?=$showAdditionalInfo?>>
        <td>名称:</td>
        <td><input type="text" name="contName" required></td>
      </tr>
      <tr <?=$showAdditionalInfo?>>
        <td colspan="2">
          <blockquote class="inline_help">
            <p>为容器命名或保留默认值.</p>
          </blockquote>
        </td>
      </tr>
      <tr id="Overview" class="basic">
        <td>概览:</td>
        <td><div id="contDescription" class="blue-text textTemplate"></div></td>
      </tr>
      <tr id="Overview" class="advanced">
        <td>概览:</td>
        <td><textarea name="contOverview" rows="10" class="textTemplate"></textarea></td>
      </tr>
      <tr>
        <td colspan="2">
          <blockquote class="inline_help">
            <p>应用程序容器的描述, 支持基本的 HTML 标记.</p>
          </blockquote>
        </td>
      </tr>
      <tr <?=$showAdditionalInfo?>>
        <td>存储库:</td>
        <td><input type="text" name="contRepository" required></td>
      </tr>
      <tr <?=$showAdditionalInfo?>>
        <td colspan="2">
          <blockquote class="inline_help">
            <p>The repository for the application on the Docker Registry.  Format of authorname/appname.
            Optionally you can add a : after appname and request a specific version for the container image.</p>
          </blockquote>
        </td>
      </tr>
      <tr class="<?=$authoring;?>">
        <td>分类:</td>
        <td>
          <input type="hidden" name="contCategory">
          <select id="catSelect" size="1" multiple="multiple" style="display:none" onchange="prepareCategory();">
            <optgroup label="Categories">
              <option value="Backup:">备份</option>
              <option value="Cloud:">云</option>
              <option value="Downloaders:">下载器</option>
              <option value="GameServers:">游戏服务器</option>
              <option value="HomeAutomation:">家庭自动化</option>
              <option value="Productivity:">团队管理</option>
              <option value="Tools:">工具</option>
              <option value="Other:">其它</option>
            </optgroup>
            <optgroup label="MediaApp">
              <option value="MediaApp:Video">媒体应用:视频</option>
              <option value="MediaApp:Music">媒体应用:音乐</option>
              <option value="MediaApp:Books">媒体应用:书籍</option>
              <option value="MediaApp:Photos">媒体应用:照片</option>
              <option value="MediaApp:Other">媒体应用:其它</option>
            </optgroup>
            <optgroup label="MediaServer">
              <option value="MediaServer:Video">媒体服务器:视频</option>
              <option value="MediaServer:Music">媒体服务器:音乐</option>
              <option value="MediaServer:Books">媒体服务器:书籍</option>
              <option value="MediaServer:Photos">媒体服务器:照片</option>
              <option value="MediaServer:Other">媒体服务器:其它</option>
            </optgroup>
            <optgroup label="Network">
              <option value="Network:Web">网络:Web</option>
              <option value="Network:DNS">网络:DNS</option>
              <option value="Network:FTP">网络:FTP</option>
              <option value="Network:Proxy">网络:代理</option>
              <option value="Network:Voip">网络:Voip</option>
              <option value="Network:Management">网络:管理</option>
              <option value="Network:Messenger">网络:消息</option>
              <option value="Network:VPN">Network:VPN</option>
              <option value="Network:Other">网络:其它</option>
            </optgroup>
            <optgroup label="Development Status">
              <option value="Status:Stable">状态:稳定版</option>
              <option value="Status:Beta">状态:测试版</option>
            </optgroup>
          </select>
        </td>
      </tr>
      <tr class="<?=$authoring;?>">
        <td>支持帖:</td>
        <td><input type="text" name="contSupport"></td>
      </tr>
      <tr class="<?=$authoring;?>">
        <td colspan="2">
          <blockquote class="inline_help">
            <p>链接到 Lime-Technology 论坛上的支持帖.</p>
          </blockquote>
        </td>
      </tr>
      <tr class="<?=$authoring;?>">
        <td>项目页:</td>
        <td><input type="text" name="contProject"></td>
      </tr>
      <tr class="<?=$authoring;?>">
        <td colspan="2">
          <blockquote class="inline_help">
            <p>链接到项目页面 (例如: www.plex.tv)</p>
          </blockquote>
        </td>
      </tr>
      <tr class="<?=$authoring;?>">
        <td>捐赠文本:</td>
        <td><input type="text" name="contDonateText"></td>
      </tr>
      <tr class="<?=$authoring;?>">
        <td colspan="2">
          <blockquote class="inline_help">
            <p>在 '应用程序' 标签中的捐赠链接上显示的文字</p>
          </blockquote>
        </td>
      </tr>
      <tr class="<?=$authoring;?>">
        <td>捐赠链接:</td>
        <td><input type="text" name="contDonateLink"></td>
      </tr>
      <tr class="<?=$authoring;?>">
        <td colspan="2">
          <blockquote class="inline_help">
            <p>链接到捐赠页面. 如果使用捐赠, 则必须同时设置图像和链接</p>
          </blockquote>
        </td>
      </tr>
      <tr class="advanced">
        <td>Docker Hub URL:</td>
        <td><input type="text" name="contRegistry"></td>
      </tr>
      <tr class="advanced">
        <td colspan="2">
          <blockquote class="inline_help">
            <p>在 Docker Hub 上容器的存储库位置的路径.</p>
          </blockquote>
        </td>
      </tr>
      <tr class='noshow'> <!-- Deprecated for author to enter or change, but needs to be present -->
        <td>模板 URL:</td>
        <td><input type="text" name="contTemplateURL"></td>
      </tr>
      <tr class="<?=$authoring;?>">
        <td colspan="2">
          <blockquote class="inline_help">
            <p>此 URL 用于保持模板更新.</p>
          </blockquote>
        </td>
      </tr>
      <tr class="advanced">
        <td>Icon URL:</td>
        <td><input type="text" name="contIcon"></td>
      </tr>
      <tr class="advanced">
        <td colspan="2">
          <blockquote class="inline_help">
            <p>链接到应用程序的图标 (如果 '显示设置' 下的 '显示仪表板应用程序' 设置为 '图标'，则仅显示在仪表板上).</p>
          </blockquote>
        </td>
      </tr>
      <tr class="advanced">
        <td>WebUI:</td>
        <td><input type="text" name="contWebUI"></td>
      </tr>
      <tr class="advanced">
        <td colspan="2">
          <blockquote class="inline_help">
            <p>When you click on an application icon from the Docker Containers page, the WebUI option will link to the path in this field.
            Use [IP] to identify the IP of your host and [PORT:####] replacing the #'s for your port.</p>
          </blockquote>
        </td>
      </tr>
      <tr class="advanced">
        <td>额外参数:</td>
        <td><input type="text" name="contExtraParams"></td>
      </tr>
      <tr class="advanced">
        <td colspan="2">
          <blockquote class="inline_help">
            <p>If you wish to append additional commands to your Docker container at run-time, you can specify them here.<br>
            For all possible Docker run-time commands, see here: <a href="https://docs.docker.com/reference/run/" target="_blank">https://docs.docker.com/reference/run/</a></p>
          </blockquote>
        </td>
      </tr>
      <tr class="advanced">
        <td>后置参数:</td>
        <td><input type="text" name="contPostArgs"></td>
      </tr>
      <tr class="advanced">
        <td colspan="2">
          <blockquote class="inline_help">
            <p>如果希望在容器定义之后附加其他参数, 可以在此处指定它们.
            此字段的内容特定于容器.</p>
          </blockquote>
        </td>
      </tr>

      <tr class="advanced">
        <td>CPU 绑定:</td>
        <td><?cpu_pinning()?></td>
      </tr>
      <tr class="advanced">
        <td colspan="2">
          <blockquote class="inline_help">
            <p>检查 CPU 核心, 将限制容器仅在选定的核心上运行. 选择 '无核心' 允许容器在所有可用核心上运行 (默认)</p>
          </blockquote>
        </td>
      </tr>


      <tr <?=$showAdditionalInfo?>>
        <td>网络类型:</td>
        <td>
          <select name="contNetwork" onchange="showSubnet(this.value)">
          <?=mk_option(1,'bridge','桥接')?>
          <?=mk_option(1,'host','主机')?>
          <?=mk_option(1,'none','无')?>
          <?foreach ($custom as $network):?>
          <?=mk_option(1,$network,"自定义 : $network")?>
          <?endforeach;?>
          </select>
        </td>
      </tr>
      <tr class="myIP" style="display:none">
        <td>固定 IP 地址 (可选):</td>
        <td><input type="text" name="contMyIP"><span id="myIP"></span></td>
      </tr>
      <tr <?=$showAdditionalInfo?>>
        <td colspan="2">
          <blockquote class="inline_help">
            <p>如果选择桥接类型, 则应用程序的网络访问将仅限于在端口映射部分中指定的端口上进行通信.
            如果选择了主机类型, 应用程序将被授予使用主机上尚未映射到另一个正在使用的应用程序/服务的任何端口进行通信的访问权限.
            一般来说, 建议将此设置保留为每个应用程序模板指定的默认值</p>
            <p>重要说明: 如果要调整端口映射, 请不要修改 Container 端口的设置, 因为只能调整 Host 端口.</p>
          </blockquote>
        </td>
      </tr>
      <tr <?=$showAdditionalInfo?>>
        <td>控制台 Shell 命令:</td>
        <td><select name="contShell">
            <?=mk_option(1,'sh','Shell')?>
            <?=mk_option(1,'bash','Bash')?>
            </select>
        </td>
      </tr>
      <tr <?=$showAdditionalInfo?>>
        <td class="spacer">特权:</td>
        <td class="spacer"><input type="checkbox" name="contPrivileged" class="switch-on-off"></td>
      </tr>
      <tr <?=$showAdditionalInfo?>>
        <td colspan="2">
          <blockquote class="inline_help">
            <p>对于需要直接使用主机设备访问或需要完全暴露主机功能的容器, 需要选择此选项.
            <br>更多信息, 请访问: <a href="https://docs.docker.com/engine/reference/run/#runtime-privilege-and-linux-capabilities" target="_blank">https://docs.docker.com/engine/reference/run/#runtime-privilege-and-linux-capabilities</a></p>
          </blockquote>
        </td>
      </tr>
    </table>
    <div id="configLocation"></div>
    <table class="settings">
      <tr>
        <td></td>
        <td id="readmore_toggle" class="readmore_collapsed"><a onclick="toggleReadmore()" style="cursor:pointer"><i class="fa fa-chevron-down"></i> 显示更多设置 ...</a></td>
      </tr>
    </table>
    <div id="configLocationAdvanced" style="display:none"></div><br>
    <table class="settings">
      <tr>
        <td></td>
        <td id="allocations_toggle" class="readmore_collapsed"><a onclick="toggleAllocations()" style="cursor:pointer"><i class="fa fa-chevron-down"></i> 显示 Docker allocations ...</a></td>
      </tr>
    </table>
    <div id="dockerAllocations" style="display:none"></div><br>
    <table class="settings">
      <tr>
        <td></td>
        <td><a href="javascript:addConfigPopup()"><i class="fa fa-plus"></i> 添加其他路径, 端口, 变量, 标签或设备</a></td>
      </tr>
    </table>
    <br>
    <table class="settings">
      <tr>
        <td></td>
        <td>
          <input type="submit" value="<?=$xmlType=='edit' ? 'Apply':' Apply '?>"><input type="button" value="完成" onclick="done()">
          <?if ($authoringMode):?><button type="submit" name="dryRun" value="true" onclick="$('*[required]').prop('required', null);">保存</button><?endif;?>
        </td>
      </tr>
    </table>
    <br><br><br>
  </form>
</div>

<?
#        ██╗███████╗    ████████╗███████╗███╗   ███╗██████╗ ██╗      █████╗ ████████╗███████╗███████╗
#        ██║██╔════╝    ╚══██╔══╝██╔════╝████╗ ████║██╔══██╗██║     ██╔══██╗╚══██╔══╝██╔════╝██╔════╝
#        ██║███████╗       ██║   █████╗  ██╔████╔██║██████╔╝██║     ███████║   ██║   █████╗  ███████╗
#   ██   ██║╚════██║       ██║   ██╔══╝  ██║╚██╔╝██║██╔═══╝ ██║     ██╔══██║   ██║   ██╔══╝  ╚════██║
#   ╚█████╔╝███████║       ██║   ███████╗██║ ╚═╝ ██║██║     ███████╗██║  ██║   ██║   ███████╗███████║
#    ╚════╝ ╚══════╝       ╚═╝   ╚══════╝╚═╝     ╚═╝╚═╝     ╚══════╝╚═╝  ╚═╝   ╚═╝   ╚══════╝╚══════╝
?>
<div id="templatePopupConfig" style="display:none">
  <dl>
    <dt>配置类型:</dt>
    <dd>
      <select name="Type" onchange="toggleMode(this,false);">
        <option value="Path">路径</option>
        <option value="Port">端口</option>
        <option value="Variable">变量</option>
        <option value="Label">标签</option>
        <option value="Device">设备</option>
      </select>
    </dd>
    <dt>名称:</dt>
    <dd><input type="text" name="Name"></dd>
    <div id="Target">
      <dt id="dt1">目标:</dt>
      <dd><input type="text" name="Target"></dd>
    </div>
    <div id="Value">
      <dt id="dt2">值:</dt>
      <dd><input type="text" name="Value"></dd>
    </div>
    <div id="Default" class="advanced">
      <dt>默认值:</dt>
      <dd><input type="text" name="Default"></dd>
    </div>
    <div id="Mode"></div>
    <dt>说明:</dt>
    <dd>
      <textarea name="Description" rows="6" style="width:304px;"></textarea>
    </dd>
    <div class="advanced">
      <dt>显示:</dt>
      <dd>
        <select name="Display">
          <option value="always" selected>总是</option>
          <option value="always-hide">总是 - 隐藏按钮</option>
          <option value="advanced">高级</option>
          <option value="advanced-hide">高级 - 隐藏按钮</option>
        </select>
      </dd>
      <dt>必要的:</dt>
      <dd>
        <select name="Required">
          <option value="false" selected>否</option>
          <option value="true">是</option>
        </select>
      </dd>
      <div id="Mask">
        <dt>密码掩码:</dt>
        <dd>
          <select name="Mask">
            <option value="false" selected>否</option>
            <option value="true">是</option>
          </select>
        </dd>
      </div>
    </div>
  </dl>
</div>

<div id="templateDisplayConfig" style="display:none">
  <input type="hidden" name="confName[]" value="{0}">
  <input type="hidden" name="confTarget[]" value="{1}">
  <input type="hidden" name="confDefault[]" value="{2}">
  <input type="hidden" name="confMode[]" value="{3}">
  <input type="hidden" name="confDescription[]" value="{4}">
  <input type="hidden" name="confType[]" value="{5}">
  <input type="hidden" name="confDisplay[]" value="{6}">
  <input type="hidden" name="confRequired[]" value="{7}">
  <input type="hidden" name="confMask[]" value="{8}">
  <table class="settings">
    <tr>
      <td class="{11}" style="vertical-align:top;">{0}:</td>
      <td>
        <input type="text" name="confValue[]" default="{2}" value="{9}" autocomplete="off" {11}>&nbsp;{10}
        <div class="orange-text">{4}</div>
      </td>
    </tr>
  </table>
</div>

<div id="templateAllocations" style="display:none">
<table class='settings'>
  <tr><td></td><td style="{0}"><span style="width:160px;display:inline-block;padding-left:20px">{1}</span>{2}</td></tr>
</table>
</div>

<script>
  var subnet = {};
<?foreach ($subnet as $network => $value):?>
  subnet['<?=$network?>'] = '<?=$value?>';
<?endforeach;?>

  function showSubnet(bridge) {
    if (bridge.match(/^(bridge|host|none)$/i) !== null) {
      $('.myIP').hide();
      $('input[name="contMyIP"]').val('');
    } else {
      $('.myIP').show();
      $('#myIP').html('Subnet: '+subnet[bridge]);
    }
  }
  function reloadTriggers() {
    $(".basic").toggle(!$(".advanced-switch:first").is(":checked"));
    $(".advanced").toggle($(".advanced-switch:first").is(":checked"));
    $(".numbersOnly").keypress(function(e){if(e.which != 45 && e.which != 8 && e.which != 0 && (e.which < 48 || e.which > 57)){return false;}});
  }
  function toggleReadmore() {
    var readm = $('#readmore_toggle');
    if ( readm.hasClass('readmore_collapsed') ) {
      readm.removeClass('readmore_collapsed').addClass('readmore_expanded');
      $('#configLocationAdvanced').slideDown('fast');
      readm.find('a').html('<i class="fa fa-chevron-up"></i> 隐藏更多设置 ...');
    } else {
      $('#configLocationAdvanced').slideUp('fast');
      readm.removeClass('readmore_expanded').addClass('readmore_collapsed');
      readm.find('a').html('<i class="fa fa-chevron-down"></i> 显示更多设置 ...');
    }
  }
  function toggleAllocations() {
    var readm = $('#allocations_toggle');
    if ( readm.hasClass('readmore_collapsed') ) {
      readm.removeClass('readmore_collapsed').addClass('readmore_expanded');
      $('#dockerAllocations').slideDown('fast');
      readm.find('a').html('<i class="fa fa-chevron-up"></i> 隐藏 Docker allocations ...');
    } else {
      $('#dockerAllocations').slideUp('fast');
      readm.removeClass('readmore_expanded').addClass('readmore_collapsed');
      readm.find('a').html('<i class="fa fa-chevron-down"></i> 显示 Docker allocations ...');
    }
  }
  function load_contOverview() {
    var new_overview = $("textarea[name='contOverview']").val();
    new_overview = new_overview.replaceAll("[","<").replaceAll("]",">");
    $("#contDescription").html(new_overview);
  }
  $(function() {
    // Load container info on page load
    if (typeof Settings != 'undefined') {
      for (var key in Settings) {
        if (Settings.hasOwnProperty(key)) {
          var target = $('#canvas').find('*[name=cont'+key+']:first');
          if (target.length) {
            var value = Settings[key];
            if (target.attr("type") == 'checkbox') {
              target.prop('checked', (value == 'true'));
            } else if ($(target).prop('nodeName') == 'DIV') {
              target.html(value);
            } else {
              target.val(value);
            }
          }
        }
      }
      load_contOverview();
      // Load the confCategory input into the s1 select
      categories=$("input[name='contCategory']").val().split(" ");
      for (var i = 0; i < categories.length; i++) {
        $("#catSelect option[value='"+categories[i]+"']").prop("selected", true);
      }
      // Remove empty description
      if (!Settings.Description.length) {
        $('#canvas').find('#Overview:first').hide();
      }
      // Load config info
      var network = $('select[name="contNetwork"]')[0].selectedIndex;
      for (var i = 0; i < Settings.Config.length; i++) {
        confNum += 1;
        Opts = Settings.Config[i];
        if (Opts.Display == "always-hide" || Opts.Display == "advanced-hide") {
          Opts.Buttons  = "<span class='advanced'><button type='button' onclick='editConfigPopup("+confNum+",<?=$disableEdit?>)'>编辑</button>";
          Opts.Buttons += "<button type='button' onclick='removeConfig("+confNum+")'>删除</button></span>";
        } else {
          Opts.Buttons  = "<button type='button' onclick='editConfigPopup("+confNum+",<?=$disableEdit?>)'>编辑</button>";
          Opts.Buttons += "<button type='button' onclick='removeConfig("+confNum+")'>删除</button>";
        }
        Opts.Number = confNum;
        newConf = makeConfig(Opts);
        if (Opts.Display == 'advanced' || Opts.Display == 'advanced-hide') {
          $("#configLocationAdvanced").append(newConf);
        } else {
          $("#configLocation").append(newConf);
        }
      }
    } else {
      $('#canvas').find('#Overview:first').hide();
    }
    // Show associated subnet with fixed IP (if existing)
    showSubnet($('select[name="contNetwork"]').val());
    // Add list of docker allocations
    $("#dockerAllocations").html(makeAllocations(Allocations,$('input[name="contName"]').val()));
    // Add switchButton
    $('.switch-on-off').each(function(){var checked = $(this).is(":checked");$(this).switchButton({labels_placement: "right", checked:checked});});
    // Add dropdownchecklist to Select Categories
    $("#catSelect").dropdownchecklist({emptyText:'选择类别...', maxDropHeight:200, width:300, explicitClose:'...关闭'});
    <?if ($authoringMode){
      echo "$('.advanced-switch').prop('checked','true'); $('.advanced-switch').change();";
      echo "$('.advanced-switch').siblings('.switch-button-background').click();";
    }?>
  });
  if ( window.location.href.indexOf("/Apps/") > 0 ) {
    $(".TemplateDropDown").hide();
  }
</script>
<?END:?>
