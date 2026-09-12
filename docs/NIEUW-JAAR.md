# Een nieuw jaar toevoegen

Elk jaar komt er een nieuwe voorstelling bij, en daarmee een nieuwe videoregistratie. Deze
instructie beschrijft hoe u die in het portaal zet. U hoeft er niets voor te programmeren:
alles gaat via het beheerdersgedeelte.

Reken op een kwartier werk, plus de tijd die het uploaden van de video kost.

---

## Voordat u begint

Loop deze lijst even af. Het scheelt achteraf zoeken.

- [ ] **Staat de video helemaal op de server?** Een half geüploade video van 8 GB ziet er in
      een bestandslijst precies zo uit als een hele. Vergelijk de bestandsgrootte op de server
      met die op uw eigen computer — die twee getallen moeten exact gelijk zijn.
- [ ] **Speelt de video af?** Open het bestand op uw eigen computer voordat u het uploadt.
- [ ] **Is het bestandsformaat MP4 (H.264)?** Dat speelt op vrijwel elk apparaat af. MKV en MOV
      werken ook, maar geven vaker gedoe bij deelnemers.
- [ ] **Klopt de bestandsnaam?** Gebruik geen spaties, accenten of leestekens; `musical-2027.mp4`
      is een prima naam. De naam die de deelnemer straks ziet, stelt u apart in.
- [ ] **Hebt u de e-maillijst?** Eén adres per regel, of een CSV-bestand.

---

## Stap 1 — De video op de server zetten

De videobestanden gaan **niet** via de browser naar het portaal: die zijn daar te groot voor.
Gebruik SFTP, bijvoorbeeld met [FileZilla](https://filezilla-project.org/) of WinSCP.

1. Verbind met de server met de SFTP-gegevens die u van de beheerder hebt gekregen.
2. Ga naar de opslagmap. Welke map dat is, staat in **Beheer → Instellingen** vermeld bij de
   opslaglocatie; vaak is dat `/var/djm-opslag`.
3. Maak daar een **map met het jaartal**, bijvoorbeeld `2027`.
4. Zet het videobestand in die map.

U krijgt dan bijvoorbeeld:

```
/var/djm-opslag/
├── 2025/
│   └── musical-2025.mp4
├── 2026/
│   └── musical-2026.mp4
└── 2027/
    └── musical-2027.mp4
```

Controleer na afloop in FileZilla of de bestandsgrootte op de server klopt. Bij een afgebroken
upload: verwijder het halve bestand en begin opnieuw — een deelnemer die een halve video
downloadt, merkt dat pas na een uur wachten.

---

## Stap 2 — Jaargang aanmaken

1. Log in op **Beheer** en ga naar **Jaargangen**.
2. Klik op **Nieuwe jaargang** en vul in:
   - **Jaar:** `2027`
   - **Titel:** de naam van de voorstelling, bijvoorbeeld `Annie (2027)`
   - **Omschrijving:** optioneel, bijvoorbeeld de speeldata of een korte toelichting. Dit ziet
     de deelnemer in het portaal.
   - **Gepubliceerd:** laat dit voorlopig **uit** staan. Zo kunt u eerst rustig alles klaarzetten.
3. Opslaan.

Publiceer de jaargang pas nadat u stap 3 en de test hebt gedaan.

---

## Stap 3 — Het bestand koppelen

Het portaal weet nu dat er een jaargang 2027 is, maar nog niet welk bestand daarbij hoort.

1. Ga naar **Beheer → Bestanden**.
2. Kies de jaargang **2027**.
3. Kies het bestand dat u in stap 1 hebt geüpload; het portaal leest de opslagmap uit.
4. Vul in:
   - **Titel:** wat de deelnemer op de downloadknop ziet, bijvoorbeeld
     `Volledige registratie (Full HD)`.
   - **Bestandsnaam:** de naam waaronder de video op de computer van de deelnemer wordt
     opgeslagen, bijvoorbeeld `DJM Annie 2027.mp4`. Hier mogen wél spaties in.
5. Opslaan.

Het portaal bepaalt zelf de bestandsgrootte. **Controleer of die klopt** met wat u in stap 1
hebt gezien. Staat er een veel te klein getal, dan is het bestand niet compleet geüpload.

U kunt meerdere bestanden aan één jaar koppelen, bijvoorbeeld een versie in hoge kwaliteit en
een kleinere versie voor wie een trage verbinding heeft.

---

## Stap 4 — E-mailadressen importeren

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
vrijmaken, verwijder het bestand dan handmatig via SFTP uit de opslagmap.

Wilt u alleen dat niemand er meer bij kan, gebruik dan liever *depubliceren* of *Verloopt op*.

---

## Kort samengevat

| Stap | Waar | Wat |
|---|---|---|
| 1 | SFTP | Video in een map met het jaartal, bijvoorbeeld `2027/` |
| 2 | Beheer → Jaargangen | Jaargang 2027 aanmaken, nog niet publiceren |
| 3 | Beheer → Bestanden | Bestand koppelen, bestandsgrootte controleren |
| 4 | Beheer → Toegang | Eerst uw eigen adres, testen, dan de hele lijst |

Loopt u vast? De technische details staan in [INSTALLATIE.md](INSTALLATIE.md), onder
*Probleemoplossing*.
