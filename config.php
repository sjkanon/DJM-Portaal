<?php

/**
 * DJM Portaal — Deventer Jeugd Musical
 * Centrale configuratie, databaseverbinding en gedeelde helpers.
 *
 * Elk instapbestand begint met: require_once __DIR__ . '/config.php';
 */

// ─── Omgevingsvariabelen laden ───────────────────────────────────────────────
function loadEnv(string $filePath): void
{
    if (!is_file($filePath)) {
        return;
    }
    foreach (file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[0] === substr($value, -1)) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '' && !isset($_ENV[$key])) {
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}
loadEnv(__DIR__ . '/.env');

function env(string $key, string $default = ''): string
{
    $waarde = $_ENV[$key] ?? getenv($key);
    return ($waarde === false || $waarde === null || $waarde === '') ? $default : (string)$waarde;
}

// ─── Basisconstanten ─────────────────────────────────────────────────────────
define('APP_NAME',    env('APP_NAME', 'Deventer Jeugd Musical'));
define('APP_VERSION', trim((string)@file_get_contents(__DIR__ . '/VERSION') ?: '1.0.0'));
define('APP_ROOT',    __DIR__);

define('DB_HOST',    env('DB_HOST', 'localhost'));
define('DB_NAME',    env('DB_NAME', 'djm_portaal'));
define('DB_USER',    env('DB_USER', 'djm_portaal'));
define('DB_PASS',    env('DB_PASS'));
define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));

define('SESSION_NAME', 'djmportaal');

// ─── Foutafhandeling ─────────────────────────────────────────────────────────
$debugMode = strtolower(env('DEBUG', 'false')) === 'true';
define('DEBUG_MODE', $debugMode);
ini_set('display_errors', $debugMode ? '1' : '0');
error_reporting($debugMode ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
ini_set('log_errors', '1');
ini_set('error_log', $logDir . '/php-error.log');

function app_log(string $bericht, array $context = []): void
{
    $regel = sprintf(
        '[%s] %s %s',
        date('Y-m-d H:i:s'),
        $bericht,
        $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
    );
    @file_put_contents(APP_ROOT . '/logs/app.log', rtrim($regel) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

// ─── Sessiebeveiliging ───────────────────────────────────────────────────────
$httpsActief = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', $httpsActief ? '1' : '0');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_strict_mode', '1');

function ensure_session_started(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    if (PHP_SAPI === 'cli') {
        return;
    }
    if (session_name() !== SESSION_NAME) {
        session_name(SESSION_NAME);
    }
    session_start();
}

function destroy_current_session(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    ensure_session_started();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'] ?: '/',
            'domain'   => $p['domain'] ?? '',
            'secure'   => (bool)($p['secure'] ?? false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
}

// ─── Securityheaders ─────────────────────────────────────────────────────────
function stuur_security_headers(): void
{
    if (headers_sent() || PHP_SAPI === 'cli') {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "img-src 'self' data: https:; "
        . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
        . "font-src 'self' https://cdn.jsdelivr.net; "
        . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
        . "form-action 'self'; frame-ancestors 'none'; base-uri 'self'"
    );
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if ($https) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// ─── Database ────────────────────────────────────────────────────────────────
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

function db_beschikbaar(): bool
{
    try {
        db()->query('SELECT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function tabel_bestaat(string $tabel): bool
{
    try {
        db()->query('SELECT 1 FROM `' . str_replace('`', '', $tabel) . '` LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

// ─── Instellingen ────────────────────────────────────────────────────────────
function instellingen(bool $herlaad = false): array
{
    static $cache = null;
    if ($cache === null || $herlaad) {
        $cache = [];
        try {
            foreach (db()->query('SELECT sleutel, waarde FROM instellingen') as $rij) {
                $cache[$rij['sleutel']] = (string)$rij['waarde'];
            }
        } catch (Throwable $e) {
            $cache = [];
        }
    }
    return $cache;
}

function instelling(string $sleutel, string $standaard = ''): string
{
    $alles = instellingen();
    $waarde = $alles[$sleutel] ?? '';
    return $waarde !== '' ? $waarde : $standaard;
}

function instelling_int(string $sleutel, int $standaard): int
{
    $waarde = instelling($sleutel, '');
    return $waarde === '' ? $standaard : (int)$waarde;
}

function instelling_bool(string $sleutel, bool $standaard = false): bool
{
    $waarde = instelling($sleutel, $standaard ? '1' : '0');
    return in_array(strtolower($waarde), ['1', 'ja', 'true', 'yes', 'aan'], true);
}

function instelling_opslaan(string $sleutel, string $waarde): void
{
    db()->prepare('INSERT INTO instellingen (sleutel, waarde) VALUES (:s, :w)
                   ON DUPLICATE KEY UPDATE waarde = VALUES(waarde)')
        ->execute([':s' => $sleutel, ':w' => $waarde]);
    instellingen(true);
}

function portaal_naam(): string
{
    return instelling('portaal_naam', APP_NAME);
}

// ─── Weergavehelpers ─────────────────────────────────────────────────────────
function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function formatteer_bytes(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 B';
    }
    $eenheden = ['B', 'KB', 'MB', 'GB', 'TB'];
    $macht = (int)min(floor(log($bytes, 1024)), count($eenheden) - 1);
    $getal = $bytes / (1024 ** $macht);
    return number_format($getal, $macht >= 2 ? 1 : 0, ',', '.') . ' ' . $eenheden[$macht];
}

function formatteer_datum(?string $datum, bool $metTijd = true): string
{
    if (!$datum) {
        return '—';
    }
    $ts = strtotime($datum);
    return $ts ? date($metTijd ? 'd-m-Y H:i' : 'd-m-Y', $ts) : '—';
}

// ─── URL's ───────────────────────────────────────────────────────────────────
/** Absolute basis-URL van de applicatie, zonder afsluitende slash. */
function app_base_url(): string
{
    $uitEnv = rtrim(env('APP_URL'), '/');
    if ($uitEnv !== '') {
        return $uitEnv;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // Pad naar de projectroot afleiden uit het draaiende script.
    $scriptDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
    $scriptDir = rtrim($scriptDir, '/');
    foreach (['/admin', '/portaal'] as $submap) {
        if (str_ends_with($scriptDir, $submap)) {
            $scriptDir = substr($scriptDir, 0, -strlen($submap));
            break;
        }
    }
    return $scheme . '://' . $host . rtrim($scriptDir, '/');
}

/**
 * Controleert of APP_URL overeenkomt met het adres waarop de bezoeker
 * binnenkomt. Wijken ze af, dan wijzen alle omleidingen naar een andere
 * origin; browsers blokkeren dan het versturen van formulieren vanwege de
 * Content-Security-Policy (form-action 'self') en niemand kan meer inloggen.
 *
 * @return string Lege string als alles klopt, anders het verwachte adres.
 */
function app_url_afwijking(): string
{
    $ingesteld = rtrim(env('APP_URL'), '/');
    if ($ingesteld === '' || PHP_SAPI === 'cli') {
        return '';
    }

    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return '';
    }

    $ingesteldeHost = strtolower((string)(parse_url($ingesteld, PHP_URL_HOST) ?? ''));
    $poort = parse_url($ingesteld, PHP_URL_PORT);
    if ($poort) {
        $ingesteldeHost .= ':' . $poort;
    }

    return ($ingesteldeHost !== '' && $ingesteldeHost !== $host) ? $ingesteld : '';
}

function url(string $pad = ''): string
{
    return app_base_url() . '/' . ltrim($pad, '/');
}

// ─── Opslag van de videobestanden ────────────────────────────────────────────
function opslag_pad(): string
{
    $pad = env('OPSLAG_PAD');
    if ($pad === '') {
        $pad = APP_ROOT . '/opslag';
    }
    return rtrim($pad, '/');
}

/**
 * Zet een relatief pad uit de database om naar een absoluut pad binnen de
 * opslagmap. Weigert alles wat buiten de opslagmap zou wijzen.
 */
function opslag_absoluut_pad(string $relatiefPad): ?string
{
    $relatiefPad = str_replace('\\', '/', trim($relatiefPad));
    $relatiefPad = ltrim($relatiefPad, '/');
    if ($relatiefPad === '' || str_contains($relatiefPad, '..') || str_contains($relatiefPad, "\0")) {
        return null;
    }

    $basis = realpath(opslag_pad());
    if ($basis === false) {
        return null;
    }

    $volledig = realpath($basis . '/' . $relatiefPad);
    if ($volledig === false || !is_file($volledig)) {
        return null;
    }
    if (!str_starts_with($volledig, $basis . DIRECTORY_SEPARATOR) && $volledig !== $basis) {
        return null;
    }
    return $volledig;
}

// ─── E-mail ──────────────────────────────────────────────────────────────────
function normaliseer_email(string $email): string
{
    return strtolower(trim($email));
}

function geldig_email(string $email): bool
{
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 190;
}

// ─── Client-informatie ───────────────────────────────────────────────────────
function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

/** IP als binaire waarde voor de VARBINARY(16)-kolommen; null bij onbekend. */
function client_ip_bin(): ?string
{
    $ip = client_ip();
    if ($ip === '') {
        return null;
    }
    $bin = @inet_pton($ip);
    return $bin === false ? null : $bin;
}

function ip_leesbaar(?string $bin): string
{
    if ($bin === null || $bin === '') {
        return '—';
    }
    $ip = @inet_ntop($bin);
    return $ip === false ? '—' : $ip;
}

function client_user_agent(): string
{
    return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

// ─── Sleutels voor ondertekening ─────────────────────────────────────────────
function app_key(): string
{
    $sleutel = env('APP_KEY');
    if ($sleutel === '') {
        // Vangnet: afgeleid van de databasegegevens zodat de applicatie blijft
        // werken, maar setup.php waarschuwt hier nadrukkelijk over.
        $sleutel = hash('sha256', DB_NAME . '|' . DB_USER . '|' . DB_PASS . '|djm');
    }
    return $sleutel;
}

function otp_pepper(): string
{
    $pepper = env('OTP_PEPPER');
    if ($pepper === '') {
        $pepper = hash('sha256', 'otp|' . app_key());
    }
    return $pepper;
}

// ─── Logboekhelpers ──────────────────────────────────────────────────────────
function log_login(string $soort, ?string $email, bool $gelukt, string $detail = ''): void
{
    try {
        db()->prepare('INSERT INTO login_log (email, soort, gelukt, detail, ip, user_agent)
                       VALUES (:email, :soort, :gelukt, :detail, :ip, :ua)')
            ->execute([
                ':email'  => $email !== null && $email !== '' ? substr($email, 0, 190) : null,
                ':soort'  => $soort,
                ':gelukt' => $gelukt ? 1 : 0,
                ':detail' => $detail !== '' ? substr($detail, 0, 255) : null,
                ':ip'     => client_ip_bin(),
                ':ua'     => client_user_agent(),
            ]);
    } catch (Throwable $e) {
        app_log('login_log mislukt', ['fout' => $e->getMessage()]);
    }
}

// ─── Installatiecontrole ─────────────────────────────────────────────────────
/** Stuurt door naar setup.php zolang de database nog niet is ingericht. */
function vereis_installatie(): void
{
    if (tabel_bestaat('instellingen')) {
        return;
    }
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    $huidig = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($huidig === 'setup.php') {
        return;
    }
    header('Location: ' . url('setup.php'));
    exit;
}
