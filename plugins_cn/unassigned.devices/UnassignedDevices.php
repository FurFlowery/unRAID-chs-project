<?php
/* Copyright 2015, Guilherme Jardim
 * Copyright 2016-2020, Dan Landon
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */

$plugin = "unassigned.devices";
$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
$translations = file_exists("$docroot/webGui/include/Translations.php");

if ($translations) {
	/* add translations */
	$_SERVER['REQUEST_URI'] = 'unassigneddevices';
	require_once "$docroot/webGui/include/Translations.php";
} else {
	/* legacy support (without javascript) */
	$noscript = true;
	require_once "$docroot/plugins/$plugin/include/Legacy.php";
}

require_once("plugins/{$plugin}/include/lib.php");
require_once("webGui/include/Helpers.php");

if (isset($_POST['display'])) $display = $_POST['display'];
if (isset($_POST['var'])) $var = $_POST['var'];

function netmasks($netmask, $rev = false)
{
	$netmasks = [	"255.255.255.252"	=> "30",
					"255.255.255.248"	=> "29",
					"255.255.255.240"	=> "28",
					"255.255.255.224"	=> "27",
					"255.255.255.192"	=> "26",
					"255.255.255.128"	=> "25",
					"255.255.255.0"		=> "24",
					"255.255.254.0"		=> "23",
					"255.255.252.0"		=> "22",
					"255.255.248.0"		=> "21",
					"255.255.240.0" 	=> "20",
					"255.255.224.0" 	=> "19",
					"255.255.192.0" 	=> "18",
					"255.255.128.0" 	=> "17",
					"255.255.0.0"		=> "16",
				];
	return $rev ? array_flip($netmasks)[$netmask] : $netmasks[$netmask];
}

function render_used_and_free($partition, $mounted) {
	global $display;

	if (strlen($partition['target']) && $mounted) {
		$free_pct = $partition['size'] ? round(100*$partition['avail']/$partition['size']) : 0;
		$used_pct = 100-$free_pct;
	    if ($display['text'] % 10 == 0) {
			$o = "<td>".my_scale($partition['used'], $unit)." $unit</td>";
		} else {
			$o = "<td><div class='usage-disk'><span style='margin:0;width:$used_pct%' class='".usage_color($display,$used_pct,false)."'></span><span>".my_scale($partition['used'], $unit)." $unit</span></div></td>";
		}
	    if ($display['text'] < 10 ? $display['text'] % 10 == 0 : $display['text'] % 10 != 0) {
			$o .= "<td>".my_scale($partition['avail'], $unit)." $unit</td>";
		} else {
			$o .= "<td><div class='usage-disk'><span style='margin:0;width:$free_pct%' class='".usage_color($display,$free_pct,true)."'></span><span>".my_scale($partition['avail'], $unit)." $unit</span></div></td>";
		}
	} else {
		$o = "<td>-</td><td>-</td>";
	}
	return $o;
}

function render_used_and_free_disk($disk, $mounted) {
	global $display;

	if ($mounted) {
		$size	= 0;
		$avail	= 0;
		$used	= 0;
		foreach ($disk['partitions'] as $partition) {
			$size	+= $partition['size'];
			$avail	+= $partition['avail'];
			$used 	+= $partition['used'];
		}
		$free_pct = $size ? round(100*$avail/$size) : 0;
		$used_pct = 100-$free_pct;
	    if ($display['text'] % 10 == 0) {
			$o = "<td>".my_scale($used, $unit)." $unit</td>";
		} else {
			$o = "<td><div class='usage-disk'><span style='margin:0;width:$used_pct%' class='".usage_color($display,$used_pct,false)."'></span><span>".my_scale($used, $unit)." $unit</span></div></td>";
		}
	    if ($display['text'] < 10 ? $display['text'] % 10 == 0 : $display['text'] % 10 != 0) {
			$o .= "<td>".my_scale($avail, $unit)." $unit</td>";
		} else {
			$o .= "<td><div class='usage-disk'><span style='margin:0;width:$free_pct%' class='".usage_color($display,$free_pct,true)."'></span><span>".my_scale($avail, $unit)." $unit</span></div></td>";
		}
	} else {
		$o = "<td>-</td><td>-</td>";
	}
	return $o;
}

function render_partition($disk, $partition, $total=FALSE) {
	global $plugin;

	if (! isset($partition['device'])) return array();
	$out = array();

	$mounted =	$partition['mounted'];
	$cmd = $partition['command'];
	if ($mounted && is_file($cmd)) {
		$script_partition = $partition['fstype'] == "crypto_LUKS" ? $partition['luks'] : $partition['device'];
		if ((! is_script_running($cmd)) & (! is_script_running($partition['user_command'], TRUE))) {
			$fscheck = "<a title='"._("作为udev执行脚本，模拟正在安装的设备")."' class='exec' onclick='openWindow_fsck(\"/plugins/{$plugin}/include/script.php?device={$script_partition}&type="._('完成')."\",\"Execute Script\",600,900);'><i class='fa fa-flash partition-script'></i></a>{$partition['part']}";
		} else {
			$fscheck = "<i class='fa fa-flash partition-script'></i>{$partition['part']}";
		}
	} elseif ( (! $mounted && $partition['fstype'] != 'btrfs') ) {
		$fscheck = "<a title='"._('文件系统检查')."' class='exec' onclick='openWindow_fsck(\"/plugins/{$plugin}/include/fsck.php?device={$partition['device']}&fs={$partition['fstype']}&luks={$partition['luks']}&serial={$partition['serial']}&check_type=ro&type="._('完成')."\",\"Check filesystem\",600,900);'><i class='fa fa-check partition-hdd'></i></a>{$partition['part']}";
	} else {
		$fscheck = "<i class='fa fa-check partition-hdd'></i>{$partition['part']}";
	}

	$rm_partition = (file_exists("/usr/sbin/parted") && get_config("Config", "destructive_mode") == "enabled" && (! $disk['partitions'][0]['pass_through'])) ? "<span title='"._("删除分区")."' device='{$partition['device']}' class='exec' style='color:#CC0000;font-weight:bold;' onclick='rm_partition(this,\"{$disk['device']}\",\"{$partition['part']}\");'><i class='fa fa-remove hdd'></i></span>" : "";
	$mpoint = "<span>{$fscheck}";
	$mount_point = basename($partition['mountpoint']);
	if ($mounted) {
		$mpoint .= "<i class='fa fa-folder-open partition-hdd'></i><a title='"._("浏览硬盘共享")."' href='/Main/Browse?dir={$partition['mountpoint']}'>{$mount_point}</a></span>";
	} else {
		$mount_point = basename($partition['mountpoint']);
		$device = ($partition['fstype'] == "crypto_LUKS") ? $partition['luks'] : $partition['device'];
		$mpoint .= "<i class='fa fa-pencil partition-hdd'></i><a title='"._("修改硬盘挂载点")."' class='exec' onclick='chg_mountpoint(\"{$partition['serial']}\",\"{$partition['part']}\",\"{$device}\",\"{$partition['fstype']}\",\"{$mount_point}\");'>{$mount_point}</a>";
		$mpoint .= "{$rm_partition}</span>";
	}
	$temp = my_temp($disk['temperature']);
	$mbutton = make_mount_button($partition);

	($disk['show_partitions'] != 'yes') ? $style = "style='display:none;'" : $style = "";
	$out[] = "<tr class='toggle-parts toggle-".basename($disk['device'])."' name='toggle-".basename($disk['device'])."' $style>";
	$out[] = "<td></td>";
	$out[] = "<td>{$mpoint}</td>";
	$out[] = "<td class='mount'>{$mbutton}</td>";
	$fstype = $partition['fstype'];
	if ($total) {
		foreach ($disk['partitions'] as $part) {
			if ($part['fstype']) {
				$fstype = $part['fstype'];
				break;
			}
		}
	}

	/* Reads and writes */
	if ($total) {
		$out[] = "<td>".my_scale($part['reads'],$unit,0,null,1)."</td>";
		$out[] = "<td>".my_scale($part['writes'],$unit,0,null,1)."</td>";
	} else {
		$out[] = "<td></td>";
		$out[] = "<td></td><td></td>";
	}

	$title = _("编辑设备设置与脚本").".";
	if ($total) {
		$title .= "\n"._("直写").":  ";
		$title .= ($partition['pass_through'] == 'yes') ? "On" : "Off";
		$title .= "   "._("只读").": ";
		$title .= ($partition['read_only'] == 'yes') ? "On" : "Off";
		$title .= "   "._("自动挂载").": ";
		$title .= ($partition['automount'] == 'yes') ? "On" : "Off";
		$title .=  "   ";
	} else {
		$title .= "\n";
	}
	$title .= _("共享").": ";
	$title .= ($partition['shared'] == 'yes') ? "On" : "Off";

	$out[] = "<td><a title='$title' href='/Main/EditSettings?s=".urlencode($partition['serial'])."&l=".urlencode(basename($partition['mountpoint']))."&p=".urlencode($partition['part'])."&m=".urlencode(json_encode($partition))."&t=".$total."'><i class='fa fa-gears'></i></a></td>";
	if ($total) {
		$mounted_disk = FALSE;
		foreach ($disk['partitions'] as $part) {
			if ($part['mounted']) {
				$mounted_disk = TRUE;
				break;
			}
		}
	}

	$out[] = "<td>".($fstype == "crypto_LUKS" ? luks_fs_type($partition['mountpoint']) : $fstype)."</td>";
	if ($total) {
		$out[] = render_used_and_free_disk($disk, $mounted_disk);
	} else {
		$out[] = "<td>".my_scale($partition['size'], $unit)." $unit</td>";
		$out[] = render_used_and_free($partition, $mounted);
	}
	$out[] = "<td><a title='"._("查看设备脚本日志")."' href='/Main/ScriptLog?s=".urlencode($partition['serial'])."&l=".urlencode(basename($partition['mountpoint']))."&p=".urlencode($partition['part'])."'><i class='fa fa-align-left".( $partition['command'] ? "":" grey-orb" )."'></i></a></td>";
	$out[] = "</tr>";
	return $out;
}

function make_mount_button($device) {
	global $paths, $Preclear;

	$button = "<span><button device='{$device['device']}' class='mount' context='%s' role='%s' %s><i class='%s'></i>%s</button></span>";

	if (isset($device['partitions'])) {
		$mounted = isset($device['mounted']) ? $device['mounted'] : in_array(TRUE, array_map(function($ar){return $ar['mounted'];}, $device['partitions']));
		$disable = count(array_filter($device['partitions'], function($p){ if (! empty($p['fstype']) && $p['fstype'] != "precleared") return TRUE;})) ? "" : "disabled";
		$format	 = (isset($device['partitions']) && ! count($device['partitions'])) || $device['partitions'][0]['fstype'] == "precleared" ? true : false;
		$context = "disk";
	} else {
		$mounted =	$device['mounted'];
		$disable = (! empty($device['fstype']) && $device['fstype'] != "crypto_LUKS" && $device['fstype'] != "precleared") ? "" : "disabled";
		$format	 = ((isset($device['fstype']) && empty($device['fstype'])) || $device['fstype'] == "precleared") ? true : false;
		$context = "partition";
	}
	$is_mounting	= array_values(preg_grep("@/mounting_".basename($device['device'])."@i", listDir(dirname($paths['mounting']))))[0];
	$is_mounting	= (time() - filemtime($is_mounting) < 300) ? TRUE : FALSE;
	$is_unmounting	= array_values(preg_grep("@/unmounting_".basename($device['device'])."@i", listDir(dirname($paths['unmounting']))))[0];
	$is_unmounting	= (time() - filemtime($is_unmounting) < 300) ? TRUE : FALSE;
	$is_formatting	= array_values(preg_grep("@/formatting_".basename($device['device'])."@i", listDir(dirname($paths['formatting']))))[0];
	$is_formatting	= (time() - filemtime($is_formatting) < 300) ? TRUE : FALSE;

	$preclearing	= $Preclear ? $Preclear->isRunning(basename($device['device'])) : false;
	if ($device['size'] == 0) {
		$button = sprintf($button, $context, 'mount', 'disabled', 'fa fa-erase', _('挂载'));
	} elseif ($format) {
		$disable = (file_exists("/usr/sbin/parted") && get_config("Config", "destructive_mode") == "enabled") ? "" : "disabled";
		$disable = $preclearing ? "disabled" : $disable;
		$button = sprintf($button, $context, 'format', $disable, 'fa fa-erase', _('格式化'));
	} elseif ($is_mounting) {
		$button = sprintf($button, $context, 'umount', 'disabled', 'fa fa-circle-o-notch fa-spin', ' '._('挂载中'));
	} elseif ($is_unmounting) {
		$button = sprintf($button, $context, 'mount', 'disabled', 'fa fa-circle-o-notch fa-spin', ' '._('卸载中'));
	} elseif ($is_formatting) {
		$button = sprintf($button, $context, 'format', 'disabled', 'fa fa-circle-o-notch fa-spin', ' '._('格式化中'));
	} elseif ($mounted) {
		$cmd = $device['command'];
		$script_running = ((is_script_running($cmd)) || (is_script_running($device['user_command'], TRUE)));;
		if ($script_running) {
			$button = sprintf($button, $context, 'umount', 'disabled', 'fa fa-circle-o-notch fa-spin', ' '._('运行中'));
		} else {
			$disable = ! isset($device['partitions'][0]['mountpoint']) || is_mounted($device['partitions'][0]['mountpoint'], TRUE) ? $disable : "disabled";
			$disable = ! isset($device['mountpoint']) || is_mounted($device['mountpoint'], TRUE) ? $disable : "disabled";
			$button = sprintf($button, $context, 'umount', $disable, 'fa fa-export', _('卸载'));
		}
	} else {
		$disable = ($device['partitions'][0]['pass_through'] || $preclearing ) ? "disabled" : $disable;
		$button = sprintf($button, $context, 'mount', $disable, 'fa fa-import', _('挂载'));
	}
	return $button;
}


switch ($_POST['action']) {
	case 'get_content':
		global $paths;

		unassigned_log("Starting page render [get_content]", "DEBUG");
		$time		 = -microtime(true);

		/* Check for a recent hot plug event. */
		$tc = $paths['hotplug_status'];
		$hotplug = is_file($tc) ? json_decode(file_get_contents($tc),TRUE) : "no";
		if ($hotplug == "yes") {
			exec("/usr/local/sbin/emcmd 'cmdHotplug=apply'");
			file_put_contents($tc, json_encode('no'));
		}

		/* Disk devices */
		$disks = get_all_disks_info();
		echo "<div id='disks_tab' class='show-disks'>";
		echo "<table class='disk_status wide disk_mounts'><thead><tr><td>"._('设备')."</td><td>"._('识别号')."</td><td></td><td>"._('温度').".</td><td>"._('读取')."</td><td>"._('写入')."</td><td>"._('设置')."</td><td>"._('文件系统')."</td><td>"._('大小')."</td><td>"._('已使用')."</td><td>"._('空闲')."</td><td>"._('日志')."</td></tr></thead>";
		echo "<tbody>";
		if ( count($disks) ) {
			foreach ($disks as $disk) {
				$mounted		= isset($disk['mounted']) ? $disk['mounted'] : in_array(TRUE, array_map(function($ar){return is_mounted($ar['device']);}, $disk['partitions']));
				$disk_name		= basename($disk['device']);
				$disk_dev		= $disk['dev'];
				$p				= (count($disk['partitions']) > 0) ? render_partition($disk, $disk['partitions'][0], TRUE) : FALSE;
				$preclearing	= $Preclear ? $Preclear->isRunning($disk_name) : false;
				$is_precleared	= ($disk['partitions'][0]['fstype'] == "precleared") ? true : false;
				$flash			= ($disk['partitions'][0]['fstype'] == "vfat") ? true : false;
				$disk['temperature'] = $disk['temperature'] ? $disk['temperature'] : get_temp(substr($disk['device'],0,10), $disk['running']);
				$temp = my_temp($disk['temperature']);

				$mbutton = make_mount_button($disk);

				$preclear_link = ($disk['size'] !== 0 && ! $disk['partitions'][0]['fstype'] && ! $mounted && $Preclear && ! $preclearing  && get_config("Config", "destructive_mode") == "enabled") ? "&nbsp;&nbsp;".$Preclear->Link($disk_name, "icon") : "";

				$hdd_serial = "<a href=\"#\" title='"._("硬盘日志信息")."' onclick=\"openBox('/webGui/scripts/disk_log&amp;arg1={$disk_name}','硬盘日志信息',600,900,false);return false\"><i class='fa fa-hdd-o icon'></i></a>";
				if ( $p	&& ! ($is_precleared || $preclearing) )
				{
					$add_toggle = TRUE;

					if ($disk['show_partitions'] != 'yes') {
						$hdd_serial .="<span title='"._("点击查看/隐藏分区或挂载点")."' class='exec toggle-hdd' hdd='{$disk_name}'><i class='fa fa-plus-square fa-append'></i></span>";
					} else {
						$hdd_serial .="<span><i class='fa fa-minus-square fa-append grey-orb'></i></span>";
					}
				}
				else
				{
					$add_toggle = FALSE;
					$hdd_serial .= "<span class='toggle-hdd' hdd='{$disk_name}'></span>";
				}

				$device = strpos($disk_dev, "dev") === FALSE ? "" : " ({$disk_name})";
				$hdd_serial .= "{$disk['serial']}$device
								{$preclear_link}
								<span id='preclear_{$disk['serial_short']}' style='display:block;'></span>";

				echo "<tr class='toggle-disk'>";
				if (strpos($disk_dev, "dev") === FALSE) {
					$disk_display = $disk_dev;
				} else {
					$disk_display = substr($disk_dev, 0, 3)." ".substr($disk_dev, 3);
					$disk_display = ucfirst($disk_display);
				}
				if ( $flash || $preclearing ) {
					echo "<td><i class='fa fa-circle orb green-orb'></i>{$disk_display}</td>";
				} else {
					echo "<td>";
					if (strpos($disk_dev, "dev") === FALSE) {
						$str = "New?name";
						echo "<i class='fa fa-circle orb ".($disk['running'] ? "green-orb" : "grey-orb" )."'></i>";
					} else {
						$str = "Device?name";
						if (! $disk['ssd']) {
							if ($disk['running']) {
								echo "<a title='"._("点击休眠硬盘")."' class='exec' onclick='spin_down_disk(\"{$disk_dev}\")'><i id='disk_orb-{$disk_dev}' class='fa fa-circle orb green-orb'></i></a>";
							} else {
								echo "<a title='"._("点击唤醒硬盘")."' class='exec' onclick='spin_up_disk(\"{$disk_dev}\")'><i id='disk_orb-{$disk_dev}' class='fa fa-circle orb grey-orb'></i></a>";
							}
						} else {
							echo "<i class='fa fa-circle orb ".($disk['running'] ? "green-orb" : "grey-orb" )."'></i>";
						}
					}
					echo ($disk['partitions'][0]['fstype'] == "crypto_LUKS" ? "<i class='fa fa-lock orb'></i>" : "");
					echo "<a title= '"._("SMART 监测启动")." ".$disk_display."' href='/Main/{$str}={$disk_dev}'> {$disk_display}</a>";
					echo "</td>";
				}
				/* Device serial number */
				echo "<td>{$hdd_serial}</td>";
				/* Mount button */
				echo "<td class='mount'>{$mbutton}</td>";
				/* Disk temperature */
				echo "<td>{$temp}</td>";

				if (! $p) {
					$rw	= get_disk_reads_writes($disk['device']);
					$reads = $rw[0];
					$writes = $rw[1];
				}
				/* Reads */
				echo ($p)?$p[4]:"<td>".my_scale($reads,$unit,0,null,1)."</td>";
				/* Writes */
				echo ($p)?$p[5]:"<td>".my_scale($writes,$unit,0,null,1)."</td>";
				/* Settings */
				echo ($p)?$p[6]:"<td>-</td>";
				/* File system */
				echo ($p)?$p[7]:"<td>-</td>";
				/* Disk size */
				echo "<td>".my_scale($disk['size'],$unit)." {$unit}</td>";
				/* Disk used and free space */
				echo ($p)?$p[8]:"<td>-</td><td>-</td>";
				/* Log button */
				echo ($p)?$p[9]:"<td>-</td>";
				echo "</tr>";
				if ($add_toggle)
				{
					echo "<tr>";
					foreach ($disk['partitions'] as $partition) {
						foreach (render_partition($disk, $partition) as $l)
						{
							echo $l;
						}
					}
					echo "</tr>";
				}
			}
		} else {
			echo "<tr><td colspan='12' style='text-align:center;'>"._('无可用未分配硬盘').".</td></tr>";
		}
		echo "</tbody></table></div>";

		/* SAMBA Mounts */
		echo "<div id='smb_tab' class='show-shares'>";
		echo "<div id='title' class='show-disks samba_mounts'><span class='left'><img src='/plugins/$plugin/icons/smbsettings.png' class='icon'>"._('SMB 共享')." &nbsp;|&nbsp;<img src='/plugins/$plugin/icons/nfs.png' class='icon'>"._('NFS 共享')." &nbsp;|&nbsp;<img src='/plugins/$plugin/icons/iso.png' class='icon' style='width:16px;'>"._('ISO 文件共享')."</span></div>";
		echo "<table class='disk_status wide samba_mounts'><thead><tr><td>"._('共享类型')."</td><td>"._('来源')."</td><td>"._('挂载点')."</td><td></td><td>"._('移除')."</td><td>"._('设置')."</td><td></td><td></td><td></td><td>"._('大小')."</td><td>"._('已使用')."</td><td>"._('空闲')."</td><td>"._('日志')."</td></tr></thead>";
		echo "<tbody>";
		$ds1 = -microtime(true);
		$samba_mounts = get_samba_mounts();
		unassigned_log("get_samba_mounts: ".($ds1 + microtime(true))."s!","DEBUG");
		if (count($samba_mounts)) {
			foreach ($samba_mounts as $mount)
			{
				$is_alive = $mount['is_alive'];
				$mounted = $mount['mounted'];
				echo "<tr>";
				$protocol = $mount['protocol'] == "NFS" ? "nfs" : "smb";
				printf( "<td><i class='fa fa-circle orb %s'></i>%s</td>", ( $is_alive ? "green-orb" : "grey-orb" ), $protocol);
				echo "<td>{$mount['name']}";
				$mount_point = basename($mount['mountpoint']);
				if ($mounted) {
					echo "<td><i class='fa fa-folder-open mount-share'></i><a title='"._("浏览远程 SMB/NFS 共享")."' href='/Main/Browse?dir={$mount['mountpoint']}'>{$mount_point}</a></td>";
				} else {
					echo "<td>
						<i class='fa fa-pencil share'></i>
						<a title='"._("更改远程 SMB/NFS 挂载点")."' class='exec' onclick='chg_samba_mountpoint(\"{$mount['name']}\",\"{$mount_point}\");'>{$mount_point}</a>
						</td>";
				}

				$disabled = $is_alive ? "enabled" : "disabled";
				if ($mount['mounted'] && (is_script_running($mount['command']) || is_script_running($mount['user_command'], TRUE))) {
					echo "<td><button class='mount' disabled> <i class='fa fa-circle-o-notch fa-spin'></i>"." "._("运行中")."</button></td>";
				} else {
					$is_mounting	= array_values(preg_grep("@/mounting_".basename($mount['device'])."@i", listDir(dirname($paths['mounting']))))[0];
					$is_mounting	= (time() - filemtime($is_mounting) < 300) ? TRUE : FALSE;
					$is_unmounting	= array_values(preg_grep("@/unmounting_".basename($mount['device'])."@i", listDir(dirname($paths['unmounting']))))[0];
					$is_unmounting	= (time() - filemtime($is_unmounting) < 300) ? TRUE : FALSE;
					if ($is_mounting) {
						echo "<td><button class='mount' disabled><i class='fa fa-circle-o-notch fa-spin'></i> "._('挂载中')."</button></td>";
					} elseif ($is_unmounting) {
						echo "<td><button class='mount' disabled><i class='fa fa-circle-o-notch fa-spin'></i> "._('卸载中')."</button></td>";
					} else {
						echo "<td>".($mounted ? "<button class='mount' device ='{$mount['device']}' onclick=\"disk_op(this, 'umount','{$mount['device']}');\"><i class='fa fa-export'></i>"._('卸载')."</button>" : "<button class='mount'device ='{$mount['device']}' onclick=\"disk_op(this, 'mount','{$mount['device']}');\" {$disabled}><i class='fa fa-import'></i>"._('挂载')."</button>")."</td>";
					}
				}
				echo $mounted ? "<td><i class='fa fa-remove hdd'></i></td>" : "<td><a class='exec' style='color:#CC0000;font-weight:bold;' onclick='remove_samba_config(\"{$mount['name']}\");' title='"._("移除远程 SMB/NFS 共享")."'> <i class='fa fa-remove hdd'></i></a></td>";

				$title = _("编辑远程 SMB/NFS 设置与脚本").".";
				$title .= "\n"._("自动挂载").": ";
				$title .= ($mount['automount'] == 'yes') ? "On" : "Off";
				$title .= "   "._("共享").": ";
				$title .= ($mount['smb_share'] == 'yes') ? "On" : "Off";

				echo "<td><a title='$title' href='/Main/EditSettings?d=".urlencode($mount['device'])."&l=".urlencode(basename($mount['mountpoint']))."&m=".urlencode(json_encode($mount))."'><i class='fa fa-gears'></i></a></td>";
				echo "<td></td><td></td><td></td>";
				echo "<td>".my_scale($mount['size'], $unit)." $unit</td>";
				echo render_used_and_free($mount, $mounted);

				echo "<td><a title='"._("查看远程 SMB/NFS 脚本日志")."' href='/Main/ScriptLog?d=".urlencode($mount['device'])."&l=".urlencode(basename($mount['mountpoint']))."'><i class='fa fa-align-left".( $mount['command'] ? "":" grey-orb" )."'></i></a></td>";
				echo "</tr>";
			}
		}

		/* ISO file Mounts */
		$iso_mounts = get_iso_mounts();
		if (count($iso_mounts)) {
			foreach ($iso_mounts as $mount) {
				$mounted = $mount['mounted'];
				$is_alive = is_file($mount['file']);
				echo "<tr>";
				printf( "<td><i class='fa fa-circle orb %s'></i>iso</td>", ( $is_alive ? "green-orb" : "grey-orb" ));
				$devname = basename($mount['device']);
				echo "<td>{$mount['device']}</td>";
				$mount_point = basename($mount['mountpoint']);
				if ($mounted) {
					echo "<td><i class='fa fa-folder-open mount-share'></i><span style='margin:0px;'></span><a title='"._("浏览 ISO 文件共享")."' href='/Main/Browse?dir={$mount['mountpoint']}'>{$mount_point}</a></td>";
				} else {
					echo "<td>
						<i class='fa fa-pencil share'></i>
						<a title='"._("更改 ISO 文件挂载点")."' class='exec' onclick='chg_iso_mountpoint(\"{$mount['device']}\",\"{$mount_point}\");'>{$mount_point}</a>
						</td>";
				}
				$disabled = $is_alive ? "enabled":"disabled";
				if ($mount['mounted'] && (is_script_running($mount['command']) || is_script_running($mount['user_command'], TRUE))) {
					echo "<td><button class='mount' disabled> <i class='fa fa-circle-o-notch fa-spin'></i> "._('运行中')."</button></td>";
				} else {
					$is_mounting	= array_values(preg_grep("@/mounting_".basename($mount['device'])."@i", listDir(dirname($paths['mounting']))))[0];
					$is_mounting	= (time() - filemtime($is_mounting) < 300) ? TRUE : FALSE;
					$is_unmounting	= array_values(preg_grep("@/unmounting_".basename($mount['device'])."@i", listDir(dirname($paths['unmounting']))))[0];
					$is_unmounting	= (time() - filemtime($is_unmounting) < 300) ? TRUE : FALSE;
					if ($is_mounting) {
						echo "<td><button class='mount' disabled><i class='fa fa-circle-o-notch fa-spin'></i> "._('挂载中')."</button></td>";
					} elseif ($is_unmounting) {
						echo "<td><button class='mount' disabled><i class='fa fa-circle-o-notch fa-spin'></i> "._('卸载中')."</button></td>";
					} else {
						echo "<td>".($mounted ? "<button class='mount' device='{$mount['device']}' onclick=\"disk_op(this, 'umount','{$mount['device']}');\"><i class='fa fa-export'></i>"._('卸载')."</button>" : "<button class='mount' device='{$mount['device']}' onclick=\"disk_op(this, 'mount','{$mount['device']}');\" {$disabled}><i class='fa fa-import'></i>"._('挂载')."</button>")."</td>";
					}
				}
				echo $mounted ? "<td><i class='fa fa-remove hdd'></i></td>" : "<td><a class='exec' style='color:#CC0000;font-weight:bold;' onclick='remove_iso_config(\"{$mount['device']}\");' title='"._("移除 ISO 文件共享")."'> <i class='fa fa-remove hdd'></i></a></td>";

				$title = _("编辑 ISO 文件设置与脚本").".";
				$title .= "\n"._("自动挂载").": ";
				$title .= ($mount['automount'] == 'yes') ? "On" : "Off";

				echo "<td><a title='$title' href='/Main/EditSettings?i=".urlencode($mount['device'])."&l=".urlencode(basename($mount['mountpoint']))."'><i class='fa fa-gears'></i></a></td>";
				echo "<td></td><td></td><td></td>";
				echo "<td>".my_scale($mount['size'], $unit)." $unit</td>";
				echo render_used_and_free($mount, $mounted);
				echo "<td><a title='"._("查看 ISO 文件脚本日志")."' href='/Main/ScriptLog?i=".urlencode($mount['device'])."&l=".urlencode(basename($mount['mountpoint']))."'><i class='fa fa-align-left".( $mount['command'] ? "":" grey-orb" )."'></i></a></td>";
				echo "</tr>";
			}
		}
		if (! count($samba_mounts) && ! count($iso_mounts)) {
			echo "<tr><td colspan='13' style='text-align:center;'>"._('无已配置的远程 SMB/NFS 或 ISO 文件共享').".</td></tr>";
		}
		echo "</tbody></table>";

		echo "<button onclick='add_samba_share()'>"._('添加远程 SMB/NFS 共享')."</button>";
		echo "<button onclick='add_iso_share()'>"._('添加 ISO 文件共享')."</button></div>";

		$config_file = $GLOBALS["paths"]["config_file"];
		$config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
		$disks_serials = array();
		foreach ($disks as $disk) $disks_serials[] = $disk['partitions'][0]['serial'];
		$ct = "";
		foreach ($config as $serial => $value) {
			if($serial == "Config") continue;
			if (! preg_grep("#{$serial}#", $disks_serials)){
				$mountpoint	= basename(get_config($serial, "mountpoint.1"));
				$ct .= "<tr><td><i class='fa fa-minus-circle orb grey-orb'></i>"._("missing")."</td><td>$serial"." ($mountpoint)</td>";
				$ct .= "<td></td><td></td><td></td><td></td><td></td><td></td>";
				$ct .= "<td><a title='"._("编辑历史设备设置与脚本")."' href='/Main/EditSettings?s=".urlencode($serial)."&l=".urlencode(basename($mountpoint))."&p=".urlencode("1")."&t=TRUE'><i class='fa fa-gears'></i></a></td>";
				$ct .= "<td title='"._("移除设备设置")."'><a style='color:#CC0000;font-weight:bold;cursor:pointer;' onclick='remove_disk_config(\"{$serial}\")'><i class='fa fa-remove hdd'></a></td></tr>";
			}
		}
		if (strlen($ct)) {
			echo "<div class='show-disks'><div class='show-historical' id='smb_tab'><div id='title'><span class='left'><img src='/plugins/{$plugin}/icons/historical.png' class='icon'>"._('历史设备')."</span></div>";
			echo "<table class='disk_status wide usb_absent'><thead><tr><td>"._('设备')."</td><td>"._('序列号 (挂载点)')."</td><td></td><td></td><td></td><td></td><td></td><td></td><td>"._('设置')."</td><td>"._('移除')."</td></tr></thead><tbody>{$ct}</tbody></table></div></div>";
		}
		unassigned_log("Total get_content render time: ".($time + microtime(true))."s", "DEBUG");
		break;

	case 'refresh_page':
		publish("reload", json_encode(array("rescan" => "yes"),JSON_UNESCAPED_SLASHES));
		break;

	case 'update_ping':
		global $paths;

		/* Refresh the ping status in the background. */
		$config_file = $paths['samba_mount'];
		$samba_mounts = @parse_ini_file($config_file, true);
		if (is_array($samba_mounts)) {
			foreach ($samba_mounts as $device => $mount) {
				$tc = $paths['ping_status'];
				$ping_status = is_file($tc) ? json_decode(file_get_contents($tc),TRUE) : array();
				$server = $mount['ip'];
				$changed = ($ping_status[$server]['changed'] == 'yes') ? TRUE : FALSE;
				$mounted = is_mounted($device);
				is_samba_server_online($server, $mounted);
				if ($changed) {
					$no_pings = $ping_status[$server]['no_pings'];
					$online = $ping_status[$server]['online'];
					$ping_status[$server] = array('timestamp' => time(), 'no_pings' => $no_pings, 'online' => $online, 'changed' => 'no');
					file_put_contents($tc, json_encode($ping_status));
					publish("reload", json_encode(array("rescan" => "yes"),JSON_UNESCAPED_SLASHES));
				}
			}
		}
		break;

	case 'get_content_json':
		unassigned_log("Starting json reply action [get_content_json]", "DEBUG");
		$time = -microtime(true);
		$disks = get_all_disks_info();
		echo json_encode($disks);
		unassigned_log("Total get_content_json render time: ".($time + microtime(true))."s", "DEBUG");
		break;

	/*	CONFIG	*/
	case 'automount':
		$serial = urldecode(($_POST['device']));
		$status = urldecode(($_POST['status']));
		echo json_encode(array( 'result' => toggle_automount($serial, $status) ));
		break;

	case 'show_partitions':
		$serial = urldecode(($_POST['serial']));
		$status = urldecode(($_POST['status']));
		echo json_encode(array( 'result' => set_config($serial, "show_partitions", ($status == "true") ? "yes" : "no")));
		break;

	case 'background':
		$device = urldecode(($_POST['device']));
		$part = urldecode(($_POST['part']));
		$status = urldecode(($_POST['status']));
		echo json_encode(array( 'result' => set_config($device, "command_bg.{$part}", $status)));
		break;

	case 'set_command':
		$serial = urldecode(($_POST['serial']));
		$part = urldecode(($_POST['part']));
		$cmd = urldecode(($_POST['command']));
		set_config($serial, "user_command.{$part}", urldecode($_POST['user_command']));
		echo json_encode(array( 'result' => set_config($serial, "command.{$part}", $cmd)));
		break;

	case 'remove_config':
		$serial = urldecode(($_POST['serial']));
		publish("reload", json_encode(array("rescan" => "yes"),JSON_UNESCAPED_SLASHES));
		echo json_encode(remove_config_disk($serial));
		break;

	case 'toggle_share':
		$info = json_decode(html_entity_decode($_POST['info']), true);
		$status = urldecode(($_POST['status']));
		$result = toggle_share($info['serial'], $info['part'],$status);
		if ($result && strlen($info['target']) && $info['mounted']) {
			add_smb_share($info['mountpoint'], $info['label']);
			add_nfs_share($info['mountpoint']);
		} elseif ($info['mounted']) {
			rm_smb_share($info['mountpoint'], $info['label']);
			rm_nfs_share($info['mountpoint']);
		}
		echo json_encode(array( 'result' => $result));
		break;

	case 'toggle_read_only':
		$serial = urldecode(($_POST['serial']));
		$status = urldecode(($_POST['status']));
		echo json_encode(array( 'result' => toggle_read_only($serial, $status) ));
		break;

	case 'toggle_pass_through':
		$serial = urldecode(($_POST['serial']));
		$status = urldecode(($_POST['status']));
		echo json_encode(array( 'result' => toggle_pass_through($serial, $status) ));
		break;

	/*	DISK	*/
	case 'mount':
		$device = urldecode($_POST['device']);
		exec("plugins/{$plugin}/scripts/rc.unassigned mount '$device' &>/dev/null", $out, $return);
		echo json_encode(["status" => $return ? false : true ]);
		break;

	case 'umount':
		$device = urldecode($_POST['device']);
		exec("plugins/{$plugin}/scripts/rc.unassigned umount '$device' &>/dev/null", $out, $return);
		echo json_encode(["status" => $return ? false : true ]);
		break;

	case 'rescan_disks':
		exec("plugins/{$plugin}/scripts/copy_config.sh");
		$tc = $paths['hotplug_status'];
		$hotplug = is_file($tc) ? json_decode(file_get_contents($tc),TRUE) : "no";
		if ($hotplug == "no") {
			file_put_contents($tc, json_encode('yes'));
			publish("reload", json_encode(array("rescan" => "yes"),JSON_UNESCAPED_SLASHES));
		}
		break;

	case 'format_disk':
		$device = urldecode($_POST['device']);
		$fs = urldecode($_POST['fs']);
		$pass = urldecode($_POST['pass']);
		@touch(sprintf($paths['formatting'],basename($device)));
		echo json_encode(array( 'status' => format_disk($device, $fs, $pass)));
		@unlink(sprintf($paths['formatting'],basename($device)));
		break;

	/*	SAMBA	*/
	case 'list_samba_hosts':
		/* $workgroup = urldecode($_POST['workgroup']); */
		$network = $_POST['network'];
		$names = [];
		foreach ($network as $iface)
		{
			$ip = $iface['ip'];
			$netmask = $iface['netmask'];
			exec("plugins/{$plugin}/scripts/port_ping.sh {$ip} {$netmask} 445", $hosts);
			foreach ($hosts as $host) {
				$name=trim(shell_exec("/usr/bin/nmblookup -A '$host' 2>/dev/null | grep -v 'GROUP' | grep -Po '[^<]*(?=<00>)' | head -n 1"));
				$names[]= $name ? $name : $host;
			}
			natsort($names);
		}
		echo implode(PHP_EOL, $names);
		/* exec("/usr/bin/nmblookup --option='disable netbios'='No' '$workgroup' | awk '{print $1}'", $output); */
		/* echo timed_exec(10, "/usr/bin/smbtree --servers --no-pass | grep -v -P '^\w+' | tr -d '\\' | awk '{print $1}' | sort"); */
		break;

	case 'list_samba_shares':
		$ip = urldecode($_POST['IP']);
		$user = isset($_POST['USER']) ? $_POST['USER'] : NULL;
		$pass = isset($_POST['PASS']) ? $_POST['PASS'] : NULL;
		$domain = isset($_POST['DOMAIN']) ? $_POST['DOMAIN'] : NULL;
		file_put_contents("{$paths['authentication']}", "username=".$user."\n");
		file_put_contents("{$paths['authentication']}", "password=".$pass."\n", FILE_APPEND);
		file_put_contents("{$paths['authentication']}", "domain=".$domain."\n", FILE_APPEND);
		$list = shell_exec("/usr/bin/smbclient -t2 -g -L '$ip' --authentication-file='{$paths['authentication']}' 2>/dev/null | /usr/bin/awk -F'|' '/Disk/{print $2}' | sort");
		exec("/bin/shred -u ".$paths['authentication']);
		echo $list;
		break;

	/*	NFS	*/
	case 'list_nfs_hosts':
		$network = $_POST['network'];
		foreach ($network as $iface)
		{
			$ip = $iface['ip'];
			$netmask = $iface['netmask'];
			echo shell_exec("/usr/bin/timeout -s 13 5 plugins/{$plugin}/scripts/port_ping.sh {$ip} {$netmask} 2049 2>/dev/null | sort -n -t . -k 1,1 -k 2,2 -k 3,3 -k 4,4");
		}
		break;

	case 'list_nfs_shares':
		$ip = urldecode($_POST['IP']);
		$rc = timed_exec(10, "/usr/sbin/showmount --no-headers -e '{$ip}' 2>/dev/null | rev | cut -d' ' -f2- | rev | sort");
		echo $rc ? $rc : " ";
		break;

	/* SMB SHARES */
	case 'add_samba_share':
		$rc = TRUE;

		$ip = urldecode($_POST['IP']);
		$ip = implode("",explode("\\", $ip));
		$ip = stripslashes(trim($ip));
		$protocol = urldecode($_POST['PROTOCOL']);
		$user = isset($_POST['USER']) ? urldecode($_POST['USER']) : "";
		$domain = isset($_POST['DOMAIN']) ? urldecode($_POST['DOMAIN']) : "";
		$pass = isset($_POST['PASS']) ? urldecode($_POST['PASS']) : "";
		$path = isset($_POST['SHARE']) ? urldecode($_POST['SHARE']) : "";
		$path = implode("",explode("\\", $path));
		$path = stripslashes(trim($path));
		$share = basename($path);
		if ($share) {
			$device = ($protocol == "NFS") ? "{$ip}:{$path}" : "//".strtoupper($ip)."/{$share}";
			$device = str_replace("$", "", $device);
			set_samba_config("{$device}", "protocol", $protocol);
			set_samba_config("{$device}", "ip", (is_ip($ip) ? $ip : strtoupper($ip)));
			set_samba_config("{$device}", "path", $path);
			set_samba_config("{$device}", "user", $user);
			set_samba_config("{$device}", "domain", $domain);
			set_samba_config("{$device}", "pass", encrypt_data($pass));
			set_samba_config("{$device}", "share", safe_name($share, FALSE));

			/* Refresh the ping status */
			is_samba_server_online($ip, FALSE);
		}
		publish("reload", json_encode(array("rescan" => "yes"),JSON_UNESCAPED_SLASHES));
		echo json_encode($rc);
		break;

	case 'remove_samba_config':
		$device = urldecode(($_POST['device']));
		publish("reload", json_encode(array("rescan" => "yes"),JSON_UNESCAPED_SLASHES));
		echo json_encode(remove_config_samba($device));
		break;

	case 'samba_automount':
		$device = urldecode(($_POST['device']));
		$status = urldecode(($_POST['status']));
		echo json_encode(array( 'result' => toggle_samba_automount($device, $status) ));
		break;

	case 'samba_share':
		$device = urldecode(($_POST['device']));
		$status = urldecode(($_POST['status']));
		echo json_encode(array( 'result' => toggle_samba_share($device, $status) ));
		break;

	case 'toggle_samba_share':
		$info = json_decode(html_entity_decode($_POST['info']), true);
		$status = urldecode(($_POST['status']));
		$result = toggle_samba_share($info['device'], $status);
		if ($result && strlen($info['target']) && $info['mounted']) {
			add_smb_share($info['mountpoint'], $info['device']);
			add_nfs_share($info['mountpoint']);
		} elseif ($info['mounted']) {
			rm_smb_share($info['mountpoint'], $info['device']);
			rm_nfs_share($info['mountpoint']);
		}
		echo json_encode(array( 'result' => $result));
		break;

	case 'samba_background':
		$device = urldecode(($_POST['device']));
		$status = urldecode(($_POST['status']));
		echo json_encode(array( 'result' => set_samba_config($device, "command_bg", $status)));
		break;

	case 'set_samba_command':
		$device = urldecode(($_POST['device']));
		$cmd = urldecode(($_POST['command']));
		set_samba_config($device, "user_command", urldecode($_POST['user_command']));
		echo json_encode(array( 'result' => set_samba_config($device, "command", $cmd)));
		break;

	/* ISO FILE SHARES */
	case 'add_iso_share':
		$rc = TRUE;
		$file = isset($_POST['ISO_FILE']) ? urldecode($_POST['ISO_FILE']) : "";
		$file = implode("",explode("\\", $file));
		$file = stripslashes(trim($file));
		if (is_file($file)) {
			$info = pathinfo($file);
			$share = $info['filename'];
			set_iso_config("{$file}", "file", $file);
			set_iso_config("{$file}", "share", $share);
		} else {
			unassigned_log("ISO File '{$file}' not found.");
			$rc = FALSE;
		}
		publish("reload", json_encode(array("rescan" => "yes"),JSON_UNESCAPED_SLASHES));
		echo json_encode($rc);
		break;

	case 'remove_iso_config':
		$device = urldecode(($_POST['device']));
		publish("reload", json_encode(array("rescan" => "yes"),JSON_UNESCAPED_SLASHES));
		echo json_encode(remove_config_iso($device));
		break;

	case 'iso_automount':
		$device = urldecode(($_POST['device']));
		$status = urldecode(($_POST['status']));
		echo json_encode(array( 'result' => toggle_iso_automount($device, $status) ));
		break;

	case 'iso_background':
		$device = urldecode(($_POST['device']));
		$status = urldecode(($_POST['status']));
		echo json_encode(array( 'result' => set_iso_config($device, "command_bg", $status)));
		break;

	case 'set_iso_command':
		$device = urldecode(($_POST['device']));
		$cmd = urldecode(($_POST['command']));
		echo json_encode(array( 'result' => set_iso_config($device, "command", $cmd)));
		break;

	/*	MISC */
	case 'rm_partition':
		$device = urldecode($_POST['device']);
		$partition = urldecode($_POST['partition']);
		publish("reload", json_encode(array("rescan" => "yes"),JSON_UNESCAPED_SLASHES));
		echo json_encode(remove_partition($device, $partition));
		break;

	case 'spin_down_disk':
		$device = urldecode($_POST['device']);
		echo json_encode(spin_disk(TRUE, $device));
		break;

	case 'spin_up_disk':
		$device = urldecode($_POST['device']);
		echo json_encode(spin_disk(FALSE, $device));
		break;

	case 'chg_mountpoint':
		$serial = urldecode($_POST['serial']);
		$partition = urldecode($_POST['partition']);
		$device	= urldecode($_POST['device']);
		$fstype	= urldecode($_POST['fstype']);
		$mountpoint	= basename(safe_name(urldecode($_POST['mountpoint']), FALSE));
		publish("reload", json_encode(array("rescan" => "yes"),JSON_UNESCAPED_SLASHES));
		echo json_encode(change_mountpoint($serial, $partition, $device, $fstype, $mountpoint));
		break;

	case 'chg_samba_mountpoint':
		$device = urldecode($_POST['device']);
		$mountpoint = basename(safe_name(basename(urldecode($_POST['mountpoint'])), FALSE));
		publish("reload", json_encode(array("rescan" => "yes"),JSON_UNESCAPED_SLASHES));
		echo json_encode(change_samba_mountpoint($device, $mountpoint));
		break;

	case 'chg_iso_mountpoint':
		$device = urldecode($_POST['device']);
		$mountpoint = basename(safe_name(basename(urldecode($_POST['mountpoint'])), FALSE));
		publish("reload", json_encode(array("rescan" => "yes"),JSON_UNESCAPED_SLASHES));
		echo json_encode(change_iso_mountpoint($device, $mountpoint));
		break;
	}
?>
