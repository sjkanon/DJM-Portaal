#!/usr/bin/env bash
# Beheerders: toevoegen met uitnodiging, zelf een wachtwoord kiezen via de link,
# resetlinks, uit- en inschakelen, en "wachtwoord vergeten" zonder dat de pagina
# verraadt welke adressen bestaan.
set -u
cd "$(dirname "$0")"
. ./hostpad.sh
BASIS=http://localhost:8123
MAILPIT=http://localhost:8125
BEHEER=$(mktemp); NIEUW=$(mktemp); LOS=$(mktemp)
GOED=0; FOUT=0

toets() {
    if [ "$2" = "$3" ]; then printf '  ✓ %s\n' "$1"; GOED=$((GOED+1));
    else printf '  ✗ %s\n      verwacht: %s\n      kreeg:    %s\n' "$1" "$2" "$3"; FOUT=$((FOUT+1)); fi
}
bevat() {
    if printf '%s' "$3" | grep -qF "$2"; then printf '  ✓ %s\n' "$1"; GOED=$((GOED+1));
    else printf '  ✗ %s (mist: %s)\n' "$1" "$2"; FOUT=$((FOUT+1)); fi
}
bevat_niet() {
    if printf '%s' "$3" | grep -qF "$2"; then printf '  ✗ %s (bevat: %s)\n' "$1" "$2"; FOUT=$((FOUT+1));
    else printf '  ✓ %s\n' "$1"; GOED=$((GOED+1)); fi
}
haal()   { local jar="$1"; shift; curl -s -b "$jar" -c "$jar" "$@"; }
status() { local jar="$1"; shift; curl -s -o /dev/null -w '%{http_code}' -b "$jar" -c "$jar" "$@"; }
csrf()   { printf '%s' "$1" | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | cut -d'"' -f4; }
sql()    { docker compose exec -T db mariadb -u root -pdjmtest -N djm_portaal -e "$1" 2>/dev/null | tr -d '\r'; }
postbus_leeg() { curl -s -X DELETE "$MAILPIT/api/v1/messages" >/dev/null 2>&1; }

# Aantal mails aan een adres.
mails_aan() {
    curl -s "$MAILPIT/api/v1/messages" | python3 -c '
import sys, json
adres = sys.argv[1]
print(sum(1 for m in json.load(sys.stdin)["messages"] if any(t["Address"].lower() == adres for t in m["To"])))' "$1"
}
# "onderwerp<TAB>pad-met-token" van de nieuwste mail aan een adres.
nieuwste_mail() {
    curl -s "$MAILPIT/api/v1/messages" | python3 -c '
import sys, json, re, urllib.request
adres, basis = sys.argv[1], sys.argv[2]
for m in json.load(sys.stdin)["messages"]:
    if any(t["Address"].lower() == adres for t in m["To"]):
        d = json.load(urllib.request.urlopen(basis + "/api/v1/message/" + m["ID"]))
        r = re.search(r"admin/wachtwoord_instellen\.php\?t=[0-9a-f]{82}", d.get("HTML") or "")
        print(d.get("Subject", "") + "\t" + (r.group(0) if r else ""))
        break' "$1" "$MAILPIT"
}
inloggen() { # inloggen jar email wachtwoord → HTTP-status van admin/index.php daarna
    local jar="$1"; : > "$jar"
    local html; html=$(haal "$jar" "$BASIS/admin/login.php")
    haal "$jar" -o /dev/null -X POST -d "csrf_token=$(csrf "$html")" \
        --data-urlencode "email=$2" --data-urlencode "wachtwoord=$3" "$BASIS/admin/login.php"
    status "$jar" "$BASIS/admin/index.php"
}
kies_wachtwoord() { # kies_wachtwoord jar pad wachtwoord herhaling → HTML na versturen
    local jar="$1"; : > "$jar"
    local html; html=$(haal "$jar" -L "$BASIS/$2")
    haal "$jar" -X POST -d "csrf_token=$(csrf "$html")" \
        --data-urlencode "wachtwoord=$3" --data-urlencode "wachtwoord_herhaling=$4" \
        "$BASIS/admin/wachtwoord_instellen.php"
}

echo "── Klaarzetten ─────────────────────────────────────────────"
docker compose exec -T web php /app/test/mailinstellingen.php >/dev/null 2>&1
docker compose exec -T web php -r '
$_SERVER["SCRIPT_NAME"]="/index.php";
require "/app/config.php";
db()->prepare("INSERT INTO beheerders (naam, email, wachtwoord_hash, rol) VALUES (:n,:e,:w,\"eigenaar\")
               ON DUPLICATE KEY UPDATE wachtwoord_hash=VALUES(wachtwoord_hash), actief=1")
    ->execute([":n"=>"Testbeheerder", ":e"=>"beheer@example.nl",
               ":w"=>password_hash("EenHeelLangWachtwoord123", PASSWORD_DEFAULT)]);
db()->exec("DELETE FROM beheerders WHERE email = \"nieuw@example.nl\"");
db()->exec("DELETE FROM aanvraag_limiet");
echo "  klaar\n";' 2>&1
sleep 1; postbus_leeg

echo ""
echo "── Zonder sessie ───────────────────────────────────────────"
toets "beheerders.php is afgeschermd" "302" "$(status "$LOS" "$BASIS/admin/beheerders.php")"
toets "wachtwoord_vergeten.php is openbaar" "200" "$(status "$LOS" "$BASIS/admin/wachtwoord_vergeten.php")"
LOGIN=$(haal "$LOS" "$BASIS/admin/login.php")
bevat "inlogpagina linkt naar wachtwoord vergeten" "wachtwoord_vergeten.php" "$LOGIN"
ONZIN=$(haal "$LOS" -L "$BASIS/admin/wachtwoord_instellen.php?t=$(printf '0%.0s' $(seq 1 82))")
bevat "verzonnen link werkt niet" "werkt niet (meer)" "$ONZIN"
toets "POST zonder token naar wachtwoord vergeten wordt geweigerd" "419" \
    "$(curl -s -o /dev/null -w '%{http_code}' -X POST -d "email=beheer@example.nl" "$BASIS/admin/wachtwoord_vergeten.php")"

echo ""
echo "── Toevoegen en uitnodigen ─────────────────────────────────"
toets "testbeheerder logt in" "200" "$(inloggen "$BEHEER" beheer@example.nl EenHeelLangWachtwoord123)"
LIJST=$(haal "$BEHEER" "$BASIS/admin/beheerders.php")
bevat "beheerderslijst toont de testbeheerder" "beheer@example.nl" "$LIJST"
haal "$BEHEER" -o /dev/null -X POST -d "csrf_token=$(csrf "$LIJST")" -d "actie=toevoegen" \
    --data-urlencode "naam=Nieuwe Beheerder" --data-urlencode "email= Nieuw@Example.NL " "$BASIS/admin/beheerders.php"
toets "account aangemaakt, adres genormaliseerd" "1" "$(sql "SELECT COUNT(*) FROM beheerders WHERE email='nieuw@example.nl' AND actief=1")"
toets "tabel beheerder_tokens bestaat (ook op een oude database)" "1" \
    "$(sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='djm_portaal' AND table_name='beheerder_tokens'")"
toets "van de link staat alleen een hash in de database" "64" "$(sql "SELECT LENGTH(token_hash) FROM beheerder_tokens t JOIN beheerders b ON b.id=t.beheerder_id WHERE b.email='nieuw@example.nl'")"
sleep 1
IFS=$'\t' read -r ONDERWERP LINK1 <<< "$(nieuwste_mail nieuw@example.nl)"
bevat "uitnodiging ontvangen" "beheerdersaccount" "$ONDERWERP"
toets "uitnodiging bevat een link" "1" "$([ -n "$LINK1" ] && echo 1 || echo 0)"

LIJST=$(haal "$BEHEER" "$BASIS/admin/beheerders.php")
haal "$BEHEER" -o /dev/null -X POST -d "csrf_token=$(csrf "$LIJST")" -d "actie=toevoegen" \
    --data-urlencode "naam=Dubbel" --data-urlencode "email=nieuw@example.nl" "$BASIS/admin/beheerders.php"
toets "hetzelfde adres twee keer toevoegen kan niet" "1" "$(sql "SELECT COUNT(*) FROM beheerders WHERE email='nieuw@example.nl'")"

echo ""
echo "── Wachtwoord kiezen via de link ───────────────────────────"
KOP=$(curl -s -o /dev/null -D - -b "$NIEUW" -c "$NIEUW" "$BASIS/$LINK1")
bevat "de code gaat uit het adres (doorverwijzing)" "wachtwoord_instellen.php" "$(printf '%s' "$KOP" | grep -i '^location:')"
bevat_niet "doorverwijzing bevat de code niet meer" "?t=" "$(printf '%s' "$KOP" | grep -i '^location:')"
UIT=$(kies_wachtwoord "$NIEUW" "$LINK1" "kort" "kort")
bevat "te kort wachtwoord geweigerd" "minimaal 12 tekens" "$UIT"
UIT=$(kies_wachtwoord "$NIEUW" "$LINK1" "EersteWachtwoord2026" "AnderWachtwoord2026")
bevat "ongelijke herhaling geweigerd" "niet gelijk" "$UIT"
UIT=$(kies_wachtwoord "$NIEUW" "$LINK1" "EersteWachtwoord2026" "EersteWachtwoord2026")
bevat "wachtwoord opgeslagen" "Uw wachtwoord is opgeslagen" "$UIT"
: > "$LOS"
bevat "dezelfde link werkt maar één keer" "werkt niet (meer)" "$(haal "$LOS" -L "$BASIS/$LINK1")"
toets "nieuwe beheerder logt in met het gekozen wachtwoord" "200" "$(inloggen "$NIEUW" nieuw@example.nl EersteWachtwoord2026)"

echo ""
echo "── Resetlink vanuit het beheer ─────────────────────────────"
NIEUW_ID=$(sql "SELECT id FROM beheerders WHERE email='nieuw@example.nl'")
postbus_leeg
for i in 1 2; do
    LIJST=$(haal "$BEHEER" "$BASIS/admin/beheerders.php")
    haal "$BEHEER" -o /dev/null -X POST -d "csrf_token=$(csrf "$LIJST")" -d "actie=link_versturen" \
        -d "beheerder_id=$NIEUW_ID" "$BASIS/admin/beheerders.php"
    sleep 1
    IFS=$'\t' read -r ONDERWERP LINK <<< "$(nieuwste_mail nieuw@example.nl)"
    [ "$i" = 1 ] && OUDE_LINK="$LINK" || NIEUWE_LINK="$LINK"
done
bevat "resetmail ontvangen" "Nieuw wachtwoord" "$ONDERWERP"
toets "twee verschillende links" "1" "$([ -n "$OUDE_LINK" ] && [ "$OUDE_LINK" != "$NIEUWE_LINK" ] && echo 1 || echo 0)"
toets "precies één link staat open" "1" "$(sql "SELECT COUNT(*) FROM beheerder_tokens WHERE beheerder_id=$NIEUW_ID AND gebruikt_op IS NULL")"
: > "$LOS"
bevat "een nieuwe link maakt de vorige ongeldig" "werkt niet (meer)" "$(haal "$LOS" -L "$BASIS/$OUDE_LINK")"
UIT=$(kies_wachtwoord "$LOS" "$NIEUWE_LINK" "TweedeWachtwoord2026" "TweedeWachtwoord2026")
bevat "nieuw wachtwoord opgeslagen" "Uw wachtwoord is opgeslagen" "$UIT"
toets "oude wachtwoord werkt niet meer" "302" "$(inloggen "$LOS" nieuw@example.nl EersteWachtwoord2026)"
# In $NIEUW zit nog de sessie van vóór de wachtwoordwijziging. Precies daarvoor
# wissel je een wachtwoord: wie al binnen was, hoort eruit te vliegen.
toets "de sessie van vóór de wijziging is weg" "302" "$(status "$NIEUW" "$BASIS/admin/index.php")"
toets "nieuwe wachtwoord werkt" "200" "$(inloggen "$NIEUW" nieuw@example.nl TweedeWachtwoord2026)"

echo ""
echo "── Uit- en inschakelen ─────────────────────────────────────"
LIJST=$(haal "$BEHEER" "$BASIS/admin/beheerders.php")
haal "$BEHEER" -o /dev/null -X POST -d "csrf_token=$(csrf "$LIJST")" -d "actie=link_versturen" \
    -d "beheerder_id=$NIEUW_ID" "$BASIS/admin/beheerders.php"
sleep 1
IFS=$'\t' read -r ONDERWERP OPEN_LINK <<< "$(nieuwste_mail nieuw@example.nl)"
LIJST=$(haal "$BEHEER" "$BASIS/admin/beheerders.php")
haal "$BEHEER" -o /dev/null -X POST -d "csrf_token=$(csrf "$LIJST")" -d "actie=uitschakelen" \
    -d "beheerder_id=$NIEUW_ID" "$BASIS/admin/beheerders.php"
toets "account uitgeschakeld" "0" "$(sql "SELECT actief FROM beheerders WHERE id=$NIEUW_ID")"
toets "lopende sessie werkt niet meer" "302" "$(status "$NIEUW" "$BASIS/admin/index.php")"
toets "inloggen lukt niet meer" "302" "$(inloggen "$LOS" nieuw@example.nl TweedeWachtwoord2026)"
: > "$LOS"
bevat "openstaande link werkt niet meer" "werkt niet (meer)" "$(haal "$LOS" -L "$BASIS/$OPEN_LINK")"

EIGEN_ID=$(sql "SELECT id FROM beheerders WHERE email='beheer@example.nl'")
LIJST=$(haal "$BEHEER" "$BASIS/admin/beheerders.php")
haal "$BEHEER" -o /dev/null -X POST -d "csrf_token=$(csrf "$LIJST")" -d "actie=uitschakelen" \
    -d "beheerder_id=$EIGEN_ID" "$BASIS/admin/beheerders.php"
toets "eigen account uitschakelen kan niet" "1" "$(sql "SELECT actief FROM beheerders WHERE id=$EIGEN_ID")"

LIJST=$(haal "$BEHEER" "$BASIS/admin/beheerders.php")
haal "$BEHEER" -o /dev/null -X POST -d "csrf_token=$(csrf "$LIJST")" -d "actie=inschakelen" \
    -d "beheerder_id=$NIEUW_ID" "$BASIS/admin/beheerders.php"
toets "weer ingeschakeld, eigen wachtwoord werkt" "200" "$(inloggen "$NIEUW" nieuw@example.nl TweedeWachtwoord2026)"

echo ""
echo "── Wachtwoord vergeten ─────────────────────────────────────"
sql "DELETE FROM aanvraag_limiet" >/dev/null
# Ook het logboek, anders telt de regel van een vorige run mee: twee runs achter
# elkaar duren samen minder dan de minuut waarover hieronder geteld wordt.
sql "DELETE FROM login_log WHERE soort='admin_reset'" >/dev/null
postbus_leeg
: > "$LOS"
vergeten() { # vergeten email → "tijd<TAB>html"
    local html; html=$(haal "$LOS" "$BASIS/admin/wachtwoord_vergeten.php")
    local uit; uit=$(mktemp)
    local tijd; tijd=$(curl -s -o "$uit" -w '%{time_total}' -b "$LOS" -c "$LOS" -X POST \
        -d "csrf_token=$(csrf "$html")" --data-urlencode "email=$1" "$BASIS/admin/wachtwoord_vergeten.php")
    printf '%s\t%s' "$tijd" "$(tr '\n\t' '  ' < "$uit")"; rm -f "$uit"
}
IFS=$'\t' read -r T_BEKEND HTML_BEKEND <<< "$(vergeten beheer@example.nl)"
IFS=$'\t' read -r T_ONBEKEND HTML_ONBEKEND <<< "$(vergeten niemand@example.nl)"
bevat "bekend adres: neutraal antwoord" "Hoort <strong>beheer@example.nl</strong> bij een actieve beheerder" "$HTML_BEKEND"
bevat "onbekend adres: hetzelfde antwoord" "Hoort <strong>niemand@example.nl</strong> bij een actieve beheerder" "$HTML_ONBEKEND"
toets "beide antwoorden duren minstens 2,4 seconden" "1" \
    "$(python3 -c "print(1 if min($T_BEKEND, $T_ONBEKEND) >= 2.4 else 0)")"
# De testomgeving heeft geen APP_URL: dan mag er zonder sessie niets uit.
sleep 1
toets "zonder APP_URL geen mail (Host-header niet te vertrouwen)" "0" "$(mails_aan beheer@example.nl)"
toets "en dat staat in het logboek" "1" \
    "$(sql "SELECT COUNT(*) FROM login_log WHERE soort='admin_reset' AND email='beheer@example.nl' AND detail LIKE '%APP_URL%' AND tijdstip > NOW() - INTERVAL 1 MINUTE")"
for i in 3 4 5; do vergeten "poging$i@example.nl" >/dev/null; done
IFS=$'\t' read -r _ HTML_TE_VEEL <<< "$(vergeten beheer@example.nl)"
bevat "na vijf aanvragen remt het IP-adres" "te veel resetlinks" "$HTML_TE_VEEL"

echo ""
echo "── Opruimen ────────────────────────────────────────────────"
sql "DELETE FROM beheerders WHERE email='nieuw@example.nl'; DELETE FROM aanvraag_limiet;" >/dev/null
toets "testaccount verwijderd (links via ON DELETE CASCADE)" "0" \
    "$(sql "SELECT COUNT(*) FROM beheerder_tokens WHERE beheerder_id=$NIEUW_ID")"

echo ""
echo "────────────────────────────────────────────────────────────"
printf '  %d geslaagd, %d mislukt\n\n' "$GOED" "$FOUT"
rm -f "$BEHEER" "$NIEUW" "$LOS"
[ "$FOUT" -eq 0 ]
