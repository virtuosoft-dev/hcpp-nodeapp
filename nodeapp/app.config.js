module.exports = {
    apps: (function() {
        
        // Get the app name, user and domain name from the current directory path.
        let app = __filename.split('/').pop().replace('.config.js', '');
        let domain = __dirname.split('/')[4];
        let user = __dirname.split('/')[2];
        return [{
            name: app + '-' + domain,
            script: __dirname + '/' + app + '.js',
            cwd: __dirname,
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
                 * Pass the allocated port number as an argument to the node application.
                 * 
                 * The port number is read from a file in /usr/local/hestia/data/hcpp/ports/%username%/%domain%.ports.
                 */
                let port = 0;
                let file = '/usr/local/hestia/data/hcpp/ports/' + user + '/' + domain + '.ports';
                const fs = require('fs');
                let ports = fs.readFileSync(file, {encoding:'utf8', flag:'r'});
                ports = ports.split(/\r?\n/);
                for( let i = 0; i < ports.length; i++) {
                    if (ports[i].indexOf(app + '_port') > -1) {
                        port = ports[i];
                        break;
                    }
                }
    
                // Find the port number assigned to app_port
                port = parseInt(port.trim().split(' ').pop());
                return "-p " + port;
            })(),
            watch: ['.restart'],
            ignore_watch: [],
            watch_delay: 5000,
            restart_delay: 5000
        }];
    })()
}
