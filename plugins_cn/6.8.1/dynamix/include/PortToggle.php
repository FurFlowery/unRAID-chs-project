<?PHP
/* Copyright 2016, Bergware International.
 * Copyright 2016, Lime Technology
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
$port = $_POST['port'];
$cmd = strtolower($_POST['cmd']);
exec("ip link set ".escapeshellarg($port)." ".escapeshellarg($cmd));
$pass = $cmd=='up' ? 'UP>' : '';
$wait = 5;
while ($wait > 0) {
  if (exec("ip link show ".escapeshellarg($port)."|grep -om1 'UP>'")==$pass) break;
  sleep(1);
  $wait--;
}
?>
