# nginx

Most shared hosts run Apache or LiteSpeed, which read the `.htaccess` the installer writes. If yours is plain nginx (or nginx without `.htaccess` support), add the rules below to the server block. Without them **your private data folder is readable from the web**: the installer and *System → Self-test* check for this and warn you.

> These rules mirror the generated `.htaccess` but were not exercised by the automated tests, which run on Apache. Check *System → Self-test* after adding them.

Folder mode, launcher at `/demos/` in a docroot of `/home/user/public_html`:

```nginx
# Private folders
location ^~ /demos/wpl-data/ { deny all; }
location ^~ /demos/app/      { deny all; }
location = /demos/cron.php   { deny all; }

# Uploaded media is never executable
location ~ ^/demos/wpl-uploads/.*\.(php[0-9]?|phtml|phar|pht|cgi|pl|py|sh)$ { deny all; }

location /demos/ {
    index index.php;
    rewrite ^/demos/s/([a-z0-9]{10})/wp-admin$ /demos/s/$1/wp-admin/ permanent;
    rewrite ^/demos/s/([a-z0-9]{10})/wp-content/uploads/(.*)$ /demos/wpl-uploads/$1/$2 last;
    rewrite ^/demos/s/[a-z0-9]{10}/_c/([a-z0-9-]+)/(.*)$ /demos/wpl-content/$1/$2 last;
    rewrite ^/demos/s/[a-z0-9]{10}/((wp-admin|wp-includes)/.*)$ /demos/wpl-core/$1 last;
    rewrite ^/demos/s/[a-z0-9]{10}/([A-Za-z0-9_-]+\.php)$ /demos/wpl-core/$1 last;
    rewrite ^/demos/s/[a-z0-9]{10}(/.*)?$ /demos/wpl-core/index.php last;
    try_files $uri $uri/ /demos/index.php?$args;
}

location ~ \.php$ {
    try_files $uri =404;
    include fastcgi_params;
    # REQUEST_URI must stay the ORIGINAL URI: that is how WordPress knows
    # which demo it is serving. The stock fastcgi_params already does this.
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass unix:/run/php/php-fpm.sock;
}
```

For subdomain mode, put the same `rewrite`s in a `server` block for `~^[a-z0-9]{10}\.demo\.example\.com$` whose `root` is the launcher folder, without the `/demos` prefix and without the `s/<slug>` part (for example `rewrite ^/wp-content/uploads/(.*)$ /wpl-uploads/$slug/$1 last;`, capturing `$slug` from the host with a named regex in `server_name`).
