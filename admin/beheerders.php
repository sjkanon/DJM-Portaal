<?php

/**
 * Beheer > Beheerders — wie er in het beheer mag.
 *
 * Een nieuwe beheerder krijgt een uitnodiging per e-mail en kiest daarin zelf
 * een wachtwoord; niemand anders ziet of kent het. Verder: een nieuwe link
 * sturen (wachtwoord vergeten, of een verlopen uitnodiging) en accounts uit- en
 * weer inschakelen. Verwijderen kan bewust niet, zodat het logboek te herleiden
 * blijft. Alle beheerders hebben dezelfde rechten.
 */

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/beheerder_helper.php';

vereis_installatie();
$beheerder = vereis_beheerder();
$eigenId   = (int)$beheerder['id'];
$door      = (string)$beheerder['naam'];

function beheerders_ophalen(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM beheerders WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function beheerders_terug(): void
{
    header('Location: ' . url('admin/beheerders.php'));
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
//  Verwerking (POST)
// ═══════════════════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    vereis_csrf();
    $actie = (string)($_POST['actie'] ?? '');

    // ─── Toevoegen en uitnodigen ───────────────────────────────────────────
    if ($actie === 'toevoegen') {
        $naam  = trim((string)($_POST['naam'] ?? ''));
        $email = normaliseer_email((string)($_POST['email'] ?? ''));

        $fout = '';
        if ($naam === '') {
            $fout = 'Vul een naam in.';
        } elseif (!geldig_email($email)) {
            $fout = 'Vul een geldig e-mailadres in.';
        } else {
            $bestaat = db()->prepare('SELECT COUNT(*) FROM beheerders WHERE email = :e');
            $bestaat->execute([':e' => $email]);
            if ((int)$bestaat->fetchColumn() > 0) {
                $fout = 'Er is al een beheerder met dit e-mailadres. Staat het account uit, schakel het dan in de lijst weer in.';
            }
        }
        if ($fout !== '') {
            flash('danger', $fout);
            // Zodat niemand de velden opnieuw hoeft in te typen.
            $_SESSION['beheerders_formulier'] = ['naam' => $naam, 'email' => $email];
            beheerders_terug();
        }

        // Een willekeurig wachtwoord dat niemand kent: tot de uitnodiging is
        // gebruikt, kan er niemand op dit account inloggen.
        db()->prepare('INSERT INTO beheerders (naam, email, wachtwoord_hash, rol, actief)
                       VALUES (:n, :e, :h, :r, 1)')
            ->execute([
                ':n' => mb_substr($naam, 0, 150),
                ':e' => $email,
                ':h' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                ':r' => 'beheerder',
            ]);
        $nieuw = beheerders_ophalen((int)db()->lastInsertId());
        log_login('admin_toegevoegd', $email, true, 'door ' . $door);

        $fouten = [];
        if ($nieuw !== null && beheerder_link_versturen($nieuw, 'uitnodiging', $door, $fouten)) {
            flash('success', sprintf(
                '%s is toegevoegd. De uitnodiging is verstuurd naar %s; de link daarin is %s geldig.',
                $naam,
                $email,
                beheerder_link_geldigheid('uitnodiging')
            ));
        } else {
            flash('warning', $naam . ' is toegevoegd, maar de uitnodiging kon niet worden verstuurd'
                . ($fouten ? ' (' . $fouten[0] . ')' : '')
                . '. Controleer Instellingen › E-mail en klik daarna bij deze beheerder op "Uitnodiging opnieuw".');
        }
        beheerders_terug();
    }

    $doelId = (int)($_POST['beheerder_id'] ?? 0);
    $doel   = $doelId > 0 ? beheerders_ophalen($doelId) : null;
    if ($doel === null) {
        flash('danger', 'Beheerder niet gevonden.');
        beheerders_terug();
    }
    $doelNaam = (string)$doel['naam'];

    // ─── Link sturen: uitnodiging opnieuw, of een resetlink ────────────────
    if ($actie === 'link_versturen') {
        if ((int)$doel['actief'] !== 1) {
            flash('warning', 'Schakel het account van ' . $doelNaam . ' eerst weer in.');
            beheerders_terug();
        }
        // Wie nog nooit heeft ingelogd, krijgt opnieuw de ruimere uitnodiging.
        $soort  = $doel['laatst_ingelogd_op'] === null ? 'uitnodiging' : 'reset';
        $fouten = [];
        $gelukt = beheerder_link_versturen($doel, $soort, $door, $fouten);
        log_login('admin_link', (string)$doel['email'], $gelukt, $soort . ' door ' . $door);

        if ($gelukt) {
            flash('success', sprintf(
                'Er is een e-mail verstuurd naar %s met een link om een wachtwoord te kiezen, %s geldig. '
                    . 'Een eerder verstuurde link werkt niet meer.',
                (string)$doel['email'],
                beheerder_link_geldigheid($soort)
            ));
        } else {
            flash('danger', 'De e-mail kon niet worden verstuurd'
                . ($fouten ? ' (' . $fouten[0] . ')' : '')
                . '. Controleer Instellingen › E-mail.');
        }
        beheerders_terug();
    }

    // ─── Uitschakelen ──────────────────────────────────────────────────────
    if ($actie === 'uitschakelen') {
        // Wie dit doet, is zelf een actieve beheerder. Zolang niemand zijn eigen
        // account kan uitschakelen, blijft er dus altijd minstens één over.
        if ($doelId === $eigenId) {
            flash('warning', 'U kunt uw eigen account niet uitschakelen. Vraag dat aan een andere beheerder.');
            beheerders_terug();
        }
        db()->prepare('UPDATE beheerders SET actief = 0 WHERE id = :id')->execute([':id' => $doelId]);
        beheerder_links_intrekken($doelId);
        log_login('admin_uitgeschakeld', (string)$doel['email'], true, 'door ' . $door);
        flash('success', $doelNaam . ' is uitgeschakeld en kan niet meer inloggen, ook niet met een eerder verstuurde link.');
        beheerders_terug();
    }

    // ─── Weer inschakelen ──────────────────────────────────────────────────
    if ($actie === 'inschakelen') {
        db()->prepare('UPDATE beheerders SET actief = 1 WHERE id = :id')->execute([':id' => $doelId]);
        log_login('admin_ingeschakeld', (string)$doel['email'], true, 'door ' . $door);
        flash('success', $doelNaam . ' kan weer inloggen met het eigen wachtwoord. '
            . 'Weet die persoon het niet meer, stuur dan een resetlink.');
        beheerders_terug();
    }

    beheerders_terug();
}

// ═══════════════════════════════════════════════════════════════════════════
//  Weergave
// ═══════════════════════════════════════════════════════════════════════════

$rijen = db()->query(
    'SELECT id, naam, email, actief, laatst_ingelogd_op
       FROM beheerders
      ORDER BY actief DESC, naam ASC'
)->fetchAll();
$openLinks    = beheerder_open_links();
$aantalActief = count(array_filter($rijen, static fn(array $r): bool => (int)$r['actief'] === 1));

$formulier = $_SESSION['beheerders_formulier'] ?? [];
unset($_SESSION['beheerders_formulier']);
$formNaam  = (string)($formulier['naam'] ?? '');
$formEmail = (string)($formulier['email'] ?? '');

admin_start('Beheerders', $aantalActief . ' actieve beheerder(s), allemaal met dezelfde rechten');
?>

<div class="row g-4">
    <div class="col-xl-8">
        <!-- Een lijst in plaats van een tabel: op een telefoon komen de knoppen dan
             onder de naam te staan, in plaats van buiten beeld te vallen. -->
        <div class="kaart">
            <ul class="list-group list-group-flush rounded-3">
                <?php foreach ($rijen as $rij):
                    $id     = (int)$rij['id'];
                    $actief = (int)$rij['actief'] === 1;
                    $nooit  = $rij['laatst_ingelogd_op'] === null;
                    $open   = $openLinks[$id] ?? null;
                    ?>
                    <li class="list-group-item d-flex flex-wrap align-items-center gap-3 px-4 py-3">
                        <div class="flex-grow-1 min-w-0<?= $actief ? '' : ' opacity-75' ?>">
                            <div class="fw-semibold text-break">
                                <?= h((string)$rij['naam']) ?>
                                <?php if ($id === $eigenId): ?>
                                    <span class="badge text-bg-light border ms-1">u</span>
                                <?php endif; ?>
                                <?php if ($actief): ?>
                                    <span class="badge text-bg-success ms-1">actief</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary ms-1">uitgeschakeld</span>
                                <?php endif; ?>
                            </div>
                            <div class="small text-muted text-break"><?= h((string)$rij['email']) ?></div>
                            <div class="small text-muted mt-1">
                                Laatst ingelogd: <?= $nooit ? 'nog nooit' : h(formatteer_datum((string)$rij['laatst_ingelogd_op'])) ?>
                                <?php if ($open !== null): ?>
                                    &middot; <?= $open['doel'] === 'uitnodiging' ? 'uitnodiging' : 'resetlink' ?> geldig tot
                                    <?= h(formatteer_datum((string)$open['verloopt_op'])) ?>
                                <?php elseif ($actief && $nooit): ?>
                                    &middot; uitnodiging niet gebruikt
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="d-flex flex-wrap gap-1">
                            <?php if ($actief): ?>
                                <form method="post" class="d-inline"<?= bevestig_attribuut(
                                    'Een e-mail sturen naar ' . $rij['email'] . " met een link om een wachtwoord te kiezen?\n\n"
                                    . 'Een eerder verstuurde link werkt daarna niet meer.'
                                    . ($nooit ? '' : ' Het huidige wachtwoord blijft werken tot er een nieuw is gekozen.')
                                ) ?>>
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="actie" value="link_versturen">
                                    <input type="hidden" name="beheerder_id" value="<?= (int)$id ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary text-nowrap">
                                        <i class="bi bi-envelope me-1"></i><?= $nooit ? 'Uitnodiging opnieuw' : 'Resetlink sturen' ?>
                                    </button>
                                </form>
                                <?php if ($id !== $eigenId): ?>
                                    <form method="post" class="d-inline"<?= bevestig_attribuut(
                                        $rij['naam'] . " uitschakelen?\n\n"
                                        . 'Die persoon kan dan niet meer inloggen. Is die nu ingelogd, dan stopt dat bij de '
                                        . 'volgende klik. U kunt het account later weer inschakelen.'
                                    ) ?>>
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="actie" value="uitschakelen">
                                        <input type="hidden" name="beheerder_id" value="<?= (int)$id ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger text-nowrap">
                                            <i class="bi bi-person-slash me-1"></i>Uitschakelen
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php else: ?>
                                <form method="post" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="actie" value="inschakelen">
                                    <input type="hidden" name="beheerder_id" value="<?= (int)$id ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-success text-nowrap">
                                        <i class="bi bi-person-check me-1"></i>Inschakelen
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="kaart p-4 mb-4">
            <h2 class="h6 mb-3"><i class="bi bi-person-plus me-1"></i>Beheerder toevoegen</h2>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="actie" value="toevoegen">
                <div class="mb-3">
                    <label class="form-label" for="naam">Naam</label>
                    <input type="text" class="form-control" id="naam" name="naam" required maxlength="150"
                        value="<?= h($formNaam) ?>" autocomplete="off">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="email">E-mailadres</label>
                    <input type="email" class="form-control" id="email" name="email" required maxlength="190"
                        value="<?= h($formEmail) ?>" autocomplete="off">
                </div>
                <button type="submit" class="btn btn-djm w-100">
                    <i class="bi bi-send me-1"></i>Toevoegen en uitnodigen
                </button>
            </form>
            <p class="small text-muted mt-3 mb-0">
                De nieuwe beheerder krijgt een e-mail en kiest daarin zelf een wachtwoord. De link is
                <?= h(beheerder_link_geldigheid('uitnodiging')) ?> geldig. U ziet of kent het wachtwoord dus nooit.
            </p>
        </div>

        <div class="kaart p-4 small text-muted">
            <h2 class="h6 text-body mb-2"><i class="bi bi-info-circle me-1"></i>Goed om te weten</h2>
            <ul class="ps-3 mb-0">
                <li class="mb-1">Een <strong>resetlink</strong> gaat altijd naar het adres van de beheerder zelf en is
                    <?= h(beheerder_link_geldigheid('reset')) ?> geldig. Wie zijn wachtwoord kwijt is, kan hem ook zelf
                    aanvragen via <em>Wachtwoord vergeten?</em> op de inlogpagina.</li>
                <li class="mb-1"><strong>Uitschakelen</strong> werkt direct. Uw eigen account kunt u niet uitschakelen,
                    zodat er altijd iemand overblijft.</li>
                <li><strong>Verwijderen</strong> kan niet: zo blijft te zien wie wat heeft gedaan. Elke handeling hier
                    staat in het <a href="<?= h(url('admin/logboek.php?tab=logins')) ?>">inloglogboek</a>.</li>
            </ul>
        </div>
    </div>
</div>

<?php admin_eind(); ?>
