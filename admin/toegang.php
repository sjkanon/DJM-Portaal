<?php

/**
 * Beheer > Toegang — de jaarlijkse handeling.
 *
 * Per jaargang: e-mailadressen plakken of een CSV uploaden, eerst een voorbeeld
 * bekijken (geldig / ongeldig / al toegang / nieuw) en pas na bevestiging
 * wegschrijven. Daarnaast de huidige toegangslijst met zoeken, paginering,
 * intrekken, uitnodiging (opnieuw) versturen en een CSV-export.
 */

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/toegang_helper.php';
require_once dirname(__DIR__) . '/includes/email_helper.php';

vereis_installatie();
$beheerder = vereis_beheerder();

const TOEGANG_PER_PAGINA   = 50;
const TOEGANG_MAIL_BLOKKEN = 25;   // aantal uitnodigingen per blok

// ═══════════════════════════════════════════════════════════════════════════
//  Parser
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Splitst één regel in segmenten op komma's, puntkomma's en tabs, maar negeert
 * scheidingstekens binnen aanhalingstekens of binnen <...>.
 * Zo blijft "Jansen, Jan" <jan@example.nl> één segment.
 *
 * @return string[]
 */
function toegang_segmenten(string $regel): array
{
    $segmenten    = [];
    $huidig       = '';
    $inAanhaling  = false;
    $inHaken      = false;
    $lengte       = strlen($regel);

    for ($i = 0; $i < $lengte; $i++) {
        $teken = $regel[$i];
        if ($teken === '"') {
            $inAanhaling = !$inAanhaling;
            $huidig .= $teken;
            continue;
        }
        if (!$inAanhaling && $teken === '<') {
            $inHaken = true;
            $huidig .= $teken;
            continue;
        }
        if (!$inAanhaling && $teken === '>') {
            $inHaken = false;
            $huidig .= $teken;
            continue;
        }
        if (!$inAanhaling && !$inHaken && ($teken === ',' || $teken === ';' || $teken === "\t" || $teken === '|')) {
            $segmenten[] = $huidig;
            $huidig = '';
            continue;
        }
        $huidig .= $teken;
    }
    $segmenten[] = $huidig;

    $schoon = [];
    foreach ($segmenten as $segment) {
        $segment = trim($segment);
        if ($segment !== '') {
            $schoon[] = $segment;
        }
    }
    return $schoon;
}

/**
 * Haalt uit één segment het e-mailadres en de eventuele naam.
 *
 * @return array{email: ?string, naam: string}
 */
function toegang_segment_ontleden(string $segment): array
{
    $segment = trim($segment);
    $email   = null;
    $naam    = $segment;

    if (preg_match('/<\s*(?:mailto:)?([^<>\s]+@[^<>\s]+)\s*>/i', $segment, $treffer)) {
        // Vorm: Jan Jansen <jan@example.nl>
        $email = $treffer[1];
        $naam  = str_replace($treffer[0], '', $segment);
    } elseif (preg_match('/(?:mailto:)?([^\s<>,;:"\']+@[^\s<>,;:"\']+)/i', $segment, $treffer)) {
        // Vorm: jan@example.nl  (eventueel met tekst ervoor of erna)
        $email = $treffer[1];
        $naam  = str_replace($treffer[0], '', $segment);
    }

    $naam = trim($naam);
    $naam = trim($naam, " \t\"'");
    $naam = trim(preg_replace('/\s+/u', ' ', $naam) ?? '');

    return ['email' => $email, 'naam' => $naam];
}

/** Bouwt één item voor het voorbeeld en valideert het adres meteen. */
function toegang_item_maken(?string $email, string $naam, string $regel): array
{
    $genormaliseerd = $email !== null ? normaliseer_email($email) : '';
    $geldig         = $genormaliseerd !== '' && geldig_email($genormaliseerd);

    return [
        'email'  => $genormaliseerd,
        'naam'   => mb_substr($naam, 0, 150),
        'regel'  => mb_substr(trim($regel), 0, 300),
        'geldig' => $geldig,
        'reden'  => $geldig ? '' : ($genormaliseerd === '' ? 'geen e-mailadres gevonden' : 'ongeldig e-mailadres'),
    ];
}

/**
 * Ontleedt één regel tot nul of meer items.
 * Ondersteunt door elkaar:
 *   jan@example.nl
 *   Jan Jansen <jan@example.nl>
 *   jan@example.nl;Jan Jansen
 *   "Jansen, Jan" <jan@example.nl>
 *   jan@example.nl, piet@example.nl
 *
 * @return array<int, array{email: string, naam: string, regel: string, geldig: bool, reden: string}>
 */
function toegang_regel_ontleden(string $regel): array
{
    $origineel = trim($regel);
    if ($origineel === '' || str_starts_with($origineel, '#')) {
        return [];
    }

    $ontleed = array_map('toegang_segment_ontleden', toegang_segmenten($origineel));
    if (!$ontleed) {
        return [];
    }

    $metAdres = 0;
    foreach ($ontleed as $deel) {
        if ($deel['email'] !== null) {
            $metAdres++;
        }
    }
    if ($metAdres === 0) {
        // Niets bruikbaars: toch tonen, zodat de beheerder de regel terugziet.
        return [toegang_item_maken(null, '', $origineel)];
    }

    $items         = [];
    $wachtendeNaam = '';
    $aantal        = count($ontleed);

    for ($i = 0; $i < $aantal; $i++) {
        $deel = $ontleed[$i];

        if ($deel['email'] === null) {
            // Naam vóór het adres onthouden: "Jan Jansen; jan@example.nl"
            $wachtendeNaam = trim($wachtendeNaam . ' ' . $deel['naam']);
            continue;
        }

        $naam = $deel['naam'] !== '' ? $deel['naam'] : $wachtendeNaam;
        $wachtendeNaam = '';

        // Naam ná het adres: "jan@example.nl;Jan Jansen"
        if ($naam === '' && isset($ontleed[$i + 1]) && $ontleed[$i + 1]['email'] === null) {
            $naam = $ontleed[$i + 1]['naam'];
            $i++;
        }

        $items[] = toegang_item_maken($deel['email'], $naam, $origineel);
    }

    return $items;
}

/** Ontleedt een geplakte tekst met meerdere regels. */
function toegang_tekst_ontleden(string $tekst): array
{
    $tekst = str_replace(["\r\n", "\r"], "\n", $tekst);
    $items = [];
    foreach (explode("\n", $tekst) as $regel) {
        foreach (toegang_regel_ontleden($regel) as $item) {
            $items[] = $item;
        }
    }
    return $items;
}

/**
 * Ontleedt een CSV-bestand: kolommen e-mail en optioneel naam, in willekeurige
 * volgorde, met of zonder kopregel, met komma of puntkomma als scheidingsteken.
 */
function toegang_csv_ontleden(string $inhoud): array
{
    // BOM weg en zorgen dat we met UTF-8 werken (Excel levert vaak Latin-1).
    $inhoud = preg_replace('/^\xEF\xBB\xBF/', '', $inhoud) ?? $inhoud;
    if (function_exists('mb_check_encoding') && !mb_check_encoding($inhoud, 'UTF-8')) {
        $inhoud = mb_convert_encoding($inhoud, 'UTF-8', 'ISO-8859-1');
    }
    $inhoud = str_replace(["\r\n", "\r"], "\n", $inhoud);

    $regels = [];
    foreach (explode("\n", $inhoud) as $regel) {
        if (trim($regel) !== '') {
            $regels[] = $regel;
        }
    }
    if (!$regels) {
        return [];
    }

    // Scheidingsteken raden op basis van de eerste regel.
    $scheiding = substr_count($regels[0], ';') >= substr_count($regels[0], ',') ? ';' : ',';

    $items = [];
    foreach ($regels as $nummer => $regel) {
        $velden = str_getcsv($regel, $scheiding, '"');
        $velden = array_map(static fn($v): string => trim((string)$v), $velden);

        $email = null;
        $naam  = '';
        foreach ($velden as $veld) {
            if ($veld === '') {
                continue;
            }
            if ($email === null && str_contains($veld, '@')) {
                $ontleed = toegang_segment_ontleden($veld);
                $email = $ontleed['email'];
                if ($ontleed['naam'] !== '') {
                    $naam = $ontleed['naam'];
                }
                continue;
            }
            if ($naam === '') {
                $naam = $veld;
            }
        }

        if ($email === null) {
            // Eerste regel zonder adres is vrijwel zeker de kopregel: overslaan.
            if ($nummer === 0) {
                continue;
            }
            $items[] = toegang_item_maken(null, '', $regel);
            continue;
        }

        $items[] = toegang_item_maken($email, $naam, $regel);
    }

    return $items;
}

/**
 * Vraagt per e-mailadres op of de deelnemer bestaat en of die al toegang heeft
 * tot deze jaargang.
 *
 * @param string[] $emails
 * @return array<string, bool> e-mailadres => heeft al toegang
 */
function toegang_bestaande_status(array $emails, int $jaargangId): array
{
    $status = [];
    foreach (array_chunk(array_values($emails), 200) as $blok) {
        $plaatshouders = [];
        $params        = [':j' => $jaargangId];
        foreach ($blok as $index => $email) {
            $plaatshouders[]      = ':e' . $index;
            $params[':e' . $index] = $email;
        }
        $stmt = db()->prepare(
            'SELECT d.email, (t.id IS NOT NULL) AS heeft_toegang
             FROM deelnemers d
             LEFT JOIN toegang t ON t.deelnemer_id = d.id AND t.jaargang_id = :j
             WHERE d.email IN (' . implode(',', $plaatshouders) . ')'
        );
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $rij) {
            $status[(string)$rij['email']] = (int)$rij['heeft_toegang'] === 1;
        }
    }
    return $status;
}

/**
 * Bouwt het voorbeeld: ontdubbelen en per adres bepalen of het nieuw is,
 * al toegang heeft of ongeldig is.
 */
function toegang_voorbeeld_opbouwen(array $items, int $jaargangId): array
{
    $gezien   = [];
    $definief = [];

    foreach ($items as $item) {
        if (!$item['geldig']) {
            $item['status'] = 'ongeldig';
            $definief[] = $item;
            continue;
        }
        if (isset($gezien[$item['email']])) {
            $item['status'] = 'dubbel';
            $item['reden']  = 'staat meerdere keren in de lijst';
            $definief[] = $item;
            continue;
        }
        $gezien[$item['email']] = true;
        $item['status'] = 'nieuw';
        $definief[] = $item;
    }

    if ($gezien) {
        $bekend = toegang_bestaande_status(array_keys($gezien), $jaargangId);
        foreach ($definief as &$item) {
            if ($item['status'] === 'nieuw' && ($bekend[$item['email']] ?? false)) {
                $item['status'] = 'bestaand';
            }
        }
        unset($item);
    }

    return $definief;
}

/** Telt de items per status. */
function toegang_voorbeeld_tellen(array $items): array
{
    $telling = ['nieuw' => 0, 'bestaand' => 0, 'ongeldig' => 0, 'dubbel' => 0];
    foreach ($items as $item) {
        $status = (string)$item['status'];
        if (isset($telling[$status])) {
            $telling[$status]++;
        }
    }
    return $telling;
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

$zoek   = trim((string)($_GET['q'] ?? ''));
$pagina = max(1, (int)($_GET['pagina'] ?? 1));

/** Bouwt een link naar deze pagina met de huidige filters. */
function toegang_link(array $extra = []): string
{
    global $gekozenId, $zoek, $pagina;
    $params = ['jaargang' => $gekozenId];
    if ($zoek !== '') {
        $params['q'] = $zoek;
    }
    if ($pagina > 1) {
        $params['pagina'] = $pagina;
    }
    foreach ($extra as $sleutel => $waarde) {
        if ($waarde === null || $waarde === '') {
            unset($params[$sleutel]);
        } else {
            $params[$sleutel] = $waarde;
        }
    }
    return url('admin/toegang.php') . '?' . http_build_query($params);
}

/** Link om na een POST naar terug te keren, opgebouwd uit de meegestuurde velden. */
function toegang_terug_link(): string
{
    $params = ['jaargang' => (int)($_POST['jaargang'] ?? 0)];
    $q = trim((string)($_POST['q'] ?? ''));
    if ($q !== '') {
        $params['q'] = $q;
    }
    $p = (int)($_POST['pagina'] ?? 1);
    if ($p > 1) {
        $params['pagina'] = $p;
    }
    return url('admin/toegang.php') . '?' . http_build_query($params);
}

// ═══════════════════════════════════════════════════════════════════════════
//  Verwerking (POST) — altijd Post/Redirect/Get
// ═══════════════════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    vereis_csrf();
    $actie = (string)($_POST['actie'] ?? '');

    if ($jaargang === null) {
        flash('danger', 'Kies eerst een jaargang.');
        header('Location: ' . url('admin/toegang.php'));
        exit;
    }

    // ─── Stap 1: voorbeeld opbouwen ────────────────────────────────────────
    if ($actie === 'voorbeeld') {
        $items = [];
        $bron  = 'plakken';

        if (isset($_FILES['csv']) && is_array($_FILES['csv'])
            && (int)$_FILES['csv']['error'] === UPLOAD_ERR_OK
            && is_uploaded_file((string)$_FILES['csv']['tmp_name'])) {
            if ((int)$_FILES['csv']['size'] > 5 * 1024 * 1024) {
                flash('danger', 'Het CSV-bestand is groter dan 5 MB. Splits de lijst op.');
                header('Location: ' . toegang_terug_link());
                exit;
            }
            $inhoud = (string)@file_get_contents((string)$_FILES['csv']['tmp_name']);
            $items  = toegang_csv_ontleden($inhoud);
            $bron   = 'csv';
        } else {
            $items = toegang_tekst_ontleden((string)($_POST['adressen'] ?? ''));
        }

        if (!$items) {
            flash('warning', 'Er zijn geen regels gevonden om te verwerken.');
            header('Location: ' . toegang_terug_link());
            exit;
        }
        if (count($items) > 5000) {
            flash('danger', 'Maximaal 5.000 regels per import. Splits de lijst op.');
            header('Location: ' . toegang_terug_link());
            exit;
        }

        $_SESSION['import_voorbeeld'] = [
            'jaargang_id' => (int)$jaargang['id'],
            'jaar'        => (int)$jaargang['jaar'],
            'bron'        => $bron,
            'tijd'        => time(),
            'items'       => toegang_voorbeeld_opbouwen($items, (int)$jaargang['id']),
        ];

        header('Location: ' . toegang_terug_link() . '#voorbeeld');
        exit;
    }

    // ─── Voorbeeld weggooien ───────────────────────────────────────────────
    if ($actie === 'annuleren') {
        unset($_SESSION['import_voorbeeld']);
        flash('info', 'Het voorbeeld is weggegooid. Er is niets gewijzigd.');
        header('Location: ' . toegang_terug_link());
        exit;
    }

    // ─── Stap 2: definitief wegschrijven ───────────────────────────────────
    if ($actie === 'bevestigen') {
        $voorbeeld = $_SESSION['import_voorbeeld'] ?? null;
        if (!is_array($voorbeeld) || (int)($voorbeeld['jaargang_id'] ?? 0) !== (int)$jaargang['id']) {
            flash('danger', 'Het voorbeeld is verlopen of hoort bij een andere jaargang. Begin opnieuw.');
            header('Location: ' . toegang_terug_link());
            exit;
        }

        $mailen = !empty($_POST['uitnodiging']) && mail_geconfigureerd();
        $door   = (string)($beheerder['naam'] ?? $beheerder['email'] ?? '');

        $nieuw      = 0;
        $bestaand   = 0;
        $overgeslagen = 0;
        $nieuweMensen = [];

        foreach ($voorbeeld['items'] as $item) {
            if (($item['status'] ?? '') === 'ongeldig' || ($item['status'] ?? '') === 'dubbel') {
                $overgeslagen++;
                continue;
            }
            try {
                $deelnemer = deelnemer_aanmaken_of_ophalen((string)$item['email'], (string)$item['naam']);
                if (toegang_toekennen((int)$deelnemer['id'], (int)$jaargang['id'], $door)) {
                    $nieuw++;
                    $nieuweMensen[] = [
                        'email' => (string)$item['email'],
                        'naam'  => (string)($deelnemer['naam'] ?? $item['naam']),
                    ];
                } else {
                    $bestaand++;
                }
            } catch (Throwable $e) {
                $overgeslagen++;
                app_log('import toegang mislukt', ['email' => $item['email'], 'fout' => $e->getMessage()]);
            }
        }

        unset($_SESSION['import_voorbeeld']);

        flash(
            'success',
            sprintf(
                '%d deelnemer(s) toegevoegd aan %d, %d had(den) al toegang, %d overgeslagen.',
                $nieuw,
                (int)$jaargang['jaar'],
                $bestaand,
                $overgeslagen
            )
        );

        // ─── Uitnodigingen in blokken versturen ────────────────────────────
        if ($mailen && $nieuweMensen) {
            $gelukt   = 0;
            $mislukt  = 0;
            $laatsteFout = '';
            foreach (array_chunk($nieuweMensen, TOEGANG_MAIL_BLOKKEN) as $blok) {
                @set_time_limit(120);   // per blok de tijdslimiet opnieuw zetten
                foreach ($blok as $persoon) {
                    $fouten = [];
                    if (verstuur_uitnodiging_mail($persoon['email'], $persoon['naam'], (int)$jaargang['jaar'], $fouten)) {
                        $gelukt++;
                    } else {
                        $mislukt++;
                        $laatsteFout = $fouten ? (string)end($fouten) : '';
                    }
                }
            }
            flash(
                $mislukt === 0 ? 'success' : 'warning',
                sprintf('Uitnodigingen: %d verstuurd, %d mislukt.', $gelukt, $mislukt)
                . ($laatsteFout !== '' ? ' Laatste fout: ' . $laatsteFout : '')
                . ' Zie Logboek > E-mail voor alle details.'
            );
        } elseif (!empty($_POST['uitnodiging']) && !mail_geconfigureerd()) {
            flash('warning', 'Er zijn geen uitnodigingen verstuurd: de e-mailinstellingen zijn nog niet compleet.');
        }

        header('Location: ' . toegang_terug_link());
        exit;
    }

    // ─── Toegang tot deze jaargang intrekken ───────────────────────────────
    if ($actie === 'intrekken') {
        $deelnemerId = (int)($_POST['deelnemer_id'] ?? 0);
        if ($deelnemerId > 0) {
            toegang_intrekken($deelnemerId, (int)$jaargang['id']);
            flash('success', 'De toegang tot ' . (int)$jaargang['jaar'] . ' is ingetrokken.');
        }
        header('Location: ' . toegang_terug_link());
        exit;
    }

    // ─── Uitnodiging (opnieuw) versturen ───────────────────────────────────
    if ($actie === 'uitnodiging') {
        $deelnemerId = (int)($_POST['deelnemer_id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM deelnemers WHERE id = :id');
        $stmt->execute([':id' => $deelnemerId]);
        $deelnemer = $stmt->fetch();

        if (!$deelnemer) {
            flash('danger', 'Deelnemer niet gevonden.');
        } elseif ((int)$deelnemer['geblokkeerd'] === 1) {
            flash('warning', 'Deze deelnemer is geblokkeerd; er is geen uitnodiging verstuurd.');
        } else {
            $fouten = [];
            if (verstuur_uitnodiging_mail(
                (string)$deelnemer['email'],
                (string)($deelnemer['naam'] ?? ''),
                (int)$jaargang['jaar'],
                $fouten
            )) {
                flash('success', 'Uitnodiging verstuurd naar ' . $deelnemer['email'] . '.');
            } else {
                flash('danger', 'Versturen mislukt: ' . ($fouten ? implode(' · ', $fouten) : 'onbekende fout'));
            }
        }
        header('Location: ' . toegang_terug_link());
        exit;
    }

    header('Location: ' . toegang_terug_link());
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
//  Export (GET) — vóór alle uitvoer
// ═══════════════════════════════════════════════════════════════════════════

if (($_GET['actie'] ?? '') === 'export' && $jaargang !== null) {
    $stmt = db()->prepare(
        'SELECT d.email, d.naam, d.geblokkeerd, d.laatst_ingelogd_op, t.toegevoegd_op, t.toegevoegd_door
         FROM toegang t
         JOIN deelnemers d ON d.id = t.deelnemer_id
         WHERE t.jaargang_id = :j
         ORDER BY d.email ASC'
    );
    $stmt->execute([':j' => (int)$jaargang['id']]);

    $bestandsnaam = 'toegang-' . (int)$jaargang['jaar'] . '-' . date('Ymd') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $bestandsnaam . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    $uitvoer = fopen('php://output', 'w');
    echo "\xEF\xBB\xBF";   // BOM, zodat Excel UTF-8 herkent
    fputcsv($uitvoer, ['email', 'naam', 'toegevoegd_op', 'toegevoegd_door', 'laatst_ingelogd_op', 'geblokkeerd'], ';');
    foreach ($stmt->fetchAll() as $rij) {
        fputcsv($uitvoer, [
            (string)$rij['email'],
            (string)($rij['naam'] ?? ''),
            (string)($rij['toegevoegd_op'] ?? ''),
            (string)($rij['toegevoegd_door'] ?? ''),
            (string)($rij['laatst_ingelogd_op'] ?? ''),
            (int)$rij['geblokkeerd'] === 1 ? 'ja' : 'nee',
        ], ';');
    }
    fclose($uitvoer);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
//  Gegevens voor de weergave
// ═══════════════════════════════════════════════════════════════════════════

$voorbeeld = $_SESSION['import_voorbeeld'] ?? null;
if (is_array($voorbeeld) && (int)($voorbeeld['jaargang_id'] ?? 0) !== $gekozenId) {
    $voorbeeld = null;   // hoort bij een andere jaargang: niet tonen
}

$rijen  = [];
$totaal = 0;
$pagina_totaal = 1;

if ($jaargang !== null) {
    $waar   = ['t.jaargang_id = :j'];
    $params = [':j' => (int)$jaargang['id']];
    if ($zoek !== '') {
        $waar[] = '(d.email LIKE :zoek OR d.naam LIKE :zoek)';
        $params[':zoek'] = '%' . $zoek . '%';
    }
    $waarSql = implode(' AND ', $waar);

    $telStmt = db()->prepare(
        'SELECT COUNT(*) FROM toegang t JOIN deelnemers d ON d.id = t.deelnemer_id WHERE ' . $waarSql
    );
    $telStmt->execute($params);
    $totaal = (int)$telStmt->fetchColumn();

    $pagina_totaal = max(1, (int)ceil($totaal / TOEGANG_PER_PAGINA));
    $pagina        = min($pagina, $pagina_totaal);
    $offset        = ($pagina - 1) * TOEGANG_PER_PAGINA;

    $stmt = db()->prepare(
        'SELECT d.id, d.email, d.naam, d.geblokkeerd, d.laatst_ingelogd_op,
                t.toegevoegd_op, t.toegevoegd_door
         FROM toegang t
         JOIN deelnemers d ON d.id = t.deelnemer_id
         WHERE ' . $waarSql . '
         ORDER BY d.email ASC
         LIMIT ' . (int)TOEGANG_PER_PAGINA . ' OFFSET ' . (int)$offset
    );
    $stmt->execute($params);
    $rijen = $stmt->fetchAll();
}

$mailKlaar = mail_geconfigureerd();

admin_start(
    'Toegang',
    $jaargang !== null ? 'Wie mag de registratie van ' . (int)$jaargang['jaar'] . ' downloaden?' : ''
);
?>

<?php if (!$jaargangen): ?>
    <div class="kaart p-5 text-center">
        <i class="bi bi-calendar3 display-5 text-muted"></i>
        <h2 class="h5 mt-3">Er zijn nog geen jaargangen</h2>
        <p class="text-muted mb-4">
            Maak eerst een jaargang aan. Daarna kunt u hier de deelnemerslijst van dat jaar beheren.
        </p>
        <a class="btn btn-djm" href="<?= h(url('admin/jaargangen.php')) ?>">
            <i class="bi bi-plus-lg me-1"></i>Jaargang aanmaken
        </a>
    </div>
<?php else: ?>

    <!-- ─── Jaargang kiezen ─────────────────────────────────────────────── -->
    <div class="kaart p-3 mb-4">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-sm-6 col-lg-4">
                <label class="form-label small text-muted mb-1" for="jaargangKeuze">Jaargang</label>
                <select class="form-select" id="jaargangKeuze" name="jaargang" onchange="this.form.submit()">
                    <?php foreach ($jaargangen as $rij): ?>
                        <option value="<?= (int)$rij['id'] ?>" <?= (int)$rij['id'] === $gekozenId ? 'selected' : '' ?>>
                            <?= (int)$rij['jaar'] ?> — <?= h((string)$rij['titel']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6 col-lg-4">
                <label class="form-label small text-muted mb-1" for="zoekveld">Zoeken in de lijst</label>
                <div class="input-group">
                    <input type="search" class="form-control" id="zoekveld" name="q"
                        value="<?= h($zoek) ?>" placeholder="e-mailadres of naam">
                    <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
                </div>
            </div>
            <div class="col-lg-4 text-lg-end">
                <?php [$statusTekst, $statusKleur] = jaargang_status($jaargang); ?>
                <span class="badge text-bg-<?= h($statusKleur) ?> me-2"><?= h($statusTekst) ?></span>
                <span class="text-muted small"><?= (int)$totaal ?> deelnemer(s) met toegang</span>
            </div>
        </form>
    </div>

    <?php if (!$mailKlaar): ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-1"></i>
            De e-mailinstellingen zijn nog niet compleet; uitnodigingen kunnen niet worden verstuurd.
            <a href="<?= h(url('admin/instellingen.php')) ?>" class="alert-link">Naar instellingen</a>.
        </div>
    <?php endif; ?>

    <?php if (is_array($voorbeeld) && !empty($voorbeeld['items'])): ?>
        <?php $telling = toegang_voorbeeld_tellen($voorbeeld['items']); ?>

        <!-- ─── Stap 2: voorbeeld en bevestiging ─────────────────────────── -->
        <div class="kaart p-4 mb-4" id="voorbeeld">
            <h2 class="h6 text-uppercase text-muted mb-3">Stap 2 — controleren en bevestigen</h2>

            <div class="row g-2 mb-3">
                <div class="col-6 col-lg-3">
                    <div class="border rounded p-3 text-center">
                        <div class="fs-4 fw-semibold text-success"><?= (int)$telling['nieuw'] ?></div>
                        <div class="small text-muted">nieuw — krijgen toegang</div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="border rounded p-3 text-center">
                        <div class="fs-4 fw-semibold text-secondary"><?= (int)$telling['bestaand'] ?></div>
                        <div class="small text-muted">had al toegang</div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="border rounded p-3 text-center">
                        <div class="fs-4 fw-semibold text-warning"><?= (int)$telling['dubbel'] ?></div>
                        <div class="small text-muted">dubbel in de lijst</div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="border rounded p-3 text-center">
                        <div class="fs-4 fw-semibold text-danger"><?= (int)$telling['ongeldig'] ?></div>
                        <div class="small text-muted">ongeldig</div>
                    </div>
                </div>
            </div>

            <div class="table-responsive mb-3" style="max-height:420px;overflow-y:auto;">
                <table class="table table-sm tabel-compact align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:120px;">Status</th>
                            <th>E-mailadres</th>
                            <th>Naam</th>
                            <th>Oorspronkelijke regel</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($voorbeeld['items'] as $item): ?>
                            <?php
                            $kleuren = [
                                'nieuw'    => 'success',
                                'bestaand' => 'secondary',
                                'dubbel'   => 'warning',
                                'ongeldig' => 'danger',
                            ];
                            $status = (string)$item['status'];
                            ?>
                            <tr>
                                <td><span class="badge text-bg-<?= h($kleuren[$status] ?? 'secondary') ?>"><?= h($status) ?></span></td>
                                <td><?= $item['email'] !== '' ? h((string)$item['email']) : '<span class="text-muted">—</span>' ?></td>
                                <td><?= h((string)$item['naam']) ?></td>
                                <td>
                                    <code class="pad"><?= h((string)$item['regel']) ?></code>
                                    <?php if (!empty($item['reden'])): ?>
                                        <div class="small text-danger"><?= h((string)$item['reden']) ?></div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <form method="post" class="border-top pt-3">
                <?= csrf_field() ?>
                <input type="hidden" name="actie" value="bevestigen">
                <input type="hidden" name="jaargang" value="<?= (int)$gekozenId ?>">
                <input type="hidden" name="q" value="<?= h($zoek) ?>">

                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="uitnodiging" name="uitnodiging"
                        value="1" <?= $mailKlaar ? '' : 'disabled' ?>>
                    <label class="form-check-label" for="uitnodiging">
                        Stuur direct een uitnodigingsmail naar de <strong><?= (int)$telling['nieuw'] ?></strong>
                        nieuw toegevoegde deelnemer(s)
                    </label>
                    <div class="form-text">
                        De mails worden in blokken van <?= (int)TOEGANG_MAIL_BLOKKEN ?> verstuurd.
                        Bij een lange lijst kan het een halve minuut of langer duren voordat de pagina terugkomt —
                        sluit het venster in die tijd niet. Wilt u later mailen? Laat dit uit en gebruik daarna de
                        knop "Uitnodiging versturen" per rij.
                    </div>
                </div>

                <button type="submit" class="btn btn-djm">
                    <i class="bi bi-check2-circle me-1"></i>
                    <?= (int)$telling['nieuw'] ?> deelnemer(s) toevoegen aan <?= (int)$jaargang['jaar'] ?>
                </button>
                <button type="submit" name="actie" value="annuleren" class="btn btn-outline-secondary ms-2"
                    formnovalidate>Annuleren</button>
            </form>
        </div>
    <?php else: ?>

        <!-- ─── Stap 1: importeren ───────────────────────────────────────── -->
        <div class="kaart p-4 mb-4">
            <h2 class="h6 text-uppercase text-muted mb-3">Stap 1 — e-mailadressen invoeren</h2>
            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="actie" value="voorbeeld">
                <input type="hidden" name="jaargang" value="<?= (int)$gekozenId ?>">
                <input type="hidden" name="q" value="<?= h($zoek) ?>">

                <div class="row g-4">
                    <div class="col-lg-8">
                        <label class="form-label" for="adressen">Plakken</label>
                        <textarea class="form-control font-monospace" id="adressen" name="adressen" rows="10"
                            placeholder="jan@example.nl&#10;Jan Jansen <jan@example.nl>&#10;piet@example.nl;Piet Pieters&#10;&quot;Jansen, Marie&quot; <marie@example.nl>&#10;an@example.nl, bo@example.nl"></textarea>
                        <div class="form-text">
                            Eén adres per regel, of meerdere gescheiden door komma's of puntkomma's.
                            Een naam mag ervoor of erachter staan. Regels die met <code>#</code> beginnen
                            worden overgeslagen.
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label" for="csv">…of een CSV-bestand</label>
                        <input class="form-control" type="file" id="csv" name="csv" accept=".csv,text/csv,text/plain">
                        <div class="form-text">
                            Kolommen <code>email</code> en optioneel <code>naam</code>, met of zonder kopregel,
                            komma of puntkomma als scheidingsteken. Maximaal 5 MB.
                            Is er een bestand gekozen, dan wordt het tekstvak genegeerd.
                        </div>
                    </div>
                </div>

                <div class="mt-3">
                    <button type="submit" class="btn btn-djm">
                        <i class="bi bi-eye me-1"></i>Voorbeeld tonen
                    </button>
                    <span class="text-muted small ms-2">Er wordt nog niets opgeslagen.</span>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <!-- ─── Huidige toegangslijst ────────────────────────────────────────── -->
    <div class="kaart p-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <h2 class="h6 text-uppercase text-muted mb-0">
                Toegang tot <?= (int)$jaargang['jaar'] ?>
                <span class="text-body-secondary">(<?= (int)$totaal ?>)</span>
            </h2>
            <a class="btn btn-outline-secondary btn-sm" href="<?= h(toegang_link(['actie' => 'export', 'pagina' => null])) ?>">
                <i class="bi bi-download me-1"></i>Exporteren als CSV
            </a>
        </div>

        <?php if (!$rijen): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-person-slash fs-2 d-block mb-2"></i>
                <?php if ($zoek !== ''): ?>
                    Geen resultaten voor "<?= h($zoek) ?>".
                    <a href="<?= h(toegang_link(['q' => null, 'pagina' => null])) ?>">Filter wissen</a>
                <?php else: ?>
                    Nog niemand heeft toegang tot deze jaargang. Voeg hierboven e-mailadressen toe.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover tabel-compact align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>E-mailadres</th>
                            <th>Naam</th>
                            <th>Toegevoegd</th>
                            <th>Laatst ingelogd</th>
                            <th class="text-end">Acties</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rijen as $rij): ?>
                            <tr>
                                <td>
                                    <a href="<?= h(url('admin/deelnemers.php?id=' . (int)$rij['id'])) ?>">
                                        <?= h((string)$rij['email']) ?>
                                    </a>
                                    <?php if ((int)$rij['geblokkeerd'] === 1): ?>
                                        <span class="badge text-bg-danger ms-1">geblokkeerd</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= h((string)($rij['naam'] ?? '')) ?: '<span class="text-muted">—</span>' ?></td>
                                <td class="small">
                                    <?= h(formatteer_datum((string)$rij['toegevoegd_op'], false)) ?>
                                    <?php if (!empty($rij['toegevoegd_door'])): ?>
                                        <div class="text-muted">door <?= h((string)$rij['toegevoegd_door']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="small"><?= h(formatteer_datum($rij['laatst_ingelogd_op'] ?? null)) ?></td>
                                <td class="text-end text-nowrap">
                                    <form method="post" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="actie" value="uitnodiging">
                                        <input type="hidden" name="jaargang" value="<?= (int)$gekozenId ?>">
                                        <input type="hidden" name="q" value="<?= h($zoek) ?>">
                                        <input type="hidden" name="pagina" value="<?= (int)$pagina ?>">
                                        <input type="hidden" name="deelnemer_id" value="<?= (int)$rij['id'] ?>">
                                        <button class="btn btn-sm btn-outline-secondary" type="submit"
                                            <?= $mailKlaar ? '' : 'disabled' ?>
                                            title="Uitnodiging (opnieuw) versturen">
                                            <i class="bi bi-envelope"></i>
                                        </button>
                                    </form>
                                    <form method="post" class="d-inline"
                                        onsubmit="return confirm('Toegang van <?= h((string)$rij['email']) ?> tot <?= (int)$jaargang['jaar'] ?> intrekken?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="actie" value="intrekken">
                                        <input type="hidden" name="jaargang" value="<?= (int)$gekozenId ?>">
                                        <input type="hidden" name="q" value="<?= h($zoek) ?>">
                                        <input type="hidden" name="pagina" value="<?= (int)$pagina ?>">
                                        <input type="hidden" name="deelnemer_id" value="<?= (int)$rij['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" type="submit"
                                            title="Toegang tot deze jaargang intrekken">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($pagina_totaal > 1): ?>
                <nav class="mt-3">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $pagina <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= h(toegang_link(['pagina' => max(1, $pagina - 1)])) ?>">Vorige</a>
                        </li>
                        <?php for ($p = max(1, $pagina - 4); $p <= min($pagina_totaal, $pagina + 4); $p++): ?>
                            <li class="page-item <?= $p === $pagina ? 'active' : '' ?>">
                                <a class="page-link" href="<?= h(toegang_link(['pagina' => $p])) ?>"><?= (int)$p ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $pagina >= $pagina_totaal ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= h(toegang_link(['pagina' => min($pagina_totaal, $pagina + 1)])) ?>">Volgende</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>

<?php endif; ?>

<?php admin_eind(); ?>
