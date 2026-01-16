# --- Stage 1: Builder (Unrar & Composer) ---
FROM alpine:3.20 AS builder

# Install build dependencies
RUN apk add --no-cache build-base wget php82 php82-openssl php82-phar php82-mbstring php82-json php82-ctype php82-curl git && \
    ln -s /usr/bin/php82 /usr/bin/php

# Build unrar
WORKDIR /tmp/unrar
RUN wget https://www.rarlab.com/rar/unrarsrc-6.2.12.tar.gz && \
    tar -xzvf unrarsrc-6.2.12.tar.gz && \
    cd unrar && \
    make -f makefile && \
    install -v -m755 unrar /usr/bin/unrar

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY core/composer.json ./
# Install dependencies
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY core/ .
RUN composer dump-autoload --optimize --no-dev

# --- Stage 2: Final Image ---
FROM alpine:3.20

# Install PHP 8.2 from Alpine repos (much smaller than official php image)
RUN apk add --no-cache \
    php82 \
    php82-cli \
    php82-curl \
    php82-fileinfo \
    php82-iconv \
    php82-mbstring \
    php82-openssl \
    php82-phar \
    php82-sockets \
    php82-zip \
    php82-simplexml \
    libstdc++

# Symlink php82 to php
RUN ln -s /usr/bin/php82 /usr/bin/php

# Copy unrar
COPY --from=builder /usr/bin/unrar /usr/bin/unrar

WORKDIR /app

# Copy entrypoint
COPY entrypoint.sh .
RUN sed -i 's/\r$//' entrypoint.sh && chmod +x entrypoint.sh

# Copy app code
COPY --from=builder /app /app

ENTRYPOINT ["/bin/sh", "/app/entrypoint.sh"]
