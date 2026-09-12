# Changelog

Alle noemenswaardige wijzigingen aan het DJM Portaal.

## Nog niet uitgebracht

### Beveiliging
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
- **`opslag/.htaccess` en `logs/.htaccess` zitten weer in git.** Ze stonden in de
  documentatie beschreven, maar `.gitignore` hield ze buiten de repository: na een verse
  checkout stonden ze niet op de server. Ook `includes/` en `docs/` hebben er nu een.
- **Volgorde in `docs/nginx.voorbeeld.conf` vastgelegd.** nginx gebruikt de eerste
  regex-`location` die past; stond het PHP-blok boven de deny-blokken, dan voerde
  PHP-FPM `/includes/auth.php` alsnog uit. Dat was in `test/nginx.test.conf` ook echt
  het geval en is daar gecorrigeerd.
- **`docs/apache.voorbeeld.conf` levert SVG's nu ook met een strikte policy uit,**
  net als de `.htaccess` en de nginx-configuratie al deden.

### Testen
- Nieuw: `test/beveiliging.sh` en `test/beveiliging.php` meten de securityheaders, de
  afgeschermde paden op alle drie de webservers, het uitloggedrag en de
  beveiligingshelpers (mailheaders, bestandspaden, downloadhandtekeningen,
  Host-header, CSV-export). Ze draaien mee in `test/alles.sh`.
- De statische controle kijkt nu naar ieder bestand dat `$_POST` of `$_FILES` leest in
  plaats van naar ieder bestand met een formulier erin, zodat een formulier en de
  verwerking ervan in verschillende bestanden mogen staan.

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
