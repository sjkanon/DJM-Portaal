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

## 4. Besluit: meetbaar of ongemeten uitleveren

Productie staat op `DELIVERY_MODE="xaccel"`. Dat is snel en bewezen robuust — de eerste volledige
7,0 GB-download liep er in zeven minuten doorheen — maar er komt geen byte langs PHP, dus het
portaal kan niet zien hoe ver iemand kwam. Alle regels in het downloadlogboek staan dan op
*niet gemeten*.

Meten kan alleen met `DELIVERY_MODE="php"`. Dat is sinds de bufferfix ook veilig: de uitlevering
stuurt `X-Accel-Buffering: no` mee, waardoor de nginx-bufferlimiet van 1 GB niet meer toeslaat. In
drie dagen PHP-uitlevering met multi-GB-downloads stond er geen enkele 5xx in het webserverlog, dus
van uitputting van PHP-werkers was geen sprake.

Huidig advies: **xaccel laten staan, en tijdelijk op `php` zetten als er iets te onderzoeken valt.**
Dat staat ook zo in de handleiding. Openstaand is of dat op den duur bevalt, of dat de meting zo
waardevol is dat `php` de standaard moet worden.

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
