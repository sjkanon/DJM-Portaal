#!/usr/bin/env bash
# End-to-end test: complete inlogflow (e-mail -> code -> sessie -> download),
# tegen een echte MariaDB en een echte SMTP-server (Mailpit).
set -u
cd "$(dirname "$0")"
. ./hostpad.sh
BASIS=http://localhost:8123
KOEKJES=$(mktemp)
GOED=0; FOUT=0

toets() { # toets "omschrijving" "verwacht" "werkelijk"
    if [ "$2" = "$3" ]; then printf '  ✓ %s\n' "$1"; GOED=$((GOED+1));
    else printf '  ✗ %s\n      verwacht: %s\n      kreeg:    %s\n' "$1" "$2" "$3"; FOUT=$((FOUT+1)); fi
}
bevat() { # bevat "omschrijving" "naald" "hooiberg"
    if printf '%s' "$3" | grep -qF "$2"; then printf '  ✓ %s\n' "$1"; GOED=$((GOED+1));
    else printf '  ✗ %s (miste: %s)\n' "$1" "$2"; FOUT=$((FOUT+1)); fi
}
bevat_niet() {
    if printf '%s' "$3" | grep -qF "$2"; then printf '  ✗ %s (vond: %s)\n' "$1" "$2"; FOUT=$((FOUT+1));
    else printf '  ✓ %s\n' "$1"; GOED=$((GOED+1)); fi
}
status() { curl -s -o /dev/null -w '%{http_code}' -b "$KOEKJES" -c "$KOEKJES" "$@"; }
haal()   { curl -s -b "$KOEKJES" -c "$KOEKJES" "$@"; }
csrf()   { printf '%s' "$1" | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | cut -d'"' -f4; }

echo "── Opstarten ───────────────────────────────────────────────"
docker compose up -d db mail web >/dev/null 2>&1
for i in $(seq 1 60); do
    [ "$(curl -s -o /dev/null -w '%{http_code}' "$BASIS/index.php" 2>/dev/null)" != "000" ] && break
    sleep 1
done
echo "  webserver bereikbaar op $BASIS"

# Database vullen en de mail naar Mailpit laten wijzen.
docker compose exec -T web php /app/test/smoke.php >/dev/null 2>&1
docker compose exec -T web php /app/test/mailinstellingen.php >/dev/null 2>&1

# Pas hierna de postbus legen. Een mail die een vorige test net had verstuurd,
# komt soms een fractie later binnen; leegden we eerder, dan telde die hieronder
# mee en leek het alsof er naar een onbekend adres was gemaild.
sleep 1
curl -s -X DELETE http://localhost:8125/api/v1/messages >/dev/null 2>&1

echo ""
echo "── Publieke pagina's ───────────────────────────────────────"
HOME_HTML=$(haal "$BASIS/index.php")
toets "index.php geeft 200" "200" "$(status "$BASIS/index.php")"
bevat "inlogformulier aanwezig" 'name="email"' "$HOME_HTML"
bevat "CSRF-token aanwezig" 'name="csrf_token"' "$HOME_HTML"
bevat_niet "geen PHP-fouten op de pagina" "Fatal error" "$HOME_HTML"
bevat_niet "geen waarschuwingen op de pagina" "Warning:" "$HOME_HTML"

echo ""
echo "── Afscherming zonder sessie ───────────────────────────────"
toets "portaal stuurt door naar inloggen" "302" "$(status "$BASIS/portaal/index.php")"
# Geen omleiding maar een 403: een downloadprogramma zou de inlogpagina achter
# een omleiding gewoon als bestand bewaren.
toets "download weigert zonder sessie" "403" "$(status "$BASIS/download.php?b=1")"
toets "beheer stuurt door naar inloggen" "302" "$(status "$BASIS/admin/index.php")"

echo ""
echo "── Onbekend adres (geen user enumeration) ──────────────────"
TOKEN=$(csrf "$HOME_HTML")
ONBEKEND=$(haal -L -X POST -d "csrf_token=$TOKEN" -d "email=niemand@example.nl" "$BASIS/index.php")
bevat_niet "geen aanwijzing dat het adres onbekend is" "onbekend" "$ONBEKEND"
AANTAL_MAILS=$(curl -s http://localhost:8125/api/v1/messages | python3 -c 'import sys,json;print(json.load(sys.stdin)["total"])')
toets "geen mail verstuurd naar onbekend adres" "0" "$AANTAL_MAILS"

echo ""
echo "── Inlogcode aanvragen ─────────────────────────────────────"
rm -f "$KOEKJES"
HOME_HTML=$(haal "$BASIS/index.php")
TOKEN=$(csrf "$HOME_HTML")
haal -o /dev/null -X POST -d "csrf_token=$TOKEN" -d "email=ouder@example.nl" "$BASIS/index.php"
sleep 1
MAILS=$(curl -s http://localhost:8125/api/v1/messages)
AANTAL=$(printf '%s' "$MAILS" | python3 -c 'import sys,json;print(json.load(sys.stdin)["total"])')
toets "één mail verstuurd" "1" "$AANTAL"
MAIL_ID=$(printf '%s' "$MAILS" | python3 -c 'import sys,json;d=json.load(sys.stdin);print(d["messages"][0]["ID"] if d["total"] else "")')
if [ -n "$MAIL_ID" ]; then
    MAIL=$(curl -s "http://localhost:8125/api/v1/message/$MAIL_ID")
    ONDERWERP=$(printf '%s' "$MAIL" | python3 -c 'import sys,json;print(json.load(sys.stdin).get("Subject",""))')
    CODE=$(printf '%s' "$MAIL" | python3 -c '
import sys,json,re
d=json.load(sys.stdin)
tekst=(d.get("HTML") or "")+(d.get("Text") or "")
tekst=re.sub(r"<[^>]+>"," ",tekst)
m=re.findall(r"\b\d{6}\b",tekst)
print(m[0] if m else "")')
    bevat "onderwerp bevat 'inlogcode'" "inlogcode" "$ONDERWERP"
    toets "code van zes cijfers in de mail" "6" "${#CODE}"
else
    echo "  ✗ geen mail gevonden — rest van de test wordt overgeslagen"; FOUT=$((FOUT+1)); CODE=""
fi

echo ""
echo "── Code verifiëren ─────────────────────────────────────────"
VER_HTML=$(haal "$BASIS/verifieer.php")
TOKEN=$(csrf "$VER_HTML")
bevat "verifieerpagina toont codeveld" 'one-time-code' "$VER_HTML"

FOUTE=$(haal -X POST -d "csrf_token=$TOKEN" -d "code=000000" "$BASIS/verifieer.php")
bevat_niet "foute code geeft geen toegang" "Uw video" "$FOUTE"

VER_HTML=$(haal "$BASIS/verifieer.php")
TOKEN=$(csrf "$VER_HTML")
haal -o /dev/null -X POST -d "csrf_token=$TOKEN" -d "code=$CODE" "$BASIS/verifieer.php"
PORTAAL=$(haal "$BASIS/portaal/index.php")
bevat "portaal toont de jaargang" "2026" "$PORTAAL"
bevat "portaal toont de bestandstitel" "Volledige registratie" "$PORTAAL"
bevat "downloadknop aanwezig" "download.php?b=" "$PORTAAL"

echo ""
echo "── Downloaden ──────────────────────────────────────────────"
LINK=$(printf '%s' "$PORTAAL" | grep -o 'download\.php?b=[^"]*' | head -1 | sed 's/&amp;/\&/g')
if [ -n "$LINK" ]; then
    KOP=$(curl -s -D - -o /tmp/djm-download.bin -b "$KOEKJES" "$BASIS/$LINK")
    toets "download geeft 200" "200" "$(printf '%s' "$KOP" | head -1 | grep -o '[0-9]\{3\}')"
    bevat "als bijlage aangeboden" "attachment" "$KOP"
    bevat "hervatten ondersteund" "Accept-Ranges" "$KOP"
    toets "volledig bestand ontvangen" "32768" "$(stat -c%s /tmp/djm-download.bin)"

    RANGE=$(curl -s -D - -o /tmp/djm-deel.bin -b "$KOEKJES" -H "Range: bytes=100-199" "$BASIS/$LINK")
    toets "deelverzoek geeft 206" "206" "$(printf '%s' "$RANGE" | head -1 | grep -o '[0-9]\{3\}')"
    bevat "Content-Range aanwezig" "Content-Range: bytes 100-199/32768" "$RANGE"
    toets "juiste hoeveelheid bytes" "100" "$(stat -c%s /tmp/djm-deel.bin)"

    ONMOGELIJK=$(curl -s -D - -o /dev/null -b "$KOEKJES" -H "Range: bytes=99999999-" "$BASIS/$LINK")
    toets "onbereikbaar bereik geeft 416" "416" "$(printf '%s' "$ONMOGELIJK" | head -1 | grep -o '[0-9]\{3\}')"

    HEADONLY=$(curl -s -I -b "$KOEKJES" "$BASIS/$LINK")
    toets "HEAD geeft 200" "200" "$(printf '%s' "$HEADONLY" | head -1 | grep -o '[0-9]\{3\}')"
    bevat "HEAD noemt de volledige lengte" "Content-Length: 32768" "$HEADONLY"

    echo ""
    echo "── Verlopen downloadlink ───────────────────────────────────"
    # Het tabblad stond al uren open, of de browser hervat een download van
    # gisteren. Zo'n link mag nooit een HTML-pagina opleveren: browsers en
    # downloadmanagers bewaren die gewoon als bestand, en dan vindt de deelnemer
    # een 'index.php' van vier kilobyte in plaats van zijn video.
    VERLOPEN=$(printf '%s' "$LINK" | sed 's/&t=[0-9]*/\&t=1/')
    OUD_KOP=$(curl -s -D - -o /dev/null -b "$KOEKJES" "$BASIS/$VERLOPEN")
    toets "verlopen link stuurt door" "302" "$(printf '%s' "$OUD_KOP" | head -1 | grep -o '[0-9]\{3\}')"
    bevat "naar een verse downloadlink" "Location:" "$OUD_KOP"
    bevat "en niet naar het overzicht" "download.php?b=" "$(printf '%s' "$OUD_KOP" | grep -i '^location:')"

    VERS_KOP=$(curl -s -L -D - -o /tmp/djm-vernieuwd.bin -b "$KOEKJES" "$BASIS/$VERLOPEN")
    bevat "de video komt alsnog als bijlage" "attachment" "$VERS_KOP"
    toets "en is compleet" "32768" "$(stat -c%s /tmp/djm-vernieuwd.bin)"
    bevat_niet "geen HTML in het gedownloade bestand" "<!DOCTYPE" "$(head -c 200 /tmp/djm-vernieuwd.bin)"

    # De rem op de lus: een link die zichzelf net vernieuwd zou hebben, wordt
    # niet nóg eens doorgestuurd.
    toets "vernieuwde link stuurt niet opnieuw door" "410" \
        "$(status "$BASIS/$VERLOPEN&v=$(date +%s)")"

    echo ""
    echo "── Download van een ander ──────────────────────────────────"
    ANDER=$(curl -s -o /dev/null -w '%{http_code}' "$BASIS/$LINK")
    toets "zonder sessie geen download" "403" "$ANDER"
else
    echo "  ✗ geen downloadlink gevonden in het portaal"; FOUT=$((FOUT+1))
fi

echo ""
echo "── Uitloggen ───────────────────────────────────────────────"
# Een GET mag niets doen: uitloggen vraagt om POST met een geldig CSRF-token.
haal -o /dev/null -L "$BASIS/logout.php"
toets "GET logt niet uit" "200" "$(status "$BASIS/portaal/index.php")"

PORTAAL=$(haal "$BASIS/portaal/index.php")
UIT_TOKEN=$(csrf "$PORTAAL")
haal -o /dev/null -L -X POST -d "csrf_token=$UIT_TOKEN" "$BASIS/logout.php"
toets "portaal weer afgeschermd" "302" "$(status "$BASIS/portaal/index.php")"

echo ""
echo "────────────────────────────────────────────────────────────"
printf '  %d geslaagd, %d mislukt\n\n' "$GOED" "$FOUT"
rm -f "$KOEKJES"
[ "$FOUT" -eq 0 ]
