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
// Preselected SMART codes for notifications
$numbers   = [];
$preselect = [['code' =>   5, 'set' => true, 'text' => '重新分配的扇区计数'],
              ['code' => 187, 'set' => true, 'text' => '报告的不可纠正错误'],
              ['code' => 188, 'set' => false,'text' => '命令超时'],
              ['code' => 197, 'set' => true, 'text' => '当前休眠的扇区计数'],
              ['code' => 198, 'set' => true, 'text' => '不可纠正扇区计数'],
              ['code' => 199, 'set' => true, 'text' => 'UDMA CRC 错误率']];

for ($x = 0; $x < count($preselect); $x++) if ($preselect[$x]['set']) $numbers[] = $preselect[$x]['code'];
$numbers = implode('|',$numbers);
?>