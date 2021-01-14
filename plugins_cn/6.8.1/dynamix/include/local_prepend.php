<?PHP
/* Copyright 2005-2018, Lime Technology
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
/* This file provides basic local setup for php cli and is
 * named in /etc/php/php.ini like this:
 * auto_prepend_file="/usr/local/emhttp/webGui/include/local_prepend.php"
 */
function csrf_terminate($reason) {
    shell_exec("logger error: " . escapeshellarg($_SERVER['REQUEST_URI']) . ": $reason csrf_token");
    exit;
}
putenv('PATH=.:/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin');
chdir('/usr/local/emhttp');
setlocale(LC_ALL,'en_US.UTF-8');
date_default_timezone_set(substr(readlink('/etc/localtime-copied-from'),20));
ini_set("session.use_strict_mode", "1");
session_name("unraid_".md5(strstr($_SERVER['HTTP_HOST'].':', ':', true)));
session_set_cookie_params(0, '/; samesite=strict', null, array_key_exists('HTTPS', $_SERVER), true);
if ($_SERVER['SCRIPT_NAME'] != '/login.php' && $_SERVER['SCRIPT_NAME'] != '/auth_request.php' && isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($var)) $var = parse_ini_file('state/var.ini');
    if (!isset($var['csrf_token'])) csrf_terminate("uninitialized");
    if (!isset($_POST['csrf_token'])) csrf_terminate("missing");
    if ($var['csrf_token'] != $_POST['csrf_token']) csrf_terminate("wrong");
    unset($_POST['csrf_token']);
}
?>
