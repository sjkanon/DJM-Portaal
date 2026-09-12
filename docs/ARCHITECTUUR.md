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
includes/layout.php         pagina_start() / pagina_eind() voor het portaal
admin/includes/layout.php   admin_start() / admin_eind() / admin_login_start()
admin/bestandscontrole.php  controle: staan alle gekoppelde bestanden er nog?
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

## Beschikbare functies (config.php)

`env()`, `db()`, `db_beschikbaar()`, `tabel_bestaat()`, `instelling()`, `instelling_int()`,
`instelling_bool()`, `instelling_opslaan()`, `instellingen()`, `portaal_naam()`, `h()`,
`formatteer_bytes()`, `formatteer_datum()`, `app_base_url()`, `url()`, `opslag_pad()`,
`opslag_absoluut_pad()`, `normaliseer_email()`, `geldig_email()`, `client_ip()`,
`client_ip_bin()`, `ip_leesbaar()`, `client_user_agent()`, `app_key()`, `otp_pepper()`,
`log_login()`, `app_log()`, `stuur_security_headers()`, `vereis_installatie()`,
`ensure_session_started()`, `destroy_current_session()`, `app_url_afwijking()`.

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
`verstuur_testmail()`, `mail_html_omhulsel()`, `mail_sjabloon_vullen()`.
`GraphMailer` heeft `diagnoseConfiguration(): array` met `token_ok`, `roles`,
`has_mail_send`, `mailbox_status`, `mailbox_hint`, `errors`.

**layout.php:** `pagina_start($titel, ['smal' => true])`, `pagina_eind()`, `toon_fout()`,
`toon_melding()`, `toon_contact()`, `branding_kleur()`, `branding_logo()`.

**admin/includes/layout.php:** `admin_start($titel, $subtitel = '')`, `admin_eind()`,
`admin_login_start($titel)`, `admin_login_eind()`, `admin_menu()`.

## Datamodel

Zie `db.sql` — dat bestand is leidend. Kern: `jaargangen` 1—n `jaargang_bestanden`,
`deelnemers` n—n `jaargangen` via `toegang`. Verder `login_codes`, `remember_tokens`,
`aanvraag_limiet`, `download_log`, `mail_log`, `login_log`, `beheerders`, `instellingen`.

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
