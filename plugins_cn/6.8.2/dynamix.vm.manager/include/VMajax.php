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

function requireLibvirt() {
	global $lv;
	// Make sure libvirt is connected to qemu
	if (!isset($lv) || !$lv->enabled()) {
		header('Content-Type: application/json');
		die(json_encode(['error' => 'failed to connect to the hypervisor']));
	}
}

function scan($line, $text) {
	return stripos($line,$text)!==false;
}

function embed(&$syslinux, $key, $value) {
	$size = count($syslinux);
	$make = false;
	$new = strlen($value) ? "$key=$value" : false;
	$i = 0;
	while ($i < $size) {
		// find sections and exclude safemode
		if (scan($syslinux[$i],'label ') && !scan($syslinux[$i],'safe mode') && !scan($syslinux[$i],'safemode')) {
			$n = $i + 1;
			// find the current requested setting
			while (!scan($syslinux[$n],'label ') && $n < $size) {
				if (scan($syslinux[$n],'append ')) {
					$cmd = preg_split('/\s+/',trim($syslinux[$n]));
					// replace the existing setting
					for ($c = 1; $c < count($cmd); $c++) if (scan($cmd[$c],$key)) {$make |= ($cmd[$c]!=$new); $cmd[$c] = $new; break;}
					// or insert the new setting
					if ($c==count($cmd) && $new) {array_splice($cmd,-1,0,$new); $make = true;}
					$syslinux[$n] = '  '.str_replace('  ',' ',implode(' ',$cmd));
				}
				$n++;
			}
			$i = $n - 1;
		}
		$i++;
	}
	return $make;
}

$arrSizePrefix = [0 => '', 1 => 'K', 2 => 'M', 3 => 'G', 4 => 'T', 5 => 'P'];
$_REQUEST      = array_merge($_GET, $_POST);
$action        = $_REQUEST['action'] ?? '';
$uuid          = $_REQUEST['uuid'] ?? '';
$arrResponse   = [];

if ($uuid) {
	requireLibvirt();
	$domName = $lv->domain_get_name_by_uuid($uuid);
	if (!$domName) {
		header('Content-Type: application/json');
		die(json_encode(['error' => $lv->get_last_error()]));
	}
}

switch ($action) {
case 'domain-autostart':
	requireLibvirt();
	$arrResponse = $lv->domain_set_autostart($domName, $_REQUEST['autostart']!='false')
	? ['success' => true, 'autostart' => (bool)$lv->domain_get_autostart($domName)]
	: ['error' => $lv->get_last_error()];
	break;

case 'domain-start':
	requireLibvirt();
	$arrResponse = $lv->domain_start($domName)
	? ['success' => true, 'state' => $lv->domain_get_state($domName)]
	: ['error' => $lv->get_last_error()];
	break;

case 'domain-pause':
	requireLibvirt();
	$arrResponse = $lv->domain_suspend($domName)
	? ['success' => true, 'state' => $lv->domain_get_state($domName)]
	: ['error' => $lv->get_last_error()];
	break;

case 'domain-resume':
	requireLibvirt();
	$arrResponse = $lv->domain_resume($domName)
	? ['success' => true, 'state' => $lv->domain_get_state($domName)]
	: ['error' => $lv->get_last_error()];
	break;

case 'domain-pmsuspend':
	requireLibvirt();
	// No support in libvirt-php to do a dompmsuspend, use virsh tool instead
	exec("virsh dompmsuspend ".escapeshellarg($uuid)." disk 2>&1", $arrOutput, $intReturnCode);
	$arrResponse = $intReturnCode==0
	? ['success' => true, 'state' => $lv->domain_get_state($domName)]
	: ['error' => str_replace('error: ', '', implode('. ', $arrOutput))];
	break;

case 'domain-pmwakeup':
	requireLibvirt();
	// No support in libvirt-php to do a dompmwakeup, use virsh tool instead
	exec("virsh dompmwakeup ".escapeshellarg($uuid)." 2>&1", $arrOutput, $intReturnCode);
	$arrResponse = $intReturnCode==0
	? ['success' => true, 'state' => $lv->domain_get_state($domName)]
	: ['error' => str_replace('error: ', '', implode('. ', $arrOutput))];
	break;

case 'domain-restart':
	requireLibvirt();
	$arrResponse = $lv->domain_reboot($domName)
	? ['success' => true, 'state' => $lv->domain_get_state($domName)]
	: ['error' => $lv->get_last_error()];
	break;

case 'domain-save':
	requireLibvirt();
	$arrResponse = $lv->domain_save($domName)
	? ['success' => true, 'state' => $lv->domain_get_state($domName)]
	: ['error' => $lv->get_last_error()];
	break;

case 'domain-stop':
	requireLibvirt();
	$arrResponse = $lv->domain_shutdown($domName)
	? ['success' => true, 'state' => $lv->domain_get_state($domName)]
	: ['error' => $lv->get_last_error()];
	$n = 30; // wait for VM to die
	while ($arrResponse['success'] && $lv->domain_get_state($domName)=='running') {
		sleep(1); if(!--$n) break;
	}
	break;

case 'domain-destroy':
	requireLibvirt();
	$arrResponse = $lv->domain_destroy($domName)
	? ['success' => true, 'state' => $lv->domain_get_state($domName)]
	: ['error' => $lv->get_last_error()];
	break;

case 'domain-delete':
	requireLibvirt();
	$arrResponse = $lv->domain_delete($domName)
	? ['success' => true]
	: ['error' => $lv->get_last_error()];
	break;

case 'domain-undefine':
	requireLibvirt();
	$arrResponse = $lv->domain_undefine($domName)
	? ['success' => true]
	: ['error' => $lv->get_last_error()];
	break;

case 'domain-define':
	requireLibvirt();
	$domName = $lv->domain_define($_REQUEST['xml']);
	$arrResponse = $domName
	? ['success' => true, 'state' => $lv->domain_get_state($domName)]
	: ['error' => $lv->get_last_error()];
	break;

case 'domain-state':
	requireLibvirt();
	$state = $lv->domain_get_state($domName);
	$arrResponse = $state
	? ['success' => true, 'state' => $state]
	: ['error' => $lv->get_last_error()];
	break;

case 'domain-diskdev':
	requireLibvirt();
	$arrResponse = $lv->domain_set_disk_dev($domName, $_REQUEST['olddev'], $_REQUEST['diskdev'])
	? ['success' => true]
	: ['error' => $lv->get_last_error()];
	break;

case 'cdrom-change':
	requireLibvirt();
	$arrResponse = $lv->domain_change_cdrom($domName, $_REQUEST['cdrom'], $_REQUEST['dev'], $_REQUEST['bus'])
	? ['success' => true]
	: ['error' => $lv->get_last_error()];
	break;

case 'memory-change':
	requireLibvirt();
	$arrResponse = $lv->domain_set_memory($domName, $_REQUEST['memory']*1024)
	? ['success' => true]
	: ['error' => $lv->get_last_error()];
	break;

case 'vcpu-change':
	requireLibvirt();
	$arrResponse = $lv->domain_set_vcpu($domName, $_REQUEST['vcpu'])
	? ['success' => true]
	: ['error' => $lv->get_last_error()];
	break;

case 'bootdev-change':
	requireLibvirt();
	$arrResponse = $lv->domain_set_boot_device($domName, $_REQUEST['bootdev'])
	? ['success' => true]
	: ['error' => $lv->get_last_error()];
	break;

case 'disk-remove':
	requireLibvirt();
	// libvirt-php has an issue with detaching a disk, use virsh tool instead
	exec("virsh detach-disk ".escapeshellarg($uuid)." ".escapeshellarg($_REQUEST['dev'])." 2>&1", $arrOutput, $intReturnCode);
	$arrResponse = $intReturnCode==0
	? ['success' => true]
	: ['error' => str_replace('error: ', '', implode('. ', $arrOutput))];
	break;

case 'snap-create':
	requireLibvirt();
	$arrResponse = $lv->domain_snapshot_create($domName)
	? ['success' => true]
	: ['error' => $lv->get_last_error()];
	break;

case 'snap-delete':
	requireLibvirt();
	$arrResponse = $lv->domain_snapshot_delete($domName, $_REQUEST['snap'])
	? ['success' => true]
	: ['error' => $lv->get_last_error()];
	break;

case 'snap-revert':
	requireLibvirt();
	$arrResponse = $lv->domain_snapshot_revert($domName, $_REQUEST['snap'])
	? ['success' => true]
	: ['error' => $lv->get_last_error()];
	break;

case 'snap-desc':
	requireLibvirt();
	$arrResponse = $lv->snapshot_set_metadata($domName, $_REQUEST['snap'], $_REQUEST['snapdesc'])
	? ['success' => true]
	: ['error' => $lv->get_last_error()];
	break;

case 'disk-create':
	$disk = $_REQUEST['disk'];
	$driver = $_REQUEST['driver'];
	$size = str_replace(["KB","MB","GB","TB","PB", " ", ","], ["K","M","G","T","P", "", ""], strtoupper($_REQUEST['size']));
	$dir = dirname($disk);
	if (!is_dir($dir)) mkdir($dir);
	// determine the actual disk if user share is being used
	$dir = transpose_user_path($dir);
	@exec("chattr +C -R ".escapeshellarg($dir)." >/dev/null");
	$strLastLine = exec("qemu-img create -q -f ".escapeshellarg($driver)." ".escapeshellarg($disk)." ".escapeshellarg($size)." 2>&1", $out, $status);
	$arrResponse = empty($status)
	? ['success' => true]
	: ['error' => $strLastLine];
	break;

case 'disk-resize':
	$disk = $_REQUEST['disk'];
	$capacity = str_replace(["KB","MB","GB","TB","PB", " ", ","], ["K","M","G","T","P", "", ""], strtoupper($_REQUEST['cap']));
	$old_capacity = str_replace(["KB","MB","GB","TB","PB", " ", ","], ["K","M","G","T","P", "", ""], strtoupper($_REQUEST['oldcap']));
	if (substr($old_capacity,0,-1) < substr($capacity,0,-1)){
		$strLastLine = exec("qemu-img resize -q ".escapeshellarg($disk)." ".escapeshellarg($capacity)." 2>&1", $out, $status);
		$arrResponse = empty($status)
		? ['success' => true]
		: ['error' => $strLastLine];
	} else {
		$arrResponse = ['error' => "磁盘容量必须大于 ".$old_capacity];
	}
	break;

case 'file-info':
	$file = $_REQUEST['file'];
	$arrResponse = [
		'isfile' => (!empty($file) ? is_file($file) : false),
		'isdir' => (!empty($file) ? is_dir($file) : false),
		'isblock' => (!empty($file) ? is_block($file) : false),
		'resizable' => false
	];
	// if file, get size and format info
	if (is_file($file)) {
		$json_info = getDiskImageInfo($file);
		if (!empty($json_info)) {
			$intDisplaySize = (int)$json_info['virtual-size'];
			$intShifts = 0;
			while (!empty($intDisplaySize) && (floor($intDisplaySize) == $intDisplaySize) && isset($arrSizePrefix[$intShifts])) {
				$arrResponse['display-size'] = $intDisplaySize.$arrSizePrefix[$intShifts];
				$intDisplaySize /= 1024;
				$intShifts++;
			}
			$arrResponse['virtual-size'] = $json_info['virtual-size'];
			$arrResponse['actual-size'] = $json_info['actual-size'];
			$arrResponse['format'] = $json_info['format'];
			$arrResponse['dirty-flag'] = $json_info['dirty-flag'];
			$arrResponse['resizable'] = true;
		}
	} elseif (is_block($file)) {
		$strDevSize = trim(shell_exec("blockdev --getsize64 ".escapeshellarg($file)));
		if (!empty($strDevSize) && is_numeric($strDevSize)) {
			$arrResponse['actual-size'] = (int)$strDevSize;
			$arrResponse['format'] = 'raw';
			$intDisplaySize = (int)$strDevSize;
			$intShifts = 0;
			while (!empty($intDisplaySize) && ($intDisplaySize >= 2) && isset($arrSizePrefix[$intShifts])) {
				$arrResponse['display-size'] = round($intDisplaySize, 0).$arrSizePrefix[$intShifts];
				$intDisplaySize /= 1000; // 1000 looks better than 1024 for block devs
				$intShifts++;
			}
		}
	}
	break;

case 'generate-mac':
	requireLibvirt();
	$arrResponse = ['mac' => $lv->generate_random_mac_addr()];
	break;

case 'get-vm-icons':
	$arrImages = [];
	foreach (glob("$docroot/plugins/dynamix.vm.manager/templates/images/*.png") as $png_file) {
		$arrImages[] = [
			'custom' => false,
			'basename' => basename($png_file),
			'url' => '/plugins/dynamix.vm.manager/templates/images/'.basename($png_file)
		];
	}
	$arrResponse = $arrImages;
	break;

case 'get-usb-devices':
	$arrValidUSBDevices = getValidUSBDevices();
	$arrResponse = $arrValidUSBDevices;
	break;

case 'hot-attach-usb':
	//TODO - If usb is a block device, then attach as a <disk type="usb"> otherwise <hostdev type="usb">
	/*
		<hostdev mode='subsystem' type='usb'>
			<source startupPolicy='optional'>
				<vendor id='0x1234'/>
				<product id='0xbeef'/>
			</source>
		</hostdev>
	<disk type='block' device='disk'>
			<driver name='qemu' type='raw'/>
			<source dev='/dev/sda'/>
			<target dev='hdX' bus='virtio'/>
		</disk>
	*/
	break;

case 'hot-detach-usb':
	//TODO
	break;

case 'syslinux':
	$cfg = '/boot/syslinux/syslinux.cfg';
	$syslinux = file($cfg, FILE_IGNORE_NEW_LINES+FILE_SKIP_EMPTY_LINES);
	$m1 = embed($syslinux, 'pcie_acs_override', $_REQUEST['pcie']);
	$m2 = embed($syslinux, 'vfio_iommu_type1.allow_unsafe_interrupts', $_REQUEST['vfio']);
	if ($m1||$m2) file_put_contents($cfg, implode("\n",$syslinux)."\n");
	$arrResponse = ['success' => true, 'modified' => $m1|$m2];
	break;

case 'reboot':
	$cfg = '/boot/syslinux/syslinux.cfg';
	$syslinux = file($cfg, FILE_IGNORE_NEW_LINES+FILE_SKIP_EMPTY_LINES);
	$cmdline = explode(' ',file_get_contents('/proc/cmdline'));
	$pcie = $vfio = '';
	foreach ($cmdline as $cmd) {
		if (scan($cmd,'pcie_acs_override')) $pcie = explode('=',$cmd)[1];
		if (scan($cmd,'allow_unsafe_interrupts')) $vfio = explode('=',$cmd)[1];
	}
	$m1 = embed($syslinux, 'pcie_acs_override', $pcie);
	$m2 = embed($syslinux, 'vfio_iommu_type1.allow_unsafe_interrupts', $vfio);
	$arrResponse = ['success' => true, 'modified' => $m1|$m2];
	break;

case 'virtio-win-iso-info':
	$path = $_REQUEST['path'];
	$file = $_REQUEST['file'];
	$pid = pgrep('-f "VirtIOWin_'.basename($file, '.iso').'_install.sh"', false);
	if (empty($file)) {
		$arrResponse = ['exists' => false, 'pid' => $pid];
		break;
	}
	if (is_file($file)) {
		$arrResponse = ['exists' => true, 'pid' => $pid, 'path' => $file];
		break;
	}
	if (empty($path) || !is_dir($path)) {
		$path = '/mnt/user/isos/';
	} else {
		$path = str_replace('//', '/', $path.'/');
	}
	$file = $path.$file;
	$arrResponse = is_file($file)
	? ['exists' => true, 'pid' => $pid, 'path' => $file]
	: ['exists' => false, 'pid' => $pid];
	break;

case 'virtio-win-iso-download':
	$arrDownloadVirtIO = [];
	$strKeyName = basename($_REQUEST['download_version'], '.iso');
	if (array_key_exists($strKeyName, $virtio_isos)) {
		$arrDownloadVirtIO = $virtio_isos[$strKeyName];
	}
	if (empty($arrDownloadVirtIO)) {
		$arrResponse = ['error' => '未知的版本: '.$_REQUEST['download_version']];
	} elseif (empty($_REQUEST['download_path'])) {
		$arrResponse = ['error' => '首先指定 ISO 存储路径'];
	} elseif (!is_dir($_REQUEST['download_path'])) {
		$arrResponse = ['error' => 'ISO 存储路径不存在, 请先创建用户共享 (或空文件夹)'];
	} else {
		@mkdir($_REQUEST['download_path'], 0777, true);
		$_REQUEST['download_path'] = realpath($_REQUEST['download_path']).'/';
		// Check free space
		if (disk_free_space($_REQUEST['download_path']) < $arrDownloadVirtIO['size']+10000) {
			$arrResponse['error'] = '没有足够的可用空间, 至少需要 '.ceil($arrDownloadVirtIO['size']/1000000).'MB';
			break;
		}
		$boolCheckOnly = !empty($_REQUEST['checkonly']);
		$strInstallScript = '/tmp/VirtIOWin_'.$strKeyName.'_install.sh';
		$strInstallScriptPgrep = '-f "VirtIOWin_'.$strKeyName.'_install.sh"';
		$strTargetFile = $_REQUEST['download_path'].$arrDownloadVirtIO['name'];
		$strLogFile = $strTargetFile.'.log';
		$strMD5File = $strTargetFile.'.md5';
		$strMD5StatusFile = $strTargetFile.'.md5status';
		// Save to /boot/config/domain.conf
		$domain_cfg['MEDIADIR'] = $_REQUEST['download_path'];
		$domain_cfg['VIRTIOISO'] = $strTargetFile;
		$tmp = '';
		foreach ($domain_cfg as $key => $value) $tmp .= "$key=\"$value\"\n";
		file_put_contents($domain_cfgfile, $tmp);
		$strDownloadCmd = 'wget -nv -c -O '.escapeshellarg($strTargetFile).' '.escapeshellarg($arrDownloadVirtIO['url']);
		$strDownloadPgrep = '-f "wget.*'.$strTargetFile.'.*'.$arrDownloadVirtIO['url'].'"';
		$strVerifyCmd = 'md5sum -c '.escapeshellarg($strMD5File);
		$strVerifyPgrep = '-f "md5sum.*'.$strMD5File.'"';
		$strCleanCmd = '(chmod 777 '.escapeshellarg($_REQUEST['download_path']).' '.escapeshellarg($strTargetFile).'; chown nobody:users '.escapeshellarg($_REQUEST['download_path']).' '.escapeshellarg($strTargetFile).'; rm '.escapeshellarg($strMD5File).' '.escapeshellarg($strMD5StatusFile).')';
		$strCleanPgrep = '-f "chmod.*chown.*rm.*'.$strMD5StatusFile.'"';
		$strAllCmd = "#!/bin/bash\n\n";
		$strAllCmd .= $strDownloadCmd.' >>'.escapeshellarg($strLogFile).' 2>&1 && ';
		$strAllCmd .= 'echo "'.$arrDownloadVirtIO['md5'].'  '.$strTargetFile.'" > '.escapeshellarg($strMD5File).' && ';
		$strAllCmd .= $strVerifyCmd.' >'.escapeshellarg($strMD5StatusFile).' 2>/dev/null && ';
		$strAllCmd .= $strCleanCmd.' >>'.escapeshellarg($strLogFile).' 2>&1 && ';
		$strAllCmd .= 'rm '.escapeshellarg($strLogFile).' && ';
		$strAllCmd .= 'rm '.escapeshellarg($strInstallScript);
		$arrResponse = [];
		if (file_exists($strTargetFile)) {
			if (!file_exists($strLogFile)) {
				if (!pgrep($strDownloadPgrep, false)) {
					// Status = done
					$arrResponse['status'] = 'Done';
					$arrResponse['localpath'] = $strTargetFile;
					$arrResponse['localfolder'] = dirname($strTargetFile);
				} else {
					// Status = cleanup
					$arrResponse['status'] = 'Cleanup ... ';
				}
			} else {
				if (pgrep($strDownloadPgrep, false)) {
					// Get Download percent completed
					$intSize = filesize($strTargetFile);
					$strPercent = 0;
					if ($intSize > 0) {
						$strPercent = round(($intSize / $arrDownloadVirtIO['size']) * 100);
					}
					$arrResponse['status'] = 'Downloading ... '.$strPercent.'%';
				} elseif (pgrep($strVerifyPgrep, false)) {
					// Status = running md5 check
					$arrResponse['status'] = 'Verifying ... ';
				} elseif (file_exists($strMD5StatusFile)) {
					// Status = running extract
					$arrResponse['status'] = 'Cleanup ... ';
					// Examine md5 status
					$strMD5StatusContents = file_get_contents($strMD5StatusFile);
					if (strpos($strMD5StatusContents, ': FAILED') !== false) {
						// ERROR: MD5 check failed
						unset($arrResponse['status']);
						$arrResponse['error'] = 'MD5 verification failed, your download is incomplete or corrupted.';
					}
				} elseif (!file_exists($strMD5File)) {
					// Status = running md5 check
					$arrResponse['status'] = 'Downloading ... 100%';
					if (!pgrep($strInstallScriptPgrep, false) && !$boolCheckOnly) {
						// Run all commands
						file_put_contents($strInstallScript, $strAllCmd);
						chmod($strInstallScript, 0777);
						exec($strInstallScript.' >/dev/null 2>&1 &');
					}
				}
			}
		} elseif (!$boolCheckOnly) {
			if (!pgrep($strInstallScriptPgrep, false)) {
				// Run all commands
				file_put_contents($strInstallScript, $strAllCmd);
				chmod($strInstallScript, 0777);
				exec($strInstallScript.' >/dev/null 2>&1 &');
			}
			$arrResponse['status'] = 'Downloading ... ';
		}
		$arrResponse['pid'] = pgrep($strInstallScriptPgrep, false);
	}
	break;

case 'virtio-win-iso-cancel':
	$arrDownloadVirtIO = [];
	$strKeyName = basename($_REQUEST['download_version'], '.iso');
	if (array_key_exists($strKeyName, $virtio_isos)) {
		$arrDownloadVirtIO = $virtio_isos[$strKeyName];
	}
	if (empty($arrDownloadVirtIO)) {
		$arrResponse = ['error' => '未知的错误: '.$_REQUEST['download_version']];
	} elseif (empty($_REQUEST['download_path'])) {
		$arrResponse = ['error' => 'ISO 存储路径为空'];
	} elseif (!is_dir($_REQUEST['download_path'])) {
		$arrResponse = ['error' => 'ISO 存储路径不存在'];
	} else {
		$strInstallScriptPgrep = '-f "VirtIOWin_'.$strKeyName.'_install.sh"';
		$pid = pgrep($strInstallScriptPgrep, false);
		if (!$pid) {
			$arrResponse = ['error' => '没有运行'];
		} else {
			if (!posix_kill($pid, SIGTERM)) {
				$arrResponse = ['error' => '无法停止进程'];
			} else {
				$strTargetFile = $_REQUEST['download_path'].$arrDownloadVirtIO['name'];
				$strLogFile = $strTargetFile.'.log';
				$strMD5File = $strTargetFile.'.md5';
				$strMD5StatusFile = $strTargetFile.'.md5status';
				@unlink($strTargetFile);
				@unlink($strMD5File);
				@unlink($strMD5StatusFile);
				@unlink($strLogFile);
				$arrResponse['status'] = 'Done';
			}
		}
	}
	break;

case 'virtio-win-iso-remove':
	$path = $_REQUEST['path'];
	$file = $_REQUEST['file'];
	$pid = pgrep('-f "VirtIOWin_'.basename($file, '.iso').'_install.sh"', false);
	if (empty($file) || substr($file, -4) !== '.iso') {
		$arrResponse = ['success' => false];
		break;
	}
	if ($pid !== false) {
		$arrResponse = ['success' => false];
		break;
	}
	if (is_file($file)) {
		$arrResponse = ['success' => unlink($file)];
		break;
	}
	if (empty($path) || !is_dir($path)) {
		$path = '/mnt/user/isos/';
	} else {
		$path = str_replace('//', '/', $path.'/');
	}
	$file = $path.$file;
	if (is_file($file)) {
		$arrResponse = ['success' => unlink($file)];
		break;
	}
	$arrResponse = ['success' => false];
	break;

default:
	$arrResponse = ['error' => 'Unknown action \''.$action.'\''];
	break;
}

header('Content-Type: application/json');
die(json_encode($arrResponse));
