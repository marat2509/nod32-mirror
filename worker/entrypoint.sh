#!/bin/sh

if [ -f "/app/health.ok" ]; then
    rm -rf /app/health.ok
fi

health=""

if [ ! -f "/app/nod32ms.conf" ]; then
    echo "E: Config file not found"
    echo "E: Create nod32ms.conf file in directory with docker-compose.yml file and restart"
else
    health="$health+"
fi

if [ -z "${UPDATE_INTERVAL}" ]; then
    UPDATE_INTERVAL=3600
fi

if [ "${#health}" = "1" ]; then
    touch /app/health.ok
    watch -eptn $UPDATE_INTERVAL "php /app/update.php"
else
    while true; do
        read _
    done
fi
