<?php
/**
 * Plugin Name: NodeApp
 * Plugin URI: https://github.com/steveorevo/hestiacp-nodeapp
 * Description: NodeApp is a plugin for HestiaCP that allows you to easily host NodeJS applications.
 */

// Register the install and uninstall scripts
global $hcpp;

$hcpp->register_install_script( dirname(__FILE__) . '/install' );
$hcpp->register_uninstall_script( dirname(__FILE__) . '/uninstall' );

// When a new domain is created, allocate a port for our NodeJS based application
$hcpp->add_action( 'pre_add_web_domain_backend', function( $args ) {
    global $hcpp;
    $name = 'nodeapp_port';
    $user = $args[0];
    $domain = $args[1];
    $port = $hcpp->allocate_port( $name, $user, $domain );
    $hcpp->log( "Allocated $name on port $port for $user/$domain" );
    return $args;
});

// Shutdown the NodeJS application when the domain is deleted
$hcpp->add_action( 'pre_delete_web_domain_backend', function( $args ) {
    global $hcpp;
    $user = $args[0];
    $domain = $args[1];
    $docroot = "/home/$user/web/$domain/nodeapp";
    $cmd = 'runuser -l ' . $user . ' -c "cd \"' . $docroot . '\" && source /opt/nvm/nvm.sh && pm2 delete app.config.js"';
    $cmd = $hcpp->do_action( 'shutdown_nodeapp_services', $cmd );
    shell_exec( $cmd );
    $hcpp->log( "Shut down NodeJS application nodeapp-$domain" );
    return $args;
});

// TODO: switching to a different template should stop the NodeJS application
// and throw a pluginable action
