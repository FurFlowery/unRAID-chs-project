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
require_once "$docroot/webGui/include/Markdown.php";

function get_ini_key($key,$default) {
  $x = strpos($key, '[');
  $var = $x>0 ? substr($key,1,$x-1) : substr($key,1);
  global $$var;
  eval("\$var=$key;");
  return $var ? $var : $default;
}

function get_file_key($file,$default) {
  list($key, $default) = explode('=',$default,2);
  $var = @parse_ini_file($file);
  return isset($var[$key]) ? $var[$key] : $default;
}

function build_pages($pattern) {
  global $site;
  foreach (glob($pattern, GLOB_NOSORT) as $entry) {
    list($header, $content) = explode("---\n", file_get_contents($entry), 2);
    $page = parse_ini_string($header);
    $page['file'] = $entry;
    $page['root'] = dirname($entry);
    $page['name'] = basename($entry, '.page');
    $page['text'] = $content;
    $site[$page['name']] = $page;
  }
}

function find_pages($item) {
  global $site,$var,$disks,$devs,$users,$shares,$sec,$sec_nfs,$sec_afp,$name,$display;
  $pages = [];
  foreach ($site as $page) {
    if (empty($page['Menu'])) continue;
    $menu = strtok($page['Menu'], ' ');
    switch ($menu[0]) {
      case '$': $menu = get_ini_key($menu,strtok(' ')); break;
      case '/': $menu = get_file_key($menu,strtok(' ')); break;
    }
    while ($menu !== false) {
      list($menu,$rank) = explode(':',$menu);
      if ($menu == $item) {
        $enabled = true;
        if (isset($page['Cond'])) eval("\$enabled={$page['Cond']};");
        if ($enabled) $pages["$rank{$page['name']}"] = $page;
        break;
      }
      $menu = strtok(' ');
    }
  }
  ksort($pages,SORT_NATURAL);
  return $pages;
}

function tab_title($name,$path,$tag) {
  global $docroot;
  if (!$tag || substr($tag,-4)=='.png') {
    $file = "$path/icons/".($tag ?: strtolower(str_replace(' ','',$name)).".png");
    if (file_exists("$docroot/$file")) {
      return "<img src='/$file' class='icon'>".htmlspecialchars(my_disk($name));
    } else {
      return "<i class='fa fa-th title'></i>".htmlspecialchars(my_disk($name));
    }
  } elseif (substr($tag,0,5)=='icon-') {
    return "<i class='$tag title'></i>".htmlspecialchars(my_disk($name));
  } else {
    if (substr($tag,0,3)!='fa-') $tag = "fa-$tag";
    return "<i class='fa $tag title'></i>".htmlspecialchars(my_disk($name));
  }
}

// hack to embed function output in a quoted string (e.g., in a page Title)
// see: http://stackoverflow.com/questions/6219972/why-embedding-functions-inside-of-strings-is-different-than-variables
function _func($x) { return $x; }
$func = '_func';
?>
