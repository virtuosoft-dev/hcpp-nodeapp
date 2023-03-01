/**
 * Give a path to an application config file, return a compatible PM2 app object with
 * details and support for .nvmrc, port allocation, and debug mode.
 * 
 * @param {*} path - the path to the *.config.js file, contains the user, domain, etc.
 */

module.exports = function(path) {
    // Get the app name, user, domain, other details given the file path
    const fs = require('fs');
    let details = {};
    let parse = path.split('/');
    details._config = parse.pop();
    details._app = details._config.replace('.config.js', '');
    details._domain = parse[4];
    details.name = details._app + '-' + details._domain;
    details._user = parse[2];
    details.cwd = path.substr(0, path.length - details._config.length - 1);
    details.script = details.cwd + '/' + details._app + '.js';
    details.watch = ['.restart'];
    details.ignore_watch = [];
    details.watch_delay = 5000;
    details.restart_delay = 5000;

    // Support optional .nvmrc file or default to current version
    let nvmrc = details.cwd + '/.nvmrc';
    let ver = 'current';
    if (fs.existsSync(nvmrc)) {
        ver = fs.readFileSync(nvmrc, {encoding: 'utf8', flat: 'r'}).trim();
    }
    const {execSync} = require('child_process');
    ver = execSync('/bin/bash -c "source /opt/nvm/nvm.sh && nvm which ' + ver + '"').toString().trim();
    if (!fs.existsSync(ver)) {
        console.error(ver);
        process.exit(1);
    }
    details.interpreter = ver;

    // Pass the allocated port number as a -p argument
    let port = 0;
    let pfile = '/usr/local/hestia/data/hcpp/ports/' + details._user + '/' + details._domain + '.ports';
    let ports = fs.readFileSync(pfile, {encoding:'utf8', flag:'r'});
    ports = ports.split(/\r?\n/);
    for( let i = 0; i < ports.length; i++) {
        if (ports[i].indexOf(details._app + '_port') > -1) {
            port = ports[i];
            break;
        }
    }
    port = parseInt(port.trim().split(' ').pop());
    details.args = "-p " + port;

    // Check for debug mode and pass debug port as port + 3000 offset
    if ( fs.existsSync(details.cwd + '/.restart/debug') ) {
        details.interpreter_args = '--inspect=' + (port + 3000);
    }
    return details;
}