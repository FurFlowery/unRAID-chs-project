<?PHP
/* Copyright 2005-2018, Lime Technology
 * Copyright 2012-2018, Bergware International.
 * Copyright 2012, Andrew Hamer-Adams, http://www.pixeleyes.co.nz.
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

$var = parse_ini_file('state/var.ini');

function dmidecode($key,$n,$all=true) {
  $entries = array_filter(explode($key,shell_exec("dmidecode -qt$n")));
  $properties = [];
  foreach ($entries as $entry) {
    $property = [];
    foreach (explode("\n",$entry) as $line) if (strpos($line,': ')!==false) {
      list($key,$value) = explode(': ',trim($line));
      $property[$key] = $value;
    }
    $properties[] = $property;
  }
  return $all ? $properties : $properties[0];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta name="robots" content="noindex, nofollow">
<meta http-equiv="Content-Security-Policy" content="block-all-mixed-content">
<link type="text/css" rel="stylesheet" href="<?autov("/webGui/styles/default-fonts.css")?>">
<link type="text/css" rel="stylesheet" href="<?autov("/webGui/styles/default-popup.css")?>">
<style>
span.key{width:92px;display:inline-block;font-weight:bold}
span.key.link{text-decoration:underline;cursor:pointer}
div.box{margin-top:8px;line-height:30px;margin-left:40px}
div.dimm_info{margin-left:96px}
div.closed{display:none}
</style>
<script>
// server uptime & update period
var uptime = <?=strtok(exec("cat /proc/uptime"),' ')?>;

function add(value, label, last) {
  return parseInt(value)+' '+label+(parseInt(value)!=1?'s':'')+(!last?', ':'');
}
function two(value, last) {
  return (parseInt(value)>9?'':'0')+parseInt(value)+(!last?':':'');
}
function updateTime() {
  document.getElementById('uptime').innerHTML = add(uptime/86400,'day')+two(uptime/3600%24)+two(uptime/60%60)+two(uptime%60,true);
  uptime++;
  setTimeout(updateTime, 1000);
}
</script>
</head>
<body onLoad="updateTime()">
<div class="box">
<div><span class="key">型号:</span>
<?
echo empty($var['SYS_MODEL']) ? 'N/A' : "{$var['SYS_MODEL']}";
?>
</div>
<div><span class="key">M/B:</span>
<?
$board = dmidecode('Base Board Information','2',0);
echo "{$board['Manufacturer']} {$board['Product Name']} 版本 {$board['Version']} - 序列号: {$board['Serial Number']}";
?>
</div>
<div><span class="key">BIOS:</span>
<?
$bios = dmidecode('BIOS Information','0',0);
echo "{$bios['Vendor']} 版本 {$bios['Version']}. 日期: {$bios['Release Date']}";
?>
</div>
<div><span class="key">CPU:</span>
<?
$cpu = dmidecode('Processor Information','4',0);
$cpumodel = str_ireplace(["Processor","(C)","(R)","(TM)"],["","&#169;","&#174;","&#8482;"],$cpu['Version']);
echo $cpumodel.(strpos($cpumodel,'@')!==false ? "" : " @ {$cpu['Current Speed']}");
?>
</div>
<div><span class="key">硬件虚拟化:</span>
<?
// Check for Intel VT-x (vmx) or AMD-V (svm) cpu virtualization support
// If either kvm_intel or kvm_amd are loaded then Intel VT-x (vmx) or AMD-V (svm) cpu virtualization support was found
$strLoadedModules = shell_exec("/etc/rc.d/rc.libvirt test");

// Check for Intel VT-x (vmx) or AMD-V (svm) cpu virtualization support
$strCPUInfo = file_get_contents('/proc/cpuinfo');

if (!empty($strLoadedModules)) {
  // Yah! CPU and motherboard supported and enabled in BIOS
  echo "启用";
} else {
  echo '<a href="http://lime-technology.com/wiki/index.php/UnRAID_Manual_6#Determining_HVM.2FIOMMU_Hardware_Support" target="_blank">';
  if (strpos($strCPUInfo,'vmx')===false && strpos($strCPUInfo, 'svm')===false) {
    // CPU doesn't support virtualization
    echo "不可用";
  } else {
    // Motherboard either doesn't support virtualization or BIOS has it disabled
    echo "禁用";
  }
  echo '</a>';
}
?>
</div>
<div><span class="key">IOMMU:</span>
<?
// Check for any IOMMU Groups
$iommu_groups = shell_exec("find /sys/kernel/iommu_groups/ -type l");

if (!empty($iommu_groups)) {
  // Yah! CPU and motherboard supported and enabled in BIOS
  echo "启用";
} else {
  echo '<a href="http://lime-technology.com/wiki/index.php/UnRAID_Manual_6#Determining_HVM.2FIOMMU_Hardware_Support" target="_blank">';
  if (strpos($strCPUInfo,'vmx')===false && strpos($strCPUInfo, 'svm')===false) {
    // CPU doesn't support virtualization so iommu would be impossible
    echo "不可用";
  } else {
    // Motherboard either doesn't support iommu or BIOS has it disabled
    echo "禁用";
  }
  echo '</a>';
}
?>
</div>
<div><span class="key">缓存:</span>
<?
$cache_installed = [];
$cache_devices = dmidecode('Cache Information','7');
foreach ($cache_devices as $device) $cache_installed[] = str_replace('kB','KiB',$device['Installed Size']);
echo implode(', ',$cache_installed);
?>
</div>
<div><span class="key link" onclick="document.getElementsByClassName('dimm_info')[0].classList.toggle('closed')">内存:</span>
<?
/*
 Memory Device (16) will get us each ram chip. By matching on MB it'll filter out Flash/Bios chips
 Sum up all the Memory Devices to get the amount of system memory installed. Convert MB to GB
 Physical Memory Array (16) usually one of these for a desktop-class motherboard but higher-end xeon motherboards
 might have two or more of these.  The trick is to filter out any Flash/Bios types by matching on GB
 Sum up all the Physical Memory Arrays to get the motherboard's total memory capacity
 Extract error correction type, if none, do not include additional information in the output
 If maximum < installed then roundup maximum to the next power of 2 size of installed. E.g. 6 -> 8 or 12 -> 16
*/
$sizes = ['MB','GB','TB'];
$memory_type = $ecc = '';
$memory_installed = $memory_maximum = 0;
$memory_devices = dmidecode('Memory Device','17');
foreach ($memory_devices as $device) {
  if ($device['Type']=='Unknown') continue;
  list($size, $unit) = explode(' ',$device['Size']);
  $base = array_search($unit,$sizes);
  if ($base!==false) $memory_installed += $size*pow(1024,$base);
  if (!$memory_type) $memory_type = $device['Type'];
}
$memory_array = dmidecode('Physical Memory Array','16');
foreach ($memory_array as $device) {
  list($size, $unit) = explode(' ',$device['Maximum Capacity']);
  $base = array_search($unit,$sizes);
  if ($base>=1) $memory_maximum += $size*pow(1024,$base);
  if (!$ecc && $device['Error Correction Type']!='None') $ecc = "{$device['Error Correction Type']} ";
}
if ($memory_installed >= 1024) {
  $memory_installed = round($memory_installed/1024);
  $memory_maximum = round($memory_maximum/1024);
  $unit = 'GiB';
} else $unit = 'MiB';

// If maximum < installed then roundup maximum to the next power of 2 size of installed. E.g. 6 -> 8 or 12 -> 16
$low = $memory_maximum < $memory_installed;
if ($low) $memory_maximum = pow(2,ceil(log($memory_installed)/log(2)));
echo "$memory_installed $unit $memory_type $ecc(最大支持容量 $memory_maximum $unit".($low?'*':'').")";
?>
<div class="dimm_info closed">
<?
foreach ($memory_devices as $device) {
  if ($device['Type']=='Unknown') continue;
  $size = preg_replace('/( .)B$/','$1iB',$device['Size']);
  echo "<div>{$device['Manufacturer']} {$device['Part Number']}, {$size} {$device['Type']} @ {$device['Configured Memory Speed']}</div>";
}
?>
</div>
</div>
<div><span class="key">网络:</span>
<?
exec("ls /sys/class/net|grep -Po '^(bond|eth)\d+$'",$sPorts);
$i = 0;
foreach ($sPorts as $port) {
  $int = "/sys/class/net/$port";
  $mtu = file_get_contents("$int/mtu");
  $link = file_get_contents("$int/carrier")==1;
  if ($i++) echo "<br><span class='key'></span>&nbsp;";
  if (substr($port,0,4)=='bond') {
    if ($link) {
      $bond_mode = str_replace('Bonding Mode: ','',file("/proc/net/bonding/$port",FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)[1]);
      echo "$port: $bond_mode, mtu $mtu";
    } else echo "$port: bond down";
  } else {
    if ($link) {
      $speed = file_get_contents("$int/speed");
      $duplex = file_get_contents("$int/duplex");
      echo "$port: $speed Mbps, $duplex duplex, mtu $mtu";
    } else echo "$port: 接口未连接";
  }
}
?>
</div>
<div><span class="key">内核:</span>
<?$kernel = exec("uname -srm");
  echo $kernel;
?></div>
<div><span class="key">OpenSSL:</span>
<?$openssl_ver = exec("openssl version|cut -d' ' -f2");
  echo $openssl_ver;
?></div>
<div><span class="key">运行时间:</span>&nbsp;<span id="uptime"></span></div>
<div style="margin-top:24px;margin-bottom:12px"><span class="key"></span>
<input type="button" value="关闭" onclick="top.Shadowbox.close()">
<?if ($_GET['more']):?>
<a href="<?=htmlspecialchars($_GET['more'])?>" class="button" style="text-decoration:none" target="_parent">更多</a>
<?endif;?>
</div></div>
</body>
</html>
