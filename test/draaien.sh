#!/usr/bin/env bash
# Start MariaDB, draait de rooktest en (optioneel) de HTTP-test.
set -u
cd "$(dirname "$0")"

docker compose up -d db >/dev/null 2>&1
echo "Wachten op de database..."
for i in $(seq 1 60); do
    if docker compose exec -T db healthcheck.sh --connect >/dev/null 2>&1; then break; fi
    sleep 2
done

docker compose run --rm --no-deps \
    -e DB_HOST=db -e DB_NAME=djm_portaal -e DB_USER=djm_portaal -e DB_PASS=djmtest \
    -e APP_URL=http://localhost:8123 -e APP_KEY=testsleutel_0123456789abcdef \
    -e OTP_PEPPER=testpepper_0123456789abcdef -e DEBUG=true \
    web php /app/test/smoke.php
