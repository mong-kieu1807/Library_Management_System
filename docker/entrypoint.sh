#!/bin/sh
set -e

cd /var/www/html

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
         storage/framework/testing storage/logs storage/app/public storage/app/private \
         storage/app/backups bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

# /run is typically a fresh tmpfs at container start, so the chown baked into the
# image at build time (Dockerfile) won't have persisted for anything under it —
# redo it here for nginx's pid dir (client_body/proxy/... temp dirs live under
# /var/lib/nginx instead, which isn't remounted, so the build-time chown holds).
mkdir -p /run/nginx
chown -R www-data:www-data /run/nginx

# storage:link errors ("link already exists") if run twice — the image may already
# carry one from build, and containers can restart without a fresh filesystem.
[ -L public/storage ] || [ -e public/storage ] || php artisan storage:link

# Config/route/view caches are rebuilt on every container start (cheap) rather than
# at image build time, since production secrets (DB password, API keys, ...) are
# only injected by DO App Platform at runtime, not during `docker build`.
php artisan config:cache
php artisan route:cache
php artisan view:cache

if [ "$1" = "web" ]; then
    PORT="${PORT:-8080}"
    sed "s/\${PORT}/${PORT}/g" /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf
    exec supervisord -c /etc/supervisor/supervisord.conf
fi

exec "$@"
