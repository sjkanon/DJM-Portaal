#!/usr/bin/env bash
# Maakt schermafdrukken van portaal en beheer met een echte browsersessie.
set -u
cd "$(dirname "$0")"
. ./hostpad.sh
UIT=${1:-/tmp/djm-shots}
mkdir -p "$UIT"

# Verse inlogcode klaarzetten voor het portaal.
docker compose exec -T web php -r '
$_SERVER["SCRIPT_NAME"]="/index.php"; $_SERVER["REMOTE_ADDR"]="127.0.0.1";
require "/app/config.php"; require "/app/includes/otp.php";
db()->exec("DELETE FROM aanvraag_limiet");
' >/dev/null 2>&1

# Oude testmail weggooien, zodat de code die straks binnenkomt de nieuwste is.
curl -s -X DELETE http://localhost:8125/api/v1/messages >/dev/null 2>&1

docker run --rm --network test_default \
    -v "$(hostpad "$PWD/schermafdrukken.js")":/usr/src/app/schermafdrukken.js:ro \
    -v "$(hostpad "$UIT")":/shots \
    -e BASIS=http://web:8080 \
    -w /usr/src/app \
    --entrypoint node \
    zenika/alpine-chrome:with-puppeteer schermafdrukken.js
