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

$user_prefs = '/boot/config/plugins/dynamix.vm.manager/userprefs.cfg';
$vms = $lv->get_domains();
if (empty($vms)) {
  echo '<tr><td colspan="8" style="text-align:center;padding-top:12px">未添加虚拟机</td></tr>';
  return;
}
if (file_exists($user_prefs)) {
  $prefs = parse_ini_file($user_prefs); $sort = [];
  foreach ($vms as $vm) $sort[] = array_search($vm,$prefs) ?? 999;
  array_multisort($sort,SORT_NUMERIC,$vms);
} else {
  natcasesort($vms);
}
$i = 0;
$menu = [];
$kvm = ['var kvm=[];'];
$show = explode(',',$_GET['show']) ?? [];

foreach ($vms as $vm) {
  $res = $lv->get_domain_by_name($vm);
  $desc = $lv->domain_get_description($res);
  $uuid = $lv->domain_get_uuid($res);
  $dom = $lv->domain_get_info($res);
  $id = $lv->domain_get_id($res) ?: '-';
  $is_autostart = $lv->domain_get_autostart($res);
  $state = $lv->domain_state_translate($dom['state']);
  $icon = $lv->domain_get_icon_url($res);
  $image = substr($icon,-4)=='.png' ? "<img src='$icon' class='img'>" : (substr($icon,0,5)=='icon-' ? "<i class='$icon img'></i>" : "<i class='fa fa-$icon img'></i>");
  $arrConfig = domain_to_config($uuid);
  if ($state == 'running') {
    $mem = $dom['memory'] / 1024;
  } else {
    $mem = $lv->domain_get_memory($res) / 1024;
  }
  $mem = round($mem).'M';
  $vcpu = $dom['nrVirtCpu'];
  $auto = $is_autostart ? 'checked':'';
  $template = $lv->_get_single_xpath_result($res, '//domain/metadata/*[local-name()=\'vmtemplate\']/@name');
  if (empty($template)) $template = 'Custom';
  $log = (is_file("/var/log/libvirt/qemu/$vm.log") ? "libvirt/qemu/$vm.log" : '');
  $disks = '-';
  $diskdesc = '';
  if (($diskcnt = $lv->get_disk_count($res)) > 0) {
    $disks = $diskcnt.' / '.$lv->get_disk_capacity($res);
    $diskdesc = '当前物理大小: '.$lv->get_disk_capacity($res, true);
  }
  $arrValidDiskBuses = getValidDiskBuses();
  $vncport = $lv->domain_get_vnc_port($res);
  $vnc = '';
  $graphics = '';
  if ($vncport > 0) {
    $wsport = $lv->domain_get_ws_port($res);
    $vnc = '/plugins/dynamix.vm.manager/vnc.html?autoconnect=true&host=' . $_SERVER['HTTP_HOST'] . '&port=&path=/wsproxy/' . $wsport . '/';
    $graphics = 'VNC:'.$vncport;
  } elseif ($vncport == -1) {
    $graphics = 'VNC:自动';
  } elseif (!empty($arrConfig['gpu'])) {
    $arrValidGPUDevices = getValidGPUDevices();
    foreach ($arrConfig['gpu'] as $arrGPU) foreach ($arrValidGPUDevices as $arrDev) {
      if ($arrGPU['id'] == $arrDev['id']) $graphics .= $arrDev['name']."\n";
    }
    $graphics = str_replace("\n", "<br>", trim($graphics));
  }
  unset($dom);
  $menu[] = sprintf("addVMContext('%s','%s','%s','%s','%s','%s');", addslashes($vm),addslashes($uuid),addslashes($template),$state,addslashes($vnc),addslashes($log));
  $kvm[] = "kvm.push({id:'$uuid',state:'$state'});";
  switch ($state) {
  case 'running':
    $shape = 'play';
    $status = '已启动';
    $color = 'green-text';
    break;
  case 'paused':
  case 'pmsuspended':
    $shape = 'pause';
    $status = '已暂停';
    $color = 'orange-text';
    break;
  default:
    $shape = 'square';
    $status = '已停止';
    $color = 'red-text';
    break;
  }

  /* VM information */
  echo "<tr parent-id='$i' class='sortable'><td class='vm-name' style='width:220px;padding:8px'>";
  echo "<span class='outer'><span id='vm-$uuid' class='hand'>$image</span><span class='inner'><a href='#' onclick='return toggle_id(\"name-$i\")' title='单击以获取更多虚拟机信息'>$vm</a><br><i class='fa fa-$shape $status $color'></i><span class='state'>$status</span></span></span></td>";
  echo "<td>$desc</td>";
  echo "<td><a class='vcpu-$uuid' style='cursor:pointer'>$vcpu</a></td>";
  echo "<td>$mem</td>";
  echo "<td title='$diskdesc'>$disks</td>";
  echo "<td>$graphics</td>";
  echo "<td><input class='autostart' type='checkbox' name='auto_{$vm}' title='开关虚拟机自动启动' uuid='$uuid' $auto></td></tr>";

  /* Disk device information */
  echo "<tr child-id='$i' id='name-$i".(in_array('name-'.$i++,$show) ? "'>" : "' style='display:none'>");
  echo "<td colspan='7' style='overflow:hidden'>";
  echo "<table class='tablesorter domdisk' id='domdisk_table'>";
  echo "<thead><tr><th><i class='fa fa-hdd-o'></i> <b>磁盘驱动器</b></th><th>总线</th><th>总容量</th><th>已分配</th></tr></thead>";
  echo "<tbody id='domdisk_list'>";

  /* Display VM disks */
  foreach ($lv->get_disk_stats($res) as $arrDisk) {
    $capacity = $lv->format_size($arrDisk['capacity'], 0);
    $allocation = $lv->format_size($arrDisk['allocation'], 0);
    $disk = $arrDisk['file'] ?? $arrDisk['partition'];
    $dev = $arrDisk['device'];
    $bus = $arrValidDiskBuses[$arrDisk['bus']] ?? 'VirtIO';
    echo "<tr><td>$disk</td><td>$bus</td>";
    if ($state == 'shutoff') {
      echo "<td title='单击以增加磁盘大小'>";
      echo "<form method='get' action=''>";
      echo "<input type='hidden' name='subaction' value='disk-resize'>";
      echo "<input type='hidden' name='uuid' value='".$uuid."'>";
      echo "<input type='hidden' name='disk' value='".htmlspecialchars($disk)."'>";
      echo "<input type='hidden' name='oldcap' value='".$capacity."'>";
      echo "<span class='diskresize' style='width:30px'>";
      echo "<span class='text'><a href='#' onclick='return false'>$capacity</a></span>";
      echo "<input class='input' type='text' style='width:46px' name='cap' value='$capacity' val='diskresize' hidden>";
      echo "</span></form></td>";
    } else {
      echo "<td>$capacity</td>";
    }
    echo "<td>$allocation</td></tr>";
  }

  /* Display VM cdroms */
  foreach ($lv->get_cdrom_stats($res) as $arrCD) {
    $capacity = $lv->format_size($arrCD['capacity'], 0);
    $allocation = $lv->format_size($arrCD['allocation'], 0);
    $disk = $arrCD['file'] ?? $arrCD['partition'];
    $dev = $arrCD['device'];
    $bus = $arrValidDiskBuses[$arrCD['bus']] ?? 'VirtIO';
    echo "<tr><td>$disk</td><td>$bus</td><td>$capacity</td><td>$allocation</td></tr>";
  }

  echo "</tbody></table>";
  echo "</td></tr>";
}
echo "\0".implode($menu).implode($kvm);
?>