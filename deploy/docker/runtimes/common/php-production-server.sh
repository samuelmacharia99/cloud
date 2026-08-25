#!/bin/sh
# Production PHP front door: nginx + php-fpm (several workers).
# Falls back to php -S only when this image was built without nginx/php-fpm.
set -e

PORT="${1:-${PORT:-8000}}"
DOCROOT="${2:-${DOCUMENT_ROOT:-}}"

if [ -z "$DOCROOT" ]; then
    if [ -f /app/public/index.php ]; then
        DOCROOT=/app/public
    elif [ -d /app/public ]; then
        DOCROOT=/app/public
    else
        DOCROOT=/app
    fi
fi

if ! command -v nginx >/dev/null 2>&1 || ! command -v php-fpm >/dev/null 2>&1; then
    echo "Talksasa: nginx/php-fpm unavailable, falling back to php -S (single-threaded)"
    if [ -f "${DOCROOT}/index.php" ]; then
        exec php -S "0.0.0.0:${PORT}" -t "$DOCROOT" "${DOCROOT}/index.php"
    fi
    exec php -S "0.0.0.0:${PORT}" -t "$DOCROOT"
fi

TMP=/tmp/talksasa-php
mkdir -p "$TMP/body" "$TMP/proxy" "$TMP/fastcgi" "$TMP/uwsgi" "$TMP/scgi"

mem_bytes=0
if [ -f /sys/fs/cgroup/memory.max ]; then
    max=$(cat /sys/fs/cgroup/memory.max 2>/dev/null || echo max)
    if [ "$max" != "max" ] && [ -n "$max" ]; then
        mem_bytes=$max
    fi
elif [ -f /sys/fs/cgroup/memory/memory.limit_in_bytes ]; then
    mem_bytes=$(cat /sys/fs/cgroup/memory/memory.limit_in_bytes 2>/dev/null || echo 0)
fi

mem_mb=512
if [ "$mem_bytes" -gt 0 ] 2>/dev/null && [ "$mem_bytes" -lt 137438953472 ]; then
    mem_mb=$((mem_bytes / 1024 / 1024))
fi
if [ "$mem_mb" -lt 64 ]; then
    mem_mb=64
fi

# Roughly 80MB RSS per PHP worker on a typical Laravel app.
children=$((mem_mb / 80))
if [ "$children" -lt 2 ]; then
    children=2
fi
if [ "$children" -gt 16 ]; then
    children=16
fi
start=$((children / 2))
if [ "$start" -lt 2 ]; then
    start=2
fi
if [ "$start" -gt "$children" ]; then
    start=$children
fi

cat > "$TMP/php-fpm.conf" <<EOF
[global]
pid = $TMP/php-fpm.pid
error_log = /proc/self/fd/2
daemonize = no
emergency_restart_threshold = 10
emergency_restart_interval = 1m
process_control_timeout = 10s

[www]
listen = 127.0.0.1:9000
pm = dynamic
pm.max_children = $children
pm.start_servers = $start
pm.min_spare_servers = 1
pm.max_spare_servers = $start
pm.max_requests = 500
clear_env = no
catch_workers_output = yes
decorate_workers_output = no
php_admin_value[upload_max_filesize] = 100M
php_admin_value[post_max_size] = 100M
php_admin_flag[display_errors] = off
EOF

cat > "$TMP/nginx.conf" <<EOF
worker_processes auto;
error_log /proc/self/fd/2 warn;
pid $TMP/nginx.pid;
daemon off;

events {
    worker_connections 1024;
}

http {
    include /etc/nginx/mime.types;
    default_type application/octet-stream;
    access_log /proc/self/fd/1 combined;
    client_body_temp_path $TMP/body;
    proxy_temp_path $TMP/proxy;
    fastcgi_temp_path $TMP/fastcgi;
    uwsgi_temp_path $TMP/uwsgi;
    scgi_temp_path $TMP/scgi;
    client_max_body_size 100M;
    sendfile on;
    keepalive_timeout 65;
    gzip on;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml;

    server {
        listen ${PORT} default_server;
        listen [::]:${PORT} default_server;
        root ${DOCROOT};
        index index.php index.html;
        server_tokens off;

        location / {
            try_files \$uri \$uri/ /index.php?\$query_string;
        }

        location ~ \.php\$ {
            include fastcgi_params;
            fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
            fastcgi_param DOCUMENT_ROOT \$document_root;
            fastcgi_pass 127.0.0.1:9000;
            fastcgi_read_timeout 300;
            fastcgi_buffers 16 16k;
            fastcgi_buffer_size 32k;
        }

        location ~ /\.(?!well-known).* {
            deny all;
        }
    }
}
EOF

echo "Talksasa: starting nginx + php-fpm on :${PORT} (docroot ${DOCROOT}, ${children} PHP workers)"
php-fpm -y "$TMP/php-fpm.conf" &
exec nginx -c "$TMP/nginx.conf"
