# DJM Portaal — Implementatieplan

Beveiligd downloadportaal voor jaarlijkse videobestanden. Toegang via e-mailadres +
eenmalige inlogcode (OTP), verstuurd via de Microsoft Graph API.

---

## 1. Uitgangspunten

| Keuze | Besluit |
|---|---|
| Toegangsmodel | Whitelist **per jaargang**: een deelnemer ziet alleen de jaren waarvoor hij/zij is toegevoegd |
| Authenticatie | E-mailadres + 6-cijferige eenmalige code (geen wachtwoorden voor deelnemers) |
| Mailkanaal | Microsoft Graph API (app-only, client credentials), SMTP als fallback |
| Levering | Download-only, met hervatbare downloads (HTTP Range) |
| Beheer | Volledige admin-webinterface |
| Hosting | Simpele webserver; uitlevering past zich automatisch aan (nginx / Apache / plain PHP) |

**Stack:** PHP 8.1+, MySQL/MariaDB, PDO, geen Composer-dependencies, Bootstrap 5 via CDN.
Dezelfde huisstijl en conventies als `Werkbon` en `Klantenportaal`, zodat beheer en
doorontwikkeling vertrouwd aanvoelen.

### Wat we hergebruiken uit bestaande repo's

- **`GraphMailer`** uit `Klantenportaal/includes/email_helper.php` — complete, dependency-vrije
  Graph-mailer met token-cache, `sendMail`, bijlagen én een `diagnoseConfiguration()` die
  controleert of het token de `Mail.Send`-rol heeft en of de postbus bereikbaar is. Wordt
  vrijwel ongewijzigd overgenomen.
- **Config-patroon** uit `Werkbon/config.php` — `.env`-loader, `db(): PDO`, `h()`, sessie-hardening
  (httponly / secure / samesite strict), logmap.
- **CSRF-helpers** uit `Werkbon/includes/auth.php` (`csrf_token()`, `verify_csrf()`, `csrf_field()`).
- **Token-flow** uit `Werkbon/portaal/uitnodiging.php` — patroon voor eenmalige, verlopende tokens.
- **`instellingen`-tabel** (sleutel/waarde) — zodat Graph-credentials, branding en mailteksten
  via de admin instelbaar zijn in plaats van hardcoded.

---

## 2. Datamodel

```
jaargangen           id, jaar (uniek), titel, slug, omschrijving, gepubliceerd,
                     zichtbaar_vanaf, verloopt_op, aangemaakt_op

jaargang_bestanden   id, jaargang_id, bestandsnaam (download-naam), pad (relatief t.o.v.
                     opslagmap), bytes, mime, sha256, sortering, actief
                     -> meerdere bestanden per jaar mogelijk (4K / compact / bonus)

deelnemers           id, email (uniek, genormaliseerd), naam, geblokkeerd,
                     aangemaakt_op, laatst_ingelogd_op

toegang              id, deelnemer_id, jaargang_id, toegevoegd_op, toegevoegd_door
                     UNIQUE(deelnemer_id, jaargang_id)

login_codes          id, email, deelnemer_id, code_hash, challenge_id, verloopt_op,
                     gebruikt_op, pogingen, ip, aangemaakt_op

remember_tokens      id, deelnemer_id, token_hash, verloopt_op, ip, laatst_gebruikt
                     (voor "onthoud dit apparaat 30 dagen")

download_log         id, deelnemer_id, bestand_id, ip, user_agent, gestart_op,
                     afgerond, bytes_verzonden

mail_log             id, ontvanger, onderwerp, soort, status, foutmelding, verzonden_op

aanvraag_limiet      sleutel (email/ip), teller, venster_start   -- rate limiting

beheerders           id, naam, email, wachtwoord_hash, rol, actief, laatst_ingelogd

instellingen         sleutel, waarde   -- Graph-config, branding, mailteksten
```

**Een jaar toevoegen raakt geen code aan** — alleen rijen in `jaargangen`,
`jaargang_bestanden` en `toegang`.

---

## 3. Inlogflow (OTP)

1. **`index.php`** — bezoeker vult e-mailadres in (CSRF-beveiligd formulier).
2. **Aanvraag verwerken:**
   - E-mail normaliseren (trim + lowercase).
   - Rate limit: max **3 codes per e-mailadres per 15 min**, max **10 per IP per uur**.
   - **Altijd** dezelfde neutrale melding tonen ("Als dit adres bij ons bekend is, is er een
     code verstuurd") en de responstijd gelijk houden — geen user enumeration.
   - Alleen als het adres toegang heeft tot ≥ 1 gepubliceerde jaargang wordt er echt gemaild.
3. **Code genereren:** 6 cijfers via `random_int()`. Opgeslagen als **HMAC-SHA256 met een pepper
   uit `.env`** — nooit de code zelf. Geldig **10 minuten**, **max 5 verificatiepogingen**,
   **eenmalig bruikbaar**; openstaande codes voor hetzelfde adres worden ingetrokken.
4. **Gebonden aan de browser:** een `challenge_id` in de sessie koppelt de code aan de browser
   waarin hij is aangevraagd. Een doorgestuurde code werkt daardoor niet elders.
   (Uitschakelbaar in de instellingen als het in de praktijk te streng blijkt.)
5. **Versturen** via Graph; elke verzending in `mail_log`.
6. **`verifieer.php`** — code invoeren. Vergelijking in constante tijd (`hash_equals`).
   Bij succes: `session_regenerate_id(true)`, sessie met `deelnemer_id`, alle codes van dat
   adres invalideren, sessieduur 2 uur, optioneel "onthoud dit apparaat 30 dagen".
7. Mislukte pogingen worden geteld en gelogd; na 5 fouten is de code dood en moet een nieuwe
   worden aangevraagd.

Dit volgt de gangbare aanbevelingen: korte levensduur, hash-at-rest, eenmalig gebruik,
constant-time vergelijking en harde throttling op het verificatie-eindpunt.

---

## 4. Downloadflow

- **`portaal/index.php`** — overzicht van de jaargangen waar deze deelnemer recht op heeft,
  met bestandsgrootte en downloadknop.
- **`download.php?bestand=<id>`** — controleert sessie → controleert koppeling in `toegang`
  (nooit een pad uit user input; alleen id → DB → pad) → logt → levert uit.
- **Uitlevering-abstractie** in `includes/download_helper.php`, ingesteld via `DELIVERY_MODE`
  in `.env` (`auto` / `xaccel` / `xsendfile` / `php`):
  - **nginx** → `X-Accel-Redirect` naar een `internal` location. Snelst, geen PHP-limieten.
  - **Apache met mod_xsendfile** → `X-Sendfile`.
  - **Fallback: PHP-streaming** — `session_write_close()`, output buffering uit,
    `set_time_limit(0)`, 8 KB-chunks, correcte `Content-Length`, en **HTTP Range-support (206)**
    zodat afgebroken downloads hervat kunnen worden.
- **Kortlevende download-URL:** de knop wijst naar een HMAC-ondertekende link (5 min geldig,
  gebonden aan deelnemer + bestand). Daardoor werkt de download ook in downloadmanagers en
  blijft de link niet bruikbaar als hij gedeeld wordt.
- **Video's staan buiten de webroot** (bv. `/var/djm-opslag/2026/`), met een `.htaccess`-deny
  als vangnet mocht de map ooit binnen de webroot belanden.

---

## 5. Beheerdersgedeelte

| Pagina | Functie |
|---|---|
| `admin/login.php` | Beheerderslogin (wachtwoord; optioneel dezelfde e-mailcode als tweede factor) |
| `admin/jaargangen.php` | Jaargang aanmaken/bewerken, publiceren/depubliceren |
| `admin/bestanden.php` | Bestand koppelen: **(a)** kiezen uit de opslagmap (aanbevolen voor multi-GB video's die via SFTP zijn geüpload) of **(b)** uploaden via de browser voor kleinere bestanden |
| `admin/toegang.php` | E-mailadressen plakken of CSV importeren per jaargang, met preview (nieuw / bestaand / ongeldig) en optioneel direct een uitnodigingsmail |
| `admin/deelnemers.php` | Zoeken, blokkeren, verwijderen (AVG), toegang per persoon inzien |
| `admin/logboek.php` | Inlogpogingen, verstuurde mails, downloads |
| `admin/instellingen.php` | Graph-credentials, afzender, branding, mailteksten, **testmail + Graph-diagnose** |

### Nieuw jaar toevoegen = 4 handelingen, geen code

1. Video via SFTP in `opslag/2027/`.
2. Admin → jaargang **2027** aanmaken.
3. Bestand koppelen aan die jaargang.
4. E-maillijst plakken → optioneel uitnodigingsmail versturen.

---

## 6. Microsoft Graph-configuratie

- App-registratie in Entra met **uitsluitend** de application permission `Mail.Send`
  (admin consent), client secret of certificaat.
- **Application Access Policy** in Exchange Online die deze app beperkt tot één postbus —
  anders mag de app namens *elke* postbus in de tenant mailen:
  ```powershell
  New-ApplicationAccessPolicy -AccessRight RestrictAccess -AppId <client-id> `
      -PolicyScopeGroupId djm-portaal-afzender@domein.nl `
      -Description "DJM Portaal mag alleen vanaf deze postbus verzenden"
  Test-ApplicationAccessPolicy -Identity noreply@domein.nl -AppId <client-id>
  ```
- Credentials in `.env` (buiten git) of in `instellingen`; de admin toont met de bestaande
  `diagnoseConfiguration()` direct of het token de juiste rol heeft en de postbus bereikbaar is.
- SPF/DKIM/DMARC op het afzenderdomein — inlogcodes moeten betrouwbaar aankomen.

---

## 7. Beveiliging

- CSRF-token op elke POST; `session_regenerate_id()` bij elke privilegewissel.
- Cookies: `httponly`, `secure`, `samesite=Strict`, `use_only_cookies`.
- Securityheaders: HSTS, `X-Content-Type-Options`, `Referrer-Policy`, CSP.
- Geen user enumeration; identieke melding en responstijd voor bekende en onbekende adressen.
- Rate limiting op codeaanvraag én codeverificatie, per e-mailadres en per IP.
- Alle inlog-, mail- en downloadgebeurtenissen worden gelogd met IP en tijdstip.
- Videobestanden zijn niet direct via een URL benaderbaar.
- `.env` uit git; `config.example.php` en `.env.example` wel in git.
- AVG: instelbare bewaartermijn voor logs, en een verwijderknop per deelnemer.

---

## 8. Bestandsstructuur

```
DJM Portaal/
├── .env.example              DB, APP_URL, OTP_PEPPER, DELIVERY_MODE, OPSLAG_PAD
├── config.php                env-loader, db(), h(), sessie-hardening
├── db.sql                    volledig schema
├── setup.php                 eenmalige installatie (eerste beheerder + schema)
├── index.php                 e-mailadres invoeren
├── verifieer.php             code invoeren
├── logout.php
├── download.php              toegangscontrole + uitlevering
├── portaal/
│   └── index.php             overzicht jaargangen van de ingelogde deelnemer
├── includes/
│   ├── auth.php              sessies, CSRF, toegangscontrole
│   ├── otp.php               code genereren, versturen, verifiëren, throttling
│   ├── email_helper.php      GraphMailer + SMTP-fallback + mailsjablonen
│   ├── download_helper.php   X-Accel / X-Sendfile / PHP-streaming met Range
│   └── layout.php            gedeelde header/footer/branding
├── admin/                    beheerinterface (zie §5)
├── opslag/                   videobestanden (buiten webroot of met deny)
├── logs/
└── docs/
    ├── INSTALLATIE.md
    ├── GRAPH-SETUP.md
    └── NIEUW-JAAR.md         stap-voor-stap voor de beheerder
```

---

## 9. Fasering

| Fase | Inhoud |
|---|---|
| **0** | `git init`, projectskelet, `config.php`, `.env.example`, `db.sql`, `setup.php` |
| **1** | Datamodel + admin-login + jaargangen- en bestandenbeheer |
| **2** | OTP-inlogflow: aanvraag, Graph-verzending, verificatie, throttling, logging |
| **3** | Portaaloverzicht + downloadlaag (X-Accel / X-Sendfile / PHP-streaming met Range) |
| **4** | Toegangsbeheer: CSV/plak-import, uitnodigingsmails, deelnemersbeheer |
| **5** | Logboeken, instellingen, Graph-diagnose, branding, mailsjablonen |
| **6** | Hardening, documentatie (`INSTALLATIE.md`, `GRAPH-SETUP.md`, `NIEUW-JAAR.md`), testronde |

---

## 10. Openstaande punten

1. **Graph-app** — is er al een app-registratie te gebruiken, of moet die nog worden aangemaakt?
   Welk afzenderadres (bv. `noreply@djm.nl`)?
2. **Server** — nginx of Apache, welke PHP-versie, en is `mod_xsendfile` beschikbaar?
   De downloadlaag detecteert dit zelf, maar met deze info kan ik de juiste config meeleveren.
3. **Bestandsgrootte** — hoe groot zijn de video's? Bij multi-GB adviseer ik uploaden via SFTP
   in plaats van via de browser.
4. **Branding** — logo, kleuren, en waar `DJM` voor staat (voor teksten en mailsjablonen).
5. **Historie** — zijn er al bestaande jaargangen + deelnemerslijsten die geïmporteerd moeten
   worden, of beginnen we bij het huidige jaar?
