<?php

/**
 * DJM Portaal — Deventer Jeugd Musical
 * Centrale configuratie, databaseverbinding en gedeelde helpers.
 *
 * Elk instapbestand begint met: require_once __DIR__ . '/config.php';
 */

// ─── Omgevingsvariabelen laden ───────────────────────────────────────────────

/**
 * Leest een .env-bestand uit tot een array sleutel => waarde.
 *
 * Een regel is `SLEUTEL=waarde`, eventueel met `export ` ervoor. Staat de
 * waarde tussen aanhalingstekens, dan worden die eraf gehaald; dat is de enige
 * manier om spaties aan het begin of einde te bewaren. Tussen dúbbele
 * aanhalingstekens gelden de gebruikelijke ontsnappingen \" \\ \n \r \t,
 * zodat ook een wachtwoord met een aanhalingsteken erin heelhuids aankomt.
 * Tussen enkele aanhalingstekens blijft alles staan zoals het er staat.
 *
 * Een `#` middenin een waarde begint hier bewust géén commentaar: dat zou een
 * bestaand wachtwoord stilzwijgend kunnen afkappen. Wie een `#` in een waarde
 * nodig heeft, hoeft dus niets te doen; wie er commentaar achter wil, zet de
 * waarde tussen aanhalingstekens en het commentaar erachter.
 *
 * De functie raakt $_ENV niet aan. Zo kan setup.php een bestaand .env inlezen
 * zonder de draaiende configuratie te beïnvloeden.
 *
 * @return array<string, string>
 */
function env_parse(string $filePath): array
{
    if (!is_file($filePath) || !is_readable($filePath)) {
        return [];
    }
    $regels = @file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($regels === false) {
        return [];
    }

    $waarden = [];
    foreach ($regels as $nummer => $regel) {
        if ($nummer === 0) {
            $regel = preg_replace('/^\xEF\xBB\xBF/', '', $regel) ?? $regel;   // byte order mark
        }
        $regel = trim($regel);
        if ($regel === '' || $regel[0] === '#' || !str_contains($regel, '=')) {
            continue;
        }
        [$sleutel, $waarde] = explode('=', $regel, 2);
        $sleutel = trim($sleutel);
        if (str_starts_with($sleutel, 'export ')) {
            $sleutel = trim(substr($sleutel, 7));
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $sleutel)) {
            continue;
        }
        $waarden[$sleutel] = env_waarde_ontleden(trim($waarde));
    }
    return $waarden;
}

/** Haalt aanhalingstekens en ontsnappingen van één .env-waarde af. */
function env_waarde_ontleden(string $waarde): string
{
    if ($waarde === '') {
        return '';
    }
    $teken = $waarde[0];
    if ($teken !== '"' && $teken !== "'") {
        return $waarde;
    }

    $lengte = strlen($waarde);
    $binnen = '';
    for ($i = 1; $i < $lengte; $i++) {
        $huidig = $waarde[$i];
        if ($teken === '"' && $huidig === '\\' && $i + 1 < $lengte) {
            $volgend = $waarde[$i + 1];
            $binnen .= match ($volgend) {
                'n'     => "\n",
                'r'     => "\r",
                't'     => "\t",
                '"'     => '"',
                '\\'    => '\\',
                default => '\\' . $volgend,
            };
            $i++;
            continue;
        }
        if ($huidig === $teken) {
            return $binnen;         // alles ná het slotteken is commentaar
        }
        $binnen .= $huidig;
    }

    // Geen slotteken gevonden: dan was het aanhalingsteken kennelijk geen quote.
    return $waarde;
}

/**
 * Zet een waarde om naar een regel die env_parse() weer precies zo teruggeeft.
 *
 * Alles gaat tussen dubbele aanhalingstekens: dat is altijd goed, ook bij een
 * lege waarde, een spatie aan het eind of een wachtwoord vol leestekens.
 */
function env_regel(string $sleutel, string $waarde): string
{
    $ontsnapt = strtr($waarde, [
        '\\'   => '\\\\',
        '"'    => '\\"',
        "\n"   => '\\n',
        "\r"   => '\\r',
        "\t"   => '\\t',
    ]);
    return $sleutel . '="' . $ontsnapt . '"';
}

function loadEnv(string $filePath): void
{
    foreach (env_parse($filePath) as $sleutel => $waarde) {
        // Een echte omgevingsvariabele (Docker, systemd, SetEnv) wint van .env.
        // Ook getenv() nakijken: of de omgeving in $_ENV terechtkomt hangt af
        // van variables_order in php.ini, en daar willen we niet van afhangen.
        if (isset($_ENV[$sleutel]) || getenv($sleutel) !== false) {
            continue;
        }
        $_ENV[$sleutel] = $waarde;
        putenv("$sleutel=$waarde");
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
define('DB_PORT',    env('DB_PORT'));
define('DB_SOCKET',  env('DB_SOCKET'));
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
/**
 * Komt de bezoeker via HTTPS binnen?
 *
 * Eén plek voor deze vraag: de sessiecookie, het onthoud-cookie, HSTS en
 * app_base_url() moeten hier niet uit elkaar kunnen lopen. Achter een
 * TLS-afsluitende proxy (nginx, HAProxy, Cloudflare) staat $_SERVER['HTTPS']
 * niet, maar stuurt de proxy X-Forwarded-Proto.
 *
 * Staat er een lijst met vertrouwde proxy's, dan nemen we die header alleen van
 * die proxy's aan. Staat er geen lijst, dan geloven we hem wel: een bezoeker die
 * hem zelf verzint werkt uitsluitend zichzelf tegen (zijn cookies worden dan
 * strenger gezet), terwijl hem niet geloven bij een installatie achter een proxy
 * zónder TRUSTED_PROXIES betekent dat de sessiecookie de `secure`-vlag verliest.
 * Dat laatste is een echte verzwakking; dit niet.
 */
function https_actief(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    $mogenWeKijken = vertrouwde_proxies() === []
        || is_vertrouwde_proxy((string)($_SERVER['REMOTE_ADDR'] ?? ''));

    if ($mogenWeKijken) {
        $doorgestuurd = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        // Een proxyketen kan er meerdere op een rij zetten: "https, http".
        if ($doorgestuurd !== '' && explode(',', $doorgestuurd)[0] === 'https') {
            return true;
        }
    }

    return ((int)($_SERVER['SERVER_PORT'] ?? 0)) === 443;
}

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', https_actief() ? '1' : '0');
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
    // Alles wat de pagina's nodig hebben — ook Bootstrap — staat in assets/ van
    // deze installatie. De policy laat daarom geen enkele externe bron toe, op
    // afbeeldingen na: een logo mag elders gehost zijn (instelling Logo-URL).
    //
    // script-src staat bewust GEEN 'unsafe-inline' toe: alle JavaScript staat in
    // losse bestanden (admin/assets/admin.js). Zou er ooit toch een stukje tekst
    // van een bezoeker als HTML op een pagina belanden, dan voert de browser het
    // daarin gesmokkelde script niet uit. Voor style-src kan dat niet: het blok
    // met de merkkleur in de layout en de style="…"-attributen in het beheer
    // zijn inline.
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "img-src 'self' data: https:; "
        . "style-src 'self' 'unsafe-inline'; "
        . "font-src 'self'; "
        . "script-src 'self'; "
        . "object-src 'none'; "
        . "form-action 'self'; frame-ancestors 'none'; base-uri 'self'"
    );
    // Pagina's tonen persoonsgegevens (e-mailadressen, logboeken). Ze mogen niet
    // in een gedeelde cache of in het schijfcachegeheugen van een geleende
    // computer achterblijven, waar ze na het uitloggen nog op te vragen zijn.
    header('Cache-Control: no-store, private');
    if (https_actief()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// ─── Database ────────────────────────────────────────────────────────────────

/**
 * Bouwt een PDO-DSN op uit losse onderdelen.
 *
 * Eén plek voor deze samenstelling, zodat db() en de verbindingstest in
 * setup.php niet uit elkaar kunnen lopen. Is er een socket opgegeven, dan gaat
 * die voor: MySQL negeert host en poort dan toch. Een lege `dbname` levert een
 * DSN op waarmee je verbindt zónder database te kiezen — dat is precies wat je
 * nodig hebt om te controleren of de database wel bestaat.
 *
 * @param array{host?: string, port?: string, socket?: string, dbname?: string, charset?: string} $gegevens
 */
function mysql_dsn(array $gegevens): string
{
    $socket  = trim((string)($gegevens['socket'] ?? ''));
    $charset = trim((string)($gegevens['charset'] ?? '')) ?: 'utf8mb4';
    $dbnaam  = trim((string)($gegevens['dbname'] ?? ''));

    $delen = [];
    if ($socket !== '') {
        $delen[] = 'unix_socket=' . $socket;
    } else {
        $delen[] = 'host=' . (trim((string)($gegevens['host'] ?? '')) ?: 'localhost');
        $poort   = trim((string)($gegevens['port'] ?? ''));
        if ($poort !== '') {
            $delen[] = 'port=' . $poort;
        }
    }
    if ($dbnaam !== '') {
        $delen[] = 'dbname=' . $dbnaam;
    }
    $delen[] = 'charset=' . $charset;

    return 'mysql:' . implode(';', $delen);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = mysql_dsn([
            'host'    => DB_HOST,
            'port'    => DB_PORT,
            'socket'  => DB_SOCKET,
            'dbname'  => DB_NAME,
            'charset' => DB_CHARSET,
        ]);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            // Zonder limiet blijft elke pagina hangen zolang het besturingssysteem
            // op een antwoord wacht. Tien seconden is ruim voor een database die
            // er is, en kort genoeg om een verkeerd adres meteen te merken.
            PDO::ATTR_TIMEOUT            => 10,
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
/**
 * De Host-header, maar alleen als die er als een hostnaam uitziet.
 *
 * De bezoeker bepaalt deze header zelf. Zonder APP_URL in .env komt hij in
 * elke link terecht die dit portaal maakt — ook in de uitnodigingsmail. Iemand
 * die een verzoek met een eigen Host-header stuurt, zou zo een mail met een
 * link naar zijn eigen server kunnen laten versturen. Daarom accepteren we
 * alleen letters, cijfers, punt, streepje en een poortnummer, en anders
 * 'localhost'. Vul APP_URL in en deze vraag speelt helemaal niet meer.
 */
function veilige_host(): string
{
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '' || strlen($host) > 253) {
        return 'localhost';
    }
    return preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/', $host) ? $host : 'localhost';
}

/** Absolute basis-URL van de applicatie, zonder afsluitende slash. */
function app_base_url(): string
{
    $uitEnv = rtrim(env('APP_URL'), '/');
    if ($uitEnv !== '') {
        return $uitEnv;
    }
    $scheme = https_actief() ? 'https' : 'http';
    $host   = veilige_host();

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

    $host = strtolower(veilige_host());
    if ($host === '' || $host === 'localhost') {
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

// ─── Vertrouwde proxy's ──────────────────────────────────────────────────────
/**
 * De proxy's waarvan dit portaal de doorstuurheaders aanneemt, uit
 * TRUSTED_PROXIES in .env (komma's ertussen, IP-adres of CIDR-bereik).
 *
 * Staat er niets, dan vertrouwen we niemand. Dat is de veilige stand: elke
 * bezoeker kan zelf een X-Forwarded-For meesturen, dus zonder deze lijst zou
 * iemand zich met één header eindeloos nieuwe IP-adressen kunnen aanmeten en
 * de rate limiting op het aanvragen van inlogcodes waardeloos maken.
 *
 * @return string[]
 */
function vertrouwde_proxies(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    foreach (explode(',', env('TRUSTED_PROXIES')) as $stuk) {
        $stuk = trim($stuk);
        if ($stuk !== '') {
            $cache[] = $stuk;
        }
    }
    return $cache;
}

/**
 * Valt $ip binnen $bereik? $bereik is één IP-adres of een CIDR-notatie
 * (10.0.0.0/8, 2001:db8::/32). Werkt voor IPv4 en IPv6.
 */
function ip_in_bereik(string $ip, string $bereik): bool
{
    $ipBin = @inet_pton($ip);
    if ($ipBin === false) {
        return false;
    }

    if (!str_contains($bereik, '/')) {
        $bereikBin = @inet_pton($bereik);
        return $bereikBin !== false && hash_equals($bereikBin, $ipBin);
    }

    [$net, $bits] = explode('/', $bereik, 2);
    $netBin = @inet_pton(trim($net));
    if ($netBin === false || !ctype_digit(trim($bits))) {
        return false;
    }
    // IPv4 en IPv6 zijn niet met elkaar te vergelijken: verschillende lengte.
    if (strlen($netBin) !== strlen($ipBin)) {
        return false;
    }

    $bits = (int)trim($bits);
    $max  = strlen($netBin) * 8;
    if ($bits < 0 || $bits > $max) {
        return false;
    }

    $helebytes = intdiv($bits, 8);
    $restbits  = $bits % 8;

    if ($helebytes > 0 && !hash_equals(substr($netBin, 0, $helebytes), substr($ipBin, 0, $helebytes))) {
        return false;
    }
    if ($restbits === 0) {
        return true;
    }
    $masker = ~((1 << (8 - $restbits)) - 1) & 0xFF;

    return (ord($netBin[$helebytes]) & $masker) === (ord($ipBin[$helebytes]) & $masker);
}

/**
 * Is dit een bruikbaar los IP-adres of CIDR-bereik? Gebruikt door setup.php om
 * een typefout in TRUSTED_PROXIES te weigeren in plaats van hem stil te
 * accepteren — een te ruim of onleesbaar bereik is hier het gevaarlijkst.
 */
function geldig_ip_of_bereik(string $waarde): bool
{
    $waarde = trim($waarde);
    if ($waarde === '') {
        return false;
    }

    if (!str_contains($waarde, '/')) {
        return @inet_pton($waarde) !== false;
    }

    [$net, $bits] = explode('/', $waarde, 2);
    $netBin = @inet_pton(trim($net));
    $bits   = trim($bits);
    if ($netBin === false || $bits === '' || !ctype_digit($bits)) {
        return false;
    }

    return (int)$bits >= 0 && (int)$bits <= strlen($netBin) * 8;
}

/** Staat dit adres in TRUSTED_PROXIES? */
function is_vertrouwde_proxy(string $ip): bool
{
    if ($ip === '') {
        return false;
    }
    foreach (vertrouwde_proxies() as $bereik) {
        if (ip_in_bereik($ip, $bereik)) {
            return true;
        }
    }
    return false;
}

// ─── Client-informatie ───────────────────────────────────────────────────────
/**
 * Het IP-adres van de bezoeker.
 *
 * Zonder proxy is dat gewoon REMOTE_ADDR. Staat er een proxy voor die in
 * TRUSTED_PROXIES is opgegeven, dan lezen we X-Forwarded-For van rechts naar
 * links: die lijst groeit aan de rechterkant aan, dus de laatste waarde is door
 * onze eigen proxy gezet en de waarden daarvóór zijn steeds minder betrouwbaar.
 * We slaan de adressen van onze eigen proxy's over en nemen het eerste adres
 * daarbuiten. Alles links daarvan heeft de bezoeker zelf kunnen verzinnen en
 * negeren we dus.
 *
 * Is REMOTE_ADDR géén vertrouwde proxy, dan kijken we niet naar de header:
 * dan praat de bezoeker rechtstreeks met ons en is REMOTE_ADDR de waarheid.
 */
function client_ip(): string
{
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');

    if ($remote === '' || !is_vertrouwde_proxy($remote)) {
        return $remote;
    }

    $doorgestuurd = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($doorgestuurd === '') {
        return $remote;
    }

    foreach (array_reverse(array_map('trim', explode(',', $doorgestuurd))) as $kandidaat) {
        // Een poort of vierkante haken eromheen (IPv6) hoort er niet bij.
        $kandidaat = trim($kandidaat, '[]');
        if ($kandidaat === '' || @inet_pton($kandidaat) === false) {
            continue;                       // onzin: overslaan, niet vertrouwen
        }
        if (is_vertrouwde_proxy($kandidaat)) {
            continue;                       // onze eigen proxy: doorlopen
        }
        return $kandidaat;
    }

    return $remote;
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
