<?php
/**
 * Extend the HestiaCP Pluginable object with our NodeApp object for
 * allocating NodeJS app ports and starting & stopping apps via PM2,
 * use multiple NodeJS versions via NVM, and auto-scan for .config.js
 * files; also adds PM2 NodeJS process list to the HestiaCP UI.
 * 
 * @author Virtuosoft/Stephen J. Carnam
 * @license AGPL-3.0, for other licensing options contact support@virtuosoft.com
 * @link https://github.com/virtuosoft-dev/hcpp-nodeapp
 * 
 */

if ( ! class_exists( 'NodeApp') ) {
    class NodeApp {

        /**
         * Scan the nodeapp folder for .config.js files and allocate a port for each
         */
        public function allocate_ports( $nodeapp_folder ) {
            global $hcpp;
            $hcpp->log( "allocate_ports: $nodeapp_folder" );
            $parse = explode( '/', $nodeapp_folder );
            $user = $parse[2];
            $domain = $parse[4];

            // Wipe the existing ports for this domain
            if ( file_exists( "/usr/local/hestia/data/hcpp/ports/$user/$domain.ports" ) ) {
                unlink( "/usr/local/hestia/data/hcpp/ports/$user/$domain.ports" );
            }

            // Allocate a port for each .config.js file found
            if ( ! is_dir( $nodeapp_folder ) ) {
                $hcpp->log( "allocate_ports: $nodeapp_folder is not a directory" );
                return;
            }
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

            // Throw a nodeapp event to allow other plugins to allocate ports
            $args = [
                'user' => $user,
                'domain' => $domain,
                'nodeapp_folder' => $nodeapp_folder
            ];
            $hcpp->do_action( 'nodeapp_ports_allocated', $args );
        }

        /**
         * Constructor, listen for the priv_change_web_domain_proxy_tpl event
         */
        public function __construct() {
            global $hcpp;
            $hcpp->add_action( 'v_change_web_domain_proxy_tpl', [ $this, 'v_change_web_domain_proxy_tpl'] );
            $hcpp->add_action( 'v_delete_web_domain_backend', [ $this, 'v_delete_web_domain_backend' ] );
            $hcpp->add_action( 'v_delete_web_domain', [ $this, 'v_delete_web_domain_backend' ] );
            $hcpp->add_action( 'v_suspend_web_domain', [ $this, 'v_suspend_web_domain' ] );
            $hcpp->add_action( 'v_unsuspend_web_domain', [ $this, 'v_unsuspend_domain' ] ); // Bulk unsuspend domains only throws this event
            $hcpp->add_action( 'v_unsuspend_domain', [ $this, 'v_unsuspend_domain' ] ); // Individually unsuspend domain only throws this event
            $hcpp->add_action( 'v_update_sys_queue', [ $this, 'v_update_sys_queue' ] );
            $hcpp->add_action( 'hcpp_invoke_plugin', [ $this, 'hcpp_invoke_plugin' ] );
            $hcpp->add_action( 'hcpp_list_web_xpath', [ $this, 'hcpp_list_web_xpath' ] );
            $hcpp->add_action( 'hcpp_list_updates_xpath', [ $this, 'hcpp_list_updates_xpath' ] );
            $hcpp->add_action( 'hcpp_add_webapp_xpath', [ $this, 'hcpp_add_webapp_xpath' ] );
            $hcpp->add_action( 'hcpp_rebooted', [ $this, 'hcpp_rebooted' ] );
            $hcpp->add_action( 'hcpp_runuser', [ $this, 'hcpp_runuser' ] );
            $hcpp->add_action( 'v_restart_proxy', [ $this, 'v_restart_proxy'] );
            $hcpp->add_custom_page( 'nodeapp', __DIR__ . '/pages/nodeapp.php' );
            $hcpp->add_custom_page( 'nodeapplog', __DIR__ . '/pages/nodeapplog.php' );
        }

        /**
         * Do maintenance will shutdown all running apps, across all users that are
         * using the specified major version of NodeJS and invoke the given callback.
         * Lastly, after the callback has returned, the apps will be restarted.
         * 
         * @param callable $cb_maintenance The callback to invoke after all apps have been shutdown, a [user=>apps] array will be passed of stopped apps.
         * @param array $majors The list of major nodejs versions to perform maintenance on; leave empty to shutdown apps from all versions.
         * @param array $apps The list of apps to perform maintenance on; leave empty to shutdown all apps.
         */
        public function do_maintenance( $cb_maintenance = null, $majors = [], $apps = [] ) {

            // Check if majors array is empty and get all majors as default
            global $hcpp;
            $hcpp->log( 'nodeapp do_maintenance' );
            $hcpp->log( $majors );

            if ( count( $majors ) == 0 ) {
                $versions = $this->get_versions();
                foreach( $versions as $v ) {
                    $majors[] = $hcpp->getLeftMost( $v['installed'], '.' );
                }
            }

            // Get user list of PM2 (bash) capable users
            $all = $hcpp->run( 'v-list-users json' );
            $users = [];
            foreach( $all as $user => $details ) {
                if ( $details['SHELL'] == 'bash' ) {
                    $users[] = $user;
                }
            }

            // Find any running apps using the major version across all users
            $pm2_list = [];
            foreach( $users as $user) {
                $pm2 = $this->get_pm2_list( $user );
                if ( is_null( $pm2 ) ) continue;
                foreach( $pm2 as $p ) {
                    if ( $p['pm2_env']['status'] == 'online' ) {
                        $version = $p['pm2_env']['exec_interpreter'];
                        $version = $hcpp->getRightMost( $version, '/v' );
                        $version = $hcpp->getLeftMost( $version, '/' );
                        $major = $hcpp->getLeftMost( $version, '.' );
                        if ( in_array( $major, $majors ) ) {
                            if ( count( $apps ) == 0 ) {
                                $pm2_list[$user][] = $p['pm_id'];
                            }else{

                                // Filter by app name i.e. 'ghost' in 'ghost | test1.dev.pw'
                                $name = $hcpp->getLeftMost( $p['name'], ' | ' );
                                if ( in_array( $name, $apps ) ) {
                                    $pm2_list[$user][] = $p['pm_id'];
                                }
                            }
                        }
                    }
                }
            }

            // Shutdown all apps by id for each user
            foreach( $pm2_list as $user => $app_ids ) {
                if ( count( $app_ids ) > 0 ) {
                    $this->stop_pm2_ids( $app_ids, $user );
                }
            }

            // Invoke the maintenance callback
            if ( is_callable( $cb_maintenance ) ) {
                $cb_maintenance( $pm2_list );
            }

            // Restart the stopped apps by id for each user
            foreach( $pm2_list as $user => $app_ids ) {
                if ( count( $app_ids ) > 0 ) {
                    $this->restart_pm2_ids( $app_ids, $user );
                }
            }
        }

        /**
         * Throw the nodeapp_nginx_modified event to allow other plugins to modify the nginx config files
         */
        public function do_nginx_modified( $restart = false ) {
            global $hcpp;
            $hcpp->log( "do_nginx_modified with restart: $restart" );
            $lines = file( "/tmp/nodeapp_nginx_modified" );
            unlink( "/tmp/nodeapp_nginx_modified" );

            // Remove any duplicate lines
            $lines = array_unique( $lines );
            $conf_folders = [];
            foreach( $lines as $line ) {
                $line = explode( ' ', $line );
                $user = $line[0];
                $domain = $line[1];
                $conf_folders[] = trim( "/home/$user/conf/web/$domain" );
            }
            $conf_folders = $hcpp->do_action( "nodeapp_nginx_confs_written", $conf_folders );
            if ( $restart ) {
                $hcpp->run( "v-restart-proxy" );
            }
        }

        /**
         * Delete the PM2 apps for the given user by ids.
         * 
         * @param array $pm2_ids The list of PM2 process ids to delete
         */
        public function delete_pm2_ids( $pm2_ids ) {
            $username = $_SESSION["user"];
            if ($_SESSION["look"] != "") {
                $username = $_SESSION["look"];
            }
		    global $hcpp;
            $pm2_ids = escapeshellarg( json_encode( $pm2_ids ) );
		    $list = json_decode( $hcpp->run("v-invoke-plugin nodeapp_delete_pm2_ids " . $username . ' ' . $pm2_ids ), true );
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

            // Write port variables in nginx.hsts.conf_ports and nginx.forcessl.conf_ports
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

                // Allow other plugins to modify nginx conf_nodeapp files
                $args = $hcpp->do_action( 'nodeapp_write_conf_nodeapp', $args );
                $nginx = $args['nginx'];
                file_put_contents( "/home/$user/conf/web/$domain/nginx.conf_nodeapp", $nginx );
                
                // Overrite the proxy_hide_header in the SSL config file
                $nginx .= "# Override prev. proxy_hide_header Upgrade\nadd_header Upgrade \$http_upgrade always;";
                $args['nginx'] = $nginx;
                $args = $hcpp->do_action( 'nodeapp_write_ssl_conf_nodeapp', $args );
                $nginx = $args['nginx'];
                file_put_contents( "/home/$user/conf/web/$domain/nginx.ssl.conf_nodeapp", $nginx );
            }

            // Queue for single nginx files modified event
            file_put_contents("/tmp/nodeapp_nginx_modified", "$user $domain\n", FILE_APPEND);
        }

        /**
         * Get the list of PM2 processes for the given user
         * 
         * @param string $user The username to get the PM2 process list for or the current HestiaCP user if not provided
         * @return array The list of PM2 processes for the given user
         */
        public function get_pm2_list( $user = '""' ) {
            if ( $user == '""' && isset( $_SESSION ) ) {
                $user = $_SESSION['user'];
                if ( $_SESSION['look'] != '' ) {
                    $user = $_SESSION['look'];
                }
            }
		    global $hcpp;
            $list = $hcpp->run("v-invoke-plugin nodeapp_pm2_jlist " . $user);
            $list = '[' . $hcpp->delLeftMost( $list, '[' );
            $list = json_decode( $list, true );
		    return $list;
        }
        
        /**
         * Get the log for the given PM2 process under the given user
         */
        public function get_pm2_log( $pm2_id ) {
            $username = $_SESSION["user"];
            if ($_SESSION["look"] != "") {
                $username = $_SESSION["look"];
            }
		    global $hcpp;
		    $log = $hcpp->run("v-invoke-plugin nodeapp_pm2_log " . $username . ' ' . $pm2_id );
            return $log;
        }

        /**
         * Get the installed and latest versions of nvm managed NodeJS installs.
         */
        public function get_versions() {
            global $hcpp;
            $list = $hcpp->runuser( '', "nvm list --no-colors" );
            $list = explode( "\n", $list );
            $installed = [];
            $latest = [];
            $majors = [];
            foreach( $list as $line ) {
              if ( strpos( $line, 'lts/' ) !== false ) {
                $line = $hcpp->getRightMost( $line, ' v' );
                $line = $hcpp->getLeftMost( $line, ' ' );
                $major = $hcpp->getLeftMost( $line, '.' );
                if ( in_array( $major, $majors ) ) {
                  if( !in_array( $line, $latest ) ) {
                    $latest[] = $line;
                  }  
                }
              }else{
                if ( strpos( $line, '*' ) !== false ) {
                  $line = $hcpp->getRightMost( $line, ' v' );
                  $line = $hcpp->getLeftMost( $line, ' ' );
                  if ( !in_array( $line, $installed ) ) {
                    $installed[] = $line;
                    $majors[] = $hcpp->getLeftMost( $line, '.' );
                  }
                }
              }
            }
            // sort latest
            usort( $latest, function( $a, $b ) {
              return version_compare( $a, $b );
            });
            $versions = [];
            $i = 0;
            foreach( $installed as $version ) {
              $versions[] = [
                'installed' => $version,
                'latest' => $latest[$i]
              ];
              $i++;
            }
            return $versions;
        }

        /**
         * Restart proxy on modified nginx config files
         */
        public function hcpp_add_webapp_xpath( $xpath ) {
            global $hcpp;
            if ( file_exists( "/tmp/nodeapp_nginx_modified" ) ) {
                $hcpp->run( "v-invoke-plugin nodeapp_nginx_modified" );
            }
            return $xpath;
        }

        /**
         * Process the PM2 list command request
         */
        public function hcpp_invoke_plugin( $args ) {
            global $hcpp;
            if ( count( $args ) < 2 ) return $args;
            $username = preg_replace( "/[^a-zA-Z0-9-_]+/", "", $args[1] ); // Sanitized username
            switch ( $args[0] ) {
                case 'nodeapp_get_versions':
                    $hcpp->log( 'nodeapp_get_versions' );
                    echo json_encode( $this->get_versions() );
                    break;
                case 'nodeapp_pm2_jlist':
                    echo $hcpp->runuser( $username, 'pm2 jlist' );
                    break;

                case 'nodeapp_delete_pm2_ids':
                    try {
                        $pm2_ids = json_decode( $args[2], true );
                    }catch( Exception $e ) {
                        $pm2_ids = [];
                    }
                    $cmd = '';
                    foreach( $pm2_ids as $id ) {
                        $cmd .= 'pm2 delete ' . $id . '; ';
                    }
                    $cmd .= 'pm2 save --force';
                    $hcpp->runuser( $username, $cmd );
                    break;
                case 'nodeapp_stop_pm2_ids':
                    try {
                        $pm2_ids = json_decode( $args[2], true );
                    }catch( Exception $e ) {
                        $pm2_ids = [];
                    }
                    $cmd = '';
                    foreach( $pm2_ids as $id ) {
                        $cmd .= 'pm2 stop ' . $id . '; pm2 reset ' . $id . '; ';
                    }
                    $cmd .= 'pm2 save --force';
                    $hcpp->runuser( $username, $cmd );

                    // Force remove the PM2 logs 
                    $error_log_path = $hcpp->runuser( $username, 'pm2 info ' . $id . ' | grep "error log path"' );
                    $error_log_path = '/' . $hcpp->delLeftMost( $error_log_path, '/' );
                    $error_log_path = $hcpp->delRightMost( $error_log_path, '.log' ) . '.log';
                    $out_log_path = str_replace( '-error.log', '-out.log', $error_log_path );
                    $cmd = 'rm -f ' . $error_log_path . '; ' . 'rm -f ' . $out_log_path;
                    $hcpp->runuser( $username, $cmd );
                    break;

                case 'nodeapp_restart_pm2_ids':
                    try {
                        $pm2_ids = json_decode( $args[2], true );
                    }catch( Exception $e ) {
                        $pm2_ids = [];
                    }
                    
                    // Restart via config.js filename; find by pm2_id
                    $list = $hcpp->runuser( $username, 'pm2 jlist' );
                    $list = '[' . $hcpp->delLeftMost( $list, '[' );
                    $list = json_decode( $list, true );
                    $cmd = '';
                    foreach( $pm2_ids as $id ) {
                        foreach( $list as $app ) {
                            if ( $app['pm_id'] == $id ) {

                                // Get the config.js file based on the script file
                                $config = $hcpp->getLeftMost( $app['name'], ' | ' );
                                $config = $app['pm2_env']['cwd'] . '/' . $config . '.config.js';
                                if ( $config != '' ) {
                                    $cmd .= 'pm2 restart ' . $config . '; ';
                                }
                            }
                        }
                    }
                    $cmd .= 'pm2 save --force';
                    $hcpp->runuser( $username, $cmd );
                    break;

                case 'nodeapp_pm2_log':
                    $pm2_id = $args[2];
                    $pm2_id = filter_var( $pm2_id, FILTER_SANITIZE_NUMBER_INT );
                    echo $hcpp->runuser( $username, 'pm2 logs --lines 4096 --nostream --raw --no-color ' . $pm2_id . ' 2>&1' );
                    break;

                case 'nodeapp_nginx_modified':

                    // Debounce allows use to queue and delay under higher loads
                    shell_exec( "nohup " . __DIR__ . "/nodeapp_debounce.sh > /dev/null 2>&1 &" );
                    break;

                case 'nodeapp_debounce':
                    if ( file_exists( "/tmp/nodeapp_nginx_modified" ) ) {
                        $this->do_nginx_modified( true );
                    }
                    break;
            }
            return $args;
        }

        public function hcpp_list_updates_xpath( $xpath ) {
            global $hcpp;
            $query = '//div[contains(@class, "units-table-row") and .//div[contains(@class, "units-table-cell") and contains(normalize-space(.), "hcpp-nodeapp")]]';

            // Add the CSS to the head of the document
            $css = 'div.units-table-cell .sub-unit {float:left;margin:-3px 5px 0px 15px;}';
            $head = $xpath->query('//head')->item(0);
            $style = $xpath->document->createElement('style', $css);
            $head->appendChild($style);

            // Inject list of NodeJS versions below hcpp-nodeapp in the updates list
            $nodes = $xpath->query($query);          
            if ($nodes->length > 0) {

                // Get the list of NodeJS versions from the cache
                $versions = $hcpp->run( 'v-invoke-plugin nodeapp_get_versions json' );
                $firstNode = $nodes->item(0);
                $arch = php_uname('m') == 'x86_64' ? 'amd64' : (php_uname('m') == 'aarch64' ? 'arm64' : 'unknown');
                $html = '';
                foreach ( $versions as $v ) {
                    $installed = $v['installed'];
                    $latest = $v['latest'];
                    $major = $hcpp->getLeftMost( $installed, '.' );
                    if ( $installed != $latest ) {
                        $icon = '<i class="fas fa-triangle-exclamation icon-orange" title="Update available"></i>';
                        $disabled = 'disabled ';
                        $notice = '(update ' . $latest . ' available)';
                    }else{
                        $icon = '<i class="fas fa-check-circle icon-green" title="Package up-to-date"></i>';
                        $disabled = '';
                        $notice = '';
                    }
                    $html .= '<div class="units-table-row ' . $disabled . 'js-unit">
                        <div class="units-table-cell units-table-heading-cell u-text-bold">
                            <span class="u-hide-desktop">Package Names:</span>
                            <div class="sub-unit">&#8627;</div>
                            nvm-nodejs-v' . $major . '
                        </div>
                        <div class="units-table-cell">
                            <span class="u-hide-desktop u-text-bold">Description:</span>
                            NodeJS v' . $major . ' runtime ' . $notice . '
                        </div>
                        <div class="units-table-cell u-text-center-desktop">
                            <span class="u-hide-desktop u-text-bold">Version:</span>
                            ' . $installed . ' (' . $arch . ')
                        </div>
                        <div class="units-table-cell u-text-center-desktop">
                            <span class="u-hide-desktop u-text-bold">Status:</span>
                            ' . $icon . '
                        </div>
                    </div>';

                }

                // Create a and insert it after the first found node
                $fragment = $xpath->document->createDocumentFragment();
                $fragment->appendXML($html);
                $firstNode->parentNode->insertBefore($fragment, $firstNode->nextSibling);
            } else {
                $hcpp->log("hcpp-nodeapp was not found in updates");
            }

            return $xpath;
        }

        /**
         * Modify runuser to incorporate NVM
         */
        public function hcpp_runuser( $args ) {
            $cmd = $args[1];
            $cmd = 'export NVM_DIR=/opt/nvm && source /opt/nvm/nvm.sh && ' . $cmd;
            $args[1] = $cmd;
            return $args;
        }

        /**
         * Check if system has rebooted and restart apps
         */
        public function hcpp_rebooted() {

            // Wait up to 60 additional seconds for MySQL to start
            $i = 0;
            while ( $i < 60 ) {
                $i++;
                $mysql = shell_exec( 'systemctl is-active mysql' );
                if ( trim( $mysql ) == 'active' ) {
                    break;
                }
                sleep( 1 );
            }

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
         * Add the PM2 process list button to the HestiaCP UI, and restart proxy on modified nginx config files
         */
        public function hcpp_list_web_xpath( $xpath ) {

            // Check that user has bash shell access needed for PM2
            global $hcpp;
            $username = $_SESSION["user"];
            if ($_SESSION["look"] != "") {
                $username = $_SESSION["look"];
            }
            $detail = $hcpp->run( "v-list-user $username json" );
            if ( isset( $detail[$username]['SHELL'] ) && $detail[$username]['SHELL'] == 'bash' ) {
                // Locate the 'Add Web Domain' button
                $addWebButton = $xpath->query( "//a[@href='/add/web/']" )->item(0);

                if ( $addWebButton ) {

                    // Create a new button element
                    $newButton = $xpath->document->createElement( 'a' );
                    $newButton->setAttribute( 'href', '?p=nodeapp' );
                    $newButton->setAttribute( 'class', 'button button-secondary' );
                    $newButton->setAttribute( 'title', 'NodaApps' );

                    // Create the icon element
                    $icon = $xpath->document->createElement('span', '&#11042;');
                    $icon->setAttribute('style', 'font-size:x-large;color:green;margin:-2px 4px 0 0;');

                    // Create the text node
                    $text = $xpath->document->createTextNode( 'NodeApps' );

                    // Append the icon and text to the new button
                    $newButton->appendChild( $icon );
                    $newButton->appendChild( $text );

                    // Insert the new button next to the existing one
                    $addWebButton->parentNode->insertBefore( $newButton, $addWebButton->nextSibling );
                }
            }
            if ( file_exists( "/tmp/nodeapp_nginx_modified" ) ) {
                $hcpp->run( "v-invoke-plugin nodeapp_nginx_modified" );
            }
            return $xpath;
        }

        /**
         * Restart the PM2 apps for the given user by ids.
         * 
         * @param array $pm2_ids The list of PM2 process ids to restart
         * @param string $user The username to restart the PM2 processes for or the current HestiaCP user if not provided
         */
        public function restart_pm2_ids( $pm2_ids, $user = '""' ) {
            if ( $user == '""' && isset( $_SESSION ) ) {
                $user = $_SESSION['user'];
                if ( $_SESSION['look'] != '' ) {
                    $user = $_SESSION['look'];
                }
            }
		    global $hcpp;
            $hcpp->log( 'nodeapp restart_pm2_ids for user: ' . $user );
            $hcpp->log( $pm2_ids );
            $pm2_ids = escapeshellarg( json_encode( $pm2_ids ) );
		    $hcpp->run("v-invoke-plugin nodeapp_restart_pm2_ids " . $user . ' ' . $pm2_ids );
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
            $cmd = 'cd "' . $nodeapp_folder . '" ';
            foreach($files as $file) {
                
                // Skip the root app if inc_root is false
                if ( $inc_root == false ) {
                    $subfolder = str_replace( "$nodeapp_folder/", '', $file );
                    if ( strpos( $subfolder, '/' ) == false) continue;
                }

                // Add app to startup
                $cmd .= "; pm2 start $file ";
            }

            
            if ( strpos( $cmd, '; pm2 start ' ) ) {
                $cmd .= '; pm2 save --force ';
                $args = [
                    'user' => $user,
                    'domain' => $domain,
                    'cmd' => $cmd
                ];

                // Run the command to start all the apps
                $args = $hcpp->do_action( 'nodeapp_startup_services', $args );
                $hcpp->runuser( $user, $args['cmd'] );
            }
        }

        /**
         * Stop the PM2 apps for the given user by ids.
         * 
         * @param array $pm2_ids The list of PM2 process ids to stop
         * @param string $user The username to stop the PM2 processes for or the current HestiaCP user if not provided
         */
        public function stop_pm2_ids( $pm2_ids, $user = '""' ) {
            if ( $user == '""' && isset( $_SESSION ) ) {
                $user = $_SESSION['user'];
                if ( $_SESSION['look'] != '' ) {
                    $user = $_SESSION['look'];
                }
            }
		    global $hcpp;
            $hcpp->log( 'nodeapp stop_pm2_ids for user: ' . $user );
            $hcpp->log( $pm2_ids );
            $pm2_ids = escapeshellarg( json_encode( $pm2_ids ) );
		    $hcpp->run("v-invoke-plugin nodeapp_stop_pm2_ids " . $user . ' ' . $pm2_ids );
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
            $cmd = 'pm2 ls | grep "' . $domain . '"';
            $lines = $hcpp->runuser( $user, $cmd );
            $lines = explode( "\n", $lines );
            $cmd = 'cd "' . $nodeapp_folder . '" ';
            foreach( $lines as $l ) {
                if ( strpos( $l, '-' . $domain ) === false ) continue;
                $app = $hcpp->getRightMost( $hcpp->getLeftMost( $l, '-' ), ' ' );
                $app = $app . '-' . $domain;

                // Add app to shutdown by name
                $cmd .= "; pm2 delete $app ";
            }
            if ( strpos( $cmd, '; pm2 delete ' ) ) {
                $cmd .= '; pm2 save --force ';
                $args = [
                    'user' => $user,
                    'domain' => $domain,
                    'cmd' => $cmd
                ];

                // Run the command to shutdown all the apps
                $args = $hcpp->do_action( 'nodeapp_shutdown_services', $args );
                $hcpp->runuser( $user, $args['cmd'] );
            }            
        }

        /**
         * Update all NVM managed NodeJS versions and global packages;
         * gracefully stop all running PM2 apps using the major version,
         * update NodeJS, and restart the stopped apps for each user.
         */
        public function update_all() {

            // Get list of new versions
            global $hcpp;
            $versions = $this->get_versions();
            $updates = [];
            foreach( $versions as $v ) {

                // Find any nodejs versions that need to be updated
                if ( $v['installed'] != $v['latest'] ) {
                    $updates[] = $v;
                    $majors[] = $hcpp->getLeftMost( $v['installed'], '.' );
                }
            }

            // Perform maintenance only if updates are available
            if ( count( $updates ) == 0 ) {
                return;
            }

            // Perform the maintenance tasks
            $this->do_maintenance( function( $stopped ) use ( $hcpp, $updates ) {
                // Update NodeJS, npm, and re-install global packages
                foreach( $updates as $v ) {
                    $cmd = 'nvm install ' . $v['latest'] . ' && nvm use ' . $v['latest'] . ' && npm install -g npm';
                    $cmd .= ' && nvm reinstall-packages ' . $v['installed'];
                    $cmd .= ' && nvm uninstall ' . $v['installed'];
                    $cmd = $hcpp->do_action( 'nodeapp_update_nodejs', $cmd );
                    $hcpp->runuser( '', $cmd );
                }
            }, $majors );
        }

        /**
         * On proxy template change, copy basic nodeapp, allocate ports, and start apps
         */
        public function v_change_web_domain_proxy_tpl( $args ) {
            global $hcpp;
            $user = $args[0];
            $domain = $args[1];
            $proxy = $args[2];
            $nodeapp_folder = "/home/$user/web/$domain/nodeapp";

            if ( !is_dir( $nodeapp_folder) && $proxy == 'NodeApp' ) {

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
                chmod( $nodeapp_folder, 0755 );

                // Install dependencies
                $cmd = 'cd "' . $nodeapp_folder . '" && npm install';
                $args['cmd'] = $cmd;
                $args = $hcpp->do_action( 'nodeapp_install_dependencies', $args );
                $hcpp->runuser( $user, $args['cmd'] );
            }

            // Shutdown stray apps and startup root and/or subfolder apps
            $this->shutdown_apps( $nodeapp_folder );
            $this->allocate_ports( $nodeapp_folder );
            $this->generate_nginx_files( $nodeapp_folder, ($proxy == 'NodeApp') );
            $this->startup_apps( $nodeapp_folder,  ($proxy == 'NodeApp') );
        }

        /**
         * On domain delete, shutdown apps
         */
        public function v_delete_web_domain_backend( $args ) {
            global $hcpp;
            $user = $args[0];
            $domain = $args[1];
            $nodeapp_folder = "/home/$user/web/$domain/nodeapp";
            $this->shutdown_apps( $nodeapp_folder );
            unlink( "/usr/local/hestia/data/hcpp/ports/$user/$domain.ports" );
        }

        /**
         * Notify of any changes to the nginx config files
         */
        public function v_restart_proxy( $args ) {
            if ( file_exists( '/tmp/nodeapp_nginx_modified' ) ) {
                $this->do_nginx_modified( false );
            }
            return $args;
        }

        /**
         * On domain suspend, shutdown apps
         */
        public function v_suspend_web_domain( $args ) {
            global $hcpp;
            $user = $args[0];
            $domain = $args[1];
            $nodeapp_folder = "/home/$user/web/$domain/nodeapp";

            // Remove prior nginx config files; duplicate location "/" will cause nginx to fail
            if ( file_exists( "/home/$user/conf/web/$domain/nginx.ssl.conf_nodeapp" ) ) {
                unlink( "/home/$user/conf/web/$domain/nginx.ssl.conf_nodeapp" );
            }
            if ( file_exists( "/home/$user/conf/web/$domain/nginx.conf_nodeapp" ) ) {
                unlink( "/home/$user/conf/web/$domain/nginx.conf_nodeapp" );
            }
            $this->shutdown_apps( $nodeapp_folder );
            return $args;
        }

        /**
         * On domain unsuspend, startup apps
         */
        public function v_unsuspend_domain( $args ) {
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
         * Check daily to update our NVM managed and installed NodeJS versions global packages.
         */
        public function v_update_sys_queue( $args ) {
            global $hcpp;
            if ( ! (isset( $args[0] ) && trim( $args[0] ) == 'daily') ) return $args;
            if ( strpos( $hcpp->run('v-list-sys-hestia-autoupdate'), 'Enabled') == false ) return $args;
            $this->update_all();
            return $args;
        }
    }
    global $hcpp;
    $hcpp->register_plugin( NodeApp::class );
}