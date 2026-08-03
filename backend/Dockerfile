FROM php:8.4-cli-alpine

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

RUN apk add --no-cache ca-certificates libcurl libsodium oniguruma unzip \
  && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS curl-dev libsodium-dev oniguruma-dev \
  && docker-php-ext-install -j$(nproc) curl mbstring opcache pcntl pdo_mysql sodium \
  && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

COPY --chown=www-data:www-data . .
RUN mkdir -p vendor runtime/logs \
  && chown -R www-data:www-data vendor runtime \
  && install -m 0755 docker-entrypoint.sh /usr/local/bin/tfsign-entrypoint

USER www-data
EXPOSE 8787

ENTRYPOINT ["/usr/local/bin/tfsign-entrypoint"]
CMD ["php", "start.php", "start"]
