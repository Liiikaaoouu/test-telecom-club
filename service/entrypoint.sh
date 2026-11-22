#!/bin/sh
set -e

if [ "${JOB_INTERVAL_MINUTES:-0}" != "0" ]; then
  php /app/scripts/cron_loop.php &
fi

exec php -S 0.0.0.0:${PORT:-8080} /app/public/router.php
