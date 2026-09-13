<?php

/**
 * Beheer — een wachtwoord kiezen via de link uit de e-mail.
 *
 * Voor een nieuwe beheerder (uitnodiging) en na "wachtwoord vergeten". De link
 * werkt één keer en verloopt; zie includes/beheerder_helper.php.
 *
 * Bij het openen gaat de code uit het adres meteen naar de sessie en stuurt de
 * pagina door naar zichzelf, zonder code. Zo blijft hij niet in de
 * browsergeschiedenis staan en gaat hij niet als verwijzer mee naar een
 * volgende pagina.
 */

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/beheerder_helper.php';
vereis_installatie();

if (isset($_GET['t'])) {
    $_SESSION['beheerder_link'] = (string)$_GET['t'];
    header('Location: ' . url('admin/wachtwoord_instellen.php'));
    exit;
}

$link = null;
try {
    $token = (string)($_SESSION['beheerder_link'] ?? '');
    $link  = $token !== '' ? beheerder_link_controleren($token) : null;
} catch (Throwable $e) {
    app_log('beheerderslink controleren mislukt', ['fout' => $e->getMessage()]);
}

$fouten = [];
$klaar  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    vereis_csrf();
    if ($link !== null) {
        $wachtwoord = (string)($_POST['wachtwoord'] ?? '');
        $herhaling  = (string)($_POST['wachtwoord_herhaling'] ?? '');
        $fouten     = beheerder_wachtwoord_fouten($wachtwoord, $herhaling, (string)$link['email']);

        if (!$fouten) {
            if (beheerder_wachtwoord_opslaan($link, $wachtwoord)) {
                unset($_SESSION['beheerder_link']);
                log_login('admin_wachtwoord', (string)$link['email'], true,
                    $link['doel'] === 'uitnodiging' ? 'gekozen via uitnodiging' : 'hersteld via resetlink');
                $klaar = true;
            } else {
                // Intussen al gebruikt, bijvoorbeeld in een ander tabblad.
                $link = null;
            }
        }
    }
}

admin_login_start($link !== null && $link['doel'] === 'uitnodiging' ? 'Wachtwoord kiezen' : 'Nieuw wachtwoord kiezen');
?>

<?php if ($klaar): ?>
    <div class="alert alert-success">
        <i class="bi bi-check-circle me-1"></i>Uw wachtwoord is opgeslagen.
    </div>
    <p class="text-muted small">
        Log nu in met <strong><?= h((string)$link['email']) ?></strong> en uw nieuwe wachtwoord.
    </p>
    <a class="btn btn-djm w-100" href="<?= h(url('admin/login.php')) ?>">
        <i class="bi bi-box-arrow-in-right me-1"></i>Naar inloggen
    </a>
<?php elseif ($link === null): ?>
    <div class="alert alert-warning">
        <i class="bi bi-link-45deg me-1"></i>Deze link werkt niet (meer).
    </div>
    <p class="text-muted small">
        Een link is maar een beperkte tijd geldig en werkt één keer. Is er daarna een nieuwe verstuurd, dan werkt
        alleen de nieuwste. Vraag hieronder een nieuwe aan, of vraag een andere beheerder om hem te sturen.
    </p>
    <a class="btn btn-djm w-100" href="<?= h(url('admin/wachtwoord_vergeten.php')) ?>">
        <i class="bi bi-send me-1"></i>Nieuwe link aanvragen
    </a>
<?php else: ?>
    <?php foreach ($fouten as $fout): ?>
        <div class="alert alert-danger py-2"><i class="bi bi-x-circle me-1"></i><?= h($fout) ?></div>
    <?php endforeach; ?>

    <p class="text-muted small">
        Kies een wachtwoord voor <strong><?= h((string)$link['naam']) ?></strong>
        (<?= h((string)$link['email']) ?>). Minimaal <?= (int)BEHEERDER_WACHTWOORD_MINIMUM ?> tekens; een zin van een
        paar woorden werkt goed.
    </p>

    <form method="post">
        <?= csrf_field() ?>
        <!-- Voor wachtwoordbeheerders: zo slaan ze het wachtwoord onder het juiste account op. -->
        <input type="email" class="d-none" name="gebruikersnaam" value="<?= h((string)$link['email']) ?>"
            autocomplete="username" readonly tabindex="-1">

        <div class="mb-3">
            <label class="form-label" for="wachtwoord">Nieuw wachtwoord</label>
            <input type="password" class="form-control" id="wachtwoord" name="wachtwoord" required autofocus
                minlength="<?= (int)BEHEERDER_WACHTWOORD_MINIMUM ?>" autocomplete="new-password">
        </div>
        <div class="mb-3">
            <label class="form-label" for="wachtwoord_herhaling">Nog een keer</label>
            <input type="password" class="form-control" id="wachtwoord_herhaling" name="wachtwoord_herhaling" required
                minlength="<?= (int)BEHEERDER_WACHTWOORD_MINIMUM ?>" autocomplete="new-password">
        </div>
        <button type="submit" class="btn btn-djm w-100">
            <i class="bi bi-check2 me-1"></i>Wachtwoord opslaan
        </button>
    </form>
<?php endif; ?>

<hr class="my-4">
<div class="text-center small">
    <a class="text-decoration-none" href="<?= h(url('admin/login.php')) ?>">
        <i class="bi bi-arrow-left me-1"></i>Terug naar inloggen
    </a>
</div>

<?php
admin_login_eind();
