<?PHP
/* Copyright 2018, Lime Technology
 * Copyright 2018, Derek Macias, Eric Schultz, Jon Panozzo.
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
	require_once "$docroot/webGui/include/Custom.php";
	require_once "$docroot/plugins/dynamix.vm.manager/include/libvirt_helpers.php";

	$arrValidMachineTypes = getValidMachineTypes();
	$arrValidGPUDevices = getValidGPUDevices();
	$arrValidAudioDevices = getValidAudioDevices();
	$arrValidOtherDevices = getValidOtherDevices();
	$arrValidUSBDevices = getValidUSBDevices();
	$arrValidDiskDrivers = getValidDiskDrivers();
	$arrValidBridges = getNetworkBridges();
	$strCPUModel = getHostCPUModel();

	// Read localpaths in from libreelec.cfg
	$strLibreELECConfig = "/boot/config/plugins/dynamix.vm.manager/libreelec.cfg";
	$arrLibreELECConfig = [];

	if (file_exists($strLibreELECConfig)) {
		$arrLibreELECConfig = parse_ini_file($strLibreELECConfig);
	} elseif (!file_exists(dirname($strLibreELECConfig))) {
		@mkdir(dirname($strLibreELECConfig), 0777, true);
	}

	// Compare libreelec.cfg and populate 'localpath' in $arrOEVersion
	foreach ($arrLibreELECConfig as $strID => $strLocalpath) {
		if (array_key_exists($strID, $arrLibreELECVersions)) {
			$arrLibreELECVersions[$strID]['localpath'] = $strLocalpath;
			if (file_exists($strLocalpath)) {
				$arrLibreELECVersions[$strID]['valid'] = '1';
			}
		}
	}

	if ($_POST['delete_version']) {
		$arrDeleteLibreELEC = [];
		if (array_key_exists($_POST['delete_version'], $arrLibreELECVersions)) {
			$arrDeleteLibreELEC = $arrLibreELECVersions[$_POST['delete_version']];
		}
		$reply = [];
		if (empty($arrDeleteLibreELEC)) {
			$reply = ['error' => '未知的版本: ' . $_POST['delete_version']];
		} else {
			// delete img file
			@unlink($arrDeleteLibreELEC['localpath']);

			// Save to strLibreELECConfig
			unset($arrLibreELECConfig[$_POST['delete_version']]);
			$text = '';
			foreach ($arrLibreELECConfig as $key => $value) $text .= "$key=\"$value\"\n";
			file_put_contents($strLibreELECConfig, $text);
			$reply = ['status' => 'ok'];
		}

		echo json_encode($reply);
		exit;
	}

	if ($_POST['download_path']) {
		$arrDownloadLibreELEC = [];
		if (array_key_exists($_POST['download_version'], $arrLibreELECVersions)) {
			$arrDownloadLibreELEC = $arrLibreELECVersions[$_POST['download_version']];
		}
		if (empty($arrDownloadLibreELEC)) {
			$reply = ['error' => '未知的版本: ' . $_POST['download_version']];
		} elseif (empty($_POST['download_path'])) {
			$reply = ['error' => '请选择 LibreELEC 镜像将下载到的位置'];
		} else {
			@mkdir($_POST['download_path'], 0777, true);
			$_POST['download_path'] = realpath($_POST['download_path']) . '/';

			// Check free space
			if (disk_free_space($_POST['download_path']) < $arrDownloadLibreELEC['size']+10000) {
				$reply = [
					'error' => '没有足够的可用空间, 至少需要 ' . ceil($arrDownloadLibreELEC['size']/1000000).'MB'
				];
				echo json_encode($reply);
				exit;
			}

			$boolCheckOnly = !empty($_POST['checkonly']);
			$strInstallScript = '/tmp/LibreELEC_' . $_POST['download_version'] . '_install.sh';
			$strInstallScriptPgrep = '-f "LibreELEC_' . $_POST['download_version'] . '_install.sh"';
			$strTempFile = $_POST['download_path'] . basename($arrDownloadLibreELEC['url']);
			$strLogFile = $strTempFile . '.log';
			$strMD5File = $strTempFile . '.md5';
			$strMD5StatusFile = $strTempFile . '.md5status';
			$strExtractedFile = $_POST['download_path'] . basename($arrDownloadLibreELEC['url'], 'tar.xz') . 'img';

			// Save to strLibreELECConfig
			$arrLibreELECConfig[$_POST['download_version']] = $strExtractedFile;
			$text = '';
			foreach ($arrLibreELECConfig as $key => $value) $text .= "$key=\"$value\"\n";
			file_put_contents($strLibreELECConfig, $text);

			$strDownloadCmd = 'wget -nv -c -O ' . escapeshellarg($strTempFile) . ' ' . escapeshellarg($arrDownloadLibreELEC['url']);
			$strDownloadPgrep = '-f "wget.*' . $strTempFile . '.*' . $arrDownloadLibreELEC['url'] . '"';
			$strVerifyCmd = 'md5sum -c ' . escapeshellarg($strMD5File);
			$strVerifyPgrep = '-f "md5sum.*' . $strMD5File . '"';
			$strExtractCmd = 'tar Jxf ' . escapeshellarg($strTempFile) . ' -C ' . escapeshellarg(dirname($strTempFile));
			$strExtractPgrep = '-f "tar.*' . $strTempFile . '.*' . dirname($strTempFile) . '"';
			$strCleanCmd = '(chmod 777 ' . escapeshellarg($_POST['download_path']) . ' ' . escapeshellarg($strExtractedFile) . '; chown nobody:users ' . escapeshellarg($_POST['download_path']) . ' ' . escapeshellarg($strExtractedFile) . '; rm ' . escapeshellarg($strTempFile) . ' ' . escapeshellarg($strMD5File) . ' ' . escapeshellarg($strMD5StatusFile) . ')';
			$strCleanPgrep = '-f "chmod.*chown.*rm.*' . $strMD5StatusFile . '"';
			$strAllCmd = "#!/bin/bash\n\n";
			$strAllCmd .= $strDownloadCmd . ' >>' . escapeshellarg($strLogFile) . ' 2>&1 && ';
			$strAllCmd .= 'echo "' . $arrDownloadLibreELEC['md5'] . '  ' . $strTempFile . '" > ' . escapeshellarg($strMD5File) . ' && ';
			$strAllCmd .= $strVerifyCmd . ' >' . escapeshellarg($strMD5StatusFile) . ' 2>/dev/null && ';
			$strAllCmd .= $strExtractCmd . ' >>' . escapeshellarg($strLogFile) . ' 2>&1 && ';
			$strAllCmd .= $strCleanCmd . ' >>' . escapeshellarg($strLogFile) . ' 2>&1 && ';
			$strAllCmd .= 'rm ' . escapeshellarg($strLogFile) . ' && ';
			$strAllCmd .= 'rm ' . escapeshellarg($strInstallScript);

			$reply = [];
			if (file_exists($strExtractedFile)) {
				if (!file_exists($strTempFile)) {
					// Status = done
					$reply['status'] = 'Done';
					$reply['localpath'] = $strExtractedFile;
					$reply['localfolder'] = dirname($strExtractedFile);
				} else {
					if (pgrep($strExtractPgrep, false)) {
						// Status = running extract
						$reply['status'] = 'Extracting ... ';
					} else {
						// Status = cleanup
						$reply['status'] = 'Cleanup ... ';
					}
				}
			} elseif (file_exists($strTempFile)) {
				if (pgrep($strDownloadPgrep, false)) {
					// Get Download percent completed
					$intSize = filesize($strTempFile);
					$strPercent = 0;
					if ($intSize > 0) {
						$strPercent = round(($intSize / $arrDownloadLibreELEC['size']) * 100);
					}
					$reply['status'] = 'Downloading ... ' . $strPercent . '%';
				} elseif (pgrep($strVerifyPgrep, false)) {
					// Status = running md5 check
					$reply['status'] = 'Verifying ... ';
				} elseif (file_exists($strMD5StatusFile)) {
					// Status = running extract
					$reply['status'] = 'Extracting ... ';
					if (!pgrep($strExtractPgrep, false)) {
						// Examine md5 status
						$strMD5StatusContents = file_get_contents($strMD5StatusFile);
						if (strpos($strMD5StatusContents, ': FAILED') !== false) {
							// ERROR: MD5 check failed
							unset($reply['status']);
							$reply['error'] = 'MD5 verification failed, your download is incomplete or corrupted.';
						}
					}
				} elseif (!file_exists($strMD5File)) {
					// Status = running md5 check
					$reply['status'] = 'Downloading ... 100%';
					if (!pgrep($strInstallScriptPgrep, false) && !$boolCheckOnly) {
						// Run all commands
						file_put_contents($strInstallScript, $strAllCmd);
						chmod($strInstallScript, 0777);
						exec($strInstallScript . ' >/dev/null 2>&1 &');
					}
				}
			} elseif (!$boolCheckOnly) {
				if (!pgrep($strInstallScriptPgrep, false)) {
					// Run all commands
					file_put_contents($strInstallScript, $strAllCmd);
					chmod($strInstallScript, 0777);
					exec($strInstallScript . ' >/dev/null 2>&1 &');
				}
				$reply['status'] = 'Downloading ... ';
			}
			$reply['pid'] = pgrep($strInstallScriptPgrep, false);
		}
		echo json_encode($reply);
		exit;
	}

	$arrLibreELECVersion = reset($arrLibreELECVersions);
	$strLibreELECVersionID = key($arrLibreELECVersions);

	$arrConfigDefaults = [
		'template' => [
			'name' => $strSelectedTemplate,
			'icon' => $arrAllTemplates[$strSelectedTemplate]['icon'],
			'libreelec' => $strLibreELECVersionID
		],
		'domain' => [
			'name' => $strSelectedTemplate,
			'persistent' => 1,
			'uuid' => $lv->domain_generate_uuid(),
			'clock' => 'utc',
			'arch' => 'x86_64',
			'machine' => getLatestMachineType('q35'),
			'mem' => 512 * 1024,
			'maxmem' => 512 * 1024,
			'password' => '',
			'cpumode' => 'host-passthrough',
			'vcpus' => 1,
			'vcpu' => [0],
			'hyperv' => 0,
			'ovmf' => 1,
			'usbmode' => 'usb3'
		],
		'media' => [
			'cdrom' => '',
			'cdrombus' => '',
			'drivers' => '',
			'driversbus' => ''
		],
		'disk' => [
			[
				'image' => $arrLibreELECVersion['localpath'],
				'size' => '',
				'driver' => 'raw',
				'dev' => 'hda',
				'readonly' => 1
			]
		],
		'gpu' => [
			[
				'id' => '',
				'mode' => 'qxl',
				'keymap' => 'en-us'
			]
		],
		'audio' => [
			[
				'id' => ''
			]
		],
		'pci' => [],
		'nic' => [
			[
				'network' => $domain_bridge,
				'mac' => $lv->generate_random_mac_addr()
			]
		],
		'usb' => [],
		'shares' => [
			[
				'source' => (is_dir('/mnt/user/appdata') ? '/mnt/user/appdata/LibreELEC/' : ''),
				'target' => 'appconfig'
			]
		]
	];

$hdrXML = "<?xml version='1.0' encoding='UTF-8'?>\n"; // XML encoding declaration

	// Merge in any default values from the VM template
	if ($arrAllTemplates[$strSelectedTemplate] && $arrAllTemplates[$strSelectedTemplate]['overrides']) {
		$arrConfigDefaults = array_replace_recursive($arrConfigDefaults, $arrAllTemplates[$strSelectedTemplate]['overrides']);
	}

	// create new VM
	if ($_POST['createvm']) {
		if ($_POST['xmldesc']) {
			// XML view
			$new = $lv->domain_define($_POST['xmldesc'], $_POST['domain']['xmlstartnow']==1);
			if ($new){
				$lv->domain_set_autostart($new, $_POST['domain']['autostart']==1);
				$reply = ['success' => true];
			} else {
				$reply = ['error' => $lv->get_last_error()];
			}
		} else {
			// form view
			if ($_POST['shares'][0]['source']) {
				@mkdir($_POST['shares'][0]['source'], 0777, true);
			}
			if ($lv->domain_new($_POST)){
				$reply = ['success' => true];
			} else {
				$reply = ['error' => $lv->get_last_error()];
			}
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

		if ($lv->domain_get_state($dom)=='running') {
			$arrErrors = [];
			$arrExistingConfig = domain_to_config($uuid);
			$arrNewUSBIDs = $_POST['usb'];

			// hot-attach any new usb devices
			foreach ($arrNewUSBIDs as $strNewUSBID) {
				foreach ($arrExistingConfig['usb'] as $arrExistingUSB) {
					if ($strNewUSBID == $arrExistingUSB['id']) continue 2;
				}
				list($strVendor,$strProduct) = explode(':', $strNewUSBID);
				// hot-attach usb
				file_put_contents('/tmp/hotattach.tmp', "<hostdev mode='subsystem' type='usb'><source startupPolicy='optional'><vendor id='0x".$strVendor."'/><product id='0x".$strProduct."'/></source></hostdev>");
				exec("virsh attach-device ".escapeshellarg($uuid)." /tmp/hotattach.tmp --live 2>&1", $arrOutput, $intReturnCode);
				unlink('/tmp/hotattach.tmp');
				if ($intReturnCode != 0) {
					$arrErrors[] = implode(' ', $arrOutput);
				}
			}

			// hot-detach any old usb devices
			foreach ($arrExistingConfig['usb'] as $arrExistingUSB) {
				if (!in_array($arrExistingUSB['id'], $arrNewUSBIDs)) {
					list($strVendor, $strProduct) = explode(':', $arrExistingUSB['id']);
					file_put_contents('/tmp/hotdetach.tmp', "<hostdev mode='subsystem' type='usb'><source startupPolicy='optional'><vendor id='0x".$strVendor."'/><product id='0x".$strProduct."'/></source></hostdev>");
					exec("virsh detach-device ".escapeshellarg($uuid)." /tmp/hotdetach.tmp --live 2>&1", $arrOutput, $intReturnCode);
					unlink('/tmp/hotdetach.tmp');
					if ($intReturnCode != 0) $arrErrors[] = implode(' ',$arrOutput);
				}
			}
			$reply = !$arrErrors ? ['success' => true] : ['error' => implode(', ',$arrErrors)];
			echo json_encode($reply);
			exit;
		}

		// backup xml for existing domain in ram
		if ($dom && !$_POST['xmldesc']) {
			$oldName = $lv->domain_get_name($dom);
			$newName = $_POST['domain']['name'];
			$oldDir = $domain_cfg['DOMAINDIR'].$oldName;
			$newDir = $domain_cfg['DOMAINDIR'].$newdName;
			if ($oldName && $newName && is_dir($oldDir) && !is_dir($newDir)) {
				// mv domain/vmname folder
				if (rename($oldDir, $newDir)) {
					// replace all disk paths in xml
					foreach ($_POST['disk'] as &$arrDisk) {
						if ($arrDisk['new']) $arrDisk['new'] = str_replace($oldDir, $newDir, $arrDisk['new']);
						if ($arrDisk['image']) $arrDisk['image'] = str_replace($oldDir, $newDir, $arrDisk['image']);
					}
				}
			}
		}

		// construct updated config
		if ($_POST['xmldesc']) {
			// XML view
			$xml = $_POST['xmldesc'];
		} else {
			// form view
			if ($_POST['shares'][0]['source']) {
				@mkdir($_POST['shares'][0]['source'], 0777, true);
			}
			$arrExistingConfig = custom::createArray('domain',$strXML);
			$arrUpdatedConfig = custom::createArray('domain',$lv->config_to_xml($_POST));
			array_update_recursive($arrExistingConfig, $arrUpdatedConfig);
			$arrConfig = array_replace_recursive($arrExistingConfig, $arrUpdatedConfig);
			$xml = custom::createXML('domain',$arrConfig)->saveXML();
		}
		// delete and create the VM
		$lv->nvram_backup($uuid);
		$lv->domain_undefine($dom);
		$lv->nvram_restore($uuid);
		$new = $lv->domain_define($xml);
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
		$boolNew = false;
		$arrConfig = array_replace_recursive($arrConfigDefaults, domain_to_config($uuid));
	} else {
		// edit new VM
		$boolRunning = false;
		$strXML = '';
		$boolNew = true;
		$arrConfig = $arrConfigDefaults;
	}

	if (array_key_exists($arrConfig['template']['libreelec'], $arrLibreELECVersions)) {
		$arrConfigDefaults['disk'][0]['image'] = $arrLibreELECVersions[$arrConfig['template']['libreelec']]['localpath'];
	}
?>

<link rel="stylesheet" href="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/lib/codemirror.css')?>">
<link rel="stylesheet" href="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/addon/hint/show-hint.css')?>">
<style type="text/css">
	.CodeMirror { border: 1px solid #eee; cursor: text; margin-top: 15px; margin-bottom: 10px; }
	.CodeMirror pre.CodeMirror-placeholder { color: #999; }
	#libreelec_image {
		color: #BBB;
		display: none;
		transform: translate(0px, 3px);
	}
	.delete_libreelec_image {
		cursor: pointer;
		margin-left: -5px;
		margin-right: 5px;
		color: #CC0011;
		font-size: 1.4rem;
		transform: translate(0px, 3px);
	}
</style>

<div class="formview">
<input type="hidden" name="domain[persistent]" value="<?=htmlspecialchars($arrConfig['domain']['persistent'])?>">
<input type="hidden" name="domain[uuid]" value="<?=htmlspecialchars($arrConfig['domain']['uuid'])?>">
<input type="hidden" name="domain[clock]" id="domain_clock" value="<?=htmlspecialchars($arrConfig['domain']['clock'])?>">
<input type="hidden" name="domain[arch]" value="<?=htmlspecialchars($arrConfig['domain']['arch'])?>">
<input type="hidden" name="domain[oldname]" value="<?=htmlspecialchars($arrConfig['domain']['name'])?>">

<input type="hidden" name="disk[0][image]" id="disk_0" value="<?=htmlspecialchars($arrConfig['disk'][0]['image'])?>">
<input type="hidden" name="disk[0][dev]" value="<?=htmlspecialchars($arrConfig['disk'][0]['dev'])?>">
<input type="hidden" name="disk[0][readonly]" value="1">

	<div class="installed">
		<table>
			<tr>
				<td>名称:</td>
				<td><input type="text" name="domain[name]" id="domain_name" class="textTemplate" title="虚拟机名称" placeholder="例如 LibreELEC" value="<?=htmlspecialchars($arrConfig['domain']['name'])?>" required /></td>
			</tr>
		</table>
		<blockquote class="inline_help">
			<p>给虚拟机起个名字 (例如 LibreELEC Family Room, LibreELEC Theatre, LibreELEC)</p>
		</blockquote>

		<table>
			<tr class="advanced">
				<td>描述:</td>
				<td><input type="text" name="domain[desc]" title="虚拟机描述" placeholder="虚拟机描述 (可选)" value="<?=htmlspecialchars($arrConfig['domain']['desc'])?>" /></td>
			</tr>
		</table>
		<div class="advanced">
			<blockquote class="inline_help">
				<p>给虚拟机一个简短的描述 (可选字段).</p>
			</blockquote>
		</div>
	</div>

	<table>
		<tr>
			<td>LibreELEC 版本:</td>
			<td>
				<select name="template[libreelec]" id="template_libreelec" class="narrow" title="选择要使用的 LibreELEC 版本">
				<?php
					foreach ($arrLibreELECVersions as $strOEVersion => $arrOEVersion) {
						$strDefaultFolder = '';
						if (!empty($domain_cfg['DOMAINDIR']) && file_exists($domain_cfg['DOMAINDIR'])) {
							$strDefaultFolder = str_replace('//', '/', $domain_cfg['DOMAINDIR'].'/LibreELEC/');
						}
						$strLocalFolder = ($arrOEVersion['localpath'] == '' ? $strDefaultFolder : dirname($arrOEVersion['localpath']));
						echo mk_option($arrConfig['template']['libreelec'], $strOEVersion, $arrOEVersion['name'], 'localpath="' . $arrOEVersion['localpath'] . '" localfolder="' . $strLocalFolder . '" valid="' . $arrOEVersion['valid'] . '"');
					}
				?>
				</select> <i class="fa fa-trash delete_libreelec_image installed" title="删除 LibreELEC 镜像"></i> <span id="libreelec_image" class="installed"></span>
			</td>
		</tr>
	</table>
	<blockquote class="inline_help">
		<p>选择要下载或用于此虚拟机的 LibreELEC 版本</p>
	</blockquote>

	<div class="available">
		<table>
			<tr>
				<td>下载文件夹:</td>
				<td>
					<input type="text" data-pickfolders="true" data-pickfilter="NO_FILES_FILTER" data-pickroot="/mnt/" value="" id="download_path" placeholder="例如 /mnt/user/domains/" title="选择一个将 LibreELEC 镜像下载到的文件夹" />
				</td>
			</tr>
		</table>
		<blockquote class="inline_help">
			<p>选择一个将 LibreELEC 镜像下载到的文件夹</p>
		</blockquote>

		<table>
			<tr>
				<td></td>
				<td>
					<input type="button" value="下载" busyvalue="下载中..." readyvalue="下载" id="btnDownload" />
					<br>
					<div id="download_status"></div>
				</td>
			</tr>
		</table>
	</div>

	<div class="installed">
		<table>
			<tr>
				<td>配置文件夹:</td>
				<td>
					<input type="text" data-pickfolders="true" data-pickfilter="NO_FILES_FILTER" data-pickroot="/mnt/" value="<?=htmlspecialchars($arrConfig['shares'][0]['source'])?>" name="shares[0][source]" placeholder="例如 /mnt/user/appdata/libreelec" title="在 Unraid 的共享路径上保存 LibrELEC 设置" required/>
					<input type="hidden" value="<?=htmlspecialchars($arrConfig['shares'][0]['target'])?>" name="shares[0][target]" />
				</td>
			</tr>
		</table>
		<blockquote class="inline_help">
			<p>选择一个现有文件夹或键入新名称, 以指定 LibreELEC 将在何处保存配置文件. 如果创建多个 LibreELEC 虚拟机, 则这些配置文件夹对于每个实例都必须是唯一的.</p>
		</blockquote>

		<table>
			<tr class="advanced">
				<td>CPU 模式:</td>
				<td>
					<select name="domain[cpumode]" title="定义给此虚拟机的 CPU 类型">
					<?php mk_dropdown_options(['host-passthrough' => '主机直通 (' . $strCPUModel . ')', 'emulated' => '模拟 (QEMU64)'], $arrConfig['domain']['cpumode']); ?>
					</select>
				</td>
			</tr>
		</table>
		<div class="advanced">
			<blockquote class="inline_help">
				<p>有两种 CPU 模式可供选择:</p>
				<p>
					<b>主机直通</b><br>
					在这种模式下, 客户机可见的 CPU 应该与主机 CPU 完全相同, 即使在 Libvirt 不理解的方面也是如此. 为了获得最佳性能, 请使用此设置.
				</p>
				<p>
					<b>模拟</b><br>
					如果主机直通模式有问题, 可以尝试模拟模式, 该模式不会给来宾暴露基于主机的 CPU 功能. 这可能会影响虚拟机的性能.
				</p>
			</blockquote>
		</div>

		<table>
			<tr>
				<td>逻辑 CPU:</td>
				<td>
					<div class="textarea four">
					<?
					$cpus = cpu_list();
					foreach ($cpus as $pair) {
						unset($cpu1,$cpu2);
						list($cpu1, $cpu2) = preg_split('/[,-]/',$pair);
						$extra = in_array($cpu1, $arrConfig['domain']['vcpu']) ? ($arrConfig['domain']['vcpus'] > 1 ? 'checked' : 'checked disabled') : '';
						if (!$cpu2) {
							echo "<label for='vcpu$cpu1' class='checkbox'>cpu $cpu1<input type='checkbox' name='domain[vcpu][]' class='domain_vcpu' id='vcpu$cpu1' value='$cpu1' $extra><span class='checkmark'></span></label>";
						} else {
							echo "<label for='vcpu$cpu1' class='cpu1 checkbox'>cpu $cpu1 / $cpu2<input type='checkbox' name='domain[vcpu][]' class='domain_vcpu' id='vcpu$cpu1' value='$cpu1' $extra><span class='checkmark'></span></label>";
							$extra = in_array($cpu2, $arrConfig['domain']['vcpu']) ? ($arrConfig['domain']['vcpus'] > 1 ? 'checked' : 'checked disabled') : '';
							echo "<label for='vcpu$cpu2' class='cpu2 checkbox'><input type='checkbox' name='domain[vcpu][]' class='domain_vcpu' id='vcpu$cpu2' value='$cpu2' $extra><span class='checkmark'></span></label>";
						}
					}
					?>
					</div>
				</td>
			</tr>
		</table>
		<blockquote class="inline_help">
			<p>通过将处理器上 CPU 核心数乘以线程数, 可以确定系统中逻辑 CPU 的数量.</p>
			<p>选择您希望允许虚拟机使用的逻辑 CPU. (至少 1).</p>
		</blockquote>

		<table>
			<tr>
				<td><span class="advanced">初始</span>内存:</td>
				<td>
					<select name="domain[mem]" id="domain_mem" class="narrow" title="定义内存大小">
					<?php
						for ($i = 1; $i <= ($maxmem*2); $i++) {
							$label = ($i * 512) . ' MB';
							$value = $i * 512 * 1024;
							echo mk_option($arrConfig['domain']['mem'], $value, $label);
						}
					?>
					</select>
				</td>

				<td class="advanced">最大内存:</td>
				<td class="advanced">
					<select name="domain[maxmem]" id="domain_maxmem" class="narrow" title="定义最大内存大小">
					<?php
						for ($i = 1; $i <= ($maxmem*2); $i++) {
							$label = ($i * 512) . ' MB';
							$value = $i * 512 * 1024;
							echo mk_option($arrConfig['domain']['maxmem'], $value, $label);
						}
					?>
					</select>
				</td>
				<td></td>
			</tr>
		</table>
		<div class="basic">
			<blockquote class="inline_help">
				<p>选择启动时分配给虚拟机的内存量.</p>
			</blockquote>
		</div>
		<div class="advanced">
			<blockquote class="inline_help">
				<p>对于没有任何 PCI 设备直通的虚拟机 (GPU, 声音等), 可以设置初始和最大内存为不同的值, 以允许内存膨胀. 如果有直通 PCI 设备, 则仅使用初始内存值, 而忽略最大内存值. 有关 KVM 内存膨胀的更多信息, 请参阅 <a href="http://www.linux-kvm.org/page/FAQ#Is_dynamic_memory_management_for_guests_supported.3F" target="_new">这里</a>.</p>
			</blockquote>
		</div>

		<table>
			<tr class="advanced">
				<td>机器:</td>
				<td>
					<select name="domain[machine]" class="narrow" id="domain_machine" title="选择机器型号.  i440fx 将适用于大多数情况.  Q35 适用于具有 PCIE 的较新机型">
					<?php mk_dropdown_options($arrValidMachineTypes, $arrConfig['domain']['machine']); ?>
					</select>
				</td>
			</tr>
		</table>
		<div class="advanced">
			<blockquote class="inline_help">
				<p>机器类型选项主要影响某些用户在使用各种硬件和 GPU 时可能获得的成功. 有关各种 QEMU 机器类型的更多信息, 请参见以下链接:</p>
				<a href="http://wiki.qemu.org/Documentation/Platforms/PC" target="_blank">http://wiki.qemu.org/Documentation/Platforms/PC</a><br>
				<a href="http://wiki.qemu.org/Features/Q35" target="_blank">http://wiki.qemu.org/Features/Q35</a><br>
				<p>根据经验, 请尝试首先使配置与 i440fx 配合使用, 如果失败, 请尝试调整至 Q35 以查看是否有任何改变.</p>
			</blockquote>
		</div>

		<table>
			<tr class="advanced">
				<td>BIOS:</td>
				<td>
					<select name="domain[ovmf]" id="domain_ovmf" class="narrow" title="选择 BIOS.  SeaBIOS 对大多数人都有用.  OVMF 需要一个 UEFI 兼容的操作系统 (例如. Windows 8/2012, 新的 Linux 发行版) 如果使用图形设备也需要支持 UEFI">
					<?php
						echo mk_option($arrConfig['domain']['ovmf'], '0', 'SeaBIOS');

						if (file_exists('/usr/share/qemu/ovmf-x64/OVMF_CODE-pure-efi.fd')) {
							echo mk_option($arrConfig['domain']['ovmf'], '1', 'OVMF');
						} else {
							echo mk_option('', '0', 'OVMF (Not Available)', 'disabled="disabled"');
						}
					?>
					</select>
				</td>
			</tr>
		</table>
		<div class="advanced">
			<blockquote class="inline_help">
				<p>
					<b>SeaBIOS</b><br>
					is the default virtual BIOS used to create virtual machines and is compatible with all guest operating systems (Windows, Linux, etc.).
				</p>
				<p>
					<b>OVMF</b><br>
					(Open Virtual Machine Firmware) adds support for booting VMs using UEFI, but virtual machine guests must also support UEFI.  Assigning graphics devices to a OVMF-based virtual machine requires that the graphics device also support UEFI.
				</p>
				<p>
					Once a VM is created this setting cannot be adjusted.
				</p>
			</blockquote>
		</div>

		<table>
			<tr class="advanced">
				<td>USB 控制器:</td>
				<td>
					<select name="domain[usbmode]" id="usbmode" class="narrow" title="选择要模拟的 USB 控制器.">
					<?php
						echo mk_option($arrConfig['domain']['usbmode'], 'usb2', '2.0 (EHCI)');
						echo mk_option($arrConfig['domain']['usbmode'], 'usb3', '3.0 (nec XHCI)');
						echo mk_option($arrConfig['domain']['usbmode'], 'usb3-qemu', '3.0 (qemu XHCI)');
					?>
					</select>
				</td>
			</tr>
		</table>
		<div class="advanced">
			<blockquote class="inline_help">
				<p>
					<b>USB 控制器</b><br>
					选择要模拟的 USB 控制器. Qemu XHCI 与 Nec XHCI 是相同的代码基础, 建议在使用 nec XHCI 之前尝试 qemu XHCI.
				</p>
			</blockquote>
		</div>

		<? foreach ($arrConfig['gpu'] as $i => $arrGPU) {
			$strLabel = ($i > 0) ? appendOrdinalSuffix($i + 1) : '';

			?>
			<table data-category="Graphics_Card" data-multiple="true" data-minimum="1" data-maximum="<?=count($arrValidGPUDevices)?>" data-index="<?=$i?>" data-prefix="<?=$strLabel?>">
				<tr>
					<td>显卡</td>
					<td>
						<select name="gpu[<?=$i?>][id]" class="gpu narrow">
						<?
							if ($i == 0) {
								// Only the first video card can be VNC
								echo mk_option($arrGPU['id'], 'vnc', 'VNC');
							} else {
								echo mk_option($arrGPU['id'], '', '无');
							}

							foreach($arrValidGPUDevices as $arrDev) {
								echo mk_option($arrGPU['id'], $arrDev['id'], $arrDev['name'].' ('.$arrDev['id'].')');
							}
						?>
						</select>
					</td>
				</tr>
				<tr class="<? if ($arrGPU['id'] == 'vnc') echo 'was'; ?>advanced romfile">
					<td>图形 BIOS ROM:</td>
					<td>
						<input type="text" data-pickcloseonfile="true" data-pickfilter="rom,bin" data-pickmatch="^[^.].*" data-pickroot="/" value="<?=htmlspecialchars($arrGPU['rom'])?>" name="gpu[<?=$i?>][rom]" placeholder="BIOS ROM 文件路径 (可选)" title="BIOS ROM 文件路径 (可选)" />
					</td>
				</tr>
			</table>
			<? if ($i == 0) { ?>
			<blockquote class="inline_help">
				<p>
					<b>显卡</b><br>
					如果要将图形卡分配给虚拟机, 请从此列表中选择它.
				</p>

				<p class="<? if ($arrGPU['id'] == 'vnc') echo 'was'; ?>advanced romfile">
					<b>图形 ROM BIOS </b><br>
					如果您想使用图形卡的自定义 BIOS ROM 请在此处指定一个.
				</p>

				<? if (count($arrValidGPUDevices) > 1) { ?>
				<p>可以通过单击左侧的符号来 添加/删除 其他设备.</p>
				<? } ?>
			</blockquote>
			<? } ?>
		<? } ?>
		<script type="text/html" id="tmplGraphics_Card">
			<table>
				<tr>
					<td>显卡</td>
					<td>
						<select name="gpu[{{INDEX}}][id]" class="gpu narrow">
						<?php
							echo mk_option('', '', '无');

							foreach($arrValidGPUDevices as $arrDev) {
								echo mk_option('', $arrDev['id'], $arrDev['name'].' ('.$arrDev['id'].')');
							}
						?>
						</select>
					</td>
				</tr>
				<tr class="advanced romfile">
					<td>图形 BIOS ROM:</td>
					<td>
						<input type="text" data-pickcloseonfile="true" data-pickfilter="rom,bin" data-pickmatch="^[^.].*" data-pickroot="/" value="" name="gpu[{{INDEX}}][rom]" placeholder="BIOS ROM 文件路径 (可选)" title="BIOS ROM 文件路径 (可选)" />
					</td>
				</tr>
			</table>
		</script>

		<? foreach ($arrConfig['audio'] as $i => $arrAudio) {
			$strLabel = ($i > 0) ? appendOrdinalSuffix($i + 1) : '';

			?>
			<table data-category="Sound_Card" data-multiple="true" data-minimum="1" data-maximum="<?=count($arrValidAudioDevices)?>" data-index="<?=$i?>" data-prefix="<?=$strLabel?>">
				<tr>
					<td>声卡:</td>
					<td>
						<select name="audio[<?=$i?>][id]" class="audio narrow">
						<?php
							echo mk_option($arrAudio['id'], '', '无');

							foreach($arrValidAudioDevices as $arrDev) {
								echo mk_option($arrAudio['id'], $arrDev['id'], $arrDev['name'].' ('.$arrDev['id'].')');
							}
						?>
						</select>
					</td>
				</tr>
			</table>
			<?php if ($i == 0) { ?>
			<blockquote class="inline_help">
				<p>选择要分配给您的虚拟机的声音设备. 大多数现代 GPU 都具有内置音频设备, 但您也可以选择板载音频设备 (如果有).</p>
				<? if (count($arrValidAudioDevices) > 1) { ?>
				<p>可以通过单击左侧的符号来 添加/删除 其他设备.</p>
				<? } ?>
			</blockquote>
			<? } ?>
		<? } ?>
		<script type="text/html" id="tmplSound_Card">
			<table>
				<tr>
					<td>声卡:</td>
					<td>
						<select name="audio[{{INDEX}}][id]" class="audio narrow">
						<?php
							foreach($arrValidAudioDevices as $arrDev) {
								echo mk_option('', $arrDev['id'], $arrDev['name'].' ('.$arrDev['id'].')');
							}
						?>
						</select>
					</td>
				</tr>
			</table>
		</script>

		<? foreach ($arrConfig['nic'] as $i => $arrNic) {
			$strLabel = ($i > 0) ? appendOrdinalSuffix($i + 1) : '';

			?>
			<table data-category="Network" data-multiple="true" data-minimum="1" data-index="<?=$i?>" data-prefix="<?=$strLabel?>">
				<tr class="advanced">
					<td>网卡 MAC:</td>
					<td>
						<input type="text" name="nic[<?=$i?>][mac]" class="narrow" value="<?=htmlspecialchars($arrNic['mac'])?>" title="随机 MAC, 你也可以自己提供" /> <i class="fa fa-refresh mac_generate" title="重新生成随机 MAC 地址"></i>
					</td>
				</tr>

				<tr class="advanced">
					<td>网桥:</td>
					<td>
						<select name="nic[<?=$i?>][network]">
						<?php
							foreach ($arrValidBridges as $strBridge) {
								echo mk_option($arrNic['network'], $strBridge, $strBridge);
							}
						?>
						</select>
					</td>
				</tr>
			</table>
			<?php if ($i == 0) { ?>
			<div class="advanced">
				<blockquote class="inline_help">
					<p>
						<b>网卡 MAC </b><br>
						默认情况下, 这里将分配一个符合虚拟网络接口控制器标准的随机 MAC 地址. 如果需要, 可以手动调整.
					</p>

					<p>
						<b>网桥</b><br>
						默认将使用的 Libvirt 的网桥 (virbr0), 您可以为主机的专用网络桥指定一个替代名称.
					</p>

					<p>可以通过单击左侧的符号来 添加/删除 其他设备.</p>
				</blockquote>
			</div>
			<? } ?>
		<? } ?>
		<script type="text/html" id="tmplNetwork">
			<table>
				<tr class="advanced">
					<td>网卡 MAC:</td>
					<td>
						<input type="text" name="nic[{{INDEX}}][mac]" class="narrow" value="" title="random mac, you can supply your own" /> <i class="fa fa-refresh mac_generate" title="重新生成随机 MAC 地址"></i>
					</td>
				</tr>

				<tr class="advanced">
					<td>网桥:</td>
					<td>
						<select name="nic[{{INDEX}}][network]">
						<?php
							foreach ($arrValidBridges as $strBridge) {
								echo mk_option($domain_bridge, $strBridge, $strBridge);
							}
						?>
						</select>
					</td>
				</tr>
			</table>
		</script>

		<table>
			<tr>
				<td>USB设备:</td>
				<td>
					<div class="textarea" style="width: 540px">
					<?php
						if (!empty($arrValidUSBDevices)) {
							foreach($arrValidUSBDevices as $i => $arrDev) {
							?>
							<label for="usb<?=$i?>"><input type="checkbox" name="usb[]" id="usb<?=$i?>" value="<?=htmlspecialchars($arrDev['id'])?>" <?php if (count(array_filter($arrConfig['usb'], function($arr) use ($arrDev) { return ($arr['id'] == $arrDev['id']); }))) echo 'checked="checked"'; ?>/> <?=htmlspecialchars($arrDev['name'])?> (<?=htmlspecialchars($arrDev['id'])?>)</label><br/>
							<?php
							}
						} else {
							echo "<i>无可用</i>";
						}
					?>
					</div>
				</td>
			</tr>
		</table>
		<blockquote class="inline_help">
			<p>如果您希望将任何 USB 设备分配给客户机, 则可以从此列表中选择它们.</p>
		</blockquote>

		<table>
			<tr>
				<td>其他 PCI 设备:</td>
				<td>
					<div class="textarea" style="width: 540px">
					<?
						$intAvailableOtherPCIDevices = 0;

						if (!empty($arrValidOtherDevices)) {
							foreach($arrValidOtherDevices as $i => $arrDev) {
								$extra = '';
								if (count(array_filter($arrConfig['pci'], function($arr) use ($arrDev) { return ($arr['id'] == $arrDev['id']); }))) {
									$extra .= ' checked="checked"';
								} elseif (!in_array($arrDev['driver'], ['pci-stub', 'vfio-pci'])) {
									//$extra .= ' disabled="disabled"';
									continue;
								}
								$intAvailableOtherPCIDevices++;
						?>
							<label for="pci<?=$i?>"><input type="checkbox" name="pci[]" id="pci<?=$i?>" value="<?=htmlspecialchars($arrDev['id'])?>" <?=$extra?>/> <?=htmlspecialchars($arrDev['name'])?> | <?=htmlspecialchars($arrDev['type'])?> (<?=htmlspecialchars($arrDev['id'])?>)</label><br/>
						<?
							}
						}

						if (empty($intAvailableOtherPCIDevices)) {
							echo "<i>无可用</i>";
						}
					?>
					</div>
				</td>
			</tr>
		</table>
		<blockquote class="inline_help">
			<p>如果您希望将其他 PCI 设备分配给客户机, 则可以从此列表中选择它们.</p>
		</blockquote>

		<table>
			<tr>
				<td></td>
				<td>
				<? if (!$boolNew) { ?>
					<input type="hidden" name="updatevm" value="1" />
					<input type="button" value="更新" busyvalue="更新中..." readyvalue="更新" id="btnSubmit" />
				<? } else { ?>
					<label for="domain_start"><input type="checkbox" name="domain[startnow]" id="domain_start" value="1" checked="checked"/>创建后启动虚拟机</label>
					<br>
					<input type="hidden" name="createvm" value="1" />
					<input type="button" value="创建" busyvalue="创建中..." readyvalue="创建" id="btnSubmit" />
				<? } ?>
					<input type="button" value="取消" id="btnCancel" />
				</td>
			</tr>
		</table>
		<? if ($boolNew) { ?>
		<blockquote class="inline_help">
			<p>单击创建返回到创建新虚拟机的页面.</p>
		</blockquote>
		<? } ?>
	</div>
</div>

<div class="xmlview">
	<textarea id="addcode" name="xmldesc" placeholder="复制并在此处粘贴域 XML 配置." autofocus><?=htmlspecialchars($hdrXML).htmlspecialchars($strXML)?></textarea>

	<table>
		<tr>
			<td></td>
			<td>
			<? if (!$boolRunning) { ?>
				<? if ($strXML) { ?>
					<input type="hidden" name="updatevm" value="1" />
					<input type="button" value="更新" busyvalue="更新中..." readyvalue="更新" id="btnSubmit" />
				<? } else { ?>
					<label for="xmldomain_start"><input type="checkbox" name="domain[xmlstartnow]" id="xmldomain_start" value="1" checked="checked"/>创建后启动虚拟机</label>
					<br>
					<input type="hidden" name="createvm" value="1" />
					<input type="button" value="创建" busyvalue="创建中..." readyvalue="创建" id="btnSubmit" />
				<? } ?>
				<input type="button" value="取消" id="btnCancel" />
			<? } else { ?>
				<input type="button" value="返回" id="btnCancel" />
			<? } ?>
			</td>
		</tr>
	</table>
</div>

<script src="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/lib/codemirror.js')?>"></script>
<script src="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/addon/display/placeholder.js')?>"></script>
<script src="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/addon/fold/foldcode.js')?>"></script>
<script src="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/addon/hint/show-hint.js')?>"></script>
<script src="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/addon/hint/xml-hint.js')?>"></script>
<script src="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/addon/hint/libvirt-schema.js')?>"></script>
<script src="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/mode/xml/xml.js')?>"></script>
<script type="text/javascript">
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

	function resetForm() {
		$("#vmform .domain_vcpu").change(); // restore the cpu checkbox disabled states
		<?if ($boolRunning):?>
		$("#vmform").find('input[type!="button"],select,.mac_generate').prop('disabled', true);
		$("#vmform").find('input[name^="usb"]').prop('disabled', false);
		<?endif?>
	}

	$('.advancedview').change(function () {
		if ($(this).is(':checked')) {
			setTimeout(function() {
				editor.refresh();
			}, 100);
		}
	});

	$("#vmform .domain_vcpu").change(function changeVCPUEvent() {
		var $cores = $("#vmform .domain_vcpu:checked");

		if ($cores.length == 1) {
			$cores.prop("disabled", true);
		} else {
			$("#vmform .domain_vcpu").prop("disabled", false);
		}
	});

	$("#vmform #domain_mem").change(function changeMemEvent() {
		$("#vmform #domain_maxmem").val($(this).val());
	});

	$("#vmform #domain_maxmem").change(function changeMaxMemEvent() {
		if (parseFloat($(this).val()) < parseFloat($("#vmform #domain_mem").val())) {
			$("#vmform #domain_mem").val($(this).val());
		}
	});

	$("#vmform").on("spawn_section", function spawnSectionEvent(evt, section, sectiondata) {
		if (sectiondata.category == 'Graphics_Card') {
			$(section).find(".gpu").change();
		}
	});

	$("#vmform").on("change", ".gpu", function changeGPUEvent() {
		var myvalue = $(this).val();
		var mylabel = $(this).children('option:selected').text();
		var myindex = $(this).closest('table').data('index');

		$romfile = $(this).closest('table').find('.romfile');
		if (myvalue == 'vnc' || myvalue == '') {
			slideUpRows($romfile.not(isVMAdvancedMode() ? '.basic' : '.advanced'));
			$romfile.filter('.advanced').removeClass('advanced').addClass('wasadvanced');
		} else {
			$romfile.filter('.wasadvanced').removeClass('wasadvanced').addClass('advanced');
			slideDownRows($romfile.not(isVMAdvancedMode() ? '.basic' : '.advanced'));

			$("#vmform .gpu").not(this).each(function () {
				if (myvalue == $(this).val()) {
					$(this).prop("selectedIndex", 0).change();
				}
			});
		}
	});

	$("#vmform").on("click", ".mac_generate", function generateMac() {
		var $input = $(this).prev('input');

		$.getJSON("/plugins/dynamix.vm.manager/include/VMajax.php?action=generate-mac", function (data) {
			if (data.mac) {
				$input.val(data.mac);
			}
		});
	});

	$("#vmform .formview #btnSubmit").click(function frmSubmit() {
		var $button = $(this);
		var $panel = $('.formview');
		var form = $button.closest('form');

		$panel.find('input').prop('disabled', false); // enable all inputs otherwise they wont post

		<?if (!$boolNew):?>
		// signal devices to be added or removed
		form.find('input[name="usb[]"],input[name="pci[]"]').each(function(){
			if (!$(this).prop('checked')) $(this).prop('checked',true).val($(this).val()+'#remove');
		});
		// remove unused graphic cards
		var gpus = [], i = 0;
		do {
			var gpu = form.find('select[name="gpu['+(i++)+'][id]"] option:selected').val();
			if (gpu) gpus.push(gpu);
		} while (gpu);
		form.find('select[name="gpu[0][id]"] option').each(function(){
			var gpu = $(this).val();
			if (gpu != 'vnc' && !gpus.includes(gpu)) form.append('<input type="hidden" name="pci[]" value="'+gpu+'#remove">');
		});
		// remove unused sound cards
		var sound = [], i = 0;
		do {
			var audio = form.find('select[name="audio['+(i++)+'][id]"] option:selected').val();
			if (audio) sound.push(audio);
		} while (audio);
		form.find('select[name="audio[0][id]"] option').each(function(){
			var audio = $(this).val();
			if (audio && !sound.includes(audio)) form.append('<input type="hidden" name="pci[]" value="'+audio+'#remove">');
		});
		<?endif?>
		var postdata = form.find('input,select').serialize().replace(/'/g,"%27");
		<?if (!$boolNew):?>
		// keep checkbox visually unchecked
		form.find('input[name="usb[]"],input[name="pci[]"]').each(function(){
			if ($(this).val().indexOf('#remove')>0) $(this).prop('checked',false);
		});
		<?endif?>

		$panel.find('input').prop('disabled', true);
		$button.val($button.attr('busyvalue'));

		$.post("/plugins/dynamix.vm.manager/templates/<?=basename(__FILE__)?>", postdata, function( data ) {
			if (data.success) {
				done();
			}
			if (data.error) {
				swal({title:"虚拟机创建出错",text:data.error,type:"error"});
				$panel.find('input').prop('disabled', false);
				$button.val($button.attr('readyvalue'));
				resetForm();
			}
		}, "json");
	});

	$("#vmform .xmlview #btnSubmit").click(function frmSubmit() {
		var $button = $(this);
		var $panel = $('.xmlview');

		editor.save();

		$panel.find('input').prop('disabled', false); // enable all inputs otherwise they wont post

		var postdata = $panel.closest('form').serialize().replace(/'/g,"%27");

		$panel.find('input').prop('disabled', true);
		$button.val($button.attr('busyvalue'));

		$.post("/plugins/dynamix.vm.manager/templates/<?=basename(__FILE__)?>", postdata, function( data ) {
			if (data.success) {
				done();
			}
			if (data.error) {
				swal({title:"虚拟机创建出错",text:data.error,type:"error"});
				$panel.find('input').prop('disabled', false);
				$button.val($button.attr('readyvalue'));
				resetForm();
			}
		}, "json");
	});

	var checkDownloadTimer = null;
	var checkOrInitDownload = function(checkonly) {
		clearTimeout(checkDownloadTimer);

		var $button = $("#vmform #btnDownload");
		var $form = $button.closest('form');

		var postdata = {
			download_version: $('#vmform #template_libreelec').val(),
			download_path: $('#vmform #download_path').val(),
			checkonly: ((typeof checkonly === 'undefined') ? false : !!checkonly) ? 1 : 0
		};

		$form.find('input').prop('disabled', true);
		$button.val($button.attr('busyvalue'));

		$.post("/plugins/dynamix.vm.manager/templates/<?=basename(__FILE__)?>", postdata, function( data ) {
			if (data.error) {
				$("#vmform #download_status").html($("#vmform #download_status").html() + '<br><span style="color: red">' + data.error + '</span>');
			} else if (data.status) {
				var old_list = $("#vmform #download_status").html().split('<br>');

				if (old_list.pop().split(' ... ').shift() == data.status.split(' ... ').shift()) {
					old_list.push(data.status);
					$("#vmform #download_status").html(old_list.join('<br>'));
				} else {
					$("#vmform #download_status").html($("#vmform #download_status").html() + '<br>' + data.status);
				}

				if (data.pid) {
					checkDownloadTimer = setTimeout(checkOrInitDownload, 1000);
					return;
				}

				if (data.status == 'Done') {
					$("#vmform #template_libreelec").find('option:selected').attr({
						localpath: data.localpath,
						localfolder:  data.localfolder,
						valid: '1'
					});
					$("#vmform #template_libreelec").change();
				}
			}

			$button.val($button.attr('readyvalue'));
			$form.find('input').prop('disabled', false);
		}, "json");
	};

	$("#vmform #btnDownload").click(function changeVirtIOVersion() {
		checkOrInitDownload(false);
	});

	// Fire events below once upon showing page
	$("#vmform #template_libreelec").change(function changeLibreELECVersion() {
		clearTimeout(checkDownloadTimer);

		$selected = $(this).find('option:selected');

		if ($selected.attr('valid') === '0') {
			$("#vmform .available").slideDown('fast');
			$("#vmform .installed").slideUp('fast');
			$("#vmform #download_status").html('');
			$("#vmform #download_path").val($selected.attr('localfolder'));
			if ($selected.attr('localpath') !== '') {
				// Check status of current running job (but dont initiate a new download)
				checkOrInitDownload(true);
			}
		} else {
			$("#vmform .available").slideUp('fast');
			$("#vmform .installed").slideDown('fast', function () {
				resetForm();

				// attach delete libreelec image onclick event
				$("#vmform .delete_libreelec_image").off().click(function deleteOEVersion() {
					swal({title:"你确定吗?",text:"删除这个 LibreELEC 文件:\n"+$selected.attr('localpath'),type:"warning",showCancelButton:true},function() {
						$.post("/plugins/dynamix.vm.manager/templates/<?=basename(__FILE__)?>", {delete_version: $selected.val()}, function(data) {
							if (data.error) {
								swal({title:"虚拟机镜像删除出错",text:data.error,type:"error"});
							} else if (data.status == 'ok') {
								$selected.attr({
									localpath: '',
									valid: '0'
								});
							}
							$("#vmform #template_libreelec").change();
						}, "json");
					});
				}).hover(function () {
					$("#vmform #libreelec_image").css('color', '#666');
				}, function () {
					$("#vmform #libreelec_image").css('color', '#BBB');
				});
			});
			$("#vmform #disk_0").val($selected.attr('localpath'));
			$("#vmform #libreelec_image").html($selected.attr('localpath'));
		}
	}).change(); // Fire now too!

	$("#vmform .gpu").change();

	resetForm();
});
</script>
