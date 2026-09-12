#!/usr/bin/env bash
# Controleert het gedrag bij een video van 5 GB: worden groottes en offsets
# voorbij de 2 GB-grens correct afgehandeld, op alle drie de uitleveringsroutes?
# Er wordt bewust niet 5 GB doorgesluisd; met deelverzoeken aan het einde van
# het bestand is hetzelfde te bewijzen zonder het netwerk vol te trekken.
set -u
cd "$(dirname "$0")"
GOED=0; FOUT=0
GROOT=5368709120                       # 5 GiB
BESTAND=../opslag/2028/musical-2028.mp4

toets() {
    if [ "$2" = "$3" ]; then printf '  ✓ %s\n' "$1"; GOED=$((GOED+1));
    else printf '  ✗ %s\n      verwacht: %s\n      kreeg:    %s\n' "$1" "$2" "$3"; FOUT=$((FOUT+1)); fi
}
bevat() {
    if printf '%s' "$3" | grep -qiF "$2"; then printf '  ✓ %s\n' "$1"; GOED=$((GOED+1));
    else printf '  ✗ %s (miste: %s)\n' "$1" "$2"; FOUT=$((FOUT+1)); fi
}
csrf() { printf '%s' "$1" | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | cut -d'"' -f4; }
php_db() { docker compose exec -T web php -r "\$_SERVER['SCRIPT_NAME']='/index.php'; require '/app/config.php'; $1" 2>/dev/null | tr -d '\r'; }

if [ ! -f "$BESTAND" ]; then
    echo "  Overgeslagen: het testbestand van 5 GB staat er niet."
    echo "  Aanmaken met (kost geen schijfruimte, het bestand is sparse):"
    echo "      truncate -s 5G \"$BESTAND\""
    exit 0
fi

echo "── Jaargang met een bestand van 5 GB klaarzetten ───────────"
OPZET=$(docker compose exec -T web php /app/test/grootbestand_setup.php 2>&1 | tr -d '\r')
echo "  $OPZET"
IN_DB=$(printf '%s' "$OPZET" | python3 -c 'import sys,json;print(json.load(sys.stdin)["bytes_in_db"])' 2>/dev/null)
OP_SCHIJF=$(printf '%s' "$OPZET" | python3 -c 'import sys,json;print(json.load(sys.stdin)["bytes_op_schijf"])' 2>/dev/null)
toets "grootte blijft heel in de database" "$GROOT" "${IN_DB:-fout}"
toets "PHP leest de grootte goed van schijf" "$GROOT" "${OP_SCHIJF:-fout}"

# Inloggen als ouder op de opgegeven server en de downloadlink van 2028 pakken.
inloggen() {
    local basis="$1" jar="$2"
    rm -f "$jar"
    curl -s -X DELETE http://localhost:8125/api/v1/messages >/dev/null 2>&1
    php_db "db()->exec('DELETE FROM aanvraag_limiet');" >/dev/null
    local h; h=$(curl -s -c "$jar" -b "$jar" "$basis/index.php")
    curl -s -o /dev/null -c "$jar" -b "$jar" -d "csrf_token=$(csrf "$h")" -d "email=ouder@example.nl" "$basis/index.php"
    sleep 1
    local code; code=$(curl -s http://localhost:8125/api/v1/messages | python3 -c '
import sys,json,urllib.request,re
d=json.load(sys.stdin)
if not d["total"]: print(""); raise SystemExit
m=json.load(urllib.request.urlopen("http://localhost:8125/api/v1/message/"+d["messages"][0]["ID"]))
t=re.sub(r"<[^>]+>"," ",(m.get("HTML") or "")+(m.get("Text") or ""))
c=re.findall(r"\b\d{6}\b",t); print(c[0] if c else "")')
    [ -z "$code" ] && { echo ""; return; }
    local v; v=$(curl -s -c "$jar" -b "$jar" "$basis/verifieer.php")
    curl -s -o /dev/null -c "$jar" -b "$jar" -d "csrf_token=$(csrf "$v")" -d "code=$code" "$basis/verifieer.php"
    local p; p=$(curl -s -b "$jar" "$basis/portaal/index.php")
    # De jaargang van 2028 staat bovenaan; pak de link uit dat blok.
    printf '%s' "$p" | grep -o 'download\.php?b=[^"]*' | head -1 | sed 's/&amp;/\&/g'
}

route() {
    local naam="$1" basis="$2"
    echo ""
    echo "── $naam ──────────────────────────────────────────────"
    local jar; jar=$(mktemp)
    local link; link=$(inloggen "$basis" "$jar")
    if [ -z "$link" ]; then printf '  ✗ inloggen mislukt\n'; FOUT=$((FOUT+1)); rm -f "$jar"; return; fi

    # Eerste honderd bytes: bewijst dat de totale grootte 64-bits correct is.
    local begin; begin=$(curl -s -D - -o /tmp/djm-groot-begin.bin -b "$jar" -H "Range: bytes=0-99" "$basis/$link")
    toets "deelverzoek aan het begin geeft 206" "206" "$(printf '%s' "$begin" | head -1 | grep -o '[0-9]\{3\}')"
    bevat "totale grootte klopt in Content-Range" "/$GROOT" "$begin"
    toets "honderd bytes ontvangen" "100" "$(stat -c%s /tmp/djm-groot-begin.bin)"

    # Bereik voorbij de 4 GB-grens: bewijst dat fseek met grote offsets werkt.
    local ver_start=$((GROOT - 1000))
    local ver_eind=$((GROOT - 901))
    local ver; ver=$(curl -s -D - -o /tmp/djm-groot-eind.bin -b "$jar" -H "Range: bytes=$ver_start-$ver_eind" "$basis/$link")
    toets "deelverzoek voorbij 4 GB geeft 206" "206" "$(printf '%s' "$ver" | head -1 | grep -o '[0-9]\{3\}')"
    bevat "juiste Content-Range ver in het bestand" "bytes $ver_start-$ver_eind/$GROOT" "$ver"
    toets "honderd bytes ontvangen uit de staart" "100" "$(stat -c%s /tmp/djm-groot-eind.bin)"

    # De laatste bytes via bytes=-n.
    local staart; staart=$(curl -s -D - -o /tmp/djm-groot-staart.bin -b "$jar" -H "Range: bytes=-50" "$basis/$link")
    toets "laatste vijftig bytes geeft 206" "206" "$(printf '%s' "$staart" | head -1 | grep -o '[0-9]\{3\}')"
    toets "vijftig bytes ontvangen" "50" "$(stat -c%s /tmp/djm-groot-staart.bin)"

    # Content-Length van de volledige download, zonder 5 GB op te halen.
    local vol; vol=$(curl -s -D /tmp/djm-groot-kop.txt -o /dev/null -b "$jar" --max-time 20 --limit-rate 1M "$basis/$link" || true)
    bevat "volledige download meldt 5 GB" "content-length: $GROOT" "$(cat /tmp/djm-groot-kop.txt)"

    rm -f "$jar"
}

echo ""
echo "Testomgeving starten..."
docker compose up -d db mail web nginx apache >/dev/null 2>&1
for i in $(seq 1 60); do
    [ "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8123/index.php 2>/dev/null)" != "000" ] && break
    sleep 1
done
docker compose exec -T web php /app/test/mailinstellingen.php >/dev/null 2>&1

route "PHP-streaming (ingebouwde server)" "http://localhost:8123"
route "nginx met X-Accel-Redirect"        "http://localhost:8126"
route "Apache met X-Sendfile"             "http://localhost:8127"

echo ""
echo "── Geheugengebruik van PHP bij het streamen ────────────────"
PIEK=$(docker compose exec -T web php /app/test/grootbestand_geheugen.php 2>/dev/null | tr -d '\r')
toets "PHP blijft onder de 16 MB geheugen" "1" "$([ "${PIEK:-999}" -lt 16 ] && echo 1 || echo 0)"
printf '    piekgebruik: %s MB bij 16 MB gelezen vanaf offset 4 GB\n' "${PIEK:-?}"

echo ""
echo "── Opruimen ────────────────────────────────────────────────"
docker compose exec -T web php /app/test/grootbestand_opruimen.php >/dev/null 2>&1
echo "  testjaargang verwijderd (het sparse bestand blijft staan)"

echo ""
echo "────────────────────────────────────────────────────────────"
printf '  %d geslaagd, %d mislukt\n\n' "$GOED" "$FOUT"
[ "$FOUT" -eq 0 ]
