#!/usr/bin/php
<?PHP
###############################################################
#                                                             #
# Community Applications copyright 2015-2021, Andrew Zawadzki #
#                                                             #
###############################################################
$docroot = $docroot ?: @$_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
$_SERVER['DOCUMENT_ROOT'] = "/usr/local/emhttp";

require_once("/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php");

function logger($string) {
  $string = escapeshellarg($string);
  shell_exec("logger -t 'Docker Auto Update' $string");
}

function notify($event,$subject,$description,$message="",$type="normal") {
  $command = '/usr/local/emhttp/plugins/dynamix/scripts/notify -e "'.$event.'" -s "'.$subject.'" -d "'.$description.'" -m "'.$message.'" -i "'.$type.'" -l "/Docker"';
  shell_exec($command);
}

# Check for available docker updates

logger("Community Applications Docker Autoupdate running");
if ( ! is_dir("/var/lib/docker/tmp") ) {
  logger("Docker not running.  Exiting");
  exit;
}
logger("Checking for available updates");
exec("/usr/local/emhttp/plugins/dynamix.docker.manager/scripts/dockerupdate.php check nonotify &> /dev/null");
$settings = json_decode(@file_get_contents("/boot/config/plugins/ca.update.applications/DockerUpdateSettings.json"),true);
if ( ! $settings ) {
  logger("No settings file found");
  exit;
}
$DockerTemplates = new DockerTemplates();
$DockerClient = new DockerClient();

$info = $DockerTemplates->getAllInfo();
# workaround for incorrect caching in dockerMan
$containers = $DockerClient->getDockerContainers();
foreach ($containers as $container) {
	$info[$container['Name']]['running'] = $container['Running'];
	$info[$container['Name']]['repository'] = $container['Image'];
	$info[$container['Name']]['ImageId'] = $container['ImageId'];
	$info[$container['Name']]['Id'] = $container['Id'];
	$info[$container['Name']]['Name'] = $container['Name'];
}

$allContainers = array_keys($info);

$updateAll = $settings['global']['dockerUpdateAll'] == "yes";

foreach($allContainers as $container) {
  if ( ! $info[$container]['updated'] || $info[$container]['updated'] == "false" ) {
    if ( $settings['containers'][$container]['name'] || $updateAll ) {
      $updateList[] = $container;
    } else {
      logger("Found update for $container.  Not set to autoupdate");
    }
  }
}
if ( ! $updateList ) {
  logger("No updates will be installed");
  exit;
}

if ( is_file("/boot/config/plugins/ca.update.applications/scripts/stopping/stop_all") ) {
  logger("Executing custom stop script /boot/config/plugins/ca.update.applications/scripts/stopping/stop_all");
  exec("/bin/bash /boot/config/plugins/ca.update.applications/scripts/stopping/stop_all");
}
foreach ($updateList as $containerScript) {
  if ( is_file("/boot/config/plugins/ca.update.applications/scripts/stopping/$containerScript") ) {
    logger("Executing custom stop script /boot/config/plugins/ca.update.applications/scripts/stopping/$containerScript");
    exec("/bin/bash /boot/config/plugins/ca.update.applications/scripts/stopping/$containerScript");
  }
}
$delayTime = $settings['global']['dockerStopDelay'];
$delayTime = $delayTime ? $delayTime : 10;

foreach ($updateList as $container) {
  if ( $info[$container]['running'] ) {
    logger("Stopping $container");
		exec("docker stop -t $delayTime $container");
  }
}
logger("Installing Updates for ".implode(" ",$updateList));
$_GET['updateContainer'] = true;
$_GET['ct'] = $updateList;
include("/usr/local/emhttp/plugins/dynamix.docker.manager/include/CreateDocker.php");
$containers = $DockerClient->getDockerContainers();
foreach ($updateList as $containerScript) {
  if ($info[$containerScript]['running']) {
    logger("Restarting $containerScript");
		exec("docker start $containerScript");
	}
  if ( is_file("/boot/config/plugins/ca.update.applications/scripts/starting/$containerScript") ) {
    logger("Executing custom start script /boot/config/plugins/ca.update.applications/scripts/starting/$containerScript");
    exec("/bin/bash /boot/config/plugins/ca.update.applications/scripts/starting/$containerScript");
  }
}
if ( is_file("/boot/config/plugins/ca.update.applications/scripts/starting/start_all") ) {
  logger("Executing custom start script /boot/config/plugins/ca.update.applications/scripts/starting/start_all");
  exec("/bin/bash /boot/config/plugins/ca.update.applications/scripts/starting/start_all");
}

if ( $settings['global']['dockerNotify'] == "yes" ) {
  notify("Community Applications","Docker Auto Update",implode(" ",$updateList)." Automatically Updated");
}
logger("Community Applications Docker Autoupdate finished");

?>