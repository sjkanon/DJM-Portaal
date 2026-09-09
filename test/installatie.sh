#!/usr/bin/env bash
# Verse installatie: lege database, setup.php doorlopen, daarna inloggen.
set -u
cd "$(dirname "$0")"
BASIS=http://localhost:8123
J=$(mktemp); GOED=0; FOUT=0

toets() {
    if [ "$2" = "$3" ]; then printf '  ✓ %s\n' "$1"; GOED=$((GOED+1));
    else printf '  ✗ %s\n      verwacht: %s\n      kreeg:    %s\n' "$1" "$2" "$3"; FOUT=$((FOUT+1)); fi
}
bevat() {
    if printf '%s' "$3" | grep -qF "$2"; then printf '  ✓ %s\n' "$1"; GOED=$((GOED+1));
    else printf '  ✗ %s (miste: %s)\n' "$1" "$2"; FOUT=$((FOUT+1)); fi
}
tok() { printf '%s' "$1" | grep -o 'name="setup_csrf" value="[^"]*"' | head -1 | cut -d'"' -f4; }
csrf() { printf '%s' "$1" | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | cut -d'"' -f4; }

echo "── Database leegmaken ──────────────────────────────────────"
docker compose exec -T db mariadb -u root -pdjmtest -e \
    "DROP DATABASE IF EXISTS djm_portaal; CREATE DATABASE djm_portaal CHARACTER SET utf8mb4;" 2>/dev/null
docker compose exec -T web rm -f /app/setup.toegestaan 2>/dev/null
TABELLEN=$(docker compose exec -T db mariadb -u root -pdjmtest -N -e \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='djm_portaal';" 2>/dev/null | tr -d '\r ')
toets "database is leeg" "0" "$TABELLEN"

echo ""
echo "── Zonder installatie stuurt alles door naar setup ─────────"
LOC=$(curl -s -o /dev/null -w '%{redirect_url}' "$BASIS/index.php")
bevat "index.php verwijst naar setup.php" "setup.php" "$LOC"

echo ""
echo "── Stap 1: controle ────────────────────────────────────────"
S1=$(curl -s -c "$J" -b "$J" "$BASIS/setup.php")
toets "setup.php geeft 200" "200" "$(curl -s -o /dev/null -w '%{http_code}' "$BASIS/setup.php")"
bevat "controleert de PHP-versie" "PHP-versie" "$S1"
bevat "controleert de opslagmap" "Opslagmap" "$S1"

echo ""
echo "── Stap 2: schema installeren ──────────────────────────────"
S2=$(curl -s -c "$J" -b "$J" "$BASIS/setup.php?stap=2")
curl -s -L -o /tmp/setup2.html -c "$J" -b "$J" -d "setup_csrf=$(tok "$S2")" \
    -d "actie=schema_installeren" "$BASIS/setup.php?stap=2"
TABELLEN=$(docker compose exec -T db mariadb -u root -pdjmtest -N -e \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='djm_portaal';" 2>/dev/null | tr -d '\r ')
toets "twaalf tabellen aangemaakt" "12" "$TABELLEN"
INSTELLINGEN=$(docker compose exec -T db mariadb -u root -pdjmtest -N -e \
    "SELECT COUNT(*) FROM djm_portaal.instellingen;" 2>/dev/null | tr -d '\r ')
toets "standaardinstellingen geladen" "1" "$([ "${INSTELLINGEN:-0}" -gt 20 ] && echo 1 || echo 0)"

echo ""
echo "── Stap 3: eerste beheerder ────────────────────────────────"
S3=$(curl -s -c "$J" -b "$J" "$BASIS/setup.php?stap=3")
curl -s -L -o /tmp/setup3.html -c "$J" -b "$J" -d "setup_csrf=$(tok "$S3")" \
    -d "actie=beheerder_aanmaken" -d "naam=Beheer DJM" -d "email=beheer@djm.test" \
    -d "wachtwoord=EenHeelLangWachtwoord123" -d "wachtwoord_herhaling=EenHeelLangWachtwoord123" \
    "$BASIS/setup.php?stap=3"
AANTAL=$(docker compose exec -T db mariadb -u root -pdjmtest -N -e \
    "SELECT COUNT(*) FROM djm_portaal.beheerders WHERE email='beheer@djm.test';" 2>/dev/null | tr -d '\r ')
toets "beheerder aangemaakt" "1" "$AANTAL"

echo ""
echo "── Setup sluit zichzelf af ─────────────────────────────────"
NA=$(curl -s "$BASIS/setup.php")
if printf '%s' "$NA" | grep -qiE "voltooid|al ingericht|setup.toegestaan|reeds"; then
    printf '  ✓ setup.php weigert een tweede installatie\n'; GOED=$((GOED+1))
else
    printf '  ✗ setup.php is nog steeds vrij toegankelijk\n'; FOUT=$((FOUT+1))
fi
WEER=$(curl -s -L -o /dev/null -w '%{http_code}' -d "actie=beheerder_aanmaken" -d "naam=Indringer" \
    -d "email=indringer@example.nl" -d "wachtwoord=NogEenLangWachtwoord1" \
    -d "wachtwoord_herhaling=NogEenLangWachtwoord1" "$BASIS/setup.php?stap=3")
AANTAL2=$(docker compose exec -T db mariadb -u root -pdjmtest -N -e \
    "SELECT COUNT(*) FROM djm_portaal.beheerders;" 2>/dev/null | tr -d '\r ')
toets "geen tweede beheerder toegevoegd" "1" "$AANTAL2"

echo ""
echo "── Inloggen op de verse installatie ────────────────────────"
K=$(mktemp)
L=$(curl -s -c "$K" -b "$K" "$BASIS/admin/login.php")
curl -s -L -o /dev/null -c "$K" -b "$K" -d "csrf_token=$(csrf "$L")" \
    -d "email=beheer@djm.test" -d "wachtwoord=EenHeelLangWachtwoord123" "$BASIS/admin/login.php"
toets "beheer bereikbaar" "200" "$(curl -s -o /dev/null -w '%{http_code}' -b "$K" "$BASIS/admin/index.php")"
OVERZICHT=$(curl -s -b "$K" "$BASIS/admin/index.php")
bevat "overzicht wijst op de ontbrekende e-mailconfiguratie" "instellingen.php" "$OVERZICHT"
toets "portaal draait ook" "200" "$(curl -s -o /dev/null -w '%{http_code}' "$BASIS/index.php")"

echo ""
echo "────────────────────────────────────────────────────────────"
printf '  %d geslaagd, %d mislukt\n\n' "$GOED" "$FOUT"
rm -f "$J" "$K"
[ "$FOUT" -eq 0 ]
