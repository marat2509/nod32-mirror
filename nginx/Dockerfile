FROM nginx:mainline-alpine
WORKDIR /app
COPY default.conf /etc/nginx/conf.d/default.conf
COPY entrypoint.sh .
COPY healthcheck.sh .
ENTRYPOINT ["/bin/sh", "/app/entrypoint.sh"]
HEALTHCHECK --interval=30s --timeout=5s --start-period=5s --retries=3 CMD ["/bin/sh", "healthcheck.sh"]