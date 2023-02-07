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
