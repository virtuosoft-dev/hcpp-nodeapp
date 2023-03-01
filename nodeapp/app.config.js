/**
 * Get compatible PM2 app object with details and automatic support for .nvmrc, 
 * port allocation, and debug mode.
 */
module.exports = {
    apps: (function() {
        let nodeapp = [require(__dirname + '/nodeapp.js')(__filename)];
        return nodeapp;
    })()
}
