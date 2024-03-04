FROM alpine:latest
RUN apk update && apk upgrade && apk add --no-cache bash git php php-curl php-fileinfo php-iconv php-mbstring php-openssl pcre php-simplexml php-sockets php-zlib php-json procps-ng
WORKDIR /app
COPY core/ .
COPY entrypoint.sh .
ENTRYPOINT ["/bin/bash", "/app/entrypoint.sh"]
HEALTHCHECK --interval=30s --timeout=5s --start-period=5s --retries=3 CMD ["test", "-f", "/app/health.ok"]
