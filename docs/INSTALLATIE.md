# Installatiehandleiding — DJM Portaal

Deze handleiding beschrijft een complete installatie van het downloadportaal van de
Deventer Jeugd Musical, van lege server tot werkend portaal. Reken op ongeveer een uur,
plus de tijd die het uploaden van de video's kost.

Doorloop de hoofdstukken op volgorde.

> **Draait u op Plesk?** Lees dan sowieso [hoofdstuk 7b](#7b-plesk). Plesk zet standaard
> nginx vóór Apache en levert video's zelf uit op extensie; met de standaardinstellingen
> staan de registraties dan publiek op internet, terwijl het portaal er goed uitziet.

| Stap | Onderwerp |
|---|---|
| 1 | [Vereisten](#1-vereisten) |
| 2 | [Bestanden uploaden](#2-bestanden-uploaden) |
| 3 | [Database aanmaken](#3-database-aanmaken) |
| 4 | [setup.php draaien](#4-setupphp-draaien) |
| 5 | [.env in detail](#5-env-in-detail) |
| 6 | [Opslagmap inrichten](#6-opslagmap-inrichten) |
| 7 | [Webserver configureren](#7-webserver-configureren) |
| 7b | [**Plesk**](#7b-plesk) — lees dit als u op Plesk draait |
| 8 | [E-mail via Microsoft Graph](#8-e-mail-via-microsoft-graph) |
| 9 | [Cron instellen](#9-cron-instellen) |
| 10 | [Na installatie](#10-na-installatie) |
| 11 | [Probleemoplossing](#11-probleemoplossing) |

---

## 1. Vereisten

| Onderdeel | Eis |
|---|---|
| PHP | 8.1 of hoger (8.3 aanbevolen) |
| PHP-extensies | `pdo_mysql`, `curl`, `mbstring`, `openssl`, `json` |
| Database | MySQL 5.7+ of MariaDB 10.3+ |
| Webserver | nginx met PHP-FPM, of Apache 2.4 met `AllowOverride All` |
| HTTPS | Verplicht. Inlogcodes en sessiecookies horen niet over http te lopen. |
| Schijfruimte | De som van alle videobestanden, plus wat marge |
| E-mail | Een Microsoft 365-tenant met een postbus om vanaf te versturen |

Er zijn geen Composer-pakketten nodig; het project heeft geen externe PHP-afhankelijkheden.
Bootstrap en Bootstrap Icons staan in `assets/vendor/` en worden meegekopieerd: het portaal
laadt niets van een CDN en heeft dus geen uitgaande internetverbinding nodig voor zijn opmaak.
Zorg er bij het uitrollen alleen voor dat de map `assets/` compleet meegaat.

Controleer de PHP-versie en de extensies:

```bash
php -v
php -m | grep -E 'pdo_mysql|curl|mbstring|openssl|json'
```

Ontbreekt er iets op Debian of Ubuntu:

```bash
sudo apt install php8.3-fpm php8.3-mysql php8.3-curl php8.3-mbstring php8.3-xml
sudo systemctl restart php8.3-fpm
```

---

## 2. Bestanden uploaden

Zet de projectmap op de server. Op een VPS is `/var/www/djm-portaal` een logische plek;
op shared hosting is dat meestal `~/domains/portaal.example.nl/public_html`.

Met rsync vanaf uw eigen computer:

```bash
rsync -av --exclude '.git' --exclude '.env' --exclude 'opslag/' \
      ./ gebruiker@server:/var/www/djm-portaal/
```

Of met git op de server:

```bash
cd /var/www
git clone <repository-url> djm-portaal
```

Zet daarna de rechten goed. De webserver (meestal `www-data`) moet in `logs/` kunnen
schrijven, en in de opslagmap kunnen lezen:

```bash
cd /var/www/djm-portaal
sudo chown -R www-data:www-data logs
sudo chmod 750 logs
sudo find . -type f -name '*.php' -exec chmod 640 {} \;
sudo chown -R root:www-data /var/www/djm-portaal
```

> De PHP-bestanden hoeven niet schrijfbaar te zijn voor de webserver. Alleen `logs/` is dat wel.

---

## 3. Database aanmaken

Maak een database en een gebruiker die daar alleen bij kan:

```sql
CREATE DATABASE djm_portaal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'djm_portaal'@'localhost' IDENTIFIED BY 'een-lang-willekeurig-wachtwoord';
GRANT ALL PRIVILEGES ON djm_portaal.* TO 'djm_portaal'@'localhost';
FLUSH PRIVILEGES;
```

Op de commandoregel:

```bash
sudo mysql -e "CREATE DATABASE djm_portaal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Op shared hosting maakt u de database en de gebruiker aan via het hostingpaneel (DirectAdmin,
Plesk, cPanel). Noteer de gebruikersnaam, het wachtwoord en de exacte databasenaam — die
krijgt daar vaak een voorvoegsel, bijvoorbeeld `klant123_djm`.

Ook dit hoofdstuk mag u overslaan: `setup.php` biedt in stap 2 aan de database zelf aan te
maken, zolang de opgegeven databasegebruiker daar rechten voor heeft. Op shared hosting is dat
meestal niet zo — maak hem dan aan via het hostingpaneel.

De tabellen hoeft u in geen geval zelf aan te maken: dat doet `setup.php` in stap 3. Wilt u het
toch handmatig doen:

```bash
mysql -u djm_portaal -p djm_portaal < db.sql
```

---

## 4. `setup.php` draaien

Open in de browser:

```
https://portaal.example.nl/setup.php
```

De wizard bestaat uit vijf stappen en vult onderweg zelf `.env` in. U hoeft dus niets met de
hand te bewerken; hoofdstuk 5 legt alleen uit wat er in dat bestand terechtkomt.

1. **Controle** — PHP-versie, extensies en schrijfrechten op `logs/`. Elk punt krijgt een
   vinkje of een kruisje met uitleg. Los de kruisjes op en klik op "Opnieuw controleren".
   De pagina noemt ook de gebruiker waaronder PHP draait, zodat u die in de
   `chown`-opdrachten kunt gebruiken.
2. **Instellen** — hier vult u het webadres, de databasegegevens, de opslagmap en de manier
   van uitleveren in. De wizard:
   - controleert elk veld en slaat niets op zolang er een fout in zit;
   - maakt de opslagmap zo nodig aan en test met een echte schrijfpoging of het werkt;
   - genereert `APP_KEY` en `OTP_PEPPER` voor u;
   - test de databaseverbinding, en biedt aan de database aan te maken als de
     inloggegevens kloppen maar de database nog niet bestaat;
   - schrijft `.env` weg met rechten `600`, na eerst een reservekopie van de vorige versie
     te hebben gemaakt.

   Met **Alleen testen** controleert u alles zonder iets weg te schrijven. Bestaat er al een
   `.env`, dan worden wachtwoorden en sleutels niet teruggetoond: laat u zo'n veld leeg, dan
   blijft de bestaande waarde staan.

   Kan de webserver niet in de projectmap schrijven, dan is dat geen blokkade: de wizard toont
   de volledige inhoud van `.env` zodat u die zelf op de server kunt plaatsen.
3. **Database** — `db.sql` wordt statement voor statement uitgevoerd en u ziet per tabel of het
   lukte, plus het aantal tabellen in de database (dat moeten er twaalf zijn). Deze stap is
   veilig te herhalen: het schema gebruikt overal `CREATE TABLE IF NOT EXISTS`.
4. **Beheerder** — maak de eerste beheerder aan (naam, e-mailadres, wachtwoord van minimaal
   12 tekens). Die krijgt de rol *eigenaar*.
5. **Klaar** — de nazorglijst, met een knop waarmee de wizard zelf nakijkt of `.env` van
   buitenaf te downloaden is.

### Beveiliging van setup.php

Zodra er één actieve beheerder in de database staat, weigert `setup.php` nog iets te doen.
Alleen wie de beheerder zojuist in dezelfde sessie heeft aangemaakt, kan de slotpagina nog
openen. Wilt u de wizard later toch nog eens draaien, maak dan eerst een leeg bestand aan in de
projectmap:

```bash
touch /var/www/djm-portaal/setup.toegestaan
```

Verwijder dat bestand meteen weer na gebruik. Nog beter: verwijder `setup.php` helemaal
(hoofdstuk 10).

---

## 5. `.env` in detail

`setup.php` schrijft dit bestand voor u. Dit hoofdstuk is bedoeld om achteraf een waarde bij te
stellen, of om `.env` toch met de hand aan te maken:

```bash
cd /var/www/djm-portaal
cp .env.example .env
chmod 600 .env
```

`.env` bevat het databasewachtwoord en de geheime sleutels. Het bestand staat niet in git en
mag nooit via de browser te downloaden zijn (zie hoofdstuk 7 en 10).

Een waarde mag tussen dubbele aanhalingstekens staan; dat is de enige manier om een spatie aan
het begin of einde te bewaren. Binnen die aanhalingstekens gelden `\"`, `\\`, `\n`, `\r` en
`\t`. Een wachtwoord met een `#` of een aanhalingsteken erin hoort dus zo:

```
DB_PASS="wachtwoord#met\"tekens"
```

Een echte omgevingsvariabele (uit Docker, systemd of `SetEnv`) wint altijd van `.env`.

### De variabelen

| Variabele | Uitleg |
|---|---|
| `APP_NAME` | Naam die in mails en titels verschijnt. |
| `APP_URL` | De volledige basis-URL, bijvoorbeeld `https://portaal.example.nl`. Zonder afsluitende slash. Zonder deze waarde leidt het portaal de URL af uit het verzoek; dat gaat mis achter een proxy en in e-mails. **Vul dit in, en let erop dat het exact klopt** — zie de waarschuwing hieronder. |

> **Let op — een verkeerde `APP_URL` maakt inloggen onmogelijk.**
> Staat er bijvoorbeeld `http://` terwijl de site op `https://` draait, of `example.nl`
> terwijl bezoekers via `www.example.nl` binnenkomen, dan wijzen alle omleidingen naar
> een ander adres. Browsers weigeren dan formulieren te versturen, omdat de
> Content-Security-Policy van het portaal alleen hetzelfde adres toestaat
> (`form-action 'self'`). Het gevolg is dat niemand kan inloggen, zonder duidelijke
> foutmelding — de pagina lijkt gewoon niets te doen.
>
> Het beheeroverzicht waarschuwt hiervoor zodra het adres afwijkt. Twijfelt u, laat de
> regel dan leeg: het portaal leidt het adres dan zelf af uit het verzoek.
| `DEBUG` | `false` op productie. `true` toont PHP-foutmeldingen in de browser. |
| `DB_HOST` | Meestal `localhost`, soms `127.0.0.1` of een aparte databaseserver. |
| `DB_PORT` | Leeg laten betekent de standaardpoort (3306). |
| `DB_SOCKET` | Pad naar de Unix-socket, als de database daarover bereikbaar is. Heeft voorrang op `DB_HOST` en `DB_PORT`. |
| `DB_NAME` | Naam van de database uit hoofdstuk 3. |
| `DB_USER` / `DB_PASS` | De databasegebruiker en zijn wachtwoord. |
| `DB_CHARSET` | Laat op `utf8mb4` staan. |
| `OTP_PEPPER` | Geheime sleutel waarmee inlogcodes worden gehasht. Codes worden nooit als platte tekst opgeslagen. |
| `APP_KEY` | Geheime sleutel die downloadlinks en tokens ondertekent. |
| `TRUSTED_PROXIES` | Alleen invullen als er een reverse proxy of load balancer vóór het portaal staat. Zie de toelichting hieronder. |
| `OPSLAG_PAD` | Absoluut pad naar de map met de videobestanden. Zie hoofdstuk 6. Leeg = de map `opslag/` in het project. |
| `DELIVERY_MODE` | `auto`, `xaccel`, `xsendfile` of `php`. Zie hoofdstuk 7. |
| `XACCEL_PREFIX` | Alleen voor nginx: het interne pad uit het `internal` location-blok, standaard `/beveiligd/`. |
| `GRAPH_TENANT_ID` | Directory (tenant) ID uit Entra. Zie `GRAPH-SETUP.md`. |
| `GRAPH_CLIENT_ID` | Application (client) ID van de app-registratie. |
| `GRAPH_CLIENT_SECRET` | De waarde (niet de Secret ID) van de client secret. |
| `MAIL_VAN_ADRES` | Het afzenderadres, bijvoorbeeld `noreply@example.nl`. Moet een echte postbus zijn. |
| `MAIL_VAN_NAAM` | Weergavenaam van de afzender. |
| `SMTP_*` | Alleen nodig als u geen Graph gebruikt maar gewone SMTP. |

De Graph- en SMTP-gegevens kunt u ook later invullen via **Beheer → Instellingen**. Wat in
`.env` staat, wordt gebruikt bij een verse installatie.

### Staat er een proxy vóór het portaal?

Draait het portaal achter nginx als reverse proxy, achter HAProxy, Traefik, een load
balancer of Cloudflare, vul dan `TRUSTED_PROXIES` in met het adres of het bereik van die
proxy:

```ini
TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12
```

Losse IP-adressen en CIDR-bereiken mogen door elkaar, gescheiden door komma's, en zowel
IPv4 als IPv6.

**Waarom dit uitmaakt.** Zonder deze regel ziet het portaal van elke bezoeker het
IP-adres van de proxy, want dat is wat de webserver doorgeeft. Alle bezoekers delen dan
dezelfde teller, en de limiet van tien inlogcodes per uur per IP-adres sluit op een drukke
avond de hele vereniging tegelijk buiten. Met de regel erbij leest het portaal het echte
bezoekersadres uit de `X-Forwarded-For`-header en telt iedereen weer apart.

**Waarom dit niet standaard aanstaat.** `X-Forwarded-For` is een gewone header: iedere
bezoeker kan er zelf een meesturen. Zou het portaal die altijd geloven, dan kon iemand bij
elke aanvraag een ander adres opgeven en de limiet eindeloos omzeilen. Daarom wordt de
header alléén aangenomen van adressen die u hier zelf opgeeft, en telt binnen die header
alleen het deel dat uw eigen proxy heeft geschreven.

> **Vul hier niets in als er geen proxy voor staat.** Een te ruim bereik — of het bereik
> waar uw bezoekers zelf vandaan komen — geeft ze precies de mogelijkheid die deze
> instelling juist moet afsluiten.

Het beheeroverzicht waarschuwt zodra er doorstuurheaders binnenkomen terwijl
`TRUSTED_PROXIES` leeg is, zodat u het niet per ongeluk overslaat. Controleren of het
klopt kan in **Beheer → Logboek → Inloggen**: daar hoort bij uw eigen inlogpoging uw
eigen IP-adres te staan, niet dat van de proxy.

### APP_KEY en OTP_PEPPER genereren

Genereer twee verschillende willekeurige sleutels van 64 tekens:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Geen PHP op de commandoregel beschikbaar? Dan kan het ook met OpenSSL:

```bash
openssl rand -hex 32
```

Zet ze in `.env`:

```
APP_KEY=8f2c...64 tekens...
OTP_PEPPER=1a9e...64 tekens...
```

Makkelijker: laat `setup.php` ze genereren. Dat gebeurt automatisch zodra de sleutels leeg
zijn of te kort om bruikbaar te zijn.

> **Belangrijk:** bewaar deze waarden. Verandert `OTP_PEPPER` later, dan zijn alle openstaande
> inlogcodes ongeldig (niet erg — deelnemers vragen gewoon een nieuwe aan). Verandert `APP_KEY`,
> dan vervallen lopende downloadlinks en worden alle "onthoud dit apparaat"-cookies ongeldig.
>
> Laat u ze leeg, dan werkt het portaal wél, maar leidt het de sleutels af van de
> databasegegevens. Dat is duidelijk zwakker: wie het databasewachtwoord kent, kan dan
> downloadlinks ondertekenen. `setup.php` waarschuwt hier ook over.

---

## 6. Opslagmap inrichten

Hier komen de videobestanden te staan. De belangrijkste regel: **de bestanden mogen niet
rechtstreeks via een URL te downloaden zijn.** Alles loopt via `download.php`, dat eerst
controleert of de ingelogde deelnemer recht heeft op dat jaar.

Zet de map daarom bij voorkeur **buiten de webroot**.

Op een hostingpaneel is dat geen voorkeur maar een noodzaak: staat de map binnen de webroot,
dan is `.htaccess` de enige afscherming, en die wordt overgeslagen zodra er een webserver
vóór Apache staat die statische bestanden zelf afhandelt. Zie [hoofdstuk 7b](#7b-plesk) voor
Plesk; bij DirectAdmin en cPanel met een nginx-proxy geldt hetzelfde.

Geeft u het pad op in stap 2 van `setup.php`, dan maakt de wizard de map zelf aan en test hij
met een echte schrijfpoging of de webserver erin kan. Ligt de map binnen de webroot, dan krijgt
u daar een waarschuwing over. De opdrachten hieronder zijn voor wie het liever zelf doet, of
wie PHP niet genoeg rechten wil geven om mappen aan te maken.

### Op een VPS (aanbevolen)

```bash
sudo mkdir -p /var/djm-opslag/2026
sudo chown -R www-data:www-data /var/djm-opslag
sudo chmod -R 750 /var/djm-opslag
```

In `.env`:

```
OPSLAG_PAD=/var/djm-opslag
```

### Op shared hosting

Daar staat de webroot vaak in `public_html` en kunt u een map ernaast maken:

```
/home/klant123/domains/portaal.example.nl/public_html/     <- webroot
/home/klant123/domains/portaal.example.nl/djm-opslag/      <- opslag, niet publiek
```

In `.env`:

```
OPSLAG_PAD=/home/klant123/domains/portaal.example.nl/djm-opslag
```

Kan dat niet, en moet de map binnen de webroot blijven staan? Gebruik dan de meegeleverde
map `opslag/`. Daar staat een `.htaccess` in die alles weigert. Onder nginx werkt die
`.htaccess` niet — voeg dan het blok uit `docs/nginx.voorbeeld.conf` toe dat
`location ~ ^/(includes|logs|docs|opslag|sql)/` weigert.

### Indeling

Eén map per jaar, met een duidelijke bestandsnaam:

```
/var/djm-opslag/
├── 2025/
│   └── musical-2025.mp4
└── 2026/
    └── musical-2026.mp4
```

Video's van meerdere gigabytes upload u via SFTP, niet via de browser:

```bash
scp musical-2026.mp4 gebruiker@server:/var/djm-opslag/2026/
```

Controleer na afloop dat het bestand compleet is en dat de webserver het kan lezen:

```bash
ls -lh /var/djm-opslag/2026/
sudo -u www-data test -r /var/djm-opslag/2026/musical-2026.mp4 && echo leesbaar
```

---

## 7. Webserver configureren

> Gebruikt u een hostingpaneel in plaats van een eigen server? Dan beheert het paneel deze
> bestanden en moet u de instellingen via het paneel doen. Voor Plesk staat dat in
> [hoofdstuk 7b](#7b-plesk).

Er liggen twee kant-en-klare voorbeelden in deze map:

- **`docs/nginx.voorbeeld.conf`** — nginx met PHP-FPM
- **`docs/apache.voorbeeld.conf`** — Apache 2.4

Beide bevatten uitleg in commentaar. Twee punten verdienen extra aandacht.

> **Tip:** zodra het beheer bereikbaar is, staat onder **Beheer › Instellingen ›
> Serverconfiguratie** hetzelfde blok nog een keer, maar dan met de opslagmap en de prefix
> van *deze* installatie al ingevuld. Kopiëren en plakken scheelt het handmatig aanpassen
> van de paden — en daarmee de meest gemaakte fout.

### Uitlevering van grote bestanden

`DELIVERY_MODE` in `.env` bepaalt hoe een video bij de bezoeker komt:

| Modus | Wanneer |
|---|---|
| `auto` | Detecteert zelf de webserver. Prima startpunt. |
| `xaccel` | nginx. `download.php` stuurt een `X-Accel-Redirect`-header en nginx levert het bestand uit. Snelst, geen PHP-limieten. Vereist het `internal` location-blok en `XACCEL_PREFIX` in `.env`. |
| `xsendfile` | Apache met `mod_xsendfile`. Zelfde principe, met de `X-Sendfile`-header en `XSendFilePath`. |
| `php` | PHP levert het bestand zelf uit in blokken, met ondersteuning voor hervatten. Werkt overal, maar vereist ruime PHP-limieten. |

Bij nginx moet `XACCEL_PREFIX` in `.env` exact overeenkomen met het pad in de configuratie:

```nginx
location /beveiligd/ {
    internal;
    alias /var/djm-opslag/;
}
```

```
XACCEL_PREFIX=/beveiligd/
OPSLAG_PAD=/var/djm-opslag
```

Test daarna of het `internal` echt werkt: open
`https://portaal.example.nl/beveiligd/2026/musical-2026.mp4` rechtstreeks in de browser.
Dat **moet** een 404 opleveren.

Sneller gaat het met de knop **Uitproberen** onder **Beheer › Instellingen › Testen**. Die
zet kort een testbestand in de opslagmap, haalt het via dezelfde route op als een echte
video — volledig, hervat en met een onmogelijk bereik — controleert of het bestand
rechtstreeks te downloaden is, en ruimt zichzelf daarna op. Blijft dit ongedaan, dan merkt u
een verkeerde configuratie pas bij de eerste deelnemer met een video van meerdere
gigabytes.

### `.env` afschermen

Open `https://portaal.example.nl/.env` in de browser. U hoort een 403 of 404 te krijgen.
Ziet u de inhoud van het bestand, stop dan alles en los dit eerst op: het databasewachtwoord
en beide geheime sleutels liggen dan op straat.

- Apache: zorg dat `AllowOverride All` aanstaat, zodat de meegeleverde `.htaccess` werkt.
- nginx: het blok `location ~ /\. { deny all; }` uit het voorbeeldbestand regelt dit.

---

## 7b. Plesk

Draait het portaal op een Plesk-server, lees dan eerst dit hoofdstuk. Plesk zet standaard
**nginx vóór Apache**, en dat verandert twee dingen die u anders pas merkt als het misgaat.

### Het belangrijkste: nginx levert statische bestanden zelf uit

In **Websites & Domeinen → uw domein → Apache- en nginx-instellingen** staat een optie in de
trant van *"Statische bestanden rechtstreeks door nginx verwerken"*, met daaronder een lijst
met extensies. Die optie staat standaard aan, en in die lijst staan onder meer `mp4`, `avi`,
`mov` en `zip`.

Wat dat betekent: een verzoek om `https://uwdomein.nl/opslag/2026/musical-2026.mp4` wordt
dan door nginx zelf afgehandeld. Het komt **nooit bij Apache aan**, en dus doet de
`.htaccess` in `opslag/` niets. De videoregistraties staan dan gewoon publiek op internet
voor iedereen die het pad raadt — terwijl alles in het portaal er correct uitziet.

> Dit is geen theoretisch risico. Het is de standaardinstelling van Plesk in combinatie met
> de standaardlocatie van de opslagmap (`opslag/` binnen de projectmap).

**De oplossing: zet de opslagmap naast de webroot in plaats van erin.**

Op Plesk is `httpdocs` de webroot. Alles wat daar een niveau boven staat, is niet via een URL
te bereiken — ongeacht welke webserver ervoor staat, en ongeacht welke instellingen er
veranderen:

```
/var/www/vhosts/uwdomein.nl/
├── httpdocs/          ← hier staat het portaal (de webroot)
└── djm-opslag/        ← hier komen de video's (NIET bereikbaar via een URL)
```

Maak die map aan via **Bestanden** in Plesk of over SSH, en zet hem in `.env`:

```ini
OPSLAG_PAD=/var/www/vhosts/uwdomein.nl/djm-opslag
```

De map moet schrijfbaar zijn voor de systeemgebruiker van het abonnement (in Plesk meestal
de FTP-gebruiker van het domein). Over SSH:

```bash
mkdir -p /var/www/vhosts/uwdomein.nl/djm-opslag
chown uwgebruiker:psacln /var/www/vhosts/uwdomein.nl/djm-opslag
chmod 750 /var/www/vhosts/uwdomein.nl/djm-opslag
```

Zet de video's daarna via SFTP in die map, met een submap per jaar (`2026/`, `2027/`).

**Moet de map tóch binnen `httpdocs` blijven?** Sluit hem dan af in
**Apache- en nginx-instellingen → Aanvullende nginx-richtlijnen**:

```nginx
location ^~ /opslag/ {
    deny all;
}
location ^~ /logs/ {
    deny all;
}
location ^~ /includes/ {
    deny all;
}
location ~ /\. {
    deny all;
}
```

`^~` is hier belangrijk: daarmee wint dit blok van de regel die nginx gebruikt om statische
bestanden op extensie af te handelen.

### Controleer het, vertrouw het niet

Ga na de installatie naar **Beheer → Instellingen → Uitlevering van downloads uitproberen**.
Die knop zet kort een testbestand klaar en probeert het daarna op te halen — ook per
videoformaat apart, juist omdat een webserver per extensie kan verschillen. Bij de regel
*"Niet rechtstreeks bereikbaar"* hoort **OK** te staan. Staat er FOUT bij, dan zijn de video's
op dit moment publiek en klopt bovenstaande nog niet.

### Uitlevering van grote bestanden

| PHP-handler in Plesk | Zet in `.env` |
|---|---|
| FPM-toepassing bediend door Apache (standaard) | `DELIVERY_MODE=php` |
| FPM-toepassing bediend door nginx | `DELIVERY_MODE=php`, of `xaccel` mét de richtlijn hieronder |
| Apache-module | `DELIVERY_MODE=php` |

Laat `DELIVERY_MODE` op een Plesk-server niet op `auto` staan. De automatische herkenning
kijkt naar wie PHP draait; wordt dat nginx, dan kiest hij `xaccel` — en zonder het
`internal` location-blok krijgt de bezoeker dan een 404 in plaats van zijn video.

`mod_xsendfile` zit niet standaard in Plesk; `xsendfile` is dus geen optie tenzij u die
module zelf installeert.

**Belangrijk bij `DELIVERY_MODE=php`:** nginx bewaart standaard eerst het hele antwoord van
Apache voordat het naar de bezoeker gaat. Bij een video van enkele gigabytes loopt de
schijf vol of valt de download stil. Zet dit in **Aanvullende nginx-richtlijnen**:

```nginx
location ~ ^/download\.php {
    proxy_pass http://127.0.0.1:7080;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_buffering off;
    proxy_read_timeout 3600s;
    proxy_send_timeout 3600s;
}
```

> Controleer de poort: Plesk gebruikt meestal `7080` voor Apache over http en `7081` voor
> https. U vindt de juiste waarde in de door Plesk gegenereerde nginx-configuratie van het
> domein.

Wilt u liever `xaccel` (nginx levert de video zelf uit, buiten PHP en Apache om), voeg dan
óók dit toe en zet `DELIVERY_MODE=xaccel` met `XACCEL_PREFIX=/beveiligd/` in `.env`:

```nginx
location /beveiligd/ {
    internal;
    alias /var/www/vhosts/uwdomein.nl/djm-opslag/;
    add_header Accept-Ranges bytes;
}
```

Dit werkt ook als Apache de PHP-kant afhandelt: nginx ziet de `X-Accel-Redirect` in het
antwoord van Apache en neemt de uitlevering over. Controleer het daarna met dezelfde
knop **Uitlevering uitproberen** — die meldt welke route de bytes werkelijk heeft geleverd.

### Het IP-adres van de bezoeker

Omdat nginx vóór Apache staat, ziet PHP mogelijk `127.0.0.1` als adres van élke bezoeker.
Dan delen alle deelnemers dezelfde teller en sluit de limiet op inlogcodes iedereen tegelijk
buiten. Recente Plesk-versies lossen dit zelf op, maar controleer het:

Open **Beheer → Logboek → Inloggen** na uw eigen inlogpoging. Staat daar uw eigen IP-adres,
dan is er niets aan de hand. Staat er `127.0.0.1` (of waarschuwt het beheeroverzicht
erover), zet dan in `.env`:

```ini
TRUSTED_PROXIES=127.0.0.1,::1
```

Zie hoofdstuk 5 voor de achtergrond.

### PHP-instellingen

Zet deze niet in `.htaccess` — dat werkt alleen met de Apache-module, niet met FPM. Gebruik
**Websites & Domeinen → uw domein → PHP-instellingen**:

| Instelling | Waarde |
|---|---|
| `max_execution_time` | `0` (of ruim, bijvoorbeeld `3600`) bij `DELIVERY_MODE=php` |
| `memory_limit` | `256M` is ruim voldoende; het bestand wordt in blokken gelezen |
| `post_max_size` / `upload_max_filesize` | Alleen van belang als u video's via de browser wilt uploaden. Voor grote bestanden is SFTP de betere route. |
| `output_buffering` | `Off` |

### Cron

Gebruik de ingebouwde planner in plaats van `crontab -e`: **Websites & Domeinen → uw domein
→ Geplande taken → Taak toevoegen**, type *"PHP-script uitvoeren"*, met als pad:

```
/httpdocs/cron_opschonen.php
```

Dagelijks, bijvoorbeeld om 04:00. Plesk gebruikt dan vanzelf de PHP-versie van het domein.

---

## 8. E-mail via Microsoft Graph

Zonder werkende e-mail kan niemand inloggen — de inlogcode komt per mail.

De volledige instructie staat in **[GRAPH-SETUP.md](GRAPH-SETUP.md)**. Kort samengevat:
een app-registratie in Entra met de *toepassingsmachtiging* `Mail.Send`, beperkt tot één
postbus met een Application Access Policy, en de tenant-ID, client-ID en client secret
ingevuld in **Beheer → Instellingen**.

Test het daarna met de knoppen **Testmail versturen** en **Graph-configuratie controleren**
op diezelfde pagina.

---

## 9. Cron instellen

`cron_opschonen.php` ruimt dagelijks verlopen inlogcodes, verlopen tokens, oude
limietvensters en verouderde logregels op. Zonder deze taak blijft alles staan; het portaal
blijft werken, maar de tabellen groeien onnodig en logs worden langer bewaard dan de
ingestelde bewaartermijn.

Zet de taak klaar met `crontab -e` (als de gebruiker die ook de webserver draait):

```cron
0 4 * * * /usr/bin/php /var/www/djm-portaal/cron_opschonen.php >> /var/www/djm-portaal/logs/cron.log 2>&1
```

Controleer eerst het pad naar PHP:

```bash
which php
```

Op shared hosting staat PHP vaak elders, bijvoorbeeld `/usr/local/bin/php83`. Veel
hostingpanelen hebben ook een grafisch cronjob-scherm; gebruik daar hetzelfde commando.

Draai het script één keer met de hand om te zien of het werkt:

```bash
php /var/www/djm-portaal/cron_opschonen.php
```

U hoort per stap een regel met het aantal opgeruimde regels te zien.

De bewaartermijn stelt u in via **Beheer → Instellingen** (`log_bewaartermijn_dagen`,
standaard 365 dagen).

---

## 10. Na installatie

Loop deze lijst af zodra het portaal draait.

1. **Verwijder `setup.php`.**

   ```bash
   rm /var/www/djm-portaal/setup.php
   ```

   Het bestand beveiligt zichzelf zodra er een beheerder bestaat, maar weg is weg. Wilt u het
   bewaren, beperk het dan tot uw eigen IP-adres (zie de voorbeeldconfiguraties).

2. **Verwijder `setup.toegestaan`** als u dat had aangemaakt.

3. **Controleer dat `.env` niet publiek is.** De slotpagina van `setup.php` heeft daar een knop
   voor; die haalt het bestand via HTTP op en zegt wat eruit kwam. Doet u het zelf, open dan
   `https://portaal.example.nl/.env` in de browser — u hoort een 403 of 404 te krijgen, geen
   tekst. Zie verder hoofdstuk 7.

4. **Controleer dat de video's niet publiek zijn.** Probeer een bestand rechtstreeks te
   openen via de URL; dat moet mislukken. Test met de échte extensie van uw video
   (`.mp4`), niet met een willekeurig ander bestand: een webserver die statische bestanden
   zelf afhandelt doet dat per extensie, en dan zegt een `.txt` niets over een `.mp4`.
   De zelftest bij punt 9 doet dit voor alle videoformaten tegelijk.

5. **Zet HTTPS verplicht.** De omleiding staat in beide voorbeeldconfiguraties.

6. **Maak een back-up.** Twee dingen zijn onvervangbaar: de database en de videobestanden.

   ```bash
   mysqldump -u djm_portaal -p djm_portaal | gzip > djm-$(date +%F).sql.gz
   ```

   Bewaar ook `.env` op een veilige plek (bijvoorbeeld in een wachtwoordmanager) — zonder
   `APP_KEY` en `OTP_PEPPER` werkt een teruggezette back-up niet meer met bestaande cookies.

7. **Zet `DEBUG=false`** in `.env`.

8. **Zet een herinnering voor de vervaldatum van het Graph client secret.** Loopt dat af,
   dan stopt de mail — en daarmee het inloggen. Zie `GRAPH-SETUP.md`.

9. **Draai de uitleveringszelftest** onder **Beheer › Instellingen › Testen**, knop
   **Uitproberen**. Alle stappen horen groen te zijn — let vooral op *"Niet rechtstreeks
   bereikbaar"*, want dat is de regel die zegt of uw video's afgeschermd zijn. Dit is de
   snelste controle dat de serverconfiguratie uit hoofdstuk 7 (of 7b) ook echt doet wat de
   bedoeling is.

10. **Doe een volledige test met uw eigen e-mailadres**: code aanvragen, inloggen, downloaden.

---

## 11. Probleemoplossing

### Geen mail ontvangen

1. Kijk eerst in **Beheer → Logboek → Mail**. Staat de mail daar als *mislukt*, dan is het
   probleem de Graph-configuratie: de foutmelding staat erbij. Zie de foutentabel in
   `GRAPH-SETUP.md`.
2. Staat de mail als *verzonden*, dan is hij de deur uit. Laat de deelnemer in de map
   Ongewenst/Spam kijken.
3. Staat er helemaal niets in het logboek, dan is er geen mail geprobeerd. Dat is bijna altijd
   omdat het e-mailadres geen toegang heeft tot een gepubliceerde jaargang: het portaal geeft
   met opzet altijd dezelfde melding, ook bij een onbekend adres, zodat niemand kan aftasten
   welke adressen bekend zijn. Controleer het adres in **Beheer → Toegang** — let op typefouten
   en op adressen die de deelnemer niet meer gebruikt.
4. Komt de mail structureel in de spam terecht, controleer dan SPF, DKIM en DMARC voor het
   afzenderdomein (hoofdstuk 7 van `GRAPH-SETUP.md`).
5. Gebruik **Beheer → Instellingen → Testmail versturen** om los te testen.

### Download stopt halverwege

1. Kijk welke `DELIVERY_MODE` actief is. Bij `php` streamt PHP zelf, en dan is dit meestal een
   tijdslimiet. Zet `max_execution_time = 0` en `output_buffering = Off`, en verhoog de
   timeouts van de webserver (`fastcgi_read_timeout` bij nginx, `ProxyTimeout`/`Timeout` bij
   Apache met FPM).
2. Beter is het om PHP helemaal uit de weg te halen: `xaccel` onder nginx of `xsendfile` onder
   Apache. Zie hoofdstuk 7.
3. Staat er een reverse proxy of Cloudflare voor? Die kan een eigen timeout hanteren.
4. Controleer in **Beheer → Logboek → Downloads** hoeveel bytes er zijn verzonden. Stopt het
   telkens rond hetzelfde aantal, dan is het een limiet; verschilt het per keer, dan is het
   eerder de verbinding van de deelnemer.
5. Adviseer deelnemers met een wisselvallige verbinding een downloadmanager: het portaal
   ondersteunt hervatten (HTTP Range).

### "Bestand niet gevonden"

Het pad in de database wijst niet naar een bestaand bestand.

1. `jaargang_bestanden.pad` is **altijd relatief** ten opzichte van `OPSLAG_PAD`. Staat de
   video in `/var/djm-opslag/2026/musical-2026.mp4` en is `OPSLAG_PAD=/var/djm-opslag`, dan is
   het pad `2026/musical-2026.mp4` — niet het volledige pad.
2. Controleer of het bestand er echt staat en of de webserver het mag lezen:

   ```bash
   ls -l /var/djm-opslag/2026/
   sudo -u www-data test -r /var/djm-opslag/2026/musical-2026.mp4 && echo leesbaar
   ```

3. Let op hoofdletters: Linux maakt onderscheid tussen `Musical.mp4` en `musical.mp4`.
4. Is `OPSLAG_PAD` recent gewijzigd? Dan kloppen alle bestaande paden niet meer.
5. Kijk in `logs/app.log` voor de precieze melding.

### Sessie verloopt te snel

1. **Beheer → Instellingen → `sessie_duur_minuten`** (standaard 120) bepaalt na hoeveel
   minuten zonder activiteit iemand wordt uitgelogd.
2. Let op: PHP ruimt sessiebestanden op volgens zijn eigen `session.gc_maxlifetime`
   (standaard 1440 seconden = 24 minuten). Staat de instelling in het portaal hoger dan dat,
   verhoog dan ook de PHP-waarde:

   ```ini
   session.gc_maxlifetime = 7200
   ```

3. Op shared hosting delen meerdere sites soms dezelfde sessiemap, waardoor een andere site
   met een korte levensduur uw sessies opruimt. Geef het portaal dan een eigen sessiemap:

   ```ini
   session.save_path = "/home/klant123/sessies"
   ```

4. Zet **"onthoud dit apparaat"** aan (`remember_toestaan`, standaard aan, 30 dagen). Dan hoeft
   een deelnemer niet telkens een nieuwe code aan te vragen.
5. Krijgt u meldingen over "sessie verlopen" bij het versturen van een formulier, dan is dat de
   CSRF-controle. Meestal is er dan echt een sessie verlopen; controleer anders of de site
   afwisselend met en zonder `www` of via http én https bereikbaar is — dan wisselt het
   sessiecookie mee. Zet `APP_URL` vast en dwing één variant af.
