#!/bin/php
#
# HestiaCP NodeApp template installer
#
<?php
// Gather the arguments
if (count( $argv ) < 6) {
    echo "Usage: <user> <domain> <ip> <home> <docroot>\n";
    exit( 1 );
}else{
    array_shift( $argv ); // remove the script path

    // Allow override of the arguments
    require_once( '/usr/local/hestia/web/pluginable.php' );
    global $hcpp; 
    $argv = $hcpp->do_action( 'pre_nodeapp_template', $argv );

    $user = $argv[0];
    $domain = $argv[1];
    $ip = $argv[2];
    $home = $argv[3];
    $docroot = $argv[4];

    // Ensure port is allocated
    $name = 'nodeapp_port';
    $port = $hcpp->allocate_port( $name, $user, $domain );
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
if ( !file_exists( $docroot ) ) {
    mkdir( $docroot, 0750, true );
    chown( $docroot, $user );
    chgrp( $docroot, $user );

    // Copy over fresh nodeapp
    $src = new RecursiveDirectoryIterator('/usr/local/hestia/plugins/nodeapp/nodeapp');
    $it  = new RecursiveIteratorIterator($src);
    foreach ( $it as $file ) {
        if ( $file->isFile()) {
            copy( $file->getPathname(), $docroot . '/' . $file->getFilename() );
            chown( $docroot . '/' . $file->getFilename(), $user );
            chgrp( $docroot . '/' . $file->getFilename(), $user );
        }
    }
    $argv = $hcpp->do_action( 'copy_nodeapp_files', $argv );

    // Install the app.js dependencies
    $cmd = 'runuser -l ' . $user . ' -c "cd \"' . $docroot . '\" && source /opt/nvm/nvm.sh && npm install"';
    $argv[5] = $cmd;
    $cmd = $hcpp->do_action( 'install_nodeapp_dependencies', $argv )[5];
    shell_exec( $cmd );
}

// Start the nodeapp service for the domain
$cmd = 'runuser -l ' . $user . ' -c "cd \"' . $docroot . '\" && source /opt/nvm/nvm.sh && pm2 start app.config.js"';
$argv[5] = $cmd;
$cmd = $hcpp->do_action( 'start_nodeapp_services', $argv )[5];
shell_exec( $cmd );
