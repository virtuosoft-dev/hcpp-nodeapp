<?php
/**
 * Extend the HestiaCP Pluginable object with our NodeApp object for
 * allocating NodeJS app ports and starting & stopping apps via PM2
 * for every .config.js file present.
 * 
 * @version 1.0.0
 * @license GPL-3.0
 * @link https://github.com/steveorevo/hestiacp-nodeapp
 * 
 */

if ( ! class_exists( 'NodeApp') ) {
    class NodeApp {

        /**
         * Constructor, listen for the priv_change_web_domain_proxy_tpl event
         */
        public function __construct() {
            global $hcpp;
            $hcpp->nodeapp = $this;
            $hcpp->add_action( 'priv_change_web_domain_proxy_tpl', [ $this, 'priv_change_web_domain_proxy_tpl' ] );
            $hcpp->add_action( 'pre_delete_web_domain_backend', [ $this, 'pre_delete_web_domain_backend' ] );
        }

        /**
         * On proxy template change, copy basic nodeapp, allocate ports, and start apps
         */
        public function priv_change_web_domain_proxy_tpl( $args ) {
            global $hcpp;
            $user = $args[0];
            $domain = $args[1];
            $proxy = $args[2];
            $nodeapp_folder = "/home/$user/web/$domain/nodeapp";
            if ( $proxy != 'NodeApp' ) {
                if ( is_dir( $nodeapp_folder) ) {
                    $hcpp->nodeapp->shutdown_apps( $nodeapp_folder );
                }
            }else{

                // Copy initial nodeapp folder
                if ( !is_dir( $nodeapp_folder) ) {
                    $this->copy_folder( '/usr/local/hestia/plugins/nodeapp/nodeapp', $nodeapp_folder, $user );
                    $args = [
                        'user' => $user,
                        'domain' => $domain,
                        'proxy' => $proxy,
                        'nodeapp_folder' => $nodeapp_folder
                    ];
                    $args = $hcpp->do_action( 'nodeapp_copy_files', $args );
                    $nodeapp_folder = $args['nodeapp_folder'];

                    // Install dependencies
                    $cmd = 'runuser -l ' . $user . ' -c "cd \"' . $nodeapp_folder . '\" && source /opt/nvm/nvm.sh && npm install"';
                    $args['cmd'] = $cmd;
                    $args = $hcpp->do_action( 'nodeapp_install_dependencies', $args );
                    shell_exec( $args['cmd'] );
                }
                $this->allocate_ports( $nodeapp_folder );
                $this->startup_apps( $nodeapp_folder );
            }
        }

        /**
         * On domain delete, shutdown apps
         */
        public function pre_delete_web_domain_backend( $args ) {
            global $hcpp;
            $user = $args[0];
            $domain = $args[1];
            $nodeapp_folder = "/home/$user/web/$domain/nodeapp";
            $this->shutdown_apps( $nodeapp_folder );
        }

        /**
         * Scan the nodeapp folder for .config.js files and allocate a port for each
         */
        public function allocate_ports( $nodeapp_folder ) {
            global $hcpp;
            $parse = explode( '/', $nodeapp_folder );
            $user = $parse[2];
            $domain = $parse[4];

            // Wipe the existing ports for this domain
            if ( file_exists( "/usr/local/hestia/data/hcpp/ports/$user/$domain.ports" ) ) {
                unlink( "/usr/local/hestia/data/hcpp/ports/$user/$domain.ports" );
            }

            // Allocate a port for each app
            $files = glob("$nodeapp_folder/*.config.js");
            foreach($files as $file) {

                // Get the name of the app from the filename
                if ( ! preg_match( '/\.config\.js$/', $file ) ) continue;
                $name = str_replace( '.config.js', '', end( explode( '/', $file ) ) );
                $name = preg_replace( "/[^a-zA-Z0-9-_]+/", "", $name ) . "_port";
                
                // Allocate a port for the app
                $hcpp->allocate_port( $name, $user, $domain );
            }

            // Throw a nodeapp event to allow other plugins allocate ports
            $args = [
                'user' => $user,
                'domain' => $domain,
                'nodeapp_folder' => $nodeapp_folder
            ];
            $hcpp->do_action( 'nodeapp_ports_allocated', $args );
        }

        /**
         * Scan the nodeapp folder for .config.js files and start the app for each
         */
        public function startup_apps( $nodeapp_folder ) {
            $parse = explode( '/', $nodeapp_folder );
            $user = $parse[2];
            $domain = $parse[4];
            $files = glob("$nodeapp_folder/*.config.js");
            $cmd = 'runuser -l ' . $user . ' -c "cd \"' . $nodeapp_folder . '\" && source /opt/nvm/nvm.sh ';
            foreach($files as $file) {

                // Get the name of the app from the filename
                if ( ! preg_match( '/\.config\.js$/', $file ) ) continue;
                $name = str_replace( '.config.js', '', end( explode( '/', $file ) ) );
                $name = preg_replace( "/[^a-zA-Z0-9-_]+/", "", $name );
                
                // Add app to startup
                $cmd .= '; pm2 start ' . $name . '.config.js ';
            }
            $cmd .= '"';

            if ( strpos( $cmd, '; pm2 start ' ) ) {
                global $hcpp;
                $args = [
                    'user' => $user,
                    'domain' => $domain,
                    'cmd' => $cmd
                ];

                // Run the command to start all the apps
                $args = $hcpp->do_action( 'nodeapp_startup_services', $args );
                $cmd = $args['cmd'];
                shell_exec( $cmd );
            }
        }

        /**
         * Scan the nodeapp folder for .config.js files and delete the app for each,
         * instead of pm2 delete all; delete each one individually as filters may
         * alter the behavior.
         */
        public function shutdown_apps( $nodeapp_folder ) {
            $parse = explode( '/', $nodeapp_folder );
            $user = $parse[2];
            $domain = $parse[4];
            $files = glob("$nodeapp_folder/*.config.js");
            $cmd = 'runuser -l ' . $user . ' -c "cd \"' . $nodeapp_folder . '\" && source /opt/nvm/nvm.sh ';
            foreach($files as $file) {

                // Get the name of the app from the filename
                if ( ! preg_match( '/\.config\.js$/', $file ) ) continue;
                $name = str_replace( '.config.js', '', end( explode( '/', $file ) ) );
                $name = preg_replace( "/[^a-zA-Z0-9-_]+/", "", $name );
                
                // Add app to shutdown
                $cmd .= '; pm2 delete ' . $name . '.config.js ';
            }
            $cmd .= '"';

            if ( strpos( $cmd, '; pm2 start ' ) ) {
                global $hcpp;
                $args = [
                    'user' => $user,
                    'domain' => $domain,
                    'cmd' => $cmd
                ];

                // Run the command to shutdown all the apps
                $args = $hcpp->do_action( 'nodeapp_shutdown_services', $args );
                $cmd = $args['cmd'];
                shell_exec( $cmd );
            }
        }

        public function copy_folder( $src, $dst, $user ) {
            if ( is_dir( $src ) ) {
              if ( !is_dir( $dst ) ) {
                mkdir( $dst );
                chmod( $dst, 0750);
                chown( $dst, $user );
                chgrp( $dst, $user );
              }
          
              $files = scandir( $src );
              foreach ( $files as $file ) {
                if ( $file != "." && $file != ".." ) {
                  $this->copy_folder( "$src/$file", "$dst/$file", $user );
                }
              }
            } elseif ( file_exists( $src ) ) {
              copy( $src, $dst );
              chmod( $dst, 0640);
              chown( $dst, $user );
              chgrp( $dst, $user );
            }
          }
    }
    new NodeApp();
}