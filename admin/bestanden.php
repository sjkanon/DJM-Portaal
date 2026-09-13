<?php

/**
 * Beheer — Bestanden koppelen aan een jaargang.
 *
 * Uploaden: de video gaat in delen via de browser naar de opslagmap
 *   (admin/upload.php + admin/assets/upload.js) en wordt meteen gekoppeld.
 *   Werkt voor bestanden van vele gigabytes; een afgebroken upload gaat verder
 *   waar hij was.
 * Kiezen uit de opslagmap: voor een bestand dat er al staat, bijvoorbeeld
 *   eerder geüpload, ontkoppeld, of door de technisch beheerder via SFTP
 *   neergezet. De database bewaart uitsluitend het relatieve pad.
 *
 * Ontkoppelen verwijdert alleen de databaseregel; het bestand blijft staan. Een
 * bestand dat nergens meer aan gekoppeld is, kan hier ook van schijf af.
 */

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/bestand_helper.php';
vereis_installatie();
$beheerder = vereis_beheerder();

// ─── Jaargangen ophalen ──────────────────────────────────────────────────────
$jaargangen = [];
try {
    $jaargangen = db()->query('SELECT id, jaar, titel FROM jaargangen ORDER BY jaar DESC')->fetchAll() ?: [];
} catch (Throwable $e) {
    app_log('jaargangen ophalen mislukt', ['fout' => $e->getMessage()]);
}
$jaargangPerId = [];
foreach ($jaargangen as $jg) {
    $jaargangPerId[(int)$jg['id']] = $jg;
}

$jaargangId = (int)($_GET['jaargang'] ?? $_POST['jaargang'] ?? 0);
if ($jaargangId > 0 && !isset($jaargangPerId[$jaargangId])) {
    $jaargangId = 0;
}
// Zonder keuze: automatisch de nieuwste jaargang tonen.
if ($jaargangId === 0 && $jaargangen) {
    $jaargangId = (int)$jaargangen[0]['id'];
}

$paginaUrl = url('admin/bestanden.php') . ($jaargangId > 0 ? '?jaargang=' . $jaargangId : '');

// ─── Verwerking ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    vereis_csrf();
    $actie = (string)($_POST['actie'] ?? '');

    // ── Bestand uit de opslagmap koppelen ───────────────────────────────────
    if ($actie === 'koppelen') {
        $gekozen = (string)($_POST['pad'] ?? '');
        $gevonden = bestand_scan_opslag();

        if ($jaargangId <= 0) {
            flash('danger', 'Kies eerst een jaargang.');
        } elseif (!isset($gevonden[$gekozen])) {
            // Het pad moet uit de scan komen; nooit rechtstreeks uit de invoer.
            flash('danger', 'Dat bestand is niet (meer) in de opslagmap gevonden.');
        } else {
            try {
                bestand_koppelen($jaargangId, $gevonden[$gekozen]['pad'], $gevonden[$gekozen]['bytes'], [
                    'titel'        => (string)($_POST['titel'] ?? ''),
                    'bestandsnaam' => (string)($_POST['bestandsnaam'] ?? ''),
                    'sortering'    => (int)($_POST['sortering'] ?? 0),
                    'actief'       => isset($_POST['actief']),
                ]);
                flash('success', 'Bestand gekoppeld: ' . $gekozen);
            } catch (Throwable $e) {
                app_log('bestand koppelen mislukt', ['fout' => $e->getMessage()]);
                flash('danger', 'Koppelen is mislukt.');
            }
        }
        header('Location: ' . $paginaUrl);
        exit;
    }

    // ── Niet-gekoppeld bestand van schijf verwijderen ───────────────────────
    if ($actie === 'verwijderen') {
        $gekozen  = (string)($_POST['pad'] ?? '');
        $gevonden = bestand_scan_opslag();

        if (!isset($gevonden[$gekozen])) {
            flash('danger', 'Dat bestand is niet (meer) in de opslagmap gevonden.');
        } else {
            try {
                // Rechtstreeks nagaan in plaats van via bestand_gekoppelde_paden():
                // die geeft bij een databasefout een lege lijst, en dan zou een
                // gekoppelde video zomaar van schijf kunnen.
                $stmt = db()->prepare('SELECT COUNT(*) FROM jaargang_bestanden WHERE pad = :p');
                $stmt->execute([':p' => $gekozen]);
                $gekoppeld = (int)$stmt->fetchColumn() > 0;
            } catch (Throwable $e) {
                app_log('koppeling nagaan mislukt', ['fout' => $e->getMessage()]);
                $gekoppeld = true;
            }
            $absoluut = opslag_absoluut_pad($gekozen);

            if ($gekoppeld) {
                flash('danger', 'Dit bestand hoort bij een jaargang. Ontkoppel het eerst; daarna kunt u het verwijderen.');
            } elseif ($absoluut === null || !@unlink($absoluut)) {
                flash('danger', 'Het bestand kon niet worden verwijderd. Heeft de webserver schrijfrechten op de map?');
            } else {
                log_login('bestand_verwijderd', (string)$beheerder['email'], true,
                    $gekozen . ' (' . formatteer_bytes($gevonden[$gekozen]['bytes']) . ') door ' . (string)$beheerder['naam']);
                flash('success', 'Verwijderd uit de opslagmap: ' . $gekozen);
            }
        }
        header('Location: ' . $paginaUrl);
        exit;
    }

    // ── Onafgemaakte upload weggooien ───────────────────────────────────────
    if ($actie === 'upload_verwijderen') {
        $upload = upload_ophalen((string)($_POST['sleutel'] ?? ''));
        try {
            if ($upload !== null) {
                upload_verwijderen($upload);
            }
            flash('success', 'De onafgemaakte upload is weggegooid.');
        } catch (UploadFout $e) {
            flash('warning', $e->getMessage());
        } catch (Throwable $e) {
            app_log('upload verwijderen mislukt', ['fout' => $e->getMessage()]);
            flash('danger', 'Weggooien is mislukt.');
        }
        header('Location: ' . $paginaUrl);
        exit;
    }

    // ── Bestandsregel bijwerken ─────────────────────────────────────────────
    if ($actie === 'bijwerken') {
        $id    = (int)($_POST['id'] ?? 0);
        $titel = trim((string)($_POST['titel'] ?? ''));
        $naam  = download_veilige_naam((string)($_POST['bestandsnaam'] ?? ''));

        if ($titel === '') {
            flash('danger', 'Vul een titel in.');
        } else {
            try {
                db()->prepare(
                    'UPDATE jaargang_bestanden
                        SET titel = :t, bestandsnaam = :n, sortering = :s, actief = :a
                      WHERE id = :id'
                )->execute([
                    ':t'  => mb_substr($titel, 0, 150),
                    ':n'  => mb_substr($naam, 0, 255),
                    ':s'  => (int)($_POST['sortering'] ?? 0),
                    ':a'  => isset($_POST['actief']) ? 1 : 0,
                    ':id' => $id,
                ]);
                flash('success', 'Bestand bijgewerkt.');
            } catch (Throwable $e) {
                app_log('bestand bijwerken mislukt', ['fout' => $e->getMessage()]);
                flash('danger', 'Bijwerken is mislukt.');
            }
        }
        header('Location: ' . $paginaUrl);
        exit;
    }

    // ── Actief aan/uit ──────────────────────────────────────────────────────
    if ($actie === 'schakelen') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            db()->prepare('UPDATE jaargang_bestanden SET actief = 1 - actief WHERE id = :id')
                ->execute([':id' => $id]);
            flash('success', 'De status van het bestand is aangepast.');
        } catch (Throwable $e) {
            app_log('bestand schakelen mislukt', ['fout' => $e->getMessage()]);
            flash('danger', 'Aanpassen is mislukt.');
        }
        header('Location: ' . $paginaUrl);
        exit;
    }

    // ── Ontkoppelen (alleen de databaseregel) ───────────────────────────────
    if ($actie === 'ontkoppelen') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            db()->prepare('DELETE FROM jaargang_bestanden WHERE id = :id')->execute([':id' => $id]);
            flash('success', 'Het bestand is ontkoppeld. Het staat nog gewoon in de opslagmap.');
        } catch (Throwable $e) {
            app_log('bestand ontkoppelen mislukt', ['fout' => $e->getMessage()]);
            flash('danger', 'Ontkoppelen is mislukt.');
        }
        header('Location: ' . $paginaUrl);
        exit;
    }

    // ── SHA-256 berekenen (alleen op verzoek) ───────────────────────────────
    if ($actie === 'hash') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmt = db()->prepare('SELECT pad FROM jaargang_bestanden WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $pad = (string)($stmt->fetchColumn() ?: '');
            $absoluut = $pad !== '' ? opslag_absoluut_pad($pad) : null;

            if ($absoluut === null) {
                flash('danger', 'Het bestand staat niet (meer) in de opslagmap.');
            } else {
                @set_time_limit(0);
                $hash = hash_file('sha256', $absoluut);
                if ($hash === false) {
                    flash('danger', 'De controlesom kon niet worden berekend.');
                } else {
                    db()->prepare('UPDATE jaargang_bestanden SET sha256 = :h, bytes = :b WHERE id = :id')
                        ->execute([':h' => $hash, ':b' => (int)@filesize($absoluut), ':id' => $id]);
                    flash('success', 'SHA-256 berekend: ' . $hash);
                }
            }
        } catch (Throwable $e) {
            app_log('sha256 berekenen mislukt', ['fout' => $e->getMessage()]);
            flash('danger', 'Berekenen is mislukt.');
        }
        header('Location: ' . $paginaUrl);
        exit;
    }

    header('Location: ' . $paginaUrl);
    exit;
}

// ─── Gegevens voor de weergave ───────────────────────────────────────────────
$bestanden = [];
$openstaand = [];
if ($jaargangId > 0) {
    try {
        $stmt = db()->prepare(
            'SELECT * FROM jaargang_bestanden WHERE jaargang_id = :j ORDER BY sortering ASC, id ASC'
        );
        $stmt->execute([':j' => $jaargangId]);
        $bestanden = $stmt->fetchAll() ?: [];
        $openstaand = upload_openstaand($jaargangId);
    } catch (Throwable $e) {
        app_log('bestanden ophalen mislukt', ['fout' => $e->getMessage()]);
        flash('danger', 'De bestandenlijst kon niet worden geladen.');
    }
}

$bewerkId         = (int)($_GET['bewerk'] ?? 0);
$gevondenOpSchijf = bestand_scan_opslag();
$gekoppeldePaden  = bestand_gekoppelde_paden();
$losseBestanden   = array_diff_key($gevondenOpSchijf, $gekoppeldePaden);
$opslagBestaat    = is_dir(opslag_pad());
$opslagSchrijfbaar = $opslagBestaat && is_writable(opslag_pad());
$vrijeRuimte      = $opslagBestaat ? @disk_free_space(opslag_pad()) : false;
$huidigeJaargang  = $jaargangId > 0 ? $jaargangPerId[$jaargangId] : null;

$subtitel = $huidigeJaargang !== null
    ? (int)$huidigeJaargang['jaar'] . ' — ' . (string)$huidigeJaargang['titel']
    : 'Koppel de videoregistratie aan een jaargang.';

admin_start('Bestanden', $subtitel);
?>

<!-- ─── Keuze van de jaargang ───────────────────────────────────────────── -->
<div class="kaart p-3 mb-4">
    <?php if (!$jaargangen): ?>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <div class="flex-grow-1 text-muted">
                Er is nog geen jaargang. Maak eerst een jaargang aan; daarna kunt u er een bestand aan koppelen.
            </div>
            <a class="btn btn-djm" href="<?= h(url('admin/jaargangen.php')) ?>">
                <i class="bi bi-calendar-plus me-1"></i>Jaargang aanmaken
            </a>
        </div>
    <?php else: ?>
        <form method="get" class="row g-2 align-items-end">
            <div class="col-sm-6 col-lg-4">
                <label class="form-label" for="jaargang">Jaargang</label>
                <select class="form-select" id="jaargang" name="jaargang" data-auto-verzenden>
                    <?php foreach ($jaargangen as $jg): ?>
                        <option value="<?= (int)$jg['id'] ?>" <?= (int)$jg['id'] === $jaargangId ? 'selected' : '' ?>>
                            <?= h((string)$jg['jaar']) ?> — <?= h((string)$jg['titel']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-auto">
                <button type="submit" class="btn btn-outline-secondary">
                    <i class="bi bi-funnel me-1"></i>Tonen
                </button>
            </div>
            <div class="col-sm-auto ms-sm-auto">
                <a class="btn btn-outline-secondary"
                    href="<?= h(url('admin/toegang.php?jaargang=' . $jaargangId)) ?>">
                    <i class="bi bi-person-check me-1"></i>Toegang van deze jaargang
                </a>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php if ($jaargangId > 0): ?>

    <!-- ─── Gekoppelde bestanden ────────────────────────────────────────── -->
    <div class="kaart mb-4">
        <div class="p-3 border-bottom">
            <h2 class="h6 mb-0"><i class="bi bi-film me-1"></i>Gekoppelde bestanden
                <span class="text-muted fw-normal">(<?= count($bestanden) ?>)</span>
            </h2>
        </div>

        <?php if (!$bestanden): ?>
            <div class="p-5 text-center text-muted">
                <i class="bi bi-film fs-1 d-block mb-2 opacity-50"></i>
                Aan deze jaargang is nog geen bestand gekoppeld.<br>
                Upload hieronder de video, of kies een bestand dat al in de opslagmap staat.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table tabel-compact align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Titel</th>
                            <th scope="col">Downloadnaam</th>
                            <th scope="col" class="text-end">Grootte</th>
                            <th scope="col" class="text-center">Op schijf</th>
                            <th scope="col" class="text-center">Actief</th>
                            <th scope="col" class="text-end">Acties</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bestanden as $rij): ?>
                            <?php
                            $rijId    = (int)$rij['id'];
                            $absoluut = opslag_absoluut_pad((string)$rij['pad']);
                            $aanwezig = $absoluut !== null;
                            ?>
                            <tr<?= $rijId === $bewerkId ? ' class="table-active"' : '' ?>>
                                <td>
                                    <div class="fw-semibold"><?= h((string)$rij['titel']) ?></div>
                                    <code class="pad"><?= h((string)$rij['pad']) ?></code>
                                    <?php if (!empty($rij['sha256'])): ?>
                                        <div class="text-muted small">
                                            <i class="bi bi-fingerprint me-1"></i>
                                            <code class="pad"><?= h(substr((string)$rij['sha256'], 0, 16)) ?>…</code>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="small"><?= h((string)$rij['bestandsnaam']) ?></td>
                                <td class="text-end text-nowrap small">
                                    <?= h(formatteer_bytes((int)$rij['bytes'])) ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($aanwezig): ?>
                                        <i class="bi bi-check-circle-fill text-success" title="Aanwezig"></i>
                                    <?php else: ?>
                                        <i class="bi bi-x-circle-fill text-danger"
                                            title="Ontbreekt in de opslagmap"></i>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ((int)$rij['actief'] === 1): ?>
                                        <span class="badge text-bg-success">actief</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-secondary">uit</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <a class="btn btn-sm btn-outline-secondary" title="Bewerken"
                                        href="<?= h($paginaUrl . '&bewerk=' . $rijId) ?>">
                                        <i class="bi bi-pencil"></i>
                                    </a>

                                    <form method="post" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="actie" value="schakelen">
                                        <input type="hidden" name="jaargang" value="<?= $jaargangId ?>">
                                        <input type="hidden" name="id" value="<?= $rijId ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary"
                                            title="<?= (int)$rij['actief'] === 1 ? 'Uitschakelen' : 'Inschakelen' ?>">
                                            <i class="bi <?= (int)$rij['actief'] === 1 ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                                        </button>
                                    </form>

                                    <form method="post" class="d-inline"
                                        <?= bevestig_attribuut("SHA-256 berekenen voor dit bestand?\n\nBij grote videobestanden kan dit lang duren; de pagina blijft ondertussen laden.") ?>>
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="actie" value="hash">
                                        <input type="hidden" name="jaargang" value="<?= $jaargangId ?>">
                                        <input type="hidden" name="id" value="<?= $rijId ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary"
                                            title="SHA-256 berekenen" <?= $aanwezig ? '' : 'disabled' ?>>
                                            <i class="bi bi-fingerprint"></i>
                                        </button>
                                    </form>

                                    <form method="post" class="d-inline"
                                        <?= bevestig_attribuut("Dit bestand ontkoppelen van de jaargang?\n\nAlleen de koppeling in de database verdwijnt.\nHet bestand zelf blijft in de opslagmap staan.") ?>>
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="actie" value="ontkoppelen">
                                        <input type="hidden" name="jaargang" value="<?= $jaargangId ?>">
                                        <input type="hidden" name="id" value="<?= $rijId ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"
                                            title="Ontkoppelen">
                                            <i class="bi bi-link-45deg"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>

                            <?php if ($rijId === $bewerkId): ?>
                                <tr class="table-active">
                                    <td colspan="6">
                                        <form method="post" class="row g-2 align-items-end">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="actie" value="bijwerken">
                                            <input type="hidden" name="jaargang" value="<?= $jaargangId ?>">
                                            <input type="hidden" name="id" value="<?= $rijId ?>">
                                            <div class="col-md-4">
                                                <label class="form-label small" for="titel-<?= $rijId ?>">Titel</label>
                                                <input type="text" class="form-control form-control-sm"
                                                    id="titel-<?= $rijId ?>" name="titel" required maxlength="150"
                                                    value="<?= h((string)$rij['titel']) ?>">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label small" for="naam-<?= $rijId ?>">Downloadnaam</label>
                                                <input type="text" class="form-control form-control-sm"
                                                    id="naam-<?= $rijId ?>" name="bestandsnaam" maxlength="255"
                                                    value="<?= h((string)$rij['bestandsnaam']) ?>">
                                            </div>
                                            <div class="col-6 col-md-2">
                                                <label class="form-label small" for="sort-<?= $rijId ?>">Sortering</label>
                                                <input type="number" class="form-control form-control-sm"
                                                    id="sort-<?= $rijId ?>" name="sortering" step="1"
                                                    value="<?= (int)$rij['sortering'] ?>">
                                            </div>
                                            <div class="col-6 col-md-2">
                                                <div class="form-check form-switch mb-2">
                                                    <input class="form-check-input" type="checkbox"
                                                        id="act-<?= $rijId ?>" name="actief" value="1"
                                                        <?= (int)$rij['actief'] === 1 ? 'checked' : '' ?>>
                                                    <label class="form-check-label small" for="act-<?= $rijId ?>">Actief</label>
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <button type="submit" class="btn btn-sm btn-djm">
                                                    <i class="bi bi-check-lg me-1"></i>Opslaan
                                                </button>
                                                <a class="btn btn-sm btn-outline-secondary" href="<?= h($paginaUrl) ?>">
                                                    Annuleren
                                                </a>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="row g-4">

        <!-- ─── Uploaden via de browser ──────────────────────────────────── -->
        <div class="col-xl-7">
            <div class="kaart h-100" id="upload" data-upload
                data-adres="<?= h(url('admin/upload.php')) ?>"
                data-csrf="<?= h(csrf_token()) ?>"
                data-jaargang="<?= $jaargangId ?>"
                data-extensies="<?= h(implode(',', BESTAND_EXTENSIES)) ?>">
                <div class="p-3 border-bottom">
                    <h2 class="h6 mb-1"><i class="bi bi-cloud-arrow-up me-1"></i>Video uploaden</h2>
                    <p class="text-muted small mb-0">
                        Ook voor video's van vele gigabytes. Het bestand gaat in stukken naar de server;
                        valt de verbinding weg, dan gaat de upload daarna vanzelf verder waar hij was.
                    </p>
                </div>

                <div class="p-3">
                    <noscript>
                        <div class="alert alert-warning py-2 small">Uploaden werkt alleen met JavaScript aan.</div>
                    </noscript>

                    <?php if (!$opslagSchrijfbaar): ?>
                        <div class="alert alert-danger py-2 small">
                            De webserver kan niet schrijven in de opslagmap
                            <code class="pad"><?= h(opslag_pad()) ?></code>. Uploaden lukt zo niet;
                            vraag de technisch beheerder om schrijfrechten.
                        </div>
                    <?php endif; ?>

                    <?php if ($openstaand): ?>
                        <div class="alert alert-info py-2 small">
                            <div class="fw-semibold mb-1">
                                <i class="bi bi-hourglass-split me-1"></i>Onafgemaakte upload<?= count($openstaand) > 1 ? 's' : '' ?>
                            </div>
                            <p class="mb-2">
                                Kies hetzelfde bestand hieronder opnieuw, dan gaat de upload verder waar hij was.
                                Na <?= UPLOAD_VERLOOP_DAGEN ?> dagen zonder voortgang wordt hij weggegooid.
                            </p>
                            <ul class="list-unstyled mb-0">
                                <?php foreach ($openstaand as $upload): ?>
                                    <?php $procent = (int)$upload['bytes'] > 0 ? (int)floor($upload['ontvangen'] / (int)$upload['bytes'] * 100) : 0; ?>
                                    <li class="d-flex flex-wrap align-items-center gap-2 py-1 border-top">
                                        <div class="flex-grow-1 min-w-0">
                                            <div class="djm-afkappen"><?= h((string)$upload['origineel']) ?></div>
                                            <div class="text-muted">
                                                <?= h(formatteer_bytes((int)$upload['ontvangen'])) ?> van
                                                <?= h(formatteer_bytes((int)$upload['bytes'])) ?> (<?= $procent ?>%)
                                                · <?= h(formatteer_datum((string)$upload['bijgewerkt_op'])) ?>
                                                <?php if (!empty($upload['beheerder_naam'])): ?>
                                                    · <?= h((string)$upload['beheerder_naam']) ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <form method="post"
                                            <?= bevestig_attribuut("Deze onafgemaakte upload weggooien?\n\nWat er al op de server staat, gaat verloren.") ?>>
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="actie" value="upload_verwijderen">
                                            <input type="hidden" name="jaargang" value="<?= $jaargangId ?>">
                                            <input type="hidden" name="sleutel" value="<?= h((string)$upload['sleutel']) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Weggooien">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <div class="djm-upload-vak" data-upload-vak>
                        <i class="bi bi-film fs-2 d-block text-muted mb-1" aria-hidden="true"></i>
                        <label class="form-label" for="upload-bestand">Sleep de video hierheen, of kies hem:</label>
                        <input type="file" class="form-control" id="upload-bestand" data-upload-invoer
                            accept=".<?= h(implode(',.', BESTAND_EXTENSIES)) ?>"
                            <?= $opslagSchrijfbaar ? '' : 'disabled' ?>>
                        <div class="form-text">
                            <?= h(implode(', ', BESTAND_EXTENSIES)) ?> · komt in
                            <code class="pad"><?= h(opslag_pad() . '/' . (int)$huidigeJaargang['jaar']) ?></code>
                            <?php if ($vrijeRuimte !== false): ?>
                                · <?= h(formatteer_bytes((int)$vrijeRuimte)) ?> vrij
                            <?php endif; ?>
                        </div>
                        <div class="small mt-2" data-upload-gekozen hidden>
                            <i class="bi bi-file-earmark-play me-1"></i>
                            <strong data-upload-naam></strong> (<span data-upload-grootte></span>)
                        </div>
                    </div>

                    <div class="form-check form-switch mt-3">
                        <input class="form-check-input" type="checkbox" id="upload-koppelen" data-upload-koppelen checked>
                        <label class="form-check-label" for="upload-koppelen">
                            Na het uploaden meteen koppelen aan <?= h((string)$huidigeJaargang['jaar']) ?>
                        </label>
                    </div>
                    <fieldset class="row g-2 mt-1" data-upload-koppeling>
                        <div class="col-md-6">
                            <label class="form-label" for="titel-upload">Titel</label>
                            <input type="text" class="form-control" id="titel-upload" name="titel" maxlength="150"
                                placeholder="Videoregistratie <?= h((string)$huidigeJaargang['jaar']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="naam-upload">Downloadnaam</label>
                            <input type="text" class="form-control" id="naam-upload" name="bestandsnaam"
                                maxlength="255" placeholder="standaard: de bestandsnaam zelf">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label" for="sort-upload">Sortering</label>
                            <input type="number" class="form-control" id="sort-upload" name="sortering" step="1" value="0">
                        </div>
                        <div class="col-6 col-md-3 d-flex align-items-end">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" id="act-upload" name="actief" value="1" checked>
                                <label class="form-check-label" for="act-upload">Actief</label>
                            </div>
                        </div>
                    </fieldset>

                    <div class="mt-3" data-upload-voortgang hidden>
                        <div class="progress" role="progressbar" aria-label="Voortgang van de upload"
                            aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                            <div class="progress-bar" data-upload-balk style="width: 0%"></div>
                        </div>
                        <div class="d-flex flex-wrap justify-content-between gap-2 small text-muted mt-1">
                            <span data-upload-status></span>
                            <span data-upload-tijd></span>
                        </div>
                    </div>

                    <div data-upload-melding role="status" hidden></div>

                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <button type="button" class="btn btn-djm" data-upload-start disabled>
                            <i class="bi bi-cloud-arrow-up me-1"></i>Uploaden
                        </button>
                        <button type="button" class="btn btn-outline-secondary" data-upload-pauze hidden>
                            <i class="bi bi-pause-fill me-1"></i>Pauzeren
                        </button>
                        <button type="button" class="btn btn-outline-danger" data-upload-annuleer hidden>
                            <i class="bi bi-x-lg me-1"></i>Annuleren
                        </button>
                    </div>
                    <div class="form-text">
                        Houd dit tabblad open tot de upload klaar is. U kunt intussen in een ander tabblad
                        verder werken in het beheer.
                    </div>
                </div>
            </div>
        </div>

        <!-- ─── Kiezen uit de opslagmap ──────────────────────────────────── -->
        <div class="col-xl-5">
            <div class="kaart h-100">
                <div class="p-3 border-bottom">
                    <h2 class="h6 mb-1"><i class="bi bi-folder2-open me-1"></i>Kiezen uit de opslagmap</h2>
                    <p class="text-muted small mb-0">
                        Voor een bestand dat al op de server staat: eerder geüpload, ontkoppeld, of door de
                        technisch beheerder rechtstreeks in de opslagmap gezet.
                    </p>
                </div>

                <div class="p-3">
                    <div class="mb-3 small">
                        <span class="text-muted">Opslagmap:</span>
                        <code class="pad"><?= h(opslag_pad()) ?></code>
                    </div>

                    <?php if (!$opslagBestaat): ?>
                        <div class="alert alert-danger py-2 small mb-0">
                            De opslagmap bestaat niet of is niet leesbaar. Controleer <code>OPSLAG_PAD</code>
                            in <code>.env</code> en de rechten op de map.
                        </div>
                    <?php elseif (!$gevondenOpSchijf): ?>
                        <div class="alert alert-info py-2 small mb-0">
                            Er staan nog geen videobestanden in de opslagmap (maximaal <?= BESTAND_MAX_DIEPTE ?> mappen diep).
                            Herkende extensies: <?= h(implode(', ', BESTAND_EXTENSIES)) ?>.
                        </div>
                    <?php else: ?>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="actie" value="koppelen">
                            <input type="hidden" name="jaargang" value="<?= $jaargangId ?>">

                            <div class="mb-3">
                                <label class="form-label" for="pad">Bestand in de opslagmap</label>
                                <select class="form-select" id="pad" name="pad" required>
                                    <option value="">— kies een bestand —</option>
                                    <?php foreach ($gevondenOpSchijf as $relatief => $info): ?>
                                        <?php $alGekoppeld = isset($gekoppeldePaden[$relatief]); ?>
                                        <option value="<?= h($relatief) ?>" <?= $alGekoppeld ? 'disabled' : '' ?>>
                                            <?= h($relatief) ?>
                                            (<?= h(formatteer_bytes($info['bytes'])) ?>)<?php
                                            echo $alGekoppeld
                                                ? ' — al gekoppeld aan ' . h((string)$gekoppeldePaden[$relatief])
                                                : ''; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">
                                    Bestanden die al aan een jaargang hangen staan grijs.
                                </div>
                            </div>

                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label" for="titel-a">Titel</label>
                                    <input type="text" class="form-control" id="titel-a" name="titel" maxlength="150"
                                        placeholder="Videoregistratie <?= h((string)$huidigeJaargang['jaar']) ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="naam-a">Downloadnaam</label>
                                    <input type="text" class="form-control" id="naam-a" name="bestandsnaam"
                                        maxlength="255" placeholder="standaard: de bestandsnaam zelf">
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label" for="sort-a">Sortering</label>
                                    <input type="number" class="form-control" id="sort-a" name="sortering"
                                        step="1" value="0">
                                </div>
                                <div class="col-6 col-md-3 d-flex align-items-end">
                                    <div class="form-check form-switch mb-2">
                                        <input class="form-check-input" type="checkbox" id="act-a" name="actief"
                                            value="1" checked>
                                        <label class="form-check-label" for="act-a">Actief</label>
                                    </div>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-outline-secondary mt-3">
                                <i class="bi bi-link-45deg me-1"></i>Koppelen aan deze jaargang
                            </button>
                        </form>

                        <?php if ($losseBestanden): ?>
                            <details class="mt-4">
                                <summary class="small">
                                    Niet-gekoppelde bestanden opruimen (<?= count($losseBestanden) ?>)
                                </summary>
                                <p class="small text-muted mt-2 mb-2">
                                    Deze bestanden hangen aan geen enkele jaargang. Verwijderen haalt ze definitief
                                    van de server; dat is niet terug te draaien.
                                </p>
                                <ul class="list-unstyled small mb-0">
                                    <?php foreach ($losseBestanden as $relatief => $info): ?>
                                        <li class="d-flex align-items-center gap-2 py-1 border-top">
                                            <div class="flex-grow-1 min-w-0">
                                                <code class="pad djm-afkappen d-block"><?= h($relatief) ?></code>
                                                <span class="text-muted"><?= h(formatteer_bytes($info['bytes'])) ?></span>
                                            </div>
                                            <form method="post"
                                                <?= bevestig_attribuut("Dit bestand definitief van de server verwijderen?\n\n" . $relatief . "\n\nDit is niet terug te draaien.") ?>>
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="actie" value="verwijderen">
                                                <input type="hidden" name="jaargang" value="<?= $jaargangId ?>">
                                                <input type="hidden" name="pad" value="<?= h($relatief) ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Verwijderen">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </details>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<?php endif; ?>

<?php
admin_eind();
