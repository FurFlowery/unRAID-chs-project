<?PHP
/* Copyright 2012-2020, Bergware International.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * Plugin development contribution by gfjardim
 */
?>
<?
$modules = [];
exec("sensors-detect --auto 2>&1|grep -Po \"^Driver.{2}\K[^\']*\"", $matches);
foreach ($matches as $module) if (exec("modprobe -D $module 2>/dev/null")) $modules[] = $module;
sort($modules);
echo implode(' ',$modules);
?>
