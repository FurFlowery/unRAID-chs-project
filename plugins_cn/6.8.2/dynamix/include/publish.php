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
function curl_socket($socket, $url, $postdata = NULL)
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, $socket);
    if ($postdata !== NULL) {
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}
function publish($endpoint, $message)
{
    return curl_socket("/var/run/nginx.socket", "http://localhost/pub/$endpoint?buffer_length=1", $message);
}
?>
