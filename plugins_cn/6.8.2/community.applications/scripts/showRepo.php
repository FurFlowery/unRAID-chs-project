<?
###############################################################
#                                                             #
# Community Applications copyright 2015-2021, Andrew Zawadzki #
#                   Licenced under GPLv2                      #
#                                                             #
###############################################################
$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: "/usr/local/emhttp";

$translations = is_file("$docroot/plugins/dynamix/include/Translations.php");

if ( $translations ) {
	$_SERVER['REQUEST_URI'] = "docker/apps";
	require_once "$docroot/plugins/dynamix/include/Translations.php";
}

require_once "$docroot/plugins/dynamix/include/Wrappers.php";
require_once "$docroot/plugins/dynamix/include/Helpers.php";
require_once "$docroot/plugins/community.applications/include/paths.php";

$unraidVersion  = parse_ini_file("/etc/unraid-version");
$unRaidVars     = parse_ini_file("/var/local/emhttp/var.ini");
$caSettings     = parse_plugin_cfg("community.applications");
$csrf_token     = $unRaidVars['csrf_token'];
$repository     = urldecode($_GET['repository']);
$repoName       = htmlentities($repository,ENT_QUOTES);

function tr($string,$ret=false) {
	if ( function_exists("_") )
		$string =  str_replace('"',"&#34;",str_replace("'","&#39;",_($string)));

	if ( $ret )
		return $string;
	else
		echo $string;
}
?>
<script src='<?autov("/plugins/dynamix/javascript/dynamix.js")?>'></script>
<script src='<?autov("/plugins/community.applications/javascript/libraries.js")?>'></script>

<link type="text/css" rel="stylesheet" href='<?autov("/webGui/styles/font-awesome.css")?>'>
<link type="text/css" rel="stylesheet" href='<?autov("/plugins/community.applications/skins/Narrow/css.php")?>'>
<link type="text/css" rel="stylesheet" href='<?autov("/webGui/styles/default-fonts.css")?>'>
<!-- Specific styling for the popup -->
<style>
p {margin-left:2rem;margin-right:2rem;}
body {margin-left:1.5rem;margin-right:1.5rem;margin-top:1.5rem;font-family:clear-sans;font-size:0.9rem;}
hr { margin-top:1rem;margin-bottom:1rem; }
div.spinner{margin:48px auto;text-align:center;}
div.spinner.fixed{position:fixed;top:50%;left:50%;margin-top:-16px;margin-left:-64px}
div.spinner .unraid_mark{height:64px}
div.spinner .unraid_mark_2,div .unraid_mark_4{animation:mark_2 1.5s ease infinite}
div.spinner .unraid_mark_3{animation:mark_3 1.5s ease infinite}
div.spinner .unraid_mark_6,div .unraid_mark_8{animation:mark_6 1.5s ease infinite}
div.spinner .unraid_mark_7{animation:mark_7 1.5s ease infinite}
@keyframes mark_2{50% {transform:translateY(-40px)} 100% {transform:translateY(0px)}}
@keyframes mark_3{50% {transform:translateY(-62px)} 100% {transform:translateY(0px)}}
@keyframes mark_6{50% {transform:translateY(40px)} 100% {transform:translateY(0px)}}
@keyframes mark_7{50% {transform:translateY(62px)} 100% {transform: translateY(0px)}}
</style>

<script>
var csrf_token = "<?=$csrf_token?>";
$(function() {
	setTimeout(function() {
		$(".spinner").show();
	},250);
	$.post("/plugins/community.applications/include/exec.php",{action:'getRepoDescription',repository:'<?=$repoName?>',csrf_token:csrf_token},function(result) {
		try {
			var descData = JSON.parse(result);
		} catch(e) {
			var descData = new Object();
			descData.description = result;
		}
		$("#popUpContent").hide();

		$("#popUpContent").html(descData.description);

		$('img').on("error",function() {
			$(this).attr('src',"/plugins/dynamix.docker.manager/images/question.png");
		});


		$("#popUpContent").show();
	});
});

function setFavourite(){
	if ( $("#favMsg").hasClass("ca_favouriteRepo") )
		return;
	var repository = "<?=$repository?>";
	$.post("/plugins/community.applications/include/exec.php",{action:'toggleFavourite',repository:'<?=$repoName?>',csrf_token:csrf_token});
	$("#favMsg").toggleClass("ca_non_favouriteRepo ca_favouriteRepo").html("<?tr("收藏的作者");?> ");
	$.cookie("ca_setFavRepo",repository,{path:"/"});
}

function searchRepo() {
	var repository = "<?=$repository?>";
	$.cookie("ca_searchRepo",repository,{path:"/"});
	window.parent.Shadowbox.close();
}
	
</script>
<html>
<body>
<span id='popUpContent'><div class='spinner fixed' style='display:none;'><?readfile("$docroot/plugins/dynamix/images/animated-logo.svg")?></div></span>
</body>
</html>