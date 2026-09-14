<?php

/**
 * Beheer > Logboek — inloggen, e-mail en downloads.
 *
 * Eén pagina met drie tabbladen (?tab=logins|mails|downloads), elk met een
 * datumfilter, een zoekveld op e-mailadres en paginering. Bovenaan zit de knop
 * om regels ouder dan de bewaartermijn op te ruimen.
 */

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/includes/layout.php';

vereis_installatie();
$beheerder = vereis_beheerder();

const LOGBOEK_PER_PAGINA = 50;

$tabbladen = [
    'logins'    => ['Inloggen',  'bi-box-arrow-in-right'],
    'mails'     => ['E-mail',    'bi-envelope'],
    'downloads' => ['Downloads', 'bi-download'],
];

$tab = (string)($_GET['tab'] ?? 'logins');
if (!isset($tabbladen[$tab])) {
    $tab = 'logins';
}

$zoek   = trim((string)($_GET['q'] ?? ''));
$van    = trim((string)($_GET['van'] ?? ''));
$tot    = trim((string)($_GET['tot'] ?? ''));
$pagina = max(1, (int)($_GET['pagina'] ?? 1));

// Datums alleen accepteren in de vorm jjjj-mm-dd.
if ($van !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $van)) {
    $van = '';
}
if ($tot !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tot)) {
    $tot = '';
}

$bewaartermijn = max(7, instelling_int('log_bewaartermijn_dagen', 365));

/** Link naar deze pagina met de huidige filters. */
function logboek_link(array $extra = []): string
{
    global $tab, $zoek, $van, $tot, $pagina;
    $params = ['tab' => $tab];
    if ($zoek !== '') {
        $params['q'] = $zoek;
    }
    if ($van !== '') {
        $params['van'] = $van;
    }
    if ($tot !== '') {
        $params['tot'] = $tot;
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
    return url('admin/logboek.php') . '?' . http_build_query($params);
}

/**
 * Label en kleur bij de reden waarom een download stopte.
 *
 * Let op de bewoording bij `client_gestopt`: PHP merkt alleen dát de verbinding
 * wegviel, niet wie hem verbrak. Een bezoeker die afsluit en een proxy die
 * stopt met lezen zien er van binnenuit hetzelfde uit. Daarom "verbinding
 * verbroken" en niet "bezoeker stopte" — het blok bovenaan de tabel wijst het
 * verschil aan.
 */
function download_reden_label(?string $reden): array
{
    switch ((string)$reden) {
        case 'voltooid':
            return ['voltooid', 'success', 'Alle bytes zijn verstuurd.'];
        case 'client_gestopt':
            return ['verbinding verbroken', 'warning',
                'De verbinding viel weg voordat het bestand op was. Dat kan de bezoeker zijn, '
                . 'maar ook iets tussen ons en de bezoeker in.'];
        case 'server_gestopt':
            return ['server brak af', 'danger',
                'Onze kant stopte: een leesfout op de schijf, een tijdslimiet of een afgeschoten proces.'];
        case 'webserver':
            return ['niet gemeten', 'secondary',
                'De webserver leverde dit bestand uit (X-Accel of X-Sendfile). Het portaal ziet dan '
                . 'geen bytes voorbijkomen en kan niet zeggen hoe ver de bezoeker kwam.'];
        case 'bezig':
            return ['bezig', 'info',
                'Loopt nog, of het proces is afgeschoten zonder zich af te melden. '
                . 'Kijk naar het tijdstip.'];
        default:
            return ['onbekend', 'light',
                'Van vóór het bijhouden van de reden.'];
    }
}

/**
 * Zoekt in de afgebroken downloads naar één plek waar ze allemaal stranden.
 *
 * Een download die afbreekt doordat iemand zijn laptop dichtklapt of door een
 * haperende wifi, stopt elke keer ergens anders. Stoppen ze daarentegen allemaal
 * rond hetzelfde aantal bytes, dan is er een grens op de server — de klassieke
 * is een nginx die vóór Apache staat en na `proxy_max_temp_file_size` (standaard
 * 1 GB) stopt met lezen. Dát verschil is precies wat een beheerder moet zien:
 * moet hij de ouder een betere verbinding adviseren, of de technisch beheerder
 * bellen?
 *
 * @param  array $regels rijen met 'verzonden' en 'totaal'
 * @return array|null    null als er geen patroon in zit
 */
function downloads_knelpunt(array $regels): ?array
{
    $bytes = [];
    foreach ($regels as $regel) {
        $bytes[] = (int)$regel['verzonden'];
    }
    if (count($bytes) < 3) {
        return null;
    }
    sort($bytes);

    // Grootste groep waarvan de hoogste waarde hooguit een tiende boven de
    // laagste ligt. Een venster dat met de waarden meeschaalt in plaats van een
    // vast aantal megabytes: bij een video van zeven gigabyte is honderd
    // megabyte verschil ruis, bij een pdf van tien megabyte is het alles.
    $begin = 0;
    $eind  = 0;
    $onder = 0;
    foreach ($bytes as $boven => $waarde) {
        while ($waarde > $bytes[$onder] * 1.1) {
            $onder++;
        }
        if ($boven - $onder > $eind - $begin) {
            $begin = $onder;
            $eind  = $boven;
        }
    }

    // Minstens drie stops, en samen een derde van alle afgebroken downloads.
    // Geen meerderheid eisen: er zijn altijd mensen die een download meteen na
    // het starten wegklikken, en die stops zouden een echt knelpunt anders
    // wegdrukken. Drie downloads die binnen een tiende van elkaar stoppen, is
    // op een bestand van gigabytes al geen toeval meer.
    $aantal = $eind - $begin + 1;
    if ($aantal < 3 || $aantal / count($bytes) < 1 / 3) {
        return null;
    }

    return [
        'aantal'     => $aantal,
        'van'        => $bytes[$begin],
        'tot'        => $bytes[$eind],
        'afgebroken' => count($bytes),
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
//  Opruimen (POST)
// ═══════════════════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    vereis_csrf();

    if ((string)($_POST['actie'] ?? '') === 'opruimen') {
        if (empty($_POST['bevestigd'])) {
            flash('warning', 'Opruimen is afgebroken: de bevestiging ontbrak.');
        } else {
            // Grensdatum in PHP berekenen en als waarde binden.
            $grens = date('Y-m-d H:i:s', strtotime('-' . $bewaartermijn . ' days'));

            $verwijderd = 0;
            $perTabel   = [];
            $tabellen = [
                'login_log'    => 'tijdstip',
                'mail_log'     => 'verzonden_op',
                'download_log' => 'gestart_op',
            ];
            foreach ($tabellen as $tabel => $kolom) {
                try {
                    $stmt = db()->prepare("DELETE FROM {$tabel} WHERE {$kolom} < :grens");
                    $stmt->execute([':grens' => $grens]);
                    $aantal = $stmt->rowCount();
                    $perTabel[] = $tabel . ': ' . $aantal;
                    $verwijderd += $aantal;
                } catch (Throwable $e) {
                    app_log('logboek opruimen mislukt', ['tabel' => $tabel, 'fout' => $e->getMessage()]);
                    $perTabel[] = $tabel . ': mislukt';
                }
            }

            flash(
                'success',
                sprintf(
                    '%d logregel(s) ouder dan %d dagen verwijderd (%s).',
                    $verwijderd,
                    $bewaartermijn,
                    implode(', ', $perTabel)
                )
            );
        }
    }

    header('Location: ' . url('admin/logboek.php?tab=' . urlencode($tab)));
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
//  Gegevens ophalen
// ═══════════════════════════════════════════════════════════════════════════

$configuratie = [
    'logins'    => ['tabel' => 'login_log',    'datum' => 'tijdstip',     'email' => 'email'],
    'mails'     => ['tabel' => 'mail_log',     'datum' => 'verzonden_op', 'email' => 'ontvanger'],
    'downloads' => ['tabel' => 'download_log', 'datum' => 'gestart_op',   'email' => 'email'],
];

$tabel      = $configuratie[$tab]['tabel'];
$datumKolom = $configuratie[$tab]['datum'];
$mailKolom  = $configuratie[$tab]['email'];

// Alles krijgt de alias l, zodat de downloadquery er zonder gedoe
// jaargang_bestanden bij kan joinen voor de bestandsgrootte.
$alias  = 'l.';
$waar   = ['1 = 1'];
$params = [];

if ($zoek !== '') {
    $waar[] = $alias . $mailKolom . ' LIKE :zoek';
    $params[':zoek'] = '%' . $zoek . '%';
}
if ($van !== '') {
    $waar[] = $alias . $datumKolom . ' >= :van';
    $params[':van'] = $van . ' 00:00:00';
}
if ($tot !== '') {
    $waar[] = $alias . $datumKolom . ' <= :tot';
    $params[':tot'] = $tot . ' 23:59:59';
}
$waarSql = implode(' AND ', $waar);

$telStmt = db()->prepare('SELECT COUNT(*) FROM ' . $tabel . ' l WHERE ' . $waarSql);
$telStmt->execute($params);
$totaal = (int)$telStmt->fetchColumn();

$paginaTotaal = max(1, (int)ceil($totaal / LOGBOEK_PER_PAGINA));
$pagina       = min($pagina, $paginaTotaal);
$offset       = ($pagina - 1) * LOGBOEK_PER_PAGINA;

// Bij downloads hoort de grootte van het bestand erbij: zonder die noemer zegt
// "1,01 GB verzonden" niets over hoe ver iemand gekomen is.
$isDownloads = $tab === 'downloads';
$selectie    = $isDownloads ? 'l.*, b.bytes AS bestand_bytes' : 'l.*';
$joinSql     = $isDownloads ? ' LEFT JOIN jaargang_bestanden b ON b.id = l.bestand_id' : '';

$stmt = db()->prepare(
    'SELECT ' . $selectie . ' FROM ' . $tabel . ' l' . $joinSql . '
     WHERE ' . $waarSql . '
     ORDER BY ' . $alias . $datumKolom . ' DESC, l.id DESC
     LIMIT ' . (int)LOGBOEK_PER_PAGINA . ' OFFSET ' . (int)$offset
);
$stmt->execute($params);
$rijen = $stmt->fetchAll();

// ─── Downloads: waar gaat het mis? ───────────────────────────────────────────
$downloadCijfers = null;
$downloadKnelpunt = null;

if ($isDownloads) {
    $cijferStmt = db()->prepare(
        "SELECT COUNT(*) AS aantal,
                SUM(CASE WHEN l.afgerond = 1 THEN 1 ELSE 0 END) AS afgerond,
                SUM(CASE WHEN l.methode = 'php' THEN 1 ELSE 0 END) AS gemeten,
                SUM(CASE WHEN l.reden = 'client_gestopt' THEN 1 ELSE 0 END) AS verbroken,
                SUM(CASE WHEN l.reden = 'server_gestopt' THEN 1 ELSE 0 END) AS serverfout,
                SUM(CASE WHEN l.reden = 'bezig' THEN 1 ELSE 0 END) AS bezig,
                SUM(CASE WHEN l.reden = 'webserver' THEN 1 ELSE 0 END) AS ongemeten
           FROM download_log l
          WHERE " . $waarSql
    );
    $cijferStmt->execute($params);
    $downloadCijfers = $cijferStmt->fetch() ?: null;

    // Alleen de PHP-uitlevering telt de bytes echt; bij xaccel/xsendfile doet de
    // webserver het werk en weten wij niet waar een download bleef steken.
    // Ook de regels die PHP als "verbinding verbroken" noteerde tellen mee. PHP
    // ziet namelijk alleen dát de verbinding wegviel, niet wie hem verbrak: een
    // proxy die ertussen zit en stopt met lezen, ziet er precies zo uit als een
    // bezoeker die zijn laptop dichtklapt. Juist het patroon verraadt het
    // verschil — vandaar dat we hier alles wat niet afliep bij elkaar leggen.
    $brokStmt = db()->prepare(
        "SELECT l.bytes_verzonden AS verzonden, b.bytes AS totaal
           FROM download_log l
           LEFT JOIN jaargang_bestanden b ON b.id = l.bestand_id
          WHERE " . $waarSql . "
            AND l.methode = 'php' AND l.afgerond = 0 AND l.bytes_verzonden > 0
          ORDER BY l.id DESC
          LIMIT 500"
    );
    $brokStmt->execute($params);
    $downloadKnelpunt = downloads_knelpunt($brokStmt->fetchAll());
}

// Hoeveel regels vallen er nu buiten de bewaartermijn?
$grensDatum = date('Y-m-d H:i:s', strtotime('-' . $bewaartermijn . ' days'));
$teOud = 0;
foreach ($configuratie as $conf) {
    try {
        $oudStmt = db()->prepare(
            'SELECT COUNT(*) FROM ' . $conf['tabel'] . ' WHERE ' . $conf['datum'] . ' < :grens'
        );
        $oudStmt->execute([':grens' => $grensDatum]);
        $teOud += (int)$oudStmt->fetchColumn();
    } catch (Throwable $e) {
        // niet blokkerend
    }
}

admin_start('Logboek', 'Inlogpogingen, verstuurde e-mail en downloads');
?>

<div class="kaart p-3 mb-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div class="small text-muted">
            Bewaartermijn: <strong><?= (int)$bewaartermijn ?> dagen</strong>
            (<a href="<?= h(url('admin/instellingen.php')) ?>">aanpassen</a>).
            <?php if ($teOud > 0): ?>
                Er staan nu <strong><?= (int)$teOud ?></strong> regel(s) buiten die termijn.
            <?php else: ?>
                Er staan geen regels buiten die termijn.
            <?php endif; ?>
        </div>
        <button class="btn btn-outline-danger btn-sm" type="button"
            data-bs-toggle="modal" data-bs-target="#opruimModal" <?= $teOud > 0 ? '' : 'disabled' ?>>
            <i class="bi bi-eraser me-1"></i>Oude regels opruimen
        </button>
    </div>
</div>

<ul class="nav nav-tabs mb-3">
    <?php foreach ($tabbladen as $sleutel => [$label, $icoon]): ?>
        <li class="nav-item">
            <a class="nav-link <?= $tab === $sleutel ? 'active' : '' ?>"
                href="<?= h(url('admin/logboek.php?tab=' . urlencode($sleutel))) ?>">
                <i class="bi <?= h($icoon) ?> me-1"></i><?= h($label) ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<div class="kaart p-3 mb-4">
    <form method="get" class="row g-2 align-items-end">
        <input type="hidden" name="tab" value="<?= h($tab) ?>">
        <div class="col-sm-6 col-lg-4">
            <label class="form-label small text-muted mb-1" for="zoekveld">E-mailadres bevat</label>
            <input type="search" class="form-control" id="zoekveld" name="q"
                value="<?= h($zoek) ?>" placeholder="bijv. example.nl">
        </div>
        <div class="col-sm-3 col-lg-2">
            <label class="form-label small text-muted mb-1" for="vanveld">Vanaf</label>
            <input type="date" class="form-control" id="vanveld" name="van" value="<?= h($van) ?>">
        </div>
        <div class="col-sm-3 col-lg-2">
            <label class="form-label small text-muted mb-1" for="totveld">Tot en met</label>
            <input type="date" class="form-control" id="totveld" name="tot" value="<?= h($tot) ?>">
        </div>
        <div class="col-lg-4">
            <button class="btn btn-djm" type="submit"><i class="bi bi-funnel me-1"></i>Filteren</button>
            <?php if ($zoek !== '' || $van !== '' || $tot !== ''): ?>
                <a class="btn btn-outline-secondary"
                    href="<?= h(url('admin/logboek.php?tab=' . urlencode($tab))) ?>">Wissen</a>
            <?php endif; ?>
            <span class="text-muted small ms-2"><?= (int)$totaal ?> regel(s)</span>
        </div>
    </form>
</div>

<div class="kaart p-4">
    <?php if (!$rijen): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-journal-text fs-2 d-block mb-2"></i>
            <?php if ($zoek !== '' || $van !== '' || $tot !== ''): ?>
                Geen regels gevonden met deze filters.
            <?php else: ?>
                Dit logboek is nog leeg.
            <?php endif; ?>
        </div>

    <?php elseif ($tab === 'logins'): ?>
        <!-- ─── Inloggen ─────────────────────────────────────────────────── -->
        <div class="table-responsive">
            <table class="table table-sm table-hover tabel-compact align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Tijdstip</th>
                        <th>E-mailadres</th>
                        <th>Soort</th>
                        <th class="text-center">Gelukt</th>
                        <th>Detail</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rijen as $rij): ?>
                        <tr>
                            <td class="small text-nowrap"><?= h(formatteer_datum((string)$rij['tijdstip'])) ?></td>
                            <td class="small"><?= h((string)($rij['email'] ?? '')) ?: '<span class="text-muted">—</span>' ?></td>
                            <td class="small"><code class="pad"><?= h((string)$rij['soort']) ?></code></td>
                            <td class="text-center">
                                <?php if ((int)$rij['gelukt'] === 1): ?>
                                    <span class="badge text-bg-success">ja</span>
                                <?php else: ?>
                                    <span class="badge text-bg-danger">nee</span>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?= h((string)($rij['detail'] ?? '')) ?></td>
                            <td class="small text-nowrap"><?= h(ip_leesbaar($rij['ip'] ?? null)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($tab === 'mails'): ?>
        <!-- ─── E-mail ───────────────────────────────────────────────────── -->
        <div class="table-responsive">
            <table class="table table-sm table-hover tabel-compact align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Tijdstip</th>
                        <th>Ontvanger</th>
                        <th>Onderwerp</th>
                        <th>Soort</th>
                        <th>Status</th>
                        <th>Methode</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rijen as $rij): ?>
                        <?php $mislukt = strtolower((string)$rij['status']) !== 'verzonden'; ?>
                        <tr>
                            <td class="small text-nowrap"><?= h(formatteer_datum((string)$rij['verzonden_op'])) ?></td>
                            <td class="small"><?= h((string)$rij['ontvanger']) ?></td>
                            <td class="small"><?= h((string)$rij['onderwerp']) ?></td>
                            <td class="small"><code class="pad"><?= h((string)$rij['soort']) ?></code></td>
                            <td class="small">
                                <span class="badge text-bg-<?= $mislukt ? 'danger' : 'success' ?>">
                                    <?= h((string)$rij['status']) ?>
                                </span>
                            </td>
                            <td class="small"><?= h((string)($rij['methode'] ?? '—')) ?></td>
                        </tr>
                        <?php if (!empty($rij['foutmelding'])): ?>
                            <tr class="table-light">
                                <td colspan="6" class="small">
                                    <a class="link-danger" data-bs-toggle="collapse"
                                        href="#fout<?= (int)$rij['id'] ?>" role="button">
                                        <i class="bi bi-chevron-down me-1"></i>Foutmelding tonen
                                    </a>
                                    <div class="collapse mt-2" id="fout<?= (int)$rij['id'] ?>">
                                        <pre class="mb-0 p-2 bg-white border rounded"
                                            style="white-space:pre-wrap;word-break:break-all;font-size:.75rem;"><?= h((string)$rij['foutmelding']) ?></pre>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php else: ?>
        <!-- ─── Downloads ────────────────────────────────────────────────── -->
        <?php
        $dlAantal    = (int)($downloadCijfers['aantal'] ?? 0);
        $dlAf        = (int)($downloadCijfers['afgerond'] ?? 0);
        $dlGemeten   = (int)($downloadCijfers['gemeten'] ?? 0);
        $dlVerbroken = (int)($downloadCijfers['verbroken'] ?? 0);
        $dlServer    = (int)($downloadCijfers['serverfout'] ?? 0);
        $dlBezig     = (int)($downloadCijfers['bezig'] ?? 0);
        $dlOngemeten = (int)($downloadCijfers['ongemeten'] ?? 0);
        $dlNiet      = max(0, $dlAantal - $dlAf);
        ?>
        <?php if ($dlAantal > 0): ?>
            <div class="alert <?= $downloadKnelpunt !== null ? 'alert-danger' : ($dlNiet > 0 ? 'alert-warning' : 'alert-success') ?> py-2 small">
                <div class="fw-semibold mb-1">
                    <i class="bi bi-activity me-1"></i>
                    <?= (int)$dlAf ?> van <?= (int)$dlAantal ?> downloads afgerond<?php
                    ?><?= $dlNiet > 0 ? ', ' . (int)$dlNiet . ' niet' : '' ?>.
                </div>
                <?php if ($dlVerbroken + $dlServer + $dlBezig + $dlOngemeten > 0): ?>
                    <p class="mb-1">
                        Waarvan
                        <?php if ($dlServer > 0): ?>
                            <span class="badge text-bg-danger"><?= (int)$dlServer ?> server brak af</span>
                        <?php endif; ?>
                        <?php if ($dlVerbroken > 0): ?>
                            <span class="badge text-bg-warning"><?= (int)$dlVerbroken ?> verbinding verbroken</span>
                        <?php endif; ?>
                        <?php if ($dlBezig > 0): ?>
                            <span class="badge text-bg-info"><?= (int)$dlBezig ?> bezig</span>
                        <?php endif; ?>
                        <?php if ($dlOngemeten > 0): ?>
                            <span class="badge text-bg-secondary"><?= (int)$dlOngemeten ?> niet gemeten</span>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <?php if ($downloadKnelpunt !== null):
                    $knelVan = formatteer_bytes((int)$downloadKnelpunt['van']);
                    $knelTot = formatteer_bytes((int)$downloadKnelpunt['tot']);
                    // Liggen ze zo dicht bij elkaar dat er afgerond hetzelfde
                    // staat, dan leest "tussen 1,01 GB en 1,01 GB" als een fout.
                    $knelBereik = $knelVan === $knelTot
                        ? 'rond ' . $knelVan
                        : 'tussen ' . $knelVan . ' en ' . $knelTot;
                    ?>
                    <p class="mb-1">
                        <strong><?= (int)$downloadKnelpunt['aantal'] ?> van de
                        <?= (int)$downloadKnelpunt['afgebroken'] ?> afgebroken downloads</strong> stopte
                        <?= h($knelBereik) ?>. Downloads die op de verbinding
                        van de bezoeker stuklopen, stoppen elke keer ergens anders; stoppen ze allemaal rond
                        hetzelfde punt, dan zit er een <strong>grens op de server</strong>. Dat geldt ook als
                        er hieronder <em>verbinding verbroken</em> staat: het portaal ziet alleen dát de
                        verbinding wegviel, en een proxy die stopt met lezen ziet er hetzelfde uit als een
                        bezoeker die afhaakt. Bij zoveel stops op dezelfde plek is het de proxy.
                    </p>
                    <p class="mb-0">
                        Meestal is dat een nginx die vóór Apache staat en het antwoord eerst naar een tijdelijk
                        bestand schrijft (standaard tot 1 GB). In het foutlogboek van de webserver staat dan
                        <code>upstream prematurely closed connection</code>. Oplossing: zet bij de
                        nginx-instellingen <code>proxy_buffering off;</code> en
                        <code>proxy_max_temp_file_size 0;</code>, of zet de uitlevering op X-Sendfile of
                        X-Accel — zie
                        <a href="<?= h(url('admin/instellingen.php')) ?>">Instellingen → Uitlevering</a>,
                        de knop <em>Uitproberen</em> daar, en hoofdstuk 7b van
                        <code>docs/INSTALLATIE.md</code>.
                    </p>
                <?php elseif ($dlNiet > 0): ?>
                    <p class="mb-0">
                        De afgebroken downloads stoppen op steeds verschillende plekken. Dat wijst op de
                        verbinding van de bezoeker en niet op de server: een onderbroken download is te
                        hervatten, ook een dag later.
                    </p>
                <?php else: ?>
                    <p class="mb-0">Geen afgebroken downloads in deze selectie.</p>
                <?php endif; ?>
                <?php if ($dlGemeten < $dlAantal): ?>
                    <p class="mb-0 mt-1 text-muted">
                        Let op: bij <?= (int)($dlAantal - $dlGemeten) ?> regel(s) deed de webserver de
                        uitlevering (X-Accel of X-Sendfile). Er komt dan geen byte langs het portaal, dus hoe
                        ver die bezoekers kwamen is niet vast te stellen — die regels staan op
                        <em>niet gemeten</em> en tellen hierboven niet mee. Wilt u het wél weten, zet dan
                        <code>DELIVERY_MODE</code> in <code>.env</code> op <code>php</code>: dan levert het
                        portaal zelf uit en meet het elke byte.
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover tabel-compact align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Tijdstip</th>
                        <th>E-mailadres</th>
                        <th>Bestandsnaam</th>
                        <th>Methode</th>
                        <th class="text-end">Verzonden</th>
                        <th style="min-width:9rem">Hoe ver gekomen</th>
                        <th>Uitkomst</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rijen as $rij):
                        $verzonden = (int)$rij['bytes_verzonden'];
                        $bestandBytes = (int)($rij['bestand_bytes'] ?? 0);
                        // Het bestand kan intussen vervangen of losgekoppeld zijn;
                        // dan is er geen noemer en tonen we geen percentage.
                        $deel = $bestandBytes > 0 ? min(100, (int)round($verzonden / $bestandBytes * 100)) : null;
                        $reden = $rij['reden'] ?? null;
                        // Oude regels hebben nog geen reden; die viel af te leiden
                        // uit afgerond, en meer weten we er niet van.
                        if ($reden === null && (int)$rij['afgerond'] === 1) {
                            $reden = 'voltooid';
                        }
                        [$label, $kleur, $uitleg] = download_reden_label($reden);
                        ?>
                        <tr>
                            <td class="small text-nowrap"><?= h(formatteer_datum((string)$rij['gestart_op'])) ?></td>
                            <td class="small"><?= h((string)($rij['email'] ?? '')) ?: '<span class="text-muted">—</span>' ?></td>
                            <td class="small"><?= h((string)($rij['bestandsnaam'] ?? '—')) ?></td>
                            <td class="small"><code class="pad"><?= h((string)($rij['methode'] ?? '—')) ?></code></td>
                            <td class="small text-end text-nowrap"><?= h(formatteer_bytes($verzonden)) ?></td>
                            <td class="small">
                                <?php if ($reden === 'webserver'): ?>
                                    <span class="text-muted">niet gemeten</span>
                                <?php elseif ($deel === null): ?>
                                    <span class="text-muted">grootte onbekend</span>
                                <?php else: ?>
                                    <div class="progress" style="height:.45rem" role="progressbar"
                                        aria-valuenow="<?= (int)$deel ?>" aria-valuemin="0" aria-valuemax="100">
                                        <div class="progress-bar bg-<?= h($kleur) ?>"
                                            style="width:<?= (int)$deel ?>%"></div>
                                    </div>
                                    <span class="text-muted"><?= (int)$deel ?>% van
                                        <?= h(formatteer_bytes($bestandBytes)) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge text-bg-<?= h($kleur) ?>" title="<?= h($uitleg) ?>">
                                    <?= h($label) ?>
                                </span>
                            </td>
                            <td class="small text-nowrap"><?= h(ip_leesbaar($rij['ip'] ?? null)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($paginaTotaal > 1): ?>
        <nav class="mt-3">
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= $pagina <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= h(logboek_link(['pagina' => max(1, $pagina - 1)])) ?>">Vorige</a>
                </li>
                <?php for ($p = max(1, $pagina - 4); $p <= min($paginaTotaal, $pagina + 4); $p++): ?>
                    <li class="page-item <?= $p === $pagina ? 'active' : '' ?>">
                        <a class="page-link" href="<?= h(logboek_link(['pagina' => $p])) ?>"><?= (int)$p ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?= $pagina >= $paginaTotaal ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= h(logboek_link(['pagina' => min($paginaTotaal, $pagina + 1)])) ?>">Volgende</a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
</div>

<!-- ─── Bevestiging opruimen ─────────────────────────────────────────────── -->
<div class="modal fade" id="opruimModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="actie" value="opruimen">
            <div class="modal-header">
                <h5 class="modal-title">Oude logregels opruimen</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>
                    Alle regels ouder dan <strong><?= (int)$bewaartermijn ?> dagen</strong>
                    (vóór <?= h(formatteer_datum($grensDatum, false)) ?>) worden verwijderd uit
                    <code>login_log</code>, <code>mail_log</code> en <code>download_log</code>.
                </p>
                <p class="mb-3">
                    Dit gaat nu om ongeveer <strong><?= (int)$teOud ?></strong> regel(s).
                    Deze handeling kan niet ongedaan worden gemaakt.
                </p>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="bevestigdOpruimen" name="bevestigd"
                        value="1" required>
                    <label class="form-check-label" for="bevestigdOpruimen">Ja, verwijder deze regels.</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuleren</button>
                <button type="submit" class="btn btn-danger"><i class="bi bi-eraser me-1"></i>Opruimen</button>
            </div>
        </form>
    </div>
</div>

<?php admin_eind(); ?>
