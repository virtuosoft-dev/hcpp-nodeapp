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
         * Scan the nodeapp folder for .config.js files and allocate a port for each
         */
        public function allocate_ports( $nodeapp_folder ) {
            global $hcpp;
            $parse = explode( '/', $nodeapp_folder );
            $user = $parse[2];
            $domain = $parse[4];

            // Wipe the existing ports for this domain
            if ( file_exists( "/opt/hcpp/ports/$user/$domain.ports" ) ) {
                unlink( "/opt/hcpp/ports/$user/$domain.ports" );
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
            $hcpp->do_action( 'nodeapp_allocate_ports', $args );
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
                $args = $hcpp->do_action( 'startup_nodeapp_services', $args );
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
                $args = $hcpp->do_action( 'shutdown_nodeapp_services', $args );
                $cmd = $args['cmd'];
                shell_exec( $cmd );
            }
        }
    }
    
    global $hcpp;
    $hcpp->nodeapp = new NodeApp();
}