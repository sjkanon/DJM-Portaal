#!/usr/bin/env bash
# Maakt de schermafdrukken voor Beheer › Handleiding opnieuw, in assets/handleiding/.
#
# Draai dit na elke wijziging die in het portaal of het beheer te zien is, en
# commit de nieuwe afbeeldingen mee. Een nieuwe afbeelding voeg je toe in
# test/handleiding.js én in admin/handleiding.php.
#
# LET OP: wist de testdatabase en vult hem met voorbeeldgegevens (zie
# handleiding_demo.php). `bash test/alles.sh` zet de gewone testgegevens terug.
set -euo pipefail
cd "$(dirname "$0")"
DOEL="$(cd .. && pwd)/assets/handleiding"

docker compose up -d db mail web >/dev/null 2>&1
for i in $(seq 1 60); do
    [ "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8123/index.php 2>/dev/null)" != "000" ] && break
    sleep 1
done

echo "── Voorbeeldgegevens ───────────────────────────────────────"
UITVOER=$(docker compose exec -T web php /app/test/handleiding_demo.php)
printf '%s\n' "$UITVOER" | head -n -1 | sed 's/^/  /'
read -r J2026 OUDER DEELNEMER <<< "$(printf '%s\n' "$UITVOER" | tail -n 1)"

echo "── Schermafdrukken ─────────────────────────────────────────"
TIJDELIJK=$(mktemp -d)
chmod 777 "$TIJDELIJK"
trap 'rm -rf "$TIJDELIJK"' EXIT

docker run --rm --network test_default \
    -v "$(pwd)/handleiding.js":/usr/src/app/handleiding.js:ro \
    -v "$TIJDELIJK":/shots \
    -e BASIS=http://web:8080 -e MAILPIT=http://mail:8025 \
    -e J2026="$J2026" -e OUDER="$OUDER" -e DEELNEMER="$DEELNEMER" \
    -w /usr/src/app --entrypoint node \
    zenika/alpine-chrome:with-puppeteer handleiding.js

AANTAL=$(find "$TIJDELIJK" -name '*.webp' | wc -l)
[ "$AANTAL" -gt 0 ] || { echo "  ✗ geen schermafdrukken gemaakt"; exit 1; }

mkdir -p "$DOEL"
rm -f "$DOEL"/*.webp
cp "$TIJDELIJK"/*.webp "$DOEL"/
printf '%s\n%s\n' "$(tr -d '[:space:]' < ../VERSION)" "$(date +%F)" > "$DOEL/versie.txt"

echo ""
echo "  ✓ $AANTAL schermafdrukken in assets/handleiding/ ($(du -sh "$DOEL" | cut -f1)), versie $(head -n 1 "$DOEL/versie.txt")"
echo "  De testdatabase bevat nu voorbeeldgegevens; bash test/alles.sh zet de testgegevens terug."
