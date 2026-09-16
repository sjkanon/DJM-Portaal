<?php

/**
 * Beheerdersinlog — e-mailadres + wachtwoord tegen de tabel `beheerders`.
 *
 * Bevat een brute-force rem per IP-adres en per account via de tabel
 * `aanvraag_limiet`. De functies daarvoor staan in includes/beheerder_helper.php,
 * los van de OTP-helpers van het portaal: het beheer mag daar niet van afhangen.
 */

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/beheerder_helper.php';
vereis_installatie();

// ─── Instellingen van de brute-force rem ─────────────────────────────────────
const ADMIN_LOGIN_MAX_POGINGEN   = 10;
const ADMIN_LOGIN_VENSTER_MINUTEN = 15;

/**
 * Hash van een wachtwoord dat niemand heeft, om tegen te controleren als het
 * e-mailadres niet bestaat. Zo kost een mislukte poging op een onbekend adres
 * evenveel tijd als op een bestaand adres; zonder dit verraadt de responstijd
 * welke adressen beheerder zijn.
 *
 * De kosten (`$2y$10$`) horen gelijk te zijn aan die van PASSWORD_DEFAULT, want
 * daarmee zijn de echte hashes gemaakt. Wijzigt PHP die standaard, vervang deze
 * waarde dan door de uitvoer van:
 *     php -r 'echo password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);'
 */
const ADMIN_LOGIN_DUMMY_HASH = '$2y$10$/YnHO/yUCv420CzbsUR70ubt0vXUtW3NO1VgmnNSqAzzapf5B5/5u';

/**
 * Controleert de `terug`-parameter. Alleen een pad binnen dit project is
 * toegestaan: geen absolute URL, geen protocol-relatieve URL (`//host`) en
 * geen `..`. Geeft de veilige doel-URL terug of null.
 */
function admin_veilige_terugweg(string $terug): ?string
{
    $terug = trim($terug);
    if ($terug === '' || strlen($terug) > 512) {
        return null;
    }
    if (str_contains($terug, "\0") || str_contains($terug, "\n") || str_contains($terug, "\r")) {
        return null;
    }
    if (str_contains($terug, '..') || str_contains($terug, '\\')) {
        return null;
    }
    // Een schema ("http:", "javascript:") is nooit toegestaan.
    if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $terug)) {
        return null;
    }
    // Protocol-relatief ("//kwaadaardig.nl/…") is nooit toegestaan.
    if (str_starts_with($terug, '//')) {
        return null;
    }

    if ($terug[0] === '/') {
        // Absoluut pad: moet binnen de basismap van deze installatie vallen.
        $basisPad = rtrim((string)parse_url(app_base_url(), PHP_URL_PATH), '/');
        if ($basisPad !== '' && !str_starts_with($terug, $basisPad . '/')) {
            return null;
        }
        return $terug;
    }

    // Relatief pad: hangen we onder de basis-URL van de applicatie.
    return url($terug);
}

// ─── Al ingelogd? ────────────────────────────────────────────────────────────
if (huidige_beheerder() !== null) {
    header('Location: ' . url('admin/index.php'));
    exit;
}

// ─── Zijn er al beheerders? ──────────────────────────────────────────────────
$aantalBeheerders = 0;
$databaseOk       = true;
try {
    $aantalBeheerders = (int)db()->query('SELECT COUNT(*) FROM beheerders')->fetchColumn();
} catch (Throwable $e) {
    $databaseOk = false;
    app_log('beheerders tellen mislukt', ['fout' => $e->getMessage()]);
}

$limietSleutel = limiet_sleutel('admin', client_ip());
$teVaak        = admin_limiet_teller($limietSleutel, ADMIN_LOGIN_VENSTER_MINUTEN) >= ADMIN_LOGIN_MAX_POGINGEN;

$terugParameter = (string)($_POST['terug'] ?? $_GET['terug'] ?? '');
$fout           = '';
$email          = '';

// ─── Verwerking ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $databaseOk && $aantalBeheerders > 0) {
    vereis_csrf();

    $email      = normaliseer_email((string)($_POST['email'] ?? ''));
    $wachtwoord = (string)($_POST['wachtwoord'] ?? '');

    $accountSleutel = $email !== '' ? admin_account_sleutel($email) : '';
    $accountTeVaak  = $accountSleutel !== ''
        && admin_limiet_teller($accountSleutel, ADMIN_LOGIN_VENSTER_MINUTEN) >= ADMIN_LOGIN_MAX_POGINGEN;

    if ($teVaak || $accountTeVaak) {
        $fout = $teVaak
            ? 'Er is te vaak achter elkaar geprobeerd in te loggen vanaf dit IP-adres. '
                . 'Wacht ' . ADMIN_LOGIN_VENSTER_MINUTEN . ' minuten en probeer het opnieuw.'
            : 'Er is te vaak achter elkaar geprobeerd op dit account in te loggen. '
                . 'Wacht ' . ADMIN_LOGIN_VENSTER_MINUTEN . ' minuten en probeer het opnieuw.';
        log_login('admin_fout', $email !== '' ? $email : null, false, 'geblokkeerd door ratelimiet');
    } elseif ($email === '' || $wachtwoord === '') {
        $fout = 'Vul uw e-mailadres en wachtwoord in.';
    } else {
        $rij = null;
        try {
            $stmt = db()->prepare('SELECT * FROM beheerders WHERE email = :e LIMIT 1');
            $stmt->execute([':e' => $email]);
            $rij = $stmt->fetch() ?: null;
        } catch (Throwable $e) {
            app_log('beheerder ophalen mislukt', ['fout' => $e->getMessage()]);
        }

        $hash = (string)($rij['wachtwoord_hash'] ?? '');
        if ($hash === '') {
            // Onbekend account: tóch een hash controleren, zodat het antwoord
            // net zo lang op zich laat wachten als bij een bestaand account.
            // Zonder dit verraadt de responstijd welke adressen beheerder zijn.
            $hash = ADMIN_LOGIN_DUMMY_HASH;
        }
        $wachtwoordKlopt = password_verify($wachtwoord, $hash);
        $ok = $rij !== null && (int)$rij['actief'] === 1
            && (string)($rij['wachtwoord_hash'] ?? '') !== '' && $wachtwoordKlopt;

        if (!$ok) {
            // Altijd dezelfde neutrale melding: geen user enumeration.
            admin_limiet_ophogen($limietSleutel, ADMIN_LOGIN_VENSTER_MINUTEN);
            admin_limiet_ophogen($accountSleutel, ADMIN_LOGIN_VENSTER_MINUTEN);
            $fout = 'Onjuiste inloggegevens.';
            log_login('admin_fout', $email, false);
        } else {
            if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                try {
                    $nieuweHash = password_hash($wachtwoord, PASSWORD_DEFAULT);
                    db()->prepare('UPDATE beheerders SET wachtwoord_hash = :h WHERE id = :id')
                        ->execute([':h' => $nieuweHash, ':id' => (int)$rij['id']]);
                    // De sessie krijgt een vingerafdruk van de hash mee (zie
                    // beheerder_stempel()). Geven we hier de oude mee, dan wijkt die
                    // bij het volgende verzoek af van wat er in de database staat en
                    // vliegt deze beheerder er meteen weer uit.
                    $rij['wachtwoord_hash'] = $nieuweHash;
                } catch (Throwable $e) {
                    app_log('wachtwoordhash verversen mislukt', ['fout' => $e->getMessage()]);
                }
            }

            admin_limiet_wissen($limietSleutel);
            admin_limiet_wissen($accountSleutel);
            beheerder_inloggen($rij);
            log_login('admin_ok', $email, true);

            $doel = admin_veilige_terugweg($terugParameter) ?? url('admin/index.php');
            header('Location: ' . $doel);
            exit;
        }
    }
}

// ─── Weergave ────────────────────────────────────────────────────────────────
admin_login_start('Beheer ' . portaal_naam());
?>

<?php if (!$databaseOk): ?>
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-triangle me-1"></i>
        De database is niet bereikbaar. Controleer de instellingen in <code>.env</code>.
    </div>
<?php elseif ($aantalBeheerders === 0): ?>
    <div class="alert alert-warning">
        <i class="bi bi-person-plus me-1"></i>
        Er is nog geen beheerder aangemaakt.
    </div>
    <p class="text-muted small">
        Rond eerst de installatie af. Daar maakt u de eerste beheerder aan.
    </p>
    <a class="btn btn-djm w-100" href="<?= h(url('setup.php')) ?>">
        <i class="bi bi-box-arrow-in-right me-1"></i>Naar de installatie
    </a>
<?php else: ?>

    <?php if ($teVaak && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
        <div class="alert alert-warning py-2">
            <i class="bi bi-hourglass-split me-1"></i>
            Er is te vaak achter elkaar geprobeerd in te loggen vanaf dit IP-adres.
            Wacht <?= ADMIN_LOGIN_VENSTER_MINUTEN ?> minuten en probeer het opnieuw.
        </div>
    <?php endif; ?>

    <?php if ($fout !== ''): ?>
        <div class="alert alert-danger py-2"><i class="bi bi-x-circle me-1"></i><?= h($fout) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="on">
        <?= csrf_field() ?>
        <input type="hidden" name="terug" value="<?= h($terugParameter) ?>">

        <div class="mb-3">
            <label class="form-label" for="email">E-mailadres</label>
            <input type="email" class="form-control" id="email" name="email" required autofocus
                value="<?= h($email) ?>" autocomplete="username" maxlength="190">
        </div>

        <div class="mb-3">
            <div class="d-flex justify-content-between align-items-baseline gap-2">
                <label class="form-label" for="wachtwoord">Wachtwoord</label>
                <a class="small text-decoration-none" href="<?= h(url('admin/wachtwoord_vergeten.php')) ?>">Wachtwoord vergeten?</a>
            </div>
            <input type="password" class="form-control" id="wachtwoord" name="wachtwoord" required
                autocomplete="current-password">
        </div>

        <button type="submit" class="btn btn-djm w-100" <?= $teVaak ? 'disabled' : '' ?>>
            <i class="bi bi-box-arrow-in-right me-1"></i>Inloggen
        </button>
    </form>

<?php endif; ?>

<hr class="my-4">
<div class="text-center small">
    <a class="text-decoration-none" href="<?= h(url('index.php')) ?>">
        <i class="bi bi-arrow-left me-1"></i>Terug naar het portaal
    </a>
</div>

<?php
admin_login_eind();
