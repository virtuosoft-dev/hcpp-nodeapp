#
# Serve our NodeJS based nodeapp at the base url
#

server {
    listen      %ip%:%proxy_port%;
    server_name %domain_idn% %alias_idn%;
    
    include %home%/%user%/conf/web/%domain%/nginx.forcessl.conf*;
    include /opt/hcpp/ports/%user%/%domain%.ports;
        
    error_log  /var/log/%web_system%/domains/%domain%.error.log error;
    client_max_body_size 512m;
    
    location / {
        proxy_pass http://127.0.0.1:$nodered_port;        
        location ~* ^.+\.(%proxy_extensions%)$ {
            root           %docroot%;
            access_log     /var/log/%web_system%/domains/%domain%.log combined;
            access_log     /var/log/%web_system%/domains/%domain%.bytes bytes;
            expires        max;
            try_files      $uri @fallback;
        }
    }

    location /error/ {
        alias   %home%/%user%/web/%domain%/document_errors/;
    }

    location @fallback {
        proxy_pass     http://127.0.0.1:$nodeapp_port;
    }

    location ~ /\.ht    {return 404;}
    location ~ /\.svn/  {return 404;}
    location ~ /\.git/  {return 404;}
    location ~ /\.hg/   {return 404;}
    location ~ /\.bzr/  {return 404;}
    location ~ /\.(?!well-known\/|file) {
       deny all;
       return 404;
    }

    include %home%/%user%/conf/web/%domain%/nginx.conf_*;
}
