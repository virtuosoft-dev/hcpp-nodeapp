# Hello world!
This is a basic NodeJS web server application, Hello world example from
https://expressjs.com/en/starter/hello-world.html. See the source code in `app.js` for details.

The application is managed under [PM2 (Process Manager 2)](https://pm2.keymetrics.io). You can run or stop running this application using the following commands from the nodeapp folder:

```
pm2 start app.config.js
```
and
```
pm2 delete app.config.js
```

PM2 features an auto-restart mechanism that you can activate by simply changing a file in the .restart subfolder. For example, you can restart the app.js application with:

```
touch .restart/restart
```

You can change the version of the NodeJS runtime engine by supplying a complete or partial version number in the file `.nvmrc` (i.e. "v18" or "current" for the latest NodeJS version). Supported NodeJS versions are:

```
v14.21.1
v16.18.1
v18.12.1
```

## Create Project
You can create your own project by simply renaming the app.js and app.config.js file; change 'app' to your own project. Don't forget to update the package.json's `main` property from 'app' to your own project's name. 
