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

function PsExecute($command, $timeout = 20, $sleep = 2) {
  exec($command.'>/dev/null & echo $!',$op);
  $pid = (int)$op[0];
  $timer = 0;
  while ($timer<$timeout) {
    sleep($sleep);
    $timer += $sleep;
    if (PsEnded($pid)) return true;
  }
  PsKill($pid);
  return false;
}
function PsEnded($pid) {
  exec("ps -eo pid|grep $pid",$output);
  foreach ($output as $list) if (trim($list)==$pid) return false;
  return true;
}
function PsKill($pid) {
  exec("kill -9 $pid");
}
if (PsExecute("$docroot/webGui/scripts/notify -s 'Unraid SMTP Test' -d 'Test message received!' -i 'alert' -t")) {
  $result = exec("tail -3 /var/log/syslog|awk '/sSMTP/ {getline;print}'|cut -d']' -f2|cut -d'(' -f1");
  $color = strpos($result, 'Sent mail') ? 'green' : 'red';
  echo "测试结果<span class='$color'>$result</span>";
} else {
  echo "测试结果<span class='red'>: 邮件服务器没有回复</span>";
}
?>
