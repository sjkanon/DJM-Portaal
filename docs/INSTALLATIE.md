# Installatiehandleiding — DJM Portaal

Deze handleiding beschrijft een complete installatie van het downloadportaal van de
Deventer Jeugd Musical, van lege server tot werkend portaal. Reken op ongeveer een uur,
plus de tijd die het uploaden van de video's kost.

Doorloop de hoofdstukken op volgorde.

| Stap | Onderwerp |
|---|---|
| 1 | [Vereisten](#1-vereisten) |
| 2 | [Bestanden uploaden](#2-bestanden-uploaden) |
| 3 | [Database aanmaken](#3-database-aanmaken) |
| 4 | [.env invullen](#4-env-invullen) |
| 5 | [setup.php draaien](#5-setupphp-draaien) |
| 6 | [Opslagmap inrichten](#6-opslagmap-inrichten) |
| 7 | [Webserver configureren](#7-webserver-configureren) |
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
Bootstrap wordt via een CDN geladen.

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

De tabellen zelf hoeft u niet aan te maken: dat doet `setup.php` in stap 5. Wilt u het toch
handmatig doen:

```bash
mysql -u djm_portaal -p djm_portaal < db.sql
```

---

## 4. `.env` invullen

Kopieer het voorbeeldbestand:

```bash
cd /var/www/djm-portaal
cp .env.example .env
chmod 640 .env
sudo chown root:www-data .env
```

`.env` bevat het databasewachtwoord en de geheime sleutels. Het bestand staat niet in git en
mag nooit via de browser te downloaden zijn (zie hoofdstuk 7 en 10).

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
| `DB_NAME` | Naam van de database uit stap 3. |
| `DB_USER` / `DB_PASS` | De databasegebruiker en zijn wachtwoord. |
| `DB_CHARSET` | Laat op `utf8mb4` staan. |
| `OTP_PEPPER` | Geheime sleutel waarmee inlogcodes worden gehasht. Codes worden nooit als platte tekst opgeslagen. |
| `APP_KEY` | Geheime sleutel die downloadlinks en tokens ondertekent. |
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

`setup.php` toont in stap 1 ook twee kant-en-klare sleutels die u kunt overnemen.

> **Belangrijk:** bewaar deze waarden. Verandert `OTP_PEPPER` later, dan zijn alle openstaande
> inlogcodes ongeldig (niet erg — deelnemers vragen gewoon een nieuwe aan). Verandert `APP_KEY`,
> dan vervallen lopende downloadlinks en worden alle "onthoud dit apparaat"-cookies ongeldig.
>
> Laat u ze leeg, dan werkt het portaal wél, maar leidt het de sleutels af van de
> databasegegevens. Dat is duidelijk zwakker: wie het databasewachtwoord kent, kan dan
> downloadlinks ondertekenen. `setup.php` waarschuwt hier ook over.

---

## 5. `setup.php` draaien

Open in de browser:

```
https://portaal.example.nl/setup.php
```

De wizard bestaat uit vier stappen.

1. **Controle** — PHP-versie, extensies, schrijfrechten en de aanwezigheid van `.env`.
   Elk punt krijgt een vinkje of een kruisje met uitleg. Los de kruisjes op en klik op
   "Opnieuw controleren".
2. **Database** — de wizard test de verbinding met de gegevens uit `.env`. Werkt die, klik dan
   op **Database inrichten**. `db.sql` wordt statement voor statement uitgevoerd en u ziet per
   tabel of het lukte, plus het aantal tabellen in de database (dat moeten er twaalf zijn). Deze
   stap is veilig te herhalen: het schema gebruikt overal `CREATE TABLE IF NOT EXISTS`.
3. **Beheerder** — maak de eerste beheerder aan (naam, e-mailadres, wachtwoord van minimaal
   12 tekens). Die krijgt de rol *eigenaar*.
4. **Klaar** — links naar het beheerdersgedeelte en het portaal.

### Beveiliging van setup.php

Zodra er één actieve beheerder in de database staat, weigert `setup.php` nog iets te doen.
Wilt u de wizard later toch nog eens draaien, maak dan eerst een leeg bestand aan in de
projectmap:

```bash
touch /var/www/djm-portaal/setup.toegestaan
```

Verwijder dat bestand meteen weer na gebruik. Nog beter: verwijder `setup.php` helemaal
(hoofdstuk 10).

---

## 6. Opslagmap inrichten

Hier komen de videobestanden te staan. De belangrijkste regel: **de bestanden mogen niet
rechtstreeks via een URL te downloaden zijn.** Alles loopt via `download.php`, dat eerst
controleert of de ingelogde deelnemer recht heeft op dat jaar.

Zet de map daarom bij voorkeur **buiten de webroot**.

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

Er liggen twee kant-en-klare voorbeelden in deze map:

- **`docs/nginx.voorbeeld.conf`** — nginx met PHP-FPM
- **`docs/apache.voorbeeld.conf`** — Apache 2.4

Beide bevatten uitleg in commentaar. Twee punten verdienen extra aandacht.

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

### `.env` afschermen

Open `https://portaal.example.nl/.env` in de browser. U hoort een 403 of 404 te krijgen.
Ziet u de inhoud van het bestand, stop dan alles en los dit eerst op: het databasewachtwoord
en beide geheime sleutels liggen dan op straat.

- Apache: zorg dat `AllowOverride All` aanstaat, zodat de meegeleverde `.htaccess` werkt.
- nginx: het blok `location ~ /\. { deny all; }` uit het voorbeeldbestand regelt dit.

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

3. **Controleer dat `.env` niet publiek is** — zie hoofdstuk 7.

4. **Controleer dat de video's niet publiek zijn.** Probeer een bestand rechtstreeks te
   openen via de URL; dat moet mislukken.

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

9. **Doe een volledige test met uw eigen e-mailadres**: code aanvragen, inloggen, downloaden.

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
