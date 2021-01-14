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
	require_once "$docroot/webGui/include/Custom.php";
	require_once "$docroot/plugins/dynamix.vm.manager/include/libvirt_helpers.php";

	$arrValidMachineTypes = getValidMachineTypes();
	$arrValidGPUDevices = getValidGPUDevices();
	$arrValidAudioDevices = getValidAudioDevices();
	$arrValidOtherDevices = getValidOtherDevices();
	$arrValidUSBDevices = getValidUSBDevices();
	$arrValidDiskDrivers = getValidDiskDrivers();
	$arrValidDiskBuses = getValidDiskBuses();
	$arrValidCdromBuses = getValidCdromBuses();
	$arrValidVNCModels = getValidVNCModels();
	$arrValidKeyMaps = getValidKeyMaps();
	$arrValidBridges = getNetworkBridges();
	$strCPUModel = getHostCPUModel();

	$arrConfigDefaults = [
		'template' => [
			'name' => $strSelectedTemplate,
			'icon' => $arrAllTemplates[$strSelectedTemplate]['icon'],
			'os' => $arrAllTemplates[$strSelectedTemplate]['os']
		],
		'domain' => [
			'name' => $strSelectedTemplate,
			'persistent' => 1,
			'uuid' => $lv->domain_generate_uuid(),
			'clock' => 'localtime',
			'arch' => 'x86_64',
			'machine' => 'pc',
			'mem' => 1024 * 1024,
			'maxmem' => 1024 * 1024,
			'password' => '',
			'cpumode' => 'host-passthrough',
			'vcpus' => 1,
			'vcpu' => [0],
			'hyperv' => 1,
			'ovmf' => 1,
			'usbmode' => 'usb2'
		],
		'media' => [
			'cdrom' => '',
			'cdrombus' => 'ide',
			'drivers' => is_file($domain_cfg['VIRTIOISO']) ? $domain_cfg['VIRTIOISO'] : '',
			'driversbus' => 'ide'
		],
		'disk' => [
			[
				'new' => '',
				'size' => '',
				'driver' => 'raw',
				'dev' => 'hda',
				'select' => $domain_cfg['VMSTORAGEMODE'],
				'bus' => 'virtio'
			]
		],
		'gpu' => [
			[
				'id' => 'vnc',
				'model' => 'qxl',
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
				'source' => '',
				'target' => ''
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
			if ($lv->domain_new($_POST)){
				// Fire off the vnc popup if available
				$dom = $lv->get_domain_by_name($_POST['domain']['name']);
				$vncport = $lv->domain_get_vnc_port($dom);
				$wsport = $lv->domain_get_ws_port($dom);
				if ($vncport > 0) {
					$vnc = '/plugins/dynamix.vm.manager/vnc.html?autoconnect=true&host='.$_SERVER['HTTP_HOST'].'&port='.$wsport.'&path=';
					$reply['vncurl'] = $vnc;
				}
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
			if ($error = create_vdisk($_POST) === false) {
				$arrExistingConfig = custom::createArray('domain',$strXML);
				$arrUpdatedConfig = custom::createArray('domain',$lv->config_to_xml($_POST));
				array_update_recursive($arrExistingConfig, $arrUpdatedConfig);
				$arrConfig = array_replace_recursive($arrExistingConfig, $arrUpdatedConfig);
				$xml = custom::createXML('domain',$arrConfig)->saveXML();
			} else {
				echo json_encode(['error' => $error]);
				exit;
			}
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
	// Add any custom metadata field defaults (e.g. os)
	if (!$arrConfig['template']['os']) {
		$arrConfig['template']['os'] = ($arrConfig['domain']['clock']=='localtime' ? 'windows' : 'linux');
	}
?>

<link rel="stylesheet" href="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/lib/codemirror.css')?>">
<link rel="stylesheet" href="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/addon/hint/show-hint.css')?>">
<style type="text/css">
	.CodeMirror { border: 1px solid #eee; cursor: text; margin-top: 15px; margin-bottom: 10px; }
	.CodeMirror pre.CodeMirror-placeholder { color: #999; }
</style>

<div class="formview">
<input type="hidden" name="template[os]" id="template_os" value="<?=htmlspecialchars($arrConfig['template']['os'])?>">
<input type="hidden" name="domain[persistent]" value="<?=htmlspecialchars($arrConfig['domain']['persistent'])?>">
<input type="hidden" name="domain[uuid]" value="<?=htmlspecialchars($arrConfig['domain']['uuid'])?>">
<input type="hidden" name="domain[clock]" id="domain_clock" value="<?=htmlspecialchars($arrConfig['domain']['clock'])?>">
<input type="hidden" name="domain[arch]" value="<?=htmlspecialchars($arrConfig['domain']['arch'])?>">
<input type="hidden" name="domain[oldname]" id="domain_oldname" value="<?=htmlspecialchars($arrConfig['domain']['name'])?>">

	<table>
		<tr>
			<td>名称:</td>
			<td><input type="text" name="domain[name]" id="domain_name" class="textTemplate" title="虚拟机名称" placeholder="例如 My Workstation" value="<?=htmlspecialchars($arrConfig['domain']['name'])?>" required /></td>
		</tr>
	</table>
	<blockquote class="inline_help">
		<p>给虚拟机起个名字 (例如 Work, Gaming, Media Player, Firewall Bitcoin Miner)</p>
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
					echo mk_option($arrConfig['domain']['mem'], 128 * 1024, '128 MB');
					echo mk_option($arrConfig['domain']['mem'], 256 * 1024, '256 MB');
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
					echo mk_option($arrConfig['domain']['maxmem'], 128 * 1024, '128 MB');
					echo mk_option($arrConfig['domain']['maxmem'], 256 * 1024, '256 MB');
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
				<select name="domain[ovmf]" id="domain_ovmf" class="narrow" title="选择 BIOS.  SeaBIOS 对大多数人都有用.  OVMF 需要一个 UEFI 兼容的操作系统 (例如. Windows 8/2012, 新的 Linux 发行版) 如果使用图形设备也需要支持 UEFI" <? if (!empty($arrConfig['domain']['state'])) echo 'disabled="disabled"'; ?>>
				<?php
					echo mk_option($arrConfig['domain']['ovmf'], '0', 'SeaBIOS');

					if (file_exists('/usr/share/qemu/ovmf-x64/OVMF_CODE-pure-efi.fd')) {
						echo mk_option($arrConfig['domain']['ovmf'], '1', 'OVMF');
					} else {
						echo mk_option('', '0', 'OVMF (Not Available)', 'disabled="disabled"');
					}
				?>
				</select>
				<?php if (!empty($arrConfig['domain']['state'])) { ?>
					<input type="hidden" name="domain[ovmf]" value="<?=htmlspecialchars($arrConfig['domain']['ovmf'])?>">
				<?php } ?>
			</td>
		</tr>
	</table>
	<div class="advanced">
		<blockquote class="inline_help">
			<p>
				<b>SeaBIOS</b><br>
				是用于创建虚拟机的默认虚拟 BIOS, 并且与所有客户机操作系统 (Windows, Linux 等) 兼容.
			</p>
			<p>
				<b>OVMF</b><br>
				(Open Virtual Machine Firmware) 增加了对使用 UEFI 引导虚拟机的支持, 但虚拟机客户机操作系统也必须支持 UEFI. 将图形设备分配给基于 OVMF 的虚拟机需要图形设备也支持 UEFI.
			</p>
			<p>
				创建虚拟机后, 无法调整此设置.
			</p>
		</blockquote>
	</div>

	<table class="domain_os windows">
		<tr class="advanced">
			<td>Hyper-V:</td>
			<td>
				<select name="domain[hyperv]" id="hyperv" class="narrow" title="Hyperv 适用于 Windows">
				<?php mk_dropdown_options(['No', 'Yes'], $arrConfig['domain']['hyperv']); ?>
				</select>
			</td>
		</tr>
	</table>
	<div class="domain_os windows">
		<div class="advanced">
			<blockquote class="inline_help">
				<p>将客户机暴露给 Microsoft 操作系统的 Hyper-V 扩展.</p>
			</blockquote>
		</div>
	</div>

	<table>
		<tr class="advanced">
			<td>USB 控制器:</td>
			<td>
				<select name="domain[usbmode]" id="usbmode" class="narrow" title="选择要模拟的 USB 控制器. 有些操作系统不支持 USB3 (例如 Windows7/XP)">
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
				<b>USB Controller</b><br>
				选择要模拟的 USB 控制器. 有些操作系统不支持 USB3 (例如 Windows7/XP).  Qemu XHCI is the same code base as Nec XHCI but without several hacks applied over the years.  Recommended to try qemu XHCI before resorting to nec XHCI.
			</p>
		</blockquote>
	</div>

	<table>
		<tr>
			<td>系统安装 ISO:</td>
			<td>
				<input type="text" data-pickcloseonfile="true" data-pickfilter="iso" data-pickmatch="^[^.].*" data-pickroot="<?=htmlspecialchars($domain_cfg['MEDIADIR'])?>" name="media[cdrom]" class="cdrom" value="<?=htmlspecialchars($arrConfig['media']['cdrom'])?>" placeholder="单击并选择要安装操作系统的 CDRom 镜像">
			</td>
		</tr>
		<tr class="advanced">
			<td>系统安装 CDRom 总线:</td>
			<td>
				<select name="media[cdrombus]" class="cdrom_bus narrow">
				<?php mk_dropdown_options($arrValidCdromBuses, $arrConfig['media']['cdrombus']); ?>
				</select>
			</td>
		</tr>
	</table>
	<blockquote class="inline_help">
		<p>选择包含您的操作系统的安装介质的虚拟 CD-ROM (ISO). 单击此字段将显示在 '设置' 页面上指定的目录中找到的 ISO 列表./p>
		<p class="advanced">
			<b>CDRom 总线</b><br>
			Specify what interface this virtual cdrom uses to connect inside the VM.
		</p>
	</blockquote>

	<table class="domain_os windows">
		<tr class="advanced">
			<td>VirtIO 驱动程序 ISO:</td>
			<td>
				<input type="text" data-pickcloseonfile="true" data-pickfilter="iso" data-pickmatch="^[^.].*" data-pickroot="<?=htmlspecialchars($domain_cfg['MEDIADIR'])?>" name="media[drivers]" class="cdrom" value="<?=htmlspecialchars($arrConfig['media']['drivers'])?>" placeholder="下载并选择 Virtio 驱动程序镜像">
			</td>
		</tr>
		<tr class="advanced">
			<td>VirtIO 驱动程序 CDRom 总线:</td>
			<td>
				<select name="media[driversbus]" class="cdrom_bus narrow">
				<?php mk_dropdown_options($arrValidCdromBuses, $arrConfig['media']['driversbus']); ?>
				</select>
			</td>
		</tr>
	</table>
	<div class="domain_os windows">
		<div class="advanced">
			<blockquote class="inline_help">
				<p>指定包含 Fedora 项目提供的 VirtIO Windows 驱动程序的虚拟 CD-ROM (ISO). 从此处下载最新的 ISO: <a href="https://docs.fedoraproject.org/en-US/quick-docs/creating-windows-virtual-machines-using-virtio-drivers/index.html#virtio-win-direct-downloads" target="_blank"> https://docs.fedoraproject.org/zh-CN/quick-docs/creating-windows-virtual-machines-using-virtio-drivers/index.html#virtio-win-direct-downloads </a></p>
				<p>安装 Windows 时, 您将到达一个步骤, 在该步骤中找不到磁盘设备. 可以在屏幕上浏览驱动程序. 打开要安装的 Windows 版本的文件夹, 然后选择其中的 AMD64 子文件夹 (即使在英特尔系统上, 也要选择 AMD64). 打开要安装的 Windows 版本的文件夹, 然后选择其中的 AMD64 子文件夹 (即使在英特尔系统上, 也要选择 AMD64). 将找到驱动程序. 全部选中, 单击 '下一步', 将显示您分配的虚拟磁盘..</p>
				<p>
					<b>CDRom 总线</b><br>
					指定虚拟 CDRom 用于在虚拟机内部连接的接口.
				</p>
			</blockquote>
		</div>
	</div>

	<? foreach ($arrConfig['disk'] as $i => $arrDisk) {
		$strLabel = ($i > 0) ? appendOrdinalSuffix($i + 1) : '主要';

		?>
		<table data-category="vDisk" data-multiple="true" data-minimum="1" data-maximum="24" data-index="<?=$i?>" data-prefix="<?=$strLabel?>">
			<tr>
				<td>虚拟磁盘位置:</td>
				<td>
					<select name="disk[<?=$i?>][select]" class="disk_select narrow">
					<?
						if ($i == 0) {
							echo '<option value="">无</option>';
						}

						$default_option = $arrDisk['select'];

						if (!empty($domain_cfg['DOMAINDIR']) && file_exists($domain_cfg['DOMAINDIR'])) {

							$boolShowAllDisks = (strpos($domain_cfg['DOMAINDIR'], '/mnt/user/') === 0);

							if (!empty($arrDisk['new'])) {
								if (strpos($domain_cfg['DOMAINDIR'], dirname(dirname($arrDisk['new']))) === false || basename($arrDisk['new']) != 'vdisk'.($i+1).'.img') {
									$default_option = 'manual';
								}
								if (file_exists(dirname(dirname($arrDisk['new'])).'/'.$arrConfig['domain']['name'].'/vdisk'.($i+1).'.img')) {
									// hide all the disks because the auto disk already has been created
									$boolShowAllDisks = false;
								}
							}

							echo mk_option($default_option, 'auto', '自动');

							if ($boolShowAllDisks) {
								$strShareUserLocalInclude = '';
								$strShareUserLocalExclude = '';
								$strShareUserLocalUseCache = 'no';

								// Get the share name and its configuration
								$arrDomainDirParts = explode('/', $domain_cfg['DOMAINDIR']);
								$strShareName = $arrDomainDirParts[3];
								if (!empty($strShareName) && is_file('/boot/config/shares/'.$strShareName.'.cfg')) {
									$arrShareCfg = parse_ini_file('/boot/config/shares/'.$strShareName.'.cfg');
									if (!empty($arrShareCfg['shareInclude'])) {
										$strShareUserLocalInclude = $arrShareCfg['shareInclude'];
									}
									if (!empty($arrShareCfg['shareExclude'])) {
										$strShareUserLocalExclude = $arrShareCfg['shareExclude'];
									}
									if (!empty($arrShareCfg['shareUseCache'])) {
										$strShareUserLocalUseCache = $arrShareCfg['shareUseCache'];
									}
								}

								// Determine if cache drive is available:
								if (!empty($disks['cache']) && (!empty($disks['cache']['device']))) {
									if ($strShareUserLocalUseCache != 'no' && $var['shareCacheEnabled'] == 'yes') {
										$strLabel = my_disk('cache').' - 剩余空间 '.my_scale($disks['cache']['fsFree']*1024, $strUnit).' '.$strUnit.'';
										echo mk_option($default_option, 'cache', $strLabel);
									}
								}

								// Determine which disks from the array are available for this share:
								foreach ($disks as $name => $disk) {
									if ((strpos($name, 'disk') === 0) && (!empty($disk['device']))) {
										if ((!empty($strShareUserLocalInclude) && (strpos($strShareUserLocalInclude.',', $name.',') === false)) ||
											(!empty($strShareUserLocalExclude) && (strpos($strShareUserLocalExclude.',', $name.',') !== false)) ||
											(!empty($var['shareUserInclude']) && (strpos($var['shareUserInclude'].',', $name.',') === false)) ||
											(!empty($var['shareUserExclude']) && (strpos($var['shareUserExclude'].',', $name.',') !== false))) {
											// skip this disk based on local and global share settings
											continue;
										}
										$strLabel = my_disk($name).' - 剩余空间 '.my_scale($disk['fsFree']*1024, $strUnit).' '.$strUnit.'';
										echo mk_option($default_option, $name, $strLabel);
									}
								}
							}

						}

						echo mk_option($default_option, 'manual', '手动');
					?>
					</select><input type="text" data-pickcloseonfile="true" data-pickfolders="true" data-pickfilter="img,qcow,qcow2" data-pickmatch="^[^.].*" data-pickroot="/mnt/" name="disk[<?=$i?>][new]" class="disk" id="disk_<?=$i?>" value="<?=htmlspecialchars($arrDisk['new'])?>" placeholder="将根据名称创建单独的子文件夹和磁盘镜像"><div class="disk_preview"></div>
				</td>
			</tr>

			<tr class="disk_file_options">
				<td>虚拟磁盘大小:</td>
				<td>
					<input type="text" name="disk[<?=$i?>][size]" value="<?=htmlspecialchars($arrDisk['size'])?>" class="narrow" placeholder="例如 10M, 1G, 10G...">
				</td>
			</tr>

			<tr class="advanced disk_file_options">
				<td>虚拟磁盘类型:</td>
				<td>
					<select name="disk[<?=$i?>][driver]" class="narrow" title="存储镜像类型">
					<?php mk_dropdown_options($arrValidDiskDrivers, $arrDisk['driver']); ?>
					</select>
				</td>
			</tr>

			<tr class="advanced disk_bus_options">
				<td>虚拟磁盘总线:</td>
				<td>
					<select name="disk[<?=$i?>][bus]" class="disk_bus narrow">
					<?php mk_dropdown_options($arrValidDiskBuses, $arrDisk['bus']); ?>
					</select>
				</td>
			</tr>
		</table>
		<?php if ($i == 0) { ?>
		<blockquote class="inline_help">
			<p>
				<b>虚拟磁盘位置</b><br>
				指定要在其存储虚拟机的用户共享的路径或指定现有的虚拟磁盘. 主虚拟磁盘将存储虚拟机的操作系统.
			</p>

			<p>
				<b>虚拟磁盘大小</b><br>
				指定后跟字母的数字. M 代表兆字节, G 代表千兆字节.
			</p>

			<p class="advanced">
				<b>虚拟磁盘类型</b><br>
				选择 RAW 以获得最佳性能. QCOW2 的实现仍在开发中.
			</p>

			<p class="advanced">
				<b>虚拟磁盘总线</b><br>
				选择 Virtio 以获得最佳性能.
			</p>

			<p>可以通过单击左侧的符号来 添加/删除 其他设备.</p>
		</blockquote>
		<? } ?>
	<? } ?>
	<script type="text/html" id="tmplvDisk">
		<table>
			<tr>
				<td>虚拟磁盘位置:</td>
				<td>
					<select name="disk[{{INDEX}}][select]" class="disk_select narrow">
					<?
						if (!empty($domain_cfg['DOMAINDIR']) && file_exists($domain_cfg['DOMAINDIR'])) {

							$default_option = $domain_cfg['VMSTORAGEMODE'];

							echo mk_option($default_option, 'auto', '自动');

							if (strpos($domain_cfg['DOMAINDIR'], '/mnt/user/') === 0) {
								$strShareUserLocalInclude = '';
								$strShareUserLocalExclude = '';
								$strShareUserLocalUseCache = 'no';

								// Get the share name and its configuration
								$arrDomainDirParts = explode('/', $domain_cfg['DOMAINDIR']);
								$strShareName = $arrDomainDirParts[3];
								if (!empty($strShareName) && is_file('/boot/config/shares/'.$strShareName.'.cfg')) {
									$arrShareCfg = parse_ini_file('/boot/config/shares/'.$strShareName.'.cfg');
									if (!empty($arrShareCfg['shareInclude'])) {
										$strShareUserLocalInclude = $arrShareCfg['shareInclude'];
									}
									if (!empty($arrShareCfg['shareExclude'])) {
										$strShareUserLocalExclude = $arrShareCfg['shareExclude'];
									}
									if (!empty($arrShareCfg['shareUseCache'])) {
										$strShareUserLocalUseCache = $arrShareCfg['shareUseCache'];
									}
								}

								// Determine if cache drive is available:
								if (!empty($disks['cache']) && (!empty($disks['cache']['device']))) {
									if ($strShareUserLocalUseCache != 'no' && $var['shareCacheEnabled'] == 'yes') {
										$strLabel = my_disk('cache').' - 剩余空间 '.my_scale($disks['cache']['fsFree']*1024, $strUnit).' '.$strUnit.'';
										echo mk_option($default_option, 'cache', $strLabel);
									}
								}

								// Determine which disks from the array are available for this share:
								foreach ($disks as $name => $disk) {
									if ((strpos($name, 'disk') === 0) && (!empty($disk['device']))) {
										if ((!empty($strShareUserLocalInclude) && (strpos($strShareUserLocalInclude.',', $name.',') === false)) ||
											(!empty($strShareUserLocalExclude) && (strpos($strShareUserLocalExclude.',', $name.',') !== false)) ||
											(!empty($var['shareUserInclude']) && (strpos($var['shareUserInclude'].',', $name.',') === false)) ||
											(!empty($var['shareUserExclude']) && (strpos($var['shareUserExclude'].',', $name.',') !== false))) {
											// skip this disk based on local and global share settings
											continue;
										}
										$strLabel = my_disk($name).' - 剩余空间 '.my_scale($disk['fsFree']*1024, $strUnit).' '.$strUnit.'';
										echo mk_option($default_option, $name, $strLabel);
									}
								}
							}

						}

						echo mk_option('', 'manual', '手动');
					?>
					</select><input type="text" data-pickcloseonfile="true" data-pickfolders="true" data-pickfilter="img,qcow,qcow2" data-pickmatch="^[^.].*" data-pickroot="/mnt/" name="disk[{{INDEX}}][new]" class="disk" id="disk_{{INDEX}}" value="" placeholder="将根据名称创建单独的子文件夹和磁盘镜像"><div class="disk_preview"></div>
				</td>
			</tr>

			<tr class="disk_file_options">
				<td>虚拟磁盘大小:</td>
				<td>
					<input type="text" name="disk[{{INDEX}}][size]" value="" class="narrow" placeholder="例如 10M, 1G, 10G...">
				</td>
			</tr>

			<tr class="advanced disk_file_options">
				<td>虚拟磁盘类型:</td>
				<td>
					<select name="disk[{{INDEX}}][driver]" class="narrow" title="存储镜像类型">
					<?php mk_dropdown_options($arrValidDiskDrivers, ''); ?>
					</select>
				</td>
			</tr>

			<tr class="advanced disk_bus_options">
				<td>虚拟磁盘总线:</td>
				<td>
					<select name="disk[{{INDEX}}][bus]" class="disk_bus narrow">
					<?php mk_dropdown_options($arrValidDiskBuses, ''); ?>
					</select>
				</td>
			</tr>
		</table>
	</script>

	<? foreach ($arrConfig['shares'] as $i => $arrShare) {
		$strLabel = ($i > 0) ? appendOrdinalSuffix($i + 1) : '';

		?>
		<table class="domain_os other" data-category="Share" data-multiple="true" data-minimum="1" data-index="<?=$i?>" data-prefix="<?=$strLabel?>">
			<tr class="advanced">
				<td>Unraid 共享:</td>
				<td>
					<input type="text" data-pickfolders="true" data-pickfilter="NO_FILES_FILTER" data-pickroot="/mnt/" value="<?=htmlspecialchars($arrShare['source'])?>" name="shares[<?=$i?>][source]" placeholder="例如 /mnt/user/..." title="Unraid 共享路径" />
				</td>
			</tr>

			<tr class="advanced">
				<td>Unraid 挂载标签:</td>
				<td>
					<input type="text" value="<?=htmlspecialchars($arrShare['target'])?>" name="shares[<?=$i?>][target]" placeholder="e.g. shares (name of mount tag inside vm)" title="mount tag inside vm" />
				</td>
			</tr>
		</table>
		<?php if ($i == 0) { ?>
		<div class="domain_os other">
			<div class="advanced">
				<blockquote class="inline_help">
					<p>
						<b>Unraid 共享</b><br>
						用于创建到基于 Linux-Based Guest 的 VirtFS 映射. 在此处指定主机上的路径.
					</p>

					<p>
						<b>Unraid 挂载标签</b><br>
						指定将用于在虚拟机内装入 VirtFS 共享的标签. 有关如何在  Linux-Based Guest 上执行此操作的信息, 请参见本页: <a href="http://wiki.qemu.org/Documentation/9psetup" target="_blank">http://wiki.qemu.org/Documentation/9psetup</a>
					</p>

					<p>单击左侧的符号可以 添加/删除 其他设备.</p>
				</blockquote>
			</div>
		</div>
		<? } ?>
	<? } ?>
	<script type="text/html" id="tmplShare">
		<table class="domain_os other">
			<tr class="advanced">
				<td>Unraid 共享:</td>
				<td>
					<input type="text" data-pickfolders="true" data-pickfilter="NO_FILES_FILTER" data-pickroot="/mnt/" value="" name="shares[{{INDEX}}][source]" placeholder="例如 /mnt/user/..." title="path of Unraid share" />
				</td>
			</tr>

			<tr class="advanced">
				<td>Unraid 挂载标签:</td>
				<td>
					<input type="text" value="" name="shares[{{INDEX}}][target]" placeholder="例如 shares (name of mount tag inside vm)" title="mount tag inside vm" />
				</td>
			</tr>
		</table>
	</script>

	<? foreach ($arrConfig['gpu'] as $i => $arrGPU) {
		$strLabel = ($i > 0) ? appendOrdinalSuffix($i + 1) : '';

		?>
		<table data-category="Graphics_Card" data-multiple="true" data-minimum="1" data-maximum="<?=count($arrValidGPUDevices)+1?>" data-index="<?=$i?>" data-prefix="<?=$strLabel?>">
			<tr>
				<td>显卡:</td>
				<td>
					<select name="gpu[<?=$i?>][id]" class="gpu narrow">
					<?php
						if ($i == 0) {
							// Only the first video card can be VNC
							echo mk_option($arrGPU['id'], 'vnc', 'VNC');
						} else {
							echo mk_option($arrGPU['id'], '', 'None');
						}

						foreach($arrValidGPUDevices as $arrDev) {
							echo mk_option($arrGPU['id'], $arrDev['id'], $arrDev['name'].' ('.$arrDev['id'].')');
						}
					?>
					</select>
				</td>
			</tr>

			<? if ($i == 0) { ?>
			<tr class="<? if ($arrGPU['id'] != 'vnc') echo 'was'; ?>advanced vncmodel">
				<td>VNC 视频驱动:</td>
				<td>
					<select id="vncmodel" name="gpu[<?=$i?>][model]" class="narrow" title="video for VNC">
					<?php mk_dropdown_options($arrValidVNCModels, $arrGPU['model']); ?>
					</select>
				</td>
			</tr>
			<tr class="vncpassword">
				<td>VNC 密码:</td>
				<td><input type="password" name="domain[password]" title="password for VNC" class="narrow" placeholder="VNC 密码 (可选)" /></td>
			</tr>
			<tr class="<? if ($arrGPU['id'] != 'vnc') echo 'was'; ?>advanced vnckeymap">
				<td>VNC 键盘:</td>
				<td>
					<select name="gpu[<?=$i?>][keymap]" title="keyboard for VNC">
					<?php mk_dropdown_options($arrValidKeyMaps, $arrGPU['keymap']); ?>
					</select>
				</td>
			</tr>
			<? } ?>
			<tr class="<? if ($arrGPU['id'] == 'vnc') echo 'was'; ?>advanced romfile">
				<td>显卡 BIOS ROM:</td>
				<td>
					<input type="text" data-pickcloseonfile="true" data-pickfilter="rom,bin" data-pickmatch="^[^.].*" data-pickroot="/" value="<?=htmlspecialchars($arrGPU['rom'])?>" name="gpu[<?=$i?>][rom]" placeholder="BIOS ROM 文件路径 (可选)" title="BIOS ROM 文件路径 (可选)" />
				</td>
			</tr>
		</table>
		<?php if ($i == 0) { ?>
		<blockquote class="inline_help">
			<p>
				<b>显卡</b><br>
				如果要将图形卡分配给虚拟机, 请从该列表中选择它, 否则将其设置为 VNC.
			</p>

			<p class="<? if ($arrGPU['id'] != 'vnc') echo 'was'; ?>advanced vncmodel">
				<b>VNC 视频驱动</b><br>
				如果要为 VNC 连接分配不同的视频驱动程序, 请在此处指定一个.
			</p>

			<p class="vncpassword">
				<b>VNC 密码</b><br>
				如果要通过密码连接 VNC 到虚拟机, 请在此处指定一个密码.
			</p>

			<p class="<? if ($arrGPU['id'] != 'vnc') echo 'was'; ?>advanced vnckeymap">
				<b>VNC 键盘</b><br>
				如果要为 VNC 连接分配不同的键盘布局, 请在此处指定一个.
			</p>

			<p class="<? if ($arrGPU['id'] == 'vnc') echo 'was'; ?>advanced romfile">
				<b>显卡 BIOS ROM</b><br>
				如果您想使用图形卡的自定义 BIOS ROM 请在此处指定一个.
			</p>

			<p>单击左侧的符号可以 添加/删除 其他设备.</p>
		</blockquote>
		<? } ?>
	<? } ?>
	<script type="text/html" id="tmplGraphics_Card">
		<table>
			<tr>
				<td>显卡:</td>
				<td>
					<select name="gpu[{{INDEX}}][id]" class="gpu narrow">
					<?php
						echo mk_option('', '', 'None');

						foreach($arrValidGPUDevices as $arrDev) {
							echo mk_option('', $arrDev['id'], $arrDev['name'].' ('.$arrDev['id'].')');
						}
					?>
					</select>
				</td>
			</tr>
			<tr class="advanced romfile">
				<td>显卡 BIOS ROM:</td>
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
			<p>可以通过单击左侧的符号来 添加/删除 其他设备.</p>
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
					<input type="text" name="nic[<?=$i?>][mac]" class="narrow" value="<?=htmlspecialchars($arrNic['mac'])?>" title="随机 MAC 地址, 你也可以自己提供" /> <i class="fa fa-refresh mac_generate" title="重新生成随机 MAC 地址"></i>
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
					<b>网卡 MAC</b><br>
					默认情况下, 这里将分配一个符合虚拟网络接口控制器标准的随机 MAC 地址. 如果需要, 可以手动调整.
				</p>

				<p>
					<b>网桥</b><br>
					默认将使用的 Libvirt 的网桥 (virbr0), 您可以为主机的专用网络桥指定一个替代名称..
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
					<input type="text" name="nic[{{INDEX}}][mac]" class="narrow" value="" title="随机 MAC 地址, 你也可以自己提供" /> <i class="fa fa-refresh mac_generate" title="重新生成随机 MAC 地址"></i>
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
			<td>USB 设备:</td>
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
				<label for="domain_start"><input type="checkbox" name="domain[startnow]" id="domain_start" value="1" checked="checked"/> 创建后启动虚拟机</label>
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
		<p>单击创建以生成虚拟磁盘, 并返回到创建新虚拟机的页面.</p>
	</blockquote>
	<? } ?>
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
					<input type="button" value="Update" busyvalue="Updating..." readyvalue="Update" id="btnSubmit" />
				<? } else { ?>
					<label for="xmldomain_start"><input type="checkbox" name="domain[xmlstartnow]" id="xmldomain_start" value="1" checked="checked"/> 创建后启动虚拟机</label>
					<br>
					<input type="hidden" name="createvm" value="1" />
					<input type="button" value="Create" busyvalue="Creating..." readyvalue="Create" id="btnSubmit" />
				<? } ?>
				<input type="button" value="Cancel" id="btnCancel" />
			<? } else { ?>
				<input type="button" value="Back" id="btnCancel" />
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
		<? if (!empty($arrConfig['domain']['state'])) echo '$(\'#vmform #domain_ovmf\').prop(\'disabled\', true); // restore bios disabled state' . "\n"; ?>
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

	var regenerateDiskPreview = function (disk_index) {
		var domaindir = '<?=$domain_cfg['DOMAINDIR']?>' + $('#domain_oldname').val();
		var tl_args = arguments.length;

		$("#vmform .disk").closest('table').each(function (index) {
			var $table = $(this);

			if (tl_args && disk_index != $table.data('index')) {
				return;
			}

			var disk_select = $table.find(".disk_select option:selected").val();
			var $disk_file_sections = $table.find('.disk_file_options');
			var $disk_bus_sections = $table.find('.disk_bus_options');
			var $disk_input = $table.find('.disk');
			var $disk_preview = $table.find('.disk_preview');

			if (disk_select == 'manual') {

				// Manual disk
				$disk_preview.fadeOut('fast', function() {
					$disk_input.fadeIn('fast');
				});

				$disk_bus_sections.filter('.wasadvanced').removeClass('wasadvanced').addClass('advanced');
				slideDownRows($disk_bus_sections.not(isVMAdvancedMode() ? '.basic' : '.advanced'));

				$.getJSON("/plugins/dynamix.vm.manager/include/VMajax.php?action=file-info&file=" + encodeURIComponent($disk_input.val()), function( info ) {
					if (info.isfile || info.isblock) {
						slideUpRows($disk_file_sections);
						$disk_file_sections.filter('.advanced').removeClass('advanced').addClass('wasadvanced');

						$disk_input.attr('name', $disk_input.attr('name').replace('new', 'image'));
					} else {
						$disk_file_sections.filter('.wasadvanced').removeClass('wasadvanced').addClass('advanced');
						slideDownRows($disk_file_sections.not(isVMAdvancedMode() ? '.basic' : '.advanced'));

						$disk_input.attr('name', $disk_input.attr('name').replace('image', 'new'));
					}
				});

			} else if (disk_select !== '') {

				// Auto disk
				var auto_disk_path = domaindir + '/vdisk' + (index+1) + '.img';
				$disk_preview.html(auto_disk_path);
				$disk_input.fadeOut('fast', function() {
					$disk_preview.fadeIn('fast');
				});

				$disk_bus_sections.filter('.wasadvanced').removeClass('wasadvanced').addClass('advanced');
				slideDownRows($disk_bus_sections.not(isVMAdvancedMode() ? '.basic' : '.advanced'));

				$.getJSON("/plugins/dynamix.vm.manager/include/VMajax.php?action=file-info&file=" + encodeURIComponent(auto_disk_path), function( info ) {
					if (info.isfile || info.isblock) {
						slideUpRows($disk_file_sections);
						$disk_file_sections.filter('.advanced').removeClass('advanced').addClass('wasadvanced');

						$disk_input.attr('name', $disk_input.attr('name').replace('new', 'image'));
					} else {
						$disk_file_sections.filter('.wasadvanced').removeClass('wasadvanced').addClass('advanced');
						slideDownRows($disk_file_sections.not(isVMAdvancedMode() ? '.basic' : '.advanced'));

						$disk_input.attr('name', $disk_input.attr('name').replace('image', 'new'));
					}
				});

			} else {

				// No disk
				var $hide_el = $table.find('.disk_bus_options,.disk_file_options,.disk_preview,.disk');
				$disk_preview.html('');
				slideUpRows($hide_el);
				$hide_el.filter('.advanced').removeClass('advanced').addClass('wasadvanced');

			}
		});
	};

	<?if ($boolNew):?>
	$("#vmform #domain_name").on("input change", function changeNameEvent() {
		$('#vmform #domain_oldname').val($(this).val());
		regenerateDiskPreview();
	});
	<?endif?>

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

	$("#vmform #domain_machine").change(function changeMachineEvent() {
		// Cdrom Bus: select IDE for i440 and SATA for q35
		if ($(this).val().indexOf('i440fx') != -1) {
			$('#vmform .cdrom_bus').val('ide');
		} else {
			$('#vmform .cdrom_bus').val('sata');
		}
	});

	$("#vmform #domain_ovmf").change(function changeBIOSEvent() {
		// using OVMF - disable vmvga vnc option
		if ($(this).val() == '1' && $("#vmform #vncmodel").val() == 'vmvga') {
			$("#vmform #vncmodel").val('qxl');
		}
		$("#vmform #vncmodel option[value='vmvga']").prop('disabled', ($(this).val() == '1'));
	}).change(); // fire event now

	$("#vmform").on("spawn_section", function spawnSectionEvent(evt, section, sectiondata) {
		if (sectiondata.category == 'vDisk') {
			regenerateDiskPreview(sectiondata.index);
		}
		if (sectiondata.category == 'Graphics_Card') {
			$(section).find(".gpu").change();
		}
	});

	$("#vmform").on("destroy_section", function destroySectionEvent(evt, section, sectiondata) {
		if (sectiondata.category == 'vDisk') {
			regenerateDiskPreview();
		}
	});

	$("#vmform").on("input change", ".cdrom", function changeCdromEvent() {
		if ($(this).val() == '') {
			slideUpRows($(this).closest('table').find('.cdrom_bus').closest('tr'));
		} else {
			slideDownRows($(this).closest('table').find('.cdrom_bus').closest('tr'));
		}
	});

	$("#vmform").on("change", ".disk_select", function changeDiskSelectEvent() {
		regenerateDiskPreview($(this).closest('table').data('index'));
	});

	$("#vmform").on("input change", ".disk", function changeDiskEvent() {
		var $input = $(this);
		var config = $input.data();

		if (config.hasOwnProperty('pickfilter')) {
			regenerateDiskPreview($input.closest('table').data('index'));
		}
	});

	$("#vmform").on("change", ".gpu", function changeGPUEvent() {
		var myvalue = $(this).val();
		var mylabel = $(this).children('option:selected').text();
		var myindex = $(this).closest('table').data('index');

		if (myindex == 0) {
			$vnc_sections = $('.vncmodel,.vncpassword,.vnckeymap');
			if (myvalue == 'vnc') {
				$vnc_sections.filter('.wasadvanced').removeClass('wasadvanced').addClass('advanced');
				slideDownRows($vnc_sections.not(isVMAdvancedMode() ? '.basic' : '.advanced'));
			} else {
				slideUpRows($vnc_sections);
				$vnc_sections.filter('.advanced').removeClass('advanced').addClass('wasadvanced');
			}
		}

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

		$.getJSON("/plugins/dynamix.vm.manager/include/VMajax.php?action=generate-mac", function( data ) {
			if (data.mac) {
				$input.val(data.mac);
			}
		});
	});

	$("#vmform .formview #btnSubmit").click(function frmSubmit() {
		var $button = $(this);
		var $panel = $('.formview');
		var form = $button.closest('form');

		$("#vmform .disk_select option:selected").not("[value='manual']").closest('table').each(function () {
			var v = $(this).find('.disk_preview').html();
			$(this).find('.disk').val(v);
		});

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
				if (data.vncurl) {
					window.open(data.vncurl, '_blank', 'scrollbars=yes,resizable=yes');
				}
				done();
			}
			if (data.error) {
				swal({title:"VM creation error",text:data.error,type:"error"});
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
				swal({title:"VM creation error",text:data.error,type:"error"});
				$panel.find('input').prop('disabled', false);
				$button.val($button.attr('readyvalue'));
				resetForm();
			}
		}, "json");
	});

	// Fire events below once upon showing page
	var os = $("#vmform #template_os").val() || 'linux';
	var os_casted = (os.indexOf('windows') == -1 ? 'other' : 'windows');

	$('#vmform .domain_os').not($('.' + os_casted)).hide();
	$('#vmform .domain_os.' + os_casted).not(isVMAdvancedMode() ? '.basic' : '.advanced').show();

	<?if ($boolNew):?>
	if (os_casted == 'windows') {
		$('#vmform #domain_clock').val('localtime');
		$("#vmform #domain_machine option").each(function(){
			if ($(this).val().indexOf('i440fx') != -1) {
				$('#vmform #domain_machine').val($(this).val()).change();
				return false;
			}
		});
	} else {
		$('#vmform #domain_clock').val('utc');
		$("#vmform #domain_machine option").each(function(){
			if ($(this).val().indexOf('q35') != -1) {
				$('#vmform #domain_machine').val($(this).val()).change();
				return false;
			}
		});
	}
	<?endif?>

	// disable usb3 option for windows7 / xp / server 2003 / server 2008
	var noUSB3 = (os == 'windows7' || os == 'windows2008' || os == 'windowsxp' || os == 'windows2003');
	if (noUSB3 && ($("#vmform #usbmode").val().indexOf('usb3')===0)) {
		$("#vmform #usbmode").val('usb2');
	}
	$("#vmform #usbmode option[value^='usb3']").prop('disabled', noUSB3);

	$("#vmform .gpu").change();

	$('#vmform .cdrom').change();

	regenerateDiskPreview();

	resetForm();
});
</script>
