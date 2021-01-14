<?PHP
/* Copyright 2005-2018, Lime Technology
 * Copyright 2015-2018, Derek Macias, Eric Schultz, Jon Panozzo.
 * Copyright 2012-2018, Bergware International.
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
	require_once "$docroot/webGui/include/Helpers.php";
	require_once "$docroot/plugins/dynamix.vm.manager/include/libvirt_helpers.php";

	$hdrXML = "<?xml version='1.0' encoding='UTF-8'?>\n"; // XML encoding declaration

	// create new VM
	if ($_POST['createvm']) {
		$new = $lv->domain_define($_POST['xmldesc'], $_POST['domain']['startnow']==1);
		if ($new){
			$lv->domain_set_autostart($new, $_POST['domain']['autostart']==1);
			$reply = ['success' => true];
		} else {
			$reply = ['error' => $lv->get_last_error()];
		}
		echo json_encode($reply);
		exit;
	}

	// update existing VM
	if ($_POST['updatevm']) {
		$uuid = $_POST['domain']['uuid'];
		$dom = $lv->domain_get_domain_by_uuid($uuid);
		$oldAutoStart = $lv->domain_get_autostart($dom)==1;
		$newAutoStart = $_POST['domain']['autostart']==1;
		$strXML = $lv->domain_get_xml($dom);

		// delete and create the VM
		$lv->nvram_backup($uuid);
		$lv->domain_undefine($dom);
		$lv->nvram_restore($uuid);
		$new = $lv->domain_define($_POST['xmldesc']);
		if ($new) {
			$lv->domain_set_autostart($new, $newAutoStart);
			$reply = ['success' => true];
		} else {
			// Failure -- try to restore existing VM
			$reply = ['error' => $lv->get_last_error()];
			$old = $lv->domain_define($strXML);
			if ($old) $lv->domain_set_autostart($old, $oldAutoStart);
		}
		echo json_encode($reply);
		exit;
	}

	if ($_GET['uuid']) {
		// edit an existing VM
		$uuid = $_GET['uuid'];
		$dom = $lv->domain_get_domain_by_uuid($uuid);
		$boolRunning = $lv->domain_get_state($dom)=='running';
		$strXML = $lv->domain_get_xml($dom);
	} else {
		// edit new VM
		$uuid = '';
		$boolRunning = false;
		$strXML = '';
	}
?>

<link rel="stylesheet" href="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/lib/codemirror.css')?>">
<link rel="stylesheet" href="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/addon/hint/show-hint.css')?>">
<style type="text/css">
	.CodeMirror { border: 1px solid #eee; cursor: text; margin-top: 15px; margin-bottom: 10px; }
	.CodeMirror pre.CodeMirror-placeholder { color: #999; }
</style>

<input type="hidden" name="domain[uuid]" value="<?=htmlspecialchars($uuid)?>">

<textarea id="addcode" name="xmldesc" placeholder="复制并在此处粘贴域 XML 配置." autofocus><?=htmlspecialchars($hdrXML).htmlspecialchars($strXML)?></textarea>

<? if (!$boolRunning) { ?>
	<? if ($strXML) { ?>
		<input type="hidden" name="updatevm" value="1" />
		<input type="button" value="更新" busyvalue="更新中..." readyvalue="更新" id="btnSubmit" />
	<? } else { ?>
		<label for="domain_start"><input type="checkbox" name="domain[startnow]" id="domain_start" value="1" checked="checked"/>创建后启动虚拟机</label>
		<br>
		<input type="hidden" name="createvm" value="1" />
		<input type="button" value="创建" busyvalue="创建中..." readyvalue="创建" id="btnSubmit" />
	<? } ?>
	<input type="button" value="取消" id="btnCancel" />
<? } else { ?>
	<input type="button" value="完成" id="btnCancel" />
<? } ?>

<script src="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/lib/codemirror.js')?>"></script>
<script src="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/addon/display/placeholder.js')?>"></script>
<script src="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/addon/fold/foldcode.js')?>"></script>
<script src="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/addon/hint/show-hint.js')?>"></script>
<script src="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/addon/hint/xml-hint.js')?>"></script>
<script src="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/addon/hint/libvirt-schema.js')?>"></script>
<script src="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/mode/xml/xml.js')?>"></script>
<script>
$(function() {
	function completeAfter(cm, pred) {
		var cur = cm.getCursor();
		if (!pred || pred()) setTimeout(function() {
			if (!cm.state.completionActive)
				cm.showHint({completeSingle: false});
		}, 100);
		return CodeMirror.Pass;
	}

	function completeIfAfterLt(cm) {
		return completeAfter(cm, function() {
			var cur = cm.getCursor();
			return cm.getRange(CodeMirror.Pos(cur.line, cur.ch - 1), cur) == "<";
		});
	}

	function completeIfInTag(cm) {
		return completeAfter(cm, function() {
			var tok = cm.getTokenAt(cm.getCursor());
			if (tok.type == "string" && (!/['"]/.test(tok.string.charAt(tok.string.length - 1)) || tok.string.length == 1)) return false;
			var inner = CodeMirror.innerMode(cm.getMode(), tok.state).state;
			return inner.tagName;
		});
	}

	var editor = CodeMirror.fromTextArea(document.getElementById("addcode"), {
		mode: "xml",
		lineNumbers: true,
		foldGutter: true,
		gutters: ["CodeMirror-linenumbers", "CodeMirror-foldgutter"],
		extraKeys: {
			"'<'": completeAfter,
			"'/'": completeIfAfterLt,
			"' '": completeIfInTag,
			"'='": completeIfInTag,
			"Ctrl-Space": "autocomplete"
		},
		hintOptions: {schemaInfo: getLibvirtSchema()}
	});

	setTimeout(function() {
		editor.refresh();
	}, 1);

	$("#vmform #btnSubmit").click(function frmSubmit() {
		var $button = $(this);
		var $form = $button.closest('form');

		editor.save();

		$form.find('input').prop('disabled', false); // enable all inputs otherwise they wont post

		var postdata = $form.serialize().replace(/'/g,"%27");

		$form.find('input').prop('disabled', true);
		$button.val($button.attr('busyvalue'));

		$.post("/plugins/dynamix.vm.manager/templates/<?=basename(__FILE__)?>", postdata, function( data ) {
			if (data.success) {
				done();
			}
			if (data.error) {
				swal({title:"虚拟机创建错误",text:data.error,type:"error"});
				$form.find('input').prop('disabled', false);
				$button.val($button.attr('readyvalue'));
			}
		}, "json");
	});
});
</script>
