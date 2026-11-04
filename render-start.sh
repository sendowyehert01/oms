#!/bin/sh
set -eu

: "${DB_URL:?Set DB_URL to the Render PostgreSQL internal connection string.}"

if [ -z "${APP_KEY:-}" ]; then
    : "${APP_KEY_MATERIAL:?Set APP_KEY or provide a generated APP_KEY_MATERIAL secret.}"
    export APP_KEY="base64:${APP_KEY_MATERIAL}"
fi

export APP_URL="${APP_URL:-${RENDER_EXTERNAL_URL:-http://localhost:${PORT:-10000}}}"

su -s /bin/sh www-data -c 'php artisan config:clear --no-interaction'
su -s /bin/sh www-data -c 'php artisan migrate --force --no-interaction'
if [ -n "${OMS_ADMIN_EMAIL:-}" ]; then
    su -s /bin/sh www-data -c 'php artisan oms:create-admin --from-env --no-interaction'
fi
su -s /bin/sh www-data -c 'php artisan config:cache --no-interaction'
su -s /bin/sh www-data -c 'php artisan route:cache --no-interaction'
su -s /bin/sh www-data -c 'php artisan view:cache --no-interaction'

exec "$@"
