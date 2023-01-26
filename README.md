# hestiacp-nodeapp
A plugin for Hestia Control Panel (via hestiacp-pluginable) that enables hosting generic NodeJS based applications with control via pm2. 

> :warning: !!! Note: this repo is in progress; when completed, a release will appear in the release tab.

&nbsp;
## Installation
Switch to a root user and simply download and unzip this project and move the folder to the /usr/local/hestia/plugins folder. It should appear as a subfolder with the name `nodeapp`, i.e. `/usr/local/hestia/plugins/nodeapp`.

Note: It is important that the plugin folder name is `nodeapp`.

```
sudo -s
cd /tmp
wget https://github.com/Steveorevo/hestiacp-nodeapp/archive/refs/heads/main.zip
unzip main.zip
mv hestiacp-nodeapp /usr/local/hestia/plugins/nodeapp
rm main.zip
```

