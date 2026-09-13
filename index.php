<?php

/**
 * Stap 1 van het inloggen: e-mailadres invoeren en een eenmalige code aanvragen.
 *
 * Deze pagina toont altijd dezelfde bevestiging, ongeacht of het adres bekend is.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/toegang_helper.php';
require_once __DIR__ . '/includes/otp.php';
require_once __DIR__ . '/includes/email_helper.php';
require_once __DIR__ . '/includes/layout.php';

vereis_installatie();

// Al ingelogd (of herkend aan het onthoud-cookie): meteen door naar het portaal.
if (herstel_uit_remember_cookie() || deelnemer_ingelogd()) {
    header('Location: ' . url('portaal/index.php'));
    exit;
}

$portaalOpen = instelling_bool('portaal_ingeschakeld', true);
$email       = '';
$fout        = '';
$melding     = '';
$meldingType = 'info';

// logout.php stuurt hierheen met ?uitgelogd=1: een flash zou het vernietigen
// van de sessie niet overleven, dus gaat de melding via de querystring.
if (isset($_GET['uitgelogd'])) {
    $melding     = 'U bent uitgelogd.';
    $meldingType = 'success';
} elseif (($_GET['reden'] ?? '') === 'sessie') {
    $melding     = 'Uw sessie is verlopen. Log opnieuw in.';
    $meldingType = 'warning';
}

if ($portaalOpen && $_SERVER['REQUEST_METHOD'] === 'POST') {
    vereis_csrf();

    $email   = trim((string)($_POST['email'] ?? ''));
    $fouten  = [];
    $uitkomst = otp_aanvragen($email, $fouten);

    switch ($uitkomst['status']) {
        case 'verstuurd':
            // Post/Redirect/Get: doorsturen naar het invoerscherm voor de code.
            header('Location: ' . url('verifieer.php'));
            exit;

        case 'ongeldig':
            $fout = 'Vul een geldig e-mailadres in.';
            break;

        case 'limiet':
            $fout = 'Er zijn te veel codes aangevraagd. Probeer het over 15 minuten opnieuw.';
            break;

        case 'mailfout':
        default:
            $fout = 'Het versturen van de code is mislukt. Probeer het later opnieuw of neem contact op.';
            break;
    }
}

portaal_start('Inloggen', [
    'smal'  => true,
    'intro' => $portaalOpen ? instelling(
        'portaal_welkomst',
        'Vul uw e-mailadres in. U ontvangt een eenmalige inlogcode waarmee u de videoregistratie van de musical kunt downloaden.'
    ) : '',
]);

if ($melding !== '') {
    toon_melding($melding, $meldingType);
}

if (!$portaalOpen): ?>
    <div class="text-center py-2">
        <i class="bi bi-lock fs-1 text-secondary"></i>
        <h1 class="h5 mt-3">Het portaal is tijdelijk gesloten</h1>
        <p class="text-secondary small mb-0">
            Op dit moment is inloggen niet mogelijk. Probeer het later opnieuw.
        </p>
    </div>
<?php else:
    toon_fout($fout);
    ?>
    <form method="post" action="<?= h(url('index.php')) ?>" novalidate>
        <?= csrf_field() ?>
        <div class="mb-3">
            <label for="email" class="form-label">E-mailadres</label>
            <input type="email"
                   class="form-control form-control-lg"
                   id="email"
                   name="email"
                   value="<?= h($email) ?>"
                   inputmode="email"
                   autocomplete="email"
                   maxlength="190"
                   autofocus
                   required>
        </div>
        <button type="submit" class="btn btn-djm btn-lg w-100">
            <i class="bi bi-envelope-check me-1"></i> Stuur mij een inlogcode
        </button>
    </form>

    <p class="text-secondary small mt-4 mb-0">
        U ontvangt alleen een code als uw adres bij ons bekend is.
        Controleer ook uw map met ongewenste e-mail.
    </p>
<?php endif; ?>

<div class="mt-4 pt-3 border-top">
    <?php toon_contact(); ?>
    <div class="text-center mt-3">
        <a href="<?= h(url('admin/login.php')) ?>" class="link-secondary small text-decoration-none">Beheer</a>
    </div>
</div>
<?php
portaal_eind();
