#!/usr/bin/env bash
# Test de drie uitleveringsroutes op de webservers waar ze voor bedoeld zijn:
#   PHP-streaming (ingebouwde server), X-Accel-Redirect (nginx), X-Sendfile (Apache).
# Controleert per route: volledige download, hervatten met Range, en 416.
set -u
cd "$(dirname "$0")"
GOED=0; FOUT=0

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

# Logt een ouder in op de opgegeven basis-URL en zet de cookiejar klaar.
inloggen() {
    local basis="$1" jar="$2" adres="${3:-ouder@example.nl}"
    rm -f "$jar"
    curl -s -X DELETE http://localhost:8125/api/v1/messages >/dev/null 2>&1
    php_db "db()->exec('DELETE FROM aanvraag_limiet');" >/dev/null
    local h; h=$(curl -s -c "$jar" -b "$jar" "$basis/index.php")
    curl -s -o /dev/null -c "$jar" -b "$jar" -d "csrf_token=$(csrf "$h")" -d "email=$adres" "$basis/index.php"
    sleep 1
    local code; code=$(curl -s http://localhost:8125/api/v1/messages | python3 -c '
import sys,json,urllib.request,re
d=json.load(sys.stdin)
if not d["total"]: print(""); raise SystemExit
m=json.load(urllib.request.urlopen("http://localhost:8125/api/v1/message/"+d["messages"][0]["ID"]))
t=re.sub(r"<[^>]+>"," ",(m.get("HTML") or "")+(m.get("Text") or ""))
c=re.findall(r"\b\d{6}\b",t); print(c[0] if c else "")')
    if [ -z "$code" ]; then echo ""; return; fi
    local v; v=$(curl -s -c "$jar" -b "$jar" "$basis/verifieer.php")
    curl -s -o /dev/null -c "$jar" -b "$jar" -d "csrf_token=$(csrf "$v")" -d "code=$code" "$basis/verifieer.php"
    local p; p=$(curl -s -b "$jar" "$basis/portaal/index.php")
    printf '%s' "$p" | grep -o 'download\.php?b=[^"]*' | head -1 | sed 's/&amp;/\&/g'
}

# Doorloopt één route volledig.
route() {
    local naam="$1" basis="$2" verwachte_methode="$3"
    local grootte=0
    echo ""
    echo "── $naam ──────────────────────────────────────────────"
    local jar; jar=$(mktemp)
    local link; link=$(inloggen "$basis" "$jar")
    if [ -z "$link" ]; then
        printf '  ✗ inloggen of downloadlink mislukt op %s\n' "$basis"; FOUT=$((FOUT+1)); rm -f "$jar"; return
    fi

    # Welk bestand hoort bij deze link? Daar hangt de verwachte grootte van af.
    local bestand_id; bestand_id=$(printf '%s' "$link" | sed -n 's/.*b=\([0-9]*\).*/\1/p')
    grootte=$(php_db "echo (int)db()->query('SELECT bytes FROM jaargang_bestanden WHERE id = ' . (int)'$bestand_id')->fetchColumn();")

    local kop; kop=$(curl -s -D - -o /tmp/djm-route.bin -b "$jar" "$basis/$link")
    toets "volledige download geeft 200" "200" "$(printf '%s' "$kop" | head -1 | grep -o '[0-9]\{3\}')"
    toets "volledig bestand ontvangen" "$grootte" "$(stat -c%s /tmp/djm-route.bin)"
    bevat "als bijlage aangeboden" "attachment" "$kop"
    bevat "hervatten ondersteund" "accept-ranges" "$kop"

    # De interne header mag nooit bij de bezoeker terechtkomen: de webserver
    # hoort hem te consumeren. Lekt hij wel, dan staat het interne pad op straat.
    if printf '%s' "$kop" | grep -qi "x-accel-redirect\|x-sendfile"; then
        printf '  ✗ interne uitleveringsheader lekt naar de bezoeker\n'; FOUT=$((FOUT+1))
    else
        printf '  ✓ interne uitleveringsheader lekt niet naar de bezoeker\n'; GOED=$((GOED+1))
    fi

    local range; range=$(curl -s -D - -o /tmp/djm-route-deel.bin -b "$jar" -H "Range: bytes=100-199" "$basis/$link")
    toets "deelverzoek geeft 206" "206" "$(printf '%s' "$range" | head -1 | grep -o '[0-9]\{3\}')"
    toets "juiste hoeveelheid bytes" "100" "$(stat -c%s /tmp/djm-route-deel.bin)"
    bevat "Content-Range aanwezig" "content-range: bytes 100-199/$grootte" "$range"

    local ver; ver=$(curl -s -D - -o /dev/null -b "$jar" -H "Range: bytes=99999999999-" "$basis/$link")
    toets "onbereikbaar bereik geeft 416" "416" "$(printf '%s' "$ver" | head -1 | grep -o '[0-9]\{3\}')"

    # Het bestand mag nooit rechtstreeks te halen zijn, buiten download.php om.
    local direct; direct=$(curl -s -o /dev/null -w '%{http_code}' "$basis/opslag/2026/musical-2026.mp4")
    if [ "$verwachte_methode" = "php" ] && [ "$direct" = "200" ]; then
        printf '  – ingebouwde PHP-server kent geen .htaccess; niet van toepassing\n'
    elif [ "$direct" = "200" ]; then
        printf '  ✗ videobestand is rechtstreeks te downloaden (%s)\n' "$direct"; FOUT=$((FOUT+1))
    else
        printf '  ✓ videobestand niet rechtstreeks bereikbaar (%s)\n' "$direct"; GOED=$((GOED+1))
    fi
    local intern; intern=$(curl -s -o /dev/null -w '%{http_code}' "$basis/beveiligd/2026/musical-2026.mp4")
    if [ "$intern" = "200" ]; then
        printf '  ✗ het interne pad /beveiligd/ is van buitenaf bereikbaar\n'; FOUT=$((FOUT+1))
    else
        printf '  ✓ intern pad /beveiligd/ niet van buitenaf bereikbaar (%s)\n' "$intern"; GOED=$((GOED+1))
    fi

    # Welke route heeft de download daadwerkelijk afgehandeld?
    local gebruikt; gebruikt=$(php_db "echo (string)db()->query('SELECT methode FROM download_log ORDER BY id DESC LIMIT 1')->fetchColumn();")
    toets "afgehandeld via $verwachte_methode" "$verwachte_methode" "$gebruikt"

    rm -f "$jar"
}

echo "Testomgeving starten..."
docker compose up -d db mail web nginx apache >/dev/null 2>&1
for i in $(seq 1 60); do
    [ "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8123/index.php 2>/dev/null)" != "000" ] && break
    sleep 1
done
docker compose exec -T web php /app/test/mailinstellingen.php >/dev/null 2>&1

route "PHP-streaming (ingebouwde server)" "http://localhost:8123" "php"
route "nginx met X-Accel-Redirect"        "http://localhost:8126" "xaccel"
route "Apache met X-Sendfile"             "http://localhost:8127" "xsendfile"

echo ""
echo "────────────────────────────────────────────────────────────"
printf '  %d geslaagd, %d mislukt\n\n' "$GOED" "$FOUT"
[ "$FOUT" -eq 0 ]
