# Changelog

Alle noemenswaardige wijzigingen aan het DJM Portaal.

## Onuitgebracht

### Portaal

- **Het overzicht na het inloggen is schermvullend.** In plaats van een losse kaart op een
  donkere achtergrond staat er nu een navigatiebalk in de merkkleur met logo, het
  e-mailadres van de deelnemer en de uitlogknop, met daaronder een kop en de jaargangen als
  kaarten in een raster (nieuwste eerst, zoveel naast elkaar als er passen). Elke kaart
  toont het aantal video's en de totale grootte. Op een telefoon valt het e-mailadres uit de
  balk en staan de kaarten onder elkaar, met de downloadknop over de volle breedte. De inlogstappen houden de compacte kaart.
  Nog steeds zonder JavaScript.

## 1.1.0 — 12 september 2026

### Plesk en andere hostingpanelen

- **De zelftest van de uitlevering probeert nu ook per videoformaat of de opslagmap
  rechtstreeks bereikbaar is.** Hij gebruikte alleen een `.bin`-bestand, en dat is precies de
  extensie die een webserver met "statische bestanden zelf afhandelen" met rust laat. Op
  Plesk — waar nginx vóór Apache staat en die optie standaard aan staat mét `mp4` en `zip` in
  de lijst — meldde de test daardoor "niet rechtstreeks bereikbaar" terwijl de video's
  publiek stonden. De test legt nu per formaat een proefbestand klaar, en de foutmelding
  noemt de oorzaak en de oplossing.
- **`setup.php` waarschuwt scherper** als de opslagmap binnen de webroot valt: `.htaccess` is
  daar niet altijd genoeg, en de melding legt uit waarom en noemt een Plesk-pad.
- **De waarschuwing over een niet-ingestelde proxy slaat alleen nog aan als het adres dat het
  portaal ziet zelf intern is.** Panelen die `REMOTE_ADDR` al corrigeren (mod_remoteip)
  leverden anders een verwarrende melding op. De melding noemt nu ook de Plesk-waarde
  `TRUSTED_PROXIES=127.0.0.1,::1`.
- **Nieuw hoofdstuk 7b in `docs/INSTALLATIE.md`**: opslagmap naast `httpdocs`, de
  nginx-richtlijnen als hij er tóch in moet, `DELIVERY_MODE` per PHP-handler,
  `proxy_buffering off` voor grote downloads, X-Accel via de paneelinstellingen, het
  bezoekersadres, PHP-instellingen en de geplande taak.

### Beveiliging

- **De testscripts weigeren nu een webverzoek** (`PHP_SAPI !== 'cli'` → 404), en `test/` wordt
  afgeschermd door de root-`.htaccess`, een eigen `test/.htaccess` en beide
  voorbeeldconfiguraties. Panelen uploaden nu eenmaal hele mappen, en `test/smoke.php` leegt
  de deelnemers- en jaargangentabel. De statische controle bewaakt dat elk testscript die
  controle houdt. De installatiehandleiding zegt nu expliciet `test/` niet mee te uploaden.
- **Content-Security-Policy zonder `'unsafe-inline'` voor scripts.** Alle JavaScript
  staat nu in `admin/assets/admin.js` in plaats van in `onclick=`-attributen en
  scriptblokken in de pagina. Zou er ooit tekst van een bezoeker ongeëscaped op een
  pagina belanden, dan voert de browser het daarin gesmokkelde script niet uit.
  Ook `object-src 'none'` toegevoegd.
- **Pagina's worden niet meer gecachet** (`Cache-Control: no-store, private`). Op een
  geleende of gedeelde computer zijn e-mailadressen en logboeken na het uitloggen niet
  meer uit de browsercache terug te halen.
- **Het onthoud-dit-apparaat-cookie krijgt nu ook achter een reverse proxy de
  `secure`-vlag.** Achter een TLS-afsluitende proxy (nginx, HAProxy, Cloudflare) staat
  `$_SERVER['HTTPS']` niet; het cookie — dertig dagen geldig — kon daardoor over gewoon
  http meereizen. De HTTPS-detectie staat nu op één plek, `https_actief()`, en wordt
  door de sessiecookie, het onthoud-cookie, HSTS en `app_base_url()` gedeeld.
- **Uitloggen vraagt om POST met een CSRF-token.** Een andere website kan een bezoeker
  of beheerder niet langer met een enkel plaatje uitloggen.
- **De Host-header wordt gecontroleerd.** Zonder `APP_URL` in `.env` kwam die header
  ongefilterd in elke link terecht die het portaal maakt, ook in de uitnodigingsmail.
- **De SMTP-mailer weigert regeleindes in adressen en namen.** Laatste zeef tegen
  header-injectie, mocht een controle elders ooit worden overgeslagen.
- **De CSV-export van de toegangslijst maakt formules onschadelijk.** Een naam die met
  `=`, `+`, `-` of `@` begint werd door Excel en LibreOffice als formule uitgevoerd
  zodra de beheerder het bestand opende.
- **Het beheerdersinloggen heeft nu ook een rem per account,** naast die per IP-adres,
  en een mislukte poging op een onbekend adres duurt even lang als op een bestaand adres.
- **Reverse proxy's worden nu expliciet vertrouwd in plaats van impliciet genegeerd.**
  Nieuw: `TRUSTED_PROXIES` in `.env` (IP-adres of CIDR-bereik, komma's ertussen). Staat er een
  proxy in die lijst, dan leest het portaal het echte bezoekersadres uit `X-Forwarded-For`,
  van rechts naar links en met de eigen proxy's overgeslagen; `X-Forwarded-Proto` telt dan ook
  alleen nog van die proxy's. Staat er niets, dan blijft alleen `REMOTE_ADDR` gelden — een
  verzonnen `X-Forwarded-For` verandert dus niets, en is dus ook geen manier om de limiet op
  het aanvragen van inlogcodes te omzeilen. Zonder deze instelling zag het portaal achter een
  proxy van álle bezoekers hetzelfde adres, waardoor de limiet van tien codes per uur de hele
  vereniging tegelijk buitensloot. Het beheeroverzicht waarschuwt nu als er doorstuurheaders
  binnenkomen terwijl de lijst leeg is, en `setup.php` vraagt het veld uit en controleert het.
- **`opslag/.htaccess` en `logs/.htaccess` zitten weer in git.** Ze stonden in de
  documentatie beschreven, maar `.gitignore` hield ze buiten de repository: na een verse
  checkout stonden ze niet op de server. Ook `includes/` en `docs/` hebben er nu een.
- **Volgorde in `docs/nginx.voorbeeld.conf` vastgelegd.** nginx gebruikt de eerste
  regex-`location` die past; stond het PHP-blok boven de deny-blokken, dan voerde
  PHP-FPM `/includes/auth.php` alsnog uit. Dat was in `test/nginx.test.conf` ook echt
  het geval en is daar gecorrigeerd.
- **`docs/apache.voorbeeld.conf` levert SVG's nu ook met een strikte policy uit,**
  net als de `.htaccess` en de nginx-configuratie al deden.

### E-mail

- **De Graph-diagnose meldt niet langer ten onrechte een mailboxprobleem.** De check doet
  `GET /users/{adres}`, en dat is een directory-aanroep: die vereist leesrechten die de app
  bewust niet heeft — de handleiding schrijft `Mail.Send` en verder niets voor. Het gevolg was
  een rode **HTTP 403** met de hint "mogelijk policy/rechtenprobleem" bij een installatie die
  helemaal in orde was, en die hint wees naar de Application Access Policy terwijl die hier
  niets mee te maken heeft: een policy geldt voor postbussen, niet voor het opzoeken van
  gebruikers. De diagnose leest nu de foutcode uit het antwoord en onderscheidt
  `Authorization_RequestDenied` (geen directory-rechten, geen fout) van een echte weigering. In
  het eerste geval staat er **niet te controleren** met de uitleg dat de testmail de controle is
  die telt. Ook de 403-hints bij het werkelijk verzenden noemen de policy niet meer als het om
  een directory-weigering gaat.

### Installatie
- **`setup.php` vult `.env` nu zelf in.** Tot nu toe controleerde de wizard alleen óf het
  bestand er was; wie installeerde moest er met SSH of FTP bij om de databasegegevens en
  de sleutels in te vullen — precies de stap waar een niet-technische beheerder op
  vastloopt. Er is een stap bij gekomen die het webadres, de databasegegevens, de
  opslagmap en de manier van uitleveren uitvraagt en het bestand wegschrijft. De wizard
  telt daarmee vijf stappen: controle, instellen, database, beheerder, klaar.
  - Elk veld wordt gecontroleerd; zit er een fout in, dan wordt er niets weggeschreven.
  - `APP_KEY` en `OTP_PEPPER` worden gegenereerd. Bestaan ze al, dan blijven ze staan,
    tenzij er uitdrukkelijk om nieuwe wordt gevraagd — met de waarschuwing erbij wat
    daardoor ongeldig wordt.
  - De opslagmap wordt zo nodig aangemaakt en met een echte schrijfpoging getest;
    `is_writable()` zegt onder SELinux of een ACL nog wel eens ten onrechte ja.
  - De databaseverbinding wordt getest. Kloppen de inloggegevens maar bestaat de database
    nog niet, dan biedt de wizard aan hem aan te maken.
  - Het bestand wordt weggeschreven via een tijdelijk bestand met rechten `600` en een
    `rename()`, zodat een half geschreven `.env` niet kan bestaan, en daarna teruggelezen
    ter controle. Van de vorige versie komt eerst een reservekopie.
  - Bestaande wachtwoorden en sleutels worden nooit teruggetoond in het formulier: leeg
    laten betekent ongewijzigd.
  - Kan de webserver niet in de projectmap schrijven, dan toont de wizard de volledige
    inhoud om zelf te plaatsen, inclusief de juiste `chown`- en `chmod`-opdracht met de
    gebruiker waaronder PHP daadwerkelijk draait.
- **De wizard kan zelf nakijken of `.env` van buitenaf te downloaden is.** De slotpagina
  haalt `https://…/.env` op en zegt wat eruit kwam. Dat is de controle die het makkelijkst
  wordt overgeslagen en het meeste kost als hij misgaat.
- **De slotpagina met de nazorglijst was onbereikbaar.** Het slot op `setup.php` valt op
  het moment dat de eerste beheerder wordt aangemaakt — dus precies vóórdat de lijst
  ("verwijder `setup.php`", "scherm `.env` af", "zet de cron klaar") in beeld kwam. Wie de
  beheerder zojuist in dezelfde sessie heeft aangemaakt, ziet hem nu wel.
- **`.env` mag nu waarden met leestekens bevatten.** De inlezer haalde alleen
  aanhalingstekens weg; een databasewachtwoord met een `"` erin kwam verminkt aan. Binnen
  dubbele aanhalingstekens gelden nu `\"`, `\\`, `\n`, `\r` en `\t`, en er kan ook
  `export ` voor een regel staan. Bestaande `.env`-bestanden blijven werken.
- **Een echte omgevingsvariabele wint van `.env`,** ook als `variables_order` in `php.ini`
  `$_ENV` niet vult. Voorheen kon een `.env` de instellingen van Docker of systemd
  overrulen.
- **`DB_PORT` en `DB_SOCKET` toegevoegd.** Een database op een andere poort of achter een
  Unix-socket was niet in te stellen; wie `localhost:3307` in `DB_HOST` zette, kreeg een
  onbegrijpelijke fout. Host en poort in één veld worden nu ook gewoon gesplitst.
- **Databaseverbindingen hebben een tijdslimiet** (tien seconden, vijf tijdens de
  installatie). Een verkeerd serveradres liet elke pagina anders hangen tot het
  besturingssysteem het opgaf.

### Opmaak
- **Bootstrap en Bootstrap Icons worden meegeleverd** in `assets/vendor/` in plaats van
  geladen vanaf het jsDelivr-CDN. Het portaal heeft daardoor geen uitgaande
  internetverbinding meer nodig om er goed uit te zien, en de
  Content-Security-Policy laat geen enkele externe bron meer toe (`style-src`,
  `font-src` en `script-src` staan nu op `'self'`). Zie `assets/vendor/HERKOMST.md`
  voor de herkomst, de controlegetallen en hoe je bijwerkt.
- **De navigatiebalk van het beheer paste niet tussen 992 en 1200 pixels breed:** de
  hele pagina schoof daar 134 pixels horizontaal weg en "Portaal" en de
  beheerdersnaam vielen buiten beeld. De balk klapt nu pas uit vanaf 1200 pixels, het
  label blijft naast zijn icoon staan (dat scheelt ook 26 pixels hoogte op elk scherm)
  en een lange portaalnaam of beheerdersnaam wordt afgekapt in plaats van dat hij de
  balk oprekt.
- **Een lichte merkkleur levert geen onleesbare tekst meer op.** Tot nu toe stond er
  altijd witte tekst op de merkkleur; bij geel of lichtgroen was dat niet te lezen.
  De tekstkleur wordt nu per kleur uitgerekend (WCAG-luminantie) en geldt voor de
  kopbalk van het portaal, de knoppen, de navigatiebalk van het beheer én de mails.
- **Het favicon volgt de merkkleur** in plaats van altijd het standaardblauw te tonen.
- Alle opmaak staat nu in één `assets/djm.css` in plaats van in vier bijna identieke
  `<style>`-blokken, en het `<head>` van portaal, beheer en installatie komt uit
  `includes/opmaak.php`. Achter elke stijl- en script-URL staat `?v=<VERSION>`, zodat
  browsers na een update vanzelf de nieuwe versie ophalen.
- Brede tabellen in het beheer laten op telefoon en tablet een schuifbalk zien, zodat
  zichtbaar is dat er nog kolommen naast staan.
- **Instellingen is bruikbaar geworden op zijn eigen lengte.** De pagina is bijna
  4000 pixels lang; de opslaanknop stond halverwege, dus na een wijziging onderin moest
  je terugscrollen. Die knop is nu een balk die in beeld blijft zolang het formulier in
  beeld is, met erbij wat hij wel en niet bewaart. Bovenaan staat een sprongnavigatie
  naar de zeven onderdelen. Allebei zonder JavaScript — gewone ankerlinks en
  `position: sticky` — zodat er niets kan haperen.
- **De knoppenbalk bij de toegangslijst liep op een telefoon uit zijn kader:** de
  keuzelijst was afgekapt tot "wie de video nc" en de tekst van "Herinnering versturen"
  stak buiten de knop uit. Onder 576 pixels staan de keuzelijst en beide knoppen nu
  onder elkaar over de volle breedte.
- Bij stap 1 van Toegang stond naast het plakvak een halflege kolom. Het CSV-blok heeft
  nu een eigen kader dat even hoog is als het plakvak ernaast, en de verdeling is 7/5
  in plaats van 8/4.
- De downloadknop in het portaal staat op een telefoon over de volle breedte.
- `setup.php` gebruikt hetzelfde `<head>` en dezelfde stijl als de rest.

### Testen
- De statische controle weigert externe bronnen in de opmaak en controleert dat elk
  stijl-, script- en lettertypebestand waarnaar verwezen wordt ook echt bestaat — een
  ontbrekend bestand zou anders pas na het uitrollen opvallen.
- `test/schermafdrukken.js` meldt een verzoek dat werd afgebroken doordat de browser
  al doorklikte niet langer als fout.
- Nieuw: `test/beveiliging.sh` en `test/beveiliging.php` meten de securityheaders, de
  afgeschermde paden op alle drie de webservers, het uitloggedrag en de
  beveiligingshelpers (mailheaders, bestandspaden, downloadhandtekeningen,
  Host-header, CSV-export, CIDR-bereiken). Ze draaien mee in `test/alles.sh`.
- Nieuw: `test/proxy_test.php` draait de hele proxymatrix door (eigen proces, want de
  proxylijst wordt per proces één keer ingelezen), en `test/beveiliging.sh` controleert
  end-to-end dat een verzonnen `X-Forwarded-For` niet in het logboek belandt.
- De statische controle kijkt nu naar ieder bestand dat `$_POST` of `$_FILES` leest in
  plaats van naar ieder bestand met een formulier erin, zodat een formulier en de
  verwerking ervan in verschillende bestanden mogen staan.
- `test/installatie.sh` draait tegen een nieuwe container `vers`, die met opzet géén
  omgevingsvariabelen meekrijgt en de projectmap alleen-lezen in handen heeft: de test
  draait op een kopie binnen de container, zodat het `.env` dat de wizard schrijft nooit
  in de werkmap van de ontwikkelaar belandt. De wizard moet `.env` daar dus echt zelf schrijven, net
  als bij een klant op een lege server. De test controleert onder meer dat een
  databasewachtwoord met een `#` en een `"` erin de rit overleeft, dat een leeg
  wachtwoordveld het bestaande wachtwoord laat staan, dat er een reservekopie met rechten
  `600` komt, dat de sleutels niet ongevraagd worden vervangen, dat het wachtwoord niet in
  het formulier wordt teruggetoond en dat `.env` na installatie niet meer te overschrijven
  is.

## 1.0.0 — 9 september 2026

Eerste versie.

### Inloggen
- Inloggen met e-mailadres en een eenmalige code van zes cijfers, verstuurd per e-mail.
- Codes worden nooit als platte tekst opgeslagen, maar als HMAC-SHA256 met een pepper uit `.env`.
- Standaard tien minuten geldig, maximaal vijf pogingen, eenmalig bruikbaar; een nieuwe code trekt de vorige in.
- Een code werkt alleen in de browser waarin hij is aangevraagd (uitschakelbaar).
- Geen user enumeration: bekende en onbekende adressen krijgen dezelfde melding én dezelfde responstijd.
- Throttling per e-mailadres (3 per 15 minuten) en per IP-adres (10 per uur); het invoeren van codes is daarnaast geremd op twintig pogingen per kwartier per IP-adres.
- Optioneel "onthoud dit apparaat" met een apart, gehasht token.

### Video's en jaargangen
- Jaargangen met titel, omschrijving, publicatiestatus en optioneel een zichtbaar-vanaf en verloopdatum.
- Meerdere bestanden per jaargang mogelijk.
- Toegang per jaargang: een deelnemer ziet alleen de jaren waarvoor hij of zij is toegevoegd.
- Downloads via ondertekende, vijf minuten geldige links; de sessie blijft de leidende controle.
- Uitlevering past zich aan de server aan: `X-Accel-Redirect` (nginx), `X-Sendfile` (Apache) of PHP-streaming met ondersteuning voor hervatten (HTTP Range).
- Videobestanden staan buiten de webroot en zijn nooit rechtstreeks benaderbaar.

### Beheer
- Beheerdersgedeelte met eigen login, brute-force rem en logboek.
- Jaargangen aanmaken, publiceren en verwijderen.
- Video's koppelen door ze uit de opslagmap te kiezen (aanbevolen voor grote bestanden via SFTP) of te uploaden.
- E-mailadressen importeren door plakken of via CSV, met een verplichte voorbeeldstap en optioneel meteen een uitnodigingsmail.
- Deelnemersbeheer met blokkeren en verwijderen (AVG).
- Bestandscontrole: laat per gekoppeld bestand zien of het nog op schijf staat en of de grootte klopt, met nadruk op bestanden die nú zichtbaar zijn voor ouders, plus een overzicht van video's in de opslagmap die aan geen enkele jaargang hangen.
- Ophaalstatus per ouder: opgehaald, wel ingelogd maar niet gedownload, of nog nooit ingelogd — met filter, percentages en een herinneringsmail naar de groep die de video nog niet heeft.
- Logboek voor inlogpogingen, verstuurde e-mails en downloads, met een opruimfunctie.
- Instellingen voor branding, mailsjablonen, inloggedrag en de Microsoft Graph-koppeling, inclusief testmail en een Graph-diagnose.
- Logo uploaden vanaf de eigen computer (PNG, JPEG, WEBP of SVG; SVG's worden opgeschoond en met een strikte policy uitgeleverd), en een contactadres dat op de inlogpagina en bij een leeg overzicht verschijnt.
- Het serverconfiguratieblok dat bij de gedetecteerde uitleveringsmethode hoort, met de paden van de installatie al ingevuld en een kopieerknop — voor alle drie de methoden, niet alleen de actieve.
- Zelftest van de uitlevering: zet kort een testbestand in de opslagmap en haalt het via dezelfde route op als een echte video — volledig, hervat en met een onmogelijk bereik — en controleert dat het bestand niet rechtstreeks te downloaden is. Dat laatste vangt een ontbrekend `internal` in nginx, wat anders onzichtbaar blijft omdat de download via het portaal gewoon lijkt te werken.

### E-mail
- Verzending via de Microsoft Graph API (app-only, alleen `Mail.Send`), met SMTP als terugvaloptie.
- De mailerklassen zijn overgenomen uit het Klantenportaal en werken zonder externe libraries.
- `docs/GRAPH-SETUP.md` beschrijft hoe je de app met een Application Access Policy tot één postbus beperkt.

### Installatie en documentatie
- Installatiewizard (`setup.php`) die zichzelf afsluit zodra er een beheerder bestaat.
- Voorbeeldconfiguraties voor nginx en Apache.
- Handleidingen voor installatie, de Graph-koppeling en het jaarlijks toevoegen van een nieuw jaar.
- Opschoonscript voor de cron.

- Waarschuwing in het beheeroverzicht als `APP_URL` niet overeenkomt met het adres waarop het portaal draait. Dat is een stille storing: browsers weigeren dan formulieren te versturen en niemand kan inloggen.

### Tests
- Testomgeving met Docker (MariaDB, Mailpit, PHP) en een testset van bijna 190 controles: statische analyse, een verse installatie, kernlogica, inlogcodes, de publieke flow, elke beheerpagina en de volledige jaarlijkse workflow.
- Een test in een echte browser die portaal en beheer doorloopt, schermafdrukken maakt en faalt op console- en netwerkfouten. Die vond een Content-Security-Policy-probleem dat met alleen `curl` onzichtbaar was.
- Alle drie de uitleveringsroutes zijn gedraaid op de webservers waar ze voor bedoeld zijn: PHP-streaming, nginx met `X-Accel-Redirect` en Apache met `mod_xsendfile`. Daaruit kwam dat `mod_xsendfile` Range-verzoeken wel honoreert maar niet aankondigt; het portaal zet die header nu zelf.
- Een test met een video van 5 GB: groottes en offsets voorbij de 2 GB-grens kloppen op alle drie de routes, en PHP blijft daarbij onder de 3 MB geheugen.
- Een controle op telefoonformaat dat geen enkele pagina horizontaal scrolt.
- De zelftest uit het beheer draait mee op alle drie de routes. Een verkeerde `alias` in het nginx-blok komt eruit als HTTP 404, een weggelaten `internal` als een bestand dat zonder inloggen op te halen is.
- De foutafhandeling van de Graph-mailer is getest tegen het echte aanmeldpunt van Microsoft: verkeerde gegevens leveren een begrijpelijke melding op en worden in het mailboek vastgelegd.
