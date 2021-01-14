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
$port = $_POST['port'] ?: 'eth0';
if (exec("ip link show ".escapeshellarg($port)."|grep -om1 'NO-CARRIER'")) {
  echo "<b>接口 ".str_replace('eth', 'Ethernet Port ', $port)." 未连接. 请检查检查电缆!</b>";
}
?>
