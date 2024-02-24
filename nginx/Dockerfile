FROM nginx:mainline-alpine
WORKDIR /app
COPY default.conf /etc/nginx/conf.d/default.conf
COPY entrypoint.sh .
ENTRYPOINT ["/app/entrypoint.sh"]
HEALTHCHECK --interval=30s --timeout=30s --start-period=5s --retries=3 CMD ["curl", "-s", "--fail", "localhost"]