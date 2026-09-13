<?php

/**
 * Beheer — Jaargangen.
 *
 * Eén pagina met de lijst van jaargangen en een aanmaak-/bewerkformulier.
 * Bewerken via ?id=<id>. Alle wijzigingen verlopen via Post/Redirect/Get.
 */

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/toegang_helper.php';
vereis_installatie();
$beheerder = vereis_beheerder();

// ─── Hulpfuncties ────────────────────────────────────────────────────────────

/** Maakt een URL-vriendelijke slug: alleen a-z, 0-9 en streepjes. */
function jaargang_slug_maken(string $tekst): string
{
    $tekst = strtolower(trim($tekst));
    // Veelvoorkomende accenten platslaan zonder afhankelijkheid van intl/iconv.
    $tekst = strtr($tekst, [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'ß' => 'ss', 'ij' => 'ij',
    ]);
    $tekst = preg_replace('/[^a-z0-9]+/', '-', $tekst) ?? '';
    return trim($tekst, '-');
}

/** Zorgt dat de slug uniek is door zo nodig -2, -3, … toe te voegen. */
function jaargang_slug_uniek(string $slug, int $negeerId = 0): string
{
    $slug = substr($slug, 0, 70);
    if ($slug === '') {
        $slug = 'jaargang';
    }
    $kandidaat = $slug;
    $nummer    = 1;
    $stmt      = db()->prepare('SELECT COUNT(*) FROM jaargangen WHERE slug = :s AND id <> :id');
    while (true) {
        $stmt->execute([':s' => $kandidaat, ':id' => $negeerId]);
        if ((int)$stmt->fetchColumn() === 0) {
            return $kandidaat;
        }
        $nummer++;
        $kandidaat = $slug . '-' . $nummer;
    }
}

/** Zet de waarde van een datetime-local-veld om naar DATETIME of NULL. */
function jaargang_datum_uit_formulier(string $waarde): ?string
{
    $waarde = trim($waarde);
    if ($waarde === '') {
        return null;
    }
    $ts = strtotime($waarde);
    return $ts === false ? null : date('Y-m-d H:i:s', $ts);
}

/** Zet een DATETIME uit de database om naar de notatie van datetime-local. */
function jaargang_datum_voor_formulier(?string $waarde): string
{
    if ($waarde === null || trim($waarde) === '') {
        return '';
    }
    $ts = strtotime($waarde);
    return $ts === false ? '' : date('Y-m-d\TH:i', $ts);
}

$terugUrl = url('admin/jaargangen.php');

// ─── Verwerking ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    vereis_csrf();
    $actie = (string)($_POST['actie'] ?? '');

    // ── Publiceren / depubliceren ───────────────────────────────────────────
    if ($actie === 'publicatie') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmt = db()->prepare('SELECT id, jaar, gepubliceerd FROM jaargangen WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $rij = $stmt->fetch();
            if (!$rij) {
                flash('danger', 'Die jaargang bestaat niet (meer).');
            } else {
                $nieuw = (int)$rij['gepubliceerd'] === 1 ? 0 : 1;
                db()->prepare('UPDATE jaargangen SET gepubliceerd = :g WHERE id = :id')
                    ->execute([':g' => $nieuw, ':id' => $id]);
                flash('success', $nieuw === 1
                    ? 'Jaargang ' . (int)$rij['jaar'] . ' is gepubliceerd.'
                    : 'Jaargang ' . (int)$rij['jaar'] . ' is gedepubliceerd.');
            }
        } catch (Throwable $e) {
            app_log('publicatie wisselen mislukt', ['fout' => $e->getMessage()]);
            flash('danger', 'Wijzigen van de publicatiestatus is mislukt.');
        }
        header('Location: ' . $terugUrl);
        exit;
    }

    // ── Verwijderen ─────────────────────────────────────────────────────────
    if ($actie === 'verwijderen') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmt = db()->prepare('SELECT jaar, titel FROM jaargangen WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $rij = $stmt->fetch();
            if (!$rij) {
                flash('danger', 'Die jaargang bestaat niet (meer).');
            } else {
                // De database ruimt via ON DELETE CASCADE de bestandsregels en
                // toegangsrechten op. De videobestanden op schijf blijven staan.
                db()->prepare('DELETE FROM jaargangen WHERE id = :id')->execute([':id' => $id]);
                flash('success', 'Jaargang ' . (int)$rij['jaar'] . ' is verwijderd. '
                    . 'De videobestanden staan nog gewoon in de opslagmap.');
            }
        } catch (Throwable $e) {
            app_log('jaargang verwijderen mislukt', ['fout' => $e->getMessage()]);
            flash('danger', 'Verwijderen is mislukt.');
        }
        header('Location: ' . $terugUrl);
        exit;
    }

    // ── Opslaan (nieuw of bewerken) ─────────────────────────────────────────
    if ($actie === 'opslaan') {
        $id           = (int)($_POST['id'] ?? 0);
        $jaarInvoer   = trim((string)($_POST['jaar'] ?? ''));
        $titel        = trim((string)($_POST['titel'] ?? ''));
        $slugInvoer   = trim((string)($_POST['slug'] ?? ''));
        $omschrijving = trim((string)($_POST['omschrijving'] ?? ''));
        $gepubliceerd = isset($_POST['gepubliceerd']) ? 1 : 0;
        $zichtbaar    = jaargang_datum_uit_formulier((string)($_POST['zichtbaar_vanaf'] ?? ''));
        $verloopt     = jaargang_datum_uit_formulier((string)($_POST['verloopt_op'] ?? ''));

        $fouten = [];
        if ($jaarInvoer === '' || !ctype_digit($jaarInvoer)) {
            $fouten[] = 'Vul een geldig jaartal in.';
        }
        $jaar = (int)$jaarInvoer;
        if ($jaar < 1900 || $jaar > 2200) {
            $fouten[] = 'Het jaartal moet tussen 1900 en 2200 liggen.';
        }
        if ($titel === '') {
            $fouten[] = 'Vul een titel in.';
        }
        if ($zichtbaar !== null && $verloopt !== null && strtotime($verloopt) <= strtotime($zichtbaar)) {
            $fouten[] = 'De vervaldatum moet ná de datum van zichtbaarheid liggen.';
        }

        if ($fouten) {
            foreach ($fouten as $fout) {
                flash('danger', $fout);
            }
            // Ingevulde waarden bewaren zodat de gebruiker niets kwijt is.
            $_SESSION['jaargang_formulier'] = [
                'id'              => $id,
                'jaar'            => $jaarInvoer,
                'titel'           => $titel,
                'slug'            => $slugInvoer,
                'omschrijving'    => $omschrijving,
                'gepubliceerd'    => $gepubliceerd,
                'zichtbaar_vanaf' => (string)($_POST['zichtbaar_vanaf'] ?? ''),
                'verloopt_op'     => (string)($_POST['verloopt_op'] ?? ''),
            ];
            header('Location: ' . $terugUrl . ($id > 0 ? '?id=' . $id : ''));
            exit;
        }

        $slug = jaargang_slug_maken($slugInvoer !== '' ? $slugInvoer : $titel . '-' . $jaar);
        $slug = jaargang_slug_uniek($slug, $id);

        $velden = [
            ':jaar'         => $jaar,
            ':titel'        => substr($titel, 0, 150),
            ':slug'         => $slug,
            ':omschrijving' => $omschrijving !== '' ? $omschrijving : null,
            ':gepubliceerd' => $gepubliceerd,
            ':zichtbaar'    => $zichtbaar,
            ':verloopt'     => $verloopt,
        ];

        try {
            if ($id > 0) {
                $velden[':id'] = $id;
                db()->prepare(
                    'UPDATE jaargangen
                        SET jaar = :jaar, titel = :titel, slug = :slug, omschrijving = :omschrijving,
                            gepubliceerd = :gepubliceerd, zichtbaar_vanaf = :zichtbaar,
                            verloopt_op = :verloopt
                      WHERE id = :id'
                )->execute($velden);
                flash('success', 'Jaargang ' . $jaar . ' is bijgewerkt.');
            } else {
                db()->prepare(
                    'INSERT INTO jaargangen
                        (jaar, titel, slug, omschrijving, gepubliceerd, zichtbaar_vanaf, verloopt_op)
                     VALUES
                        (:jaar, :titel, :slug, :omschrijving, :gepubliceerd, :zichtbaar, :verloopt)'
                )->execute($velden);
                $id = (int)db()->lastInsertId();
                flash('success', 'Jaargang ' . $jaar . ' is aangemaakt. '
                    . 'Koppel nu een bestand en importeer de e-mailadressen.');
            }
            header('Location: ' . $terugUrl);
            exit;
        } catch (PDOException $e) {
            $melding = $e->getMessage();
            if ($e->getCode() === '23000') {
                if (str_contains($melding, 'uniq_jaar')) {
                    flash('danger', 'Er bestaat al een jaargang voor ' . $jaar . '. '
                        . 'Bewerk die jaargang in plaats van een nieuwe aan te maken.');
                } elseif (str_contains($melding, 'uniq_slug')) {
                    flash('danger', 'Die slug is al in gebruik. Kies een andere.');
                } else {
                    flash('danger', 'Deze jaargang botst met een bestaande jaargang.');
                }
            } else {
                app_log('jaargang opslaan mislukt', ['fout' => $melding]);
                flash('danger', 'Opslaan is mislukt.');
            }
            $_SESSION['jaargang_formulier'] = [
                'id'              => (int)($_POST['id'] ?? 0),
                'jaar'            => $jaarInvoer,
                'titel'           => $titel,
                'slug'            => $slugInvoer,
                'omschrijving'    => $omschrijving,
                'gepubliceerd'    => $gepubliceerd,
                'zichtbaar_vanaf' => (string)($_POST['zichtbaar_vanaf'] ?? ''),
                'verloopt_op'     => (string)($_POST['verloopt_op'] ?? ''),
            ];
            header('Location: ' . $terugUrl . ((int)($_POST['id'] ?? 0) > 0 ? '?id=' . (int)$_POST['id'] : ''));
            exit;
        }
    }

    header('Location: ' . $terugUrl);
    exit;
}

// ─── Gegevens voor de weergave ───────────────────────────────────────────────
$bewerkId = (int)($_GET['id'] ?? 0);
$bewerken = null;
if ($bewerkId > 0) {
    try {
        $stmt = db()->prepare('SELECT * FROM jaargangen WHERE id = :id');
        $stmt->execute([':id' => $bewerkId]);
        $bewerken = $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        app_log('jaargang ophalen mislukt', ['fout' => $e->getMessage()]);
    }
    if ($bewerken === null) {
        flash('warning', 'Die jaargang is niet gevonden.');
        header('Location: ' . $terugUrl);
        exit;
    }
}

// Standaardwaarden voor het formulier.
$formulier = [
    'id'              => $bewerken ? (int)$bewerken['id'] : 0,
    'jaar'            => $bewerken ? (string)$bewerken['jaar'] : date('Y'),
    'titel'           => $bewerken ? (string)$bewerken['titel'] : '',
    'slug'            => $bewerken ? (string)$bewerken['slug'] : '',
    'omschrijving'    => $bewerken ? (string)($bewerken['omschrijving'] ?? '') : '',
    'gepubliceerd'    => $bewerken ? (int)$bewerken['gepubliceerd'] : 0,
    'zichtbaar_vanaf' => $bewerken ? jaargang_datum_voor_formulier($bewerken['zichtbaar_vanaf']) : '',
    'verloopt_op'     => $bewerken ? jaargang_datum_voor_formulier($bewerken['verloopt_op']) : '',
];

// Eerder ingevulde (afgekeurde) waarden hebben voorrang.
if (!empty($_SESSION['jaargang_formulier'])) {
    $bewaard = (array)$_SESSION['jaargang_formulier'];
    unset($_SESSION['jaargang_formulier']);
    if ((int)($bewaard['id'] ?? 0) === $formulier['id']) {
        $formulier = array_merge($formulier, $bewaard);
    }
}

$jaargangen = [];
try {
    $jaargangen = db()->query(
        'SELECT j.*,
                (SELECT COUNT(*) FROM jaargang_bestanden b WHERE b.jaargang_id = j.id) AS aantal_bestanden
           FROM jaargangen j
          ORDER BY j.jaar DESC, j.id DESC'
    )->fetchAll() ?: [];
} catch (Throwable $e) {
    app_log('jaargangen ophalen mislukt', ['fout' => $e->getMessage()]);
    flash('danger', 'De lijst met jaargangen kon niet worden geladen.');
}

admin_start('Jaargangen', 'Eén rij per jaar: titel, zichtbaarheidsperiode en publicatie.');
?>

<div class="row g-4">

    <!-- ─── Lijst ───────────────────────────────────────────────────────── -->
    <div class="col-xl-8">
        <div class="kaart">
            <div class="p-3 border-bottom">
                <h2 class="h6 mb-0"><i class="bi bi-list-ul me-1"></i>Alle jaargangen
                    <span class="text-muted fw-normal">(<?= count($jaargangen) ?>)</span>
                </h2>
            </div>

            <?php if (!$jaargangen): ?>
                <div class="p-5 text-center text-muted">
                    <i class="bi bi-calendar3 fs-1 d-block mb-2 opacity-50"></i>
                    Er is nog geen jaargang aangemaakt.<br>
                    Gebruik het formulier hiernaast om het eerste jaar toe te voegen.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table tabel-compact align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Jaar</th>
                                <th scope="col">Titel</th>
                                <th scope="col">Status</th>
                                <th scope="col" class="text-center">Bestanden</th>
                                <th scope="col" class="text-center">Deelnemers</th>
                                <th scope="col" class="text-end">Acties</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($jaargangen as $rij): ?>
                                <?php
                                [$statusTekst, $statusKleur] = jaargang_status($rij);
                                $rijId = (int)$rij['id'];
                                $aantalDeelnemers = jaargang_aantal_deelnemers($rijId);
                                ?>
                                <tr<?= $rijId === $formulier['id'] ? ' class="table-active"' : '' ?>>
                                    <td class="fw-semibold"><?= h((string)$rij['jaar']) ?></td>
                                    <td>
                                        <div><?= h((string)$rij['titel']) ?></div>
                                        <code class="pad"><?= h((string)$rij['slug']) ?></code>
                                    </td>
                                    <td>
                                        <span class="badge text-bg-<?= h($statusKleur) ?>"><?= h($statusTekst) ?></span>
                                        <?php if (!empty($rij['zichtbaar_vanaf']) || !empty($rij['verloopt_op'])): ?>
                                            <div class="text-muted small mt-1">
                                                <?= h(formatteer_datum($rij['zichtbaar_vanaf'])) ?>
                                                &ndash;
                                                <?= h(formatteer_datum($rij['verloopt_op'])) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center"><?= (int)$rij['aantal_bestanden'] ?></td>
                                    <td class="text-center"><?= $aantalDeelnemers ?></td>
                                    <td class="text-end text-nowrap">
                                        <div class="btn-group btn-group-sm">
                                            <a class="btn btn-outline-secondary" title="Bewerken"
                                                href="<?= h($terugUrl . '?id=' . $rijId) ?>">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <a class="btn btn-outline-secondary" title="Bestanden"
                                                href="<?= h(url('admin/bestanden.php?jaargang=' . $rijId)) ?>">
                                                <i class="bi bi-film"></i>
                                            </a>
                                            <a class="btn btn-outline-secondary" title="Toegang"
                                                href="<?= h(url('admin/toegang.php?jaargang=' . $rijId)) ?>">
                                                <i class="bi bi-person-check"></i>
                                            </a>
                                        </div>

                                        <form method="post" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="actie" value="publicatie">
                                            <input type="hidden" name="id" value="<?= $rijId ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary ms-1"
                                                title="<?= (int)$rij['gepubliceerd'] === 1 ? 'Depubliceren' : 'Publiceren' ?>">
                                                <i class="bi <?= (int)$rij['gepubliceerd'] === 1 ? 'bi-eye-slash' : 'bi-eye' ?>"></i>
                                            </button>
                                        </form>

                                        <form method="post" class="d-inline"
                                            <?= bevestig_attribuut("Jaargang " . (int)$rij['jaar'] . " verwijderen?\n\nAlle gekoppelde bestandsregels en toegangsrechten van deze jaargang verdwijnen mee.\nDe videobestanden zelf blijven gewoon in de opslagmap staan.\n\nDit kan niet ongedaan worden gemaakt.") ?>>
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="actie" value="verwijderen">
                                            <input type="hidden" name="id" value="<?= $rijId ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger ms-1"
                                                title="Verwijderen">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <p class="text-muted small mt-2 mb-0">
            <i class="bi bi-info-circle me-1"></i>
            Bij het verwijderen van een jaargang verdwijnen ook de gekoppelde bestandsregels en
            toegangsrechten (de database doet dat automatisch). Het videobestand zelf blijft op
            schijf staan; opruimen kan daarna onder Bestanden, bij de niet-gekoppelde bestanden.
        </p>
    </div>

    <!-- ─── Formulier ───────────────────────────────────────────────────── -->
    <div class="col-xl-4">
        <div class="kaart">
            <div class="p-3 border-bottom d-flex align-items-center justify-content-between">
                <h2 class="h6 mb-0">
                    <i class="bi <?= $formulier['id'] > 0 ? 'bi-pencil' : 'bi-plus-circle' ?> me-1"></i>
                    <?= $formulier['id'] > 0 ? 'Jaargang bewerken' : 'Nieuwe jaargang' ?>
                </h2>
                <?php if ($formulier['id'] > 0): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= h($terugUrl) ?>">
                        <i class="bi bi-x-lg me-1"></i>Annuleren
                    </a>
                <?php endif; ?>
            </div>

            <form method="post" class="p-3">
                <?= csrf_field() ?>
                <input type="hidden" name="actie" value="opslaan">
                <input type="hidden" name="id" value="<?= (int)$formulier['id'] ?>">

                <div class="mb-3">
                    <label class="form-label" for="jaar">Jaar <span class="text-danger">*</span></label>
                    <input type="number" class="form-control" id="jaar" name="jaar" required
                        min="1900" max="2200" step="1" value="<?= h((string)$formulier['jaar']) ?>">
                    <div class="form-text">Elk jaartal mag maar één keer voorkomen.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="titel">Titel <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="titel" name="titel" required maxlength="150"
                        value="<?= h((string)$formulier['titel']) ?>"
                        placeholder="Bijvoorbeeld: Musical 2025 — De Sterrenjacht">
                </div>

                <div class="mb-3">
                    <label class="form-label" for="slug">Slug</label>
                    <input type="text" class="form-control" id="slug" name="slug" maxlength="80"
                        value="<?= h((string)$formulier['slug']) ?>" placeholder="automatisch">
                    <div class="form-text">Laat leeg om deze automatisch af te leiden uit titel en jaar.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="omschrijving">Omschrijving</label>
                    <textarea class="form-control" id="omschrijving" name="omschrijving" rows="3"
                        placeholder="Optionele toelichting die de deelnemer in het portaal ziet."><?= h((string)$formulier['omschrijving']) ?></textarea>
                </div>

                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="gepubliceerd" name="gepubliceerd"
                        value="1" <?= (int)$formulier['gepubliceerd'] === 1 ? 'checked' : '' ?>>
                    <label class="form-check-label" for="gepubliceerd">Gepubliceerd</label>
                    <div class="form-text">Alleen gepubliceerde jaargangen zijn zichtbaar voor deelnemers.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="zichtbaar_vanaf">Zichtbaar vanaf</label>
                    <input type="datetime-local" class="form-control" id="zichtbaar_vanaf" name="zichtbaar_vanaf"
                        value="<?= h((string)$formulier['zichtbaar_vanaf']) ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label" for="verloopt_op">Verloopt op</label>
                    <input type="datetime-local" class="form-control" id="verloopt_op" name="verloopt_op"
                        value="<?= h((string)$formulier['verloopt_op']) ?>">
                    <div class="form-text">Beide datums mogen leeg blijven: dan geldt er geen beperking.</div>
                </div>

                <button type="submit" class="btn btn-djm w-100">
                    <i class="bi bi-check-lg me-1"></i>
                    <?= $formulier['id'] > 0 ? 'Wijzigingen opslaan' : 'Jaargang aanmaken' ?>
                </button>
            </form>
        </div>

        <?php if ($formulier['id'] > 0): ?>
            <div class="d-grid gap-2 mt-3">
                <a class="btn btn-outline-secondary"
                    href="<?= h(url('admin/bestanden.php?jaargang=' . (int)$formulier['id'])) ?>">
                    <i class="bi bi-film me-1"></i>Bestanden van deze jaargang
                </a>
                <a class="btn btn-outline-secondary"
                    href="<?= h(url('admin/toegang.php?jaargang=' . (int)$formulier['id'])) ?>">
                    <i class="bi bi-person-check me-1"></i>Toegang van deze jaargang
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
admin_eind();
