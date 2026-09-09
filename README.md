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
setup.php                    installatiewizard — verwijderen na installatie
index.php                    e-mailadres invoeren, code aanvragen
verifieer.php                code invoeren
logout.php
download.php                 toegangscontrole en uitlevering
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
└── layout.php               gedeelde opmaak voor het portaal

admin/                       beheerinterface
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
- **Bootstrap 5** en **Bootstrap Icons** via het jsDelivr-CDN
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

Dat draait achtereenvolgens de syntaxcontrole, een statische controle op
SQL-interpolatie, CSRF, uitvoer-escaping en autorisatie, de kernlogica, de
inlogcodes (eenmalig gebruik, pogingenlimiet, throttling, timinggedrag), de
publieke inlogflow met een echte mailserver, elke beheerpagina, en tot slot de
volledige jaarlijkse workflow: jaargang aanmaken, video koppelen,
e-mailadressen importeren, uitnodigen, inloggen en downloaden.

De testomgeving is bereikbaar op <http://localhost:8123> (portaal) en
<http://localhost:8125> (Mailpit, om de verstuurde e-mails te bekijken).
Afsluiten met `docker compose -f test/docker-compose.yml down`.

## Beveiliging

- **Inlogcodes** zijn zes cijfers, tien minuten geldig, eenmalig bruikbaar en na vijf foute
  pogingen ongeldig. Ze worden nooit als platte tekst opgeslagen, maar als
  `hash_hmac('sha256', code, OTP_PEPPER)`. Een code is via een `challenge_id` gebonden aan de
  browser waarin hij is aangevraagd, zodat een doorgestuurde code elders niet werkt.
- **Geen user enumeration.** De startpagina toont altijd dezelfde melding, of het adres nu
  bekend is of niet.
- **Rate limiting** op zowel het aanvragen als het verifiëren van codes, per e-mailadres én per
  IP-adres.
- **Bestandspaden komen nooit uit gebruikersinvoer.** Een download loopt van id naar database
  naar een pad dat gecontroleerd binnen de opslagmap moet vallen. De videobestanden staan bij
  voorkeur buiten de webroot en zijn niet met een directe URL te benaderen.
- **CSRF-token** op elk formulier; `session_regenerate_id(true)` bij elke inlog; sessiecookies
  met `httponly`, `secure` en `samesite`.
- **Securityheaders** (CSP, HSTS, `X-Content-Type-Options`, `X-Frame-Options`,
  `Referrer-Policy`) worden door PHP gestuurd en nog eens door de webserver als vangnet.
- **Logging** van alle inlog-, mail- en downloadgebeurtenissen met IP en tijdstip, met een
  instelbare bewaartermijn die `cron_opschonen.php` dagelijks afdwingt.
- **`.env` bevat de geheimen** (databasewachtwoord, `APP_KEY`, `OTP_PEPPER`) en staat niet in
  git. Controleer na installatie dat het bestand niet via de browser te downloaden is, en
  verwijder `setup.php`.

Een beveiligingsprobleem gevonden? Meld het bij de beheerder van de vereniging in plaats van
het publiek te maken.
