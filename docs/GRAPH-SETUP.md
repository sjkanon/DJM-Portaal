# Microsoft Graph instellen — DJM Portaal

Het portaal verstuurt inlogcodes en uitnodigingen via de Microsoft Graph API, met een
zogenoemde *app-only* aanmelding: het portaal meldt zich aan als applicatie, niet namens een
gebruiker. Er is dus geen wachtwoord van een postbus nodig en er hoeft niemand in te loggen.

Deze handleiding loopt van de app-registratie tot een geslaagde testmail. Reken op een half
uur. U hebt nodig:

- een beheerdersaccount in Microsoft Entra ID (voorheen Azure AD) met de rol *Globale
  beheerder* of *Toepassingsbeheerder* — voor het verlenen van beheerderstoestemming;
- een beheerdersaccount in Exchange Online — voor stap 4;
- één postbus om vanaf te versturen, bijvoorbeeld `noreply@jouwdomein.nl`.

---

## 1. App-registratie aanmaken

1. Ga naar [portal.azure.com](https://portal.azure.com) en log in.
2. Ga naar **Microsoft Entra ID** → **App-registraties** → **Nieuwe registratie**.
3. Vul in:
   - **Naam:** `DJM Portaal` (deze naam ziet u later terug in logboeken en toestemmingen)
   - **Ondersteunde accounttypen:** *Alleen accounts in deze organisatiemap* (één tenant)
   - **Omleidings-URI (redirect URI):** **leeg laten.**
4. Klik op **Registreren**.

> De redirect-URI blijft bewust leeg. Die is alleen nodig als gebruikers via een
> inlogscherm toestemming geven. Het portaal gebruikt de *client credentials*-stroom: het
> haalt zelf een token op met client-ID en secret. Er is geen inlogscherm en dus geen
> omleiding.

---

## 2. Tenant-ID, client-ID en client secret

Na het registreren komt u op de overzichtspagina van de app.

1. Noteer daar:
   - **Toepassings-id (client)** → dit is `GRAPH_CLIENT_ID`
   - **Map-id (tenant)** → dit is `GRAPH_TENANT_ID`
2. Ga naar **Certificaten en geheimen** → tabblad **Clientgeheimen** → **Nieuw clientgeheim**.
3. Geef een omschrijving (`DJM Portaal mail`) en kies een geldigheidsduur. Microsoft staat
   maximaal 24 maanden toe.
4. Klik op **Toevoegen**. De kolom **Waarde** toont nu het geheim.

> **Kopieer de waarde meteen.** Na het verlaten van de pagina is hij niet meer op te vragen en
> moet u een nieuw geheim aanmaken. Let op dat u de **Waarde** kopieert, niet de **Geheim-id**.

### Noteer de vervaldatum

Zet de vervaldatum van het clientgeheim nu meteen in de agenda van de vereniging, met een
herinnering **een maand van tevoren**.

**Als het geheim verloopt, stopt de mail — en daarmee kan niemand meer inloggen.** Er komt
geen waarschuwing vanuit het portaal; de eerste die het merkt is een deelnemer die geen code
ontvangt. Vernieuwen is eenvoudig: maak een nieuw geheim aan en zet de nieuwe waarde in
**Beheer → Instellingen**.

| Gegeven | Waar u het invult |
|---|---|
| Map-id (tenant) | Beheer → Instellingen → `graph_tenant_id`, of `GRAPH_TENANT_ID` in `.env` |
| Toepassings-id (client) | Beheer → Instellingen → `graph_client_id`, of `GRAPH_CLIENT_ID` in `.env` |
| Waarde van het clientgeheim | Beheer → Instellingen → `graph_client_secret`, of `GRAPH_CLIENT_SECRET` in `.env` |

---

## 3. API-machtiging `Mail.Send` toevoegen

1. Ga in de app-registratie naar **API-machtigingen** → **Een machtiging toevoegen**.
2. Kies **Microsoft Graph**.
3. Kies **Toepassingsmachtigingen** — *niet* Gedelegeerde machtigingen.
4. Zoek op `Mail.Send`, vink het aan en klik op **Machtigingen toevoegen**.
5. Klik daarna op **Beheerderstoestemming verlenen voor \<organisatie\>** en bevestig.
   De kolom **Status** moet daarna een groen vinkje tonen.
6. Verwijder de standaard aanwezige gedelegeerde machtiging `User.Read` als u die niet gebruikt.

> **Toepassingsmachtiging, geen gedelegeerde machtiging.** Dit is het meest gemaakte fouten
> hier. Een *gedelegeerde* machtiging werkt alleen als er een ingelogde gebruiker is, en die
> is er niet: het portaal draait op de achtergrond en mailt zonder dat er iemand aanwezig is.
> Kiest u toch de gedelegeerde variant, dan krijgt u bij het versturen een HTTP 403.
>
> Voeg ook niets anders toe dan `Mail.Send`. De app hoeft geen mail te lezen en geen
> gebruikers op te vragen.

Zonder de stap "beheerderstoestemming verlenen" doet de machtiging nog niets. Als u die knop
niet kunt aanklikken, hebt u niet de juiste rol; vraag een globale beheerder om het te doen.

---

## 4. Beperken tot één postbus (Application Access Policy)

**Dit is de belangrijkste stap van deze handleiding.**

`Mail.Send` als toepassingsmachtiging geldt standaard voor de **hele tenant**. De app mag
daarmee mail versturen namens *elke* postbus in de organisatie — ook die van de voorzitter, de
penningmeester en iedereen die verder een account heeft. Dat is veel meer dan dit portaal nodig
heeft, en het is precies het soort machtiging waarmee schade wordt aangericht als het
clientgeheim ooit uitlekt.

Met een *Application Access Policy* in Exchange Online beperkt u de app tot één postbus.

### Uitvoeren

Installeer eenmalig de Exchange Online-module (PowerShell, als beheerder):

```powershell
Install-Module -Name ExchangeOnlineManagement -Scope CurrentUser
```

Voer daarna uit — vervang `jouwdomein.nl`, `noreply@jouwdomein.nl` en `<client-id>` door uw
eigen waarden:

```powershell
Connect-ExchangeOnline

# 1. Een beveiligingsgroep die precies één postbus bevat: de afzender.
New-DistributionGroup -Name "DJM Portaal afzenders" -Type Security `
    -PrimarySmtpAddress djm-portaal-afzenders@jouwdomein.nl

# 2. De afzenderpostbus in die groep zetten.
Add-DistributionGroupMember -Identity djm-portaal-afzenders@jouwdomein.nl `
    -Member noreply@jouwdomein.nl

# 3. De app beperken tot de postbussen in die groep.
New-ApplicationAccessPolicy -AccessRight RestrictAccess -AppId <client-id> `
    -PolicyScopeGroupId djm-portaal-afzenders@jouwdomein.nl `
    -Description "DJM Portaal mag alleen vanaf deze postbus verzenden"

# 4. Controleren dat het werkt.
Test-ApplicationAccessPolicy -Identity noreply@jouwdomein.nl -AppId <client-id>
```

De uitvoer van stap 4 hoort `AccessCheckResult : Granted` te tonen. Test ook een postbus die
er *niet* in hoort te zitten — daar hoort `Denied` uit te komen:

```powershell
Test-ApplicationAccessPolicy -Identity voorzitter@jouwdomein.nl -AppId <client-id>
```

### Twee dingen om te weten

- **Het duurt even.** Een nieuwe of gewijzigde policy is doorgaans binnen enkele minuten
  actief, maar het kan tot ongeveer een uur duren. Krijgt u vlak na het aanmaken nog een
  HTTP 403 bij de testmail, wacht dan even en probeer het opnieuw. `Test-ApplicationAccessPolicy`
  kan al `Granted` melden terwijl de policy in de praktijk nog niet overal is doorgevoerd.
- **Microsoft vervangt deze policies op termijn** door RBAC voor Exchange-applicaties
  (*Role Based Access Control for Applications*, met `New-ServicePrincipal` en
  `New-ManagementRoleAssignment`). Application Access Policies werken nog, maar zijn door
  Microsoft als verouderd aangemerkt. Richt u de app opnieuw in of migreert u naar een andere
  tenant, kijk dan eerst of RBAC in uw situatie de aangewezen route is. Het doel blijft
  hetzelfde: de app mag bij één postbus en verder bij niets.

---

## 5. Eisen aan de afzenderpostbus

Het adres in `MAIL_VAN_ADRES` (of **Beheer → Instellingen → `email_van_adres`**) moet een
**echte Exchange Online-postbus** zijn.

| Type | Werkt het? |
|---|---|
| Gewone gebruikerspostbus met licentie | Ja |
| **Gedeelde postbus** (shared mailbox, zonder licentie) | Ja — en vaak de beste keuze voor een `noreply`-adres |
| Distributielijst / distributiegroep | **Nee** — een lijst is geen postbus en heeft geen `sendMail`-eindpunt |
| Alias van een bestaande postbus | Nee — gebruik het primaire SMTP-adres |
| Mail contact / extern adres | Nee |

Een gedeelde postbus aanmaken kan via het Microsoft 365-beheercentrum of met PowerShell:

```powershell
New-Mailbox -Shared -Name "DJM Portaal noreply" -PrimarySmtpAddress noreply@jouwdomein.nl
```

Verstuurde berichten belanden in de map *Verzonden items* van die postbus. Dat is handig voor
controle achteraf; ruim de map periodiek op.

---

## 6. Invullen en testen in het portaal

1. Log in op **Beheer** → **Instellingen**.
2. Vul in bij het onderdeel e-mail:
   - **E-mailmethode:** `graph`
   - **Tenant-ID**, **Client-ID**, **Client secret**
   - **Afzenderadres:** `noreply@jouwdomein.nl`
   - **Afzendernaam:** bijvoorbeeld `Deventer Jeugd Musical`
3. Sla op.
4. Klik op **Graph-configuratie controleren**. Deze knop haalt een token op en controleert:
   - of het ophalen van het token lukt (tenant, client-ID en secret kloppen);
   - of het token daadwerkelijk de rol `Mail.Send` bevat;
   - of de opgegeven postbus bestaat.

   Die laatste regel meldt vrijwel altijd **niet te controleren**, en dat hoort zo. De check
   zoekt de postbus op in de directory, en daarvoor heeft de app leesrechten nodig die u in
   hoofdstuk 3 bewust niet hebt gegeven. Het zegt niets over het verzenden; de testmail in de
   volgende stap is de controle die telt.
5. Klik op **Testmail versturen** en vul uw eigen adres in.
6. Controleer het resultaat in **Beheer → Logboek → Mail**. Daar staat per bericht de status en,
   bij een mislukking, de foutmelding van Microsoft.

Werkt de testmail, dan werkt het inloggen. Probeer daarna één keer de volledige route: vraag op
het portaal een inlogcode aan met uw eigen e-mailadres.

---

## 7. SPF, DKIM en DMARC controleren

Een inlogcode die in de spammap belandt, is net zo onbruikbaar als een code die niet verstuurd
is. Zorg daarom dat het afzenderdomein correct is ingericht. Voor een domein dat via Microsoft
365 mailt:

**SPF** — één TXT-record op het domein:

```
v=spf1 include:spf.protection.outlook.com -all
```

**DKIM** — inschakelen in het Microsoft 365 Defender-portaal onder *E-mail en samenwerking* →
*Beleid en regels* → *Bedreigingsbeleid* → *DKIM*. Microsoft toont daar de twee CNAME-records
(`selector1._domainkey` en `selector2._domainkey`) die u bij uw DNS-provider moet zetten.
Schakel DKIM daarna in voor het domein.

**DMARC** — een TXT-record op `_dmarc.jouwdomein.nl`. Begin voorzichtig en scherp later aan:

```
v=DMARC1; p=none; rua=mailto:dmarc@jouwdomein.nl
```

Controleren:

```bash
dig +short TXT jouwdomein.nl
dig +short TXT _dmarc.jouwdomein.nl
dig +short CNAME selector1._domainkey.jouwdomein.nl
```

Stuur daarna een testmail naar een adres bij Gmail en bekijk daar *Origineel weergeven*: bij
`SPF`, `DKIM` en `DMARC` hoort overal **PASS** te staan.

Let er ook op dat u geen woorden gebruikt die spamfilters triggeren, en dat de mail vanaf een
consistent afzenderadres komt. De standaard mailteksten in het portaal zijn hierop afgestemd.

---

## 8. Veelvoorkomende foutmeldingen

| Melding | Oorzaak | Oplossing |
|---|---|---|
| **HTTP 401** — `invalid_client` / `AADSTS7000215` | Het clientgeheim klopt niet, of het is verlopen. | Controleer of u de **Waarde** hebt gekopieerd en niet de Geheim-id. Kijk in **Certificaten en geheimen** of het geheim nog geldig is; maak zo nodig een nieuw geheim aan. |
| **HTTP 401** — `AADSTS900023` / `unauthorized_client` | Verkeerde tenant-ID of client-ID. | Neem beide waarden opnieuw over van de overzichtspagina van de app-registratie. |
| **HTTP 403** — `ErrorAccessDenied` | Ofwel blokkeert de Application Access Policy deze postbus, ofwel ontbreekt `Mail.Send`, ofwel is die als *gedelegeerde* machtiging toegevoegd, ofwel is er geen beheerderstoestemming verleend. | Draai `Test-ApplicationAccessPolicy -Identity <postbus> -AppId <client-id>`; bij `Denied` staat de postbus niet in de groep. Controleer anders in **API-machtigingen** dat `Mail.Send` er staat als **Toepassing** met een groen vinkje bij Status. Vlak na het aanmaken van de policy: wacht tot een uur. |
| **HTTP 403** — `Access is denied. Check credentials and try again.` | Beheerderstoestemming is nooit verleend. | Klik op **Beheerderstoestemming verlenen** in de app-registratie. |
| **HTTP 404** — `MailboxNotEnabledForRESTAPI` | Het adres bestaat wel, maar heeft geen postbus die via de Graph API bereikbaar is: een distributielijst, een mail contact, een postbus op een lokale Exchange-server, of een account zonder Exchange-licentie. | Gebruik een echte Exchange Online-postbus (een gedeelde postbus mag). Zie hoofdstuk 5. |
| **HTTP 404** — `ResourceNotFound` / `Resource could not be discovered` | Het opgegeven afzenderadres bestaat niet in deze tenant, of het is een alias in plaats van het primaire adres. | Controleer het adres op typefouten en gebruik het primaire SMTP-adres van de postbus. |
| **HTTP 429** | Te veel verzoeken in korte tijd. | Wacht en probeer het opnieuw. Bij een grote import van uitnodigingen: verstuur in kleinere groepen. |
| **cURL-fout: `Could not resolve host`** of een timeout | De server kan `login.microsoftonline.com` of `graph.microsoft.com` niet bereiken. | Controleer de DNS en of uitgaand verkeer op poort 443 is toegestaan. Op sommige shared hosting is uitgaand verkeer geblokkeerd; vraag de hostingpartij deze twee hosts vrij te geven. |
| **De diagnose meldt bij Mailbox-status `niet te controleren`** | De app mag de directory niet uitlezen — ze heeft alleen `Mail.Send`, precies zoals hoofdstuk 3 voorschrijft. | Dit is geen fout en hoeft niet opgelost te worden. Een Application Access Policy speelt hier niet: die geldt voor postbussen, niet voor het opzoeken van gebruikers. Controleer met de testmail of verzenden werkt. |
| **De diagnose meldt `has_mail_send: false`** | Het token bevat de rol `Mail.Send` niet. | De machtiging is niet als toepassingsmachtiging toegevoegd, of er is geen beheerderstoestemming verleend. Zie hoofdstuk 3. |

Alle verzendpogingen — geslaagd en mislukt — staan met foutmelding in **Beheer → Logboek → Mail**.
Technische fouten komen daarnaast in `logs/app.log` te staan.
