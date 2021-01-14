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
$notify  = "$docroot/webGui/scripts/notify";

switch ($_POST['cmd']) {
case 'init':
  shell_exec("$notify init");
  break;
case 'smtp-init':
  shell_exec("$notify smtp-init");
  break;
case 'cron-init':
  shell_exec("$notify cron-init");
  break;
case 'add':
  foreach ($_POST as $option => $value) {
    switch ($option) {
    case 'e':
    case 's':
    case 'd':
    case 'i':
    case 'm':
      $notify .= " -{$option} \"{$value}\"";
      break;
    case 'x':
    case 't':
      $notify .= " -{$option}";
      break;
    }
  }
  shell_exec("$notify add");
  break;
case 'get':
  echo shell_exec("$notify get");
  break;
case 'archive':
  shell_exec("$notify archive ".escapeshellarg($_POST['file']));
  break;
}
?>
