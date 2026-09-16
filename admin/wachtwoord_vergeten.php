<?php

/**
 * Beheer — wachtwoord vergeten.
 *
 * Stuurt een link waarmee een beheerder zelf een nieuw wachtwoord kiest. De
 * pagina antwoordt altijd hetzelfde, of het adres nu bij een beheerder hoort of
 * niet: anders is hier na te gaan wie er beheerder is.
 */

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/beheerder_helper.php';
vereis_installatie();

const RESET_MAX_PER_IP      = 5;
const RESET_MAX_PER_ADRES   = 3;
const RESET_VENSTER_MINUTEN = 60;

/**
 * Minimale looptijd van een aanvraag, in seconden. Een mail versturen kost
 * tijd; zonder deze drempel verraadt een traag antwoord dat het adres bestaat.
 * Duurt het versturen zelf langer, dan valt het verschil weg in de spreiding
 * van de mailserver.
 */
const RESET_MIN_LOOPTIJD = 2.5;

/** Verstuurt de link als het adres bij een actieve beheerder hoort. */
function reset_aanvragen(string $email): void
{
    try {
        $stmt = db()->prepare('SELECT * FROM beheerders WHERE email = :e AND actief = 1 LIMIT 1');
        $stmt->execute([':e' => $email]);
        $rij = $stmt->fetch();
        if (!$rij) {
            log_login('admin_reset', $email, false, 'geen actieve beheerder');
            return;
        }
        // Zonder vast webadres zou de link in de mail kunnen wijzen naar een
        // host die de aanvrager zelf kiest; zie beheerder_link_basis_vast().
        if (!beheerder_link_basis_vast()) {
            log_login('admin_reset', $email, false, 'niet verstuurd: APP_URL ontbreekt');
            app_log('resetlink niet verstuurd: zet APP_URL in .env', ['email' => $email]);
            return;
        }
        $fouten = [];
        $gelukt = beheerder_link_versturen($rij, 'reset', '', $fouten);
        log_login('admin_reset', $email, $gelukt, $gelukt ? 'link verstuurd' : 'mail mislukt');
    } catch (Throwable $e) {
        app_log('resetlink aanvragen mislukt', ['fout' => $e->getMessage()]);
    }
}

$email     = '';
$fout      = '';
$verstuurd = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    vereis_csrf();
    $start     = microtime(true);
    $email     = normaliseer_email((string)($_POST['email'] ?? ''));
    $ipSleutel = limiet_sleutel('adminreset-ip', client_ip());

    if (!geldig_email($email)) {
        $fout = 'Vul een geldig e-mailadres in.';
    } elseif (admin_limiet_teller($ipSleutel, RESET_VENSTER_MINUTEN) >= RESET_MAX_PER_IP) {
        $fout = 'Er zijn vanaf dit IP-adres te veel resetlinks aangevraagd. Probeer het over een uur opnieuw.';
        log_login('admin_reset', $email, false, 'geblokkeerd door ratelimiet');
    } else {
        admin_limiet_ophogen($ipSleutel, RESET_VENSTER_MINUTEN);
        $adresSleutel = admin_email_sleutel('adminreset', $email);
        // Te vaak voor één adres: stil niets versturen. Een eigen melding zou
        // hier verraden dat er met dit adres iets bijzonders is.
        if (admin_limiet_teller($adresSleutel, RESET_VENSTER_MINUTEN) < RESET_MAX_PER_ADRES) {
            admin_limiet_ophogen($adresSleutel, RESET_VENSTER_MINUTEN);
            reset_aanvragen($email);
        } else {
            log_login('admin_reset', $email, false, 'te vaak aangevraagd voor dit adres');
        }
        $verstuurd = true;
    }

    $rest = RESET_MIN_LOOPTIJD - (microtime(true) - $start);
    if ($rest > 0) {
        usleep((int)($rest * 1000000));
    }
}

admin_login_start('Wachtwoord vergeten');
?>

<?php if ($verstuurd): ?>
    <div class="alert alert-success">
        <i class="bi bi-envelope-check me-1"></i>
        Hoort <strong><?= h($email) ?></strong> bij een actieve beheerder, dan is er zojuist een e-mail verstuurd
        met een link om een nieuw wachtwoord te kiezen.
    </div>
    <p class="text-muted small mb-0">
        De link is <?= h(beheerder_link_geldigheid('reset')) ?> geldig en werkt één keer. Geen e-mail ontvangen?
        Kijk ook bij ongewenste e-mail, of vraag een andere beheerder om u via Beheer › Beheerders een link te sturen.
    </p>
<?php else: ?>
    <?php if ($fout !== ''): ?>
        <div class="alert alert-danger py-2"><i class="bi bi-x-circle me-1"></i><?= h($fout) ?></div>
    <?php endif; ?>

    <p class="text-muted small">
        Vul het e-mailadres van uw beheerdersaccount in. U ontvangt een link waarmee u zelf een nieuw wachtwoord kiest.
    </p>

    <form method="post">
        <?= csrf_field() ?>
        <div class="mb-3">
            <label class="form-label" for="email">E-mailadres</label>
            <input type="email" class="form-control" id="email" name="email" required autofocus
                value="<?= h($email) ?>" autocomplete="username" maxlength="190">
        </div>
        <button type="submit" class="btn btn-djm w-100">
            <i class="bi bi-send me-1"></i>Stuur mij een resetlink
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
