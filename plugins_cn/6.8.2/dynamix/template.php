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
// Define root path
$docroot = $_SERVER['DOCUMENT_ROOT'];

require_once "$docroot/webGui/include/Helpers.php";
require_once "$docroot/webGui/include/PageBuilder.php";

// Get the webGui configuration preferences
extract(parse_plugin_cfg('dynamix',true));

// Read emhttp status
$var     = (array)parse_ini_file('state/var.ini');
$sec     = (array)parse_ini_file('state/sec.ini',true);
$devs    = (array)parse_ini_file('state/devs.ini',true);
$disks   = (array)parse_ini_file('state/disks.ini',true);
$users   = (array)parse_ini_file('state/users.ini',true);
$shares  = (array)parse_ini_file('state/shares.ini',true);
$sec_nfs = (array)parse_ini_file('state/sec_nfs.ini',true);
$sec_afp = (array)parse_ini_file('state/sec_afp.ini',true);

// Read network settings
extract(parse_ini_file('state/network.ini',true));

// Merge SMART settings
require_once "$docroot/webGui/include/CustomMerge.php";

// Build webGui pages first, then plugins pages
$site = [];
build_pages('webGui/*.page');
foreach (glob('plugins/*', GLOB_ONLYDIR) as $plugin) {
  if ($plugin != 'plugins/dynamix') build_pages("$plugin/*.page");
}

// get variables
$name = $_GET['name'];
$dir = $_GET['dir'];
$path = substr(explode('?', $_SERVER['REQUEST_URI'])[0], 1);

// The current "task" is the first element of the path
$task = strtok($path, '/');

// Here's the page we're rendering
$myPage = $site[basename($path)];
$pageroot = $docroot.'/'.dirname($myPage['file']);
$update = true; // set for legacy

// Giddyup
require_once "$docroot/webGui/include/DefaultPageLayout.php";
?>
