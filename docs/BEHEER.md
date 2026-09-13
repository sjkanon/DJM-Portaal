# Beheerhandleiding — DJM Portaal

Dit document is bedoeld voor wie het portaal van iemand overneemt, of het beheer met iemand
deelt. Het beschrijft hoe het platform in elkaar zit, wat u in het beheerdersgedeelte kunt
doen, welke taken er terugkomen en wat u doet als er iets misgaat.

Er zijn twee soorten beheer, en het mag gerust om twee verschillende mensen gaan:

| Rol | Doet | Nodig |
|---|---|---|
| **Beheerder van de vereniging** | Jaargangen, video's, e-maillijsten, vragen van deelnemers | Beheeraccount in het portaal, SFTP-toegang tot de opslagmap |
| **Technisch beheerder** | Server, back-ups, updates, Microsoft 365-koppeling, storingen | Toegang tot de server of het hostingpaneel, de database, `.env`, de Microsoft Entra-omgeving |

Wie alleen het jaarlijkse werk doet, heeft genoeg aan hoofdstuk 1 tot en met 5 en
[NIEUW-JAAR.md](NIEUW-JAAR.md).

> **In het portaal staat dezelfde handleiding, met schermafdrukken:** log in op het beheer en
> kies **Handleiding** onder uw naam, rechts in de menubalk. Elk beheerscherm heeft ook een knop **Uitleg**.
> Dit bestand is de tekstversie, voor wie (nog) niet kan inloggen.

---

## 1. Hoe het platform werkt

```
 Deelnemer                      Portaal (PHP)                     Microsoft 365
 ─────────                      ─────────────                     ─────────────
 vult e-mailadres in  ───────▶  staat het adres op de lijst
                                van een zichtbare jaargang?
                                  ja ──▶ code van 6 cijfers  ───▶ Graph API verstuurt mail
 ontvangt mail, typt code ───▶  code klopt? ──▶ ingelogd
 klikt op downloadknop    ───▶  download.php controleert
                                sessie + toegang  ──▶  video uit de opslagmap
```

- **Deelnemers hebben geen wachtwoord en geen account.** Hun e-mailadres is hun identiteit. Wie
  op de lijst van een jaargang staat, kan inloggen; wie er niet op staat, niet.
- **Een deelnemer ziet alleen de jaargangen waarvoor hij of zij is toegevoegd**, en daarvan
  alleen de jaargangen die op dat moment gepubliceerd en zichtbaar zijn.
- **De video's staan in een afgeschermde opslagmap** en zijn alleen via het portaal op te
  halen, nooit met een directe link.
- **Beheerders loggen apart in** op `/admin/` met e-mailadres en wachtwoord.

### De onderdelen

| Onderdeel | Wat | Wie beheert het |
|---|---|---|
| Webserver + PHP | De applicatie zelf | Technisch beheerder |
| MySQL/MariaDB-database | Jaargangen, deelnemers, toegang, logboeken, instellingen | Technisch beheerder |
| Opslagmap (bijv. `/var/djm-opslag`) | De videobestanden, per jaar een map | Beheerder vereniging (via SFTP) |
| `.env` in de projectmap | Databasewachtwoord en geheime sleutels | Technisch beheerder |
| App-registratie in Microsoft Entra | Mag mail versturen namens de afzenderpostbus | Technisch beheerder |
| Afzenderpostbus in Microsoft 365 | Het adres waar de inlogcodes vandaan komen | Beheerder Microsoft 365 van de vereniging |
| Dagelijkse taak (`cron_opschonen.php`) | Ruimt verlopen codes en oude logregels op | Technisch beheerder |

---

## 2. Overdracht: wat moet de nieuwe beheerder hebben?

Loop deze lijst na bij elke overdracht. Deel wachtwoorden via een wachtwoordmanager, niet per
mail of WhatsApp.

- [ ] **Beheeraccount in het portaal** op eigen naam: een andere beheerder voegt u toe via
      **Beheer → Beheerders** (zie hoofdstuk 4). Deel geen account.
- [ ] **SFTP-gegevens** voor de opslagmap, en het pad van die map.
- [ ] **Adres van het portaal** en van het beheer (`https://…/admin/`).
- [ ] **Contactadres** dat deelnemers in het portaal zien (**Beheer → Instellingen → Portaal**)
      moet uitkomen bij iemand die nog actief is.

Voor de technisch beheerder daarnaast:

- [ ] Toegang tot de server of het hostingpaneel.
- [ ] Databasenaam, gebruiker en wachtwoord.
- [ ] Een kopie van `.env`. Zonder `APP_KEY` en `OTP_PEPPER` werkt een teruggezette installatie
      niet meer met bestaande cookies en inlogcodes.
- [ ] Toegang tot Microsoft Entra (de app-registratie) en de **vervaldatum van het client
      secret**. Zet die in een gedeelde agenda (zie hoofdstuk 5).
- [ ] Waar de back-ups staan en hoe u ze terugzet.

**Vertrekt er iemand?** Schakel diens beheeraccount uit (hoofdstuk 4), trek de SFTP-toegang in,
en vervang het client secret als die persoon het ooit in handen heeft gehad.

---

## 3. Het beheerdersgedeelte, scherm voor scherm

### Overzicht

Kerncijfers (jaargangen, deelnemers, downloads, verstuurde codes), de laatste downloads en
inlogpogingen, en **waarschuwingen**. Een gele of rode balk hier betekent bijna altijd dat
inloggen voor deelnemers niet (goed) werkt. Voorbeelden: e-mail is niet ingesteld, `APP_URL`
klopt niet, of er staat een proxy voor het portaal die niet is ingesteld. Geef zo'n melding
door aan de technisch beheerder.

### Jaargangen

Eén regel per jaar: titel, omschrijving en zichtbaarheid.

- **Gepubliceerd:** alleen gepubliceerde jaargangen zien deelnemers.
- **Zichtbaar vanaf / Verloopt op:** optionele datums. Een jaargang die verlopen is, verdwijnt
  vanzelf uit het portaal.
- **Verwijderen** wist de jaargang, de koppelingen en de toegangsrechten, maar **niet** het
  videobestand op schijf. Wilt u alleen dat niemand er meer bij kan, depubliceer dan liever.

### Bestanden

Koppelt een videobestand uit de opslagmap aan een jaargang. U kiest het bestand uit een lijst,
geeft het een titel (de tekst op de downloadknop) en een downloadnaam. Via de browser uploaden
kan ook, maar alleen voor kleine bestanden. Grote video's zet u via SFTP neer.

### Controle

Vergelijkt elk gekoppeld bestand met wat er echt op schijf staat: *in orde*, *grootte wijkt af*,
*ontbreekt*, *onleesbaar* of *leeg*. Kijk hier na het uploaden van een nieuwe video, en als
een deelnemer meldt dat een download niet werkt. Er staat ook een knop om een controlesom
(SHA-256) te berekenen, zodat u later kunt zien of een bestand ongewijzigd is. Deze pagina
wijzigt nooit iets op schijf.

### Toegang

Het belangrijkste scherm voor het jaarlijkse werk. Per jaargang:

- **E-mailadressen toevoegen:** plakken of een CSV uploaden, dan **Controleren**, dan pas
  bevestigen. Optioneel meteen een uitnodigingsmail.
- **De toegangslijst** met per adres de **ophaalstatus**: *opgehaald*, *ingelogd maar niet
  opgehaald*, of *nooit ingelogd*.
- **Uitnodiging opnieuw versturen** of **toegang intrekken** per adres.
- **Herinnering versturen** naar een hele groep (*wie de video nog niet ophaalde* of *wie nog
  nooit inlogde*). U ziet eerst hoeveel mails er gaan voordat u bevestigt. Wie de afgelopen
  24 uur al gemaild is, of geblokkeerd is, wordt standaard overgeslagen.
- **Exporteren** van de lijst als CSV.

### Deelnemers

Zoeken op e-mailadres, en per deelnemer:

- de naam aanpassen en de toegang per jaargang aan- of uitvinken. Het e-mailadres zelf is niet
  te wijzigen: klopt het niet, verwijder de deelnemer dan en voeg het juiste adres toe via
  **Toegang**;
- de downloads en inlogpogingen van die persoon bekijken;
- **Blokkeren:** de persoon krijgt geen inlogcodes meer. De toegangsrechten blijven bewaard,
  dus deblokkeren zet alles terug;
- **Verwijderen (AVG):** wist de deelnemer, alle toegang, tokens en openstaande codes. Het
  downloadlogboek blijft bewaard als verantwoording. Dit is niet terug te draaien.

### Logboek

Drie tabbladen: **inlogpogingen**, **verstuurde e-mail** (met foutmelding als verzenden
mislukte) en **downloads** (met hoeveel er is verzonden en of de download is afgerond). Filter
op e-mailadres en datum. Logregels ouder dan de bewaartermijn ruimt de dagelijkse taak zelf op.

### Instellingen

| Onderdeel | Wat staat er |
|---|---|
| Portaal | Naam, merkkleur, logo, welkomsttekst, contactadres. **Portaal ingeschakeld** uitzetten sluit de inlogpagina: niemand kan dan een inlogcode aanvragen, bijvoorbeeld tijdens onderhoud. Wie al is ingelogd, wordt niet uitgelogd. |
| E-mail | Afzenderadres en -naam, Microsoft Graph-gegevens, SMTP als terugvaloptie |
| Inloggen | Geldigheid van codes, aantal pogingen, limieten, sessieduur, "onthoud dit apparaat", bewaartermijn logboek |
| Mailsjablonen | Onderwerp en tekst van de inlogcode- en uitnodigingsmail |
| Testen | **Testmail versturen**, **Graph-configuratie controleren**, **Uitlevering uitproberen** |
| Serverconfiguratie / Technische status | Voor de technisch beheerder |

Wijzig de instellingen onder *Inloggen* alleen als u weet waarom. De standaardwaarden zijn
bewust gekozen.

### Beheerders

Wie er in het beheer mag. Voeg iemand toe met naam en e-mailadres: die krijgt een uitnodiging
en kiest daarin zelf een wachtwoord. Per beheerder ziet u wanneer die het laatst inlogde en of
er nog een link openstaat, en kunt u een nieuwe link sturen of het account uitschakelen. Zie
hoofdstuk 4.

---

## 4. Beheerders toevoegen, resetten en uitschakelen

Dit gaat via **Beheer → Beheerders**. Alle beheerders hebben dezelfde rechten.

- **Toevoegen:** vul naam en e-mailadres in. De nieuwe beheerder krijgt een e-mail en kiest
  daarin zelf een wachtwoord van minimaal 12 tekens. De link is 72 uur geldig en werkt één
  keer. Niemand anders ziet of kent het wachtwoord.
- **Wachtwoord vergeten:** klik op de inlogpagina van het beheer op *Wachtwoord vergeten?*.
  De link is 60 minuten geldig. Een andere beheerder kan hem ook versturen met **Resetlink
  sturen**; hij gaat altijd naar het adres van de beheerder zelf. De pagina zegt nooit of een
  adres bekend is, en per uur kunnen er maar een paar links worden aangevraagd. Dit werkt
  alleen als `APP_URL` in `.env` staat; de installatiewizard zet hem er altijd in.
- **Uitschakelen:** werkt direct, ook als die persoon op dat moment is ingelogd, en maakt een
  openstaande link ongeldig. Uw eigen account kunt u niet uitschakelen, zodat er altijd iemand
  overblijft. Verwijderen kan niet: zo blijft het logboek te herleiden.

Elke handeling staat in **Logboek → Inloggen** (`admin_toegevoegd`, `admin_link`, `admin_reset`,
`admin_wachtwoord`, `admin_uitgeschakeld`, `admin_ingeschakeld`), met wie hem deed.

### Als niemand er meer in komt

Werkt de e-mail niet (bijvoorbeeld door een verlopen client secret) en weet geen enkele
beheerder zijn wachtwoord nog, dan kan alleen de technisch beheerder helpen, via de server.

Via de installatiewizard:

1. Staat `setup.php` niet meer op de server, zet hem dan tijdelijk terug.
2. Maak in de projectmap een leeg bestand `setup.toegestaan` aan.
3. Open `https://…/setup.php?stap=4` en vul naam, e-mailadres en een wachtwoord van minimaal
   12 tekens in.
   Gebruikt u het e-mailadres van een **bestaande** beheerder, dan krijgt die het nieuwe
   wachtwoord en wordt het account weer actief. Zo reset u dus ook een wachtwoord.
4. **Verwijder daarna meteen `setup.toegestaan` én `setup.php`.** Zolang dat bestand er staat,
   kan iedereen de wizard openen, ook het scherm waarmee `.env` wordt overschreven.

Of rechtstreeks in de database:

```bash
php -r 'echo password_hash("HIER-HET-WACHTWOORD", PASSWORD_DEFAULT), "\n";'
```

```sql
-- nieuwe beheerder
INSERT INTO beheerders (naam, email, wachtwoord_hash, rol, actief)
VALUES ('Voornaam Achternaam', 'naam@example.nl', '<hash van hierboven>', 'beheerder', 1);

-- wachtwoord resetten
UPDATE beheerders SET wachtwoord_hash = '<hash van hierboven>' WHERE email = 'naam@example.nl';
```

Het e-mailadres moet in kleine letters. Laat het wachtwoord daarna niet in de shellgeschiedenis
staan (`history -d` of de terminal sluiten).

### Uitschakelen in de database

```sql
UPDATE beheerders SET actief = 0 WHERE email = 'naam@example.nl';
```

Dat werkt direct, ook als die persoon op dat moment is ingelogd. Uitschakelen is beter dan
verwijderen, omdat het logboek dan te herleiden blijft.

### Wie is er beheerder?

```sql
SELECT naam, email, actief, laatst_ingelogd_op FROM beheerders;
```

---

## 5. Terugkerende taken

| Wanneer | Wat | Wie | Hoe |
|---|---|---|---|
| Elk jaar, na de voorstelling | Nieuwe jaargang: video uploaden, koppelen, adressen importeren | Vereniging | [NIEUW-JAAR.md](NIEUW-JAAR.md) |
| Een paar weken na de uitnodiging | Herinnering sturen aan wie nog niet heeft opgehaald | Vereniging | **Toegang → Herinnering versturen** |
| Na elke nieuwe video | Controleren of alle bestanden er (heel) staan | Vereniging | **Controle** |
| Maandelijks | Blik op het Overzicht: waarschuwingen? mislukte mails? | Vereniging | **Overzicht**, **Logboek → E-mail** |
| Ruim vóór de vervaldatum | **Client secret vernieuwen**, anders stopt het inloggen | Technisch | [GRAPH-SETUP.md](GRAPH-SETUP.md), hoofdstuk 2 |
| Wekelijks (automatisch) | Back-up van database en opslagmap | Technisch | Zie hoofdstuk 7 |
| Een keer per kwartaal | Controleren of de dagelijkse opschoontaak nog draait | Technisch | `logs/cron.log` |
| Bij een wisseling van beheerder | Accounts overdragen en oude intrekken | Beide | Hoofdstuk 2 en 4 |
| Als afgesproken termijn om is | Oude jaargang laten verlopen of depubliceren | Vereniging | **Jaargangen → Verloopt op** |

**Het client secret is de enige taak die het portaal ongemerkt laat stoppen.** Het portaal
waarschuwt er niet vooraf voor. Zet de vervaldatum in een agenda die niet aan één persoon hangt.

---

## 6. Vragen van deelnemers

### "Ik krijg geen inlogcode"

1. **Logboek → E-mail**, zoek op het adres.
   - *Mislukt:* het ligt aan de mailinstellingen. De foutmelding staat erbij; geef die door aan
     de technisch beheerder.
   - *Verzonden:* de mail is de deur uit. Laat de deelnemer in de spam kijken.
   - *Niets te vinden:* het adres heeft geen toegang tot een zichtbare jaargang. Het portaal
     zegt dat met opzet niet tegen de bezoeker. Ga naar **Deelnemers**, zoek het adres en
     controleer: staat het er, zonder typefout? Is het niet geblokkeerd? Heeft het een vinkje
     bij een gepubliceerde, niet-verlopen jaargang?
2. Te vaak achter elkaar een code aangevraagd? Dan houdt het portaal tijdelijk de boot af. Laat
   de deelnemer een uur wachten.

### "Mijn code werkt niet"

- Een code is 10 minuten geldig, werkt maar één keer en vervalt na 5 foute pogingen.
- De code werkt alleen in **dezelfde browser** als waarin hij is aangevraagd. Wie de code op de
  laptop aanvraagt en op de telefoon intypt, of de mail doorstuurt naar iemand anders, krijgt
  een foutmelding. Laat de deelnemer een nieuwe code aanvragen op het apparaat waarop hij of zij
  wil downloaden.

### "Ik gebruik een ander e-mailadres"

Een e-mailadres is niet te wijzigen. Voeg het nieuwe adres toe via **Toegang**, bij dezelfde
jaargangen. Is het oude adres nergens meer voor nodig, verwijder die deelnemer dan bij
**Deelnemers**.

### "De download stopt halverwege" of "de video is kapot"

1. **Controle**: staat het bestand er, en klopt de grootte?
2. **Logboek → Downloads**: hoeveel is er verzonden? Stopt het bij iedereen rond hetzelfde
   punt, dan is het een serverinstelling (technisch beheerder,
   [INSTALLATIE.md](INSTALLATIE.md) hoofdstuk 11). Verschilt het per keer, dan ligt het meestal
   aan de verbinding van de deelnemer.
3. Adviseer een stabiele verbinding (wifi, niet mobiel). Downloads zijn te hervatten, ook met
   een downloadmanager.

### "Ik wil dat mijn gegevens worden verwijderd"

**Deelnemers → [adres] → Deelnemer verwijderen.** Het downloadlogboek blijft bewaard (met het
e-mailadres als momentopname) totdat de bewaartermijn verstreken is; vermeld dat in uw antwoord.

### "Iemand anders heeft mijn video gedownload" / misbruik

Kijk bij **Deelnemers → [adres]** naar de inlogpogingen en downloads (tijdstip, IP-adres).
**Blokkeer** het adres zo nodig. Schakel de technisch beheerder in als er iets niet klopt.

---

## 7. Technisch beheer

### Niemand kan inloggen — eerste controle

1. **Overzicht**: staat er een rode of gele waarschuwing? Die noemt meestal de oorzaak.
2. **Instellingen → Testen → Graph-configuratie controleren.** Een fout over het token betekent
   meestal een verlopen client secret.
3. **Instellingen → Testen → Testmail versturen** naar uw eigen adres.
4. **Logboek → E-mail**: de foutmelding bij de laatste mislukte mail.
5. `logs/` in de projectmap: het applicatie- en foutlogboek.
6. Werkt het formulier helemaal niet (pagina ververst, niets gebeurt)? Controleer `APP_URL` in
   `.env`, zie [ARCHITECTUUR.md](ARCHITECTUUR.md), "Let op bij installatie". Laat hem niet leeg:
   zonder `APP_URL` verstuurt *Wachtwoord vergeten?* van het beheer geen link.

De foutentabel voor Microsoft Graph staat in [GRAPH-SETUP.md](GRAPH-SETUP.md), hoofdstuk 8;
de overige probleemoplossing in [INSTALLATIE.md](INSTALLATIE.md), hoofdstuk 11.

### Back-ups

Onvervangbaar zijn: **de database**, **de opslagmap** en **`.env`**.

```bash
mysqldump -u djm_portaal -p djm_portaal | gzip > djm-$(date +%F).sql.gz
```

- De opslagmap is groot, maar verandert maar één keer per jaar. Een kopie na elke nieuwe
  jaargang is meestal genoeg, mits de originele video ook nog ergens anders staat.
- Bewaar `.env` in de wachtwoordmanager van de vereniging, niet naast de back-up op dezelfde
  server.
- **Probeer een keer per jaar een back-up terug te zetten**, op een testomgeving. Een back-up
  die nooit is teruggezet, is geen back-up maar hoop.

### Een nieuwe versie installeren

1. Lees [CHANGELOG.md](../CHANGELOG.md) voor de versie(s) waar u overheen gaat: staat er iets
   over de database, `.env` of de webserverconfiguratie?
2. Maak eerst een back-up (database + `.env`).
3. Zet de nieuwe bestanden neer met `git pull` of `rsync`, zoals in
   [INSTALLATIE.md](INSTALLATIE.md) hoofdstuk 2. Overschrijf **niet** `.env`, `opslag/` en
   `logs/`, en upload `test/` niet.
4. `db.sql` is veilig opnieuw uit te voeren: het maakt ontbrekende tabellen aan en laat bestaande
   gegevens staan. Het past bestaande tabellen echter **niet** aan; staat er in de changelog een
   databasewijziging, volg dan de instructie die daar staat.
5. Controleer daarna: **Overzicht** zonder waarschuwingen, **Instellingen → Testen →
   Uitproberen** helemaal groen, en één keer zelf inloggen en een download starten.

De huidige versie staat in het bestand `VERSION`.

### De dagelijkse opschoontaak

```bash
php /pad/naar/djm-portaal/cron_opschonen.php
```

Hoort elke nacht te draaien (zie [INSTALLATIE.md](INSTALLATIE.md) hoofdstuk 9, of 7b voor
Plesk). Draait hij niet, dan blijft het portaal werken, maar worden logboeken langer bewaard dan
de ingestelde termijn. Dat is een AVG-punt.

---

## 8. Waar vind ik wat

| Document | Onderwerp |
|---|---|
| [README.md](../README.md) | Wat het portaal is, functionaliteit, beveiliging |
| **BEHEER.md** (dit document) | Dagelijks beheer en overdracht |
| [NIEUW-JAAR.md](NIEUW-JAAR.md) | Stap voor stap een nieuwe jaargang toevoegen |
| [INSTALLATIE.md](INSTALLATIE.md) | Server inrichten, `.env`, webserver, Plesk, probleemoplossing |
| [GRAPH-SETUP.md](GRAPH-SETUP.md) | Microsoft 365-koppeling, client secret, foutmeldingen |
| [ARCHITECTUUR.md](ARCHITECTUUR.md) | Voor ontwikkelaars: opbouw en afspraken in de code |
| [CHANGELOG.md](../CHANGELOG.md) | Wat er per versie is veranderd |
