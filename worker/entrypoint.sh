#!/bin/sh

if [ ! -f "/app/nod32ms.conf" ]; then
    echo "E: Config file not found"
    echo "E: Create nod32ms.conf file in directory with docker-compose.yml file and restart"
    exit 1
fi


if [ -z "${UPDATE_INTERVAL}" ]; then
    UPDATE_INTERVAL=3600
fi

while true; do
    php /app/update.php
    if [ ! $? -eq 0 ]; then
        echo "E: Run script failed, exitting..."
        exit 1
    else
        sleep $UPDATE_INTERVAL
    fi
done
