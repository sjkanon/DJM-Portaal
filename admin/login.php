<?php

/**
 * Beheerdersinlog — e-mailadres + wachtwoord tegen de tabel `beheerders`.
 *
 * Bevat een eenvoudige brute-force rem op IP-niveau via de tabel
 * `aanvraag_limiet`. Die logica staat bewust in dit bestand: het beheer mag
 * niet afhankelijk zijn van de OTP-helpers van het portaal.
 */

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/includes/layout.php';
vereis_installatie();

// ─── Instellingen van de brute-force rem ─────────────────────────────────────
const ADMIN_LOGIN_MAX_POGINGEN   = 10;
const ADMIN_LOGIN_VENSTER_MINUTEN = 15;

/** Huidige tellerstand voor deze sleutel; 0 als het venster verlopen is. */
function admin_limiet_teller(string $sleutel, int $vensterMinuten): int
{
    try {
        $stmt = db()->prepare('SELECT teller, venster_start FROM aanvraag_limiet WHERE sleutel = :s');
        $stmt->execute([':s' => $sleutel]);
        $rij = $stmt->fetch();
        if (!$rij) {
            return 0;
        }
        $start = strtotime((string)$rij['venster_start']);
        if ($start === false || $start < time() - ($vensterMinuten * 60)) {
            return 0;
        }
        return (int)$rij['teller'];
    } catch (Throwable $e) {
        // Bij twijfel niet blokkeren: een kapotte tabel mag het beheer niet buitensluiten.
        app_log('admin rate limit lezen mislukt', ['fout' => $e->getMessage()]);
        return 0;
    }
}

/** Hoogt de teller op en start een nieuw venster zodra het oude verlopen is. */
function admin_limiet_ophogen(string $sleutel, int $vensterMinuten): void
{
    $minuten = max(1, $vensterMinuten);
    $sql = sprintf(
        'INSERT INTO aanvraag_limiet (sleutel, teller, venster_start)
         VALUES (:s, 1, NOW())
         ON DUPLICATE KEY UPDATE
             teller = IF(venster_start < (NOW() - INTERVAL %1$d MINUTE), 1, teller + 1),
             venster_start = IF(venster_start < (NOW() - INTERVAL %1$d MINUTE), NOW(), venster_start)',
        $minuten
    );
    try {
        db()->prepare($sql)->execute([':s' => $sleutel]);
    } catch (Throwable $e) {
        app_log('admin rate limit ophogen mislukt', ['fout' => $e->getMessage()]);
    }
}

/** Wist de teller na een geslaagde inlog. */
function admin_limiet_wissen(string $sleutel): void
{
    try {
        db()->prepare('DELETE FROM aanvraag_limiet WHERE sleutel = :s')->execute([':s' => $sleutel]);
    } catch (Throwable $e) {
        // niet blokkerend
    }
}

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

$limietSleutel = 'admin:' . client_ip();
$teVaak        = admin_limiet_teller($limietSleutel, ADMIN_LOGIN_VENSTER_MINUTEN) >= ADMIN_LOGIN_MAX_POGINGEN;

$terugParameter = (string)($_POST['terug'] ?? $_GET['terug'] ?? '');
$fout           = '';
$email          = '';

// ─── Verwerking ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $databaseOk && $aantalBeheerders > 0) {
    vereis_csrf();

    $email      = normaliseer_email((string)($_POST['email'] ?? ''));
    $wachtwoord = (string)($_POST['wachtwoord'] ?? '');

    if ($teVaak) {
        $fout = 'Er is te vaak achter elkaar geprobeerd in te loggen vanaf dit IP-adres. '
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
        $ok   = $rij !== null && (int)$rij['actief'] === 1 && $hash !== ''
            && password_verify($wachtwoord, $hash);

        if (!$ok) {
            // Altijd dezelfde neutrale melding: geen user enumeration.
            admin_limiet_ophogen($limietSleutel, ADMIN_LOGIN_VENSTER_MINUTEN);
            $fout = 'Onjuiste inloggegevens.';
            log_login('admin_fout', $email, false);
        } else {
            if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                try {
                    db()->prepare('UPDATE beheerders SET wachtwoord_hash = :h WHERE id = :id')
                        ->execute([':h' => password_hash($wachtwoord, PASSWORD_DEFAULT), ':id' => (int)$rij['id']]);
                } catch (Throwable $e) {
                    app_log('wachtwoordhash verversen mislukt', ['fout' => $e->getMessage()]);
                }
            }

            admin_limiet_wissen($limietSleutel);
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
            <label class="form-label" for="wachtwoord">Wachtwoord</label>
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
