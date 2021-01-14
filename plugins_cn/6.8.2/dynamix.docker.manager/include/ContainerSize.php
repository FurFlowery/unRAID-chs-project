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
require_once "$docroot/webGui/include/Helpers.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>容器大小</title>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="robots" content="noindex, nofollow">
<meta http-equiv="Content-Security-Policy" content="block-all-mixed-content">
<link type="text/css" rel="stylesheet" href="<?autov("/webGui/styles/default-fonts.css")?>">
<link type="text/css" rel="stylesheet" href="<?autov("/webGui/styles/default-popup.css")?>">
<link type="text/css" rel="stylesheet" href="<?autov("/webGui/styles/font-awesome.css")?>">
</head>
<style>
div.button{width:100%;text-align:center;display:none}
div.spinner{margin:0 auto;text-align:center}
div.spinner .unraid_mark{height:64px}
div.spinner .unraid_mark_2,div .unraid_mark_4{animation:mark_2 1.5s ease infinite}
div.spinner .unraid_mark_3{animation:mark_3 1.5s ease infinite}
div.spinner .unraid_mark_6,div .unraid_mark_8{animation:mark_6 1.5s ease infinite}
div.spinner .unraid_mark_7{animation:mark_7 1.5s ease infinite}
@keyframes mark_2{50% {transform:translateY(-40px)} 100% {transform:translateY(0px)}}
@keyframes mark_3{50% {transform:translateY(-62px)} 100% {transform:translateY(0px)}}
@keyframes mark_6{50% {transform:translateY(40px)} 100% {transform:translateY(0px)}}
@keyframes mark_7{50% {transform:translateY(62px)} 100% {transform: translateY(0px)}}
pre{font-family:bitstream;font-size:1.3rem}
</style>
<script type="text/javascript" src="<?autov('/webGui/javascript/dynamix.js')?>"></script>
<script>
$(function(){
  $('div.spinner').html('<?readfile("$docroot/webGui/images/animated-logo.svg")?>');
  $.get('/plugins/dynamix.docker.manager/include/GetContainerSize.php',function(data){
    $('div.spinner').hide();
    $('#data').text(data);
    $('div.button').show();
  });
});
</script>
<body style='margin:20px'>
<div class="spinner"></div>
<pre id="data"></pre>
<div class="button"><input type="button" value="完成" onclick="top.Shadowbox.close()"></div>
</body>
</html>
