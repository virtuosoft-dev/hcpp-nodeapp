<?php
/**
 * Extend the HestiaCP Pluginable object with our NodeApp object for
 * allocating NodeJS app ports and starting & stopping apps via PM2
 * and NVM for every .config.js file present.
 * 
 * @version 1.0.0
 * @license GPL-3.0
 * @link https://github.com/virtuosoft-dev/hcpp-nodeapp
 * 
 */

if ( ! class_exists( 'NodeApp') ) {
    class NodeApp {
        public $domain = "";
        public $user = "";

        /**
         * Constructor, listen for the priv_change_web_domain_proxy_tpl event
         */
        public function __construct() {
            global $hcpp;
            $hcpp->nodeapp = $this;
            $hcpp->add_action( 'priv_change_web_domain_proxy_tpl', [ $this, 'priv_change_web_domain_proxy_tpl' ] );
            $hcpp->add_action( 'pre_delete_web_domain_backend', [ $this, 'pre_delete_web_domain_backend' ] );
            $hcpp->add_action( 'priv_suspend_web_domain', [ $this, 'priv_suspend_web_domain' ] );
            $hcpp->add_action( 'priv_unsuspend_web_domain', [ $this, 'priv_unsuspend_domain' ] ); // Bulk unsuspend domains only throws this event
            $hcpp->add_action( 'priv_unsuspend_domain', [ $this, 'priv_unsuspend_domain' ] ); // Individually unsuspend domain only throws this event
            $hcpp->add_action( 'hcpp_rebooted', [ $this, 'hcpp_rebooted' ] );
            $hcpp->add_action( 'hcpp_runuser', [ $this, 'hcpp_runuser' ] );
        }

        /**
         * Modify runuser to incorporate NVM
         */
        public function hcpp_runuser( $cmd ) {
            $cmd = 'export NVM_DIR=/opt/nvm && source /opt/nvm/nvm.sh && ' . $cmd;
            return $cmd;
        }

        /**
         * Check if system has rebooted and restart apps
         */
        public function hcpp_rebooted() {

            // Restart all PM2 apps for all user accounts
            $users = scandir('/home');
            global $hcpp;
            $cmd = '';
            foreach ( $users as $user ) {
                // Ignore hidden files/folders and system folders
                if ( $user == '.' || $user == '..' || $user == 'lost+found' || $user == 'systemd' ) {
                    continue;
                }
                
                // Check if the .pm2 folder exists in the user's home directory
                if ( is_dir( "/home/$user/.pm2" ) ) {

                    // Restart any pm2 processes
                    $cmd .= 'runuser -s /bin/bash -l ' . $user . ' -c "cd /home/' . $user . ' && ';
                    $cmd .= 'export NVM_DIR=/opt/nvm && source /opt/nvm/nvm.sh && pm2 resurrect"' . "\n";
                }
            }
            $cmd = $hcpp->do_action( 'nodeapp_resurrect_apps', $cmd );
            if ( trim( $cmd ) != '' )  {
                $hcpp->log( shell_exec( $cmd ) );
            }
        }

        /**
         * On proxy template change, copy basic nodeapp, allocate ports, and start apps
         */
        public function priv_change_web_domain_proxy_tpl( $args ) {
            global $hcpp;
            $user = $args[0];
            $domain = $args[1];
            $proxy = $args[2];

            // Remember for post_change_web_domain_proxy_tpl
            $this->user = $user;
            $this->domain = $domain;

            $nodeapp_folder = "/home/$user/web/$domain/nodeapp";
            
            if ( $proxy == 'NodeApp' ) {

                if ( !is_dir( $nodeapp_folder) ) {

                    // Copy initial nodeapp folder
                    $hcpp->copy_folder( __DIR__ . '/nodeapp', $nodeapp_folder, $user );
                    $args = [
                        'user' => $user,
                        'domain' => $domain,
                        'proxy' => $proxy,
                        'nodeapp_folder' => $nodeapp_folder
                    ];
                    $args = $hcpp->do_action( 'nodeapp_copy_files', $args );
                    $nodeapp_folder = $args['nodeapp_folder'];

                    // Install dependencies
                    $cmd = 'runuser -s /bin/bash -l ' . $user . ' -c "cd \"' . $nodeapp_folder . '\" && export NVM_DIR=/opt/nvm && source /opt/nvm/nvm.sh && npm install"';
                    $args['cmd'] = $cmd;
                    $args = $hcpp->do_action( 'nodeapp_install_dependencies', $args );
                    shell_exec( $args['cmd'] );
                }

                // Shutdown stray apps and startup root and subfolder apps
                $this->shutdown_apps( $nodeapp_folder );
                $this->allocate_ports( $nodeapp_folder );
                $this->generate_nginx_files( $nodeapp_folder );
                $this->startup_apps( $nodeapp_folder );
            }else {

                // Shutdown stray apps and only startup subfolder apps
                if ( is_dir( $nodeapp_folder) ) {
                    $this->shutdown_apps( $nodeapp_folder );
                    $this->allocate_ports( $nodeapp_folder );
                    $this->generate_nginx_files( $nodeapp_folder, false );
                    $this->startup_apps( $nodeapp_folder, false );
                }
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
         * On domain suspend, shutdown apps
         */
        public function priv_suspend_web_domain( $args ) {
            global $hcpp;
            $user = $args[0];
            $domain = $args[1];
            $nodeapp_folder = "/home/$user/web/$domain/nodeapp";
            $this->shutdown_apps( $nodeapp_folder );
            return $args;
        }

        /**
         * On domain unsuspend, startup apps
         */
        public function priv_unsuspend_domain( $args ) {
            global $hcpp;
            $user = $args[0];
            $domain = $args[1];
            $nodeapp_folder = "/home/$user/web/$domain/nodeapp";
            if ( is_dir( $nodeapp_folder) ) {
                $proxy = $hcpp->run("v-list-web-domain $user $domain json");
                if ( $proxy != NULL ) {
                   $proxy = $proxy[$domain]["PROXY"];
                   $this->allocate_ports( $nodeapp_folder );
                   $this->generate_nginx_files( $nodeapp_folder, ( $proxy == "NodeApp" ) );
                   $this->startup_apps( $nodeapp_folder, ( $proxy == "NodeApp" ) );
                }    
            }
            return $args;
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

            // Allocate a port for each .config.js file found
            $files = $this->get_config_files( $nodeapp_folder );          
            foreach($files as $file) {

                // Get the name of the app from the filename
                if ( ! preg_match( '/\.config\.js$/', $file ) ) continue;
                $file = explode( '/', $file );
                $file = end( $file );
                $name = str_replace( '.config.js', '', $file );
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
         * Generate Nginx proxy settings for each .config.js file found in subfolders
         * and map the subfolder to a matching reverse proxy url of the same path
         */
        public function generate_nginx_files( $nodeapp_folder, $inc_root = true ) {
            global $hcpp;
            $parse = explode( '/', $nodeapp_folder );
            $user = $parse[2];
            $domain = $parse[4];
            $files = $this->get_config_files( $nodeapp_folder );

            // Remove prior nginx config files
            if ( file_exists( "/home/$user/conf/web/$domain/nginx.ssl.conf_nodeapp" ) ) {
                unlink( "/home/$user/conf/web/$domain/nginx.ssl.conf_nodeapp" );
            }
            if ( file_exists( "/home/$user/conf/web/$domain/nginx.conf_nodeapp" ) ) {
                unlink( "/home/$user/conf/web/$domain/nginx.conf_nodeapp" );
            }
            

            // Generate new nodeapp nginx config files
            $nginx = '';
            foreach($files as $file) {

                $subfolder = str_replace( "$nodeapp_folder/", '', $file );
                if ( strpos( $subfolder, '/' ) === false ) {
                    $subfolder = '/';
                }else{
                    $subfolder = '/' . $hcpp->delRightMost($subfolder, '/') . '/';
                }
                if ( $inc_root == false && $subfolder == '/' ) continue; // Skip root
                $app = $hcpp->getRightMost( $file, '/' );
                $app = str_replace( '.config.js', '', $app );
                $nginx .= 'location ' . $subfolder . ' {
                    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
                    proxy_set_header X-Forwarded-Proto $scheme;
                    proxy_set_header Host $host;
                    proxy_pass http://127.0.0.1:$' . $app . '_port;
                    proxy_http_version 1.1;
                    proxy_set_header Upgrade $http_upgrade;
                    proxy_set_header Connection "upgrade";
                }' . "\n";
            }

            // Write the nginx config nodeapp subfolder file to the user's conf folder
            if ($nginx != '') {
                $args = [
                    'user' => $user,
                    'domain' => $domain,
                    'nginx' => $nginx
                ];

                // Allow other plugins to modify the subfolder nginx config files
                $args = $hcpp->do_action( 'nodeapp_subfolder_nginx_conf', $args );
                $nginx = $args['nginx'];
                file_put_contents( "/home/$user/conf/web/$domain/nginx.conf_nodeapp", $nginx );

                $args = $hcpp->do_action( 'nodeapp_subfolder_nginx_ssl_conf', $args );
                $nginx = $args['nginx'];
                file_put_contents( "/home/$user/conf/web/$domain/nginx.ssl.conf_nodeapp", $nginx );
            }

            // Include port variables in nginx.hsts.conf_ports and nginx.forcessl.conf_ports
            $file = "/usr/local/hestia/data/hcpp/ports/$user/$domain.ports";
            if ( file_exists( $file ) ) {
                $content = "include /usr/local/hestia/data/hcpp/ports/$user/$domain.ports;";
                file_put_contents( "/home/$user/conf/web/$domain/nginx.forcessl.conf_ports", $content );
                file_put_contents( "/home/$user/conf/web/$domain/nginx.hsts.conf_ports", $content );
            }else{
                if ( file_exists( "/home/$user/conf/web/$domain/nginx.forcessl.conf_ports" ) ) {
                    unlink( "/home/$user/conf/web/$domain/nginx.forcessl.conf_ports" );
                }
                if ( file_exists( "/home/$user/conf/web/$domain/nginx.hsts.conf_ports" ) ) {
                    unlink( "/home/$user/conf/web/$domain/nginx.hsts.conf_ports" );
                }
            }
        }

        /**
         * Generate random alpha numeric for passwords, seeds, etc.
         *
         * @param int $length The length of characters to return.
         * @param string $chars The set of possible characters to choose from.
         * @return string The resulting randomly generated string.
         */
        public function random_chars( $length = 10, $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz1234567890' ) {
            $string = '';
            for ( $i = 0; $i < $length; $i++ ) {
                $string .= $chars[rand( 0, strlen( $chars ) - 1 )];
            }
            return $string;
        }

        /**
         * Scan the nodeapp folder for .config.js files and start the app for each
         */
        public function startup_apps( $nodeapp_folder, $inc_root = true ) {
            global $hcpp;
            $parse = explode( '/', $nodeapp_folder );
            $user = $parse[2];
            $domain = $parse[4];
            $files = $this->get_config_files( $nodeapp_folder );
            $cmd = 'runuser -s /bin/bash -l ' . $user . ' -c "cd \"' . $nodeapp_folder . '\" && export NVM_DIR=/opt/nvm && source /opt/nvm/nvm.sh ';
            foreach($files as $file) {
                
                // Skip the root app if inc_root is false
                if ( $inc_root == false ) {
                    $subfolder = str_replace( "$nodeapp_folder/", '', $file );
                    if ( strpos( $subfolder, '/' ) == false) continue;
                }

                // Add app to startup
                $cmd .= "; pm2 start $file ";
            }
            $cmd .= '"';
            if ( strpos( $cmd, '; pm2 start ' ) ) {
                $cmd .= "; pm2 save --force ";
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
         * Gather list of all running apps to delete and construct shutdown command,
         * instead of pm2 delete all; delete each one individually as filters may
         * alter the behavior to keep user and select domain apps running.
         */
        public function shutdown_apps( $nodeapp_folder ) {
            global $hcpp;
            $parse = explode( '/', $nodeapp_folder );
            $user = $parse[2];
            $domain = $parse[4];

            // Get list of apps to delete
            $cmd = 'runuser -s /bin/bash -l ' . $user . ' -c "export NVM_DIR=/opt/nvm && source /opt/nvm/nvm.sh ; pm2 ls | grep ' . $domain . '"';
            $lines = shell_exec( $cmd );
            $lines = explode( "\n", $lines );
            $cmd = 'runuser -s /bin/bash -l ' . $user . ' -c "cd \"' . $nodeapp_folder . '\" && export NVM_DIR=/opt/nvm && source /opt/nvm/nvm.sh ';
            foreach( $lines as $l ) {
                if ( strpos( $l, '-' . $domain ) === false ) continue;
                $app = $hcpp->getRightMost( $hcpp->getLeftMost( $l, '-' ), ' ' );
                $app = $app . '-' . $domain;

                // Add app to shutdown by name
                $cmd .= "; pm2 delete $app ";
            }
            if ( strpos( $cmd, '; pm2 delete ' ) ) {
                $cmd .= '; pm2 save --force "';
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

        /**
         * Gather a list of all valid PM2 configuration files that allocate ports from the given folder
         */
        public function get_config_files( $dir ) {
            global $hcpp;
            $configFiles = array();
            $files = scandir( $dir );
            foreach ( $files as $file ) {
                if ( $file == '.' || $file == '..' ) continue;
                $path = $dir . '/' . $file;
                if ( is_dir( $path ) && $file != "node_modules" ) {
                    $configFiles = array_merge( $configFiles, $this->get_config_files( $path ) );
                } else if ( preg_match('/\.config\.js$/', $file ) ) {

                    // Sanitize the name of the app to prevent Nginx injection
                    if ( ! preg_match( '/\.config\.js$/', $file ) ) continue;
                    $file = explode( '/', $file );
                    $file = end( $file );
                    $name = str_replace( '.config.js', '', $file );
                    $name = preg_replace( "/[^a-zA-Z0-9-_]+/", "", $name );
                    if ( $path == $dir . '/' . $name . '.config.js' ) {

                        // Check for hestia pm2 port allocating validity
                        if ( file_exists( $path ) && strpos( file_get_contents( $path ), '/usr/local/hestia') !== false ) {
                            $configFiles[] = $path;
                        }
                    }
                }
            }
            return $configFiles;
        }
    }
    new NodeApp();
}