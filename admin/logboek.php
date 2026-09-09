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

$waar   = ['1 = 1'];
$params = [];

if ($zoek !== '') {
    $waar[] = $mailKolom . ' LIKE :zoek';
    $params[':zoek'] = '%' . $zoek . '%';
}
if ($van !== '') {
    $waar[] = $datumKolom . ' >= :van';
    $params[':van'] = $van . ' 00:00:00';
}
if ($tot !== '') {
    $waar[] = $datumKolom . ' <= :tot';
    $params[':tot'] = $tot . ' 23:59:59';
}
$waarSql = implode(' AND ', $waar);

$telStmt = db()->prepare('SELECT COUNT(*) FROM ' . $tabel . ' WHERE ' . $waarSql);
$telStmt->execute($params);
$totaal = (int)$telStmt->fetchColumn();

$paginaTotaal = max(1, (int)ceil($totaal / LOGBOEK_PER_PAGINA));
$pagina       = min($pagina, $paginaTotaal);
$offset       = ($pagina - 1) * LOGBOEK_PER_PAGINA;

$stmt = db()->prepare(
    'SELECT * FROM ' . $tabel . '
     WHERE ' . $waarSql . '
     ORDER BY ' . $datumKolom . ' DESC, id DESC
     LIMIT ' . (int)LOGBOEK_PER_PAGINA . ' OFFSET ' . (int)$offset
);
$stmt->execute($params);
$rijen = $stmt->fetchAll();

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
        <div class="table-responsive">
            <table class="table table-sm table-hover tabel-compact align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Tijdstip</th>
                        <th>E-mailadres</th>
                        <th>Bestandsnaam</th>
                        <th>Methode</th>
                        <th class="text-end">Verzonden</th>
                        <th class="text-center">Afgerond</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rijen as $rij): ?>
                        <tr>
                            <td class="small text-nowrap"><?= h(formatteer_datum((string)$rij['gestart_op'])) ?></td>
                            <td class="small"><?= h((string)($rij['email'] ?? '')) ?: '<span class="text-muted">—</span>' ?></td>
                            <td class="small"><?= h((string)($rij['bestandsnaam'] ?? '—')) ?></td>
                            <td class="small"><code class="pad"><?= h((string)($rij['methode'] ?? '—')) ?></code></td>
                            <td class="small text-end text-nowrap"><?= h(formatteer_bytes((int)$rij['bytes_verzonden'])) ?></td>
                            <td class="text-center">
                                <?php if ((int)$rij['afgerond'] === 1): ?>
                                    <span class="badge text-bg-success">ja</span>
                                <?php else: ?>
                                    <span class="badge text-bg-warning">nee</span>
                                <?php endif; ?>
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
