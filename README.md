# unRAID-chs-project
unRAID 中文化项目

原项目地址(已失效):https://github.com/KleinerSource/unRAID-chs-project

本项目Fork原项目，从V2.0开始接手此项目，在原插件基础上新增一些插件的翻译以及修正一些翻译问题

基于 unRAID Server Web 控制台 的汉化项目.

让国内用户更好的体验 unRAID Server.

支持作为 插件, 重新封包(bzroot), sftp上传, 等方式使用.

请注意，由于unraid插件安装方式的特性，项目Fork无用，依然会从本项目下载插件相关文件，需要修改相关安装脚本才可以。

### 插件方式:
登录 unraid web面板 选择插件页面
输入插件地址  点击安装即可.

#### 自建源(服务器位于台湾，建议国内使用)：

unRaid 6.8.1 https://file.dxmc.net/Github/unRAID-chs-project/release/urchs.681.plg

unRaid 6.8.2 https://file.dxmc.net/Github/unRAID-chs-project/release/urchs.682.plg

#### Github源(建议海外使用)：

unRaid 6.8.1
https://raw.githubusercontent.com/yunlancn/unRAID-chs-project/master/release/urchs.681.plg

unRaid 6.8.2 
https://raw.githubusercontent.com/yunlancn/unRAID-chs-project/master/release/urchs.682.plg

Gitee源由于Gitee将插件的txz包当作shell处理提供给unraid，导致下载下来的插件无法解压，因此废弃

### 注: 插件方式安装重启服务器受不影响, 如果要卸载插件 需要重启服务器才会生效.

### 更新日志  
  
#### V2.0(2021-01-14)  
  
新增翻译  
1、新增"插件商店"(Community Applications)插件翻译  
2、新增"系统温度"(Dynamix System Temperature)插件翻译  
3、新增"未分配设备"(Unassigned Devices)插件翻译  
4、新增"自动更新"(CA Auto Update Applications)插件翻译  
5、新增"系统状态"(Dynamix System Statistics)插件翻译  
  
修复问题  
1、修复翻译细节  

### 插件翻译  

1、插件商店Community Applications  
2、系统温度Dynamix System Temperature  
3、未分配设备Unassigned Devices  
4、自动更新CA Auto Update Applications  
5、系统状态Dynamix System Statistics   
待添加......
  
### 目录说明

release - 打包后的插件以及插件安装脚本  
plugins_cn - 插件及系统翻译  
gui/usr/share/fonts - 中文字体  

### sftp上传方式:
在unraid启动完成后 利用winscp等软件 连接到unraid ssh 将文件复制到 /usr/local/emhttp/plugins 中. 刷新页面即可.

注: 该方法在 unraid server 重启服务器之后 将会恢复到原英文版.

遇到问题可以提交 ISSUES 给我.
