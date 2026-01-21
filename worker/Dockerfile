# --- Stage 1: Builder (Composer) ---
FROM alpine:3.23 AS builder

# Install build dependencies
RUN apk add --no-cache build-base wget php85 php85-openssl php85-phar php85-mbstring php85-json php85-ctype php85-curl git && \
    ln -s /usr/bin/php85 /usr/bin/php

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY core/composer.json ./
# Install dependencies
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY core/ .
RUN composer dump-autoload --optimize --no-dev

# --- Stage 2: Final Image ---
FROM alpine:3.23

# Install PHP 8.5 from Alpine repos (much smaller than official php image)
RUN apk add --no-cache \
    php85 \
    php85-cli \
    php85-curl \
    php85-fileinfo \
    php85-iconv \
    php85-mbstring \
    php85-openssl \
    php85-phar \
    php85-sockets \
    php85-zip \
    php85-simplexml \
    libstdc++

# Symlink php82 to php
RUN ln -s /usr/bin/php85 /usr/bin/php

WORKDIR /app

# Copy entrypoint
COPY entrypoint.sh .
RUN sed -i 's/\r$//' entrypoint.sh && chmod +x entrypoint.sh

# Copy app code
COPY --from=builder /app /app

ENTRYPOINT ["/bin/sh", "/app/entrypoint.sh"]
