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
require_once "$docroot/webGui/include/ColorCoding.php";

$logs = glob($_POST['log'].'*',GLOB_NOSORT);
usort($logs, create_function('$a,$b', 'return filemtime($a)-filemtime($b);'));
foreach ($logs as $log) {
  foreach (file($log) as $line) {
    $span = "span class='text'";
    foreach ($match as $type) foreach ($type['text'] as $text) if (preg_match("/$text/i",$line)) {$span = "span class='{$type['class']}'"; break 2;}
    echo "<$span>".htmlspecialchars($line)."</span>";
  }
}
?>
