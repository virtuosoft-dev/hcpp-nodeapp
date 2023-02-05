<?php
/**
 * Plugin Name: NodeApp
 * Plugin URI: https://github.com/steveorevo/hestiacp-nodeapp
 * Description: NodeApp is a plugin for HestiaCP that allows you to easily host NodeJS applications.
 */

// Register the install and uninstall scripts
global $hcpp;
require_once( dirname(__FILE__) . '/nodeapp.php' );

$hcpp->register_install_script( dirname(__FILE__) . '/install' );
$hcpp->register_uninstall_script( dirname(__FILE__) . '/uninstall' );

// Shutdown the NodeJS applications when the domain is deleted
$hcpp->add_action( 'pre_delete_web_domain_backend', function( $args ) {
    global $hcpp;
    $user = $args[0];
    $domain = $args[1];
    $nodeapp_folder = "/home/$user/web/$domain/nodeapp";
    $hcpp->nodeapp->shutdown_apps( $nodeapp_folder );
    return $args;
});

// Startup or shutdown applications when the domain proxy template is changed
$hcpp->add_action( 'priv_change_web_domain_proxy_tpl', function( $args ) {
    global $hcpp;
    $user = $args[0];
    $domain = $args[1];
    $proxy = $args[2];
    $nodeapp_folder = "/home/$user/web/$domain/nodeapp";
    if ( $proxy != 'NodeApp' ) {    
        $hcpp->nodeapp->shutdown_apps( $nodeapp_folder );
    }else{
        $hcpp->nodeapp->allocate_ports( $nodeapp_folder );
        $hcpp->nodeapp->startup_apps( $nodeapp_folder );
    }
    return $args;
});
