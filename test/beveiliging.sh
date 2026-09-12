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
echo "────────────────────────────────────────────────────────────"
printf '  %d geslaagd, %d mislukt\n\n' "$GOED" "$FOUT"
[ "$FOUT" -eq 0 ]
