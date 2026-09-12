#!/usr/bin/env bash
# Beveiligingsregressies: de eigenschappen die deze applicatie veilig houden,
# gemeten aan de draaiende server in plaats van aan de broncode.
#
# Draait tegen alle drie de webservers waar de uitlevering voor bedoeld is:
# de ingebouwde PHP-server, nginx en Apache.
set -u
cd "$(dirname "$0")"
GOED=0; FOUT=0

toets() { # toets "omschrijving" "verwacht" "werkelijk"
    if [ "$2" = "$3" ]; then printf '  ✓ %s\n' "$1"; GOED=$((GOED+1));
    else printf '  ✗ %s\n      verwacht: %s\n      kreeg:    %s\n' "$1" "$2" "$3"; FOUT=$((FOUT+1)); fi
}
bevat() { # bevat "omschrijving" "naald" "hooiberg"
    if printf '%s' "$3" | grep -qiF "$2"; then printf '  ✓ %s\n' "$1"; GOED=$((GOED+1));
    else printf '  ✗ %s (miste: %s)\n' "$1" "$2"; FOUT=$((FOUT+1)); fi
}
bevat_niet() {
    if printf '%s' "$3" | grep -qiF "$2"; then printf '  ✗ %s (vond: %s)\n' "$1" "$2"; FOUT=$((FOUT+1));
    else printf '  ✓ %s\n' "$1"; GOED=$((GOED+1)); fi
}
status() { curl -s -o /dev/null -w '%{http_code}' "$1"; }

docker compose up -d db mail web nginx apache >/dev/null 2>&1
for i in $(seq 1 60); do
    [ "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8123/index.php 2>/dev/null)" != "000" ] && break
    sleep 1
done
# smoke.php zet de database opnieuw op; mailinstellingen.php wijst de mail daarna
# weer naar Mailpit. Die tweede stap hoort erbij: zonder dat draait een volgende
# test in test/alles.sh zonder werkende mailconfiguratie.
docker compose exec -T web php /app/test/smoke.php >/dev/null 2>&1
docker compose exec -T web php /app/test/mailinstellingen.php >/dev/null 2>&1

echo "── Securityheaders op de inlogpagina ───────────────────────"
KOPPEN=$(curl -s -D - -o /dev/null http://localhost:8123/index.php)
bevat     "Content-Security-Policy aanwezig"      "content-security-policy:" "$KOPPEN"
bevat     "script-src staat alleen 'self' toe"    "script-src 'self';" "$KOPPEN"
bevat_niet "geen 'unsafe-inline' in script-src"   "script-src 'self' 'unsafe-inline'" "$KOPPEN"
bevat_niet "geen extern script-adres"             "cdn.jsdelivr.net" "$KOPPEN"
bevat     "object-src is dichtgezet"              "object-src 'none'" "$KOPPEN"
bevat     "frame-ancestors 'none'"                "frame-ancestors 'none'" "$KOPPEN"
bevat     "form-action 'self'"                    "form-action 'self'" "$KOPPEN"
bevat     "X-Content-Type-Options: nosniff"       "x-content-type-options: nosniff" "$KOPPEN"
bevat     "X-Frame-Options: DENY"                 "x-frame-options: deny" "$KOPPEN"
bevat     "Referrer-Policy gezet"                 "referrer-policy:" "$KOPPEN"
bevat     "pagina's worden niet gecachet"         "cache-control: no-store" "$KOPPEN"
bevat     "zoekmachines worden geweerd"           "noindex" "$(curl -s http://localhost:8123/index.php)"

echo ""
echo "── Sessiecookie ────────────────────────────────────────────"
bevat "sessiecookie is httponly" "httponly" "$KOPPEN"
bevat "sessiecookie is samesite" "samesite=lax" "$KOPPEN"

echo ""
echo "── Geen inline JavaScript meer in de pagina's ──────────────"
# De CSP blokkeert het toch; dit vangt het af vóór een beheerder een lege
# knop in handen krijgt.
# Regels die met *, // of # beginnen zijn PHP-commentaar en tellen niet mee;
# daar staat het woord "onsubmit" juist in als uitleg.
INLINE=""
for BESTAND in $(find ../admin ../includes ../portaal ../index.php ../verifieer.php \
        ../download.php ../setup.php -name '*.php' 2>/dev/null | sort); do
    if grep -vE '^[[:space:]]*(\*|//|#)' "$BESTAND" \
        | grep -qE '<script>|on(click|change|submit|load)[[:space:]]*='; then
        INLINE="$INLINE$BESTAND\n"
    fi
done
INLINE=$(printf '%b' "$INLINE" | sed '/^$/d')
if [ -z "$INLINE" ]; then
    printf '  ✓ geen inline script of gebeurtenis-attribuut gevonden\n'; GOED=$((GOED+1))
else
    printf '  ✗ inline JavaScript gevonden in:\n'; printf '%s\n' "$INLINE" | sed 's/^/      /'; FOUT=$((FOUT+1))
fi
toets "admin.js wordt uitgeleverd" "200" "$(status http://localhost:8123/admin/assets/admin.js)"

echo ""
echo "── Geen externe bronnen meer in de pagina's ────────────────"
# De CSP staat alleen 'self' toe. Blijft er ergens een CDN-adres staan, dan
# blokkeert de browser het en valt de opmaak of een knop weg.
EXTERN=$(grep -rlE '(src|href)="https?://' ../admin ../includes ../portaal ../index.php \
    ../verifieer.php ../download.php ../setup.php 2>/dev/null || true)
if [ -z "$EXTERN" ]; then
    printf '  ✓ alle stijlen en scripts komen uit assets/ van deze installatie\n'; GOED=$((GOED+1))
else
    printf '  ✗ extern adres gevonden in:\n'; printf '%s\n' "$EXTERN" | sed 's/^/      /'; FOUT=$((FOUT+1))
fi

echo ""
echo "── Een vervalste X-Forwarded-For verandert niets ───────────"
# In deze testomgeving staat TRUSTED_PROXIES niet ingesteld. Een bezoeker die
# zelf een doorstuurheader meestuurt, mag dan géén ander IP-adres in het logboek
# krijgen; anders is de rate limiting op inlogcodes met één header te omzeilen.
docker compose exec -T db mariadb -u root -pdjmtest djm_portaal \
    -e "DELETE FROM login_log; DELETE FROM aanvraag_limiet;" >/dev/null 2>&1
K=$(mktemp)
FORM=$(curl -s -c "$K" -b "$K" http://localhost:8123/index.php)
TOKEN=$(printf '%s' "$FORM" | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | cut -d'"' -f4)
curl -s -o /dev/null -c "$K" -b "$K" -H "X-Forwarded-For: 198.51.100.77" \
    -d "csrf_token=$TOKEN" -d "email=ouder@example.nl" http://localhost:8123/index.php
rm -f "$K"
GELOGD=$(docker compose exec -T db mariadb -u root -pdjmtest djm_portaal -N \
    -e "SELECT INET6_NTOA(ip) FROM login_log ORDER BY id DESC LIMIT 1;" 2>/dev/null | tr -d '\r')
if [ "$GELOGD" = "198.51.100.77" ]; then
    printf '  ✗ het verzonnen adres staat in het logboek (%s)\n' "$GELOGD"; FOUT=$((FOUT+1))
elif [ -z "$GELOGD" ]; then
    printf '  ✗ geen logregel gevonden om te controleren\n'; FOUT=$((FOUT+1))
else
    printf '  ✓ logboek noteert het echte adres (%s), niet het verzonnen adres\n' "$GELOGD"; GOED=$((GOED+1))
fi

echo ""
echo "── Uitloggen kan niet met een GET ──────────────────────────"
toets "GET op logout.php stuurt door"       "302" "$(status http://localhost:8123/logout.php)"
toets "GET op admin/logout.php stuurt door" "302" "$(status http://localhost:8123/admin/logout.php)"

echo ""
echo "── Afgeschermde paden per webserver ────────────────────────"
for SERVER in "PHP-server|http://localhost:8123" "nginx|http://localhost:8126" "Apache|http://localhost:8127"; do
    NAAM=${SERVER%%|*}; BASIS=${SERVER#*|}
    printf '  %s\n' "$NAAM"
    for PAD in /.env /db.sql /includes/auth.php /logs/app.log /opslag/2026/musical-2026.mp4 /README.md; do
        CODE=$(status "$BASIS$PAD")
        if [ "$NAAM" = "PHP-server" ]; then
            # De ingebouwde PHP-server kent geen .htaccess en is nooit voor
            # productie bedoeld; alleen melden, niet afkeuren.
            printf '    – %-32s %s (niet van toepassing)\n' "$PAD" "$CODE"
        elif [ "$CODE" = "200" ]; then
            printf '    ✗ %-32s is bereikbaar (200)\n' "$PAD"; FOUT=$((FOUT+1))
        else
            printf '    ✓ %-32s afgeschermd (%s)\n' "$PAD" "$CODE"; GOED=$((GOED+1))
        fi
    done
done

echo ""
echo "── Beheer eist een sessie ──────────────────────────────────"
for PAD in index.php jaargangen.php bestanden.php bestandscontrole.php toegang.php \
           deelnemers.php logboek.php instellingen.php; do
    toets "admin/$PAD stuurt door naar inloggen" "302" "$(status "http://localhost:8123/admin/$PAD")"
done

echo ""
echo "── Eenheidstests op de beveiligingshelpers ─────────────────"
docker compose exec -T web php /app/test/beveiliging.php || FOUT=$((FOUT+1))

echo ""
echo "── Opslagmap binnen de webroot (het Plesk-scenario) ────────"
# Op Plesk staat nginx vóór Apache en levert nginx statische bestanden zelf uit,
# gekozen op extensie. .htaccess doet dan niets meer voor een .mp4. Twee dingen
# moeten kloppen:
#   1. de zelftest MOET dat kunnen zien (anders stempelt hij ten onrechte groen);
#   2. op een server waar .htaccess wél geldt, moet de map dicht zijn.

# 1. De ingebouwde PHP-server kent geen .htaccess: daar hoort de zelftest te
#    klagen, en wel per videoformaat.
BLOOT=$(docker compose exec -T -e OPSLAG_PAD=/app/opslag web \
    php /app/test/zelftest_cli.php http://localhost:8080 2>&1)
if printf '%s' "$BLOOT" | grep -q "^FOUT Niet rechtstreeks bereikbaar"; then
    printf '  ✓ zelftest ziet een open opslagmap\n'; GOED=$((GOED+1))
else
    printf '  ✗ zelftest ziet een open opslagmap NIET — de controle is waardeloos\n'; FOUT=$((FOUT+1))
fi
if printf '%s' "$BLOOT" | grep -q "opslagmap (.mp4)"; then
    printf '  ✓ en meldt het videoformaat apart (.mp4)\n'; GOED=$((GOED+1))
else
    printf '  ✗ .mp4 wordt niet apart geprobeerd; een extensiegebonden lek blijft dan onzichtbaar\n'; FOUT=$((FOUT+1))
fi

# 2. Apache mét .htaccess hoort de map wél dicht te houden, ook per formaat.
DICHT=$(docker compose exec -T -e OPSLAG_PAD=/var/www/html/opslag apache \
    php /var/www/html/test/zelftest_cli.php http://localhost:80 2>&1)
if printf '%s' "$DICHT" | grep -q "^OK   Niet rechtstreeks bereikbaar"; then
    printf '  ✓ met .htaccess is de map dicht, ook per videoformaat\n'; GOED=$((GOED+1))
else
    printf '  ✗ .htaccess schermt de opslagmap niet af\n'; FOUT=$((FOUT+1))
    printf '%s\n' "$DICHT" | grep "Niet rechtstreeks" | sed 's/^/      /'
fi

echo ""
echo "── Achter een reverse proxy ────────────────────────────────"
# Eigen proces: de proxylijst wordt per proces één keer ingelezen.
docker compose exec -T -e TRUSTED_PROXIES="10.0.0.0/8,172.16.0.0/12" \
    web php /app/test/proxy_test.php || FOUT=$((FOUT+1))

echo ""
echo "────────────────────────────────────────────────────────────"
printf '  %d geslaagd, %d mislukt\n\n' "$GOED" "$FOUT"
[ "$FOUT" -eq 0 ]
