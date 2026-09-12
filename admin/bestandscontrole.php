<?php

/**
 * Beheer — Bestandscontrole.
 *
 * Vergelijkt elke regel in `jaargang_bestanden` met wat er werkelijk in de
 * opslagmap staat. Zo ziet de beheerder een verplaatst, hernoemd, verwijderd of
 * half geüpload videobestand vóórdat een ouder op een kapotte downloadknop
 * klikt.
 *
 * De controle gebeurt live bij het opbouwen van de pagina; er wordt niets
 * gecachet. Deze pagina raakt nooit een bestand op schijf aan: ze leest alleen.
 * Alle acties werken uitsluitend op de databaseregel, en altijd via
 * bestand-id → database → opslag_absoluut_pad().
 */

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/toegang_helper.php';
vereis_installatie();
$beheerder = vereis_beheerder();

// ─── Instellingen van de controle ────────────────────────────────────────────
/** Extensies die als videobestand (of downloadpakket) meetellen bij het scannen. */
const CONTROLE_EXTENSIES = ['mp4', 'mkv', 'mov', 'm4v', 'webm', 'avi', 'zip'];

/** Hoe diep de opslagmap doorzocht wordt. 1 = alleen de opslagmap zelf. */
const CONTROLE_MAX_DIEPTE = 3;

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Normaliseert een relatief pad uit de database zodat paden uit de scan en
 * paden uit de database met elkaar te vergelijken zijn.
 */
function controle_normaliseer_pad(string $pad): string
{
    return ltrim(str_replace('\\', '/', trim($pad)), '/');
}

/**
 * Onderzoekt één bestandsregel en geeft de bevindingen terug.
 *
 * Sleutels: status, absoluut, bytes_schijf, verschil, leesbaar, gewijzigd_op.
 * Mogelijke statussen: in_orde | afwijkend | ontbreekt | onleesbaar | leeg.
 */
function controle_onderzoek_bestand(array $rij): array
{
    $bevinding = [
        'status'       => 'ontbreekt',
        'absoluut'     => null,
        'bytes_db'     => (int)($rij['bytes'] ?? 0),
        'bytes_schijf' => null,
        'verschil'     => 0,
        'gewijzigd_op' => null,
    ];

    $absoluut = opslag_absoluut_pad((string)($rij['pad'] ?? ''));
    if ($absoluut === null) {
        return $bevinding;
    }
    $bevinding['absoluut'] = $absoluut;

    $mtime = @filemtime($absoluut);
    if ($mtime !== false) {
        $bevinding['gewijzigd_op'] = date('Y-m-d H:i:s', $mtime);
    }

    if (!is_readable($absoluut)) {
        $bevinding['status'] = 'onleesbaar';
        return $bevinding;
    }

    $opSchijf = @filesize($absoluut);
    if ($opSchijf === false) {
        // Bestand bestaat wel, maar de grootte is niet op te vragen.
        $bevinding['status'] = 'onleesbaar';
        return $bevinding;
    }

    $opSchijf = (int)$opSchijf;
    $bevinding['bytes_schijf'] = $opSchijf;
    $bevinding['verschil']     = $opSchijf - $bevinding['bytes_db'];

    if ($opSchijf === 0) {
        $bevinding['status'] = 'leeg';
    } elseif ($opSchijf !== $bevinding['bytes_db']) {
        $bevinding['status'] = 'afwijkend';
    } else {
        $bevinding['status'] = 'in_orde';
    }

    return $bevinding;
}

/** Label, Bootstrap-kleur, icoon en uitleg bij een status. */
function controle_status_uitleg(string $status): array
{
    return [
        'in_orde' => [
            'label'  => 'in orde',
            'kleur'  => 'success',
            'icoon'  => 'bi-check-circle',
            'uitleg' => 'Het bestand staat in de opslagmap en de grootte klopt met de database.',
        ],
        'afwijkend' => [
            'label'  => 'grootte wijkt af',
            'kleur'  => 'warning',
            'icoon'  => 'bi-exclamation-triangle',
            'uitleg' => 'Het bestand bestaat, maar is niet zo groot als de database aangeeft. '
                . 'Meestal een afgebroken upload of een vervangen bestand.',
        ],
        'ontbreekt' => [
            'label'  => 'ontbreekt',
            'kleur'  => 'danger',
            'icoon'  => 'bi-x-octagon',
            'uitleg' => 'Op dit pad staat geen bestand meer. Verplaatst, hernoemd of verwijderd.',
        ],
        'onleesbaar' => [
            'label'  => 'onleesbaar',
            'kleur'  => 'danger',
            'icoon'  => 'bi-shield-lock',
            'uitleg' => 'Het bestand staat er wel, maar de webserver mag het niet lezen. '
                . 'Controleer de rechten van het bestand en de bovenliggende mappen.',
        ],
        'leeg' => [
            'label'  => 'leeg',
            'kleur'  => 'danger',
            'icoon'  => 'bi-file-earmark-x',
            'uitleg' => 'Het bestand is 0 bytes groot. De upload is vrijwel zeker mislukt.',
        ],
    ][$status] ?? [
        'label'  => 'onbekend',
        'kleur'  => 'secondary',
        'icoon'  => 'bi-question-circle',
        'uitleg' => 'De status van dit bestand kon niet worden bepaald.',
    ];
}

/** Is deze status een probleem waar de beheerder iets mee moet? */
function controle_is_probleem(string $status): bool
{
    return $status !== 'in_orde';
}

/**
 * Scant de opslagmap recursief (maximaal CONTROLE_MAX_DIEPTE niveaus, zonder
 * symlinks te volgen) op videobestanden.
 *
 * @return array<string, array{pad: string, bytes: int, gewijzigd_op: ?string}>
 */
function controle_scan_opslag(): array
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
            if ($item === '' || $item === '.' || $item === '..' || $item[0] === '.') {
                continue;
            }
            $volledig = $map . '/' . $item;
            if (is_link($volledig)) {
                continue;   // symlinks nooit volgen: die kunnen buiten de opslagmap wijzen
            }
            $relatief = $relatieveMap === '' ? $item : $relatieveMap . '/' . $item;

            if (is_dir($volledig)) {
                if ($diepte < CONTROLE_MAX_DIEPTE) {
                    $stapel[] = [$relatief, $diepte + 1];
                }
                continue;
            }
            if (!is_file($volledig)) {
                continue;
            }
            $ext = strtolower((string)pathinfo($item, PATHINFO_EXTENSION));
            if (!in_array($ext, CONTROLE_EXTENSIES, true)) {
                continue;
            }

            $mtime = @filemtime($volledig);
            $gevonden[$relatief] = [
                'pad'          => $relatief,
                'bytes'        => (int)@filesize($volledig),
                'gewijzigd_op' => $mtime !== false ? date('Y-m-d H:i:s', $mtime) : null,
            ];
        }
    }

    ksort($gevonden, SORT_NATURAL | SORT_FLAG_CASE);
    return $gevonden;
}

/** Eén bestandsregel met de gegevens van de jaargang erbij, of null. */
function controle_bestand_ophalen(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    try {
        $stmt = db()->prepare(
            'SELECT b.*, j.jaar, j.titel AS jaargang_titel
               FROM jaargang_bestanden b
               JOIN jaargangen j ON j.id = b.jaargang_id
              WHERE b.id = :id'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        app_log('bestandscontrole: regel ophalen mislukt', ['fout' => $e->getMessage()]);
        return null;
    }
}

$paginaUrl = url('admin/bestandscontrole.php');

// ─── Verwerking van de acties ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    vereis_csrf();
    $actie = (string)($_POST['actie'] ?? '');
    $id    = (int)($_POST['id'] ?? 0);
    $rij   = controle_bestand_ophalen($id);

    if ($rij === null) {
        flash('danger', 'Die bestandsregel bestaat niet (meer).');
        header('Location: ' . $paginaUrl);
        exit;
    }

    try {
        switch ($actie) {

            // ── Werkelijke grootte overnemen in de database ─────────────────
            case 'grootte_bijwerken':
                $absoluut = opslag_absoluut_pad((string)$rij['pad']);
                if ($absoluut === null) {
                    flash('danger', 'Het bestand staat niet (meer) in de opslagmap; de grootte kan niet worden bijgewerkt.');
                    break;
                }
                $opSchijf = @filesize($absoluut);
                if ($opSchijf === false) {
                    flash('danger', 'De grootte van het bestand kon niet worden opgevraagd.');
                    break;
                }
                db()->prepare('UPDATE jaargang_bestanden SET bytes = :b WHERE id = :id')
                    ->execute([':b' => (int)$opSchijf, ':id' => $id]);
                flash('success', 'De grootte van "' . (string)$rij['titel'] . '" is bijgewerkt naar '
                    . formatteer_bytes((int)$opSchijf) . '.');
                break;

            // ── Downloadknop meteen uit het portaal halen ───────────────────
            case 'uitschakelen':
                db()->prepare('UPDATE jaargang_bestanden SET actief = 0 WHERE id = :id')
                    ->execute([':id' => $id]);
                flash('success', '"' . (string)$rij['titel'] . '" is uitgeschakeld. '
                    . 'De downloadknop is nu uit het portaal verdwenen.');
                break;

            // ── Weer zichtbaar maken ───────────────────────────────────────
            case 'inschakelen':
                db()->prepare('UPDATE jaargang_bestanden SET actief = 1 WHERE id = :id')
                    ->execute([':id' => $id]);
                flash('success', '"' . (string)$rij['titel'] . '" is weer ingeschakeld.');
                break;

            // ── Alleen de databaseregel weghalen ────────────────────────────
            case 'verwijderen':
                db()->prepare('DELETE FROM jaargang_bestanden WHERE id = :id')->execute([':id' => $id]);
                flash('success', 'De regel "' . (string)$rij['titel'] . '" is uit de database verwijderd. '
                    . 'Een eventueel bestand op schijf is niet aangeraakt en staat er nog.');
                break;

            // ── SHA-256 berekenen of controleren (alleen op verzoek) ────────
            case 'hash':
                $absoluut = opslag_absoluut_pad((string)$rij['pad']);
                if ($absoluut === null) {
                    flash('danger', 'Het bestand staat niet (meer) in de opslagmap; er valt niets te berekenen.');
                    break;
                }
                if (!is_readable($absoluut)) {
                    flash('danger', 'Het bestand is niet leesbaar voor de webserver; controleer de rechten.');
                    break;
                }

                @set_time_limit(0);
                // hash_file() leest het bestand in blokken; het hele bestand
                // komt dus nooit in het geheugen te staan.
                $hash = @hash_file('sha256', $absoluut);
                if ($hash === false || $hash === null) {
                    flash('danger', 'De controlesom kon niet worden berekend.');
                    break;
                }

                $bestaand = trim((string)($rij['sha256'] ?? ''));
                if ($bestaand === '') {
                    db()->prepare('UPDATE jaargang_bestanden SET sha256 = :h WHERE id = :id')
                        ->execute([':h' => $hash, ':id' => $id]);
                    flash('success', 'SHA-256 berekend en opgeslagen voor "' . (string)$rij['titel'] . '": ' . $hash);
                } elseif (hash_equals(strtolower($bestaand), strtolower($hash))) {
                    flash('success', 'De controlesom van "' . (string)$rij['titel'] . '" klopt nog: het bestand is ongewijzigd.');
                } else {
                    flash('danger', 'De controlesom van "' . (string)$rij['titel'] . '" wijkt af! '
                        . 'Opgeslagen: ' . $bestaand . ' — nu berekend: ' . $hash . '. '
                        . 'Het bestand op schijf is veranderd of beschadigd.');
                }
                break;

            default:
                flash('warning', 'Onbekende actie.');
                break;
        }
    } catch (Throwable $e) {
        app_log('bestandscontrole: actie mislukt', ['actie' => $actie, 'id' => $id, 'fout' => $e->getMessage()]);
        flash('danger', 'De bewerking is mislukt. Zie het technische logboek voor details.');
    }

    header('Location: ' . $paginaUrl);
    exit;
}

// ─── Staat van de opslagmap ──────────────────────────────────────────────────
$opslagPad   = opslag_pad();
$opslagBasis = realpath($opslagPad);
$opslagOk    = $opslagBasis !== false && is_dir($opslagBasis) && is_readable($opslagBasis);

// ─── Alle jaargangen (ook zonder bestanden) ──────────────────────────────────
$jaargangen = [];
try {
    $jaargangen = db()->query(
        'SELECT id, jaar, titel, gepubliceerd, zichtbaar_vanaf, verloopt_op
           FROM jaargangen ORDER BY jaar DESC'
    )->fetchAll() ?: [];
} catch (Throwable $e) {
    app_log('bestandscontrole: jaargangen ophalen mislukt', ['fout' => $e->getMessage()]);
    flash('warning', 'De jaargangen konden niet worden geladen.');
}

// ─── Alle gekoppelde bestanden met de status van hun jaargang ────────────────
$rijen = [];
if ($opslagOk) {
    try {
        $rijen = db()->query(
            'SELECT b.*, j.jaar, j.titel AS jaargang_titel,
                    j.gepubliceerd, j.zichtbaar_vanaf, j.verloopt_op,
                    CASE WHEN ' . sql_jaargang_zichtbaar('j') . ' THEN 1 ELSE 0 END AS jaargang_zichtbaar
               FROM jaargang_bestanden b
               JOIN jaargangen j ON j.id = b.jaargang_id
              ORDER BY j.jaar DESC, b.sortering ASC, b.id ASC'
        )->fetchAll() ?: [];
    } catch (Throwable $e) {
        app_log('bestandscontrole: bestanden ophalen mislukt', ['fout' => $e->getMessage()]);
        flash('danger', 'De bestandenlijst kon niet worden geladen.');
    }
}

// ─── Controleren en groeperen per jaargang ───────────────────────────────────
$perJaargang    = [];
$telling        = ['in_orde' => 0, 'afwijkend' => 0, 'ontbreekt' => 0, 'onleesbaar' => 0, 'leeg' => 0];
$kritiek        = [];   // probleem én nu zichtbaar voor deelnemers
$gekoppeldePaden = [];
$totaalBestanden = 0;

foreach ($rijen as $rij) {
    $jaargangId = (int)$rij['jaargang_id'];
    $bevinding  = controle_onderzoek_bestand($rij);
    $status     = (string)$bevinding['status'];

    $rij['controle']  = $bevinding;
    $rij['probleem']  = controle_is_probleem($status);
    $rij['zichtbaar'] = (int)$rij['jaargang_zichtbaar'] === 1 && (int)$rij['actief'] === 1;

    if (isset($telling[$status])) {
        $telling[$status]++;
    }
    $totaalBestanden++;
    $gekoppeldePaden[controle_normaliseer_pad((string)$rij['pad'])] = (int)$rij['jaar'];

    if (!isset($perJaargang[$jaargangId])) {
        $perJaargang[$jaargangId] = [
            'id'        => $jaargangId,
            'jaar'      => (int)$rij['jaar'],
            'titel'     => (string)$rij['jaargang_titel'],
            'status'    => jaargang_status([
                'gepubliceerd'    => (int)$rij['gepubliceerd'],
                'zichtbaar_vanaf' => $rij['zichtbaar_vanaf'],
                'verloopt_op'     => $rij['verloopt_op'],
            ]),
            'zichtbaar' => (int)$rij['jaargang_zichtbaar'] === 1,
            'bestanden' => [],
        ];
    }
    $perJaargang[$jaargangId]['bestanden'][] = $rij;

    if ($rij['probleem'] && $rij['zichtbaar']) {
        $kritiek[] = $rij;
    }
}

$aantalProblemen = $telling['afwijkend'] + $telling['ontbreekt'] + $telling['onleesbaar'] + $telling['leeg'];

// Aantal deelnemers per jaargang, alleen opvragen waar het nodig is.
$deelnemersPerJaargang = [];
foreach ($kritiek as $rij) {
    $jid = (int)$rij['jaargang_id'];
    if (!array_key_exists($jid, $deelnemersPerJaargang)) {
        try {
            $deelnemersPerJaargang[$jid] = jaargang_aantal_deelnemers($jid);
        } catch (Throwable $e) {
            app_log('bestandscontrole: deelnemers tellen mislukt', ['fout' => $e->getMessage()]);
            $deelnemersPerJaargang[$jid] = 0;
        }
    }
}

// ─── Verweesde bestanden in de opslagmap ─────────────────────────────────────
$verweesd = [];
if ($opslagOk) {
    foreach (controle_scan_opslag() as $pad => $info) {
        if (!isset($gekoppeldePaden[controle_normaliseer_pad($pad)])) {
            $verweesd[] = $info;
        }
    }
}

// Jaargang waar een verweesd bestand naartoe gekoppeld zou worden.
$koppelJaargangId = (int)($_GET['jaargang'] ?? 0);
$koppelBestaat    = false;
foreach ($jaargangen as $jg) {
    if ((int)$jg['id'] === $koppelJaargangId) {
        $koppelBestaat = true;
        break;
    }
}
if (!$koppelBestaat) {
    $koppelJaargangId = 0;
}
$koppelUrl = url('admin/bestanden.php') . ($koppelJaargangId > 0 ? '?jaargang=' . $koppelJaargangId : '');

admin_start(
    'Bestandscontrole',
    'Klopt elke databaseregel nog met het bestand op schijf?'
);
?>

<!-- ─── Actiebalk ───────────────────────────────────────────────────────── -->
<div class="d-flex flex-wrap gap-2 mb-4">
    <a class="btn btn-djm" href="<?= h($paginaUrl) ?>">
        <i class="bi bi-arrow-clockwise me-1"></i>Opnieuw controleren
    </a>
    <a class="btn btn-outline-secondary" href="<?= h(url('admin/bestanden.php')) ?>">
        <i class="bi bi-film me-1"></i>Naar Bestanden
    </a>
    <span class="align-self-center text-muted small ms-sm-2">
        Gecontroleerd op <?= h(formatteer_datum(date('Y-m-d H:i:s'))) ?> · opslagmap
        <code class="pad"><?= h($opslagPad) ?></code>
    </span>
</div>

<?php if (!$opslagOk): ?>

    <!-- ─── De opslagmap zelf is het probleem ───────────────────────────── -->
    <div class="alert alert-danger">
        <h2 class="h5 alert-heading">
            <i class="bi bi-exclamation-octagon me-1"></i>De opslagmap is niet bruikbaar
        </h2>
        <p class="mb-2">
            Het verwachte pad is:
            <code class="pad d-block mt-1"><?= h($opslagPad) ?></code>
        </p>
        <p class="mb-2">
            <?php if ($opslagBasis === false || !is_dir((string)$opslagBasis)): ?>
                Die map bestaat niet (of is geen map). Waarschijnlijk staat <code>OPSLAG_PAD</code> in
                <code>.env</code> verkeerd, of is de map verplaatst of verwijderd.
            <?php else: ?>
                Die map bestaat wel, maar de webserver mag hem niet lezen. Controleer de rechten en
                de eigenaar van de map en van alle bovenliggende mappen.
            <?php endif; ?>
        </p>
        <p class="mb-0">
            Zolang dit niet klopt kan geen enkel bestand worden gecontroleerd én kan geen enkele
            deelnemer downloaden. Herstel dit eerst en klik daarna op
            <strong>Opnieuw controleren</strong>.
        </p>
    </div>

<?php else: ?>

    <!-- ─── Samenvatting ────────────────────────────────────────────────── -->
    <?php if ($totaalBestanden === 0): ?>
        <div class="kaart p-5 text-center text-muted mb-4">
            <i class="bi bi-hdd-stack fs-1 d-block mb-2 opacity-50"></i>
            Er is nog geen enkel bestand aan een jaargang gekoppeld. Er valt dus niets te controleren.
            <div class="mt-3">
                <a class="btn btn-djm btn-sm" href="<?= h(url('admin/bestanden.php')) ?>">
                    <i class="bi bi-link-45deg me-1"></i>Bestand koppelen
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-3 mb-4">
            <?php
            $kaarten = [
                ['in orde',           $telling['in_orde'],    'success', 'bi-check-circle'],
                ['grootte wijkt af',  $telling['afwijkend'],  'warning', 'bi-exclamation-triangle'],
                ['ontbreekt',         $telling['ontbreekt'],  'danger',  'bi-x-octagon'],
                ['onleesbaar',        $telling['onleesbaar'], 'danger',  'bi-shield-lock'],
                ['leeg (0 bytes)',    $telling['leeg'],       'danger',  'bi-file-earmark-x'],
            ];
            foreach ($kaarten as [$kaartLabel, $kaartAantal, $kaartKleur, $kaartIcoon]):
            ?>
                <div class="col-6 col-lg">
                    <div class="kaart p-3 h-100 border-<?= h($kaartKleur) ?><?= $kaartAantal > 0 ? '' : ' opacity-75' ?>">
                        <div class="text-<?= h($kaartKleur) ?>">
                            <i class="bi <?= h($kaartIcoon) ?> me-1"></i><span class="small"><?= h($kaartLabel) ?></span>
                        </div>
                        <div class="fs-3 fw-semibold"><?= (int)$kaartAantal ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($aantalProblemen === 0): ?>
            <div class="alert alert-success">
                <i class="bi bi-check2-circle me-1"></i>
                Alle <?= (int)$totaalBestanden ?> bestanden zijn aanwezig en compleet.
                Er is niets dat uw aandacht nodig heeft.
            </div>
        <?php else: ?>
            <div class="alert alert-warning">
                <h2 class="h6 alert-heading">
                    <i class="bi bi-tools me-1"></i>Wat u moet doen
                </h2>
                <ul class="mb-0 ps-3">
                    <?php if ($telling['ontbreekt'] > 0): ?>
                        <li>
                            <strong><?= (int)$telling['ontbreekt'] ?> bestand(en) ontbreken.</strong>
                            Zet het bestand terug op het oude pad in de opslagmap, of verwijder de
                            databaseregel en koppel het bestand opnieuw via
                            <a href="<?= h(url('admin/bestanden.php')) ?>">Bestanden</a>.
                        </li>
                    <?php endif; ?>
                    <?php if ($telling['afwijkend'] > 0): ?>
                        <li>
                            <strong><?= (int)$telling['afwijkend'] ?> bestand(en) hebben een afwijkende grootte.</strong>
                            Controleer of de SFTP-upload volledig is afgerond. Klopt het bestand,
                            werk dan de grootte bij; is de upload afgebroken, upload dan opnieuw.
                        </li>
                    <?php endif; ?>
                    <?php if ($telling['onleesbaar'] > 0): ?>
                        <li>
                            <strong><?= (int)$telling['onleesbaar'] ?> bestand(en) zijn onleesbaar.</strong>
                            Geef de webserver leesrechten op het bestand en op alle bovenliggende mappen.
                        </li>
                    <?php endif; ?>
                    <?php if ($telling['leeg'] > 0): ?>
                        <li>
                            <strong><?= (int)$telling['leeg'] ?> bestand(en) zijn leeg (0 bytes).</strong>
                            De upload is mislukt; upload het bestand opnieuw.
                        </li>
                    <?php endif; ?>
                    <li>
                        Kunt u een probleem niet meteen oplossen? Schakel het bestand dan uit, dan
                        verdwijnt de downloadknop uit het portaal in plaats van een foutmelding te geven.
                    </li>
                </ul>
            </div>
        <?php endif; ?>

        <!-- ─── Zichtbaar voor deelnemers én kapot ──────────────────────── -->
        <?php if ($kritiek): ?>
            <div class="alert alert-danger">
                <h2 class="h5 alert-heading">
                    <i class="bi bi-exclamation-octagon me-1"></i>
                    Nu zichtbaar voor deelnemers, maar niet te downloaden
                </h2>
                <p>
                    Deze <?= (int)count($kritiek) ?> bestand(en) staan op actief in een jaargang die nú
                    zichtbaar is. Wie hierop klikt, krijgt een foutmelding. Los het bestand op of
                    schakel het uit.
                </p>
                <div class="table-responsive">
                    <table class="table table-sm tabel-compact align-middle mb-0 bg-white rounded">
                        <thead>
                            <tr>
                                <th scope="col">Jaar</th>
                                <th scope="col">Bestand</th>
                                <th scope="col">Status</th>
                                <th scope="col" class="text-end">Deelnemers met toegang</th>
                                <th scope="col" class="text-end">Actie</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($kritiek as $rij): ?>
                                <?php $uitleg = controle_status_uitleg((string)$rij['controle']['status']); ?>
                                <tr>
                                    <td class="text-nowrap"><?= (int)$rij['jaar'] ?></td>
                                    <td>
                                        <div class="fw-semibold"><?= h((string)$rij['titel']) ?></div>
                                        <code class="pad"><?= h((string)$rij['pad']) ?></code>
                                    </td>
                                    <td>
                                        <span class="badge text-bg-<?= h($uitleg['kleur']) ?>">
                                            <i class="bi <?= h($uitleg['icoon']) ?> me-1"></i><?= h($uitleg['label']) ?>
                                        </span>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <i class="bi bi-people me-1"></i>
                                        <?= (int)($deelnemersPerJaargang[(int)$rij['jaargang_id']] ?? 0) ?>
                                    </td>
                                    <td class="text-end">
                                        <form method="post" class="d-inline"
                                            <?= bevestig_attribuut('Dit bestand uitschakelen? De downloadknop verdwijnt meteen uit het portaal.') ?>>
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="actie" value="uitschakelen">
                                            <input type="hidden" name="id" value="<?= (int)$rij['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">
                                                <i class="bi bi-eye-slash me-1"></i>Uitschakelen
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- ─── Overzicht per jaargang ──────────────────────────────────── -->
        <?php foreach ($perJaargang as $jaargang): ?>
            <?php [$statusLabel, $statusKleur] = $jaargang['status']; ?>
            <div class="kaart mb-4">
                <div class="p-3 border-bottom d-flex flex-wrap align-items-center gap-2">
                    <h2 class="h6 mb-0 me-2">
                        <i class="bi bi-calendar3 me-1"></i><?= (int)$jaargang['jaar'] ?> —
                        <?= h($jaargang['titel']) ?>
                    </h2>
                    <span class="badge text-bg-<?= h($statusKleur) ?>"><?= h($statusLabel) ?></span>
                    <span class="text-muted small">
                        <?= (int)count($jaargang['bestanden']) ?> bestand(en)
                    </span>
                    <div class="ms-auto">
                        <a class="btn btn-sm btn-outline-secondary"
                            href="<?= h(url('admin/bestanden.php?jaargang=' . (int)$jaargang['id'])) ?>">
                            <i class="bi bi-pencil me-1"></i>Bestanden van deze jaargang
                        </a>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table tabel-compact align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Bestand</th>
                                <th scope="col">Op schijf</th>
                                <th scope="col" class="text-end">Grootte</th>
                                <th scope="col" class="text-nowrap">Gewijzigd</th>
                                <th scope="col" class="text-center">Actief</th>
                                <th scope="col" class="text-end">Acties</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($jaargang['bestanden'] as $rij): ?>
                                <?php
                                $bevinding = $rij['controle'];
                                $status    = (string)$bevinding['status'];
                                $uitleg    = controle_status_uitleg($status);
                                $isActief  = (int)$rij['actief'] === 1;
                                $verschil  = (int)$bevinding['verschil'];
                                ?>
                                <tr<?= $rij['probleem'] && $rij['zichtbaar'] ? ' class="table-danger"' : '' ?>>
                                    <td>
                                        <div class="fw-semibold"><?= h((string)$rij['titel']) ?></div>
                                        <div class="small text-muted">
                                            <i class="bi bi-download me-1"></i><?= h((string)$rij['bestandsnaam']) ?>
                                        </div>
                                        <code class="pad"><?= h((string)$rij['pad']) ?></code>
                                        <?php if (!empty($rij['sha256'])): ?>
                                            <div class="small text-muted">
                                                <i class="bi bi-fingerprint me-1"></i>
                                                <code class="pad"><?= h(substr((string)$rij['sha256'], 0, 16)) ?>…</code>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="min-width: 15rem;">
                                        <span class="badge text-bg-<?= h($uitleg['kleur']) ?>">
                                            <i class="bi <?= h($uitleg['icoon']) ?> me-1"></i><?= h($uitleg['label']) ?>
                                        </span>
                                        <div class="small text-muted mt-1"><?= h($uitleg['uitleg']) ?></div>
                                        <?php if ($rij['probleem'] && $rij['zichtbaar']): ?>
                                            <div class="small text-danger fw-semibold mt-1">
                                                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                                                Zichtbaar voor deelnemers: de downloadknop staat nu aan.
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-nowrap small">
                                        <div>
                                            <span class="text-muted">database</span>
                                            <?= h(formatteer_bytes((int)$bevinding['bytes_db'])) ?>
                                        </div>
                                        <?php if ($bevinding['bytes_schijf'] !== null): ?>
                                            <div<?= $status === 'afwijkend' || $status === 'leeg' ? ' class="text-danger fw-semibold"' : '' ?>>
                                                <span class="text-muted fw-normal">schijf</span>
                                                <?= h(formatteer_bytes((int)$bevinding['bytes_schijf'])) ?>
                                            </div>
                                            <?php if ($verschil !== 0): ?>
                                                <div class="text-danger">
                                                    <?= h(($verschil > 0 ? '+' : '−') . formatteer_bytes(abs($verschil))) ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <div class="text-muted">schijf —</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-nowrap small">
                                        <?= h(formatteer_datum($bevinding['gewijzigd_op'])) ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($isActief): ?>
                                            <span class="badge text-bg-success">actief</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-secondary">uit</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex flex-column align-items-end gap-1">
                                            <?php if ($status === 'afwijkend'): ?>
                                                <form method="post"
                                                    <?= bevestig_attribuut('De grootte in de database vervangen door de werkelijke bestandsgrootte?') ?>>
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="actie" value="grootte_bijwerken">
                                                    <input type="hidden" name="id" value="<?= (int)$rij['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-warning text-nowrap">
                                                        <i class="bi bi-arrow-repeat me-1"></i>Grootte bijwerken
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <?php if ($rij['probleem'] && $isActief): ?>
                                                <form method="post"
                                                    <?= bevestig_attribuut('Dit bestand uitschakelen? De downloadknop verdwijnt meteen uit het portaal.') ?>>
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="actie" value="uitschakelen">
                                                    <input type="hidden" name="id" value="<?= (int)$rij['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger text-nowrap">
                                                        <i class="bi bi-eye-slash me-1"></i>Uitschakelen
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <?php if ($status === 'in_orde' && !$isActief): ?>
                                                <form method="post">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="actie" value="inschakelen">
                                                    <input type="hidden" name="id" value="<?= (int)$rij['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-success text-nowrap">
                                                        <i class="bi bi-eye me-1"></i>Inschakelen
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <form method="post"
                                                <?= bevestig_attribuut('SHA-256 berekenen? Bij een grote videoregistratie kan dit enkele minuten duren; laat het venster open staan.') ?>>
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="actie" value="hash">
                                                <input type="hidden" name="id" value="<?= (int)$rij['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-secondary text-nowrap"
                                                    <?= $bevinding['absoluut'] === null ? 'disabled' : '' ?>>
                                                    <i class="bi bi-fingerprint me-1"></i>
                                                    <?= empty($rij['sha256']) ? 'SHA-256 berekenen' : 'SHA-256 controleren' ?>
                                                </button>
                                            </form>

                                            <form method="post"
                                                <?= bevestig_attribuut('Alleen de databaseregel verwijderen? Een eventueel bestand op schijf blijft gewoon staan.') ?>>
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="actie" value="verwijderen">
                                                <input type="hidden" name="id" value="<?= (int)$rij['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-secondary text-nowrap">
                                                    <i class="bi bi-trash me-1"></i>Regel verwijderen
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- ─── Verweesde bestanden in de opslagmap ─────────────────────────── -->
    <div class="kaart mb-4">
        <div class="p-3 border-bottom">
            <h2 class="h6 mb-0">
                <i class="bi bi-folder2-open me-1"></i>Niet-gekoppelde bestanden in de opslagmap
                <span class="text-muted fw-normal">(<?= (int)count($verweesd) ?>)</span>
            </h2>
            <div class="text-muted small mt-1">
                Videobestanden die wél in de opslagmap staan, maar aan geen enkele jaargang gekoppeld
                zijn. Puur informatief — deze pagina verwijdert nooit iets van schijf.
            </div>
        </div>

        <?php if (!$verweesd): ?>
            <div class="p-4 text-center text-muted">
                <i class="bi bi-check2 me-1"></i>
                Elk videobestand in de opslagmap is aan een jaargang gekoppeld.
            </div>
        <?php else: ?>
            <?php if ($jaargangen): ?>
                <div class="p-3 border-bottom bg-light">
                    <form method="get" class="row g-2 align-items-end">
                        <div class="col-sm-6 col-lg-4">
                            <label class="form-label" for="jaargang">Koppelen aan jaargang</label>
                            <select class="form-select" id="jaargang" name="jaargang">
                                <option value="0">— kies een jaargang —</option>
                                <?php foreach ($jaargangen as $jg): ?>
                                    <option value="<?= (int)$jg['id'] ?>"
                                        <?= (int)$jg['id'] === $koppelJaargangId ? 'selected' : '' ?>>
                                        <?= h((string)$jg['jaar']) ?> — <?= h((string)$jg['titel']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-auto">
                            <button type="submit" class="btn btn-outline-secondary">
                                <i class="bi bi-check2 me-1"></i>Kiezen
                            </button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table tabel-compact align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Pad in de opslagmap</th>
                            <th scope="col" class="text-end">Grootte</th>
                            <th scope="col" class="text-nowrap">Gewijzigd</th>
                            <th scope="col" class="text-end">Actie</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($verweesd as $los): ?>
                            <tr>
                                <td><code class="pad"><?= h((string)$los['pad']) ?></code></td>
                                <td class="text-end text-nowrap small">
                                    <?= h(formatteer_bytes((int)$los['bytes'])) ?>
                                </td>
                                <td class="text-nowrap small">
                                    <?= h(formatteer_datum($los['gewijzigd_op'])) ?>
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-secondary text-nowrap"
                                        href="<?= h($koppelUrl) ?>">
                                        <i class="bi bi-link-45deg me-1"></i>Koppelen
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

<?php endif; ?>

<?php admin_eind(); ?>
