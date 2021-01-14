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
$unit = ['B','kB','MB','GB','TB','PB','EB','ZB','YB'];
$list = [];

function autoscale($value) {
  global $unit;
  $size = count($unit);
  $base = $value ? floor(log($value, 1000)) : 0;
  if ($base>=$size) $base = $size-1;
  $value /= pow(1000, $base);
  $decimals = $base ? ($value>=100 ? 0 : ($value>=10 ? 1 : (round($value*100)%100===0 ? 0 : 2))) : 0;
  return number_format($value, $decimals, '.', $value>9999 ? ',' : '').$unit[$base];
}

function gap($text) {
  return preg_replace('/([kMGTPEZY]?B)$/'," $1",$text);
}

function align($text, $w=13) {
  if ($w>0) $text = gap($text);
  return sprintf("%{$w}s",$text);
}

exec("docker ps -sa --format='{{.Names}}|{{.Size}}'",$container);
echo align('Name',-30).align('Container').align('Writable').align('Log')."\n";
echo str_repeat('-',69)."\n";
foreach ($container as $ct) {
  list($name,$size) = explode('|',$ct);
  list($writable,$dummy,$total) = explode(' ',str_replace(['(',')'],'',$size));
  list($value,$base) = explode(' ',gap($total));
  $list[] = ['name' => $name, 'total' => $value*pow(1000,array_search($base,$unit)), 'writable' => $writable, 'log' => (exec("docker inspect --format='{{.LogPath}}' $name|xargs du -b 2>/dev/null |cut -f1")) ?: "0"];
}
array_multisort(array_column($list,'total'),SORT_DESC,$list); // sort on container size
foreach ($list as $ct) echo align($ct['name'],-30).align(autoscale($ct['total'])).align($ct['writable']).align(autoscale($ct['log']))."\n";
?>
