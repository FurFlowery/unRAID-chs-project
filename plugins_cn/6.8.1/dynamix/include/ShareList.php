<?PHP
/* Copyright 2005-2019, Lime Technology
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
require_once "$docroot/webGui/include/Helpers.php";

$shares  = parse_ini_file('state/shares.ini',true);
$disks   = parse_ini_file('state/disks.ini',true);
$var     = parse_ini_file('state/var.ini');
$sec     = parse_ini_file('state/sec.ini',true);
$sec_nfs = parse_ini_file('state/sec_nfs.ini',true);
$sec_afp = parse_ini_file('state/sec_afp.ini',true);
$compute = $_GET['compute'];
$path    = $_GET['path'];
$fill    = $_GET['fill'];

$display = [];
$display['scale'] = $_GET['scale'];
$display['number'] = $_GET['number'];

if (!$shares) {
  echo "<tr><td colspan='8' style='text-align:center;padding-top:12px'><i class='fa fa-folder-open-o icon'></i>没有可导出的用户共享</td></tr>";
  exit;
}
// Natural sorting of share names
uksort($shares,'strnatcasecmp');

// Display export settings
function user_share_settings($protocol,$share) {
  if (empty($share)) return;
  if ($protocol!='yes' || $share['export']=='-') return "-";
  if ($share['export']=='e') return ucfirst($share['security']);
  return '<em>'.ucfirst($share['security']).'</em>';
}

function globalInclude($name) {
  global $var;
  return substr($name,0,4)!='disk' || !$var['shareUserInclude'] || strpos("{$var['shareUserInclude']},","$name,")!==false;
}

function shareInclude($name) {
  global $include;
  return !$include || substr($name,0,4)!='disk' || strpos("$include,", "$name,")!==false;
}

// Compute all user shares & check encryption
$crypto = false;
foreach ($shares as $name => $share) {
  if ($compute=='yes') exec("webGui/scripts/share_size ".escapeshellarg($name)." ssz1");
  $crypto |= $share['luksStatus']>0;
}

// global shares include/exclude
$myDisks = array_filter(array_diff(array_keys($disks), explode(',',$var['shareUserExclude'])), 'globalInclude');

// Share size per disk
$ssz1 = [];
if ($fill)
  foreach (glob("state/*.ssz1", GLOB_NOSORT) as $entry) $ssz1[basename($entry, ".ssz1")] = parse_ini_file($entry);
else
  exec("rm -f /var/local/emhttp/*.ssz1");

// Build table
$row = 0;
foreach ($shares as $name => $share) {
  $row++;
  $color = $share['color'];
  switch ($color) {
    case 'green-on' : $orb = 'circle'; $color = 'green'; $help = '所有文件已受保护'; break;
    case 'yellow-on': $orb = 'warning'; $color = 'yellow'; $help = '部分或全部文件未受保护'; break;
  }
  if ($crypto) switch ($share['luksStatus']) {
    case 0: $luks = "<i class='nolock fa fa-lock'></i>"; break;
    case 1: $luks = "<a class='info' onclick='return false'><i class='padlock fa fa-unlock-alt green-text'></i><span>所有文件已加密</span></a>"; break;
    case 2: $luks = "<a class='info' onclick='return false'><i class='padlock fa fa-unlock-alt orange-text'></i><span>部分或全部文件未加密</span></a>"; break;
   default: $luks = "<a class='info' onclick='return false'><i class='padlock fa fa-lock red-text'></i><span>未知的加密状态'</span></a>"; break;
  } else $luks = "";
  echo "<tr>";
  echo "<td><a class='info nohand' onclick='return false'><i class='fa fa-$orb orb $color-orb'></i><span style='left:18px'>$help</span></a>$luks<a href='$path/Share?name=".urlencode($name)."' onclick=\"$.cookie('one','tab1',{path:'/'})\">$name</a></td>";
  echo "<td>{$share['comment']}</td>";
  echo "<td>".user_share_settings($var['shareSMBEnabled'], $sec[$name])."</td>";
  echo "<td>".user_share_settings($var['shareNFSEnabled'], $sec_nfs[$name])."</td>";
  echo "<td>".user_share_settings($var['shareAFPEnabled'], $sec_afp[$name])."</td>";
  $cmd="/webGui/scripts/share_size"."&arg1=".urlencode($name)."&arg2=ssz1";
  $cache = ucfirst($share['useCache']);
  if (array_key_exists($name, $ssz1)) {
    echo "<td>$cache</td>";
    echo "<td>".my_scale($ssz1[$name]['disk.total']*1024, $unit)." $unit</td>";
    echo "<td>".my_scale($share['free']*1024, $unit)." $unit</td>";
    echo "<td><a href='$path/Browse?dir=/mnt/user/".urlencode($name)."'><img src='/webGui/images/explore.png' title='Browse /mnt/user/".urlencode($name)."'></a></td>";
    echo "</tr>";
    foreach ($ssz1[$name] as $diskname => $disksize) {
      if ($diskname=='disk.total') continue;
      $include = $share['include'];
      $inside = in_array($diskname, array_filter(array_diff($myDisks, explode(',',$share['exclude'])), 'shareInclude'));
      echo "<tr class='share_status_size".($inside ? "'>" : " warning'>");
      echo "<td>".my_disk($diskname).":</td>";
      echo "<td>".($inside ? "" : "<em>共享在指定磁盘列表之外</em>")."</td>";
      echo "<td></td>";
      echo "<td></td>";
      echo "<td></td>";
      echo "<td></td>";
      echo "<td class='share-$row-1'>".my_scale($disksize*1024, $unit)." $unit</td>";
      echo "<td class='share-$row-2'>".my_scale($disks[$diskname]['fsFree']*1024, $unit)." $unit</td>";
      echo "<td><a href='/update.htm?cmd=$cmd&csrf_token={$var['csrf_token']}' target='progressFrame' title='重新计算...' onclick='$.cookie(\"ssz\",\"ssz\",{path:\"/\"});$(\".share-$row-1\").html(\"请稍等...\");$(\".share-$row-2\").html(\"\");'><i class='fa fa-refresh icon'></i></a></td>";
      echo "</tr>";
    }
  } else {
    echo "<td>$cache</td>";
    echo "<td><a href='/update.htm?cmd=$cmd&csrf_token={$var['csrf_token']}' target='progressFrame' onclick=\"$.cookie('ssz','ssz',{path:'/'});$(this).text('请稍等...')\">计算...</a></td>";
    echo "<td>".my_scale($share['free']*1024, $unit)." $unit</td>";
    echo "<td><a href='$path/Browse?dir=/mnt/user/".urlencode($name)."'><img src='/webGui/images/explore.png' title='Browse /mnt/user/".urlencode($name)."'></a></td>";
    echo "</tr>";
  }
}
if ($row==0) {
  echo "<tr><td colspan='9' style='text-align:center;padding-top:12px'><i class='fa fa-folder-open-o icon'></i>没有可导出的用户共享</td></tr>";
}
