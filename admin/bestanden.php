<?php

/**
 * Beheer — Bestanden koppelen aan een jaargang.
 *
 * Route A (aanbevolen): het videobestand staat al via SFTP in de opslagmap en
 *   wordt hier alleen gekozen. De database bewaart uitsluitend het relatieve pad.
 * Route B (secundair): uploaden via de browser. Werkt alleen binnen de
 *   PHP-limieten en is dus ongeschikt voor grote videoregistraties.
 *
 * Ontkoppelen verwijdert altijd alleen de databaseregel; het bestand op schijf
 * blijft staan.
 */

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/includes/layout.php';
vereis_installatie();
$beheerder = vereis_beheerder();

// ─── Toegestane bestandstypen ────────────────────────────────────────────────
const BESTAND_EXTENSIES = ['mp4', 'mkv', 'mov', 'm4v', 'webm', 'avi', 'zip'];
const BESTAND_MAX_DIEPTE = 3;

/** MIME-type op basis van de extensie; nooit op basis van gebruikersinvoer. */
function mime_uit_extensie(string $extensie): string
{
    return [
        'mp4'  => 'video/mp4',
        'm4v'  => 'video/x-m4v',
        'mkv'  => 'video/x-matroska',
        'mov'  => 'video/quicktime',
        'webm' => 'video/webm',
        'avi'  => 'video/x-msvideo',
        'zip'  => 'application/zip',
    ][strtolower($extensie)] ?? 'application/octet-stream';
}

/**
 * Naam die de bezoeker in zijn downloadmap ziet. Ruimer dan de naam op schijf:
 * spaties en accenten mogen hier wél. Padscheidingstekens, aanhalingstekens,
 * puntkomma's en regeleindes gaan eruit — die kunnen de Content-Disposition-
 * header breken.
 */
function download_veilige_naam(string $naam): string
{
    $naam = str_replace(['\\', '/'], ' ', $naam);
    $naam = preg_replace('/[\x00-\x1F\x7F";]+/u', '', $naam) ?? '';
    $naam = preg_replace('/\s+/u', ' ', $naam) ?? '';
    $naam = trim($naam, " ._-");
    return $naam === '' ? 'bestand' : mb_substr($naam, 0, 200);
}

/** Maakt een bestandsnaam veilig: alleen letters, cijfers, punt, streepje, underscore. */
function bestand_veilige_naam(string $naam): string
{
    $naam = basename(str_replace('\\', '/', $naam));
    $naam = preg_replace('/[^A-Za-z0-9._-]+/', '_', $naam) ?? '';
    $naam = trim($naam, '._-');
    return $naam === '' ? 'bestand' : substr($naam, 0, 200);
}

/**
 * Scant de opslagmap recursief (maximaal BESTAND_MAX_DIEPTE niveaus) op
 * videobestanden. Geeft een lijst met relatief pad => [pad, bytes, ext].
 */
function bestand_scan_opslag(): array
{
    $basis = realpath(opslag_pad());
    if ($basis === false || !is_dir($basis)) {
        return [];
    }

    $gevonden = [];
    $stapel   = [['', 1]];

    while ($stapel) {
        [$relatieveMap, $diepte] = array_pop($stapel);
        $map   = $relatieveMap === '' ? $basis : $basis . '/' . $relatieveMap;
        $items = @scandir($map);
        if ($items === false) {
            continue;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item[0] === '.') {
                continue;
            }
            $volledig = $map . '/' . $item;
            if (is_link($volledig)) {
                continue;   // symlinks nooit volgen: die kunnen buiten de opslagmap wijzen
            }
            $relatief = $relatieveMap === '' ? $item : $relatieveMap . '/' . $item;

            if (is_dir($volledig)) {
                if ($diepte < BESTAND_MAX_DIEPTE) {
                    $stapel[] = [$relatief, $diepte + 1];
                }
                continue;
            }
            if (!is_file($volledig)) {
                continue;
            }
            $ext = strtolower((string)pathinfo($item, PATHINFO_EXTENSION));
            if (!in_array($ext, BESTAND_EXTENSIES, true)) {
                continue;
            }
            $gevonden[$relatief] = [
                'pad'   => $relatief,
                'bytes' => (int)@filesize($volledig),
                'ext'   => $ext,
            ];
        }
    }

    ksort($gevonden, SORT_NATURAL | SORT_FLAG_CASE);
    return $gevonden;
}

/** Alle paden die al aan een jaargang gekoppeld zijn: pad => jaar. */
function bestand_gekoppelde_paden(): array
{
    try {
        $rijen = db()->query(
            'SELECT b.pad, j.jaar FROM jaargang_bestanden b
               JOIN jaargangen j ON j.id = b.jaargang_id'
        )->fetchAll() ?: [];
    } catch (Throwable $e) {
        app_log('gekoppelde paden ophalen mislukt', ['fout' => $e->getMessage()]);
        return [];
    }
    $kaart = [];
    foreach ($rijen as $rij) {
        $kaart[(string)$rij['pad']] = (int)$rij['jaar'];
    }
    return $kaart;
}

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

// ─── POST groter dan post_max_size? ──────────────────────────────────────────
// Dan zijn $_POST en $_FILES leeg en zou de CSRF-controle een verwarrende
// foutmelding geven. Vang dat af met een begrijpelijke uitleg.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)
    && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    flash('danger', 'De verzonden gegevens waren groter dan de serverlimiet (post_max_size = '
        . ini_get('post_max_size') . '). Gebruik route A: zet het bestand via SFTP in de opslagmap '
        . 'en kies het daar.');
    header('Location: ' . $paginaUrl);
    exit;
}

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
            $bron  = $gevonden[$gekozen];
            $titel = trim((string)($_POST['titel'] ?? ''));
            $naam  = trim((string)($_POST['bestandsnaam'] ?? ''));
            if ($titel === '') {
                $titel = 'Videoregistratie ' . (int)$jaargangPerId[$jaargangId]['jaar'];
            }
            $naam = $naam !== '' ? download_veilige_naam($naam) : download_veilige_naam(basename($gekozen));

            try {
                db()->prepare(
                    'INSERT INTO jaargang_bestanden
                        (jaargang_id, titel, bestandsnaam, pad, bytes, mime, sortering, actief)
                     VALUES (:j, :t, :n, :p, :b, :m, :s, :a)'
                )->execute([
                    ':j' => $jaargangId,
                    ':t' => substr($titel, 0, 150),
                    ':n' => substr($naam, 0, 255),
                    ':p' => substr($bron['pad'], 0, 500),
                    ':b' => $bron['bytes'],
                    ':m' => mime_uit_extensie($bron['ext']),
                    ':s' => (int)($_POST['sortering'] ?? 0),
                    ':a' => isset($_POST['actief']) ? 1 : 0,
                ]);
                flash('success', 'Bestand gekoppeld: ' . $bron['pad']);
            } catch (Throwable $e) {
                app_log('bestand koppelen mislukt', ['fout' => $e->getMessage()]);
                flash('danger', 'Koppelen is mislukt.');
            }
        }
        header('Location: ' . $paginaUrl);
        exit;
    }

    // ── Uploaden via de browser ─────────────────────────────────────────────
    if ($actie === 'uploaden') {
        $upload = $_FILES['bestand'] ?? null;

        if ($jaargangId <= 0) {
            flash('danger', 'Kies eerst een jaargang.');
        } elseif (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            flash('danger', 'Er is geen bestand gekozen.');
        } elseif ((int)$upload['error'] !== UPLOAD_ERR_OK) {
            $meldingen = [
                UPLOAD_ERR_INI_SIZE   => 'Het bestand is groter dan upload_max_filesize (' . ini_get('upload_max_filesize') . ').',
                UPLOAD_ERR_FORM_SIZE  => 'Het bestand is groter dan toegestaan door het formulier.',
                UPLOAD_ERR_PARTIAL    => 'Het bestand is maar gedeeltelijk geüpload.',
                UPLOAD_ERR_NO_TMP_DIR => 'De server heeft geen tijdelijke map voor uploads.',
                UPLOAD_ERR_CANT_WRITE => 'De server kon het bestand niet wegschrijven.',
                UPLOAD_ERR_EXTENSION  => 'Een PHP-extensie heeft de upload geblokkeerd.',
            ];
            flash('danger', $meldingen[(int)$upload['error']] ?? 'De upload is mislukt.');
        } else {
            $origineel = (string)($upload['name'] ?? '');
            $extensie  = strtolower((string)pathinfo($origineel, PATHINFO_EXTENSION));

            if (!in_array($extensie, BESTAND_EXTENSIES, true)) {
                flash('danger', 'Dit bestandstype is niet toegestaan. Toegestaan: '
                    . implode(', ', BESTAND_EXTENSIES) . '.');
            } elseif (!is_uploaded_file((string)$upload['tmp_name'])) {
                flash('danger', 'De upload is niet geldig.');
            } else {
                $jaar    = (int)$jaargangPerId[$jaargangId]['jaar'];
                $doelMap = opslag_pad() . '/' . $jaar;
                if (!is_dir($doelMap) && !@mkdir($doelMap, 0775, true) && !is_dir($doelMap)) {
                    flash('danger', 'De map ' . $jaar . ' kon niet in de opslagmap worden aangemaakt. '
                        . 'Controleer de schrijfrechten.');
                    header('Location: ' . $paginaUrl);
                    exit;
                }

                $veiligeNaam = bestand_veilige_naam($origineel);
                if (strtolower((string)pathinfo($veiligeNaam, PATHINFO_EXTENSION)) !== $extensie) {
                    $veiligeNaam .= '.' . $extensie;
                }
                // Bestaande bestanden nooit overschrijven.
                $basisNaam = (string)pathinfo($veiligeNaam, PATHINFO_FILENAME);
                $teller    = 1;
                while (file_exists($doelMap . '/' . $veiligeNaam)) {
                    $teller++;
                    $veiligeNaam = $basisNaam . '-' . $teller . '.' . $extensie;
                }

                if (!@move_uploaded_file((string)$upload['tmp_name'], $doelMap . '/' . $veiligeNaam)) {
                    flash('danger', 'Het bestand kon niet in de opslagmap worden gezet.');
                } else {
                    @chmod($doelMap . '/' . $veiligeNaam, 0644);
                    $relatief = $jaar . '/' . $veiligeNaam;
                    $titel    = trim((string)($_POST['titel'] ?? ''));
                    if ($titel === '') {
                        $titel = 'Videoregistratie ' . $jaar;
                    }
                    $downloadNaam = trim((string)($_POST['bestandsnaam'] ?? ''));
                    $downloadNaam = $downloadNaam !== '' ? download_veilige_naam($downloadNaam) : $veiligeNaam;

                    try {
                        db()->prepare(
                            'INSERT INTO jaargang_bestanden
                                (jaargang_id, titel, bestandsnaam, pad, bytes, mime, sortering, actief)
                             VALUES (:j, :t, :n, :p, :b, :m, :s, :a)'
                        )->execute([
                            ':j' => $jaargangId,
                            ':t' => substr($titel, 0, 150),
                            ':n' => substr($downloadNaam, 0, 255),
                            ':p' => substr($relatief, 0, 500),
                            ':b' => (int)@filesize($doelMap . '/' . $veiligeNaam),
                            ':m' => mime_uit_extensie($extensie),
                            ':s' => (int)($_POST['sortering'] ?? 0),
                            ':a' => isset($_POST['actief']) ? 1 : 0,
                        ]);
                        flash('success', 'Bestand geüpload en gekoppeld: ' . $relatief);
                    } catch (Throwable $e) {
                        app_log('geüpload bestand koppelen mislukt', ['fout' => $e->getMessage()]);
                        flash('warning', 'Het bestand staat in de opslagmap, maar de koppeling is mislukt. '
                            . 'Koppel het handmatig via route A.');
                    }
                }
            }
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
                    ':t'  => substr($titel, 0, 150),
                    ':n'  => substr($naam, 0, 255),
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
if ($jaargangId > 0) {
    try {
        $stmt = db()->prepare(
            'SELECT * FROM jaargang_bestanden WHERE jaargang_id = :j ORDER BY sortering ASC, id ASC'
        );
        $stmt->execute([':j' => $jaargangId]);
        $bestanden = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        app_log('bestanden ophalen mislukt', ['fout' => $e->getMessage()]);
        flash('danger', 'De bestandenlijst kon niet worden geladen.');
    }
}

$bewerkId       = (int)($_GET['bewerk'] ?? 0);
$gevondenOpSchijf = bestand_scan_opslag();
$gekoppeldePaden  = bestand_gekoppelde_paden();
$opslagBestaat    = is_dir(opslag_pad());
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
                Kies hieronder een bestand uit de opslagmap.
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

        <!-- ─── Route A: kiezen uit de opslagmap ─────────────────────────── -->
        <div class="col-xl-7">
            <div class="kaart h-100">
                <div class="p-3 border-bottom">
                    <h2 class="h6 mb-1">
                        <span class="badge text-bg-success me-1">Route A</span>
                        Kiezen uit de opslagmap
                    </h2>
                    <p class="text-muted small mb-0">
                        Dit is de aanbevolen route voor grote video's: zet het bestand via SFTP in de
                        opslagmap en kies het hier. Het bestand gaat dan niet door de browser en er
                        gelden geen PHP-limieten.
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
                            Er zijn nog geen videobestanden gevonden (maximaal <?= BESTAND_MAX_DIEPTE ?> mappen diep).
                            Zet het bestand via SFTP in de opslagmap — bijvoorbeeld in een submap per jaar — en
                            ververs deze pagina.
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

                            <button type="submit" class="btn btn-djm mt-3">
                                <i class="bi bi-link-45deg me-1"></i>Koppelen aan deze jaargang
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ─── Route B: uploaden via de browser ─────────────────────────── -->
        <div class="col-xl-5">
            <div class="kaart h-100">
                <div class="p-3 border-bottom">
                    <h2 class="h6 mb-1">
                        <span class="badge text-bg-secondary me-1">Route B</span>
                        Uploaden via de browser
                    </h2>
                    <p class="text-muted small mb-0">
                        Alleen geschikt voor kleine bestanden. Een videoregistratie van meerdere
                        gigabytes gaat hier vrijwel zeker niet doorheen.
                    </p>
                </div>

                <div class="p-3">
                    <div class="alert alert-warning py-2 small">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Serverlimieten op dit moment:
                        <ul class="mb-0 ps-3">
                            <li><code>upload_max_filesize</code> = <?= h((string)ini_get('upload_max_filesize')) ?></li>
                            <li><code>post_max_size</code> = <?= h((string)ini_get('post_max_size')) ?></li>
                        </ul>
                        Is uw bestand groter? Gebruik dan route A.
                    </div>

                    <form method="post" enctype="multipart/form-data">
                        <?= csrf_field() ?>
                        <input type="hidden" name="actie" value="uploaden">
                        <input type="hidden" name="jaargang" value="<?= $jaargangId ?>">

                        <div class="mb-3">
                            <label class="form-label" for="bestand">Bestand</label>
                            <input type="file" class="form-control" id="bestand" name="bestand" required
                                accept=".<?= h(implode(',.', BESTAND_EXTENSIES)) ?>">
                            <div class="form-text">
                                Toegestaan: <?= h(implode(', ', BESTAND_EXTENSIES)) ?>.
                                Het bestand komt in
                                <code class="pad"><?= h(opslag_pad() . '/' . (int)$huidigeJaargang['jaar']) ?></code>.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="titel-b">Titel</label>
                            <input type="text" class="form-control" id="titel-b" name="titel" maxlength="150"
                                placeholder="Videoregistratie <?= h((string)$huidigeJaargang['jaar']) ?>">
                        </div>

                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label" for="sort-b">Sortering</label>
                                <input type="number" class="form-control" id="sort-b" name="sortering"
                                    step="1" value="0">
                            </div>
                            <div class="col-6 d-flex align-items-end">
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" id="act-b" name="actief"
                                        value="1" checked>
                                    <label class="form-check-label" for="act-b">Actief</label>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-outline-secondary mt-3">
                            <i class="bi bi-upload me-1"></i>Uploaden en koppelen
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

<?php endif; ?>

<?php
admin_eind();
