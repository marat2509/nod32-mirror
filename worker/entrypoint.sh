#!/bin/sh

if [ ! -f "/app/nod32ms.conf" ]; then
    echo "E: Config file not found"
    echo "E: Create nod32ms.conf file in directory with docker-compose.yml file and restart"
    exit 1
fi

UPDATE_INTERVAL=${UPDATE_INTERVAL:-3600}

while true; do
    start_time=$(date +%s)
    php /app/update.php
    end_time=$(date +%s)
    duration=$((end_time - start_time))

    if [ $? -ne 0 ]; then
        echo "E: Run script failed after $duration s, exiting..."
        exit 1
    fi

    sleep_time=$((UPDATE_INTERVAL - duration))

    if [ "$sleep_time" -gt 0 ]; then
        sleep "$sleep_time"
    else
        echo "Warning: Script execution time ($duration s) exceeded UPDATE_INTERVAL ($UPDATE_INTERVAL s). Skipping sleep."
    fi
done
