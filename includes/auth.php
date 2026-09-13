<?php

/**
 * Sessies, CSRF en toegangscontrole.
 *
 * Twee gescheiden identiteiten in dezelfde sessie:
 *   - deelnemer : ingelogd via e-mailadres + eenmalige code (portaal + download)
 *   - beheerder : ingelogd via e-mailadres + wachtwoord (admin)
 */

if (!function_exists('db')) {
    require_once dirname(__DIR__) . '/config.php';
}

ensure_session_started();

// ─── Sessieverloop ───────────────────────────────────────────────────────────
(function (): void {
    if (PHP_SAPI === 'cli') {
        return;
    }
    $maxInactief = max(15, instelling_int('sessie_duur_minuten', 120)) * 60;
    $laatste = (int)($_SESSION['laatste_activiteit'] ?? 0);
    if ($laatste > 0 && (time() - $laatste) > $maxInactief) {
        destroy_current_session();
        ensure_session_started();
    }
    $_SESSION['laatste_activiteit'] = time();
})();

// ─── CSRF ────────────────────────────────────────────────────────────────────
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(?string $token): bool
{
    return is_string($token) && $token !== ''
        && hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token);
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

/** Controleert het CSRF-token van een POST en stopt bij een fout. */
function vereis_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Sessie verlopen. Ga terug, ververs de pagina en probeer het opnieuw.');
    }
}

// ─── Deelnemer ───────────────────────────────────────────────────────────────
function deelnemer_inloggen(array $deelnemer): void
{
    session_regenerate_id(true);
    $_SESSION['deelnemer_id']    = (int)$deelnemer['id'];
    $_SESSION['deelnemer_email'] = (string)$deelnemer['email'];
    $_SESSION['deelnemer_naam']  = (string)($deelnemer['naam'] ?? '');
    $_SESSION['ingelogd_op']     = time();
    $_SESSION['laatste_activiteit'] = time();
    unset($_SESSION['otp_challenge'], $_SESSION['otp_email']);

    try {
        db()->prepare('UPDATE deelnemers SET laatst_ingelogd_op = NOW() WHERE id = :id')
            ->execute([':id' => (int)$deelnemer['id']]);
    } catch (Throwable $e) {
        app_log('bijwerken laatst_ingelogd_op mislukt', ['fout' => $e->getMessage()]);
    }
}

function deelnemer_ingelogd(): bool
{
    return !empty($_SESSION['deelnemer_id']);
}

function huidige_deelnemer(): ?array
{
    if (!deelnemer_ingelogd()) {
        return null;
    }
    static $cache = null;
    if ($cache !== null && (int)$cache['id'] === (int)$_SESSION['deelnemer_id']) {
        return $cache;
    }
    $stmt = db()->prepare('SELECT * FROM deelnemers WHERE id = :id AND geblokkeerd = 0');
    $stmt->execute([':id' => (int)$_SESSION['deelnemer_id']]);
    $rij = $stmt->fetch();
    if (!$rij) {
        unset($_SESSION['deelnemer_id'], $_SESSION['deelnemer_email'], $_SESSION['deelnemer_naam']);
        return null;
    }
    $cache = $rij;
    return $rij;
}

function vereis_deelnemer(): array
{
    $deelnemer = huidige_deelnemer();
    if ($deelnemer === null) {
        header('Location: ' . url('index.php?reden=sessie'));
        exit;
    }
    return $deelnemer;
}

function deelnemer_uitloggen(): void
{
    $email = (string)($_SESSION['deelnemer_email'] ?? '');
    if ($email !== '') {
        log_login('uitgelogd', $email, true);
    }
    vergeet_dit_apparaat();
    destroy_current_session();
}

// ─── Onthoud dit apparaat ────────────────────────────────────────────────────
const REMEMBER_COOKIE = 'djm_apparaat';

function onthoud_dit_apparaat(int $deelnemerId): void
{
    if (!instelling_bool('remember_toestaan', true)) {
        return;
    }
    $dagen    = max(1, instelling_int('remember_dagen', 30));
    $selector = bin2hex(random_bytes(12));            // 24 tekens
    $geheim   = bin2hex(random_bytes(32));
    $verloopt = time() + ($dagen * 86400);

    db()->prepare('INSERT INTO remember_tokens (deelnemer_id, selector, token_hash, verloopt_op, ip)
                   VALUES (:d, :s, :t, :v, :ip)')
        ->execute([
            ':d'  => $deelnemerId,
            ':s'  => $selector,
            ':t'  => hash('sha256', $geheim),
            ':v'  => date('Y-m-d H:i:s', $verloopt),
            ':ip' => client_ip_bin(),
        ]);

    // https_actief() kijkt ook naar X-Forwarded-Proto. Zonder dat zou dit cookie
    // achter een TLS-afsluitende proxy zonder `secure` de deur uit gaan, en dan
    // reist een sleutel die dertig dagen toegang geeft mee over gewoon http.
    setcookie(REMEMBER_COOKIE, $selector . ':' . $geheim, [
        'expires'  => $verloopt,
        'path'     => '/',
        'secure'   => https_actief(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Probeert de sessie te herstellen uit het onthoud-cookie. */
function herstel_uit_remember_cookie(): bool
{
    if (deelnemer_ingelogd() || empty($_COOKIE[REMEMBER_COOKIE])) {
        return false;
    }
    if (!instelling_bool('remember_toestaan', true)) {
        return false;
    }

    $delen = explode(':', (string)$_COOKIE[REMEMBER_COOKIE], 2);
    if (count($delen) !== 2) {
        vergeet_dit_apparaat();
        return false;
    }
    [$selector, $geheim] = $delen;

    $stmt = db()->prepare('SELECT r.*, d.email, d.naam, d.geblokkeerd
                           FROM remember_tokens r
                           JOIN deelnemers d ON d.id = r.deelnemer_id
                           WHERE r.selector = :s AND r.verloopt_op > NOW()');
    $stmt->execute([':s' => $selector]);
    $rij = $stmt->fetch();

    if (!$rij || (int)$rij['geblokkeerd'] === 1
        || !hash_equals((string)$rij['token_hash'], hash('sha256', $geheim))) {
        vergeet_dit_apparaat();
        return false;
    }

    db()->prepare('UPDATE remember_tokens SET laatst_gebruikt = NOW() WHERE id = :id')
        ->execute([':id' => (int)$rij['id']]);

    deelnemer_inloggen([
        'id'    => (int)$rij['deelnemer_id'],
        'email' => (string)$rij['email'],
        'naam'  => (string)($rij['naam'] ?? ''),
    ]);
    log_login('code_ok', (string)$rij['email'], true, 'hersteld via onthoud-dit-apparaat');
    return true;
}

function vergeet_dit_apparaat(): void
{
    if (!empty($_COOKIE[REMEMBER_COOKIE])) {
        $selector = explode(':', (string)$_COOKIE[REMEMBER_COOKIE], 2)[0];
        try {
            db()->prepare('DELETE FROM remember_tokens WHERE selector = :s')
                ->execute([':s' => $selector]);
        } catch (Throwable $e) {
            // niet blokkerend
        }
    }
    setcookie(REMEMBER_COOKIE, '', [
        'expires'  => time() - 42000,
        'path'     => '/',
        'secure'   => https_actief(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[REMEMBER_COOKIE]);
}

// ─── Beheerder ───────────────────────────────────────────────────────────────
function beheerder_inloggen(array $beheerder): void
{
    session_regenerate_id(true);
    $_SESSION['beheerder_id']   = (int)$beheerder['id'];
    $_SESSION['beheerder_naam'] = (string)$beheerder['naam'];
    $_SESSION['beheerder_rol']  = (string)($beheerder['rol'] ?? 'beheerder');
    $_SESSION['laatste_activiteit'] = time();

    db()->prepare('UPDATE beheerders SET laatst_ingelogd_op = NOW() WHERE id = :id')
        ->execute([':id' => (int)$beheerder['id']]);
}

function beheerder_ingelogd(): bool
{
    return !empty($_SESSION['beheerder_id']);
}

function huidige_beheerder(): ?array
{
    if (!beheerder_ingelogd()) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM beheerders WHERE id = :id AND actief = 1');
    $stmt->execute([':id' => (int)$_SESSION['beheerder_id']]);
    $rij = $stmt->fetch();
    if (!$rij) {
        unset($_SESSION['beheerder_id'], $_SESSION['beheerder_naam'], $_SESSION['beheerder_rol']);
        return null;
    }
    return $rij;
}

function vereis_beheerder(): array
{
    $beheerder = huidige_beheerder();
    if ($beheerder === null) {
        // Een script (admin/upload.php) kan niets met een doorverwijzing naar de
        // inlogpagina: die volgt fetch() stilletjes en levert dan HTML op.
        if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            exit(json_encode(['fout' => 'U bent niet meer ingelogd.']));
        }
        $terug = urlencode((string)($_SERVER['REQUEST_URI'] ?? ''));
        header('Location: ' . url('admin/login.php') . ($terug ? '?terug=' . $terug : ''));
        exit;
    }
    return $beheerder;
}

function beheerder_uitloggen(): void
{
    if (beheerder_ingelogd()) {
        log_login('uitgelogd', null, true, 'beheerder ' . (string)($_SESSION['beheerder_naam'] ?? ''));
    }
    unset($_SESSION['beheerder_id'], $_SESSION['beheerder_naam'], $_SESSION['beheerder_rol']);
    session_regenerate_id(true);
}

// ─── Meldingen tussen pagina's ───────────────────────────────────────────────
function flash(string $type, string $bericht): void
{
    $_SESSION['flash'][] = ['type' => $type, 'bericht' => $bericht];
}

function flash_ophalen(): array
{
    $meldingen = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($meldingen) ? $meldingen : [];
}
