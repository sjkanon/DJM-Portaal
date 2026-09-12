# DJM Portaal

Beveiligd downloadportaal voor de videoregistraties van de **Deventer Jeugd Musical**.
Deelnemers halen hier de video op van het jaar waarin zij hebben meegespeeld.

Versie **1.0.0** · PHP 8.1+ · MySQL/MariaDB · geen Composer-afhankelijkheden

---

## Hoe het werkt

Een deelnemer vult op de startpagina zijn e-mailadres in. Staat dat adres op de lijst van een
gepubliceerde jaargang, dan stuurt het portaal via de Microsoft Graph API een eenmalige code van
zes cijfers naar dat adres. Met die code logt de deelnemer in — er zijn geen wachtwoorden en
geen accounts om te beheren. Na het inloggen ziet hij alleen de jaren waarvoor hij is
toegevoegd, met een downloadknop per bestand. De videobestanden staan buiten de webroot en zijn
uitsluitend via `download.php` te bereiken, dat eerst de sessie en de toegangsrechten
controleert.

---

## Functionaliteit

**Voor de deelnemer**

- Inloggen met e-mailadres en een eenmalige code; geen wachtwoord om te onthouden
- Overzicht van de eigen jaargangen met titel, omschrijving en bestandsgrootte
- Downloads die te hervatten zijn (HTTP Range), ook met een downloadmanager
- Optioneel "onthoud dit apparaat" voor 30 dagen

**Voor de beheerder**

- Jaargangen aanmaken, publiceren, depubliceren en laten verlopen
- Bestanden koppelen: kiezen uit de opslagmap (voor grote video's via SFTP) of uploaden
- Toegang per jaargang beheren: e-mailadressen plakken of via CSV importeren, met preview
  en optioneel meteen een uitnodigingsmail
- Deelnemers zoeken, blokkeren en verwijderen (AVG)
- Logboeken voor inlogpogingen, verstuurde mail en downloads
- Instellingen voor Microsoft Graph, afzender, branding en mailteksten, met een testmail en
  een Graph-diagnose

**Een nieuw jaar toevoegen kost geen regel code**: video uploaden, jaargang aanmaken, bestand
koppelen, e-mailadressen importeren.

---

## Mapstructuur

```
config.php                   .env-loader, db(), instellingen, helpers, securityheaders
db.sql                       volledig databaseschema (leidend)
setup.php                    installatiewizard: omgeving, .env, database, beheerder
index.php                    e-mailadres invoeren, code aanvragen
verifieer.php                code invoeren
logout.php
download.php                 toegangscontrole en uitlevering
zelftest.php                 levert het testbestand van de uitleveringszelftest uit
cron_opschonen.php           dagelijkse opschoontaak (CLI)
.htaccess                    Apache: afscherming en securityheaders
VERSION

portaal/index.php            overzicht van de eigen jaargangen

includes/
├── auth.php                 sessies, CSRF, deelnemer- en beheerderidentiteit
├── otp.php                  codes genereren, versturen, verifiëren, throttling
├── email_helper.php         GraphMailer, SMTP-fallback, mailsjablonen
├── toegang_helper.php       wie mag welke jaargang en welk bestand
├── download_helper.php      X-Accel-Redirect / X-Sendfile / PHP-stream
├── uitlevering_helper.php   serverconfiguratie tonen en de uitlevering uitproberen
├── layout.php               gedeelde opmaak voor het portaal
└── opmaak.php               merkkleur, logo, favicon en het <head>-blok van alle pagina's

admin/                       beheerinterface
admin/assets/admin.js        het enige JavaScript van het beheer (de CSP staat inline niet toe)
assets/djm.css               eigen opmaak voor portaal, beheer en installatie
assets/vendor/               Bootstrap en Bootstrap Icons, meegeleverd (zie HERKOMST.md)
opslag/                      videobestanden — .htaccess weigert alles
logs/                        applicatie- en foutlogboek
docs/                        documentatie en voorbeeldconfiguraties
```

---

## Documentatie

| Document | Voor wie |
|---|---|
| [docs/INSTALLATIE.md](docs/INSTALLATIE.md) | De serverbeheerder: van lege server tot werkend portaal, inclusief probleemoplossing |
| [docs/GRAPH-SETUP.md](docs/GRAPH-SETUP.md) | De serverbeheerder: Microsoft Graph instellen zodat de inlogcodes verstuurd worden |
| [docs/NIEUW-JAAR.md](docs/NIEUW-JAAR.md) | De beheerder van de vereniging: elk jaar een nieuwe jaargang toevoegen |

Verder in `docs/`: [ARCHITECTUUR.md](docs/ARCHITECTUUR.md) met de afspraken in de code, en
[nginx.voorbeeld.conf](docs/nginx.voorbeeld.conf) en
[apache.voorbeeld.conf](docs/apache.voorbeeld.conf) als kant-en-klare serverconfiguraties.

---

## Techniek

- **PHP 8.1 of hoger**, zonder Composer of externe PHP-pakketten
- **MySQL 5.7+ / MariaDB 10.3+** via PDO, uitsluitend prepared statements
- **Bootstrap 5** en **Bootstrap Icons**, meegeleverd in `assets/vendor/` — geen CDN, dus de
  opmaak blijft staan zonder internetverbinding en de CSP hoeft geen externe bron toe te laten
- **Microsoft Graph API** (app-only, client credentials) voor e-mail, met SMTP als terugvaloptie
- **Uitlevering** via `X-Accel-Redirect` (nginx), `X-Sendfile` (Apache met `mod_xsendfile`) of
  streaming door PHP zelf — instelbaar met `DELIVERY_MODE` in `.env`
- Nederlands in code, interface en commentaar; vier spaties inspringen

Vereiste PHP-extensies: `pdo_mysql`, `curl`, `mbstring`, `openssl`, `json`.

---

## Testen

Er is een testomgeving op basis van Docker (MariaDB, Mailpit en PHP), zodat de
hele keten zonder installatie te draaien is:

```bash
bash test/alles.sh
```

Dat draait achtereenvolgens:

- syntaxcontrole en een statische controle op SQL-interpolatie, dubbel gebruikte
  query-parameters, CSRF, uitvoer-escaping en autorisatie;
- een verse installatie via `setup.php`, in een container zonder omgevingsvariabelen, zodat
  de wizard `.env` echt zelf moet schrijven;
- de kernlogica en de inlogcodes (eenmalig gebruik, pogingenlimiet, throttling,
  binding aan de browser, timinggedrag);
- de beveiliging: securityheaders, afgeschermde paden per webserver, uitloggen dat
  alleen via POST gaat, en de helpers voor mailheaders, bestandspaden,
  downloadhandtekeningen, de Host-header en de CSV-export;
- de publieke inlogflow met een echte mailserver, en elke beheerpagina;
- de volledige jaarlijkse workflow: jaargang aanmaken, video koppelen,
  e-mailadressen importeren, uitnodigen, inloggen en downloaden;
- het portaal en het beheer in een echte browser, ook op telefoonformaat;
- **de uitlevering op de webservers waar hij voor bedoeld is**: PHP-streaming,
  nginx met `X-Accel-Redirect` en Apache met `mod_xsendfile`, elk met volledige
  download, hervatten via Range, een 416 bij een onmogelijk bereik, en de
  controle dat de videomap niet rechtstreeks bereikbaar is;
- dezelfde drie routes nog een keer via de zelftest die in het beheer achter de
  knop **Uitproberen** zit, zodat die knop meeloopt met elke wijziging;
- de foutafhandeling van de Graph-mailer en het opschoonscript.

**Grote bestanden.** Er is een aparte test voor een video van 5 GB, die controleert
of groottes en offsets voorbij de 2 GB-grens kloppen. Het testbestand staat niet in
git; maak het eerst aan (het kost geen schijfruimte, het is een sparse bestand):

```bash
truncate -s 5G opslag/2028/musical-2028.mp4
```

Zonder dat bestand slaat die test zichzelf over.

De testomgeving is bereikbaar op <http://localhost:8123> (ingebouwde PHP-server),
<http://localhost:8126> (nginx) en <http://localhost:8127> (Apache), met
<http://localhost:8125> als Mailpit om de verstuurde e-mails te bekijken.
Afsluiten met `docker compose -f test/docker-compose.yml down`.

## Beveiliging

- **Inlogcodes** zijn zes cijfers, tien minuten geldig, eenmalig bruikbaar en na vijf foute
  pogingen ongeldig. Ze worden nooit als platte tekst opgeslagen, maar als
  `hash_hmac('sha256', code, OTP_PEPPER)`. Een code is via een `challenge_id` gebonden aan de
  browser waarin hij is aangevraagd, zodat een doorgestuurde code elders niet werkt.
- **Geen user enumeration.** De startpagina toont altijd dezelfde melding, of het adres nu
  bekend is of niet.
- **Rate limiting** op zowel het aanvragen als het verifiëren van codes, per e-mailadres én per
  IP-adres. Staat er een reverse proxy of load balancer vóór het portaal, zet die dan in
  `TRUSTED_PROXIES` in `.env` — pas dan telt het echte bezoekersadres mee in plaats van het
  adres van de proxy. Zonder die regel wordt `X-Forwarded-For` genegeerd, want die header is
  door iedere bezoeker zelf te verzinnen. Het beheeroverzicht waarschuwt als het portaal
  doorstuurheaders binnenkrijgt terwijl er niets is ingesteld.
- **Bestandspaden komen nooit uit gebruikersinvoer.** Een download loopt van id naar database
  naar een pad dat gecontroleerd binnen de opslagmap moet vallen. De videobestanden staan bij
  voorkeur buiten de webroot en zijn niet met een directe URL te benaderen.
- **CSRF-token** op elk formulier, ook op uitloggen; `session_regenerate_id(true)` bij elke
  inlog; sessiecookies met `httponly`, `secure` en `samesite`. De `secure`-vlag wordt ook
  gezet achter een TLS-afsluitende proxy.
- **Securityheaders** (CSP, HSTS, `X-Content-Type-Options`, `X-Frame-Options`,
  `Referrer-Policy`, `Cache-Control: no-store`) worden door PHP gestuurd en nog eens door
  de webserver als vangnet. De CSP staat geen inline JavaScript toe: alle scripts staan in
  losse bestanden.
- **Logging** van alle inlog-, mail- en downloadgebeurtenissen met IP en tijdstip, met een
  instelbare bewaartermijn die `cron_opschonen.php` dagelijks afdwingt.
- **`.env` bevat de geheimen** (databasewachtwoord, `APP_KEY`, `OTP_PEPPER`) en staat niet in
  git. `setup.php` schrijft het bestand met rechten `600`, bewaart eerst een reservekopie van
  de vorige versie, en kan op de slotpagina zelf nakijken of `.env` van buitenaf te downloaden
  is. Verwijder `setup.php` daarna.
- **Afscherming in lagen.** De webserverconfiguratie is de eerste laag; `opslag/`, `logs/`,
  `includes/` en `docs/` hebben daarnaast elk een eigen `.htaccess`. Let bij nginx op de
  volgorde van de `location`-blokken: de eerste passende regex wint, dus de deny-blokken
  horen bóven het PHP-blok te staan. `docs/nginx.voorbeeld.conf` heeft die volgorde al.
- **`bash test/beveiliging.sh`** meet dit allemaal aan de draaiende server: securityheaders,
  afgeschermde paden op nginx én Apache, het uitloggedrag, en de beveiligingshelpers.

Een beveiligingsprobleem gevonden? Meld het bij de beheerder van de vereniging in plaats van
het publiek te maken.
