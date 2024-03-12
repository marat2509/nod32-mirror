FROM nginx:mainline-alpine
WORKDIR /app
COPY default.conf /etc/nginx/conf.d/default.conf
COPY entrypoint.sh .
ENTRYPOINT ["/bin/sh", "/app/entrypoint.sh"]
