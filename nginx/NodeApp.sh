#!/bin/php
#
# HestiaCP NodeApp template installer
#
<?php
// Gather the arguments
if (count( $argv ) < 6) {
    echo "Usage: <user> <domain> <ip> <home> <docroot>\n";
    exit(1);
}else{
    $user = $argv[1];
    $domain = $argv[2];
    $ip = $argv[3];
    $home = $argv[4];
    $docroot = $argv[5];
}

// Utility string function
function replaceLast( $haystack, $needle, $replace ) {
    $pos = strrpos( $haystack, $needle );
    if ($pos !== false) {
    $newstring = substr_replace( $haystack, $replace, $pos, strlen( $needle ) );
    }else{
        $newstring = $haystack;
    }
    return $newstring;
}
$docroot = replaceLast( $docroot, '/public_html', '/nodeapp' );

// Create the NodeApp directory
if (!file_exists( $docroot )) {
    mkdir( $docroot, 0750, true);
    chown( $docroot, $user );
    chgrp( $docroot, $user );
}

// Copy over fresh nodeapp config file
if (!file_exists( $docroot . '/app.config.js' )) {
    copy( '/usr/local/hestia/plugins/nodeapp/nodeapp/app.config.js', $docroot . '/app.config.js' );
    chown( $docroot . '/app.config.js', $user );
    chgrp( $docroot . '/app.config.js', $user );
}

// Copy over fresh Hello World! Express app
if (!file_exists( $docroot . '/app.js' )) {
    copy( '/usr/local/hestia/plugins/nodeapp/nodeapp/app.js', $docroot . '/app.js' );
    chown( $docroot . '/app.js', $user );
    chgrp( $docroot . '/app.js', $user );
}

// Restart the nodeapp service for the domain
$cmd = 'runuser -l ' . $user . ' -c "cd \"' . $docroot . '\" && pm2 delete app.config.js && pm2 start app.config.js';
shell_exec($cmd);
