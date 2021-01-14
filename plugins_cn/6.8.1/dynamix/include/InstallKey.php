<?PHP
/* Copyright 2005-2018, Lime Technology
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

function addLog($line) { echo "<script>addLog('$line');</script>"; }

readfile("$docroot/logging.htm");
$var = parse_ini_file('state/var.ini');

$parsed_url = parse_url($_GET['url']);
if (($parsed_url['host']=="keys.lime-technology.com")||($parsed_url['host']=="lime-technology.com")) {
  addLog("Downloading {$_GET['url']} ... ");
  $key_file = basename($_GET['url']);
  exec("/usr/bin/wget -q -O ".escapeshellarg("/boot/config/$key_file")." ".escapeshellarg($_GET['url']), $output, $return_var);
  if ($return_var === 0) {
    if ($var['mdState'] == "STARTED")
      addLog("<br>正在安装 ... 请停止阵列以完成密钥安装.<br>");
    else
      addLog("<br>已安装 ...<br>");
  }
  else {
    addLog("ERROR ($return_var)<br>");
  }
}
else
  addLog("ERROR, bad or missing key file URL: {$_GET['url']}<br>");
?>
