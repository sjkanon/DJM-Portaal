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
logo.php                    het geüploade logo, met een sandbox-policy (geen sessie)
portaal/index.php           overzicht jaargangen van de ingelogde deelnemer
includes/auth.php           sessies, CSRF, deelnemer- en beheerderidentiteit
includes/otp.php            codes genereren, versturen, verifiëren, throttling
includes/email_helper.php   GraphMailer + SimpleMailer + mailsjablonen
includes/toegang_helper.php wie mag welke jaargang / welk bestand
includes/download_helper.php uitlevering: X-Accel / X-Sendfile / PHP-stream
includes/webserverlog_helper.php achteraf uitlezen hoeveel de webserver verstuurde
includes/uitlevering_helper.php serverconfig tonen + de uitlevering echt uitproberen
includes/layout.php         portaal_start() / portaal_eind() voor het portaal
includes/beheerder_helper.php  rem op pogingen, links om een wachtwoord te kiezen
includes/bestand_helper.php opslagmap scannen, bestanden koppelen, upload in delen
admin/includes/layout.php   admin_start() / admin_eind() / admin_login_start()
admin/bestanden.php         video uploaden, bestanden koppelen en opruimen
admin/upload.php            JSON-eindpunt voor de upload in delen (admin/assets/upload.js)
admin/bestandscontrole.php  controle: staan alle gekoppelde bestanden er nog?
admin/bericht.php           vrije tekst naar een groep deelnemers, met voorbeeld en bevestiging
admin/handleiding.php       handleiding voor beheerders, met schermafdrukken
admin/beheerders.php        beheerders toevoegen, links sturen, uit- en inschakelen
admin/wachtwoord_vergeten.php  resetlink aanvragen (zonder sessie)
admin/wachtwoord_instellen.php wachtwoord kiezen via de link (zonder sessie)
assets/handleiding/         schermafdrukken voor de handleiding (gemaakt door test/handleiding.sh)
admin/*.php                 beheerinterface
opslag/                     videobestanden en branding/logo.<ext> (niet publiek benaderbaar)
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
`log_login()`, `app_log()`, `limiet_sleutel()`, `stuur_security_headers()`, `vereis_installatie()`,
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
`beheerder_uitloggen()`, `beheerder_stempel()`, `beheerder_sessie_vergeten()`, `flash()`,
`flash_ophalen()`.

**toegang_helper.php:** `sql_jaargang_zichtbaar()`, `deelnemer_op_email()`,
`deelnemer_aanmaken_of_ophalen()`, `email_heeft_toegang()`, `deelnemer_jaargangen()`,
`deelnemer_bestand()`, `jaargang_aantal_deelnemers()`, `toegang_toekennen()`,
`toegang_intrekken()`, `jaargang_status()`.

**download_helper.php:** `download_link()`, `download_link_net_vernieuwd()`,
`download_handtekening_geldig()`, `download_methode()`, `bestand_mime()`,
`download_log_kolommen()`, `download_loggen()`, `download_log_bijwerken()`,
`download_content_headers()`, `download_kenmerk()`, `download_if_range_geldig()`,
`download_pad_coderen()`, `download_compressie_uit()`, `download_buffering_uit()`,
`download_uitleveren()`, `download_uitleveren_php()`, `download_range_afwijzen()`.

`download_log.reden` legt vast waaróm een download stopte: `bezig` → `voltooid`,
`client_gestopt` of `server_gestopt`, en `webserver` als X-Accel of X-Sendfile uitleverde.
Alleen de PHP-uitlevering kan dit vaststellen, want alleen daar komen de bytes langs PHP;
`bytes_verzonden` wordt tijdens het streamen elke `DOWNLOAD_VOORTGANG` bytes bijgewerkt, zodat
ook een afgeschoten proces laat zien hoe ver iemand kwam. Let op de grens van wat PHP wéét:
`connection_aborted()` zegt dát de verbinding wegviel, niet wie hem verbrak — een proxy die
stopt met lezen ziet er hetzelfde uit als een bezoeker die afhaakt. Het beheerscherm lost dat
op met het patroon: stops die zich om één punt verzamelen zijn de infrastructuur, verspreide
stops zijn de bezoekers.

**webserverlog_helper.php:** `webserverlog_paden()`, `webserverlog_beschikbaar()`,
`webserverlog_regel_ontleden()`, `webserverlog_zoeken()`, `webserverlog_verrijken()`.
Levert de webserver zelf uit, dan meet PHP niets — maar de webserver noteert per verzoek hoeveel
hij verstuurde. `download_log.sleutel` (de eerste zestien tekens van de handtekening uit de
downloadlink) staat ook in die logregel en is per link uniek, dus de koppeling is exact en niet
een gok op tijdstip en IP. Het beheerscherm haalt de aantallen op voor de regels die het toont en
schrijft ze weg, zodat het eenmalig werk is en latere logrotatie niet meer uitmaakt. Het pad komt
uit `WEBSERVER_LOG`; ontbreekt dat, dan wordt de Plesk-indeling geprobeerd. Het moet het log van de
vóórste webserver zijn: staat nginx voor Apache, dan bevat Apaches log alleen het lege antwoord
waarmee PHP de uitlevering doorgaf. Alles hierin is optioneel en mag nooit een fout opleveren.
Een link is `DOWNLOAD_LINK_GELDIG` (twaalf uur) geldig; verloopt hij, dan geeft `download.php`
er een verse voor in de plaats in plaats van een fout te tonen, want de echte autorisatie is de
sessie plus `deelnemer_bestand()`. De PHP-uitlevering stuurt `ETag` en `Last-Modified` mee en
honoreert `If-Range`, zodat een downloadmanager niet twee verschillende versies van een video aan
elkaar plakt, en zet compressie uit: gecomprimeerde uitvoer bij een ongecomprimeerde
`Content-Length` levert een halve, onafspeelbare video op.

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

**opmaak.php:** `branding_kleur()`, `branding_tekstkleur()`, `branding_logo()`, `djm_head()`,
`djm_asset()`, `djm_favicon()`, plus het geüploade logo: `logo_map()`, `logo_oude_map()`,
`logo_pad()`, `logo_bestandsnaam()`, `logo_bestanden_verwijderen()`, `logo_mime()`.
Een geüpload logo staat in `branding/` binnen de opslagmap en gaat uitsluitend via `logo.php`
naar buiten, dat er een `sandbox`-policy op zet. Een SVG is namelijk XML en mag scripts
bevatten; komt zo'n bestand rechtstreeks uit de webroot, dan draait dat binnen onze eigen
origin. `logo_oude_map()` (assets/) is er voor installaties van vóór deze wijziging: hun logo
blijft werken en verhuist bij de eerstvolgende upload.

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

**bestand_helper.php:** `bestand_scan_opslag()`, `bestand_gekoppelde_paden()`, `bestand_koppelen()`,
`bestand_veilige_naam()`, `bestand_vrije_naam()`, `download_veilige_naam()`, `mime_uit_extensie()`,
`upload_start()`, `upload_deel_schrijven()`, `upload_afronden()`, `upload_verwijderen()`,
`upload_openstaand()`, `upload_opruimen()`. Een upload staat tot hij compleet is in
`<opslagmap>/.uploads/<sleutel>.deel`; de scan slaat alles met een punt ervoor over, dus een half
bestand is nooit te koppelen. Wat op schijf staat, is leidend voor de voortgang; de tabel `uploads`
(zo nodig aangemaakt door `upload_tabel()`) onthoudt alleen wat bij welk bestand hoort.
`admin/upload.php` antwoordt met JSON: `vereis_beheerder()` geeft daar een 401 in plaats van een
doorverwijzing zodra het verzoek om JSON vraagt. Gebruik in zo'n eindpunt geen status 419: Apache
maakt van een onbekende status een 500.

**admin/includes/layout.php:** `admin_start($titel, $subtitel = '')`, `admin_eind()`,
`admin_login_start($titel)`, `admin_login_eind()`, `admin_menu()`.

## Datamodel

Zie `db.sql` — dat bestand is leidend. Kern: `jaargangen` 1—n `jaargang_bestanden`,
`deelnemers` n—n `jaargangen` via `toegang`. Verder `login_codes`, `remember_tokens`,
`aanvraag_limiet`, `download_log`, `mail_log`, `login_log`, `beheerders`, `beheerder_tokens`, `uploads`, `instellingen`.

`jaargang_bestanden.pad` is **altijd relatief** ten opzichte van `opslag_pad()` en wordt
uitsluitend via `opslag_absoluut_pad()` naar een absoluut pad omgezet.

## Beveiligingsuitgangspunten

1. Geen user enumeration: `index.php` toont altijd dezelfde melding, ongeacht of het adres
   bekend is.
2. Inlogcodes worden nooit als platte tekst opgeslagen —
   `hash_hmac('sha256', $code, otp_pepper())`.
3. Codes: 6 cijfers, standaard 10 minuten geldig, maximaal 5 verificatiepogingen, eenmalig
   bruikbaar, gebonden aan de browsersessie via `challenge_id`.
4. Rate limiting per e-mailadres én per IP via de tabel `aanvraag_limiet`. De sleutel is
   altijd een HMAC met `APP_KEY` (`limiet_sleutel()`, of `admin_email_sleutel()` voor het
   beheer): de tabel telt alleen en hoeft geen e-mailadres of IP-adres te bewaren.
5. Bestandspaden komen nooit uit gebruikersinvoer: id → database → `opslag_absoluut_pad()`.
   `download.php` stuurt nooit door naar een gewone pagina en antwoordt nooit met status 200 op
   iets anders dan bestandsbytes: een browser of downloadmanager bewaart zo'n pagina als bestand,
   en dan staat er een `index.php` van vier kilobyte in de downloadmap in plaats van de video.
   Fouten zijn daarom een status (403/404/410/416) met een kleine foutpagina. De enige omleiding
   die het eindpunt maakt, is naar een verse `download_link()` — die komt weer op hetzelfde
   eindpunt uit en levert dus alsnog de video.
6. `session_regenerate_id(true)` bij elke inlog. De beheersessie draagt daarnaast
   `beheerder_stempel()` mee — een HMAC van de wachtwoordhash — die `huidige_beheerder()` bij elk
   verzoek naast de database legt. Verandert het wachtwoord, dan vervallen alle sessies van dat
   account. Wie `beheerder_inloggen()` aanroept moet dus een rij meegeven met de *actuele*
   `wachtwoord_hash`; `admin/login.php` werkt die bij na een `password_needs_rehash()`.
7. Codepogingen worden ook per IP-adres geremd (twintig per kwartier), naast de
   pogingenteller per code.
8. Elk eindpunt stuurt zijn eigen securityheaders. Voor de gewone pagina's doet
   `portaal_start()` / `admin_start()` dat; `download.php` en `zelftest.php` gebruiken die layouts
   niet en roepen `stuur_security_headers()` daarom zelf aan, meteen na `vereis_installatie()`.
   Wat in `.htaccess` en de voorbeeldconfiguraties staat is een vangnet, geen basis: `.htaccess`
   geldt niet onder nginx, en een los serverblok wordt bij een verhuizing vergeten.

## Let op bij installatie

`APP_URL` moet exact overeenkomen met het adres waarop het portaal draait. Wijkt het af,
dan wijzen alle omleidingen naar een andere origin en weigeren browsers formulieren te
versturen vanwege `form-action 'self'` in de Content-Security-Policy — met als gevolg dat
niemand kan inloggen. `app_url_afwijking()` signaleert dit; het beheeroverzicht toont er
een waarschuwing over. De regel mag ook leeg blijven: dan leidt het portaal het adres af
uit het verzoek.
