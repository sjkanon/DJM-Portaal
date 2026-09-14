#!/usr/bin/env bash
# De jaarlijkse handeling, van begin tot eind:
# beheerder maakt een jaargang, koppelt een video, importeert e-mailadressen en
# stuurt uitnodigingen; een geïmporteerde ouder logt daarna in en downloadt.
set -u
cd "$(dirname "$0")"
. ./hostpad.sh
BASIS=http://localhost:8123
BEHEER=$(mktemp); OUDER=$(mktemp)
GOED=0; FOUT=0
JAAR=2027
NIEUW_ADRES=nieuwe.ouder@example.nl

toets() {
    if [ "$2" = "$3" ]; then printf '  ✓ %s\n' "$1"; GOED=$((GOED+1));
    else printf '  ✗ %s\n      verwacht: %s\n      kreeg:    %s\n' "$1" "$2" "$3"; FOUT=$((FOUT+1)); fi
}
bevat() {
    if printf '%s' "$3" | grep -qF "$2"; then printf '  ✓ %s\n' "$1"; GOED=$((GOED+1));
    else printf '  ✗ %s (miste: %s)\n' "$1" "$2"; FOUT=$((FOUT+1)); fi
}
csrf() { printf '%s' "$1" | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | cut -d'"' -f4; }
php_in_container() { docker compose exec -T web php -r "\$_SERVER['SCRIPT_NAME']='/index.php'; require '/app/config.php'; $1" 2>/dev/null | tr -d '\r'; }

echo "── Opruimen en klaarzetten ─────────────────────────────────"
php_in_container "
db()->exec(\"DELETE FROM jaargangen WHERE jaar = $JAAR\");
db()->exec(\"DELETE FROM deelnemers WHERE email = '$NIEUW_ADRES'\");
db()->exec(\"DELETE FROM download_log WHERE email = '$NIEUW_ADRES'\");
db()->prepare('INSERT INTO beheerders (naam,email,wachtwoord_hash,rol) VALUES (:n,:e,:w,\"eigenaar\")
   ON DUPLICATE KEY UPDATE wachtwoord_hash=VALUES(wachtwoord_hash), actief=1')
  ->execute([':n'=>'Testbeheerder',':e'=>'beheer@example.nl',
             ':w'=>password_hash('EenHeelLangWachtwoord123', PASSWORD_DEFAULT)]);
db()->exec('DELETE FROM aanvraag_limiet');
@mkdir('/app/opslag/$JAAR', 0775, true);
file_put_contents('/app/opslag/$JAAR/musical-$JAAR.mp4', str_repeat('DJM', 8192));
echo 'klaar';" >/dev/null
curl -s -X DELETE http://localhost:8125/api/v1/messages >/dev/null 2>&1
echo "  testgegevens gewist, video van $JAAR in de opslagmap gezet"

echo ""
echo "── Stap 1: beheerder logt in ───────────────────────────────"
L=$(curl -s -c "$BEHEER" -b "$BEHEER" "$BASIS/admin/login.php")
curl -s -o /dev/null -c "$BEHEER" -b "$BEHEER" -d "csrf_token=$(csrf "$L")" \
    -d "email=beheer@example.nl" -d "wachtwoord=EenHeelLangWachtwoord123" "$BASIS/admin/login.php"
toets "beheer bereikbaar" "200" "$(curl -s -o /dev/null -w '%{http_code}' -b "$BEHEER" "$BASIS/admin/index.php")"

echo ""
echo "── Stap 2: jaargang $JAAR aanmaken ─────────────────────────"
J=$(curl -s -c "$BEHEER" -b "$BEHEER" "$BASIS/admin/jaargangen.php")
curl -s -L -o /dev/null -c "$BEHEER" -b "$BEHEER" -d "csrf_token=$(csrf "$J")" \
    -d "actie=opslaan" -d "id=0" -d "jaar=$JAAR" -d "titel=Jesus Christ Superstar" \
    -d "omschrijving=Uitvoering juni $JAAR in de Deventer Schouwburg." \
    -d "gepubliceerd=1" "$BASIS/admin/jaargangen.php"
JAARGANG_ID=$(php_in_container "echo (int)db()->query('SELECT id FROM jaargangen WHERE jaar = $JAAR')->fetchColumn();")
toets "jaargang staat in de database" "1" "$([ "${JAARGANG_ID:-0}" -gt 0 ] && echo 1 || echo 0)"
LIJST=$(curl -s -b "$BEHEER" "$BASIS/admin/jaargangen.php")
bevat "jaargang zichtbaar in het overzicht" "Jesus Christ Superstar" "$LIJST"

echo ""
echo "── Stap 3: video koppelen ──────────────────────────────────"
B=$(curl -s -c "$BEHEER" -b "$BEHEER" "$BASIS/admin/bestanden.php?jaargang=$JAARGANG_ID")
bevat "de video uit de opslagmap wordt gevonden" "musical-$JAAR.mp4" "$B"
curl -s -L -o /dev/null -c "$BEHEER" -b "$BEHEER" -d "csrf_token=$(csrf "$B")" \
    -d "actie=koppelen" -d "jaargang=$JAARGANG_ID" -d "pad=$JAAR/musical-$JAAR.mp4" \
    -d "titel=Volledige registratie" -d "actief=1" --data-urlencode "bestandsnaam=DJM $JAAR - Jesus Christ Superstar.mp4" \
    "$BASIS/admin/bestanden.php"
AANTAL_BESTANDEN=$(php_in_container "echo (int)db()->query('SELECT COUNT(*) FROM jaargang_bestanden WHERE jaargang_id = $JAARGANG_ID')->fetchColumn();")
toets "video gekoppeld aan de jaargang" "1" "$AANTAL_BESTANDEN"

echo ""
echo "── Stap 4: e-mailadressen importeren ───────────────────────"
T=$(curl -s -c "$BEHEER" -b "$BEHEER" "$BASIS/admin/toegang.php?jaargang=$JAARGANG_ID")
ADRESSEN="Nieuwe Ouder <$NIEUW_ADRES>
ouder@example.nl
dit is geen adres
tweede.ouder@example.nl;Tweede Ouder"
VOORBEELD=$(curl -s -L -c "$BEHEER" -b "$BEHEER" -d "csrf_token=$(csrf "$T")" \
    -d "actie=voorbeeld" -d "jaargang=$JAARGANG_ID" --data-urlencode "adressen=$ADRESSEN" \
    "$BASIS/admin/toegang.php")
bevat "voorbeeldstap toont het nieuwe adres" "$NIEUW_ADRES" "$VOORBEELD"
bevat "voorbeeldstap meldt de ongeldige regel" "dit is geen adres" "$VOORBEELD"
NOG_NIET=$(php_in_container "echo (int)db()->query('SELECT COUNT(*) FROM toegang WHERE jaargang_id = $JAARGANG_ID')->fetchColumn();")
toets "voorbeeldstap schrijft nog niets weg" "0" "$NOG_NIET"

curl -s -L -o /dev/null -c "$BEHEER" -b "$BEHEER" -d "csrf_token=$(csrf "$VOORBEELD")" \
    -d "actie=bevestigen" -d "jaargang=$JAARGANG_ID" -d "uitnodiging=1" "$BASIS/admin/toegang.php"
TOEGEKEND=$(php_in_container "echo (int)db()->query('SELECT COUNT(*) FROM toegang WHERE jaargang_id = $JAARGANG_ID')->fetchColumn();")
toets "drie geldige adressen toegang gegeven" "3" "$TOEGEKEND"
sleep 2
UITNODIGINGEN=$(curl -s "http://localhost:8125/api/v1/messages" | python3 -c 'import sys,json;print(json.load(sys.stdin)["total"])')
toets "drie uitnodigingen verstuurd" "3" "$UITNODIGINGEN"

echo ""
echo "── Stap 5: de nieuwe ouder logt in ─────────────────────────"
curl -s -X DELETE http://localhost:8125/api/v1/messages >/dev/null 2>&1
H=$(curl -s -c "$OUDER" -b "$OUDER" "$BASIS/index.php")
curl -s -o /dev/null -c "$OUDER" -b "$OUDER" -d "csrf_token=$(csrf "$H")" \
    -d "email=$NIEUW_ADRES" "$BASIS/index.php"
sleep 1
CODE=$(curl -s http://localhost:8125/api/v1/messages | python3 -c '
import sys,json,urllib.request,re
d=json.load(sys.stdin)
if not d["total"]: print(""); raise SystemExit
mid=d["messages"][0]["ID"]
m=json.load(urllib.request.urlopen(f"http://localhost:8125/api/v1/message/{mid}"))
t=re.sub(r"<[^>]+>"," ",(m.get("HTML") or "")+(m.get("Text") or ""))
c=re.findall(r"\b\d{6}\b",t); print(c[0] if c else "")')
toets "inlogcode ontvangen" "6" "${#CODE}"

V=$(curl -s -c "$OUDER" -b "$OUDER" "$BASIS/verifieer.php")
curl -s -o /dev/null -c "$OUDER" -b "$OUDER" -d "csrf_token=$(csrf "$V")" \
    -d "code=$CODE" "$BASIS/verifieer.php"
P=$(curl -s -b "$OUDER" "$BASIS/portaal/index.php")
bevat "portaal toont de nieuwe jaargang" "$JAAR" "$P"
bevat "portaal toont de musicaltitel" "Jesus Christ Superstar" "$P"

echo ""
echo "── Stap 6: downloaden ──────────────────────────────────────"
LINK=$(printf '%s' "$P" | grep -o 'download\.php?b=[^"]*' | head -1 | sed 's/&amp;/\&/g')
if [ -z "$LINK" ]; then printf '  ✗ geen downloadlink in het portaal\n'; FOUT=$((FOUT+1)); LINK="download.php?b=0"; fi
KOP=$(curl -s -D - -o /tmp/djm-jaar.bin -b "$OUDER" "$BASIS/$LINK")
toets "download geeft 200" "200" "$(printf '%s' "$KOP" | head -1 | grep -o '[0-9]\{3\}')"
bevat "juiste bestandsnaam aangeboden" "Jesus Christ Superstar.mp4" "$KOP"
toets "volledig bestand ontvangen" "24576" "$(stat -c%s /tmp/djm-jaar.bin)"
GELOGD=$(php_in_container "echo (int)db()->query('SELECT COUNT(*) FROM download_log WHERE email = \"$NIEUW_ADRES\"')->fetchColumn();")
toets "download is vastgelegd in het logboek" "1" "$GELOGD"

echo ""
echo "── Stap 7: een oud jaar blijft afgeschermd ─────────────────"
ANDER=$(php_in_container "echo (int)db()->query('SELECT b.id FROM jaargang_bestanden b JOIN jaargangen j ON j.id=b.jaargang_id WHERE j.jaar = 2026 LIMIT 1')->fetchColumn();")
if [ "${ANDER:-0}" -gt 0 ]; then
    STATUS=$(curl -s -o /dev/null -w '%{http_code}' -b "$OUDER" "$BASIS/download.php?b=$ANDER")
    toets "video van 2026 niet toegankelijk voor deze ouder" "404" "$STATUS"
else
    echo "  – geen tweede jaargang aanwezig, overgeslagen"
fi

echo ""
echo "────────────────────────────────────────────────────────────"
printf '  %d geslaagd, %d mislukt\n\n' "$GOED" "$FOUT"
rm -f "$BEHEER" "$OUDER"
[ "$FOUT" -eq 0 ]
