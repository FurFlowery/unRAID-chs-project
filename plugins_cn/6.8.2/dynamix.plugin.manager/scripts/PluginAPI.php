<?PHP
/* Copyright 2019, Lime Technology
 * Copyright 2019, Andrew Zawadzki.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */

$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
require_once "$docroot/plugins/dynamix.plugin.manager/include/PluginHelpers.php";

function download_url($url, $path = "") {
	$ch = curl_init();
	curl_setopt($ch,CURLOPT_URL,$url);
	curl_setopt($ch,CURLOPT_FRESH_CONNECT,true);
	curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
	curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,15);
	curl_setopt($ch,CURLOPT_TIMEOUT,45);
	curl_setopt($ch,CURLOPT_ENCODING,"");
	curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	$out = curl_exec($ch);
	curl_close($ch);
	if ( $path )
		file_put_contents($path,$out);

	return $out ?: false;
}

$options = $_POST['options'];
$plugin = $options['plugin'];

if ( ! $plugin || ! file_exists("/var/log/plugins/$plugin") ) {
	echo json_encode(array("updateAvailable"=>false));
	return;
}

exec("mkdir -p /tmp/plugins");
@unlink("/tmp/plugins/$plugin");
$url = @plugin("pluginURL","/boot/config/plugins/$plugin");
download_url($url,"/tmp/plugins/$plugin");

$changes = @plugin("changes","/tmp/plugins/$plugin");
$version = @plugin("version","/tmp/plugins/$plugin");
$installedVersion = @plugin("version","/boot/config/plugins/$plugin");
$min = @plugin("min","/tmp/plugins/$plugin") ?: "6.4.0";
if ( $changes ) {
	file_put_contents("/tmp/plugins/".pathinfo($plugin, PATHINFO_FILENAME).".txt",$changes);
} else {
	@unlink("/tmp/plugins/".pathinfo($plugin, PATHINFO_FILENAME).".txt");
}

$update = false;
if ( strcmp($version,$installedVersion) > 0 ) {
	$unraid = parse_ini_file("/etc/unraid-version");
	$update = (version_compare($min,$unraid['version'],">")) ? false : true;
}

echo json_encode(array("updateAvailable" => $update,"version" => $version,"min"=>$min,"changes"=>$changes,"installedVersion"=>$installedVersion));

?>
