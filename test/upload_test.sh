#!/usr/bin/env bash
# Upload in delen (admin/upload.php) op alle drie de webservers: de ingebouwde
# PHP-server, nginx + PHP-FPM en Apache. Een ruwe body in stukken gedraagt zich
# per server anders (bodylimieten, bufferen), dus alle drie.
#
# Daarna dezelfde upload in een echte browser (upload_browser.js), met een
# weggevallen verbinding en een ververst tabblad halverwege.
set -u
cd "$(dirname "$0")"
. ./hostpad.sh
GOED=0; FOUT=0
JAAR=2029
WERK=$(mktemp -d)
trap 'rm -rf "$WERK"' EXIT

toets() {
    if [ "$2" = "$3" ]; then printf '  ✓ %s\n' "$1"; GOED=$((GOED+1));
    else printf '  ✗ %s\n      verwacht: %s\n      kreeg:    %s\n' "$1" "$2" "$3"; FOUT=$((FOUT+1)); fi
}
csrf() { printf '%s' "$1" | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | cut -d'"' -f4; }
php_db() { docker compose exec -T web php -r "\$_SERVER['SCRIPT_NAME']='/index.php'; require '/app/config.php'; require '/app/includes/bestand_helper.php'; $1" 2>/dev/null | tr -d '\r'; }

# post <koekjes> <url> [curl-argumenten…] — zet STATUS en BODY
post() {
    local jar="$1" adres="$2"; shift 2
    STATUS=$(curl -s -o "$WERK/antwoord" -w '%{http_code}' -b "$jar" -c "$jar" -X POST \
        -H 'Accept: application/json' "$@" "$adres")
    BODY=$(cat "$WERK/antwoord")
}
# veld <naam> — waarde uit de JSON in BODY
veld() {
    printf '%s' "$BODY" | python3 -c "
import sys, json
try: v = json.load(sys.stdin).get('$1')
except Exception: v = 'GEEN-JSON'
print(str(v).lower() if isinstance(v, bool) else ('' if v is None else v))"
}

echo "── Klaarzetten ─────────────────────────────────────────────"
JAARGANG_ID=$(php_db "
db()->exec('DELETE FROM jaargangen WHERE jaar = $JAAR');
foreach (glob(opslag_pad() . '/$JAAR/*') ?: [] as \$f) { @unlink(\$f); }
foreach (glob(opslag_pad() . '/' . UPLOAD_MAP . '/*.deel') ?: [] as \$f) { @unlink(\$f); }
// nginx/PHP-FPM en Apache draaien als www-data, deze container als root.
foreach ([opslag_pad() . '/' . UPLOAD_MAP, opslag_pad() . '/$JAAR'] as \$map) { @mkdir(\$map, 0777, true); @chmod(\$map, 0777); }
db()->prepare('INSERT INTO beheerders (naam,email,wachtwoord_hash,rol) VALUES (:n,:e,:w,\"eigenaar\")
   ON DUPLICATE KEY UPDATE wachtwoord_hash=VALUES(wachtwoord_hash), actief=1')
  ->execute([':n'=>'Testbeheerder',':e'=>'beheer@example.nl',
             ':w'=>password_hash('EenHeelLangWachtwoord123', PASSWORD_DEFAULT)]);
db()->exec('DELETE FROM aanvraag_limiet');
db()->exec(\"INSERT INTO jaargangen (jaar, titel, slug, gepubliceerd) VALUES ($JAAR, 'Uploadtest', 'uploadtest-$JAAR', 0)\");
echo (int)db()->lastInsertId();")
toets "jaargang $JAAR aangemaakt" "1" "$([ "${JAARGANG_ID:-0}" -gt 0 ] 2>/dev/null && echo 1 || echo 0)"

# 20 MB aan willekeurige bytes: meer dan twee stukken bij post_max_size = 8M.
head -c 20000000 /dev/urandom > "$WERK/video.mp4"
GROOTTE=$(stat -c %s "$WERK/video.mp4")
HASH_ORIGINEEL=$(sha256sum "$WERK/video.mp4" | cut -d' ' -f1)
printf '  testbestand: %s bytes\n' "$GROOTTE"

# start <koekjes> <basis> <token> <naam> <gewijzigd>
start() {
    post "$1" "$2/admin/upload.php?actie=start" -H "X-CSRF-Token: $3" -H 'Content-Type: application/json' \
        --data "{\"jaargang\": $JAARGANG_ID, \"naam\": \"$4\", \"bytes\": $GROOTTE, \"gewijzigd\": $5}"
}
# deel <koekjes> <basis> <token> <sleutel> <offset> <lengte> [sha256]
deel() {
    local jar="$1" basis="$2" token="$3" sleutel="$4" offset="$5" lengte="$6" hash="${7:-}"
    tail -c +$((offset + 1)) "$WERK/video.mp4" | head -c "$lengte" > "$WERK/stuk"
    [ -z "$hash" ] && hash=$(sha256sum "$WERK/stuk" | cut -d' ' -f1)
    post "$jar" "$basis/admin/upload.php?actie=deel&sleutel=$sleutel&offset=$offset" \
        -H "X-CSRF-Token: $token" -H 'Content-Type: application/octet-stream' \
        -H "X-Deel-Sha256: $hash" --data-binary @"$WERK/stuk"
}

test_server() {
    local naam="$1" basis="$2" nummer="$3"
    local jar="$WERK/koekjes-$nummer"
    echo ""
    echo "── $naam ─────────────────────────────────────────────"

    if [ "$(curl -s -o /dev/null -w '%{http_code}' "$basis/index.php")" = "000" ]; then
        echo "  Overgeslagen: $basis is niet bereikbaar."
        return
    fi

    post "$WERK/leeg" "$basis/admin/upload.php?actie=start" -H 'Content-Type: application/json' --data '{}'
    toets "zonder sessie: 401" "401" "$STATUS"
    toets "zonder sessie: JSON in plaats van de inlogpagina" "U bent niet meer ingelogd." "$(veld fout)"

    php_db "db()->exec('DELETE FROM aanvraag_limiet');" >/dev/null
    local l; l=$(curl -s -c "$jar" -b "$jar" "$basis/admin/login.php")
    curl -s -o /dev/null -c "$jar" -b "$jar" -d "csrf_token=$(csrf "$l")" \
        -d "email=beheer@example.nl" -d "wachtwoord=EenHeelLangWachtwoord123" "$basis/admin/login.php"
    local pagina; pagina=$(curl -s -c "$jar" -b "$jar" "$basis/admin/bestanden.php?jaargang=$JAARGANG_ID")
    local token; token=$(csrf "$pagina")
    toets "Bestanden toont het uploadvak" "1" "$(printf '%s' "$pagina" | grep -c 'data-upload-invoer')"

    toets "GET wordt geweigerd" "405" "$(curl -s -o /dev/null -w '%{http_code}' -b "$jar" "$basis/admin/upload.php?actie=start")"
    post "$jar" "$basis/admin/upload.php?actie=start" -H 'Content-Type: application/json' --data '{}'
    toets "zonder CSRF-token: 403" "403" "$STATUS"

    post "$jar" "$basis/admin/upload.php?actie=start" -H "X-CSRF-Token: $token" -H 'Content-Type: application/json' \
        --data "{\"jaargang\": $JAARGANG_ID, \"naam\": \"script.php\", \"bytes\": 10, \"gewijzigd\": 1}"
    toets "verkeerd bestandstype: 415" "415" "$STATUS"

    start "$jar" "$basis" "$token" "video.mp4" "$nummer"
    toets "start: 200" "200" "$STATUS"
    local sleutel; sleutel=$(veld sleutel)
    local stuk; stuk=$(veld deelgrootte)
    toets "start: nog niets ontvangen" "0" "$(veld ontvangen)"
    toets "stukgrootte past binnen post_max_size" "1" "$([ "${stuk:-0}" -gt 0 ] && [ "${stuk:-0}" -le 8388608 ] && echo 1 || echo 0)"

    deel "$jar" "$basis" "$token" "$sleutel" 5 1000
    toets "verkeerde offset: 409" "409" "$STATUS"
    toets "409 noemt wat er echt binnen is" "0" "$(veld ontvangen)"

    deel "$jar" "$basis" "$token" "$sleutel" 0 "$stuk"
    toets "eerste stuk: 200" "200" "$STATUS"
    toets "eerste stuk: ontvangen klopt" "$stuk" "$(veld ontvangen)"

    deel "$jar" "$basis" "$token" "$sleutel" "$stuk" "$stuk" "$(printf '0%.0s' $(seq 64))"
    toets "beschadigd stuk (SHA-256 klopt niet): 422" "422" "$STATUS"

    start "$jar" "$basis" "$token" "video.mp4" "$nummer"
    toets "opnieuw starten hervat dezelfde upload" "$sleutel" "$(veld sleutel)"
    toets "beschadigd stuk is teruggedraaid" "$stuk" "$(veld ontvangen)"

    post "$jar" "$basis/admin/upload.php?actie=afronden&sleutel=$sleutel" -H "X-CSRF-Token: $token" \
        -H 'Content-Type: application/json' --data '{"koppelen": true}'
    toets "afronden vóór het einde: 409" "409" "$STATUS"

    local offset="$stuk"
    while [ "$offset" -lt "$GROOTTE" ]; do
        local lengte=$(( GROOTTE - offset < stuk ? GROOTTE - offset : stuk ))
        deel "$jar" "$basis" "$token" "$sleutel" "$offset" "$lengte"
        [ "$STATUS" = "200" ] || break
        offset=$(veld ontvangen)
    done
    toets "alle stukken aangekomen" "$GROOTTE" "$offset"

    post "$jar" "$basis/admin/upload.php?actie=afronden&sleutel=$sleutel" -H "X-CSRF-Token: $token" \
        -H 'Content-Type: application/json' \
        --data '{"koppelen": true, "titel": "Uploadtest", "bestandsnaam": "DJM uploadtest.mp4", "actief": true}'
    toets "afronden: 200" "200" "$STATUS"
    toets "afronden: gekoppeld" "true" "$(veld gekoppeld)"
    local pad; pad=$(veld pad)
    local verwacht="$JAAR/video.mp4"
    [ "$nummer" -gt 1 ] && verwacht="$JAAR/video-$nummer.mp4"
    toets "bestaand bestand niet overschreven" "$verwacht" "$pad"
    toets "bestand op schijf is identiek" "$HASH_ORIGINEEL" \
        "$(php_db "echo hash_file('sha256', opslag_pad() . '/$pad');")"
    toets "koppeling in de database" "$GROOTTE" \
        "$(php_db "echo (int)db()->query(\"SELECT bytes FROM jaargang_bestanden WHERE pad = '$pad'\")->fetchColumn();")"
    toets "geen half bestand achtergebleven" "0" \
        "$(php_db "echo count(glob(opslag_pad() . '/' . UPLOAD_MAP . '/$sleutel.deel'));")"

    # Een stuk groter dan post_max_size gooit PHP weg vóór upload.php draait. Met
    # display_errors aan (zoals in deze testcontainers) staat PHP's waarschuwing dan
    # al in het antwoord en blijft de status 200; in productie wordt het 413. Waar
    # het om gaat: er wordt niets geschreven en het antwoord meldt geen succes.
    start "$jar" "$basis" "$token" "groot.mp4" "$nummer"
    sleutel=$(veld sleutel)
    deel "$jar" "$basis" "$token" "$sleutel" 0 9000000
    toets "stuk boven post_max_size: geen succes gemeld" "" "$(printf '%s' "$BODY" | grep -o '"ontvangen":9000000')"
    start "$jar" "$basis" "$token" "groot.mp4" "$nummer"
    toets "stuk boven post_max_size: niets geschreven" "0" "$(veld ontvangen)"

    post "$jar" "$basis/admin/upload.php?actie=annuleren&sleutel=$sleutel" -H "X-CSRF-Token: $token" \
        -H 'Content-Type: application/json' --data '{}'
    toets "annuleren: 200" "200" "$STATUS"
    toets "annuleren ruimt het halve bestand op" "0" \
        "$(php_db "echo count(glob(opslag_pad() . '/' . UPLOAD_MAP . '/$sleutel.deel'));")"
}

test_server "Ingebouwde PHP-server" http://localhost:8123 1
test_server "nginx + PHP-FPM" http://localhost:8126 2
test_server "Apache" http://localhost:8127 3

echo ""
echo "── Nachtelijke opruimtaak ──────────────────────────────────"
UITKOMST=$(php_db "
\$u = upload_start((int)db()->query('SELECT id FROM beheerders LIMIT 1')->fetchColumn(), $JAARGANG_ID, 'vergeten.mp4', 1000, 42);
file_put_contents(opslag_pad() . '/' . UPLOAD_MAP . '/' . \$u['sleutel'] . '.deel', str_repeat('x', 100));
db()->exec(\"UPDATE uploads SET bijgewerkt_op = NOW() - INTERVAL 8 DAY WHERE sleutel = '\" . \$u['sleutel'] . \"'\");
\$wees = opslag_pad() . '/' . UPLOAD_MAP . '/' . str_repeat('a', 32) . '.deel';
file_put_contents(\$wees, 'x'); touch(\$wees, time() - 2 * 86400);
\$n = upload_opruimen(UPLOAD_VERLOOP_DAGEN);
echo \$n, ' ', (int)is_file(opslag_pad() . '/' . UPLOAD_MAP . '/' . \$u['sleutel'] . '.deel'), ' ', (int)is_file(\$wees);")
toets "stilliggende upload en los half bestand opgeruimd" "2 0 0" "$UITKOMST"

echo ""
echo "── In een echte browser ────────────────────────────────────"
php_db "db()->exec('DELETE FROM aanvraag_limiet');" >/dev/null
if docker run --rm --network test_default \
        -v "$(hostpad "$PWD/upload_browser.js")":/usr/src/app/upload_browser.js:ro \
        -e BASIS=http://web:8080 -e JAARGANG="$JAARGANG_ID" \
        -w /usr/src/app --entrypoint node \
        zenika/alpine-chrome:with-puppeteer upload_browser.js; then
    GOED=$((GOED+1))
else
    FOUT=$((FOUT+1))
fi

echo ""
echo "── Opruimen ────────────────────────────────────────────────"
php_db "
db()->exec('DELETE FROM jaargangen WHERE jaar = $JAAR');
foreach (glob(opslag_pad() . '/$JAAR/*') ?: [] as \$f) { @unlink(\$f); }
@rmdir(opslag_pad() . '/$JAAR');" >/dev/null
echo "  jaargang $JAAR en de testbestanden verwijderd"

echo ""
printf '  %d geslaagd, %d mislukt\n' "$GOED" "$FOUT"
[ "$FOUT" -eq 0 ]
