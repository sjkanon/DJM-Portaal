# Openstaand werk

Wat er nog ligt, met genoeg context om het koud op te pakken. Afgeronde punten weghalen, niet
afvinken — de geschiedenis staat in `CHANGELOG.md`.

Laatst bijgewerkt: 14 september 2026.

---

## 1. Volledige testset draaien over de huidige code

**Waarom het er nog niet is:** Docker stond uit toen de laatste wijzigingen af waren. De losse
onderdelen zijn wel gedraaid (kernlogica 32/32, publieke flow 38/38, beheer 78/78, uitlevering
35/35 op alle drie de routes, syntaxcontrole 46 bestanden), maar niet in één run over de
definitieve code.

```bash
bash test/alles.sh
```

Twee dingen om vooraf te weten:

- De testharnas is sinds 14 september Windows-proof (`test/hostpad.sh`). Draait het toch mis met
  `Could not open input file`, dan wordt `hostpad()` ergens niet gebruikt bij een `docker run -v`.
- **`python3` moet écht Python zijn.** Op Windows staat in `WindowsApps` een `python3` die alleen
  "Python was not found" afdrukt en verder niets doet. `command -v python3` vindt hem, dus de
  scripts denken dat het goed zit. Gevolg: `e2e.sh` en `beheerders_test.sh` kunnen de inlogcode niet
  uit Mailpit halen en alles daarna valt om, en `audit.sh` slaat de controle op named parameters
  over. Controleer vóór een volle run `python3 -c "print(1)"`; staat de Store-stub in de weg, zet
  hem dan uit bij Instellingen › Apps › Geavanceerde app-instellingen › App-uitvoeringsaliassen.
- Draai de onderdelen ook eens los (`bash test/admin_test.sh`). Een sectie die in `alles.sh` omvalt
  maar los slaagt, is vrijwel altijd de volgorde of de omgeving, niet de code.
- Loopt er een eerdere run vast, ruim dan op met `docker compose down -v` in `test/`. Een
  afgebroken `installatie.sh` laat een gedropte database en een half gevulde `vers`-container
  achter, en dan faalt alles erna op iets dat niets met de code te maken heeft.

## 2. Migratietest toevoegen aan de testset

**Dit heeft al een keer productie omgelegd.** `admin/logboek.php?tab=downloads` gaf een 500 op elke
installatie waar `download_log` de kolom `reden` nog niet had. De testset dékt dat scherm, maar
draaide tegen een database die net uit `db.sql` was opgebouwd — mét de nieuwe kolom. Een bestaande
installatie heeft die juist niet.

Toe te voegen aan `test/admin_test.sh` (of een eigen `test/migratie.sh`):

1. Laat de kolom weg: `ALTER TABLE download_log DROP COLUMN reden`.
2. Open elk beheerscherm en controleer op PHP-fouten — `admin_test.sh` heeft daar al `schoon()` voor.
3. Controleer dat de kolom daarna bestaat, want de pagina hoort hem zelf aan te maken.

Algemener: elke wijziging die `db.sql` raakt, hoort getest te worden tegen een database van vóór die
wijziging. Een verse installatie bewijst niets over de installaties die er al zijn.

## 3. Schermafdrukken van de handleiding opnieuw

Het logboekscherm heeft een kolom **Uitkomst** erbij gekregen en een uitgebreider blok bovenaan; de
afbeelding `assets/handleiding/beheer-logboek-downloads.webp` klopt niet meer.

```bash
bash test/handleiding.sh      # wist de testdatabase
```

Daarna `assets/handleiding/` meecommitten. `bash test/alles.sh` zet de gewone testgegevens terug.

## 4. Ook bij xaccel meten: het nginx-log uitlezen

Productie staat op `DELIVERY_MODE="xaccel"`. Dat is snel en bewezen robuust — de eerste volledige
7,0 GB-download liep er in zeven minuten doorheen — maar er komt geen byte langs PHP, dus staat elke
regel in het logboek op *niet gemeten*. Meten kan wél met `DELIVERY_MODE="php"` (sinds de bufferfix
ook veilig), maar dan doet PHP weer het zware werk.

**Het hoeft geen keuze te zijn.** nginx logt namelijk exact wat hij naar de bezoeker stuurde:

```
91.183.202.84 - - [14/Sep/2026:11:46:14 +0200]
  "GET /download.php?b=1&t=1789422010&s=7dbd6d39…a057b3a HTTP/2.0" 200 6998877816
```

Die `s=` is per downloadlink uniek (HMAC over bestand, deelnemer en vervaltijd), dus dat is een
exacte koppeling tussen een logregel en een regel in `download_log` — geen giswerk op tijdstip en
IP. Nagekeken op 14 september: `~/logs/dvd.deventerjeugdmusical.nl/proxy_access_ssl_log` is voor de
PHP-gebruiker leesbaar (map leesbaar, `fopen` lukt).

### Aanpak

1. Kolom `download_log.sleutel CHAR(16)` — de eerste 16 tekens van de handtekening, gevuld door
   `download_loggen()`. Genoeg om uniek te zijn, en geen bruikbaar geheim.
2. Een helper die het log doorloopt, per regel `s=<sleutel>` zoekt en status + bytes teruggeeft.
   Regex op het stuk na de afsluitende aanhalingstekens; standaard combined-format.
3. Uitkomst wegschrijven in `download_log`, zodat het eenmalig werk is en rotatie daarna niet meer
   uitmaakt: bytes gelijk aan de bestandsgrootte → `voltooid`, minder → `client_gestopt` (het label
   *verbinding verbroken* klopt hier ook: nginx weet net zomin wie hem verbrak).
4. Aanroepen bij het openen van het downloadtabblad, voor de regels die op het scherm staan en nog
   op `webserver` staan. Eventueel later ook in `cron_opschonen.php`, zodat regels verrijkt worden
   vóór de rotatie.
5. Pad instelbaar via `WEBSERVER_LOG` in `.env`, leeg = uit, met als terugval het Plesk-pad
   `$HOME/logs/<host>/proxy_access_ssl_log`. Niet ingesteld of onleesbaar → gedraag je als nu.

### Waar het misgaat als je niet oplet

- **Hosting-specifiek.** Een andere host logt op een ander pad, in een ander formaat, of logt
  proxyverzoeken helemaal niet. Het moet dus netjes terugvallen op *niet gemeten* en nooit een
  fout opleveren.
- **Apache's log is de verkeerde.** `access_ssl_log` toont bij xaccel alleen het antwoord van PHP
  aan nginx (± 5,8 kB). Alleen `proxy_access_ssl_log` heeft de echte bytes.
- **Rotatie.** Rond 03:42 gaat het log naar `.processed` en uiteindelijk naar `.gz`. Verrijk je pas
  later, lees dan ook `.processed`.
- **Nog niet afgeronde downloads** staan nog niet in het log: nginx schrijft de regel pas als de
  reactie klaar is. Een regel die nog op `webserver` staat kan dus gewoon nog lopen.
- **Migratietest meenemen** (zie punt 2). Deze wijziging raakt `db.sql` opnieuw.

Zolang dit er niet is, blijft het advies: xaccel laten staan en `DELIVERY_MODE` tijdelijk op `php`
zetten als er iets te onderzoeken valt.

## 5. Hardening: opslagmap buiten de webroot

`OPSLAG_PAD` is leeg, dus de video's staan in de documentroot
(`~/dvd.deventerjeugdmusical.nl/opslag/`). Ze zijn afgeschermd — rechtstreeks opvragen geeft 403,
ook per videoformaat getest — maar dat leunt op `.htaccess` en op de Plesk-instelling *Serve static
files directly by nginx* die uit moet blijven. Eén vinkje verkeerd en de hele videobibliotheek staat
publiek.

Robuuster: de map naast `httpdocs` zetten en `OPSLAG_PAD` invullen. Let op dat het `alias`-pad in de
nginx-richtlijn (zie hieronder) dan mee moet veranderen.

---

## Serveromgeving (Plesk) — nodig om bovenstaande te kunnen doen

- **Uitrollen gaat vanzelf.** Een push naar `devel` wordt door de Plesk Git-deployment binnen enkele
  minuten naar productie gezet. Er zit geen acceptatiestap tussen: wat je pusht, staat live.
- **X-Accel-richtlijn** staat bij *Apache & nginx Settings → Additional nginx directives*:

  ```nginx
  location ^~ /beveiligd/ {
      internal;
      alias /var/www/vhosts/deventerjeugdmusical.nl/dvd.deventerjeugdmusical.nl/opslag/;
  }
  ```

  `^~` is er zodat dit blok wint van regex-locations die Plesk zelf toevoegt; `internal` zorgt dat
  het pad alleen via `X-Accel-Redirect` bereikbaar is.
- **`mod_xsendfile` bestaat niet op deze server** (110 Apache-modules, geen enkele met `xsend`).
  X-Sendfile is dus geen optie zonder dat de hoster de module installeert; dat vraagt root.
- **Niet aanzetten:** *Serve static files directly by nginx*. Dan serveert nginx `.mp4` rechtstreeks
  uit de documentroot en negeert hij de `.htaccess` die `opslag/` dichthoudt.
- **Reservekopie van `.env`:** `.env.backup-20260914-110453` staat op de server, van vóór de
  omschakeling naar xaccel. Terugdraaien is één regel:
  `sed -i 's|^DELIVERY_MODE=.*|DELIVERY_MODE="auto"|' ~/dvd.deventerjeugdmusical.nl/.env`
- **PHP op de server** staat niet in `$PATH`: `/opt/plesk/php/8.2/bin/php`.
- **Webserverlogs:** `~/logs/dvd.deventerjeugdmusical.nl/`. `access_ssl_log` is die van Apache,
  `proxy_access_ssl_log` die van nginx — en alleen die laatste toont bij xaccel het werkelijke
  aantal verzonden bytes. Beide roteren dagelijks.
