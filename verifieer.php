<?php

/**
 * Stap 2 van het inloggen: de eenmalige code uit de e-mail invoeren.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/toegang_helper.php';
require_once __DIR__ . '/includes/otp.php';
require_once __DIR__ . '/includes/email_helper.php';
require_once __DIR__ . '/includes/layout.php';

vereis_installatie();

if (deelnemer_ingelogd()) {
    header('Location: ' . url('portaal/index.php'));
    exit;
}

// Zonder aanvraag in deze sessie is er niets te verifiëren.
$email = (string)($_SESSION['otp_email'] ?? '');
if ($email === '') {
    header('Location: ' . url('index.php'));
    exit;
}

/**
 * Maskeert het lokale deel van een e-mailadres: jan.jansen@example.nl wordt
 * j••••n@example.nl. Het aantal bolletjes is vast, zodat de lengte van het
 * adres niet af te lezen is.
 */
function masker_email(string $email): string
{
    $apenstaartje = strrpos($email, '@');
    if ($apenstaartje === false || $apenstaartje === 0) {
        return '••••';
    }

    $lokaal = substr($email, 0, $apenstaartje);
    $domein = substr($email, $apenstaartje);
    $bolletjes = str_repeat('•', 4);

    if (mb_strlen($lokaal) <= 1) {
        return $bolletjes . $domein;
    }

    return mb_substr($lokaal, 0, 1) . $bolletjes . mb_substr($lokaal, -1) . $domein;
}

$onthouden      = instelling_bool('remember_toestaan', true);
$fout           = '';
$onthoudGekozen = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    vereis_csrf();

    $code           = (string)($_POST['code'] ?? '');
    $onthoudGekozen = $onthouden && !empty($_POST['onthouden']);

    $fouten    = [];
    $deelnemer = otp_verifieren($email, $code, $fouten);

    if ($deelnemer !== null) {
        deelnemer_inloggen($deelnemer);
        if ($onthoudGekozen) {
            onthoud_dit_apparaat((int)$deelnemer['id']);
        }
        log_login('code_ok', $email, true);

        header('Location: ' . url('portaal/index.php'));
        exit;
    }

    $fout = $fouten[0] ?? 'De code kon niet worden gecontroleerd. Vraag een nieuwe code aan.';
}

pagina_start('Inlogcode invoeren', ['smal' => true]);
toon_fout($fout);
?>

<p class="text-secondary small">
    We hebben een eenmalige code gestuurd naar
    <strong class="text-body"><?= h(masker_email($email)) ?></strong>.
    De code bestaat uit zes cijfers en is
    <?= (int)max(1, instelling_int('otp_geldigheid_minuten', 10)) ?> minuten geldig.
</p>

<form method="post" action="<?= h(url('verifieer.php')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label for="code" class="form-label">Inlogcode</label>
        <input type="text"
               class="form-control djm-code-invoer"
               id="code"
               name="code"
               inputmode="numeric"
               pattern="[0-9]*"
               maxlength="6"
               autocomplete="one-time-code"
               spellcheck="false"
               autofocus
               required>
    </div>

    <?php if ($onthouden): ?>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" value="1" id="onthouden" name="onthouden"
                   <?= $onthoudGekozen ? 'checked' : '' ?>>
            <label class="form-check-label small" for="onthouden">
                Onthoud dit apparaat
                <span class="text-secondary d-block">Doe dit niet op een gedeelde computer.</span>
            </label>
        </div>
    <?php endif; ?>

    <button type="submit" class="btn btn-djm btn-lg w-100">
        <i class="bi bi-box-arrow-in-right me-1"></i> Inloggen
    </button>
</form>

<div class="text-center mt-4 pt-3 border-top">
    <a href="<?= h(url('index.php')) ?>" class="link-secondary small text-decoration-none">
        <i class="bi bi-arrow-repeat me-1"></i>Nieuwe code aanvragen
    </a>
</div>
<?php
pagina_eind();
