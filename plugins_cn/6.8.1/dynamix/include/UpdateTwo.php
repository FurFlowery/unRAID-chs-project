<?PHP
/* Copyright 2005-2018, Lime Technology
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

function scan($line, $text) {
  return stripos($line,$text)!==false;
}

$name = urldecode($_POST['name']);
switch ($_POST['id']) {
case 'vm':
  // update vm
  require_once "$docroot/plugins/dynamix.vm.manager/include/libvirt_helpers.php";
  $file = "/var/tmp/$name.tmp";
  if (!file_exists($file)) {
    $reply = ['error' => "File: '$file' not found"];
    break;
  }
  // read new cpu assignments
  $cpuset = explode(',',file_get_contents($file)); unlink($file);
  $vcpus = count($cpuset);
  // initial cores/threads assignment
  $cores = $vcpus;
  $threads = 1;
  $vendor = exec("grep -Pom1 '^vendor_id\\s+: \\K\\S+' /proc/cpuinfo");
  if ($vendor == 'AuthenticAMD') {
    $ht = 1; // force single threaded for AMD
  } else {
    $ht = exec("lscpu|grep -Po '^Thread\\(s\\) per core:\\s+\\K\\d+'") ?: 1; // fetch hyperthreading
  }
  // adjust for hyperthreading
  if ($vcpus > $ht && $vcpus%$ht===0) {
    $cores /= $ht;
    $threads = $ht;
  }
  $uuid = $lv->domain_get_uuid($lv->get_domain_by_name($name));
  $dom = $lv->domain_get_domain_by_uuid($uuid);
  $auto = $lv->domain_get_autostart($dom)==1;
  $xml = simplexml_load_string($lv->domain_get_xml($dom));
  // update topology and vpcus
  $xml->cpu->topology['cores'] = $cores;
  $xml->cpu->topology['threads'] = $threads;
  $xml->vcpu = $vcpus;
  $pin = []; foreach ($xml->cputune->emulatorpin->attributes() as $key => $value) $pin[$key] = (string)$value;
  unset($xml->cputune);
  $xml->addChild('cputune');
  for ($i = 0; $i < $vcpus; $i++) {
    $vcpu = $xml->cputune->addChild('vcpupin');
    $vcpu['vcpu'] = $i;
    $vcpu['cpuset'] = $cpuset[$i];
  }
  if ($pin) {
    $attr = $xml->cputune->addChild('emulatorpin');
    foreach ($pin as $key => $value) $attr[$key] = $value;
  }
  // stop running vm first?
  $running = $lv->domain_get_state($dom)=='running';
  if ($running) {
    $lv->domain_shutdown($dom);
    for ($n =0; $n < 30; $n++) { // allow up to 30s for VM to shutdown
      sleep(1);
      if ($stopped = $lv->domain_get_state($dom)=='shutoff') break;
    }
  } else $stopped = true;
  if (!$stopped) {
    $reply = ['error' => "Failed to stop '$name'"];
    break;
  }
  $lv->nvram_backup($uuid);
  $lv->domain_undefine($dom);
  $lv->nvram_restore($uuid);
  if (!$lv->domain_define($xml->saveXML())) {
    $reply = ['error' => $lv->get_last_error()];
    break;
  }
  $lv->domain_set_autostart($dom, $auto);
  if ($running && !$lv->domain_start($dom)) {
    $reply = ['error' => $lv->get_last_error()];
  } else {
    $reply = ['success' => $name];
  }
  break;
case 'ct':
  // update docker container
  require_once "$docroot/plugins/dynamix.docker.manager/include/DockerClient.php";
  require_once "$docroot/plugins/dynamix.docker.manager/include/Helpers.php";
  $DockerClient = new DockerClient();
  $DockerTemplates = new DockerTemplates();
  // get available networks
  $subnet = DockerUtil::network(DockerUtil::custom());
  // get full template path
  $xml = $DockerTemplates->getUserTemplate($name);
  list($cmd, $ct, $repository) = xmlToCommand($xml);
  $imageID = $DockerClient->getImageID($repository);
  // pull image
  $container = $DockerClient->getContainerDetails($ct);
  // determine if the container is still running
  if (!empty($container) && !empty($container['State']) && !empty($container['State']['Running'])) {
    // since container was already running, put it back it to a running state after update
    $cmd = str_replace('/docker create ', '/docker run -d ', $cmd);
    // attempt graceful stop of container first
    $DockerClient->stopContainer($ct,30);
  }
  // force kill container if still running after 30 seconds
  $DockerClient->removeContainer($ct);
  execCommand($cmd,false);
  $DockerClient->flushCaches();
  $newImageID = $DockerClient->getImageID($repository);
  // remove old orphan image since it's no longer used by this container
  if ($imageID && $imageID != $newImageID) {
    $DockerClient->removeImage($imageID);
  }
  $reply = ['success' => $name];
  break;
case 'is':
  $cfg  = '/boot/syslinux/syslinux.cfg';
  $syslinux  = file($cfg, FILE_IGNORE_NEW_LINES+FILE_SKIP_EMPTY_LINES);
  $size = count($syslinux);
  $make = false;
  $file = "/var/tmp/$name.tmp";
  $isolcpus = file_get_contents($file);
  if ($isolcpus != '') {
    $numbers = explode(',',$isolcpus);
    sort($numbers,SORT_NUMERIC);
    $isolcpus = $previous = array_shift($numbers);
    $range = false;
    // convert sequential numbers to a range
    foreach ($numbers as $number) {
      if ($number == $previous+1) {
        $range = true;
      } else {
        if ($range) {$isolcpus .= '-'.$previous; $range = false;}
        $isolcpus .= ','.$number;
      }
      $previous = $number;
    }
    if ($range) $isolcpus .= '-'.$previous;
    $isolcpus = "isolcpus=$isolcpus";
  }
  unlink($file);
  $i = 0;
  while ($i < $size) {
    // find sections and exclude safemode
    if (scan($syslinux[$i],'label ') && !scan($syslinux[$i],'safe mode') && !scan($syslinux[$i],'safemode')) {
      $n = $i + 1;
      // find the current requested setting
      while (!scan($syslinux[$n],'label ') && $n < $size) {
        if (scan($syslinux[$n],'append ')) {
          $cmd = preg_split('/\s+/',trim($syslinux[$n]));
          // replace an existing setting
          for ($c = 1; $c < count($cmd); $c++) if (scan($cmd[$c],'isolcpus')) {$make |= ($cmd[$c]!=$isolcpus); $cmd[$c] = $isolcpus; break;}
          // or insert a new setting
          if ($c==count($cmd) && $isolcpus) {array_splice($cmd,-1,0,$isolcpus); $make = true;}
          $syslinux[$n] = '  '.str_replace('  ',' ',implode(' ',$cmd));
        }
        $n++;
      }
      $i = $n - 1;
    }
    $i++;
  }
  if ($make) file_put_contents($cfg, implode("\n",$syslinux)."\n");
  $reply = ['success' => $name];
  break;
}
header('Content-Type: application/json');
die(json_encode($reply));
?>
