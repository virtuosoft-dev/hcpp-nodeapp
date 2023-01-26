# hestiacp-nodeapp
A plugin for Hestia Control Panel (via hestiacp-pluginable) that enables hosting generic NodeJS based applications with control via pm2. 

> :warning: !!! Note: this repo is in progress; when completed, a release will appear in the release tab.

&nbsp;
## Installation
HestiaCP-NodeApp requires an Ubuntu based installation of [Hestia Control Panel](https://hestiacp.com) in addition to an installation of [HestiaCP-Pluginable](https://github.com/steveorevo/hestiacp-pluginable) to function; please ensure that you have first installed pluginable on your Hestia Control Panel before proceeding. Switch to a root user and simply download and unzip this project and move the folder to the /usr/local/hestia/plugins folder. It should appear as a subfolder with the name `nodeapp`, i.e. `/usr/local/hestia/plugins/nodeapp`.

Note: It is important that the plugin folder name is `nodeapp`.

```
sudo -s
cd /tmp
wget https://github.com/Steveorevo/hestiacp-nodeapp/archive/refs/heads/main.zip
unzip main.zip
mv hestiacp-nodeapp-main /usr/local/hestia/plugins/nodeapp
rm main.zip
```

Lastly, be sure to logout and log back in to your Hestia Control Panel; the plugin will immediately start installing NodeJS depedencies in the background. This may take a while, but eventually you will have a new Nginx **"Proxy Template"** for NodeApp. Add a new web domain and visit the **Advanced Options** section and look for the NodeApp option under You can also install manaully by invoking the installer as a root user:

```
sudo -s
cd /usr/local/hestia/plugins/nodeapp
./install
```



