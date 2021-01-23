<?PHP
require_once "$docroot/webGui/include/Markdown.php";

// Compatibility functions to support Unraid legacy versions without multi-language
if (!function_exists('_')) {
  function _($text) {return $text;}
}
if (!function_exists('parse_lang_file')) {
  function parse_lang_file($file) {return;}
}
if (!function_exists('parse_help_file')) {
  function parse_help_file($file) {return;}
}
if (!function_exists('parse_text')) {
  function parse_text($text) {return preg_replace_callback('/_\((.+?)\)_/m',function($m){return $m[1];},preg_replace(["/^:.+_(help|plug):$/m","/^:end$/m","/^\\$(translations = ).+;/m"],['','','\\$$1true;'],strpos($text,"---\n")===false?$text:explode("---\n",$text,2)[1]));}
}
if (!function_exists('parse_file')) {
  function parse_file($file,$markdown=true) {return $markdown ? Markdown(parse_text(file_get_contents($file))) : parse_text(file_get_contents($file));}
}
if (!function_exists('parse_plugin')) {
  function parse_plugin($plugin) {return;}
}
if (!$noscript) echo "<script>if (typeof _ != 'function') function _(t) {return t;}</script>";
?>
