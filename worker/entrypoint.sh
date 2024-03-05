#!/bin/sh

if [ ! -f "/app/nod32ms.conf" ]; then
    echo "E: Config file not found"
    echo "E: Create nod32ms.conf file in directory with docker-compose.yml file and restart"
    exit 1
fi


if [ -z "${UPDATE_INTERVAL}" ]; then
    UPDATE_INTERVAL=3600
fi

watch -tn $UPDATE_INTERVAL "php /app/update.php"
