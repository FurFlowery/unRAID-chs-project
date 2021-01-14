<?PHP

###############################################################
#                                                             #
# Community Applications copyright 2015-2016, Andrew Zawadzki #
#                                                             #
###############################################################

require_once("/usr/local/emhttp/plugins/ca.update.applications/include/helpers.php");

function makeCron($frequency,$day,$dayOfMonth,$hour,$minute,$custom) {
  switch ($frequency) {
    case "Daily":
      $cronSetting = "$minute $hour * * *";
      break;
    case "Weekly":
      $cronSetting = "$minute $hour * * $day";
      break;
    case "Monthly":
      $cronSetting = "$minute $hour $dayOfMonth * *";
      break;
    case "Custom":
      $cronSetting = $custom;
      break;
    case "disabled":
      $cronSetting = false;
      break;
    default:
      $cronSetting = "Invalid frequency setting of $frequency";
      break;
  }
  return $cronSetting;
}

############################################
############################################
##                                        ##
## BEGIN MAIN ROUTINES CALLED BY THE HTML ##
##                                        ##
############################################
############################################


switch ($_POST['action']) {
#################################################
#                                               #
# Setup the json file for the cron autoupdating #
#                                               #
#################################################

case 'autoUpdatePlugins':
  $globalUpdate          = getPost("globalUpdate","no");
  $pluginList            = getPostArray("pluginList","");
  $updateArray['notify'] = getPost("notify","yes");
  $updateArray['delay']  = getPost("delay","3");
  $updateArray['Global'] = ( $globalUpdate == "yes" ) ? "true" : "false";
  $pluginCron            = getPostArray("pluginCron");
  foreach ($pluginCron as $setting) {
    $updateArray['cron'][$setting[0]] = trim($setting[1]);
  }
  foreach($pluginList as $plugin) {
    $plugins[] = $plugin[0];
  }

  if ( is_array($plugins) ) {
    foreach ($plugins as $plg) {
      if (is_file("/var/log/plugins/$plg") ) {
        $updateArray[$plg] = "true";
      }
    }
  }
  $frequency  = $updateArray['cron']['pluginCronFrequency'];
  $day        = $updateArray['cron']['pluginCronDay'];
  $dayOfMonth = $updateArray['cron']['pluginCronDayOfMonth'];
  $hour       = $updateArray['cron']['pluginCronHour'];
  $minute     = $updateArray['cron']['pluginCronMinute'];
  $custom     = $updateArray['cron']['pluginCronCustom'];
  
  $pluginCron = "# Generated cron settings for plugin autoupdates\n";
  $generatedCron = makeCron($frequency,$day,$dayOfMonth,$hour,$minute,$custom);
  $pluginCron .= "$generatedCron /usr/local/emhttp/plugins/ca.update.applications/scripts/updateApplications.php >/dev/null 2>&1\n";
  
  if ( $generatedCron ) {
    file_put_contents("/boot/config/plugins/ca.update.applications/plugin_update.cron",$pluginCron);
  } else {
    @unlink("/boot/config/plugins/ca.update.applications/plugin_update.cron");
  }
  writeJsonFile("/boot/config/plugins/ca.update.applications/AutoUpdateSettings.json",$updateArray);
  exec("/usr/local/sbin/update_cron");
  break;

case 'dockerApply':
  $settings                     = getPostArray("dockerSettings");
  $containers                   = getPostArray("autoUpdate");
  $dockerCron                   = getPostArray("dockerCron");
  
  foreach($dockerCron as $cronSetting) {
    $dockerSettings['cron'][$cronSetting[0]] = trim($cronSetting[1]);
  }  
  foreach($containers as $container) {
    $tmp['name'] = $container;
    $tmp['update'] = true;
    $dockerSettings['containers'][$container] = $tmp;
  }
  
  foreach ($settings as $setting) {
    $dockerSettings['global'][$setting[0]] = $setting[1];
  }
  $frequency  = $dockerSettings['cron']['dockerCronFrequency'];
  $day        = $dockerSettings['cron']['dockerCronDay'];
  $dayOfMonth = $dockerSettings['cron']['dockerCronDayOfMonth'];
  $hour       = $dockerSettings['cron']['dockerCronHour'];
  $minute     = $dockerSettings['cron']['dockerCronMinute'];
  $custom     = $dockerSettings['cron']['dockerCronCustom'];
  
  $dockerCron = "# Generated cron settings for docker autoupdates\n";
  $generatedCron = makeCron($frequency,$day,$dayOfMonth,$hour,$minute,$custom);
  $dockerCron .= "$generatedCron /usr/local/emhttp/plugins/ca.update.applications/scripts/updateDocker.php >/dev/null 2>&1\n";
  
  if ( $generatedCron ) {
    file_put_contents("/boot/config/plugins/ca.update.applications/docker_update.cron",$dockerCron);
  } else {
    @unlink("/boot/config/plugins/ca.update.applications/docker_update.cron");
  }
  writeJsonFile("/boot/config/plugins/ca.update.applications/DockerUpdateSettings.json",$dockerSettings);
  exec("/usr/local/sbin/update_cron");
  break;
}
?>
