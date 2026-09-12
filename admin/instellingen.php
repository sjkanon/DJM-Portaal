<?php

/**
 * Beheer > Instellingen — portaal, e-mail, inloggen, mailsjablonen.
 *
 * Bevat ook de drie testknoppen: een testmail versturen, de Graph-configuratie
 * controleren met GraphMailer::diagnoseConfiguration(), en de uitleveringslaag
 * echt uitproberen met zelftest_uitvoeren().
 */

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/email_helper.php';
require_once dirname(__DIR__) . '/includes/uitlevering_helper.php';

vereis_installatie();
$beheerder = vereis_beheerder();

const GEHEIM_PLAATSHOUDER = '••••••••';

/** Leest een tekstveld uit de POST, ingekort tot een maximumlengte. */
function inst_tekst(string $veld, int $maxLengte = 255): string
{
    return mb_substr(trim((string)($_POST[$veld] ?? '')), 0, $maxLengte);
}

/** Leest een getalveld en klemt het tussen een minimum en een maximum. */
function inst_getal(string $veld, int $min, int $max, int $standaard): int
{
    $waarde = trim((string)($_POST[$veld] ?? ''));
    if ($waarde === '' || !is_numeric($waarde)) {
        return $standaard;
    }
    return max($min, min($max, (int)$waarde));
}

/** Leest een checkbox als '1' of '0'. */
function inst_vinkje(string $veld): string
{
    return !empty($_POST[$veld]) ? '1' : '0';
}

// ─── Logo-upload ─────────────────────────────────────────────────────────────

/** Maximale grootte van een geüpload logo: 2 MB. */
const LOGO_MAX_BYTES = 2097152;

/** Extensies waaronder een geüpload logo kan staan. */
const LOGO_EXTENSIES = ['png', 'jpg', 'webp', 'svg'];

/** Absoluut pad van de map waarin het logo terechtkomt. */
function logo_map(): string
{
    return APP_ROOT . '/assets';
}

/** Het geüploade logo dat er nu staat, of null als er geen is. */
function logo_bestandsnaam(): ?string
{
    foreach (LOGO_EXTENSIES as $ext) {
        if (is_file(logo_map() . '/logo.' . $ext)) {
            return 'logo.' . $ext;
        }
    }
    return null;
}

/** Gooit elk eerder geüpload logo weg, zodat er altijd maar één overblijft. */
function logo_bestanden_verwijderen(): void
{
    foreach (LOGO_EXTENSIES as $ext) {
        $pad = logo_map() . '/logo.' . $ext;
        if (is_file($pad)) {
            @unlink($pad);
        }
    }
}

/**
 * Maakt de inhoud van een SVG onschadelijk.
 *
 * Een SVG is geen plaatje maar XML, en mag scripts bevatten: <script>-blokken,
 * on*-attributen (onload, onclick, …) en javascript:-URI's worden door de
 * browser uitgevoerd zodra het logo op een pagina staat — met de rechten van de
 * ingelogde bezoeker. Daarom slaan we nooit het aangeleverde bestand zelf op,
 * maar deze gestripte versie. Bij twijfel over de inhoud weigeren we liever
 * helemaal (zie logo_verwerken()).
 */
function svg_schoonmaken(string $inhoud): string
{
    // Volledige <script>-blokken, inclusief hun inhoud, en losse script-tags.
    $inhoud = (string)preg_replace('#<script\b[^>]*>.*?</\s*script\s*>#is', '', $inhoud);
    $inhoud = (string)preg_replace('#<\s*/?\s*script\b[^>]*>#i', '', $inhoud);
    // Gebeurtenis-attributen: onload="…", onclick='…', onmouseover=…
    $inhoud = (string)preg_replace('#\son[a-z-]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#is', '', $inhoud);
    // javascript:-URI's in href, xlink:href of style.
    $inhoud = (string)preg_replace('#javascript\s*:#i', '', $inhoud);
    return $inhoud;
}

/**
 * Verwerkt een geüpload logo: controleert, slaat op als assets/logo.<ext> en
 * zet branding_logo_url op het nieuwe adres.
 *
 * Geeft null terug wanneer alles goed ging óf wanneer er geen bestand is
 * meegestuurd, en anders een foutmelding voor de beheerder.
 */
function logo_verwerken(): ?string
{
    $bestand = $_FILES['logo'] ?? null;
    if (!is_array($bestand) || !isset($bestand['error'])) {
        return null;
    }

    switch ((int)$bestand['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            return null;    // er is simpelweg geen nieuw logo gekozen
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'Het logo is te groot; deze server accepteert maximaal '
                . (string)ini_get('upload_max_filesize') . ' per upload. Kies een kleiner bestand.';
        case UPLOAD_ERR_PARTIAL:
            return 'Het logo is maar half geüpload. Probeer het nog een keer.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'De server heeft geen tijdelijke map voor uploads. Vraag uw hostingpartij hiernaar.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'De server kon het geüploade logo niet wegschrijven.';
        case UPLOAD_ERR_EXTENSION:
            return 'Een PHP-extensie heeft de upload van het logo geblokkeerd.';
        default:
            return 'De upload van het logo is mislukt (foutcode ' . (int)$bestand['error'] . ').';
    }

    $tijdelijk = (string)($bestand['tmp_name'] ?? '');
    if ($tijdelijk === '' || !is_uploaded_file($tijdelijk)) {
        return 'Het geüploade logo is niet gevonden. Probeer het nog een keer.';
    }

    $grootte = (int)($bestand['size'] ?? 0);
    if ($grootte <= 0) {
        return 'Het geüploade logo is leeg.';
    }
    if ($grootte > LOGO_MAX_BYTES) {
        return 'Het logo is ' . formatteer_bytes($grootte) . '; maximaal '
            . formatteer_bytes(LOGO_MAX_BYTES) . ' is toegestaan.';
    }

    // De extensie zegt niets: we kijken naar de daadwerkelijke inhoud.
    $soorten = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'];
    $info    = @getimagesize($tijdelijk);
    $extensie = ($info !== false && isset($soorten[(int)($info[2] ?? 0)]))
        ? $soorten[(int)$info[2]] : null;

    $svgInhoud = null;
    if ($extensie === null) {
        // Geen bitmap: dan moet het een SVG zijn — beginnend met een
        // XML-declaratie of meteen met de <svg>-tag.
        $ruw = (string)@file_get_contents($tijdelijk, false, null, 0, LOGO_MAX_BYTES);
        $kop = ltrim(preg_replace('/^\xEF\xBB\xBF/', '', $ruw) ?? '');
        if ($ruw === '' || (!str_starts_with($kop, '<?xml') && stripos($kop, '<svg') !== 0)) {
            return 'Dit bestand is geen PNG, JPEG, WEBP of SVG. Kies een ander bestand.';
        }
        if (stripos($ruw, '<svg') === false) {
            return 'Dit SVG-bestand bevat geen <svg>-element. Kies een ander bestand.';
        }
        // Externe entiteiten (XXE) kunnen we niet veilig opschonen: weigeren.
        if (stripos($ruw, '<!entity') !== false) {
            return 'Dit SVG-bestand bevat XML-entiteiten en wordt om veiligheidsredenen geweigerd.';
        }
        $svgInhoud = svg_schoonmaken($ruw);
        $extensie  = 'svg';
    }

    $map = logo_map();
    if (!is_dir($map) && !@mkdir($map, 0775, true) && !is_dir($map)) {
        return 'De map assets/ kon niet worden aangemaakt; het logo is niet opgeslagen.';
    }
    if (!is_writable($map)) {
        return 'De map assets/ is niet schrijfbaar; het logo is niet opgeslagen.';
    }

    // Eerst opruimen, zodat er nooit twee logo's met verschillende extensie zijn.
    logo_bestanden_verwijderen();

    $naam = 'logo.' . $extensie;
    $doel = $map . '/' . $naam;
    $gelukt = $svgInhoud !== null
        ? @file_put_contents($doel, $svgInhoud) !== false
        : @move_uploaded_file($tijdelijk, $doel);
    if (!$gelukt) {
        return 'Het logo kon niet worden opgeslagen in de map assets/.';
    }
    @chmod($doel, 0644);

    // Cachebuster, zodat een vervangen logo meteen zichtbaar is.
    instelling_opslaan('branding_logo_url', url('assets/' . $naam) . '?v=' . time());
    return null;
}

// ═══════════════════════════════════════════════════════════════════════════
//  Verwerking (POST)
// ═══════════════════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    vereis_csrf();
    $actie = (string)($_POST['actie'] ?? '');

    // ─── Alle instellingen opslaan ─────────────────────────────────────────
    if ($actie === 'opslaan') {
        $waarschuwingen = [];

        // Portaal
        instelling_opslaan('portaal_naam', inst_tekst('portaal_naam', 150) ?: APP_NAME);
        instelling_opslaan('portaal_welkomst', mb_substr(trim((string)($_POST['portaal_welkomst'] ?? '')), 0, 2000));
        instelling_opslaan('portaal_ingeschakeld', inst_vinkje('portaal_ingeschakeld'));

        $kleur = inst_tekst('branding_kleur', 7);
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $kleur)) {
            $waarschuwingen[] = 'De merkkleur is geen geldige hexcode (#rrggbb); de oude waarde is behouden.';
        } else {
            instelling_opslaan('branding_kleur', strtolower($kleur));
        }

        $logo = inst_tekst('branding_logo_url', 500);
        if ($logo !== '' && !filter_var($logo, FILTER_VALIDATE_URL) && !str_starts_with($logo, '/')) {
            $waarschuwingen[] = 'De logo-URL is niet herkend als adres; de oude waarde is behouden.';
        } else {
            instelling_opslaan('branding_logo_url', $logo);
        }

        // Een geüpload logo overschrijft de URL hierboven.
        $logoFout = logo_verwerken();
        if ($logoFout !== null) {
            $waarschuwingen[] = $logoFout;
        }

        // Contact
        $contactEmail = normaliseer_email(inst_tekst('contact_email', 190));
        if ($contactEmail !== '' && !geldig_email($contactEmail)) {
            $waarschuwingen[] = 'Het contactadres is geen geldig e-mailadres; de oude waarde is behouden.';
        } else {
            instelling_opslaan('contact_email', $contactEmail);
        }
        instelling_opslaan('contact_tekst', inst_tekst('contact_tekst', 255));

        // E-mail
        $methode = (string)($_POST['email_methode'] ?? 'graph');
        instelling_opslaan('email_methode', in_array($methode, ['graph', 'smtp'], true) ? $methode : 'graph');

        instelling_opslaan('graph_tenant_id', inst_tekst('graph_tenant_id', 100));
        instelling_opslaan('graph_client_id', inst_tekst('graph_client_id', 100));

        // Leeg gelaten geheimen laten de bestaande waarde staan.
        $secret = trim((string)($_POST['graph_client_secret'] ?? ''));
        if ($secret !== '' && $secret !== GEHEIM_PLAATSHOUDER) {
            instelling_opslaan('graph_client_secret', mb_substr($secret, 0, 255));
        }

        $vanAdres = normaliseer_email(inst_tekst('email_van_adres', 190));
        if ($vanAdres !== '' && !geldig_email($vanAdres)) {
            $waarschuwingen[] = 'Het afzenderadres is geen geldig e-mailadres; de oude waarde is behouden.';
        } else {
            instelling_opslaan('email_van_adres', $vanAdres);
        }
        instelling_opslaan('email_van_naam', inst_tekst('email_van_naam', 150));

        instelling_opslaan('smtp_host', inst_tekst('smtp_host', 190));
        instelling_opslaan('smtp_poort', (string)inst_getal('smtp_poort', 1, 65535, 587));
        $beveiliging = (string)($_POST['smtp_beveiliging'] ?? 'tls');
        instelling_opslaan(
            'smtp_beveiliging',
            in_array($beveiliging, ['tls', 'ssl', 'geen'], true) ? $beveiliging : 'tls'
        );
        instelling_opslaan('smtp_gebruikersnaam', inst_tekst('smtp_gebruikersnaam', 190));

        $smtpWachtwoord = trim((string)($_POST['smtp_wachtwoord'] ?? ''));
        if ($smtpWachtwoord !== '' && $smtpWachtwoord !== GEHEIM_PLAATSHOUDER) {
            instelling_opslaan('smtp_wachtwoord', mb_substr($smtpWachtwoord, 0, 255));
        }

        // Inloggen
        instelling_opslaan('otp_geldigheid_minuten', (string)inst_getal('otp_geldigheid_minuten', 2, 60, 10));
        instelling_opslaan('otp_max_pogingen', (string)inst_getal('otp_max_pogingen', 3, 10, 5));
        instelling_opslaan('otp_max_per_email', (string)inst_getal('otp_max_per_email', 1, 20, 3));
        instelling_opslaan('otp_max_per_ip', (string)inst_getal('otp_max_per_ip', 1, 200, 10));
        instelling_opslaan('otp_bind_browser', inst_vinkje('otp_bind_browser'));
        instelling_opslaan('sessie_duur_minuten', (string)inst_getal('sessie_duur_minuten', 15, 1440, 120));
        instelling_opslaan('remember_toestaan', inst_vinkje('remember_toestaan'));
        instelling_opslaan('remember_dagen', (string)inst_getal('remember_dagen', 1, 365, 30));
        instelling_opslaan('log_bewaartermijn_dagen', (string)inst_getal('log_bewaartermijn_dagen', 7, 3650, 365));

        // Mailsjablonen
        instelling_opslaan('mail_onderwerp_code', inst_tekst('mail_onderwerp_code', 255));
        instelling_opslaan('mail_tekst_code', mb_substr(trim((string)($_POST['mail_tekst_code'] ?? '')), 0, 5000));
        instelling_opslaan('mail_onderwerp_uitnodiging', inst_tekst('mail_onderwerp_uitnodiging', 255));
        instelling_opslaan('mail_tekst_uitnodiging', mb_substr(trim((string)($_POST['mail_tekst_uitnodiging'] ?? '')), 0, 5000));

        flash('success', 'De instellingen zijn opgeslagen.');
        foreach ($waarschuwingen as $waarschuwing) {
            flash('warning', $waarschuwing);
        }
        header('Location: ' . url('admin/instellingen.php'));
        exit;
    }

    // ─── Geüpload logo verwijderen ─────────────────────────────────────────
    if ($actie === 'logo_verwijderen') {
        logo_bestanden_verwijderen();
        instelling_opslaan('branding_logo_url', '');
        flash('success', 'Het logo is verwijderd; het portaal toont weer alleen de naam.');
        header('Location: ' . url('admin/instellingen.php'));
        exit;
    }

    // ─── Testmail versturen ────────────────────────────────────────────────
    if ($actie === 'testmail') {
        $adres = normaliseer_email((string)($_POST['test_adres'] ?? ''));
        if (!geldig_email($adres)) {
            flash('danger', 'Vul een geldig e-mailadres in om naartoe te testen.');
        } else {
            $fouten = [];
            @set_time_limit(120);
            if (verstuur_testmail($adres, $fouten)) {
                $_SESSION['instellingen_test'] = [
                    'soort'  => 'testmail',
                    'gelukt' => true,
                    'adres'  => $adres,
                    'fouten' => $fouten,
                ];
            } else {
                $_SESSION['instellingen_test'] = [
                    'soort'  => 'testmail',
                    'gelukt' => false,
                    'adres'  => $adres,
                    'fouten' => $fouten,
                ];
            }
        }
        header('Location: ' . url('admin/instellingen.php') . '#testen');
        exit;
    }

    // ─── Graph-configuratie controleren ────────────────────────────────────
    if ($actie === 'diagnose') {
        @set_time_limit(120);
        $mailer = mailer_maken();
        if ($mailer instanceof GraphMailer) {
            $_SESSION['instellingen_test'] = [
                'soort'     => 'diagnose',
                'resultaat' => $mailer->diagnoseConfiguration(),
            ];
        } else {
            $_SESSION['instellingen_test'] = [
                'soort'     => 'diagnose',
                'resultaat' => null,
            ];
        }
        header('Location: ' . url('admin/instellingen.php') . '#testen');
        exit;
    }

    // ─── Uitlevering van downloads echt uitproberen ────────────────────────
    if ($actie === 'zelftest') {
        // De server doet in deze test vier HTTP-verzoeken aan zichzelf; de
        // standaardlimiet van 30 seconden is daar krap voor.
        @set_time_limit(180);
        $_SESSION['instellingen_test'] = [
            'soort'     => 'zelftest',
            'resultaat' => zelftest_uitvoeren(),
        ];
        header('Location: ' . url('admin/instellingen.php') . '#testen');
        exit;
    }

    header('Location: ' . url('admin/instellingen.php'));
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
//  Weergave
// ═══════════════════════════════════════════════════════════════════════════

$testResultaat = $_SESSION['instellingen_test'] ?? null;
unset($_SESSION['instellingen_test']);

$alles = instellingen(true);

/** Huidige waarde van een instelling, met terugval. */
function inst_waarde(string $sleutel, string $standaard = ''): string
{
    global $alles;
    $waarde = (string)($alles[$sleutel] ?? '');
    return $waarde !== '' ? $waarde : $standaard;
}

$heeftGraphSecret = trim((string)($alles['graph_client_secret'] ?? '')) !== '';
$heeftSmtpWachtwoord = trim((string)($alles['smtp_wachtwoord'] ?? '')) !== '';
$methode = inst_waarde('email_methode', 'graph');
$kleur   = preg_match('/^#[0-9A-Fa-f]{6}$/', inst_waarde('branding_kleur', '#0d6efd'))
    ? inst_waarde('branding_kleur', '#0d6efd') : '#0d6efd';

// Voorbeeld van het logo: de ingestelde URL, anders een geüpload bestand.
$logoBestand = logo_bestandsnaam();
$huidigLogo  = inst_waarde('branding_logo_url');
if ($huidigLogo === '' && $logoBestand !== null) {
    $huidigLogo = url('assets/' . $logoBestand);
}

admin_start('Instellingen', 'Portaal, e-mail, inloggen en mailsjablonen');
?>

<!-- Eigen formulier voor de verwijderknop; die staat via form="…" in de kaart hieronder. -->
<form method="post" id="logo-verwijderen" class="d-none">
    <?= csrf_field() ?>
    <input type="hidden" name="actie" value="logo_verwijderen">
</form>

<form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="actie" value="opslaan">

    <!-- ─── Portaal ──────────────────────────────────────────────────────── -->
    <div class="kaart p-4 mb-4">
        <h2 class="h6 text-uppercase text-muted mb-3"><i class="bi bi-house me-1"></i>Portaal</h2>

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="portaal_naam">Naam van het portaal</label>
                <input class="form-control" id="portaal_naam" name="portaal_naam" maxlength="150"
                    value="<?= h(inst_waarde('portaal_naam', APP_NAME)) ?>">
                <div class="form-text">Verschijnt in de koptekst, de paginatitels en de e-mails.</div>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="branding_kleur">Merkkleur</label>
                <input class="form-control form-control-color w-100" type="color" id="branding_kleur"
                    name="branding_kleur" value="<?= h($kleur) ?>">
                <div class="form-text">Hexcode <code>#rrggbb</code>.</div>
            </div>
            <div class="col-md-3 d-flex align-items-center">
                <div class="form-check form-switch mt-3">
                    <input class="form-check-input" type="checkbox" id="portaal_ingeschakeld"
                        name="portaal_ingeschakeld" value="1"
                        <?= inst_waarde('portaal_ingeschakeld', '1') === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="portaal_ingeschakeld">Portaal ingeschakeld</label>
                </div>
            </div>
            <div class="col-12">
                <label class="form-label" for="portaal_welkomst">Welkomsttekst op de inlogpagina</label>
                <textarea class="form-control" id="portaal_welkomst" name="portaal_welkomst" rows="3"
                    maxlength="2000"><?= h(inst_waarde('portaal_welkomst')) ?></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="logo">Logo uploaden</label>
                <?php if ($huidigLogo !== ''): ?>
                    <div class="border rounded p-3 mb-2 bg-white d-flex align-items-center gap-3">
                        <img src="<?= h($huidigLogo) ?>" alt="Huidig logo" style="max-height:64px; max-width:220px;">
                        <button class="btn btn-outline-danger btn-sm ms-auto" type="submit"
                            form="logo-verwijderen">
                            <i class="bi bi-trash me-1"></i>Logo verwijderen
                        </button>
                    </div>
                <?php endif; ?>
                <input class="form-control" type="file" id="logo" name="logo"
                    accept="image/png,image/jpeg,image/webp,image/svg+xml">
                <div class="form-text">
                    PNG, JPEG, WEBP of SVG, maximaal <?= h(formatteer_bytes(LOGO_MAX_BYTES)) ?>.
                    Het bestand komt in <code>assets/</code> te staan en overschrijft de logo-URL hiernaast.
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="branding_logo_url">Logo-URL</label>
                <input class="form-control" id="branding_logo_url" name="branding_logo_url" maxlength="500"
                    value="<?= h(inst_waarde('branding_logo_url')) ?>"
                    placeholder="https://… of /assets/logo.png">
                <div class="form-text">
                    Alternatief voor wie het logo elders host. Laat leeg om alleen de naam te tonen.
                </div>
            </div>

            <div class="col-md-6">
                <label class="form-label" for="contact_email">Contactadres</label>
                <input class="form-control" type="email" id="contact_email" name="contact_email" maxlength="190"
                    value="<?= h(inst_waarde('contact_email')) ?>" placeholder="hulp@example.nl">
                <div class="form-text">
                    Verschijnt onderaan de inlogpagina en bij een leeg videokoverzicht. Laat leeg om niets te tonen.
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="contact_tekst">Tekst bij het contactadres</label>
                <input class="form-control" id="contact_tekst" name="contact_tekst" maxlength="255"
                    value="<?= h(inst_waarde('contact_tekst', 'Lukt het inloggen niet? Neem contact met ons op.')) ?>">
            </div>
        </div>
    </div>

    <!-- ─── E-mail ───────────────────────────────────────────────────────── -->
    <div class="kaart p-4 mb-4">
        <h2 class="h6 text-uppercase text-muted mb-3"><i class="bi bi-envelope me-1"></i>E-mail</h2>

        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label" for="email_methode">Verzendmethode</label>
                <select class="form-select" id="email_methode" name="email_methode">
                    <option value="graph" <?= $methode === 'graph' ? 'selected' : '' ?>>Microsoft Graph (aanbevolen)</option>
                    <option value="smtp" <?= $methode === 'smtp' ? 'selected' : '' ?>>SMTP</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="email_van_adres">Afzenderadres</label>
                <input class="form-control" type="email" id="email_van_adres" name="email_van_adres"
                    maxlength="190" value="<?= h(inst_waarde('email_van_adres')) ?>"
                    placeholder="noreply@example.nl">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="email_van_naam">Afzendernaam</label>
                <input class="form-control" id="email_van_naam" name="email_van_naam" maxlength="150"
                    value="<?= h(inst_waarde('email_van_naam', APP_NAME)) ?>">
            </div>
        </div>

        <hr class="my-4">
        <h3 class="h6 mb-3">Microsoft Graph</h3>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label" for="graph_tenant_id">Tenant-ID</label>
                <input class="form-control" id="graph_tenant_id" name="graph_tenant_id" maxlength="100"
                    value="<?= h(inst_waarde('graph_tenant_id')) ?>" autocomplete="off">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="graph_client_id">Client-ID</label>
                <input class="form-control" id="graph_client_id" name="graph_client_id" maxlength="100"
                    value="<?= h(inst_waarde('graph_client_id')) ?>" autocomplete="off">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="graph_client_secret">Client secret</label>
                <input class="form-control" type="password" id="graph_client_secret" name="graph_client_secret"
                    autocomplete="new-password"
                    placeholder="<?= $heeftGraphSecret ? h(GEHEIM_PLAATSHOUDER) : 'nog niet ingesteld' ?>">
                <div class="form-text">
                    <?= $heeftGraphSecret
                        ? 'Er is een secret opgeslagen. Laat dit veld leeg om het te behouden.'
                        : 'Nog geen secret opgeslagen.' ?>
                </div>
            </div>
        </div>

        <hr class="my-4">
        <h3 class="h6 mb-3">SMTP (terugvaloptie)</h3>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label" for="smtp_host">Server</label>
                <input class="form-control" id="smtp_host" name="smtp_host" maxlength="190"
                    value="<?= h(inst_waarde('smtp_host')) ?>" placeholder="smtp.example.nl">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="smtp_poort">Poort</label>
                <input class="form-control" type="number" id="smtp_poort" name="smtp_poort"
                    min="1" max="65535" value="<?= (int)inst_waarde('smtp_poort', '587') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="smtp_beveiliging">Beveiliging</label>
                <?php $bev = inst_waarde('smtp_beveiliging', 'tls'); ?>
                <select class="form-select" id="smtp_beveiliging" name="smtp_beveiliging">
                    <option value="tls" <?= $bev === 'tls' ? 'selected' : '' ?>>STARTTLS</option>
                    <option value="ssl" <?= $bev === 'ssl' ? 'selected' : '' ?>>SSL</option>
                    <option value="geen" <?= $bev === 'geen' ? 'selected' : '' ?>>Geen</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="smtp_gebruikersnaam">Gebruikersnaam</label>
                <input class="form-control" id="smtp_gebruikersnaam" name="smtp_gebruikersnaam" maxlength="190"
                    value="<?= h(inst_waarde('smtp_gebruikersnaam')) ?>" autocomplete="off">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="smtp_wachtwoord">Wachtwoord</label>
                <input class="form-control" type="password" id="smtp_wachtwoord" name="smtp_wachtwoord"
                    autocomplete="new-password"
                    placeholder="<?= $heeftSmtpWachtwoord ? h(GEHEIM_PLAATSHOUDER) : 'nog niet ingesteld' ?>">
            </div>
        </div>
    </div>

    <!-- ─── Inloggen ─────────────────────────────────────────────────────── -->
    <div class="kaart p-4 mb-4">
        <h2 class="h6 text-uppercase text-muted mb-3"><i class="bi bi-shield-lock me-1"></i>Inloggen</h2>

        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label" for="otp_geldigheid_minuten">Code geldig (minuten)</label>
                <input class="form-control" type="number" id="otp_geldigheid_minuten" name="otp_geldigheid_minuten"
                    min="2" max="60" value="<?= (int)inst_waarde('otp_geldigheid_minuten', '10') ?>">
                <div class="form-text">2 tot 60.</div>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="otp_max_pogingen">Max. verificatiepogingen</label>
                <input class="form-control" type="number" id="otp_max_pogingen" name="otp_max_pogingen"
                    min="3" max="10" value="<?= (int)inst_waarde('otp_max_pogingen', '5') ?>">
                <div class="form-text">Per code, 3 tot 10.</div>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="otp_max_per_email">Max. codes per e-mailadres</label>
                <input class="form-control" type="number" id="otp_max_per_email" name="otp_max_per_email"
                    min="1" max="20" value="<?= (int)inst_waarde('otp_max_per_email', '3') ?>">
                <div class="form-text">Binnen het limietvenster.</div>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="otp_max_per_ip">Max. codes per IP-adres</label>
                <input class="form-control" type="number" id="otp_max_per_ip" name="otp_max_per_ip"
                    min="1" max="200" value="<?= (int)inst_waarde('otp_max_per_ip', '10') ?>">
                <div class="form-text">Ruimer instellen bij gedeelde internetverbindingen.</div>
            </div>

            <div class="col-md-3">
                <label class="form-label" for="sessie_duur_minuten">Sessieduur (minuten)</label>
                <input class="form-control" type="number" id="sessie_duur_minuten" name="sessie_duur_minuten"
                    min="15" max="1440" value="<?= (int)inst_waarde('sessie_duur_minuten', '120') ?>">
                <div class="form-text">15 tot 1440 (24 uur).</div>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="remember_dagen">Onthoud dit apparaat (dagen)</label>
                <input class="form-control" type="number" id="remember_dagen" name="remember_dagen"
                    min="1" max="365" value="<?= (int)inst_waarde('remember_dagen', '30') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="log_bewaartermijn_dagen">Logboek bewaren (dagen)</label>
                <input class="form-control" type="number" id="log_bewaartermijn_dagen" name="log_bewaartermijn_dagen"
                    min="7" max="3650" value="<?= (int)inst_waarde('log_bewaartermijn_dagen', '365') ?>">
                <div class="form-text">
                    Gebruikt door <a href="<?= h(url('admin/logboek.php')) ?>">Logboek</a> &rsaquo; Oude regels opruimen.
                </div>
            </div>
            <div class="col-md-3 d-flex align-items-center">
                <div class="form-check form-switch mt-3">
                    <input class="form-check-input" type="checkbox" id="remember_toestaan" name="remember_toestaan"
                        value="1" <?= inst_waarde('remember_toestaan', '1') === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="remember_toestaan">"Onthoud dit apparaat" toestaan</label>
                </div>
            </div>

            <div class="col-12">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="otp_bind_browser" name="otp_bind_browser"
                        value="1" <?= inst_waarde('otp_bind_browser', '1') === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="otp_bind_browser">Code aan de browser binden</label>
                </div>
                <div class="form-text">
                    De code werkt alleen in de browser waarin hij is aangevraagd. Een doorgestuurde code is
                    daardoor onbruikbaar. Zet dit uit als deelnemers de code bijvoorbeeld op hun telefoon
                    lezen en op een andere computer willen invoeren.
                </div>
            </div>
        </div>
    </div>

    <!-- ─── Mailsjablonen ────────────────────────────────────────────────── -->
    <div class="kaart p-4 mb-4">
        <h2 class="h6 text-uppercase text-muted mb-3"><i class="bi bi-file-text me-1"></i>Mailsjablonen</h2>

        <div class="alert alert-light border small mb-4">
            <strong>Plaatshouders:</strong>
            <code>{naam}</code> naam van de ontvanger ·
            <code>{code}</code> de inlogcode ·
            <code>{minuten}</code> geldigheidsduur ·
            <code>{jaar}</code> jaartal van de jaargang ·
            <code>{portaal_naam}</code> naam van het portaal ·
            <code>{url}</code> adres van het portaal.
            <div class="text-muted mt-1">
                <code>{code}</code> en <code>{minuten}</code> werken alleen in de inlogcodemail,
                <code>{jaar}</code> alleen in de uitnodiging.
            </div>
        </div>

        <div class="row g-3">
            <div class="col-12">
                <label class="form-label" for="mail_onderwerp_code">Onderwerp — inlogcode</label>
                <input class="form-control" id="mail_onderwerp_code" name="mail_onderwerp_code" maxlength="255"
                    value="<?= h(inst_waarde('mail_onderwerp_code', 'Uw inlogcode voor {portaal_naam}')) ?>">
            </div>
            <div class="col-12">
                <label class="form-label" for="mail_tekst_code">Tekst — inlogcode</label>
                <textarea class="form-control font-monospace" id="mail_tekst_code" name="mail_tekst_code"
                    rows="6" maxlength="5000"><?= h(inst_waarde('mail_tekst_code')) ?></textarea>
            </div>
            <div class="col-12">
                <label class="form-label" for="mail_onderwerp_uitnodiging">Onderwerp — uitnodiging</label>
                <input class="form-control" id="mail_onderwerp_uitnodiging" name="mail_onderwerp_uitnodiging"
                    maxlength="255"
                    value="<?= h(inst_waarde('mail_onderwerp_uitnodiging', 'Uw video staat klaar — {portaal_naam}')) ?>">
            </div>
            <div class="col-12">
                <label class="form-label" for="mail_tekst_uitnodiging">Tekst — uitnodiging</label>
                <textarea class="form-control font-monospace" id="mail_tekst_uitnodiging"
                    name="mail_tekst_uitnodiging" rows="6"
                    maxlength="5000"><?= h(inst_waarde('mail_tekst_uitnodiging')) ?></textarea>
            </div>
        </div>
    </div>

    <div class="mb-4">
        <button class="btn btn-djm btn-lg" type="submit"><i class="bi bi-save me-1"></i>Instellingen opslaan</button>
    </div>
</form>

<!-- ─── Testen ───────────────────────────────────────────────────────────── -->
<div class="kaart p-4 mb-4" id="testen">
    <h2 class="h6 text-uppercase text-muted mb-3"><i class="bi bi-clipboard-check me-1"></i>Testen</h2>

    <div class="row g-4">
        <div class="col-lg-4">
            <form method="post" class="border rounded p-3 h-100">
                <?= csrf_field() ?>
                <input type="hidden" name="actie" value="testmail">
                <label class="form-label" for="test_adres">Testmail versturen naar</label>
                <div class="input-group">
                    <input class="form-control" type="email" id="test_adres" name="test_adres" required
                        value="<?= h((string)($beheerder['email'] ?? '')) ?>">
                    <button class="btn btn-outline-primary" type="submit">
                        <i class="bi bi-send me-1"></i>Versturen
                    </button>
                </div>
                <div class="form-text">
                    Gebruikt de instellingen zoals ze nu zijn <strong>opgeslagen</strong>.
                    Sla wijzigingen dus eerst op.
                </div>
            </form>
        </div>
        <div class="col-lg-4">
            <form method="post" class="border rounded p-3 h-100">
                <?= csrf_field() ?>
                <input type="hidden" name="actie" value="diagnose">
                <label class="form-label">Graph-configuratie controleren</label>
                <p class="form-text mt-0">
                    Haalt een token op, leest de rollen erin uit en probeert de postbus te bereiken.
                    Er wordt geen e-mail verstuurd.
                </p>
                <button class="btn btn-outline-primary" type="submit">
                    <i class="bi bi-activity me-1"></i>Controleren
                </button>
            </form>
        </div>
        <div class="col-lg-4">
            <form method="post" class="border rounded p-3 h-100">
                <?= csrf_field() ?>
                <input type="hidden" name="actie" value="zelftest">
                <label class="form-label">Uitlevering van downloads uitproberen</label>
                <p class="form-text mt-0">
                    Zet kort een testbestand in de opslagmap en haalt het op via dezelfde route
                    als een echte video: volledig, hervat, en een onmogelijk bereik. Ruimt
                    zichzelf daarna op.
                </p>
                <button class="btn btn-outline-primary" type="submit">
                    <i class="bi bi-hdd-network me-1"></i>Uitproberen
                </button>
            </form>
        </div>
    </div>

    <?php if (is_array($testResultaat) && ($testResultaat['soort'] ?? '') === 'testmail'): ?>
        <div class="alert alert-<?= !empty($testResultaat['gelukt']) ? 'success' : 'danger' ?> mt-4 mb-0">
            <?php if (!empty($testResultaat['gelukt'])): ?>
                <i class="bi bi-check-circle me-1"></i>
                Testmail verstuurd naar <strong><?= h((string)$testResultaat['adres']) ?></strong>.
                Komt hij niet aan? Kijk in de map ongewenste e-mail en in
                <a href="<?= h(url('admin/logboek.php?tab=mails')) ?>" class="alert-link">Logboek &rsaquo; E-mail</a>.
            <?php else: ?>
                <i class="bi bi-x-circle me-1"></i>
                <strong>Versturen mislukt.</strong>
                <?php if (!empty($testResultaat['fouten'])): ?>
                    <ul class="mb-0 mt-2 small">
                        <?php foreach ((array)$testResultaat['fouten'] as $fout): ?>
                            <li><?= h((string)$fout) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    Geen nadere foutmelding beschikbaar.
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (is_array($testResultaat) && ($testResultaat['soort'] ?? '') === 'diagnose'): ?>
        <?php $diagnose = $testResultaat['resultaat'] ?? null; ?>
        <div class="mt-4">
            <?php if (!is_array($diagnose)): ?>
                <div class="alert alert-info mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    De verzendmethode staat op <strong>SMTP</strong>. De Graph-diagnose is alleen beschikbaar
                    wanneer Microsoft Graph is gekozen. Gebruik hierboven de testmail om SMTP te controleren.
                </div>
            <?php else: ?>
                <h3 class="h6">Resultaat van de Graph-diagnose</h3>
                <div class="table-responsive">
                    <table class="table table-sm tabel-compact align-middle">
                        <tbody>
                            <tr>
                                <th style="width:220px;">Token opgehaald</th>
                                <td>
                                    <?php if (!empty($diagnose['token_ok'])): ?>
                                        <span class="badge text-bg-success">ja</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-danger">nee</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Gevonden rollen</th>
                                <td>
                                    <?php $rollen = (array)($diagnose['roles'] ?? []); ?>
                                    <?php if (!$rollen): ?>
                                        <span class="text-muted">geen rollen in het token</span>
                                    <?php else: ?>
                                        <?php foreach ($rollen as $rol): ?>
                                            <code class="pad me-1"><?= h((string)$rol) ?></code>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Mail.Send aanwezig</th>
                                <td>
                                    <?php if (!empty($diagnose['has_mail_send'])): ?>
                                        <span class="badge text-bg-success">ja</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-danger">nee</span>
                                        <span class="small text-muted ms-1">
                                            Ken in Entra de applicatiepermissie <code>Mail.Send</code> toe
                                            en verleen admin consent.
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Mailbox-status</th>
                                <td>
                                    <?php $status = (int)($diagnose['mailbox_status'] ?? 0); ?>
                                    <span class="badge text-bg-<?= $status === 200 ? 'success' : ($status === 0 ? 'secondary' : 'warning') ?>">
                                        HTTP <?= (int)$status ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <th>Hint</th>
                                <td class="small"><?= h((string)($diagnose['mailbox_hint'] ?? '')) ?: '<span class="text-muted">—</span>' ?></td>
                            </tr>
                            <?php if (!empty($diagnose['errors'])): ?>
                                <tr>
                                    <th>Fouten</th>
                                    <td>
                                        <ul class="mb-0 small text-danger">
                                            <?php foreach ((array)$diagnose['errors'] as $fout): ?>
                                                <li><?= h((string)$fout) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="alert alert-warning mb-0">
                    <i class="bi bi-shield-exclamation me-1"></i>
                    <strong>Let op de reikwijdte van Mail.Send.</strong>
                    Als <em>applicatierechten</em> geeft <code>Mail.Send</code> standaard toegang tot
                    <strong>álle postbussen in de tenant</strong> — de app kan dan namens iedereen mailen.
                    Beperk dat in Exchange Online met een <em>Application Access Policy</em>, zodat deze
                    app uitsluitend vanaf het afzenderadres mag verzenden. De PowerShell-opdrachten
                    (<code>New-ApplicationAccessPolicy</code> en <code>Test-ApplicationAccessPolicy</code>)
                    staan in <code>docs/GRAPH-SETUP.md</code>.
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (is_array($testResultaat) && ($testResultaat['soort'] ?? '') === 'zelftest'): ?>
        <?php $zelf = (array)($testResultaat['resultaat'] ?? []); ?>
        <div class="mt-4">
            <div class="alert alert-<?= !empty($zelf['gelukt']) ? 'success' : 'danger' ?>">
                <?php if (!empty($zelf['gelukt'])): ?>
                    <i class="bi bi-check-circle me-1"></i>
                    <strong>De uitlevering werkt.</strong>
                    Een echte video volgt precies deze route.
                <?php else: ?>
                    <i class="bi bi-x-circle me-1"></i>
                    <strong>De uitlevering werkt nog niet volledig.</strong>
                    Zolang dit niet klopt, lopen downloads bij deelnemers mis — vaak pas zichtbaar
                    bij een groot bestand. De configuratie hieronder hoort erbij.
                <?php endif; ?>
            </div>

            <div class="table-responsive">
                <table class="table table-sm tabel-compact align-middle">
                    <tbody>
                        <tr>
                            <th style="width:240px;">Gebruikte methode</th>
                            <td>
                                <code class="pad"><?= h((string)($zelf['methode'] ?? '')) ?></code>
                                <?php if (($zelf['gemeld'] ?? '') !== ''): ?>
                                    <span class="small text-muted ms-1">Zoals het antwoord zelf meldde.</span>
                                <?php elseif (empty($zelf['antwoord'])): ?>
                                    <span class="small text-muted ms-1">
                                        Uit eigen detectie; er kwam geen antwoord om het aan af te lezen.
                                    </span>
                                <?php else: ?>
                                    <span class="small text-muted ms-1">
                                        Uit eigen detectie; het antwoord zelf verried de route niet.
                                        Er zit dan waarschijnlijk een proxy of cache tussen die zowel
                                        de eigen header als de bestandsnaam herschrijft.
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Getest adres</th>
                            <td><code class="pad"><?= h((string)($zelf['basis'] ?? '')) ?>/zelftest.php</code></td>
                        </tr>
                        <?php foreach ((array)($zelf['stappen'] ?? []) as $stapRij): ?>
                            <tr>
                                <th><?= h((string)$stapRij['naam']) ?></th>
                                <td>
                                    <?php if ($stapRij['gelukt'] === true): ?>
                                        <span class="badge text-bg-success">goed</span>
                                    <?php elseif ($stapRij['gelukt'] === false): ?>
                                        <span class="badge text-bg-danger">mislukt</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-secondary">overgeslagen</span>
                                    <?php endif; ?>
                                    <div class="small text-muted"><?= h((string)$stapRij['detail']) ?></div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (!empty($zelf['onveilig'])): ?>
                <div class="alert alert-warning mb-0">
                    <i class="bi bi-shield-exclamation me-1"></i>
                    Het TLS-certificaat van <code><?= h((string)($zelf['basis'] ?? '')) ?></code> kon niet
                    worden gecontroleerd; de test is daarna zonder certificaatcontrole gedraaid. De
                    uitlevering is dus wel getest, maar browsers zullen het portaal onveilig noemen
                    totdat het certificaat klopt.
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ─── Serverconfiguratie ───────────────────────────────────────────────── -->
<?php
$configMethode = download_methode();
$configBlokken = [
    'xaccel'    => uitlevering_serverconfig('xaccel'),
    'xsendfile' => uitlevering_serverconfig('xsendfile'),
    'php'       => uitlevering_serverconfig('php'),
];
?>
<div class="kaart p-4 mb-4" id="serverconfig">
    <h2 class="h6 text-uppercase text-muted mb-3"><i class="bi bi-file-earmark-code me-1"></i>Serverconfiguratie</h2>
    <p class="small text-muted">
        Het blok hieronder hoort bij de uitleveringsmethode en heeft de paden van déze installatie
        al ingevuld — opslagmap en prefix hoeft u dus niet zelf over te nemen. Deze installatie
        gebruikt nu <strong><?= h($configMethode) ?></strong>; dat tabblad staat open.
        De volledige voorbeeldconfiguraties staan in <code>docs/</code>.
    </p>

    <ul class="nav nav-pills mb-3" role="tablist">
        <?php foreach ($configBlokken as $sleutel => $blok): ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $sleutel === $configMethode ? 'active' : '' ?>"
                    data-bs-toggle="pill" data-bs-target="#cfg-<?= h($sleutel) ?>" type="button" role="tab">
                    <?= h($blok['titel']) ?>
                </button>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="tab-content">
        <?php foreach ($configBlokken as $sleutel => $blok): ?>
            <div class="tab-pane fade <?= $sleutel === $configMethode ? 'show active' : '' ?>"
                id="cfg-<?= h($sleutel) ?>" role="tabpanel">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                    <div class="small text-muted"><?= h($blok['waar']) ?></div>
                    <button class="btn btn-sm btn-outline-secondary" type="button"
                        data-kopieer="cfg-tekst-<?= h($sleutel) ?>">
                        <i class="bi bi-clipboard me-1"></i>Kopiëren
                    </button>
                </div>
                <pre class="border rounded bg-light p-3 mb-2 small" id="cfg-tekst-<?= h($sleutel) ?>"><?= h($blok['tekst']) ?></pre>
                <p class="small text-muted mb-0"><?= h($blok['uitleg']) ?></p>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- De kopieerknoppen hierboven worden afgehandeld in admin/assets/admin.js;
     de Content-Security-Policy staat geen scriptblok in de pagina zelf toe. -->

<!-- ─── Technische status ────────────────────────────────────────────────── -->
<?php
$opslag        = opslag_pad();
$opslagBestaat = is_dir($opslag);
$opslagSchrijf = $opslagBestaat && is_writable($opslag);
$deliveryMode  = env('DELIVERY_MODE', 'auto');
$serverSoftware = (string)($_SERVER['SERVER_SOFTWARE'] ?? 'onbekend');

// Zelfde detectie als de downloadlaag: nginx -> X-Accel, Apache -> X-Sendfile.
if ($deliveryMode !== 'auto') {
    $gedetecteerd = $deliveryMode;
} elseif (stripos($serverSoftware, 'nginx') !== false) {
    $gedetecteerd = 'xaccel';
} elseif (stripos($serverSoftware, 'apache') !== false || stripos($serverSoftware, 'lighttpd') !== false) {
    $gedetecteerd = 'xsendfile (indien de module geladen is), anders php';
} else {
    $gedetecteerd = 'php';
}

$heeftAppKey = env('APP_KEY') !== '';
$heeftPepper = env('OTP_PEPPER') !== '';

$assetsMap     = logo_map();
$assetsBestaat = is_dir($assetsMap);
$assetsSchrijf = $assetsBestaat ? is_writable($assetsMap) : is_writable(dirname($assetsMap));

/** Telt de rijen in een tabel; null als de tabel niet te lezen is. */
function inst_aantal(string $tabel): ?int
{
    try {
        return (int)db()->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $tabel) . '`')->fetchColumn();
    } catch (Throwable $e) {
        return null;
    }
}

$aantalJaargangen = inst_aantal('jaargangen');
$aantalBestanden  = inst_aantal('jaargang_bestanden');
$aantalDeelnemers = inst_aantal('deelnemers');
?>
<div class="kaart p-4">
    <h2 class="h6 text-uppercase text-muted mb-3"><i class="bi bi-cpu me-1"></i>Technische status</h2>
    <div class="table-responsive">
        <table class="table table-sm tabel-compact align-middle mb-0">
            <tbody>
                <tr>
                    <th style="width:260px;">PHP-versie</th>
                    <td><code class="pad"><?= h(PHP_VERSION) ?></code>
                        <?php if (version_compare(PHP_VERSION, '8.1.0', '<')): ?>
                            <span class="badge text-bg-danger ms-1">te oud, 8.1 of hoger vereist</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Applicatieversie</th>
                    <td><code class="pad"><?= h(APP_VERSION) ?></code></td>
                </tr>
                <tr>
                    <th>Opslagmap</th>
                    <td>
                        <code class="pad"><?= h($opslag) ?></code><br>
                        <?php if (!$opslagBestaat): ?>
                            <span class="badge text-bg-danger">bestaat niet</span>
                        <?php elseif (!$opslagSchrijf): ?>
                            <span class="badge text-bg-warning">bestaat, niet schrijfbaar</span>
                            <span class="small text-muted ms-1">
                                Alleen nodig als u via de browser wilt uploaden.
                            </span>
                        <?php else: ?>
                            <span class="badge text-bg-success">bestaat en is schrijfbaar</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Map assets/ (logo-upload)</th>
                    <td>
                        <code class="pad"><?= h($assetsMap) ?></code><br>
                        <?php if ($assetsBestaat && $assetsSchrijf): ?>
                            <span class="badge text-bg-success">bestaat en is schrijfbaar</span>
                        <?php elseif ($assetsBestaat): ?>
                            <span class="badge text-bg-warning">bestaat, niet schrijfbaar</span>
                            <span class="small text-muted ms-1">Een logo uploaden lukt zo niet.</span>
                        <?php elseif ($assetsSchrijf): ?>
                            <span class="badge text-bg-secondary">bestaat nog niet</span>
                            <span class="small text-muted ms-1">
                                Wordt bij de eerste logo-upload aangemaakt.
                            </span>
                        <?php else: ?>
                            <span class="badge text-bg-warning">kan niet worden aangemaakt</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Inhoud van deze installatie</th>
                    <td>
                        <?= $aantalJaargangen === null ? '—' : (int)$aantalJaargangen ?> jaargangen ·
                        <?= $aantalBestanden === null ? '—' : (int)$aantalBestanden ?> bestanden ·
                        <?= $aantalDeelnemers === null ? '—' : (int)$aantalDeelnemers ?> deelnemers
                    </td>
                </tr>
                <tr>
                    <th>Uitlevering van downloads</th>
                    <td>
                        <code class="pad">DELIVERY_MODE=<?= h($deliveryMode) ?></code> &rarr;
                        <strong><?= h($gedetecteerd) ?></strong><br>
                        <span class="small text-muted">
                            Webserver: <code class="pad"><?= h($serverSoftware) ?></code>
                        </span>
                    </td>
                </tr>
                <tr>
                    <th>APP_KEY in .env</th>
                    <td>
                        <?php if ($heeftAppKey): ?>
                            <span class="badge text-bg-success">ingesteld</span>
                        <?php else: ?>
                            <span class="badge text-bg-warning">ontbreekt</span>
                            <span class="small text-muted ms-1">
                                Er wordt nu een afgeleide sleutel gebruikt. Zet een eigen waarde in
                                <code>.env</code>: <code>php -r "echo bin2hex(random_bytes(32));"</code>
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>OTP_PEPPER in .env</th>
                    <td>
                        <?php if ($heeftPepper): ?>
                            <span class="badge text-bg-success">ingesteld</span>
                        <?php else: ?>
                            <span class="badge text-bg-warning">ontbreekt</span>
                            <span class="small text-muted ms-1">
                                Zonder eigen pepper worden inlogcodes gehasht met een afgeleide sleutel.
                                Wijzigen maakt openstaande codes ongeldig.
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<?php admin_eind(); ?>
