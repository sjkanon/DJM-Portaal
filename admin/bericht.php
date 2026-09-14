<?php

/**
 * Beheer > Bericht — een eigen bericht aan een groep deelnemers.
 *
 * Voor alles wat niet in een sjabloon past: een storing die verholpen is, een
 * gewijzigde datum, een uitleg over het downloaden. Vrije tekst, met dezelfde
 * opmaak als de andere mails uit het portaal.
 *
 * De volgorde is bewust dezelfde als bij de herinnering in Toegang: eerst een
 * voorbeeld met het aantal ontvangers erbij, dan pas versturen. Een bericht
 * gaat naar echte ouders en is niet terug te halen, dus er zit altijd een
 * bevestiging tussen — en er is een knop om het eerst naar uzelf te sturen.
 */

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/toegang_helper.php';
require_once dirname(__DIR__) . '/includes/email_helper.php';

vereis_installatie();
$beheerder = vereis_beheerder();

const BERICHT_MAIL_BLOKKEN = 25;   // aantal mails per blok
const BERICHT_HERHAAL_UREN = 24;   // binnen deze termijn niet nogmaals hetzelfde bericht
const BERICHT_MAX_TEKENS   = 4000;

// ═══════════════════════════════════════════════════════════════════════════
//  Groepen
// ═══════════════════════════════════════════════════════════════════════════

/**
 * De groepen waar een bericht heen kan: een kort label voor de keuzelijst en de
 * volzin voor de bevestigingsstap, waar het juist woordelijk moet kloppen.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function bericht_groepen(): array
{
    return [
        'niet_compleet'  => ['Nog niet compleet opgehaald', 'iedereen die de video nog niet compleet heeft opgehaald'],
        'nooit_ingelogd' => ['Nog nooit ingelogd',          'iedereen die nog nooit heeft ingelogd'],
        'alles'          => ['Iedereen met toegang',        'iedereen met toegang tot deze jaargang'],
    ];
}

/** De volzin bij een groep, voor in een lopende tekst. */
function bericht_groep_omschrijving(string $groep): string
{
    return bericht_groepen()[$groep][1] ?? '';
}

/**
 * Wat er per deelnemer aan downloads van deze jaargang bekend is.
 *
 * `afgerond` is de enige harde uitspraak die het portaal doet: alle bytes zijn
 * de deur uit. Levert de webserver uit (X-Accel of X-Sendfile), dan komt er
 * geen byte langs PHP en blijft dat op 0 staan terwijl de ouder de video wél
 * compleet binnenkreeg. Daarom tellen we die regels apart: het scherm zegt er
 * dan bij dat de groep "niet compleet" te ruim kan zijn.
 */
function bericht_download_join(string $param): string
{
    return '
         LEFT JOIN (
             SELECT dl.deelnemer_id,
                    MAX(dl.afgerond) AS compleet,
                    SUM(CASE WHEN dl.reden = \'webserver\' THEN 1 ELSE 0 END) AS ongemeten,
                    COUNT(*) AS pogingen
             FROM download_log dl
             WHERE dl.deelnemer_id IS NOT NULL
               AND dl.bestand_id IN (
                   SELECT b.id FROM jaargang_bestanden b WHERE b.jaargang_id = ' . $param . '
               )
             GROUP BY dl.deelnemer_id
         ) x ON x.deelnemer_id = d.id';
}

/**
 * De deelnemers in een groep, inclusief de geblokkeerde: die worden pas bij het
 * verdelen eruit gehaald, zodat het scherm kan zeggen hoeveel er zijn.
 */
function bericht_kandidaten(int $jaargangId, string $groep): array
{
    switch ($groep) {
        case 'nooit_ingelogd':
            $waar = ' AND d.laatst_ingelogd_op IS NULL';
            break;
        case 'alles':
            $waar = '';
            break;
        default:
            $waar = ' AND (x.compleet IS NULL OR x.compleet = 0)';
    }

    $stmt = db()->prepare(
        'SELECT d.id, d.email, d.naam, d.geblokkeerd,
                x.compleet, x.ongemeten, x.pogingen
         FROM toegang t
         JOIN deelnemers d ON d.id = t.deelnemer_id'
        . bericht_download_join(':jb') . '
         WHERE t.jaargang_id = :j' . $waar . '
         ORDER BY d.email ASC'
    );
    $stmt->execute([':j' => $jaargangId, ':jb' => $jaargangId]);
    return $stmt->fetchAll();
}

/**
 * Adressen die dit bericht al kregen: hetzelfde onderwerp, verzonden, binnen de
 * termijn. Twee keer op "Versturen" klikken of de pagina herladen mag geen
 * tweede mail opleveren.
 *
 * @return array<string, bool> genormaliseerd adres => true
 */
function bericht_al_gehad(string $onderwerp, int $uren): array
{
    $stmt = db()->prepare(
        'SELECT DISTINCT ontvanger
         FROM mail_log
         WHERE soort = :soort
           AND status = :status
           AND onderwerp = :onderwerp
           AND verzonden_op >= (NOW() - INTERVAL ' . (int)$uren . ' HOUR)'
    );
    $stmt->execute([':soort' => 'bericht', ':status' => 'verzonden', ':onderwerp' => substr($onderwerp, 0, 255)]);

    $adressen = [];
    foreach ($stmt->fetchAll() as $rij) {
        $adressen[normaliseer_email((string)$rij['ontvanger'])] = true;
    }
    return $adressen;
}

/**
 * Verdeelt de kandidaten in: te mailen, geblokkeerd en "kreeg dit bericht al".
 *
 * @return array{ontvangers: array, herhaling: array, geblokkeerd: int, ongemeten: int}
 */
function bericht_verdelen(array $kandidaten, string $onderwerp): array
{
    $alGehad = bericht_al_gehad($onderwerp, BERICHT_HERHAAL_UREN);

    $ontvangers  = [];
    $herhaling   = [];
    $geblokkeerd = 0;
    $ongemeten   = 0;

    foreach ($kandidaten as $rij) {
        if ((int)$rij['geblokkeerd'] === 1) {
            $geblokkeerd++;
            continue;
        }
        if ((int)($rij['ongemeten'] ?? 0) > 0 && (int)($rij['compleet'] ?? 0) === 0) {
            $ongemeten++;
        }
        $persoon = [
            'email' => (string)$rij['email'],
            'naam'  => (string)($rij['naam'] ?? ''),
        ];
        if (isset($alGehad[normaliseer_email($persoon['email'])])) {
            $herhaling[] = $persoon;
        } else {
            $ontvangers[] = $persoon;
        }
    }

    return [
        'ontvangers'  => $ontvangers,
        'herhaling'   => $herhaling,
        'geblokkeerd' => $geblokkeerd,
        'ongemeten'   => $ongemeten,
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
//  De mail zelf
// ═══════════════════════════════════════════════════════════════════════════

/** Het contactadres dat in {contact} terechtkomt. */
function bericht_contact(): string
{
    return trim(instelling('contact_email', ''));
}

/**
 * Onderwerp en tekst waarmee het formulier opent: wat er de vorige keer is
 * verstuurd, en anders het bericht waarvoor dit scherm is gemaakt — een
 * storing die verholpen is. Overschrijf het gerust; wat u verstuurt, staat er
 * de volgende keer weer in.
 */
function bericht_standaard(): array
{
    $standaardTekst =
        "Beste {naam},\n\n"
        . "De afgelopen tijd ging het downloaden van de videoregistratie bij een aantal van u mis. "
        . "De download stopte halverwege, of u hield een klein bestandje over in plaats van de video.\n\n"
        . "Die problemen zijn verholpen. U hoeft niets te doen: ga naar {url}, log in met de "
        . "eenmalige code die u per e-mail krijgt, en start de download opnieuw. Een eerder "
        . "mislukt of onvolledig bestand kunt u weggooien.\n\n"
        . "Lukt het nog steeds niet? Laat het ons weten via {contact}, dan kijken we met u mee.\n\n"
        . "Met vriendelijke groet,\n{portaal_naam}";

    return [
        'onderwerp' => instelling('bericht_laatste_onderwerp', 'De downloadproblemen zijn verholpen — {portaal_naam}'),
        'tekst'     => mail_tekst_normaliseren(instelling('bericht_laatste_tekst', $standaardTekst)),
    ];
}

/** De waarden achter de plaatshouders, voor één ontvanger. */
function bericht_waarden(string $naam, int $jaar): array
{
    return [
        'naam'         => $naam !== '' ? $naam : 'deelnemer',
        'jaar'         => (string)$jaar,
        'portaal_naam' => portaal_naam(),
        'url'          => app_base_url(),
        'contact'      => bericht_contact(),
    ];
}

/**
 * Stuurt het bericht naar één adres. Losse functie in plaats van een variant in
 * email_helper.php: de tekst komt hier uit het formulier en niet uit een
 * sjabloon, dus er valt niets te delen behalve het omhulsel.
 */
function bericht_versturen(
    string $email,
    string $naam,
    string $onderwerp,
    string $tekst,
    int $jaar,
    bool $metKnop,
    array &$fouten = []
): bool {
    $waarden   = bericht_waarden($naam, $jaar);
    $onderwerp = mail_sjabloon_vullen($onderwerp, $waarden);
    $tekst     = mail_sjabloon_vullen($tekst, $waarden);

    $knop = '';
    if ($metKnop) {
        $knop = '<div style="margin:8px 0 20px;text-align:center;">'
            . '<a href="' . h(app_base_url()) . '" style="display:inline-block;padding:12px 26px;'
            . 'background:' . h(branding_kleur())
            . ';color:' . h(branding_tekstkleur()) . ';text-decoration:none;'
            . 'border-radius:8px;font-weight:600;">Naar het portaal</a></div>';
    }

    return verstuur_mail($email, $naam, $onderwerp, mail_html_omhulsel($onderwerp, $tekst, $knop), 'bericht', $fouten);
}

/** Haalt onderwerp, tekst, groep en de knopkeuze uit het formulier. */
function bericht_invoer(): array
{
    $groep = (string)($_POST['groep'] ?? 'niet_compleet');
    if (!isset(bericht_groepen()[$groep])) {
        $groep = 'niet_compleet';
    }

    return [
        'onderwerp' => trim(mb_substr((string)($_POST['onderwerp'] ?? ''), 0, 200)),
        'tekst'     => trim(mb_substr(str_replace("\r\n", "\n", (string)($_POST['tekst'] ?? '')), 0, BERICHT_MAX_TEKENS)),
        'groep'     => $groep,
        'knop'      => !empty($_POST['knop']),
    ];
}

/** Wat er mis is met de invoer; een lege lijst betekent: in orde. */
function bericht_invoer_fouten(array $invoer): array
{
    $fouten = [];
    if ($invoer['onderwerp'] === '') {
        $fouten[] = 'Vul een onderwerp in.';
    }
    if ($invoer['tekst'] === '') {
        $fouten[] = 'Vul de tekst van het bericht in.';
    }
    // Een lege {contact} zou "laat het ons weten via ," opleveren: een zin met
    // een gat erin, in een mail waar niet op geantwoord kan worden. Liever hier
    // stoppen dan dat honderd ouders dat lezen.
    if (bericht_contact() === ''
        && (str_contains($invoer['tekst'], '{contact}') || str_contains($invoer['onderwerp'], '{contact}'))) {
        $fouten[] = 'U gebruikt {contact}, maar er staat nog geen contactadres bij Instellingen. '
            . 'Vul dat in, of typ het adres zelf in de tekst.';
    }
    return $fouten;
}

// ═══════════════════════════════════════════════════════════════════════════
//  Jaargang kiezen
// ═══════════════════════════════════════════════════════════════════════════

$jaargangen = db()->query('SELECT * FROM jaargangen ORDER BY jaar DESC')->fetchAll();

$gekozenId = (int)($_POST['jaargang'] ?? $_GET['jaargang'] ?? 0);
$jaargang  = null;
foreach ($jaargangen as $rij) {
    if ((int)$rij['id'] === $gekozenId) {
        $jaargang = $rij;
        break;
    }
}
if ($jaargang === null && $jaargangen) {
    $jaargang  = $jaargangen[0];
    $gekozenId = (int)$jaargang['id'];
}

function bericht_terug_link(): string
{
    global $gekozenId;
    return url('admin/bericht.php') . '?jaargang=' . (int)$gekozenId;
}

// ═══════════════════════════════════════════════════════════════════════════
//  Verwerking (POST)
// ═══════════════════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    vereis_csrf();
    $actie = (string)($_POST['actie'] ?? '');

    if ($jaargang === null) {
        flash('danger', 'Er is nog geen jaargang om een bericht over te sturen.');
        header('Location: ' . url('admin/bericht.php'));
        exit;
    }

    // ─── Het concept weggooien ─────────────────────────────────────────────
    if ($actie === 'annuleren') {
        unset($_SESSION['bericht_voorbeeld']);
        flash('info', 'Het bericht is afgebroken. Er is niets verstuurd.');
        header('Location: ' . bericht_terug_link());
        exit;
    }

    // ─── Stap 2: daadwerkelijk versturen ───────────────────────────────────
    // Vóór de controle op het formulier: dit formulier heeft geen tekstvelden
    // meer, de tekst komt uit het voorbeeld dat in stap 1 is vastgelegd.
    if ($actie === 'versturen') {
        $opdracht = $_SESSION['bericht_voorbeeld'] ?? null;
        if (!is_array($opdracht) || (int)($opdracht['jaargang_id'] ?? 0) !== (int)$jaargang['id']) {
            flash('danger', 'Het voorbeeld is verlopen of hoort bij een andere jaargang. Begin opnieuw.');
            header('Location: ' . bericht_terug_link());
            exit;
        }
        if (!mail_geconfigureerd()) {
            flash('danger', 'De e-mailinstellingen zijn nog niet compleet; er is niets verstuurd.');
            header('Location: ' . bericht_terug_link());
            exit;
        }

        $ontvangers = $opdracht['ontvangers'];
        if (!empty($_POST['ook_herhaling'])) {
            foreach ($opdracht['herhaling'] as $persoon) {
                $ontvangers[] = $persoon;
            }
        }

        // Meteen weg uit de sessie: een herladen van de pagina mag er geen
        // tweede ronde van maken.
        unset($_SESSION['bericht_voorbeeld']);

        if (!$ontvangers) {
            flash('info', 'Er bleven geen adressen over om te mailen. Er is niets verstuurd.');
            header('Location: ' . bericht_terug_link());
            exit;
        }

        $gelukt      = 0;
        $mislukt     = 0;
        $laatsteFout = '';
        foreach (array_chunk($ontvangers, BERICHT_MAIL_BLOKKEN) as $blok) {
            @set_time_limit(120);   // per blok de tijdslimiet opnieuw zetten
            foreach ($blok as $persoon) {
                $fouten = [];
                if (bericht_versturen(
                    (string)$persoon['email'],
                    (string)$persoon['naam'],
                    (string)$opdracht['onderwerp'],
                    (string)$opdracht['tekst'],
                    (int)$opdracht['jaar'],
                    !empty($opdracht['knop']),
                    $fouten
                )) {
                    $gelukt++;
                } else {
                    $mislukt++;
                    $laatsteFout = $fouten ? (string)end($fouten) : '';
                }
            }
        }

        // Zodat het formulier de volgende keer opent met wat er verstuurd is.
        instelling_opslaan('bericht_laatste_onderwerp', (string)$opdracht['onderwerp']);
        instelling_opslaan('bericht_laatste_tekst', (string)$opdracht['tekst']);

        app_log('bericht verstuurd', [
            'beheerder' => (string)$beheerder['email'],
            'jaargang'  => (int)$opdracht['jaar'],
            'groep'     => (string)$opdracht['groep'],
            'onderwerp' => (string)$opdracht['onderwerp'],
            'gelukt'    => $gelukt,
            'mislukt'   => $mislukt,
        ]);

        $overgeslagen = (int)$opdracht['geblokkeerd'];
        flash(
            $mislukt === 0 ? 'success' : 'warning',
            sprintf('Bericht: %d verstuurd, %d mislukt.', $gelukt, $mislukt)
            . ($overgeslagen > 0 ? sprintf(' %d geblokkeerde deelnemer(s) overgeslagen.', $overgeslagen) : '')
            . ($laatsteFout !== '' ? ' Laatste fout: ' . $laatsteFout : '')
            . ' Zie Logboek > E-mail voor alle details.'
        );

        header('Location: ' . bericht_terug_link());
        exit;
    }

    $invoer = bericht_invoer();
    $fout   = bericht_invoer_fouten($invoer);
    if ($fout) {
        $_SESSION['bericht_concept'] = $invoer;
        flash('danger', implode(' ', $fout));
        header('Location: ' . bericht_terug_link());
        exit;
    }

    if (!mail_geconfigureerd()) {
        $_SESSION['bericht_concept'] = $invoer;
        flash('danger', 'De e-mailinstellingen zijn nog niet compleet; er kan niets worden verstuurd.');
        header('Location: ' . bericht_terug_link());
        exit;
    }

    // ─── Eerst naar uzelf ──────────────────────────────────────────────────
    if ($actie === 'testmail') {
        $_SESSION['bericht_concept'] = $invoer;
        $fouten = [];
        $gelukt = bericht_versturen(
            (string)$beheerder['email'],
            (string)($beheerder['naam'] ?? ''),
            $invoer['onderwerp'],
            $invoer['tekst'],
            (int)$jaargang['jaar'],
            $invoer['knop'],
            $fouten
        );
        flash(
            $gelukt ? 'success' : 'danger',
            $gelukt
                ? 'Het bericht is naar ' . $beheerder['email'] . ' gestuurd. Er ging niets naar deelnemers.'
                : 'De testmail kon niet worden verstuurd' . ($fouten ? ': ' . implode(' | ', $fouten) : '.')
        );
        header('Location: ' . bericht_terug_link());
        exit;
    }

    // ─── Stap 1: groep kiezen en tellen ────────────────────────────────────
    if ($actie === 'voorbeeld') {
        $groep     = $invoer['groep'];
        $verdeling = bericht_verdelen(
            bericht_kandidaten((int)$jaargang['id'], $groep),
            $invoer['onderwerp']
        );

        if (!$verdeling['ontvangers'] && !$verdeling['herhaling']) {
            unset($_SESSION['bericht_voorbeeld']);
            $_SESSION['bericht_concept'] = $invoer;
            flash('info', 'Er valt niemand in deze groep. Er is niets te versturen.');
            header('Location: ' . bericht_terug_link());
            exit;
        }

        $_SESSION['bericht_voorbeeld'] = [
            'jaargang_id' => (int)$jaargang['id'],
            'jaar'        => (int)$jaargang['jaar'],
            'groep'       => $groep,
            'onderwerp'   => $invoer['onderwerp'],
            'tekst'       => $invoer['tekst'],
            'knop'        => $invoer['knop'],
            'tijd'        => time(),
            'ontvangers'  => $verdeling['ontvangers'],
            'herhaling'   => $verdeling['herhaling'],
            'geblokkeerd' => $verdeling['geblokkeerd'],
            'ongemeten'   => $verdeling['ongemeten'],
        ];
        unset($_SESSION['bericht_concept']);

        header('Location: ' . bericht_terug_link() . '#bevestigen');
        exit;
    }

    header('Location: ' . bericht_terug_link());
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
//  Weergave
// ═══════════════════════════════════════════════════════════════════════════

$voorbeeld = $_SESSION['bericht_voorbeeld'] ?? null;
if (is_array($voorbeeld) && (int)($voorbeeld['jaargang_id'] ?? 0) !== (int)$gekozenId) {
    $voorbeeld = null;
}

$concept = $_SESSION['bericht_concept'] ?? null;
unset($_SESSION['bericht_concept']);

$standaard = bericht_standaard();
$onderwerp = is_array($concept) ? (string)$concept['onderwerp'] : $standaard['onderwerp'];
$tekst     = is_array($concept) ? (string)$concept['tekst'] : $standaard['tekst'];
$metKnop   = is_array($concept) ? !empty($concept['knop']) : true;
$groep     = is_array($concept) ? (string)$concept['groep'] : 'niet_compleet';

$mailKlaar = mail_geconfigureerd();
$contact   = bericht_contact();

admin_start('Bericht', 'Een eigen bericht aan een groep deelnemers');
?>

<?php if (!$jaargangen): ?>
    <div class="kaart p-4">
        <p class="mb-0 text-muted">Er is nog geen jaargang. Maak er eerst een aan bij
            <a href="<?= h(url('admin/jaargangen.php')) ?>">Jaargangen</a>.</p>
    </div>
<?php else: ?>

    <?php if (!$mailKlaar): ?>
        <div class="alert alert-warning">
            De e-mailinstellingen zijn nog niet compleet; er kan niets worden verstuurd.
            Vul ze aan bij <a href="<?= h(url('admin/instellingen.php')) ?>">Instellingen</a>.
        </div>
    <?php endif; ?>

    <!-- ─── Jaargang kiezen ──────────────────────────────────────────────── -->
    <div class="kaart p-3 mb-4">
        <form method="get" class="d-flex flex-wrap align-items-center gap-2">
            <label class="form-label mb-0 me-2" for="jaargang">Jaargang</label>
            <select class="form-select w-auto" id="jaargang" name="jaargang" data-auto-verzenden>
                <?php foreach ($jaargangen as $rij): ?>
                    <option value="<?= (int)$rij['id'] ?>" <?= (int)$rij['id'] === $gekozenId ? 'selected' : '' ?>>
                        <?= (int)$rij['jaar'] ?> — <?= h((string)$rij['titel']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="btn btn-outline-secondary">Kiezen</button></noscript>
            <span class="text-muted small">
                Het bericht gaat naar deelnemers met toegang tot deze jaargang.
            </span>
        </form>
    </div>

    <?php if (is_array($voorbeeld)): ?>
        <?php
        $aantalTeMailen = count($voorbeeld['ontvangers']);
        $aantalHerhaald = count($voorbeeld['herhaling']);
        $aantalGeblokt  = (int)$voorbeeld['geblokkeerd'];
        $aantalOngemeten = (int)($voorbeeld['ongemeten'] ?? 0);
        $groepTekst     = bericht_groep_omschrijving((string)$voorbeeld['groep']);
        $eerste         = $voorbeeld['ontvangers'][0] ?? ($voorbeeld['herhaling'][0] ?? ['naam' => '', 'email' => '']);
        $waarden        = bericht_waarden((string)$eerste['naam'], (int)$voorbeeld['jaar']);
        $proefOnderwerp = mail_sjabloon_vullen((string)$voorbeeld['onderwerp'], $waarden);
        $proefTekst     = mail_sjabloon_vullen((string)$voorbeeld['tekst'], $waarden);
        ?>

        <!-- ─── Stap 2: controleren en bevestigen ────────────────────────── -->
        <div class="kaart p-4 mb-4 border border-warning" id="bevestigen">
            <h2 class="h6 text-uppercase text-muted mb-3">Stap 2 — controleren en bevestigen</h2>

            <div class="alert alert-warning d-flex gap-3 align-items-start">
                <i class="bi bi-exclamation-triangle-fill fs-4"></i>
                <div>
                    <strong>Let op: dit stuurt een echte e-mail naar echte ouders.</strong>
                    Het bericht gaat naar <?= h($groepTekst) ?> van
                    <?= (int)$voorbeeld['jaar'] ?>. Controleer de tekst en het aantal voordat u bevestigt.
                </div>
            </div>

            <div class="text-center my-4">
                <div class="display-3 fw-semibold text-warning"><?= (int)$aantalTeMailen ?></div>
                <div class="text-muted">e-mailadres(sen) ontvangen dit bericht</div>
            </div>

            <ul class="list-unstyled small text-muted mb-3">
                <li><i class="bi bi-people me-1"></i>Groep: <?= h($groepTekst) ?>.</li>
                <?php if ($aantalGeblokt > 0): ?>
                    <li>
                        <i class="bi bi-slash-circle me-1"></i>
                        <?= (int)$aantalGeblokt ?> geblokkeerde deelnemer(s) worden overgeslagen.
                    </li>
                <?php endif; ?>
                <?php if ($aantalHerhaald > 0): ?>
                    <li>
                        <i class="bi bi-clock-history me-1"></i>
                        <?= (int)$aantalHerhaald ?> adres(sen) kregen dit bericht in de afgelopen
                        <?= (int)BERICHT_HERHAAL_UREN ?> uur al en worden overgeslagen.
                    </li>
                <?php endif; ?>
                <?php if ($aantalOngemeten > 0 && $voorbeeld['groep'] === 'niet_compleet'): ?>
                    <li>
                        <i class="bi bi-question-circle me-1"></i>
                        <?= (int)$aantalOngemeten ?> van hen begon wél aan een download die het portaal niet
                        kon meten, omdat de webserver hem uitleverde. Die kunnen de video dus gewoon compleet
                        hebben. Zie <a href="<?= h(url('admin/handleiding.php') . '#scherm-logboek') ?>">de uitleg
                        bij het logboek</a>.
                    </li>
                <?php endif; ?>
                <li>
                    <i class="bi bi-envelope me-1"></i>
                    De mails gaan in blokken van <?= (int)BERICHT_MAIL_BLOKKEN ?>; bij een lange lijst
                    duurt het even voordat de pagina terugkomt. Sluit het venster in die tijd niet.
                </li>
            </ul>

            <div class="border rounded p-3 mb-3 bg-body-tertiary">
                <div class="small text-muted mb-1">Zo ziet het eruit bij de eerste ontvanger
                    (<?= h((string)$eerste['email']) ?>):</div>
                <div class="fw-semibold mb-2"><?= h($proefOnderwerp) ?></div>
                <div class="small" style="white-space:pre-wrap;"><?= h($proefTekst) ?></div>
                <?php if (!empty($voorbeeld['knop'])): ?>
                    <div class="small text-muted mt-2"><i class="bi bi-box-arrow-up-right me-1"></i>Met een knop
                        <em>Naar het portaal</em> eronder.</div>
                <?php endif; ?>
            </div>

            <form method="post" class="border-top pt-3">
                <?= csrf_field() ?>
                <input type="hidden" name="actie" value="versturen">
                <input type="hidden" name="jaargang" value="<?= (int)$gekozenId ?>">

                <?php if ($aantalHerhaald > 0): ?>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="ookHerhaling" name="ook_herhaling" value="1">
                        <label class="form-check-label" for="ookHerhaling">
                            Toch ook naar de <strong><?= (int)$aantalHerhaald ?></strong> adres(sen) sturen die
                            dit bericht al kregen
                        </label>
                    </div>
                <?php endif; ?>

                <button type="submit" class="btn btn-warning"
                    <?= $aantalTeMailen === 0 && $aantalHerhaald === 0 ? 'disabled' : '' ?>>
                    <i class="bi bi-send me-1"></i>
                    Ja, verstuur het bericht naar <?= (int)$aantalTeMailen ?> adres(sen)
                </button>
                <button type="submit" name="actie" value="annuleren" class="btn btn-outline-secondary ms-2"
                    formnovalidate>Annuleren</button>
            </form>
        </div>
    <?php else: ?>

        <!-- ─── Stap 1: het bericht schrijven ────────────────────────────── -->
        <div class="kaart p-4 mb-4">
            <h2 class="h6 text-uppercase text-muted mb-3">Stap 1 — het bericht schrijven</h2>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="actie" value="voorbeeld">
                <input type="hidden" name="jaargang" value="<?= (int)$gekozenId ?>">

                <div class="row g-4">
                    <div class="col-lg-8">
                        <div class="mb-3">
                            <label class="form-label" for="onderwerp">Onderwerp</label>
                            <input class="form-control" type="text" id="onderwerp" name="onderwerp"
                                maxlength="200" required value="<?= h($onderwerp) ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="tekst">Tekst</label>
                            <textarea class="form-control" id="tekst" name="tekst" rows="14" required
                                maxlength="<?= (int)BERICHT_MAX_TEKENS ?>"><?= h($tekst) ?></textarea>
                            <div class="form-text">
                                Een lege regel maakt een nieuwe alinea. Opmaak (vet, links) kan niet:
                                de mail krijgt dezelfde nette omlijsting als de andere mails uit het portaal.
                            </div>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="knop" name="knop" value="1"
                                <?= $metKnop ? 'checked' : '' ?>>
                            <label class="form-check-label" for="knop">
                                Een knop <strong>Naar het portaal</strong> onder de tekst zetten
                            </label>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <div class="border rounded p-3 bg-body-tertiary h-100">
                            <label class="form-label" for="groep">Naar wie</label>
                            <select class="form-select mb-3" id="groep" name="groep">
                                <?php foreach (bericht_groepen() as $sleutel => [$label, $omschrijving]): ?>
                                    <option value="<?= h($sleutel) ?>" <?= $sleutel === $groep ? 'selected' : '' ?>>
                                        <?= h($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text mb-3">
                                U ziet in de volgende stap om hoeveel mensen het gaat. Geblokkeerde deelnemers
                                krijgen nooit een bericht.
                            </div>

                            <div class="small">
                                <div class="fw-semibold mb-1">Plaatshouders</div>
                                <ul class="list-unstyled mb-0 text-muted">
                                    <li><code class="pad">{naam}</code> — de naam van de ontvanger, of
                                        <em>deelnemer</em> als die niet bekend is</li>
                                    <li><code class="pad">{jaar}</code> — <?= (int)($jaargang['jaar'] ?? 0) ?></li>
                                    <li><code class="pad">{portaal_naam}</code> — <?= h(portaal_naam()) ?></li>
                                    <li><code class="pad">{url}</code> — het adres van het portaal</li>
                                    <li>
                                        <code class="pad">{contact}</code> —
                                        <?php if ($contact !== ''): ?>
                                            <?= h($contact) ?>
                                        <?php else: ?>
                                            <span class="text-danger">nog leeg; vul het contactadres in bij
                                                <a href="<?= h(url('admin/instellingen.php')) ?>">Instellingen</a></span>
                                        <?php endif; ?>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-3 d-flex flex-wrap align-items-center gap-2 djm-knoppenbalk">
                    <button type="submit" class="btn btn-djm djm-actie" <?= $mailKlaar ? '' : 'disabled' ?>>
                        <i class="bi bi-eye me-1"></i>Voorbeeld tonen
                    </button>
                    <button type="submit" name="actie" value="testmail" class="btn btn-outline-secondary"
                        <?= $mailKlaar ? '' : 'disabled' ?>>
                        <i class="bi bi-send-check me-1"></i>Eerst naar mijzelf sturen
                    </button>
                    <span class="text-muted small">Er gaat nu nog niets naar deelnemers.</span>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <div class="kaart p-4">
        <h2 class="h6 text-uppercase text-muted mb-3">Wat er van uitgaat</h2>
        <ul class="small text-muted mb-0">
            <li>Elk bericht staat in <a href="<?= h(url('admin/logboek.php') . '?tab=mails') ?>">Logboek &rsaquo;
                E-mail</a> als soort <code class="pad">bericht</code>, met de foutmelding erbij als het misging.</li>
            <li>Ieder adres krijgt een eigen mail; ontvangers zien elkaars adres niet.</li>
            <li>Hetzelfde onderwerp gaat binnen <?= (int)BERICHT_HERHAAL_UREN ?> uur niet twee keer naar
                hetzelfde adres, tenzij u dat in stap 2 aanvinkt.</li>
            <li>Onder aan de mail staat dat er niet op geantwoord kan worden. Zet daarom een adres in de tekst
                waar mensen wél terechtkunnen &mdash; daar is <code class="pad">{contact}</code> voor.</li>
        </ul>
    </div>

<?php endif; ?>

<?php admin_eind(); ?>
