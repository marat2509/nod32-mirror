FROM alpine:latest
RUN apk update && \
  apk upgrade &&

RUN apk add --no-cache \
  git \
  pcre \
  php \
  php-curl \
  php-fileinfo \
  php-iconv \
  php-json \
  php-mbstring \
  php-openssl \
  php-simplexml \
  php-sockets \
  php-zlib \
  xterm

WORKDIR /app
COPY core/ .
COPY entrypoint.sh .
ENTRYPOINT ["/bin/sh", "/app/entrypoint.sh"]