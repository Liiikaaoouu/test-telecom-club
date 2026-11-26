FROM php:8.2-cli

# the official php images ship pdo_sqlite/sqlite3/curl enabled; fail the
# build early (not the container) if that ever changes
RUN php -r 'foreach (["pdo_sqlite","sqlite3","curl"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "missing extension: $e\n"); exit(1); } }'

WORKDIR /app

# Never print warnings into an HTTP response (see php.ini).
COPY php.ini /usr/local/etc/php/conf.d/zz-errors.ini

COPY . .
RUN mkdir -p /app/data

ENV PHP_CLI_SERVER_WORKERS=4 \
    PORT=8080 \
    DB_PATH=/app/data/daily-pass.db

EXPOSE 8080
ENTRYPOINT ["sh", "/app/entrypoint.sh"]
