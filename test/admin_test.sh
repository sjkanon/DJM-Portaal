#!/usr/bin/env bash
# Integratietest van het beheerdersgedeelte: inloggen en elke pagina opvragen,
# met en zonder sessie, en controleren dat er geen PHP-fouten doorheen komen.
set -u
cd "$(dirname "$0")"
. ./hostpad.sh
BASIS=http://localhost:8123
KOEKJES=$(mktemp)
GOED=0; FOUT=0

toets() {
    if [ "$2" = "$3" ]; then printf '  ✓ %s\n' "$1"; GOED=$((GOED+1));
    else printf '  ✗ %s\n      verwacht: %s\n      kreeg:    %s\n' "$1" "$2" "$3"; FOUT=$((FOUT+1)); fi
}
schoon() { # schoon "pagina" "html" — geen PHP-fouten in de uitvoer
    local naam="$1" html="$2" gevonden=""
    for patroon in "Fatal error" "Parse error" "Uncaught" "Warning:" "Deprecated:" "Undefined variable" "Undefined array key" "SQLSTATE"; do
        if printf '%s' "$html" | grep -qF "$patroon"; then gevonden="$gevonden $patroon"; fi
    done
    if [ -z "$gevonden" ]; then printf '  ✓ %s: schone uitvoer\n' "$naam"; GOED=$((GOED+1));
    else printf '  ✗ %s bevat:%s\n' "$naam" "$gevonden"; FOUT=$((FOUT+1))
         printf '%s' "$html" | grep -oE '(Fatal error|Parse error|Uncaught|Warning:|Deprecated:|Undefined [a-z ]+|SQLSTATE)[^<]{0,180}' | head -3 | sed 's/^/      /'
    fi
}
haal()   { curl -s -b "$KOEKJES" -c "$KOEKJES" "$@"; }
status() { curl -s -o /dev/null -w '%{http_code}' -b "$KOEKJES" -c "$KOEKJES" "$@"; }
csrf()   { printf '%s' "$1" | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | cut -d'"' -f4; }

PAGINAS="index.php jaargangen.php bestanden.php bestandscontrole.php toegang.php deelnemers.php bericht.php logboek.php beheerders.php instellingen.php handleiding.php"

echo "── Testbeheerder klaarzetten ───────────────────────────────"
docker compose exec -T web php -r '
$_SERVER["SCRIPT_NAME"]="/index.php";
require "/app/config.php";
db()->prepare("INSERT INTO beheerders (naam, email, wachtwoord_hash, rol) VALUES (:n,:e,:w,\"eigenaar\")
               ON DUPLICATE KEY UPDATE wachtwoord_hash=VALUES(wachtwoord_hash), actief=1")
    ->execute([":n"=>"Testbeheerder", ":e"=>"beheer@example.nl",
               ":w"=>password_hash("EenHeelLangWachtwoord123", PASSWORD_DEFAULT)]);
db()->exec("DELETE FROM aanvraag_limiet");
echo "beheerder klaar\n";' 2>&1 | sed 's/^/  /'

echo ""
echo "── Zonder sessie ───────────────────────────────────────────"
for p in $PAGINAS; do
    toets "admin/$p is afgeschermd" "302" "$(curl -s -o /dev/null -w '%{http_code}' "$BASIS/admin/$p")"
done

echo ""
echo "── Inloggen ────────────────────────────────────────────────"
LOGIN=$(haal "$BASIS/admin/login.php")
toets "inlogpagina geeft 200" "200" "$(status "$BASIS/admin/login.php")"
schoon "admin/login.php" "$LOGIN"

TOKEN=$(csrf "$LOGIN")
MIS=$(haal -X POST -d "csrf_token=$TOKEN" -d "email=beheer@example.nl" -d "wachtwoord=fout" "$BASIS/admin/login.php")
toets "verkeerd wachtwoord geeft geen toegang" "302" "$(curl -s -o /dev/null -w '%{http_code}' "$BASIS/admin/index.php")"

rm -f "$KOEKJES"
LOGIN=$(haal "$BASIS/admin/login.php")
TOKEN=$(csrf "$LOGIN")
haal -o /dev/null -X POST -d "csrf_token=$TOKEN" -d "email=beheer@example.nl" -d "wachtwoord=EenHeelLangWachtwoord123" "$BASIS/admin/login.php"
toets "na inloggen is het beheer bereikbaar" "200" "$(status "$BASIS/admin/index.php")"

echo ""
echo "── Alle beheerpagina's ─────────────────────────────────────"
for p in $PAGINAS; do
    HTML=$(haal "$BASIS/admin/$p")
    toets "admin/$p geeft 200" "200" "$(status "$BASIS/admin/$p")"
    schoon "admin/$p" "$HTML"
done

echo ""
echo "── Pagina's met parameters ─────────────────────────────────"
JAARGANG_ID=$(docker compose exec -T web php -r '
$_SERVER["SCRIPT_NAME"]="/index.php"; require "/app/config.php";
echo (int)db()->query("SELECT id FROM jaargangen ORDER BY jaar DESC LIMIT 1")->fetchColumn();' 2>/dev/null | tr -d '\r')
DEELNEMER_ID=$(docker compose exec -T web php -r '
$_SERVER["SCRIPT_NAME"]="/index.php"; require "/app/config.php";
echo (int)db()->query("SELECT id FROM deelnemers ORDER BY id LIMIT 1")->fetchColumn();' 2>/dev/null | tr -d '\r')

# Ook mét zoekterm en filters: een zoekveld dat dezelfde named parameter twee
# keer gebruikt werkt alleen zonder emulated prepares, en faalt dus pas hier.
for url in "jaargangen.php?id=$JAARGANG_ID" "bestanden.php?jaargang=$JAARGANG_ID" \
           "toegang.php?jaargang=$JAARGANG_ID" "deelnemers.php?id=$DEELNEMER_ID" \
           "bestandscontrole.php" \
           "toegang.php?jaargang=$JAARGANG_ID&q=ouder" \
           "toegang.php?jaargang=$JAARGANG_ID&q=ouder&status=opgehaald" \
           "toegang.php?jaargang=$JAARGANG_ID&status=nooit_ingelogd" \
           "bericht.php?jaargang=$JAARGANG_ID" \
           "deelnemers.php?q=example" "deelnemers.php?q=example&filter=geblokkeerd" \
           "logboek.php?tab=mails&q=example" "logboek.php?tab=logins&q=beheer" \
           "logboek.php?tab=mails" "logboek.php?tab=downloads" "logboek.php?tab=logins"; do
    HTML=$(haal "$BASIS/admin/$url")
    toets "admin/$url geeft 200" "200" "$(status "$BASIS/admin/$url")"
    schoon "admin/$url" "$HTML"
done

echo ""
echo "── Onzinnige parameters ────────────────────────────────────"
for url in "jaargangen.php?id=999999" "bestanden.php?jaargang=abc" "deelnemers.php?id=-1" \
           "toegang.php?jaargang=999999" "logboek.php?tab=bestaatniet&pagina=-5" \
           "bericht.php?jaargang=999999" "bericht.php?jaargang=abc" \
           "toegang.php?jaargang=$JAARGANG_ID&status=%27+OR+1%3D1--&pagina=-5" \
           "toegang.php?jaargang=$JAARGANG_ID&q=%3Cscript%3Ealert(1)%3C%2Fscript%3E" \
           "bestandscontrole.php?onzin=1"; do
    HTML=$(haal "$BASIS/admin/$url")
    schoon "admin/$url" "$HTML"
done

echo ""
echo "── Bericht aan deelnemers ──────────────────────────────────"
# De hele weg: schrijven, voorbeeld, versturen — en de twee vangnetten die
# voorkomen dat ouders een halve zin of twee keer hetzelfde krijgen.
ONDERWERP="Testbericht $(date +%s)"
# Mailen moet ingesteld zijn, ook als dit script los draait.
docker compose exec -T web php /app/test/mailinstellingen.php >/dev/null 2>&1

# 1. {contact} zonder contactadres: dat zou "laat het weten via ," opleveren.
docker compose exec -T web php -r '
$_SERVER["SCRIPT_NAME"]="/index.php"; require "/app/config.php";
instelling_opslaan("contact_email", "");' >/dev/null 2>&1
PAGINA=$(haal "$BASIS/admin/bericht.php?jaargang=$JAARGANG_ID")
haal -o /dev/null -X POST -b "$KOEKJES" -c "$KOEKJES" \
    --data-urlencode "csrf_token=$(csrf "$PAGINA")" --data-urlencode "actie=voorbeeld" \
    --data-urlencode "jaargang=$JAARGANG_ID" --data-urlencode "groep=niet_compleet" \
    --data-urlencode "onderwerp=$ONDERWERP" \
    --data-urlencode "tekst=Beste {naam}, laat het weten via {contact}." \
    "$BASIS/admin/bericht.php"
PAGINA=$(haal "$BASIS/admin/bericht.php?jaargang=$JAARGANG_ID")
if printf '%s' "$PAGINA" | grep -qF "geen contactadres"; then
    toets "leeg contactadres blokkeert {contact}" "ja" "ja"
else
    toets "leeg contactadres blokkeert {contact}" "ja" "nee"
fi

# 2. Met contactadres: voorbeeld, bevestigen, versturen.
docker compose exec -T web php -r '
$_SERVER["SCRIPT_NAME"]="/index.php"; require "/app/config.php";
instelling_opslaan("contact_email", "secretariaat@example.nl");' >/dev/null 2>&1
PAGINA=$(haal "$BASIS/admin/bericht.php?jaargang=$JAARGANG_ID")
haal -o /dev/null -X POST \
    --data-urlencode "csrf_token=$(csrf "$PAGINA")" --data-urlencode "actie=voorbeeld" \
    --data-urlencode "jaargang=$JAARGANG_ID" --data-urlencode "groep=alles" \
    --data-urlencode "onderwerp=$ONDERWERP" \
    --data-urlencode "tekst=Beste {naam}, het werkt weer. Vragen? {contact}." \
    "$BASIS/admin/bericht.php"
STAP2=$(haal "$BASIS/admin/bericht.php?jaargang=$JAARGANG_ID")
schoon "admin/bericht.php stap 2" "$STAP2"
if printf '%s' "$STAP2" | grep -qF "controleren en bevestigen"; then
    toets "voorbeeld leidt naar de bevestigingsstap" "ja" "ja"
else
    toets "voorbeeld leidt naar de bevestigingsstap" "ja" "nee"
fi
# De plaatshouders zijn in het voorbeeld al ingevuld: geen {naam} meer te zien.
if printf '%s' "$STAP2" | grep -qF "secretariaat@example.nl"; then
    toets "het voorbeeld vult {contact} in" "ja" "ja"
else
    toets "het voorbeeld vult {contact} in" "ja" "nee"
fi

VOOR=$(docker compose exec -T db mariadb -udjm_portaal -pdjmtest djm_portaal -N -e \
    "SELECT COUNT(*) FROM mail_log WHERE soort = 'bericht';" 2>/dev/null | tr -d '\r ')
haal -o /dev/null -X POST --data-urlencode "csrf_token=$(csrf "$STAP2")" \
    --data-urlencode "actie=versturen" --data-urlencode "jaargang=$JAARGANG_ID" \
    "$BASIS/admin/bericht.php"
NA=$(docker compose exec -T db mariadb -udjm_portaal -pdjmtest djm_portaal -N -e \
    "SELECT COUNT(*) FROM mail_log WHERE soort = 'bericht';" 2>/dev/null | tr -d '\r ')
if [ "${NA:-0}" -gt "${VOOR:-0}" ]; then
    toets "het bericht is verstuurd en gelogd" "ja" "ja"
else
    toets "het bericht is verstuurd en gelogd" "ja" "nee"
fi

# 3. Nog een keer hetzelfde onderwerp: iedereen hoort nu overgeslagen te worden.
PAGINA=$(haal "$BASIS/admin/bericht.php?jaargang=$JAARGANG_ID")
haal -o /dev/null -X POST \
    --data-urlencode "csrf_token=$(csrf "$PAGINA")" --data-urlencode "actie=voorbeeld" \
    --data-urlencode "jaargang=$JAARGANG_ID" --data-urlencode "groep=alles" \
    --data-urlencode "onderwerp=$ONDERWERP" \
    --data-urlencode "tekst=Beste {naam}, het werkt weer." \
    "$BASIS/admin/bericht.php"
HERHAAL=$(haal "$BASIS/admin/bericht.php?jaargang=$JAARGANG_ID")
if printf '%s' "$HERHAAL" | grep -qF "kregen dit bericht in de afgelopen"; then
    toets "hetzelfde onderwerp gaat niet twee keer" "ja" "ja"
else
    toets "hetzelfde onderwerp gaat niet twee keer" "ja" "nee"
fi
haal -o /dev/null -X POST --data-urlencode "csrf_token=$(csrf "$HERHAAL")" \
    --data-urlencode "actie=annuleren" --data-urlencode "jaargang=$JAARGANG_ID" \
    "$BASIS/admin/bericht.php"

echo ""
echo "── CSRF-bescherming ────────────────────────────────────────"
CSRF_STATUS=$(curl -s -o /dev/null -w '%{http_code}' -b "$KOEKJES" -X POST \
    -d "jaar=2099" -d "titel=Zonder token" "$BASIS/admin/jaargangen.php")
toets "POST zonder token wordt geweigerd" "419" "$CSRF_STATUS"

echo ""
echo "── Uitloggen ───────────────────────────────────────────────"
# Een GET mag niets doen: uitloggen vraagt om POST met een geldig CSRF-token.
haal -o /dev/null -L "$BASIS/admin/logout.php"
toets "GET logt niet uit" "200" "$(status "$BASIS/admin/index.php")"

OVERZICHT=$(haal "$BASIS/admin/index.php")
UIT_TOKEN=$(csrf "$OVERZICHT")
haal -o /dev/null -L -X POST -d "csrf_token=$UIT_TOKEN" "$BASIS/admin/logout.php"
toets "beheer weer afgeschermd" "302" "$(status "$BASIS/admin/index.php")"

echo ""
echo "── Bestaande installatie: schema van vóór de laatste wijziging ──"
# Een verse database komt uit db.sql en heeft alles al. Een portaal dat al
# draaide juist niet: daar moeten de schermen zelf de ontbrekende kolommen
# aanmaken. Dit ging een keer mis — Logboek > Downloads gaf een 500 op elke
# bestaande installatie, omdat de migratie alleen in het downloadpad zat en dat
# scherm die code niet laadt. Daarom hier: kolommen weghalen, alles openen,
# controleren dat ze terugkomen.
docker compose exec -T db mariadb -udjm_portaal -pdjmtest djm_portaal -e \
    "ALTER TABLE download_log DROP COLUMN IF EXISTS reden, DROP COLUMN IF EXISTS sleutel;" 2>/dev/null
ONTBREEKT=$(docker compose exec -T db mariadb -udjm_portaal -pdjmtest djm_portaal -N -e \
    "SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'download_log'
        AND column_name IN ('reden', 'sleutel');" 2>/dev/null | tr -d '\r ')
toets "kolommen zijn weg (bestaande installatie nagebootst)" "0" "$ONTBREEKT"

# Opnieuw inloggen: de vorige test heeft uitgelogd.
LOGIN=$(haal "$BASIS/admin/login.php")
haal -o /dev/null -X POST -d "csrf_token=$(csrf "$LOGIN")" \
    -d "email=beheer@example.nl" -d "wachtwoord=EenHeelLangWachtwoord123" "$BASIS/admin/login.php"

for TAB in downloads logins mails; do
    HTML=$(haal "$BASIS/admin/logboek.php?tab=$TAB")
    toets "logboek?tab=$TAB geeft 200" "200" "$(status "$BASIS/admin/logboek.php?tab=$TAB")"
    schoon "logboek?tab=$TAB zonder de kolommen" "$HTML"
done
HERSTELD=$(docker compose exec -T db mariadb -udjm_portaal -pdjmtest djm_portaal -N -e \
    "SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'download_log'
        AND column_name IN ('reden', 'sleutel');" 2>/dev/null | tr -d '\r ')
toets "het scherm heeft de kolommen zelf aangemaakt" "2" "$HERSTELD"

OVERZICHT=$(haal "$BASIS/admin/index.php")
haal -o /dev/null -L -X POST -d "csrf_token=$(csrf "$OVERZICHT")" "$BASIS/admin/logout.php"

echo ""
echo "── Installatie ─────────────────────────────────────────────"
SETUP=$(curl -s "$BASIS/setup.php")
toets "setup.php geeft 200" "200" "$(curl -s -o /dev/null -w '%{http_code}' "$BASIS/setup.php")"
schoon "setup.php" "$SETUP"
if printf '%s' "$SETUP" | grep -qiE "voltooid|al ingericht|setup.toegestaan|al geïnstalleerd|reeds"; then
    printf '  ✓ setup.php is afgesloten na installatie\n'; GOED=$((GOED+1))
else
    printf '  ✗ setup.php lijkt nog vrij toegankelijk na installatie\n'; FOUT=$((FOUT+1))
fi

echo ""
echo "────────────────────────────────────────────────────────────"
printf '  %d geslaagd, %d mislukt\n\n' "$GOED" "$FOUT"
rm -f "$KOEKJES"
[ "$FOUT" -eq 0 ]
