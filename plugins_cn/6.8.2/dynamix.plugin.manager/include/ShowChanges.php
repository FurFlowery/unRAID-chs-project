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
require_once "$docroot/webGui/include/Markdown.php";
require_once "$docroot/webGui/include/Helpers.php";
?>
<!DOCTYPE HTML>
<html lang="en">
<head>
<meta name="robots" content="noindex, nofollow">
<meta http-equiv="Content-Security-Policy" content="block-all-mixed-content">
<link type="text/css" rel="stylesheet" href="<?autov("/webGui/styles/default-fonts.css")?>">
<link type="text/css" rel="stylesheet" href="<?autov("/webGui/styles/default-popup.css")?>">
</head>
<body style="margin:14px 10px">
<?
$file = $_GET['file'];
$tmp = $_GET['tmp'] ? '/var/tmp' : '/tmp/plugins/';

if (file_exists($file) && strpos(realpath($file),$tmp)===0 && substr($file,-4)=='.txt') echo Markdown(file_get_contents($file)); else echo Markdown("*没有发行说明!*");
?>
<br><div style="text-align:center"><input type="button" value="完成" onclick="top.Shadowbox.close()"></div>
</body>
</html>
