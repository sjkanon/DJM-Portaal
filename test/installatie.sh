#!/usr/bin/env bash
# Verse installatie: lege database, geen .env, setup.php helemaal doorlopen.
#
# Dit draait tegen de container `vers`, die met opzet géén omgevingsvariabelen
# meekrijgt. Zo moet de wizard het .env-bestand echt zelf schrijven, net als bij
# een klant op een lege server. De andere containers hebben DB_HOST en de rest
# wél als omgevingsvariabele, en die winnen van .env — die merken hier niets van.
set -u
cd "$(dirname "$0")"
BASIS=http://localhost:8128
PROJECT=$(cd .. && pwd)
J=$(mktemp); GOED=0; FOUT=0

toets() {
    if [ "$2" = "$3" ]; then printf '  ✓ %s\n' "$1"; GOED=$((GOED+1));
    else printf '  ✗ %s\n      verwacht: %s\n      kreeg:    %s\n' "$1" "$2" "$3"; FOUT=$((FOUT+1)); fi
}
bevat() {
    if printf '%s' "$3" | grep -qF "$2"; then printf '  ✓ %s\n' "$1"; GOED=$((GOED+1));
    else printf '  ✗ %s (miste: %s)\n' "$1" "$2"; FOUT=$((FOUT+1)); fi
}
mist() {
    if printf '%s' "$3" | grep -qF "$2"; then printf '  ✗ %s (bevatte: %s)\n' "$1" "$2"; FOUT=$((FOUT+1));
    else printf '  ✓ %s\n' "$1"; GOED=$((GOED+1)); fi
}
tok() { printf '%s' "$1" | grep -o 'name="setup_csrf" value="[^"]*"' | head -1 | cut -d'"' -f4; }
csrf() { printf '%s' "$1" | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | cut -d'"' -f4; }
haal() { curl -s -c "$J" -b "$J" "$1"; }
# Bewust zonder curl -L: curl herhaalt na een 302 de POST, een browser doet een GET.
stuur() { local url="$1"; shift; curl -s -o /dev/null -c "$J" -b "$J" -X POST "$@" "$url"; }

# Het databasewachtwoord bevat met opzet een # en een aanhalingsteken: precies
# de tekens waarop een .env-schrijver stukloopt als hij niet goed citeert.
DBWW='wachtwoord#met"tekens'

echo "── Testomgeving klaarzetten ────────────────────────────────────"
docker compose up -d vers >/dev/null 2>&1
for i in $(seq 1 60); do
    [ "$(curl -s -o /dev/null -w '%{http_code}' "$BASIS/setup.php" 2>/dev/null)" != "000" ] && break
    sleep 1
done
docker compose exec -T db mariadb -u root -pdjmtest -e \
    "DROP DATABASE IF EXISTS djm_portaal; DROP USER IF EXISTS 'wizard'@'%';
     CREATE USER 'wizard'@'%' IDENTIFIED BY '$DBWW';
     GRANT ALL PRIVILEGES ON *.* TO 'wizard'@'%'; FLUSH PRIVILEGES;" 2>/dev/null
docker compose exec -T vers sh -c 'rm -f /app/.env /app/.env.backup-* /app/setup.toegestaan' 2>/dev/null
BESTAAT=$(docker compose exec -T db mariadb -u root -pdjmtest -N -e \
    "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='djm_portaal';" 2>/dev/null | tr -d '\r ')
toets "database bestaat nog niet" "0" "$BESTAAT"
toets "er is nog geen .env" "1" "$(docker compose exec -T vers sh -c '[ -f /app/.env ] && echo 0 || echo 1' 2>/dev/null | tr -d '\r ')"

echo ""
echo "── Zonder installatie stuurt alles door naar setup ─────────"
LOC=$(curl -s -o /dev/null -w '%{redirect_url}' "$BASIS/index.php")
bevat "index.php verwijst naar setup.php" "setup.php" "$LOC"

echo ""
echo "── Stap 1: controle van de omgeving ────────────────────────"
S1=$(haal "$BASIS/setup.php")
toets "setup.php geeft 200" "200" "$(curl -s -o /dev/null -w '%{http_code}' "$BASIS/setup.php")"
bevat "controleert de PHP-versie" "PHP-versie" "$S1"
bevat "controleert de map logs/" "Map logs/" "$S1"
bevat "wijst door naar de instellingen" "Verder naar de instellingen" "$S1"

echo ""
echo "── Stap 2: onjuiste invoer wordt geweigerd ─────────────────"
S2=$(haal "$BASIS/setup.php?stap=2")
bevat "toont het instellingenformulier" 'name="APP_URL"' "$S2"
MIS=$(curl -s -c "$J" -b "$J" -X POST "$BASIS/setup.php?stap=2" \
    -d "setup_csrf=$(tok "$S2")" -d "actie=env_opslaan" \
    --data-urlencode "APP_URL=portaal.example.nl" --data-urlencode "DB_HOST=db" \
    --data-urlencode "DB_NAME=fout-naam!" --data-urlencode "DB_USER=" \
    --data-urlencode "OPSLAG_PAD=relatief/pad" --data-urlencode "XACCEL_PREFIX=beveiligd")
bevat "weigert een adres zonder https://" "geen geldig adres" "$MIS"
bevat "weigert een databasenaam met leestekens" "letters, cijfers en liggende streepjes" "$MIS"
bevat "weigert een lege gebruikersnaam" "Vul de databasegebruiker in" "$MIS"
bevat "weigert een relatief opslagpad" "volledig pad" "$MIS"
bevat "weigert een prefix zonder slashes" "begint én eindigt met een slash" "$MIS"
toets "en schrijft niets weg" "1" "$(docker compose exec -T vers sh -c '[ -f /app/.env ] && echo 0 || echo 1' 2>/dev/null | tr -d '\r ')"

echo ""
echo "── Stap 2: .env wegschrijven ───────────────────────────────"
# APP_URL wijst eerst naar het adres waarop de container zichzelf kan bereiken;
# dat is nodig om verderop de .env-bereikbaarheidscontrole te kunnen toetsen.
S2=$(haal "$BASIS/setup.php?stap=2")
stuur "$BASIS/setup.php?stap=2" \
    -d "setup_csrf=$(tok "$S2")" -d "actie=env_opslaan" \
    --data-urlencode "APP_URL=http://vers:8080" --data-urlencode "APP_NAME=Deventer Jeugd Musical" \
    --data-urlencode "DB_HOST=db" --data-urlencode "DB_NAME=djm_portaal" \
    --data-urlencode "DB_USER=wizard" --data-urlencode "DB_PASS=$DBWW" \
    --data-urlencode "DB_CHARSET=utf8mb4" --data-urlencode "DELIVERY_MODE=auto" \
    --data-urlencode "XACCEL_PREFIX=/beveiligd/" \
    --data-urlencode "MAIL_VAN_NAAM=Deventer Jeugd Musical" \
    --data-urlencode "SMTP_POORT=587" --data-urlencode "SMTP_BEVEILIGING=tls"
toets ".env is aangemaakt" "0" "$(docker compose exec -T vers sh -c '[ -f /app/.env ] && echo 0 || echo 1' 2>/dev/null | tr -d '\r ')"
toets ".env is alleen voor de eigenaar leesbaar" "600" \
    "$(docker compose exec -T vers sh -c 'stat -c %a /app/.env' 2>/dev/null | tr -d '\r ')"
ENV=$(docker compose exec -T vers cat /app/.env 2>/dev/null)
bevat "het lastige wachtwoord is goed geciteerd" 'DB_PASS="wachtwoord#met\"tekens"' "$ENV"
bevat "APP_KEY is gegenereerd" "APP_KEY=" "$ENV"
toets "APP_KEY is 64 tekens" "1" "$(printf '%s' "$ENV" | grep -cE '^APP_KEY="[0-9a-f]{64}"$' | tr -d ' ')"
toets "OTP_PEPPER is 64 tekens" "1" "$(printf '%s' "$ENV" | grep -cE '^OTP_PEPPER="[0-9a-f]{64}"$' | tr -d ' ')"
toets "APP_KEY en OTP_PEPPER verschillen" "2" \
    "$(printf '%s' "$ENV" | grep -oE '"[0-9a-f]{64}"' | sort -u | wc -l | tr -d ' ')"

echo ""
echo "── Stap 2: merkt een publiek leesbare .env op ──────────────"
# De ingebouwde PHP-server serveert élk bestand, dus ook .env. Dat is precies
# de situatie die de wizard moet betrappen.
S2=$(haal "$BASIS/setup.php?stap=2")
stuur "$BASIS/setup.php?stap=2" \
    -d "setup_csrf=$(tok "$S2")" -d "actie=env_bereikbaarheid" -d "terug=2"
UITSLAG=$(haal "$BASIS/setup.php?stap=2")
bevat "betrapt een .env die van buitenaf te downloaden is" "publiek te downloaden" "$UITSLAG"
bevat "en zegt erbij dat het eerst gerepareerd moet worden" "op straat" "$UITSLAG"

echo ""
echo "── Stap 2: opnieuw opslaan bewaart het oude bestand ────────"
# Een zelf toegevoegde regel hoort het overschrijven te overleven.
docker compose exec -T vers sh -c 'printf "\nEIGEN_SLEUTEL=\"iets van mezelf\"\n" >> /app/.env' 2>/dev/null
S2=$(haal "$BASIS/setup.php?stap=2")
bevat "meldt dat er al een .env is" "Er is al een" "$S2"
# Wachtwoordveld leeg laten hoort het bestaande wachtwoord te behouden.
stuur "$BASIS/setup.php?stap=2" \
    -d "setup_csrf=$(tok "$S2")" -d "actie=env_opslaan" \
    --data-urlencode "APP_URL=$BASIS" --data-urlencode "APP_NAME=Deventer Jeugd Musical" \
    --data-urlencode "DB_HOST=db" --data-urlencode "DB_NAME=djm_portaal" \
    --data-urlencode "DB_USER=wizard" --data-urlencode "DB_PASS=" \
    --data-urlencode "DB_CHARSET=utf8mb4" --data-urlencode "DELIVERY_MODE=auto" \
    --data-urlencode "XACCEL_PREFIX=/beveiligd/" \
    --data-urlencode "MAIL_VAN_NAAM=Deventer Jeugd Musical" \
    --data-urlencode "SMTP_POORT=587" --data-urlencode "SMTP_BEVEILIGING=tls"
ENV2=$(docker compose exec -T vers cat /app/.env 2>/dev/null)
bevat "het nieuwe adres staat erin" "APP_URL=\"$BASIS\"" "$ENV2"
bevat "een leeg wachtwoordveld laat het wachtwoord staan" 'DB_PASS="wachtwoord#met\"tekens"' "$ENV2"
toets "er is één reservekopie gemaakt" "1" \
    "$(docker compose exec -T vers sh -c 'ls /app/.env.backup-* 2>/dev/null | wc -l' | tr -d '\r ')"
toets "de reservekopie is ook afgeschermd" "600" \
    "$(docker compose exec -T vers sh -c 'stat -c %a $(ls /app/.env.backup-* | head -1)' 2>/dev/null | tr -d '\r ')"
bevat "een zelf toegevoegde sleutel blijft staan" 'EIGEN_SLEUTEL="iets van mezelf"' "$ENV2"
SLEUTELS=$(printf '%s' "$ENV2" | grep -oE '^(APP_KEY|OTP_PEPPER)="[0-9a-f]{64}"$' | sort)
toets "de sleutels zijn niet opnieuw gegenereerd" "$(printf '%s' "$ENV" | grep -oE '^(APP_KEY|OTP_PEPPER)="[0-9a-f]{64}"$' | sort)" "$SLEUTELS"

echo ""
echo "── Stap 2: de database bestaat nog niet ────────────────────"
S2=$(haal "$BASIS/setup.php?stap=2")
bevat "merkt dat de database ontbreekt" "database bestaat nog niet" "$S2"
bevat "biedt aan hem aan te maken" "Database nu aanmaken" "$S2"
mist "toont het wachtwoord niet terug in het formulier" "$DBWW" "$S2"
stuur "$BASIS/setup.php?stap=2" -d "setup_csrf=$(tok "$S2")" -d "actie=database_aanmaken"
BESTAAT=$(docker compose exec -T db mariadb -u root -pdjmtest -N -e \
    "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='djm_portaal';" 2>/dev/null | tr -d '\r ')
toets "database aangemaakt door de wizard" "1" "$BESTAAT"

echo ""
echo "── Stap 3: schema installeren ──────────────────────────────"
S3=$(haal "$BASIS/setup.php?stap=3")
bevat "de verbinding werkt nu" "De verbinding met de database werkt" "$S3"
stuur "$BASIS/setup.php?stap=3" -d "setup_csrf=$(tok "$S3")" -d "actie=schema_installeren"
TABELLEN=$(docker compose exec -T db mariadb -u root -pdjmtest -N -e \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='djm_portaal';" 2>/dev/null | tr -d '\r ')
toets "twaalf tabellen aangemaakt" "12" "$TABELLEN"
INSTELLINGEN=$(docker compose exec -T db mariadb -u root -pdjmtest -N -e \
    "SELECT COUNT(*) FROM djm_portaal.instellingen;" 2>/dev/null | tr -d '\r ')
toets "standaardinstellingen geladen" "1" "$([ "${INSTELLINGEN:-0}" -gt 20 ] && echo 1 || echo 0)"

echo ""
echo "── Stap 4: eerste beheerder ────────────────────────────────"
S4=$(haal "$BASIS/setup.php?stap=4")
stuur "$BASIS/setup.php?stap=4" \
    -d "setup_csrf=$(tok "$S4")" -d "actie=beheerder_aanmaken" -d "naam=Beheer DJM" \
    -d "email=beheer@djm.test" -d "wachtwoord=EenHeelLangWachtwoord123" \
    -d "wachtwoord_herhaling=EenHeelLangWachtwoord123"
AANTAL=$(docker compose exec -T db mariadb -u root -pdjmtest -N -e \
    "SELECT COUNT(*) FROM djm_portaal.beheerders WHERE email='beheer@djm.test';" 2>/dev/null | tr -d '\r ')
toets "beheerder aangemaakt" "1" "$AANTAL"

echo ""
echo "── Stap 5: de slotpagina ───────────────────────────────────"
S5=$(haal "$BASIS/setup.php?stap=5")
bevat "toont de nazorglijst" "Doe dit nu meteen" "$S5"
bevat "waarschuwt over setup.php" "Verwijder" "$S5"
CTRL=$(curl -s -c "$J" -b "$J" -X POST "$BASIS/setup.php?stap=5" \
    -d "setup_csrf=$(tok "$S5")" -d "actie=env_bereikbaarheid" -d "terug=5")
S5B=$(haal "$BASIS/setup.php?stap=5")
if printf '%s' "$S5B" | grep -qE "weigert|niet bereiken|niet de inhoud"; then
    printf '  ✓ controleert of .env van buitenaf te downloaden is\n'; GOED=$((GOED+1))
else
    printf '  ✗ de .env-controle gaf geen bruikbare uitslag\n'; FOUT=$((FOUT+1))
fi

echo ""
echo "── Setup sluit zichzelf af ─────────────────────────────────"
K=$(mktemp)
NA=$(curl -s -c "$K" -b "$K" "$BASIS/setup.php")
if printf '%s' "$NA" | grep -qiE "voltooid|al ingericht|setup.toegestaan|reeds"; then
    printf '  ✓ setup.php weigert een tweede installatie\n'; GOED=$((GOED+1))
else
    printf '  ✗ setup.php is nog steeds vrij toegankelijk\n'; FOUT=$((FOUT+1))
fi
curl -s -o /dev/null -c "$K" -b "$K" -X POST -d "actie=beheerder_aanmaken" -d "naam=Indringer" \
    -d "email=indringer@example.nl" -d "wachtwoord=NogEenLangWachtwoord1" \
    -d "wachtwoord_herhaling=NogEenLangWachtwoord1" "$BASIS/setup.php?stap=4"
AANTAL2=$(docker compose exec -T db mariadb -u root -pdjmtest -N -e \
    "SELECT COUNT(*) FROM djm_portaal.beheerders;" 2>/dev/null | tr -d '\r ')
toets "geen tweede beheerder toegevoegd" "1" "$AANTAL2"
curl -s -o /dev/null -c "$K" -b "$K" -X POST -d "actie=env_opslaan" \
    --data-urlencode "APP_URL=http://kwaadwillend.example" --data-urlencode "DB_HOST=elders" \
    --data-urlencode "DB_NAME=x" --data-urlencode "DB_USER=x" "$BASIS/setup.php?stap=2"
ENVNA=$(docker compose exec -T vers cat /app/.env 2>/dev/null)
mist ".env is niet meer te overschrijven" "kwaadwillend.example" "$ENVNA"

echo ""
echo "── Inloggen op de verse installatie ────────────────────────"
K2=$(mktemp)
L=$(curl -s -c "$K2" -b "$K2" "$BASIS/admin/login.php")
curl -s -o /dev/null -c "$K2" -b "$K2" -X POST -d "csrf_token=$(csrf "$L")" \
    -d "email=beheer@djm.test" -d "wachtwoord=EenHeelLangWachtwoord123" "$BASIS/admin/login.php"
toets "beheer bereikbaar" "200" "$(curl -s -o /dev/null -w '%{http_code}' -b "$K2" "$BASIS/admin/index.php")"
OVERZICHT=$(curl -s -b "$K2" "$BASIS/admin/index.php")
bevat "overzicht wijst op de ontbrekende e-mailconfiguratie" "instellingen.php" "$OVERZICHT"
toets "portaal draait ook" "200" "$(curl -s -o /dev/null -w '%{http_code}' "$BASIS/index.php")"

echo ""
echo "── Opruimen ────────────────────────────────────────────────"
# De .env die de wizard schreef hoort niet in de werkmap achter te blijven; de
# overige containers halen hun instellingen uit omgevingsvariabelen.
docker compose exec -T vers sh -c 'rm -f /app/.env /app/.env.backup-*' 2>/dev/null
toets ".env opgeruimd" "1" "$(docker compose exec -T vers sh -c '[ -f /app/.env ] && echo 0 || echo 1' 2>/dev/null | tr -d '\r ')"
toets "reservekopieën opgeruimd" "0" \
    "$(docker compose exec -T vers sh -c 'ls /app/.env.backup-* 2>/dev/null | wc -l' | tr -d '\r ')"

echo ""
echo "────────────────────────────────────────────────────────────"
printf '  %d geslaagd, %d mislukt\n\n' "$GOED" "$FOUT"
rm -f "$J" "$K" "$K2"
[ "$FOUT" -eq 0 ]
