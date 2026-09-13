# Architectuur & contract — DJM Portaal

Deventer Jeugd Musical. Beveiligd downloadportaal voor de videoregistratie per jaargang.
Toegang via e-mailadres + eenmalige inlogcode (OTP) per e-mail, verstuurd via Microsoft Graph.

**Stack:** PHP 8.1+, MySQL/MariaDB via PDO, geen Composer, Bootstrap 5 + Bootstrap Icons
meegeleverd in `assets/vendor/` (geen CDN). Nederlands in code, UI en commentaar. Vier
spaties inspringen.

---

## Bestandsindeling

```
config.php                  env, db(), instellingen, helpers, security headers
db.sql                      volledig schema (leidend)
setup.php                   installatiewizard: omgeving, .env, database, beheerder
index.php                   e-mailadres invoeren -> code aanvragen
verifieer.php               code invoeren
logout.php
download.php                toegangscontrole + uitlevering
zelftest.php                testbestand van de uitleveringszelftest (ondertekend, geen sessie)
portaal/index.php           overzicht jaargangen van de ingelogde deelnemer
includes/auth.php           sessies, CSRF, deelnemer- en beheerderidentiteit
includes/otp.php            codes genereren, versturen, verifiëren, throttling
includes/email_helper.php   GraphMailer + SimpleMailer + mailsjablonen
includes/toegang_helper.php wie mag welke jaargang / welk bestand
includes/download_helper.php uitlevering: X-Accel / X-Sendfile / PHP-stream
includes/uitlevering_helper.php serverconfig tonen + de uitlevering echt uitproberen
includes/layout.php         portaal_start() / portaal_eind() voor het portaal
includes/beheerder_helper.php  rem op pogingen, links om een wachtwoord te kiezen
admin/includes/layout.php   admin_start() / admin_eind() / admin_login_start()
admin/bestandscontrole.php  controle: staan alle gekoppelde bestanden er nog?
admin/handleiding.php       handleiding voor beheerders, met schermafdrukken
admin/beheerders.php        beheerders toevoegen, links sturen, uit- en inschakelen
admin/wachtwoord_vergeten.php  resetlink aanvragen (zonder sessie)
admin/wachtwoord_instellen.php wachtwoord kiezen via de link (zonder sessie)
assets/handleiding/         schermafdrukken voor de handleiding (gemaakt door test/handleiding.sh)
admin/*.php                 beheerinterface
opslag/                     videobestanden (niet publiek benaderbaar)
```

## Vaste afspraken

- Elk instapbestand begint met `require_once __DIR__ . '/config.php';` (of `dirname(__DIR__)`),
  daarna `require_once .../includes/auth.php`.
- Uitvoer altijd door `h()`. Elk POST-formulier bevat `csrf_field()`; verwerking begint met
  `vereis_csrf()`.
- Alle SQL via prepared statements met named parameters. Nooit stringconcatenatie met invoer.
- E-mailadressen altijd door `normaliseer_email()` vóór opslag of vergelijking.
- Meldingen tussen pagina's via `flash('success'|'danger'|'warning'|'info', $tekst)` +
  Post/Redirect/Get.
- Logregels via `log_login()` (inloggebeurtenissen) en `app_log()` (technische fouten).
- Datums tonen met `formatteer_datum()`, bestandsgroottes met `formatteer_bytes()`.
- Links bouwen met `url('admin/jaargangen.php')` — nooit hardcoded paden.

## Handleiding bijhouden

**Beheer › Handleiding** (`admin/handleiding.php`) is de handleiding voor wie het portaal beheert.
Een wijziging die een beheerder of deelnemer kan merken, is pas af als de handleiding klopt:

1. Pas de tekst aan in `admin/handleiding.php`, en in `docs/BEHEER.md` (de tekstversie).
2. Een nieuw scherm in `admin_menu()` krijgt een blok met `id="scherm-<naam>"` en
   `data-scherm="<naam>.php"`. De knop **Uitleg** op dat scherm springt ernaartoe, en
   `test/audit.sh` faalt zolang het blok ontbreekt.
3. Maak de schermafdrukken opnieuw met `bash test/handleiding.sh` en commit
   `assets/handleiding/` mee. Een nieuwe afbeelding voeg je toe in `test/handleiding.js` en
   met `handleiding_figuur()` in de pagina; `test/audit.sh` controleert dat het bestand bestaat.
4. Noem de wijziging in `CHANGELOG.md`.

## Beschikbare functies (config.php)

`env()`, `db()`, `db_beschikbaar()`, `tabel_bestaat()`, `instelling()`, `instelling_int()`,
`instelling_bool()`, `instelling_opslaan()`, `instellingen()`, `portaal_naam()`, `h()`,
`formatteer_bytes()`, `formatteer_datum()`, `app_base_url()`, `url()`, `opslag_pad()`,
`opslag_absoluut_pad()`, `normaliseer_email()`, `geldig_email()`, `client_ip()`,
`client_ip_bin()`, `ip_leesbaar()`, `client_user_agent()`, `app_key()`, `otp_pepper()`,
`log_login()`, `app_log()`, `stuur_security_headers()`, `vereis_installatie()`,
`ensure_session_started()`, `destroy_current_session()`, `app_url_afwijking()`,
`https_actief()`, `veilige_host()`, `vertrouwde_proxies()`, `is_vertrouwde_proxy()`,
`ip_in_bereik()`, `geldig_ip_of_bereik()`.

`client_ip()` is de enige plek die bepaalt welk IP-adres bij een bezoeker hoort. Lees hem
nooit zelf uit `$_SERVER`: alleen deze functie weet of `X-Forwarded-For` te vertrouwen is
(zie `TRUSTED_PROXIES` in `.env`). Hetzelfde geldt voor `https_actief()` en
`veilige_host()` — allebei kijken ze naar headers die een bezoeker zelf kan zetten.

**auth.php:** `csrf_token()`, `csrf_field()`, `verify_csrf()`, `vereis_csrf()`,
`deelnemer_inloggen()`, `deelnemer_ingelogd()`, `huidige_deelnemer()`, `vereis_deelnemer()`,
`deelnemer_uitloggen()`, `onthoud_dit_apparaat()`, `herstel_uit_remember_cookie()`,
`vergeet_dit_apparaat()`, `beheerder_inloggen()`, `huidige_beheerder()`, `vereis_beheerder()`,
`beheerder_uitloggen()`, `flash()`, `flash_ophalen()`.

**toegang_helper.php:** `sql_jaargang_zichtbaar()`, `deelnemer_op_email()`,
`deelnemer_aanmaken_of_ophalen()`, `email_heeft_toegang()`, `deelnemer_jaargangen()`,
`deelnemer_bestand()`, `jaargang_aantal_deelnemers()`, `toegang_toekennen()`,
`toegang_intrekken()`, `jaargang_status()`.

**email_helper.php:** `mail_config()`, `mailer_maken()`, `mail_geconfigureerd()`,
`verstuur_mail()`, `verstuur_inlogcode_mail()`, `verstuur_uitnodiging_mail()`,
`verstuur_beheerder_link_mail()`, `verstuur_testmail()`, `mail_html_omhulsel()`, `mail_sjabloon_vullen()`.
`GraphMailer` heeft `diagnoseConfiguration(): array` met `token_ok`, `roles`,
`has_mail_send`, `mailbox_status`, `mailbox_conclusief`, `mailbox_hint`, `errors`.

De mailbox-check doet `GET /users/{adres}`; dat is een directory-aanroep en vereist
leesrechten die de app bewust niet heeft. Levert die een 403 met
`Authorization_RequestDenied`, dan staat `mailbox_conclusief` op `false`: de uitslag
zegt dan niets over het verzenden en wordt in Beheer als *niet te controleren*
getoond in plaats van als fout.

**layout.php:** `portaal_start($titel, ['email' => …, 'intro' => …, 'smal' => true])`,
`portaal_eind()`, `toon_flash()`, `toon_fout()`,
`toon_melding()`, `toon_contact()`, `branding_kleur()`, `branding_logo()`.

**beheerder_helper.php:** `beheerder_link_versturen()`, `beheerder_link_controleren()`,
`beheerder_wachtwoord_opslaan()`, `beheerder_links_intrekken()`, `admin_limiet_teller()` /
`admin_limiet_ophogen()` / `admin_limiet_wissen()`. Links bestaan uit een selector en een geheim
deel; van dat laatste staat alleen een HMAC met `APP_KEY` in de database. De tabel
`beheerder_tokens` wordt zo nodig aangemaakt, want `db.sql` draait alleen bij de installatie.
`admin/wachtwoord_vergeten.php` verstuurt alleen een link als `APP_URL` vaststaat
(`beheerder_link_basis_vast()`): zonder sessie bepaalt de aanvrager anders via de Host-header
naar welke site de link wijst.

**admin/includes/layout.php:** `admin_start($titel, $subtitel = '')`, `admin_eind()`,
`admin_login_start($titel)`, `admin_login_eind()`, `admin_menu()`.

## Datamodel

Zie `db.sql` — dat bestand is leidend. Kern: `jaargangen` 1—n `jaargang_bestanden`,
`deelnemers` n—n `jaargangen` via `toegang`. Verder `login_codes`, `remember_tokens`,
`aanvraag_limiet`, `download_log`, `mail_log`, `login_log`, `beheerders`, `beheerder_tokens`, `instellingen`.

`jaargang_bestanden.pad` is **altijd relatief** ten opzichte van `opslag_pad()` en wordt
uitsluitend via `opslag_absoluut_pad()` naar een absoluut pad omgezet.

## Beveiligingsuitgangspunten

1. Geen user enumeration: `index.php` toont altijd dezelfde melding, ongeacht of het adres
   bekend is.
2. Inlogcodes worden nooit als platte tekst opgeslagen —
   `hash_hmac('sha256', $code, otp_pepper())`.
3. Codes: 6 cijfers, standaard 10 minuten geldig, maximaal 5 verificatiepogingen, eenmalig
   bruikbaar, gebonden aan de browsersessie via `challenge_id`.
4. Rate limiting per e-mailadres én per IP via de tabel `aanvraag_limiet`.
5. Bestandspaden komen nooit uit gebruikersinvoer: id → database → `opslag_absoluut_pad()`.
6. `session_regenerate_id(true)` bij elke inlog.
7. Codepogingen worden ook per IP-adres geremd (twintig per kwartier), naast de
   pogingenteller per code.

## Let op bij installatie

`APP_URL` moet exact overeenkomen met het adres waarop het portaal draait. Wijkt het af,
dan wijzen alle omleidingen naar een andere origin en weigeren browsers formulieren te
versturen vanwege `form-action 'self'` in de Content-Security-Policy — met als gevolg dat
niemand kan inloggen. `app_url_afwijking()` signaleert dit; het beheeroverzicht toont er
een waarschuwing over. De regel mag ook leeg blijven: dan leidt het portaal het adres af
uit het verzoek.
