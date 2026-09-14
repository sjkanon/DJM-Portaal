#!/usr/bin/env bash
# Test de drie uitleveringsroutes op de webservers waar ze voor bedoeld zijn:
#   PHP-streaming (ingebouwde server), X-Accel-Redirect (nginx), X-Sendfile (Apache).
# Controleert per route: volledige download, hervatten met Range, en 416.
# Draait daarna per route ook de zelftest die in Beheer > Instellingen achter de
# knop "Uitproberen" zit, zodat die dezelfde routes dekt als deze test zelf.
set -u
cd "$(dirname "$0")"
. ./hostpad.sh
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

    # Het logboek moet eerlijk zijn over wat het weet. Levert de webserver uit,
    # dan komt er geen byte langs PHP: dat heet "webserver", niet "afgerond".
    # De sleutel maakt de regel later terugvindbaar in het log van die webserver.
    # Kijk naar de regel van de vólledige download, niet naar de laatste: daarna
    # kwamen nog een deelverzoek en een onmogelijk bereik langs.
    local boekhouding
    boekhouding=$(php_db "\$r = db()->query('SELECT reden, LENGTH(COALESCE(sleutel, \"\")) AS s
                                              FROM download_log
                                             WHERE bytes_verzonden > 0 OR reden = \"webserver\"
                                             ORDER BY id DESC LIMIT 1')->fetch();
                          echo \$r['reden'], ' ', (int)\$r['s'];")
    if [ "$verwachte_methode" = "php" ]; then
        toets "logboek: zelf gemeten, met sleutel" "voltooid 16" "$boekhouding"
        # Een onmogelijk bereik levert niets op, maar moet de regel wél afsluiten.
        # Anders blijft hij op 'bezig' staan en lijkt hij eeuwig te lopen.
        toets "onmogelijk bereik sluit de logregel af" "afgewezen" \
            "$(php_db "echo (string)db()->query('SELECT reden FROM download_log ORDER BY id DESC LIMIT 1')->fetchColumn();")"
    else
        toets "logboek: niet gemeten, met sleutel" "webserver 16" "$boekhouding"
    fi

    rm -f "$jar"
}

# Draait de zelftest uit het beheer op één route. Die legt zelf een testbestand
# klaar, haalt het over HTTP op en ruimt het weer op; wij controleren alleen of
# hij de juiste route herkent en alle stappen haalt.
zelftest() {
    local naam="$1" dienst="$2" script="$3" basis="$4" verwacht="$5" htaccess="$6"
    echo ""
    echo "── zelftest uit het beheer: $naam ─────────────────────"
    local uit; uit=$(docker compose exec -T "$dienst" php "$script" "$basis" 2>&1)

    bevat "route herkend als $verwacht" "gemeld door de server: $verwacht" "$uit"

    local stap
    for stap in "Volledige download" "Hervatten (HTTP Range)" "Onmogelijk bereik afwijzen"; do
        if printf '%s' "$uit" | grep -q "^OK   $stap"; then
            printf '  ✓ %s\n' "$stap"; GOED=$((GOED+1))
        else
            printf '  ✗ %s\n' "$stap"; FOUT=$((FOUT+1))
            printf '%s\n' "$uit" | sed 's/^/      /'
        fi
    done

    # De ingebouwde PHP-server kent geen .htaccess, dus daar ís de opslagmap
    # publiek. De zelftest meldt dat terecht; hier telt het niet als fout.
    if [ "$htaccess" = "nee" ]; then
        printf '  – ingebouwde PHP-server kent geen .htaccess; afschermingsstap niet van toepassing\n'
    elif printf '%s' "$uit" | grep -qE "^(OK|OVER) +Niet rechtstreeks bereikbaar"; then
        printf '  ✓ Niet rechtstreeks bereikbaar\n'; GOED=$((GOED+1))
    else
        printf '  ✗ Niet rechtstreeks bereikbaar\n'; FOUT=$((FOUT+1))
        printf '%s\n' "$uit" | sed 's/^/      /'
    fi
}

echo "Testomgeving starten..."
docker compose up -d db mail web nginx apache fpm >/dev/null 2>&1
for i in $(seq 1 60); do
    [ "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8123/index.php 2>/dev/null)" != "000" ] && break
    sleep 1
done
docker compose exec -T web php /app/test/mailinstellingen.php >/dev/null 2>&1

route "PHP-streaming (ingebouwde server)" "http://localhost:8123" "php"
route "nginx met X-Accel-Redirect"        "http://localhost:8126" "xaccel"
route "Apache met X-Sendfile"             "http://localhost:8127" "xsendfile"

zelftest "PHP-streaming" web    /app/test/zelftest_cli.php          http://localhost:8080 php       nee
zelftest "nginx"         fpm    /app/test/zelftest_cli.php          http://nginx:8080     xaccel    ja
zelftest "Apache"        apache /var/www/html/test/zelftest_cli.php http://localhost      xsendfile ja

echo ""
echo "────────────────────────────────────────────────────────────"
printf '  %d geslaagd, %d mislukt\n\n' "$GOED" "$FOUT"
[ "$FOUT" -eq 0 ]
