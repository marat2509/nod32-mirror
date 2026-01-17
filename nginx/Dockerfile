FROM nginx:mainline-alpine
WORKDIR /app
COPY nginx.conf /etc/nginx/nginx.conf
COPY entrypoint.sh .
ENTRYPOINT ["/bin/sh", "/app/entrypoint.sh"]
