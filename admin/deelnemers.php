<?php

/**
 * Beheer > Deelnemers — personen over alle jaargangen heen.
 *
 * Overzicht met zoeken en paginering, plus een detailweergave (?id=) waarin de
 * naam, de toegang per jaargang, de laatste downloads en de laatste
 * inlogpogingen te zien zijn. Blokkeren en definitief verwijderen (AVG) zitten
 * hier ook.
 */

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/toegang_helper.php';

vereis_installatie();
$beheerder = vereis_beheerder();

const DEELNEMERS_PER_PAGINA = 50;
const DEELNEMERS_LOG_AANTAL = 20;

$zoek   = trim((string)($_GET['q'] ?? ''));
$filter = (string)($_GET['filter'] ?? '');       // '' | 'geblokkeerd' | 'zonder_toegang'
$pagina = max(1, (int)($_GET['pagina'] ?? 1));
$detailId = (int)($_GET['id'] ?? 0);

/** Link naar het overzicht met de huidige filters. */
function deelnemers_link(array $extra = []): string
{
    global $zoek, $filter, $pagina;
    $params = [];
    if ($zoek !== '') {
        $params['q'] = $zoek;
    }
    if ($filter !== '') {
        $params['filter'] = $filter;
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
    return url('admin/deelnemers.php') . ($params ? '?' . http_build_query($params) : '');
}

/** Terugkeerlink na een POST, opgebouwd uit de meegestuurde velden. */
function deelnemers_terug_link(int $deelnemerId = 0): string
{
    $params = [];
    if ($deelnemerId > 0) {
        $params['id'] = $deelnemerId;
    }
    $q = trim((string)($_POST['q'] ?? ''));
    if ($q !== '') {
        $params['q'] = $q;
    }
    $f = (string)($_POST['filter'] ?? '');
    if ($f !== '') {
        $params['filter'] = $f;
    }
    $p = (int)($_POST['pagina'] ?? 1);
    if ($p > 1) {
        $params['pagina'] = $p;
    }
    return url('admin/deelnemers.php') . ($params ? '?' . http_build_query($params) : '');
}

function deelnemer_ophalen(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM deelnemers WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

// ═══════════════════════════════════════════════════════════════════════════
//  Verwerking (POST)
// ═══════════════════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    vereis_csrf();
    $actie       = (string)($_POST['actie'] ?? '');
    $deelnemerId = (int)($_POST['deelnemer_id'] ?? 0);
    $deelnemer   = $deelnemerId > 0 ? deelnemer_ophalen($deelnemerId) : null;

    if ($deelnemer === null) {
        flash('danger', 'Deelnemer niet gevonden.');
        header('Location: ' . url('admin/deelnemers.php'));
        exit;
    }

    // ─── Naam en toegang per jaargang opslaan ──────────────────────────────
    if ($actie === 'opslaan') {
        $naam = trim((string)($_POST['naam'] ?? ''));
        db()->prepare('UPDATE deelnemers SET naam = :n WHERE id = :id')
            ->execute([
                ':n'  => $naam !== '' ? mb_substr($naam, 0, 150) : null,
                ':id' => $deelnemerId,
            ]);

        $gekozen = array_map('intval', (array)($_POST['jaargangen'] ?? []));

        $huidigStmt = db()->prepare('SELECT jaargang_id FROM toegang WHERE deelnemer_id = :d');
        $huidigStmt->execute([':d' => $deelnemerId]);
        $huidig = array_map('intval', $huidigStmt->fetchAll(PDO::FETCH_COLUMN));

        $bestaandeIds = array_map(
            'intval',
            db()->query('SELECT id FROM jaargangen')->fetchAll(PDO::FETCH_COLUMN)
        );
        $gekozen = array_values(array_intersect($gekozen, $bestaandeIds));

        $door = (string)($beheerder['naam'] ?? $beheerder['email'] ?? '');
        $toegevoegd = 0;
        $ingetrokken = 0;
        foreach (array_diff($gekozen, $huidig) as $jaargangId) {
            if (toegang_toekennen($deelnemerId, (int)$jaargangId, $door)) {
                $toegevoegd++;
            }
        }
        foreach (array_diff($huidig, $gekozen) as $jaargangId) {
            toegang_intrekken($deelnemerId, (int)$jaargangId);
            $ingetrokken++;
        }

        flash('success', sprintf(
            'Opgeslagen. %d jaargang(en) toegevoegd, %d ingetrokken.',
            $toegevoegd,
            $ingetrokken
        ));
        header('Location: ' . deelnemers_terug_link($deelnemerId));
        exit;
    }

    // ─── Blokkeren / deblokkeren ───────────────────────────────────────────
    if ($actie === 'blokkeren' || $actie === 'deblokkeren') {
        $nieuw = $actie === 'blokkeren' ? 1 : 0;
        db()->prepare('UPDATE deelnemers SET geblokkeerd = :g WHERE id = :id')
            ->execute([':g' => $nieuw, ':id' => $deelnemerId]);

        if ($nieuw === 1) {
            // Openstaande codes en onthoud-tokens meteen ongeldig maken.
            db()->prepare('UPDATE login_codes SET ingetrokken_op = NOW()
                           WHERE email = :e AND gebruikt_op IS NULL AND ingetrokken_op IS NULL')
                ->execute([':e' => (string)$deelnemer['email']]);
            db()->prepare('DELETE FROM remember_tokens WHERE deelnemer_id = :d')
                ->execute([':d' => $deelnemerId]);
            flash('success', $deelnemer['email'] . ' is geblokkeerd en ontvangt geen inlogcodes meer.');
        } else {
            flash('success', $deelnemer['email'] . ' is gedeblokkeerd.');
        }
        header('Location: ' . deelnemers_terug_link($deelnemerId));
        exit;
    }

    // ─── Verwijderen (AVG) ─────────────────────────────────────────────────
    if ($actie === 'verwijderen') {
        if (empty($_POST['bevestigd'])) {
            flash('warning', 'Verwijderen is afgebroken: de bevestiging ontbrak.');
            header('Location: ' . deelnemers_terug_link($deelnemerId));
            exit;
        }

        $email = (string)$deelnemer['email'];
        try {
            // login_codes heeft geen refererende sleutel; die ruimen we zelf op.
            db()->prepare('DELETE FROM login_codes WHERE email = :e')->execute([':e' => $email]);
            // toegang en remember_tokens verdwijnen via ON DELETE CASCADE.
            db()->prepare('DELETE FROM deelnemers WHERE id = :id')->execute([':id' => $deelnemerId]);
            flash('success', $email . ' is verwijderd. Het downloadlogboek is bewaard gebleven.');
        } catch (Throwable $e) {
            app_log('deelnemer verwijderen mislukt', ['id' => $deelnemerId, 'fout' => $e->getMessage()]);
            flash('danger', 'Verwijderen mislukt. Zie logs/app.log.');
        }
        header('Location: ' . deelnemers_terug_link(0));
        exit;
    }

    header('Location: ' . deelnemers_terug_link($deelnemerId));
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
//  Detailweergave
// ═══════════════════════════════════════════════════════════════════════════

if ($detailId > 0) {
    $deelnemer = deelnemer_ophalen($detailId);

    if ($deelnemer === null) {
        admin_start('Deelnemer');
        ?>
        <div class="kaart p-5 text-center">
            <i class="bi bi-question-circle display-5 text-muted"></i>
            <h2 class="h5 mt-3">Deze deelnemer bestaat niet (meer)</h2>
            <a class="btn btn-djm mt-2" href="<?= h(url('admin/deelnemers.php')) ?>">Terug naar het overzicht</a>
        </div>
        <?php
        admin_eind();
        exit;
    }

    $jaargangen = db()->query('SELECT * FROM jaargangen ORDER BY jaar DESC')->fetchAll();

    $stmt = db()->prepare('SELECT jaargang_id, toegevoegd_op, toegevoegd_door FROM toegang WHERE deelnemer_id = :d');
    $stmt->execute([':d' => $detailId]);
    $toegangPerJaargang = [];
    foreach ($stmt->fetchAll() as $rij) {
        $toegangPerJaargang[(int)$rij['jaargang_id']] = $rij;
    }

    $stmt = db()->prepare(
        'SELECT * FROM download_log
         WHERE deelnemer_id = :d OR email = :e
         ORDER BY gestart_op DESC
         LIMIT ' . (int)DEELNEMERS_LOG_AANTAL
    );
    $stmt->execute([':d' => $detailId, ':e' => (string)$deelnemer['email']]);
    $downloads = $stmt->fetchAll();

    $stmt = db()->prepare(
        'SELECT * FROM login_log
         WHERE email = :e
         ORDER BY tijdstip DESC
         LIMIT ' . (int)DEELNEMERS_LOG_AANTAL
    );
    $stmt->execute([':e' => (string)$deelnemer['email']]);
    $inlogpogingen = $stmt->fetchAll();

    $geblokkeerd = (int)$deelnemer['geblokkeerd'] === 1;

    admin_start((string)($deelnemer['naam'] ?: $deelnemer['email']), (string)$deelnemer['email']);
    ?>

    <a class="btn btn-sm btn-outline-secondary mb-3" href="<?= h(url('admin/deelnemers.php')) ?>">
        <i class="bi bi-arrow-left me-1"></i>Alle deelnemers
    </a>

    <?php if ($geblokkeerd): ?>
        <div class="alert alert-danger">
            <i class="bi bi-slash-circle me-1"></i>
            Deze deelnemer is <strong>geblokkeerd</strong>: er wordt geen inlogcode meer verstuurd en
            bestaande sessies en onthoud-tokens zijn ongeldig gemaakt.
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-7">
            <!-- ─── Gegevens en toegang ─────────────────────────────────── -->
            <div class="kaart p-4 mb-4">
                <h2 class="h6 text-uppercase text-muted mb-3">Gegevens en toegang</h2>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="actie" value="opslaan">
                    <input type="hidden" name="deelnemer_id" value="<?= (int)$deelnemer['id'] ?>">

                    <div class="mb-3">
                        <label class="form-label" for="email">E-mailadres</label>
                        <input class="form-control" id="email" type="text"
                            value="<?= h((string)$deelnemer['email']) ?>" disabled>
                        <div class="form-text">
                            Het e-mailadres is de identiteit van de deelnemer en kan niet worden gewijzigd.
                            Klopt het adres niet? Verwijder deze deelnemer en voeg het juiste adres toe.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="naam">Naam</label>
                        <input class="form-control" id="naam" name="naam" maxlength="150"
                            value="<?= h((string)($deelnemer['naam'] ?? '')) ?>" placeholder="bijv. Jan Jansen">
                        <div class="form-text">Wordt gebruikt in de aanhef van de e-mails.</div>
                    </div>

                    <label class="form-label">Toegang tot jaargangen</label>
                    <?php if (!$jaargangen): ?>
                        <p class="text-muted">
                            Er zijn nog geen jaargangen.
                            <a href="<?= h(url('admin/jaargangen.php')) ?>">Maak er eerst een aan</a>.
                        </p>
                    <?php else: ?>
                        <div class="border rounded p-2 mb-3" style="max-height:300px;overflow-y:auto;">
                            <?php foreach ($jaargangen as $jaargang): ?>
                                <?php
                                $heeft = isset($toegangPerJaargang[(int)$jaargang['id']]);
                                [$statusTekst, $statusKleur] = jaargang_status($jaargang);
                                ?>
                                <div class="form-check d-flex align-items-center gap-2 py-1">
                                    <input class="form-check-input mt-0" type="checkbox"
                                        id="jaargang<?= (int)$jaargang['id'] ?>"
                                        name="jaargangen[]" value="<?= (int)$jaargang['id'] ?>"
                                        <?= $heeft ? 'checked' : '' ?>>
                                    <label class="form-check-label flex-grow-1" for="jaargang<?= (int)$jaargang['id'] ?>">
                                        <strong><?= (int)$jaargang['jaar'] ?></strong>
                                        <?= h((string)$jaargang['titel']) ?>
                                        <span class="badge text-bg-<?= h($statusKleur) ?> ms-1"><?= h($statusTekst) ?></span>
                                        <?php if ($heeft): ?>
                                            <span class="text-muted small ms-1">
                                                sinds <?= h(formatteer_datum((string)$toegangPerJaargang[(int)$jaargang['id']]['toegevoegd_op'], false)) ?>
                                            </span>
                                        <?php endif; ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-djm"><i class="bi bi-save me-1"></i>Opslaan</button>
                </form>
            </div>

            <!-- ─── Blokkeren en verwijderen ────────────────────────────── -->
            <div class="kaart p-4">
                <h2 class="h6 text-uppercase text-muted mb-3">Beheer</h2>

                <form method="post" class="mb-4">
                    <?= csrf_field() ?>
                    <input type="hidden" name="actie" value="<?= $geblokkeerd ? 'deblokkeren' : 'blokkeren' ?>">
                    <input type="hidden" name="deelnemer_id" value="<?= (int)$deelnemer['id'] ?>">
                    <button type="submit" class="btn btn-outline-<?= $geblokkeerd ? 'success' : 'warning' ?>">
                        <i class="bi bi-<?= $geblokkeerd ? 'unlock' : 'slash-circle' ?> me-1"></i>
                        <?= $geblokkeerd ? 'Deblokkeren' : 'Blokkeren' ?>
                    </button>
                    <div class="form-text mt-2">
                        Een geblokkeerde deelnemer ontvangt <strong>geen inlogcode</strong> meer en kan dus niet
                        inloggen of downloaden. De toegangsrechten blijven bewaard, zodat deblokkeren alles
                        in één keer herstelt.
                    </div>
                </form>

                <div class="border-top pt-3">
                    <button class="btn btn-outline-danger" type="button"
                        data-bs-toggle="modal" data-bs-target="#verwijderModal">
                        <i class="bi bi-trash me-1"></i>Deelnemer verwijderen
                    </button>
                    <div class="form-text mt-2">
                        Verwijdert de persoon inclusief alle toegangsrechten en onthoud-tokens.
                        Het <strong>downloadlogboek blijft bestaan</strong>: daarin staat alleen een
                        momentopname van het e-mailadres.
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <!-- ─── Downloads ───────────────────────────────────────────── -->
            <div class="kaart p-4 mb-4">
                <h2 class="h6 text-uppercase text-muted mb-3">
                    Laatste <?= (int)DEELNEMERS_LOG_AANTAL ?> downloads
                </h2>
                <?php if (!$downloads): ?>
                    <p class="text-muted mb-0">Nog geen downloads.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm tabel-compact mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Tijdstip</th>
                                    <th>Bestand</th>
                                    <th class="text-end">Verzonden</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($downloads as $rij): ?>
                                    <tr>
                                        <td class="small text-nowrap"><?= h(formatteer_datum((string)$rij['gestart_op'])) ?></td>
                                        <td class="small"><?= h((string)($rij['bestandsnaam'] ?? '—')) ?></td>
                                        <td class="small text-end text-nowrap">
                                            <?= h(formatteer_bytes((int)$rij['bytes_verzonden'])) ?>
                                            <?php if ((int)$rij['afgerond'] === 1): ?>
                                                <i class="bi bi-check-circle text-success ms-1" title="afgerond"></i>
                                            <?php else: ?>
                                                <i class="bi bi-dash-circle text-warning ms-1" title="afgebroken"></i>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ─── Inlogpogingen ───────────────────────────────────────── -->
            <div class="kaart p-4">
                <h2 class="h6 text-uppercase text-muted mb-3">
                    Laatste <?= (int)DEELNEMERS_LOG_AANTAL ?> inlogpogingen
                </h2>
                <?php if (!$inlogpogingen): ?>
                    <p class="text-muted mb-0">Nog geen inlogpogingen.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm tabel-compact mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Tijdstip</th>
                                    <th>Soort</th>
                                    <th>IP</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($inlogpogingen as $rij): ?>
                                    <tr>
                                        <td class="small text-nowrap"><?= h(formatteer_datum((string)$rij['tijdstip'])) ?></td>
                                        <td class="small">
                                            <span class="badge text-bg-<?= (int)$rij['gelukt'] === 1 ? 'success' : 'danger' ?>">
                                                <?= h((string)$rij['soort']) ?>
                                            </span>
                                            <?php if (!empty($rij['detail'])): ?>
                                                <div class="text-muted"><?= h((string)$rij['detail']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small text-nowrap"><?= h(ip_leesbaar($rij['ip'] ?? null)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ─── Bevestiging verwijderen ─────────────────────────────────────── -->
    <div class="modal fade" id="verwijderModal" tabindex="-1">
        <div class="modal-dialog">
            <form class="modal-content" method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="actie" value="verwijderen">
                <input type="hidden" name="deelnemer_id" value="<?= (int)$deelnemer['id'] ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Deelnemer verwijderen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>
                        U staat op het punt <strong><?= h((string)$deelnemer['email']) ?></strong>
                        definitief te verwijderen.
                    </p>
                    <ul class="small">
                        <li>Alle toegangsrechten tot jaargangen vervallen.</li>
                        <li>Onthoud-dit-apparaat-tokens en openstaande inlogcodes worden gewist.</li>
                        <li>Het <strong>downloadlogboek blijft bewaard</strong>; daarin staat alleen een
                            momentopname van het e-mailadres, als verantwoording van wie wat heeft opgehaald.</li>
                        <li>Deze handeling kan niet ongedaan worden gemaakt.</li>
                    </ul>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="bevestigd" name="bevestigd"
                            value="1" required>
                        <label class="form-check-label" for="bevestigd">
                            Ja, ik weet het zeker.
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuleren</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-1"></i>Definitief verwijderen
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php
    admin_eind();
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
//  Overzicht
// ═══════════════════════════════════════════════════════════════════════════

$waar   = ['1 = 1'];
$params = [];

if ($zoek !== '') {
    $waar[] = '(d.email LIKE :zoek OR d.naam LIKE :zoek)';
    $params[':zoek'] = '%' . $zoek . '%';
}
if ($filter === 'geblokkeerd') {
    $waar[] = 'd.geblokkeerd = 1';
} elseif ($filter === 'zonder_toegang') {
    $waar[] = 'NOT EXISTS (SELECT 1 FROM toegang t WHERE t.deelnemer_id = d.id)';
}
$waarSql = implode(' AND ', $waar);

$telStmt = db()->prepare('SELECT COUNT(*) FROM deelnemers d WHERE ' . $waarSql);
$telStmt->execute($params);
$totaal = (int)$telStmt->fetchColumn();

$paginaTotaal = max(1, (int)ceil($totaal / DEELNEMERS_PER_PAGINA));
$pagina       = min($pagina, $paginaTotaal);
$offset       = ($pagina - 1) * DEELNEMERS_PER_PAGINA;

$stmt = db()->prepare(
    'SELECT d.*,
            (SELECT COUNT(*) FROM toegang t WHERE t.deelnemer_id = d.id) AS aantal_jaargangen
     FROM deelnemers d
     WHERE ' . $waarSql . '
     ORDER BY d.email ASC
     LIMIT ' . (int)DEELNEMERS_PER_PAGINA . ' OFFSET ' . (int)$offset
);
$stmt->execute($params);
$rijen = $stmt->fetchAll();

$totaalAlles = (int)db()->query('SELECT COUNT(*) FROM deelnemers')->fetchColumn();

admin_start('Deelnemers', $totaalAlles . ' persoon/personen in het portaal');
?>

<div class="kaart p-3 mb-4">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-sm-6 col-lg-5">
            <label class="form-label small text-muted mb-1" for="zoekveld">Zoeken</label>
            <div class="input-group">
                <input type="search" class="form-control" id="zoekveld" name="q"
                    value="<?= h($zoek) ?>" placeholder="e-mailadres of naam">
                <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
            </div>
        </div>
        <div class="col-sm-6 col-lg-4">
            <label class="form-label small text-muted mb-1" for="filterveld">Filter</label>
            <select class="form-select" id="filterveld" name="filter" onchange="this.form.submit()">
                <option value="">Alle deelnemers</option>
                <option value="geblokkeerd" <?= $filter === 'geblokkeerd' ? 'selected' : '' ?>>Alleen geblokkeerd</option>
                <option value="zonder_toegang" <?= $filter === 'zonder_toegang' ? 'selected' : '' ?>>Zonder toegang tot enige jaargang</option>
            </select>
        </div>
        <div class="col-lg-3 text-lg-end">
            <span class="text-muted small"><?= (int)$totaal ?> resultaat/resultaten</span>
        </div>
    </form>
</div>

<div class="kaart p-4">
    <?php if (!$rijen): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-people fs-2 d-block mb-2"></i>
            <?php if ($zoek !== '' || $filter !== ''): ?>
                Geen deelnemers gevonden met deze filters.
                <a href="<?= h(url('admin/deelnemers.php')) ?>">Filters wissen</a>
            <?php else: ?>
                Er zijn nog geen deelnemers. Voeg ze toe via
                <a href="<?= h(url('admin/toegang.php')) ?>">Toegang</a>.
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover tabel-compact align-middle">
                <thead class="table-light">
                    <tr>
                        <th>E-mailadres</th>
                        <th>Naam</th>
                        <th class="text-center">Jaargangen</th>
                        <th>Laatst ingelogd</th>
                        <th class="text-center">Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rijen as $rij): ?>
                        <tr>
                            <td>
                                <a href="<?= h(deelnemers_link(['id' => (int)$rij['id']])) ?>">
                                    <?= h((string)$rij['email']) ?>
                                </a>
                            </td>
                            <td><?= h((string)($rij['naam'] ?? '')) ?: '<span class="text-muted">—</span>' ?></td>
                            <td class="text-center">
                                <span class="badge text-bg-<?= (int)$rij['aantal_jaargangen'] > 0 ? 'primary' : 'secondary' ?>">
                                    <?= (int)$rij['aantal_jaargangen'] ?>
                                </span>
                            </td>
                            <td class="small"><?= h(formatteer_datum($rij['laatst_ingelogd_op'] ?? null)) ?></td>
                            <td class="text-center">
                                <?php if ((int)$rij['geblokkeerd'] === 1): ?>
                                    <span class="badge text-bg-danger">geblokkeerd</span>
                                <?php else: ?>
                                    <span class="badge text-bg-success">actief</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary"
                                    href="<?= h(deelnemers_link(['id' => (int)$rij['id']])) ?>">
                                    Openen
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($paginaTotaal > 1): ?>
            <nav class="mt-3">
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= $pagina <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= h(deelnemers_link(['pagina' => max(1, $pagina - 1)])) ?>">Vorige</a>
                    </li>
                    <?php for ($p = max(1, $pagina - 4); $p <= min($paginaTotaal, $pagina + 4); $p++): ?>
                        <li class="page-item <?= $p === $pagina ? 'active' : '' ?>">
                            <a class="page-link" href="<?= h(deelnemers_link(['pagina' => $p])) ?>"><?= (int)$p ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $pagina >= $paginaTotaal ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= h(deelnemers_link(['pagina' => min($paginaTotaal, $pagina + 1)])) ?>">Volgende</a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php admin_eind(); ?>
