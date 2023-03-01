/**
 * Get compatible PM2 app config object with automatic support for .nvmrc, 
 * port allocation, and debug mode.
 */
module.exports = {
    apps: (function() {
        let nodeapp = require('/usr/local/hestia/plugins/nodeapp/nodeapp.js')(__filename);
        return [nodeapp];
    })()
}
