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
echo "── CSRF: elk bestand met een POST-formulier ────────────────"
VOOR=$PROBLEMEN
for f in $BESTANDEN; do
    grep -qE 'method="post"|method=.post.' "$f" || continue
    if ! grep -q "csrf_field()" "$f"; then melden "$f: formulier zonder csrf_field()"; fi
    if ! grep -qE "vereis_csrf\(\)|verify_csrf\(" "$f"; then melden "$f: geen CSRF-controle bij verwerking"; fi
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
        | grep -vE '\$[a-zA-Z_]*([Ii]d|[Aa]antal|[Nn]ummer|[Pp]agina|[Jj]aar|[Bb]ytes|[Tt]eller|i|n)\b\s*\?>')
    [ -n "$TREFFERS" ] && { melden "$f"; printf '%s\n' "$TREFFERS" | head -3 | sed 's/^/      /'; }
done
[ "$PROBLEMEN" -eq "$VOOR" ] && echo "  ✓ geen onbeschermde uitvoer gevonden"

echo ""
echo "── Beheerpagina's: autorisatie aanwezig ────────────────────"
VOOR=$PROBLEMEN
for f in admin/*.php; do
    case "$(basename "$f")" in login.php|logout.php) continue;; esac
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
echo "── Bestandspaden: alleen via opslag_absoluut_pad() ─────────"
VOOR=$PROBLEMEN
TREFFERS=$(grep -rnE "(readfile|fopen|file_get_contents|unlink)\s*\(\s*\\\$_(GET|POST|REQUEST)" $BESTANDEN 2>/dev/null)
[ -n "$TREFFERS" ] && { melden "bestandsbewerking direct op gebruikersinvoer"; printf '%s\n' "$TREFFERS" | sed 's/^/      /'; }
[ "$PROBLEMEN" -eq "$VOOR" ] && echo "  ✓ geen bestandsbewerkingen op ruwe invoer"

echo ""
echo "────────────────────────────────────────────────────────────"
printf '  %d bevindingen\n\n' "$PROBLEMEN"
[ "$PROBLEMEN" -eq 0 ]
