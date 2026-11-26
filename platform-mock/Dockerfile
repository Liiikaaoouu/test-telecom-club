FROM php:8.2-cli

RUN php -r 'foreach (["pdo_sqlite","sqlite3","curl"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "missing extension: $e\n"); exit(1); } }'

WORKDIR /app

# Never print warnings into an HTTP response (see php.ini).
COPY php.ini /usr/local/etc/php/conf.d/zz-errors.ini

COPY . .
RUN mkdir -p /app/data

ENV PHP_CLI_SERVER_WORKERS=4 \
    PORT=9100 \
    MOCK_DB=/app/data/mock.db

EXPOSE 9100
CMD ["sh", "-c", "exec php -S 0.0.0.0:${PORT:-9100} /app/public/router.php"]
