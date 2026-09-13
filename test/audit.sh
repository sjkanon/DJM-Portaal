#!/usr/bin/env bash
# Statische controle op de veelvoorkomende fouten in deze codebase.
cd "$(dirname "$0")/.."
BESTANDEN=$(find . -name "*.php" -not -path "./test/*" -not -path "./.git/*" | sort)
PROBLEMEN=0

melden() { printf '  ✗ %s\n' "$1"; PROBLEMEN=$((PROBLEMEN+1)); }

echo "── SQL-injectie: variabelen in queries ─────────────────────"
for f in $BESTANDEN; do
    # Zoek query-strings met PHP-variabele-interpolatie erin.
    TREFFERS=$(grep -nE "(query|prepare|exec)\s*\(\s*[\"'][^\"']*\\\$[a-zA-Z_]" "$f" | grep -vE '\$plaatshouders|\$alias|\$sql|\$tabel|\$velden|\$kolommen|\$richting|\$sortering|\$volgorde|\$limiet|\$offset|\$vervalt' )
    [ -n "$TREFFERS" ] && { melden "$f"; printf '%s\n' "$TREFFERS" | head -3 | sed 's/^/      /'; }
done
[ "$PROBLEMEN" -eq 0 ] && echo "  ✓ geen directe interpolatie in queries gevonden"

echo ""
echo "── LIMIT/OFFSET: moeten ints zijn, geen ruwe invoer ────────"
VOOR=$PROBLEMEN
for f in $BESTANDEN; do
    TREFFERS=$(grep -nE "LIMIT\s+\\\$|OFFSET\s+\\\$" "$f" | grep -vE '\(int\)|intval|\$limiet|\$offset|\$perPagina|\$aantal|\$start')
    [ -n "$TREFFERS" ] && { melden "$f"; printf '%s\n' "$TREFFERS" | head -3 | sed 's/^/      /'; }
done
[ "$PROBLEMEN" -eq "$VOOR" ] && echo "  ✓ LIMIT/OFFSET overal via gecaste gehele getallen"

echo ""
echo "── Named parameters: hooguit één keer per query ────────────"
python3 test/audit_params.py || PROBLEMEN=$((PROBLEMEN+1))

echo ""
echo "── CSRF: formulieren én de verwerking ervan ────────────────"
VOOR=$PROBLEMEN
for f in $BESTANDEN; do
    # Elk POST-formulier stuurt een token mee.
    if grep -qE 'method="post"|method=.post.' "$f" && ! grep -q "csrf_field()" "$f"; then
        melden "$f: formulier zonder csrf_field()"
    fi
    # En elk bestand dat een POST verwerkt, controleert dat token. Het formulier
    # en de verwerking hoeven niet in hetzelfde bestand te staan (het uitlogknopje
    # staat in de layout, de controle in logout.php).
    if grep -qE '\$_POST\[|\$_FILES\[' "$f" \
        && ! grep -qE "vereis_csrf\(\)|verify_csrf\(" "$f"; then
        melden "$f: leest \$_POST maar controleert geen CSRF-token"
    fi
done
[ "$PROBLEMEN" -eq "$VOOR" ] && echo "  ✓ alle formulieren zijn CSRF-beveiligd"

echo ""
echo "── Uitvoer: echo van variabelen zonder h() ─────────────────"
VOOR=$PROBLEMEN
for f in $BESTANDEN; do
    # Ternaries met vaste teksten en gehele getallen zijn geen risico; die filteren we weg.
    TREFFERS=$(grep -nE '<\?=\s*\$[a-zA-Z_]' "$f" \
        | grep -vE 'h\(|\(int\)|number_format|formatteer_|urlencode|json_encode|date\(|implode|count\(' \
        | grep -vE '\?[^:]*:' \
        | grep -vE '\$[a-zA-Z_]*([Ii]d|[Nn]ummer|[Pp]agina|[Jj]aar|[Bb]ytes|[Tt]eller|i|n)\b\s*\?>' \
        | grep -vFf <(grep "^${f#./}:" test/audit-uitzonderingen.txt | cut -d: -f2- | cut -d'#' -f1 | sed 's/[[:space:]]*$//' ) 2>/dev/null || true)
    [ -n "$TREFFERS" ] && { melden "$f"; printf '%s\n' "$TREFFERS" | head -3 | sed 's/^/      /'; }
done
[ "$PROBLEMEN" -eq "$VOOR" ] && echo "  ✓ geen onbeschermde uitvoer gevonden"

echo ""
echo "── Beheerpagina's: autorisatie aanwezig ────────────────────"
VOOR=$PROBLEMEN
for f in admin/*.php; do
    # De pagina's rond het wachtwoord zijn juist bedoeld voor wie niet is ingelogd.
    case "$(basename "$f")" in login.php|logout.php|wachtwoord_vergeten.php|wachtwoord_instellen.php) continue;; esac
    grep -q "vereis_beheerder()" "$f" || melden "$f: roept vereis_beheerder() niet aan"
done
[ "$PROBLEMEN" -eq "$VOOR" ] && echo "  ✓ elke beheerpagina eist een beheerderssessie"

echo ""
echo "── Geheimen: niets hardgecodeerd ───────────────────────────"
VOOR=$PROBLEMEN
TREFFERS=$(grep -rnE "(client_secret|wachtwoord|password|api_key)\s*=\s*[\"'][A-Za-z0-9+/_~.-]{16,}[\"']" $BESTANDEN 2>/dev/null | grep -v "example")
[ -n "$TREFFERS" ] && { melden "mogelijk hardgecodeerd geheim"; printf '%s\n' "$TREFFERS" | head -5 | sed 's/^/      /'; }
[ "$PROBLEMEN" -eq "$VOOR" ] && echo "  ✓ geen hardgecodeerde geheimen"

echo ""
echo "── Opmaak: geen externe bronnen, alle assets aanwezig ──────"
VOOR=$PROBLEMEN
# Het portaal moet het zonder internet doen. Een <script>, <link> of @import
# naar een ander domein zou betekenen dat de site stukgaat zodra dat domein
# onbereikbaar is — en dat de Content-Security-Policy weer opgerekt moet worden.
TREFFERS=$(grep -rnE '(src|href)="https?://|@import[^;]*https?://' $BESTANDEN assets/*.css 2>/dev/null)
[ -n "$TREFFERS" ] && { melden "externe bron in de opmaak"; printf '%s\n' "$TREFFERS" | sed 's/^/      /'; }

# Elk bestand dat via djm_asset() of url() als script/stijl wordt ingeladen,
# moet ook echt bestaan. Anders staat de site na een deploy zonder opmaak en
# ziet niemand dat aan de PHP-syntaxcontrole.
for PAD in $(grep -rhoE "djm_asset\('[^']+'\)" $BESTANDEN | sed "s/djm_asset('//;s/')//" | sort -u); do
    [ -f "$PAD" ] || melden "verwezen bestand ontbreekt: $PAD"
done
# En de lettertypes waar bootstrap-icons.css naar wijst.
for FONT in $(grep -oE 'url\("[^"]+\.woff2?[^"]*"\)' assets/vendor/bootstrap-icons.css 2>/dev/null \
        | sed 's/url("//;s/".*//;s/?.*//'); do
    [ -f "assets/vendor/$FONT" ] || melden "lettertype ontbreekt: assets/vendor/$FONT"
done
[ "$PROBLEMEN" -eq "$VOOR" ] && echo "  ✓ alle opmaak komt uit deze installatie en is aanwezig"

echo ""
echo "── Handleiding: elk beheerscherm beschreven ────────────────"
VOOR=$PROBLEMEN
# Beheer › Handleiding moet meegroeien met de software. Een nieuw scherm in het
# menu zonder uitleg laat de knop "Uitleg" in het niets springen; een
# schermafdruk die ontbreekt valt stilletjes weg. Allebei laten we hier vallen.
for SCHERM in $(sed -n '/function admin_menu/,/^}/p' admin/includes/layout.php | grep -oE "'[a-z_]+\.php'" | tr -d "'"); do
    grep -q "data-scherm=\"$SCHERM\"" admin/handleiding.php \
        || melden "admin/handleiding.php: geen uitleg bij $SCHERM (blok met data-scherm=\"$SCHERM\")"
done
for NAAM in $(grep -oE "handleiding_figuur\('[a-z0-9-]+'" admin/handleiding.php | sed "s/.*('//;s/'//" | sort -u); do
    [ -f "assets/handleiding/$NAAM.webp" ] \
        || melden "schermafdruk ontbreekt: assets/handleiding/$NAAM.webp (maak hem met bash test/handleiding.sh)"
done
[ "$PROBLEMEN" -eq "$VOOR" ] && echo "  ✓ elk beheerscherm staat in de handleiding, met schermafdrukken"
# Geen fout, wel een seintje: schermafdrukken van een oudere versie.
if [ -f assets/handleiding/versie.txt ] && [ "$(head -n 1 assets/handleiding/versie.txt)" != "$(tr -d '[:space:]' < VERSION)" ]; then
    echo "  – schermafdrukken zijn van versie $(head -n 1 assets/handleiding/versie.txt), het portaal is $(tr -d '[:space:]' < VERSION): draai bash test/handleiding.sh"
fi

echo ""
echo "── Testscripts weigeren een webverzoek ─────────────────────"
VOOR=$PROBLEMEN
# test/ hoort niet op een server te staan, maar hostingpanelen uploaden nu
# eenmaal hele mappen. smoke.php leegt de deelnemerstabel; dat mag nooit met
# een browserverzoek te starten zijn.
for f in test/*.php; do
    grep -q "PHP_SAPI" "$f" || melden "$f: geen controle op PHP_SAPI === 'cli'"
done
[ "$PROBLEMEN" -eq "$VOOR" ] && echo "  ✓ elk testscript draait alleen op de commandoregel"

echo ""
echo "── Bezoekersadres: alleen via client_ip() ──────────────────"
VOOR=$PROBLEMEN
# Wie zelf REMOTE_ADDR of X-Forwarded-For uitleest, omzeilt de controle op
# vertrouwde proxy's in client_ip() en https_actief(). config.php is de enige
# plek waar dat mag; admin/index.php kijkt alleen of de header er ís.
for f in $BESTANDEN; do
    case "${f#./}" in config.php|admin/index.php) continue;; esac
    TREFFERS=$(grep -nE "\\\$_SERVER\[.(REMOTE_ADDR|HTTP_X_FORWARDED_FOR|HTTP_X_FORWARDED_PROTO|HTTP_X_REAL_IP|HTTP_CF_CONNECTING_IP)" "$f")
    [ -n "$TREFFERS" ] && { melden "$f: leest het bezoekersadres buiten client_ip() om"; printf '%s\n' "$TREFFERS" | head -3 | sed 's/^/      /'; }
done
[ "$PROBLEMEN" -eq "$VOOR" ] && echo "  ✓ het bezoekersadres loopt overal via client_ip()"

echo ""
echo "── Bestandspaden: alleen via opslag_absoluut_pad() ─────────"
VOOR=$PROBLEMEN
TREFFERS=$(grep -rnE "(readfile|fopen|file_get_contents|unlink)\s*\(\s*\\\$_(GET|POST|REQUEST)" $BESTANDEN 2>/dev/null)
[ -n "$TREFFERS" ] && { melden "bestandsbewerking direct op gebruikersinvoer"; printf '%s\n' "$TREFFERS" | sed 's/^/      /'; }
[ "$PROBLEMEN" -eq "$VOOR" ] && echo "  ✓ geen bestandsbewerkingen op ruwe invoer"

echo ""
echo "────────────────────────────────────────────────────────────"
printf '  %d bevindingen\n\n' "$PROBLEMEN"
[ "$PROBLEMEN" -eq 0 ]
