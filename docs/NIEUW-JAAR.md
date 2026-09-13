# Een nieuw jaar toevoegen

Elk jaar komt er een nieuwe voorstelling bij, en daarmee een nieuwe videoregistratie. Deze
instructie beschrijft hoe u die in het portaal zet. U hoeft er niets voor te programmeren:
alles gaat via het beheerdersgedeelte.

Reken op een kwartier werk, plus de tijd die het uploaden van de video kost.

---

## Voordat u begint

Loop deze lijst even af. Het scheelt achteraf zoeken.

- [ ] **Staat de video op deze computer?** U uploadt hem via de browser. Staat hij op een
      usb-schijf, kopieer hem dan eerst naar de computer zelf, of laat de schijf aangesloten tot
      de upload klaar is.
- [ ] **Speelt de video af?** Open het bestand op uw eigen computer voordat u het uploadt.
- [ ] **Is het bestandsformaat MP4 (H.264)?** Dat speelt op vrijwel elk apparaat af. MKV en MOV
      werken ook, maar geven vaker gedoe bij deelnemers.
- [ ] **Klopt de bestandsnaam?** Gebruik geen spaties, accenten of leestekens; `musical-2027.mp4`
      is een prima naam. De naam die de deelnemer straks ziet, stelt u apart in.
- [ ] **Hebt u de e-maillijst?** Eén adres per regel, of een CSV-bestand.

---

## Stap 1 — Jaargang aanmaken

1. Log in op **Beheer** en ga naar **Jaargangen**.
2. Klik op **Nieuwe jaargang** en vul in:
   - **Jaar:** `2027`
   - **Titel:** de naam van de voorstelling, bijvoorbeeld `Annie (2027)`
   - **Omschrijving:** optioneel, bijvoorbeeld de speeldata of een korte toelichting. Dit ziet
     de deelnemer in het portaal.
   - **Gepubliceerd:** laat dit voorlopig **uit** staan. Zo kunt u eerst rustig alles klaarzetten.
3. Opslaan.

Publiceer de jaargang pas nadat u stap 2 en de test hebt gedaan.

---

## Stap 2 — De video uploaden

1. Ga naar **Beheer → Bestanden** en kies de jaargang **2027**.
2. Sleep de video in het vak **Video uploaden**, of kies hem met de knop.
3. Vul in:
   - **Titel:** wat de deelnemer op de downloadknop ziet, bijvoorbeeld
     `Volledige registratie (Full HD)`.
   - **Downloadnaam:** de naam waaronder de video op de computer van de deelnemer wordt
     opgeslagen, bijvoorbeeld `DJM Annie 2027.mp4`. Hier mogen wél spaties in.

   Dat kan ook tijdens het uploaden, en later nog aanpassen kan altijd.
4. Klik op **Uploaden**.

U ziet hoeveel er al binnen is, hoe snel het gaat en hoe lang het nog duurt. Een video van 8 GB
is met een gewone thuisverbinding al gauw een paar uur onderweg.

- **Houd het tabblad open** en laat de computer niet in slaap vallen. In een ander tabblad verder
  werken kan gewoon.
- **Valt de verbinding weg?** Dan hoeft u niets te doen: het portaal probeert het zelf opnieuw.
- **Tabblad toch dicht, of de computer uit?** Open **Bestanden** opnieuw, kies dezelfde jaargang
  en hetzelfde bestand. De upload gaat verder waar hij was. Een onafgemaakte upload die een week
  stilligt, wordt vanzelf weggegooid.

Is de upload klaar, dan ververst de pagina en staat de video bij **Gekoppelde bestanden**. Een
half bestand komt daar nooit te staan: de video wordt pas gekoppeld als hij compleet is. Kijk voor
de zekerheid nog even in **Beheer → Controle**; daar hoort *in orde* te staan.

U kunt meerdere bestanden aan één jaar koppelen, bijvoorbeeld een versie in hoge kwaliteit en
een kleinere versie voor wie een trage verbinding heeft.

> **Staat de video al op de server?** Bijvoorbeeld omdat de technisch beheerder hem rechtstreeks
> heeft neergezet. Kies hem dan bij **Kiezen uit de opslagmap** en klik op **Koppelen aan deze
> jaargang**.

---

## Stap 3 — E-mailadressen importeren

Nu bepaalt u wie deze jaargang mag downloaden.

1. Ga naar **Beheer → Toegang** en kies de jaargang **2027**.
2. Plak de e-mailadressen in het invoerveld (één per regel), of upload een CSV-bestand.
3. Klik op **Controleren**. U krijgt een overzicht te zien van wat er gaat gebeuren: welke
   adressen nieuw zijn, welke al bekend zijn en welke ongeldig zijn (typefouten, ontbrekende
   `@`). Loop dat overzicht even door.
4. Kies of u **meteen een uitnodigingsmail** wilt versturen. Die mail vertelt de deelnemer dat
   de video klaarstaat en hoe hij of zij kan inloggen.
5. Bevestig de import.

### Test eerst met uw eigen adres

Doe dit vóór u de hele lijst importeert:

1. Importeer alleen **uw eigen e-mailadres** in jaargang 2027, mét uitnodigingsmail.
2. Publiceer de jaargang (**Beheer → Jaargangen**, vinkje *Gepubliceerd*).
3. Controleer of de uitnodiging aankomt.
4. Ga naar het portaal, vraag een inlogcode aan, log in en start de download. Laat hem even
   lopen — als de download begint en de bestandsgrootte klopt, is alles goed.
5. Werkt alles? Importeer dan de volledige lijst.

Gaat er iets mis, dan heeft nog niemand er last van gehad.

---

## Een oud jaar afsluiten

Twee manieren, afhankelijk van wat u wilt:

**Tijdelijk onzichtbaar maken** — zet in **Beheer → Jaargangen** het vinkje *Gepubliceerd* uit.
De jaargang verdwijnt uit het portaal. Alles blijft bewaard; u kunt het later weer aanzetten.

**Automatisch laten verlopen** — vul bij de jaargang een datum in bij **Verloopt op**. Vanaf die
datum is de jaargang niet meer te downloaden, zonder dat u er nog naar hoeft om te kijken.
Handig als u afspreekt dat een registratie een jaar beschikbaar blijft. Met **Zichtbaar vanaf**
kunt u andersom een jaargang alvast klaarzetten voor een datum in de toekomst.

Deelnemers behouden in beide gevallen hun toegang tot de jaren waarvoor ze zijn toegevoegd; ze
zien alleen de jaren die op dat moment zichtbaar zijn.

---

## Een jaargang verwijderen

Als u een jaargang verwijdert, verdwijnen de jaargang, de bestandskoppelingen en de
toegangsrechten uit de database.

**Het videobestand zelf blijft op de schijf staan.** Dat is met opzet: een verkeerde klik mag
geen video van 8 GB wissen die misschien nergens anders meer staat. Wilt u de ruimte echt
vrijmaken, verwijder het bestand dan in **Beheer → Bestanden**, bij *Niet-gekoppelde bestanden opruimen*.

Wilt u alleen dat niemand er meer bij kan, gebruik dan liever *depubliceren* of *Verloopt op*.

---

## Kort samengevat

| Stap | Waar | Wat |
|---|---|---|
| 1 | Beheer → Jaargangen | Jaargang 2027 aanmaken, nog niet publiceren |
| 2 | Beheer → Bestanden | Video uploaden; hij wordt na afloop meteen gekoppeld |
| 3 | Beheer → Toegang | Eerst uw eigen adres, testen, dan de hele lijst |

Loopt u vast? De technische details staan in [INSTALLATIE.md](INSTALLATIE.md), onder
*Probleemoplossing*.
