# Changelog

Alle noemenswaardige wijzigingen aan het DJM Portaal.

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
