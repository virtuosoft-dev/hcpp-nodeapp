module.exports = {
    apps: [{
        name: "app",
        script: "./app.js",
        interpreter: "/opt/nvm/versions/node/v18.12.1/bin/node",
        //interpreter: "/opt/nvm/versions/node/16.18.1/bin/node",
        //interpreter: "/opt/nvm/versions/node/14.21.1/bin/node",
        
        args: (function () {

            // Current folder has username and domain,
            // i.e. /home/%username%/web/%domain%, use it to find
            // port file in /opt/hcpp/ports/%username%/%domain%.ports
            let file = __dirname;
            file.replace('/home/', '/opt/hcpp/ports/').replace('/web/', '/').replace('/nodeapp', '.ports');
            const fs = require('fs');

            // Pass the port number as the `p` argument to the app
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
