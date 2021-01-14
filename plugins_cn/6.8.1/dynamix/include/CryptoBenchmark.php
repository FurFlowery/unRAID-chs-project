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

list($luks,$size,$hash,$rng) = explode(',',exec("/usr/sbin/cryptsetup --help|tail -1"));
$luks = str_replace('-plain64','',trim(explode(':',$luks)[1]));
$size = str_replace(' bits','',trim(explode(':',$size)[1]));
$hash = trim(explode(':',$hash)[1]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>性能测试</title>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="robots" content="noindex, nofollow">
<meta http-equiv="Content-Security-Policy" content="block-all-mixed-content">
<link type="text/css" rel="stylesheet" href="<?autov("/webGui/styles/default-fonts.css")?>">
<link type="text/css" rel="stylesheet" href="<?autov("/webGui/styles/default-popup.css")?>">
<link type="text/css" rel="stylesheet" href="<?autov("/webGui/styles/font-awesome.css")?>">
<script src="<?autov("/webGui/javascript/dynamix.js")?>"></script>
<script>
var test = 'sha1,sha256,sha512,ripemd160,whirlpool,aes-cbc:128,serpent-cbc:128,twofish-cbc:128,aes-cbc:256,serpent-cbc:256,twofish-cbc:256,aes-xts:256,serpent-xts:256,twofish-xts:256,aes-xts:512,serpent-xts:512,twofish-xts:512';

function benchmark(index,last){
  if (index > last) return;
  $.get('/webGui/include/update.crypto.php',{index:index,test:test,luks:'<?=$luks?>:<?=$size?>',hash:'<?=$hash?>'},function(data){
    $('pre').append(data);
    benchmark(index+1,last);
  });
}
$(function(){
  benchmark(0,test.split(',').length);
});
</script>
</head>
<body style='margin:20px'>
<pre style='font-family:bitstream;font-size:1.2rem'></pre>
</body>
</html>
