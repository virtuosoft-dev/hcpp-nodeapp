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
