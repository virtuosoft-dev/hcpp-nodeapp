#!/bin/php
<?php
//
// HestiaCP NodeApp template installer
//

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
$nodeapp_folder = replaceLast( $docroot, '/public_html', '/nodeapp' );

// Create the NodeApp directory
if ( !file_exists( $nodeapp_folder ) ) {
    mkdir( $nodeapp_folder, 0750, true );
    chown( $nodeapp_folder, $user );
    chgrp( $nodeapp_folder, $user );

    // Copy over fresh nodeapp
    $src = new RecursiveDirectoryIterator('/usr/local/hestia/plugins/nodeapp/nodeapp');
    $it  = new RecursiveIteratorIterator($src);
    foreach ( $it as $file ) {
        if ( $file->isFile()) {
            copy( $file->getPathname(), $nodeapp_folder . '/' . $file->getFilename() );
            chown( $nodeapp_folder . '/' . $file->getFilename(), $user );
            chgrp( $nodeapp_folder . '/' . $file->getFilename(), $user );
        }else{
            mkdir( $nodeapp_folder . '/' . $file->getFilename(), 0750, true );
            chown( $nodeapp_folder . '/' . $file->getFilename(), $user );
            chgrp( $nodeapp_folder . '/' . $file->getFilename(), $user );
        }
    }
    $argv = $hcpp->do_action( 'copy_nodeapp_files', $argv );

    // Install the app.js dependencies
    $cmd = 'runuser -l ' . $user . ' -c "cd \"' . $nodeapp_folder . '\" && source /opt/nvm/nvm.sh && npm install"';
    $argv[5] = $cmd;
    $args = [
        'user' => $user,
        'domain' => $domain,
        'ip' => $ip,
        'home' => $home,
        'docroot' => $docroot,
        'cmd' => $cmd
    ];
    $args = $hcpp->do_action( 'install_nodeapp_dependencies', $args );
    $cmd = $args['cmd'];
    shell_exec( $cmd );
}

// Start the nodeapp service for the domain
//global $hcpp;
//require_once( '/usr/local/hesita/plugins/nodeapp/nodeapp.php' );
//$hcpp->nodeapp->allocate_ports( $nodeapp_folder );
//$hcpp->nodeapp->startup_nodeapps( $nodeapp_folder );
