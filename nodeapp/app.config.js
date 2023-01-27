module.exports = {
    apps: [{
        name: "app",
        script: "./app.js",
        interpreter: (function() {
            /**
             * Specify the node interpreter to use.
             * 
             * Read the .nvmrc file in the current directory and use the node version specified in it,
             * or default to the latest node version.
             */

            let file = __dirname + '/.nvmrc';
            let ver = 'current';
            const fs = require('fs');
            if (fs.existsSync(file)) {
                ver = fs.readFileSync(file, {encoding:'utf8', flag:'r'}).trim();
            }
            ver = execSync("nvm which " . ver).toString();
            if (!fs.existsSync(ver)) {
                console.error(ver);
                process.exit(1);
            }else{
                return ver.trim();
            }
        })(),       
        args: (function() {
            /**
             * Pass the allocated port number as an argument to app.js
             * 
             * The port number is read from a file in /opt/hcpp/ports/%username%/%domain%.ports,
             * we know the username and domain name from the current directory path.
             */

            let file = __dirname;
            file = file.replace('/home/', '/opt/hcpp/ports/').replace('/web/', '/').replace('/nodeapp', '.ports');
            const fs = require('fs');
            let port = fs.readFileSync(file, {encoding:'utf8', flag:'r'});
            port = parseInt(port.trim().split(' ').pop());
            return "-p " + port;
        })(),
        watch: [".restart"],
        ignore_watch: [],
        watch_delay: 5000,
        restart_delay: 5000
    }]
}
