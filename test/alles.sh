#!/usr/bin/env bash
# Draait de volledige testset. Start zo nodig de testomgeving.
cd "$(dirname "$0")"
. ./hostpad.sh
PROJECT=$(cd .. && pwd)
MISLUKT=0
KOP() { printf '\n\033[1m══ %s ══\033[0m\n' "$1"; }

docker compose up -d db mail web nginx apache >/dev/null 2>&1
for i in $(seq 1 60); do
    [ "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8123/index.php 2>/dev/null)" != "000" ] && break
    sleep 1
done

KOP "Syntaxcontrole"
FOUTEN=0
# Eén container voor alle bestanden in plaats van één per bestand: dat scheelt
# bij vijftig bestanden net zoveel keer een container opstarten.
UIT=$(docker run --rm -v "$(hostpad "$PROJECT")":/app php:8.3-cli \
    sh -c 'for f in $(find /app -name "*.php" -not -path "*/.git/*" | sort); do
               php -l "$f" | grep -v "^No syntax errors"
           done' 2>&1)
if [ -n "$UIT" ]; then
    printf '%s\n' "$UIT" | sed 's/^/  ✗ /'
    FOUTEN=1
fi
[ "$FOUTEN" -eq 0 ] && echo "  ✓ alle PHP-bestanden zijn syntactisch correct" || MISLUKT=$((MISLUKT+1))

KOP "Statische controle";      bash audit.sh      || MISLUKT=$((MISLUKT+1))
# Wist de database en loopt setup.php door; moet dus vóór de tests die gegevens
# nodig hebben (smoke.php richt de database daarna opnieuw in).
KOP "Verse installatie";       bash installatie.sh || MISLUKT=$((MISLUKT+1))
KOP "Kernlogica";              docker compose exec -T web php /app/test/smoke.php || MISLUKT=$((MISLUKT+1))
KOP "Inlogcodes";              docker compose exec -T web php /app/test/mailinstellingen.php >/dev/null
                               docker compose exec -T web php /app/test/otp_test.php || MISLUKT=$((MISLUKT+1))
KOP "Publieke flow";           bash e2e.sh        || MISLUKT=$((MISLUKT+1))
KOP "Beheerdersgedeelte";      bash admin_test.sh || MISLUKT=$((MISLUKT+1))
KOP "Beheerders en wachtwoorden"; bash beheerders_test.sh || MISLUKT=$((MISLUKT+1))
# Securityheaders, afgeschermde paden en de beveiligingshelpers, gemeten aan de
# draaiende servers in plaats van aan de broncode.
KOP "Beveiliging";             bash beveiliging.sh || MISLUKT=$((MISLUKT+1))
KOP "Jaarlijkse workflow";     bash jaarflow.sh   || MISLUKT=$((MISLUKT+1))
# Echte browser: vangt problemen die curl niet ziet, zoals een Content-Security-
# Policy die formulieren blokkeert of JavaScript dat stukloopt.
KOP "In een echte browser";    bash schermafdrukken.sh || MISLUKT=$((MISLUKT+1))
KOP "Op telefoonformaat";      docker run --rm --network test_default \
                                   -v "$(hostpad "$PWD/mobiel.js")":/usr/src/app/mobiel.js:ro \
                                   -w /usr/src/app --entrypoint node \
                                   zenika/alpine-chrome:with-puppeteer mobiel.js || MISLUKT=$((MISLUKT+1))
# De routes die in productie gebruikt worden: nginx en Apache, niet alleen de
# ingebouwde PHP-server.
KOP "Uitlevering per webserver"; bash uitlevering.sh || MISLUKT=$((MISLUKT+1))
KOP "Bestand van 5 GB";        bash grootbestand.sh || MISLUKT=$((MISLUKT+1))
# Op alle drie de webservers en in een echte browser, met een weggevallen
# verbinding en een ververst tabblad halverwege.
KOP "Upload in delen";         bash upload_test.sh || MISLUKT=$((MISLUKT+1))
KOP "Graph-foutafhandeling";   docker compose exec -T web php /app/test/graph_test.php || MISLUKT=$((MISLUKT+1))
KOP "Opschoonscript";          docker compose exec -T web php /app/cron_opschonen.php || MISLUKT=$((MISLUKT+1))

printf '\n\033[1m════════════════════════════════════════════════════════════\033[0m\n'
if [ "$MISLUKT" -eq 0 ]; then printf '  Alle testonderdelen geslaagd.\n\n'; else printf '  %d testonderdeel/-onderdelen met fouten.\n\n' "$MISLUKT"; fi
exit "$MISLUKT"
