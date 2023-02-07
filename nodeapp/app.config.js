module.exports = {
    apps: [{
        name: (function() {
            /**
             * Name the app based on the domain name from the current directory path.
             */
            let domain = __dirname.split('/')[4];
            return 'app-' + domain;
        })(),
        script: "./app.js",
        interpreter: (function() {
            /**
             * Specify the node interpreter to use.
             * 
             * Read the .nvmrc file and find a suitable node version specified from it,
             * or default to the latest node version.
             */

            let file = __dirname + '/.nvmrc';
            let ver = 'current';
            const fs = require('fs');
            if (fs.existsSync(file)) {
                ver = fs.readFileSync(file, {encoding:'utf8', flag:'r'}).trim();
            }
            const { execSync } = require('child_process');
            ver = execSync('/bin/bash -c "source /opt/nvm/nvm.sh && nvm which ' + ver + '"').toString().trim();
            if (!fs.existsSync(ver)) {
                console.error(ver);
                process.exit(1);
            }else{
                return ver;
            }
        })(),       
        args: (function() {
            /**
             * Pass the allocated port number as an argument to app.js
             * 
             * The port number is read from a file in /usr/local/hestia/data/hcpp/ports/%username%/%domain%.ports,
             * we know the username and domain name from the current directory path.
             */
            let port = 0;
            let file = __dirname;
            file = file.replace('/home/', '/usr/local/hestia/data/hcpp/ports/').replace('/web/', '/').replace('/nodeapp', '.ports');
            const fs = require('fs');
            let ports = fs.readFileSync(file, {encoding:'utf8', flag:'r'});
            ports = ports.split(/\r?\n/);
            for( let i = 0; i < ports.length; i++) {
                if (ports[i].indexOf('app_port') > -1) {
                    port = ports[i];
                    break;
                }
            }

            // Find the port number assigned to app_port
            port = parseInt(port.trim().split(' ').pop());
            return "-p " + port;
        })(),
        watch: [".restart/app"],
        ignore_watch: [],
        watch_delay: 5000,
        restart_delay: 5000
    }]
}
