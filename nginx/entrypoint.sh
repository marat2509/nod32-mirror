#!/bin/sh

if [ ! -e "/app/www/index.html" ]; then
    echo "NOD32 mirror" > /app/www/index.html
fi

nginx -g "daemon off;"