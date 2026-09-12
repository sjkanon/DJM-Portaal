<?php

/**
 * DJM Portaal — installatiewizard.
 *
 * Stap 1  omgevingscontrole (PHP, extensies, schrijfrechten)
 * Stap 2  .env invullen en wegschrijven
 * Stap 3  databaseverbinding testen en db.sql uitvoeren
 * Stap 4  eerste beheerder aanmaken
 * Stap 5  afronden
 *
 * Beveiliging: zodra er een actieve beheerder in de database staat doet dit
 * bestand niets meer, tenzij er in de projectroot een bestand
 * `setup.toegestaan` staat. Verwijder setup.php na de installatie.
 *
 * Dit bestand gebruikt met opzet niet includes/layout.php of admin/includes/layout.php:
 * die verwachten een werkende database, en die is er tijdens de installatie nog niet.
 * Het <head>-blok komt wél uit includes/opmaak.php; dat werkt zonder database,
 * zolang er een vaste kleur wordt meegegeven in plaats van de ingestelde.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/opmaak.php';

ensure_session_started();
stuur_security_headers();

const SETUP_ONTGRENDEL_BESTAND = 'setup.toegestaan';

// ─── Hulpfuncties ────────────────────────────────────────────────────────────

/** Eigen CSRF-token; auth.php wordt hier bewust niet gebruikt. */
function setup_csrf_token(): string
{
    if (empty($_SESSION['setup_csrf'])) {
        $_SESSION['setup_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['setup_csrf'];
}

function setup_csrf_field(): string
{
    return '<input type="hidden" name="setup_csrf" value="' . h(setup_csrf_token()) . '">';
}

function setup_vereis_csrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }
    $ingestuurd = (string)($_POST['setup_csrf'] ?? '');
    if ($ingestuurd === '' || !hash_equals((string)($_SESSION['setup_csrf'] ?? ''), $ingestuurd)) {
        http_response_code(419);
        exit('Sessie verlopen. Ververs de pagina en probeer het opnieuw.');
    }
}

function setup_ontgrendeld(): bool
{
    return is_file(APP_ROOT . '/' . SETUP_ONTGRENDEL_BESTAND);
}

/** Aantal actieve beheerders; -1 als de tabel (nog) niet bestaat. */
function setup_aantal_beheerders(): int
{
    if (!tabel_bestaat('beheerders')) {
        return -1;
    }
    try {
        return (int)db()->query('SELECT COUNT(*) FROM beheerders WHERE actief = 1')->fetchColumn();
    } catch (Throwable $e) {
        return -1;
    }
}

/** De installatie is voltooid zodra er minstens één actieve beheerder is. */
function setup_installatie_voltooid(): bool
{
    return setup_aantal_beheerders() > 0;
}

/** Verbindingstest los van db(), zodat de foutmelding bruikbaar is. */
function setup_db_test(): array
{
    try {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
        new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return ['ok' => true, 'fout' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'fout' => $e->getMessage()];
    }
}

/**
 * Splitst db.sql in losse statements.
 *
 * Bewust simpel gehouden: commentaarregels die met `--` beginnen vallen weg en
 * er wordt gesplitst op een puntkomma aan het einde van een regel. Het schema
 * bevat geen puntkomma's binnen tekstwaarden (de standaardinstellingen bevatten
 * wel `\n`, maar dat zijn twee letterlijke tekens en geen regeleinde), dus dit
 * is voldoende. Lege stukken worden overgeslagen.
 */
function setup_sql_statements(string $sql): array
{
    $sql = str_replace(["\r\n", "\r"], "\n", $sql);

    $regels = [];
    foreach (explode("\n", $sql) as $regel) {
        if (str_starts_with(ltrim($regel), '--')) {
            continue;
        }
        $regels[] = $regel;
    }
    $sql = implode("\n", $regels);

    $delen = preg_split('/;[ \t]*\n/', $sql) ?: [];

    $statements = [];
    foreach ($delen as $deel) {
        $deel = trim(rtrim(trim($deel), ';'));
        if ($deel === '') {
            continue;
        }
        $statements[] = $deel;
    }
    return $statements;
}

/** Korte omschrijving van een statement voor de terugmelding. */
function setup_statement_label(string $statement): string
{
    $eenRegel = trim(preg_replace('/\s+/', ' ', $statement) ?? $statement);
    if (preg_match('/^CREATE TABLE(?: IF NOT EXISTS)?\s+`?([a-z0-9_]+)`?/i', $eenRegel, $m)) {
        return 'Tabel ' . $m[1];
    }
    if (preg_match('/^INSERT INTO\s+`?([a-z0-9_]+)`?/i', $eenRegel, $m)) {
        return 'Standaardwaarden in ' . $m[1];
    }
    return mb_substr($eenRegel, 0, 60) . (mb_strlen($eenRegel) > 60 ? '…' : '');
}

function setup_aantal_tabellen(): int
{
    try {
        return count(db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        return 0;
    }
}

function setup_map_schrijfbaar(string $pad): bool
{
    return is_dir($pad) && is_writable($pad);
}

function setup_stap_url(int $stap): string
{
    return 'setup.php?stap=' . $stap;
}


// ─── Omgevingsbestand (.env) ─────────────────────────────────────────────────

function setup_env_pad(): string
{
    return APP_ROOT . '/.env';
}

/**
 * De sleutels die de wizard beheert, gegroepeerd zoals ze in .env komen te
 * staan. Sleutels die iemand er zelf bij heeft gezet blijven bewaard; die
 * komen onderaan onder "Overige" terecht.
 *
 * @return array<string, array{uitleg: string[], sleutels: string[]}>
 */
function setup_env_blokken(): array
{
    return [
        'Applicatie' => [
            'uitleg'   => [],
            'sleutels' => ['APP_NAME', 'APP_URL', 'DEBUG'],
        ],
        'Database' => [
            'uitleg'   => ['DB_SOCKET heeft voorrang op DB_HOST en DB_PORT.'],
            'sleutels' => ['DB_HOST', 'DB_PORT', 'DB_SOCKET', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_CHARSET'],
        ],
        'Beveiliging' => [
            'uitleg'   => [
                'OTP_PEPPER hasht de inlogcodes, APP_KEY ondertekent downloadlinks',
                'en onthoud-cookies. Bewaar ze: wijzigen maakt lopende codes en',
                'links ongeldig.',
            ],
            'sleutels' => ['OTP_PEPPER', 'APP_KEY'],
        ],
        'Opslag' => [
            'uitleg'   => ['Leeg laten betekent de map opslag/ in dit project.'],
            'sleutels' => ['OPSLAG_PAD'],
        ],
        'Uitlevering van downloads' => [
            'uitleg'   => ['auto | xaccel (nginx) | xsendfile (Apache) | php'],
            'sleutels' => ['DELIVERY_MODE', 'XACCEL_PREFIX'],
        ],
        'Microsoft Graph' => [
            'uitleg'   => ['Kan ook later via Beheer > Instellingen.'],
            'sleutels' => ['GRAPH_TENANT_ID', 'GRAPH_CLIENT_ID', 'GRAPH_CLIENT_SECRET', 'MAIL_VAN_ADRES', 'MAIL_VAN_NAAM'],
        ],
        'SMTP (terugvaloptie)' => [
            'uitleg'   => ['Alleen nodig als de e-mailmethode op smtp staat.'],
            'sleutels' => ['SMTP_HOST', 'SMTP_POORT', 'SMTP_BEVEILIGING', 'SMTP_GEBRUIKER', 'SMTP_WACHTWOORD'],
        ],
    ];
}

/** @return string[] Alle sleutels die de wizard beheert, op volgorde. */
function setup_env_sleutels(): array
{
    $alles = [];
    foreach (setup_env_blokken() as $blok) {
        foreach ($blok['sleutels'] as $sleutel) {
            $alles[] = $sleutel;
        }
    }
    return $alles;
}

/**
 * Velden die nooit teruggetoond worden in het formulier. Laat de gebruiker er
 * een leeg, dan blijft de bestaande waarde staan. Zo hoeft een wachtwoord niet
 * in de HTML te belanden om de wizard te kunnen herhalen.
 *
 * @return string[]
 */
function setup_env_geheim(): array
{
    return ['DB_PASS', 'GRAPH_CLIENT_SECRET', 'SMTP_WACHTWOORD', 'APP_KEY', 'OTP_PEPPER'];
}

/** De huidige inhoud van .env, of een lege array als het bestand er nog niet is. */
function setup_env_bestaand(): array
{
    return env_parse(setup_env_pad());
}

/**
 * Schrijft het complete .env-bestand uit.
 *
 * @param array<string, string> $waarden De beheerde sleutels.
 * @param array<string, string> $overige Sleutels die al in .env stonden en die
 *                                       de wizard niet kent.
 */
function setup_env_samenstellen(array $waarden, array $overige = []): string
{
    $regels = [
        '# ─── DJM Portaal — omgevingsconfiguratie ─────────────────────────────────',
        '# Geschreven door setup.php op ' . date('d-m-Y H:i'),
        '# Dit bestand hoort NIET in git en mag nooit publiek te downloaden zijn.',
    ];

    foreach (setup_env_blokken() as $kop => $blok) {
        $regels[] = '';
        $regels[] = '# ─── ' . $kop . ' ' . str_repeat('─', max(1, 68 - mb_strlen($kop)));
        foreach ($blok['uitleg'] as $uitleg) {
            $regels[] = '# ' . $uitleg;
        }
        foreach ($blok['sleutels'] as $sleutel) {
            $regels[] = env_regel($sleutel, (string)($waarden[$sleutel] ?? ''));
        }
    }

    if ($overige) {
        $regels[] = '';
        $regels[] = '# ─── Overige (stond al in .env en is ongemoeid gelaten) ──────────────────';
        foreach ($overige as $sleutel => $waarde) {
            $regels[] = env_regel($sleutel, (string)$waarde);
        }
    }

    return implode("\n", $regels) . "\n";
}

/**
 * Zet de inhoud op schijf: eerst een reservekopie, dan een tijdelijk bestand
 * met rechten 0600, en dat pas met rename() op zijn plaats. rename() is binnen
 * dezelfde map één ondeelbare stap, dus een half geschreven .env kan niet
 * bestaan — ook niet als PHP er middenin mee ophoudt.
 *
 * @param array<string, string> $waarden De waarden waarmee $inhoud is opgebouwd,
 *                                       om achteraf te kunnen terugcontroleren.
 * @return array{ok: bool, fout: string, backup: string}
 */
function setup_env_schrijven(string $inhoud, array $waarden): array
{
    $pad      = setup_env_pad();
    $map      = dirname($pad);
    $bestaat  = is_file($pad);
    $mislukt  = static fn(string $fout): array => ['ok' => false, 'fout' => $fout, 'backup' => ''];

    if ($bestaat && !is_writable($pad)) {
        return $mislukt('Het bestand .env bestaat al, maar de webserver mag er niet in schrijven.');
    }
    if (!is_writable($map)) {
        return $mislukt('De projectmap ' . $map . ' is niet beschrijfbaar voor de webserver.');
    }

    // Reservekopie. Lukt die niet, dan stoppen we: liever geen wijziging dan
    // een oude configuratie die we niet kunnen terugzetten.
    $backup = '';
    if ($bestaat) {
        $basis = $pad . '.backup-' . date('Ymd-His');
        $backup = $basis;
        for ($n = 2; file_exists($backup) && $n < 100; $n++) {
            $backup = $basis . '-' . $n;
        }
        if (!@copy($pad, $backup)) {
            return $mislukt('De reservekopie ' . basename($backup) . ' kon niet worden gemaakt.');
        }
        @chmod($backup, 0600);
    }

    $tijdelijk = $map . '/.env.tmp-' . bin2hex(random_bytes(6));
    if (@file_put_contents($tijdelijk, $inhoud, LOCK_EX) !== strlen($inhoud)) {
        @unlink($tijdelijk);
        return $mislukt('Het tijdelijke bestand kon niet worden weggeschreven.');
    }
    @chmod($tijdelijk, 0600);

    if (!@rename($tijdelijk, $pad)) {
        @unlink($tijdelijk);
        return $mislukt('Het tijdelijke bestand kon niet naar .env worden hernoemd.');
    }
    @chmod($pad, 0600);
    clearstatcache(true, $pad);

    // Teruglezen: pas als de parser er weer precies uithaalt wat erin ging, is
    // het echt gelukt. Dit vangt een halfvolle schijf, een rare tekenset of een
    // waarde die de aanhalingstekens breekt allemaal in één keer af.
    $terug = env_parse($pad);
    foreach ($waarden as $sleutel => $waarde) {
        if (($terug[$sleutel] ?? null) !== $waarde) {
            return $mislukt('Het bestand is geschreven, maar ' . $sleutel
                . ' komt er anders weer uit. De reservekopie staat nog in de projectmap.');
        }
    }

    return ['ok' => true, 'fout' => '', 'backup' => $backup === '' ? '' : basename($backup)];
}

// ─── Databasecontrole met opgegeven gegevens ─────────────────────────────────

/**
 * Probeert verbinding te maken. Zonder $metDatabase wordt er verbonden met de
 * server zelf, zonder database te kiezen — zo is te zien of de inloggegevens
 * kloppen terwijl de database nog niet bestaat.
 *
 * @param array<string, string> $g
 * @return array{ok: bool, fout: string, onbekendeDatabase: bool}
 */
function setup_db_verbinden(array $g, bool $metDatabase = true): array
{
    $dsn = mysql_dsn([
        'host'    => $g['DB_HOST'] ?? '',
        'port'    => $g['DB_PORT'] ?? '',
        'socket'  => $g['DB_SOCKET'] ?? '',
        'dbname'  => $metDatabase ? ($g['DB_NAME'] ?? '') : '',
        'charset' => $g['DB_CHARSET'] ?? 'utf8mb4',
    ]);

    try {
        new PDO($dsn, (string)($g['DB_USER'] ?? ''), (string)($g['DB_PASS'] ?? ''), [
            PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            // Zonder tijdslimiet blijft de wizard hangen op een adres dat niet
            // antwoordt, tot PHP zelf de stekker eruit trekt.
            PDO::ATTR_TIMEOUT          => 5,
        ]);
        return ['ok' => true, 'fout' => '', 'onbekendeDatabase' => false];
    } catch (Throwable $e) {
        $melding = $e->getMessage();
        return [
            'ok'                => false,
            'fout'              => $melding,
            'onbekendeDatabase' => str_contains($melding, '[1049]') || str_contains($melding, 'Unknown database'),
        ];
    }
}

/**
 * Maakt de database aan met de opgegeven gegevens.
 *
 * @param array<string, string> $g
 * @return array{ok: bool, fout: string}
 */
function setup_db_database_aanmaken(array $g): array
{
    $naam = (string)($g['DB_NAME'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_]+$/', $naam)) {
        return ['ok' => false, 'fout' => 'De databasenaam bevat tekens die hier niet zijn toegestaan.'];
    }
    $charset = (string)($g['DB_CHARSET'] ?? 'utf8mb4');
    if (!preg_match('/^[A-Za-z0-9_]+$/', $charset)) {
        $charset = 'utf8mb4';
    }

    $dsn = mysql_dsn([
        'host'    => $g['DB_HOST'] ?? '',
        'port'    => $g['DB_PORT'] ?? '',
        'socket'  => $g['DB_SOCKET'] ?? '',
        'dbname'  => '',
        'charset' => $charset,
    ]);

    try {
        $pdo = new PDO($dsn, (string)($g['DB_USER'] ?? ''), (string)($g['DB_PASS'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $naam . '` CHARACTER SET ' . $charset);
        return ['ok' => true, 'fout' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'fout' => $e->getMessage()];
    }
}

// ─── Mappen ──────────────────────────────────────────────────────────────────

/**
 * Maakt de map zo nodig aan en controleert met een echte schrijfpoging of de
 * webserver erin kan. is_writable() kijkt alleen naar de rechtenbits en zegt
 * onder SELinux, een read-only mount of een ACL nog wel eens ten onrechte ja.
 *
 * @return array{ok: bool, fout: string, aangemaakt: bool}
 */
function setup_map_klaarmaken(string $pad): array
{
    $aangemaakt = false;
    if (!is_dir($pad)) {
        if (!@mkdir($pad, 0775, true) && !is_dir($pad)) {
            return ['ok' => false, 'fout' => 'De map bestaat niet en kon niet worden aangemaakt.', 'aangemaakt' => false];
        }
        $aangemaakt = true;
    }

    $proef = rtrim($pad, '/') . '/.schrijfproef-' . bin2hex(random_bytes(4));
    if (@file_put_contents($proef, 'ok') === false) {
        return ['ok' => false, 'fout' => 'De map bestaat, maar de webserver kan er niet in schrijven.', 'aangemaakt' => $aangemaakt];
    }
    @unlink($proef);

    return ['ok' => true, 'fout' => '', 'aangemaakt' => $aangemaakt];
}

// ─── Is .env van buitenaf te downloaden? ─────────────────────────────────────

/**
 * Haalt het eigen .env op via HTTP. Komt de inhoud terug, dan staat het
 * databasewachtwoord op straat en moet de webserverconfiguratie eerst worden
 * gerepareerd.
 *
 * De TLS-controle staat hier uit: dit is een verzoek van de server aan
 * zichzelf, waarbij een zelfondertekend of nog niet uitgerold certificaat
 * gewoon voorkomt. Er wordt niets vertrouwelijks verstuurd; we kijken alleen
 * naar wat er terugkomt.
 *
 * @return array{status: string, tekst: string}  status: open | dicht | onbekend
 */
function setup_env_bereikbaar(): array
{
    if (!function_exists('curl_init')) {
        return ['status' => 'onbekend', 'tekst' => 'De PHP-extensie curl ontbreekt, dus deze controle kan niet automatisch.'];
    }

    $adres = url('.env');
    $ch    = @curl_init($adres);
    if ($ch === false) {
        return ['status' => 'onbekend', 'tekst' => 'De controle kon niet worden gestart.'];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_USERAGENT      => 'DJM Portaal setup',
    ]);
    $inhoud = curl_exec($ch);
    $code   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $fout   = curl_error($ch);
    curl_close($ch);

    if ($inhoud === false || $code === 0) {
        return [
            'status' => 'onbekend',
            'tekst'  => 'De server kon zichzelf niet bereiken op ' . $adres . ' (' . ($fout ?: 'geen antwoord')
                . '). Controleer het dan zelf even in de browser.',
        ];
    }

    $tekst = (string)$inhoud;
    if ($code === 200 && (str_contains($tekst, 'DB_PASS') || str_contains($tekst, 'APP_KEY') || str_contains($tekst, 'DB_NAME'))) {
        return ['status' => 'open', 'tekst' => 'Het bestand .env is publiek te downloaden via ' . $adres . '.'];
    }
    if ($code === 200) {
        return [
            'status' => 'onbekend',
            'tekst'  => $adres . ' geeft een 200, maar niet de inhoud van .env. Waarschijnlijk vangt een'
                . ' rewrite het verzoek op. Controleer het zelf even in de browser.',
        ];
    }

    return ['status' => 'dicht', 'tekst' => 'De webserver weigert ' . $adres . ' (HTTP ' . $code . ').'];
}

// ─── Invoer van stap 2 ───────────────────────────────────────────────────────

/**
 * Haalt één veld uit $_POST.
 *
 * Regeleinden en nulbytes gaan er altijd uit: die kunnen in een .env-regel niet
 * voorkomen en zijn de manier om er een extra regel in te smokkelen. Bij
 * gewone velden gaan ook de overige stuurtekens eruit en wordt er getrimd; bij
 * wachtwoorden niet, want daar kan een spatie aan het eind bij horen.
 */
function setup_invoer(string $veld, int $maxLengte = 255, bool $geheim = false): string
{
    $waarde = (string)($_POST[$veld] ?? '');
    $waarde = str_replace(["\r", "\n", "\0"], '', $waarde);
    if (!$geheim) {
        $waarde = preg_replace('/[\x00-\x1F\x7F]/', '', $waarde) ?? $waarde;
        $waarde = trim($waarde);
    }
    return mb_substr($waarde, 0, $maxLengte);
}

/**
 * Controleert alles wat in stap 2 is ingevuld.
 *
 * Lege wachtwoordvelden betekenen "laat staan wat er al is"; daarom komt het
 * bestaande .env er als tweede invoer bij.
 *
 * @param array<string, string> $bestaand
 * @return array{waarden: array<string,string>, fouten: array<string,string>, waarschuwingen: string[]}
 */
function setup_env_valideren(array $bestaand): array
{
    $waarden        = [];
    $fouten         = [];
    $waarschuwingen = [];

    // ── Applicatie ──
    $waarden['APP_NAME'] = setup_invoer('APP_NAME', 150) ?: 'Deventer Jeugd Musical';

    $appUrl = rtrim(setup_invoer('APP_URL', 255), '/');
    if ($appUrl === '') {
        $fouten['APP_URL'] = 'Vul het adres in waarop het portaal straks bereikbaar is.';
    } elseif (!preg_match('~^https?://[A-Za-z0-9][A-Za-z0-9.\-]*(:\d{1,5})?(/[A-Za-z0-9._~\-]+)*$~', $appUrl)) {
        $fouten['APP_URL'] = 'Dit is geen geldig adres. Begin met https:// en laat de slash aan het eind weg.';
    } else {
        $host = strtolower((string)(parse_url($appUrl, PHP_URL_HOST) ?? ''));
        if (str_starts_with($appUrl, 'http://') && !in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            $waarschuwingen[] = 'Het adres begint met http:// in plaats van https://. Inlogcodes, '
                . 'wachtwoorden en downloadlinks gaan dan onversleuteld over de lijn.';
        }
        $huidigeHost = strtolower(veilige_host());
        $poort       = parse_url($appUrl, PHP_URL_PORT);
        $volledig    = $host . ($poort ? ':' . $poort : '');
        if ($huidigeHost !== 'localhost' && $volledig !== '' && $volledig !== $huidigeHost) {
            $waarschuwingen[] = 'U bekijkt deze pagina op ' . $huidigeHost . ', maar APP_URL wijst naar '
                . $host . '. Dat mag, maar klopt het adres niet, dan kan straks niemand inloggen.';
        }
    }
    $waarden['APP_URL'] = $appUrl;

    $waarden['DEBUG'] = isset($_POST['DEBUG']) ? 'true' : 'false';
    if ($waarden['DEBUG'] === 'true') {
        $waarschuwingen[] = 'DEBUG staat aan. Foutmeldingen komen dan op het scherm, inclusief paden '
            . 'en soms databasegegevens. Zet dit uit zodra het portaal in gebruik is.';
    }

    // ── Database ──
    $waarden['DB_SOCKET'] = setup_invoer('DB_SOCKET', 190);
    if ($waarden['DB_SOCKET'] !== '' && !str_starts_with($waarden['DB_SOCKET'], '/')) {
        $fouten['DB_SOCKET'] = 'Een socketpad begint met een slash, bijvoorbeeld /var/run/mysqld/mysqld.sock.';
    }

    $host = setup_invoer('DB_HOST', 190);
    $poort = setup_invoer('DB_PORT', 5);
    // Een veelgemaakte vergissing: host en poort samen in één veld.
    if (preg_match('/^(.+):(\d{1,5})$/', $host, $m)) {
        $host  = $m[1];
        $poort = $poort === '' ? $m[2] : $poort;
    }
    if ($waarden['DB_SOCKET'] === '') {
        if ($host === '') {
            $fouten['DB_HOST'] = 'Vul de databaseserver in, meestal localhost.';
        } elseif (!preg_match('/^[A-Za-z0-9.\-]+$/', $host) && @inet_pton($host) === false) {
            $fouten['DB_HOST'] = 'Dit is geen geldige servernaam of IP-adres.';
        }
    }
    if ($poort !== '' && (!ctype_digit($poort) || (int)$poort < 1 || (int)$poort > 65535)) {
        $fouten['DB_PORT'] = 'Een poortnummer is een getal van 1 tot en met 65535.';
    }
    $waarden['DB_HOST'] = $host;
    $waarden['DB_PORT'] = $poort;

    $waarden['DB_NAME'] = setup_invoer('DB_NAME', 64);
    if ($waarden['DB_NAME'] === '') {
        $fouten['DB_NAME'] = 'Vul de naam van de database in.';
    } elseif (!preg_match('/^[A-Za-z0-9_]+$/', $waarden['DB_NAME'])) {
        $fouten['DB_NAME'] = 'Gebruik alleen letters, cijfers en liggende streepjes.';
    }

    $waarden['DB_USER'] = setup_invoer('DB_USER', 80);
    if ($waarden['DB_USER'] === '') {
        $fouten['DB_USER'] = 'Vul de databasegebruiker in.';
    }

    // Leeg = ongewijzigd laten.
    $wachtwoord = setup_invoer('DB_PASS', 255, true);
    $waarden['DB_PASS'] = $wachtwoord !== '' ? $wachtwoord : (string)($bestaand['DB_PASS'] ?? '');
    if ($waarden['DB_PASS'] === '') {
        $waarschuwingen[] = 'Het databasewachtwoord is leeg. Dat kan lokaal prima werken, maar op een '
            . 'gedeelde server hoort er een wachtwoord op.';
    }

    $charset = setup_invoer('DB_CHARSET', 20) ?: 'utf8mb4';
    $waarden['DB_CHARSET'] = in_array($charset, ['utf8mb4', 'utf8mb3', 'utf8'], true) ? $charset : 'utf8mb4';

    // ── Beveiliging ──
    $vernieuwen = isset($_POST['sleutels_vernieuwen']);
    foreach (['APP_KEY', 'OTP_PEPPER'] as $sleutel) {
        $huidig = (string)($bestaand[$sleutel] ?? '');
        $bruikbaar = strlen($huidig) >= 32;
        if ($vernieuwen || !$bruikbaar) {
            $waarden[$sleutel] = bin2hex(random_bytes(32));
            if ($huidig !== '') {
                $waarschuwingen[] = $sleutel . ' is vervangen door een nieuwe sleutel.'
                    . ($sleutel === 'APP_KEY'
                        ? ' Lopende downloadlinks en onthoud-cookies zijn daarmee vervallen.'
                        : ' Openstaande inlogcodes zijn daarmee vervallen.');
            }
        } else {
            $waarden[$sleutel] = $huidig;
        }
    }

    // ── Opslag ──
    $opslag = rtrim(setup_invoer('OPSLAG_PAD', 255), '/');
    if ($opslag !== '' && !str_starts_with($opslag, '/')) {
        $fouten['OPSLAG_PAD'] = 'Geef een volledig pad op dat met een slash begint, of laat het veld leeg.';
    }
    $waarden['OPSLAG_PAD'] = $opslag;

    $doelmap = $opslag !== '' ? $opslag : APP_ROOT . '/opslag';
    if (!isset($fouten['OPSLAG_PAD'])) {
        $resultaat = setup_map_klaarmaken($doelmap);
        if (!$resultaat['ok']) {
            $fouten['OPSLAG_PAD'] = $resultaat['fout'] . ' Voer op de server uit: mkdir -p '
                . $doelmap . ' && chown ' . setup_webserver_gebruiker() . ' ' . $doelmap;
        } elseif (str_starts_with($doelmap . '/', APP_ROOT . '/')) {
            $waarschuwingen[] = 'De opslagmap staat binnen de webroot. Dat werkt, maar de videobestanden '
                . 'zijn dan alleen door .htaccess afgeschermd. Veiliger is een map daarbuiten, '
                . 'bijvoorbeeld /var/djm-opslag.';
        }
    }

    // ── Uitlevering ──
    $modus = strtolower(setup_invoer('DELIVERY_MODE', 20));
    $waarden['DELIVERY_MODE'] = in_array($modus, ['auto', 'xaccel', 'xsendfile', 'php'], true) ? $modus : 'auto';

    $prefix = setup_invoer('XACCEL_PREFIX', 100) ?: '/beveiligd/';
    if (!str_starts_with($prefix, '/') || !str_ends_with($prefix, '/')) {
        $fouten['XACCEL_PREFIX'] = 'Dit pad begint én eindigt met een slash, bijvoorbeeld /beveiligd/.';
    }
    $waarden['XACCEL_PREFIX'] = $prefix;

    // ── Microsoft Graph en SMTP (mag allemaal leeg blijven) ──
    $waarden['GRAPH_TENANT_ID'] = setup_invoer('GRAPH_TENANT_ID', 100);
    $waarden['GRAPH_CLIENT_ID'] = setup_invoer('GRAPH_CLIENT_ID', 100);

    $secret = setup_invoer('GRAPH_CLIENT_SECRET', 255, true);
    $waarden['GRAPH_CLIENT_SECRET'] = $secret !== '' ? $secret : (string)($bestaand['GRAPH_CLIENT_SECRET'] ?? '');

    $vanAdres = strtolower(setup_invoer('MAIL_VAN_ADRES', 190));
    if ($vanAdres !== '' && !geldig_email($vanAdres)) {
        $fouten['MAIL_VAN_ADRES'] = 'Dit is geen geldig e-mailadres.';
    }
    $waarden['MAIL_VAN_ADRES'] = $vanAdres;
    $waarden['MAIL_VAN_NAAM']  = setup_invoer('MAIL_VAN_NAAM', 150);

    $waarden['SMTP_HOST'] = setup_invoer('SMTP_HOST', 190);
    $smtpPoort = setup_invoer('SMTP_POORT', 5);
    if ($smtpPoort !== '' && (!ctype_digit($smtpPoort) || (int)$smtpPoort < 1 || (int)$smtpPoort > 65535)) {
        $fouten['SMTP_POORT'] = 'Een poortnummer is een getal van 1 tot en met 65535.';
    }
    $waarden['SMTP_POORT'] = $smtpPoort;

    $beveiliging = strtolower(setup_invoer('SMTP_BEVEILIGING', 10));
    $waarden['SMTP_BEVEILIGING'] = in_array($beveiliging, ['tls', 'ssl', 'geen'], true) ? $beveiliging : 'tls';
    $waarden['SMTP_GEBRUIKER']   = setup_invoer('SMTP_GEBRUIKER', 190);

    $smtpWachtwoord = setup_invoer('SMTP_WACHTWOORD', 255, true);
    $waarden['SMTP_WACHTWOORD'] = $smtpWachtwoord !== '' ? $smtpWachtwoord : (string)($bestaand['SMTP_WACHTWOORD'] ?? '');

    return ['waarden' => $waarden, 'fouten' => $fouten, 'waarschuwingen' => $waarschuwingen];
}

/** Naam van de gebruiker waaronder PHP draait, voor de chown-tips. */
function setup_webserver_gebruiker(): string
{
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $info = @posix_getpwuid(posix_geteuid());
        if (is_array($info) && !empty($info['name'])) {
            return (string)$info['name'];
        }
    }
    return get_current_user() ?: 'www-data';
}

// ─── Formuliervelden ─────────────────────────────────────────────────────────

/**
 * Eén invoerveld. $opties: type, uitleg, plaatshouder, maxlength, fout,
 * verplicht, autocomplete, breedte (Bootstrap-kolommen).
 */
function setup_veld(string $naam, string $label, string $waarde, array $opties = []): void
{
    $type   = $opties['type'] ?? 'text';
    $fout   = (string)($opties['fout'] ?? '');
    $id     = 'veld_' . strtolower($naam);
    ?>
    <div class="col-md-<?= (int)($opties['breedte'] ?? 12) ?>">
        <label class="form-label" for="<?= h($id) ?>"><?= h($label) ?></label>
        <input class="form-control <?= $fout !== '' ? 'is-invalid' : '' ?>"
               type="<?= h($type) ?>" id="<?= h($id) ?>" name="<?= h($naam) ?>"
               value="<?= h($waarde) ?>"
               maxlength="<?= (int)($opties['maxlength'] ?? 255) ?>"
               <?php if (!empty($opties['plaatshouder'])): ?>placeholder="<?= h((string)$opties['plaatshouder']) ?>"<?php endif; ?>
               <?php if (!empty($opties['verplicht'])): ?>required<?php endif; ?>
               autocomplete="<?= h((string)($opties['autocomplete'] ?? 'off')) ?>">
        <?php if ($fout !== ''): ?>
            <div class="invalid-feedback d-block"><?= h($fout) ?></div>
        <?php endif; ?>
        <?php if (!empty($opties['uitleg'])): ?>
            <div class="form-text"><?= $opties['uitleg'] ?></div>
        <?php endif; ?>
    </div>
    <?php
}

/** Eén keuzelijst. $keuzes is waarde => label. */
function setup_keuze(string $naam, string $label, string $waarde, array $keuzes, array $opties = []): void
{
    $id = 'veld_' . strtolower($naam);
    ?>
    <div class="col-md-<?= (int)($opties['breedte'] ?? 12) ?>">
        <label class="form-label" for="<?= h($id) ?>"><?= h($label) ?></label>
        <select class="form-select" id="<?= h($id) ?>" name="<?= h($naam) ?>">
            <?php foreach ($keuzes as $optie => $tekst): ?>
                <option value="<?= h((string)$optie) ?>" <?= (string)$optie === $waarde ? 'selected' : '' ?>>
                    <?= h((string)$tekst) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if (!empty($opties['uitleg'])): ?>
            <div class="form-text"><?= $opties['uitleg'] ?></div>
        <?php endif; ?>
    </div>
    <?php
}

/** Eén vinkje. */
function setup_vinkje(string $naam, string $label, bool $aan, string $uitleg = ''): void
{
    $id = 'veld_' . strtolower($naam);
    ?>
    <div class="col-12">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="<?= h($id) ?>" name="<?= h($naam) ?>"
                   value="1" <?= $aan ? 'checked' : '' ?>>
            <label class="form-check-label" for="<?= h($id) ?>"><?= h($label) ?></label>
            <?php if ($uitleg !== ''): ?>
                <div class="form-text"><?= $uitleg ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

// ─── Opmaak ──────────────────────────────────────────────────────────────────

function setup_kop(string $titel, int $huidigeStap = 0): void
{
    $stappen = [1 => 'Controle', 2 => 'Instellen', 3 => 'Database', 4 => 'Beheerder', 5 => 'Klaar'];
    ?>
<!doctype html>
<html lang="nl">
<head>
    <?php djm_head($titel . ' — installatie DJM Portaal'); ?>
</head>
<body class="djm-setup">
<div class="container setup-kaart">
    <h1 class="h3 mb-1">DJM Portaal — installatie</h1>
    <p class="text-muted">Versie <?= h(APP_VERSION) ?></p>

    <?php if ($huidigeStap > 0): ?>
        <ul class="nav nav-pills mb-4">
            <?php foreach ($stappen as $nummer => $naam): ?>
                <li class="nav-item">
                    <span class="nav-link <?= $nummer === $huidigeStap ? 'active' : ($nummer < $huidigeStap ? 'text-success' : 'text-muted') ?>">
                        <?= $nummer ?>. <?= h($naam) ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body p-4">
    <?php
}

function setup_voet(): void
{
    ?>
        </div>
    </div>
    <p class="text-muted small mt-3 mb-5">
        Volledige handleiding: <code>docs/INSTALLATIE.md</code> ·
        Microsoft Graph: <code>docs/GRAPH-SETUP.md</code>
    </p>
</div>
</body>
</html>
    <?php
}

/**
 * Eén regel in de controlelijst.
 *
 * $uitleg mag bewust HTML bevatten (<code>, <br>); alle dynamische delen worden
 * bij de aanroep al door h() gehaald. $tekst wordt hier wel geëscaped.
 */
function setup_punt(bool $ok, string $tekst, string $uitleg = ''): void
{
    ?>
    <li class="<?= $ok ? 'text-success' : 'text-danger' ?>">
        <strong><?= $ok ? '&#10003;' : '&#10007;' ?></strong>
        <span class="text-body"><?= h($tekst) ?></span>
        <?php if (!$ok && $uitleg !== ''): ?>
            <div class="small text-body-secondary ms-4"><?= $uitleg ?></div>
        <?php endif; ?>
    </li>
    <?php
}

// ─── Slot op de installatie ──────────────────────────────────────────────────

if (setup_installatie_voltooid() && !setup_ontgrendeld()) {
    setup_kop('Installatie voltooid');
    ?>
    <h2 class="h5">De installatie is al voltooid</h2>
    <p>
        Er staat al minstens één actieve beheerder in de database. Om te voorkomen dat iemand
        anders de installatie overneemt, doet <code>setup.php</code> nu niets meer.
    </p>
    <p class="mb-2">Wilt u de installatie tóch opnieuw draaien? Maak dan op de server een leeg
        bestand aan in de projectmap:</p>
    <pre>touch <?= h(APP_ROOT) ?>/<?= h(SETUP_ONTGRENDEL_BESTAND) ?></pre>
    <p class="small text-body-secondary">
        Verwijder dat bestand direct daarna weer. Beter nog: verwijder <code>setup.php</code>
        helemaal van de server zodra het portaal draait.
    </p>
    <a class="btn btn-primary" href="<?= h(url('admin/login.php')) ?>">Naar het beheerdersgedeelte</a>
    <a class="btn btn-outline-secondary" href="<?= h(url('index.php')) ?>">Naar het portaal</a>
    <?php
    setup_voet();
    exit;
}

// ─── Router ──────────────────────────────────────────────────────────────────

setup_vereis_csrf();

$stap   = (int)($_GET['stap'] ?? 1);
$actie  = (string)($_POST['actie'] ?? '');
$stap   = ($stap >= 1 && $stap <= 5) ? $stap : 1;

// Stap 2 — .env invullen en wegschrijven.
$envBestaand       = setup_env_bestaand();
$envWaarden        = [];
$envFouten         = [];
$envWaarschuwingen = [];
$envSchrijffout    = '';
$envHandmatig      = '';

if ($actie === 'env_opslaan' || $actie === 'env_testen') {
    $stap              = 2;
    $controle          = setup_env_valideren($envBestaand);
    $envWaarden        = $controle['waarden'];
    $envFouten         = $controle['fouten'];
    $envWaarschuwingen = $controle['waarschuwingen'];

    if (!$envFouten && $actie === 'env_opslaan') {
        // Sleutels die iemand zelf aan .env heeft toegevoegd blijven staan.
        $overige   = array_diff_key($envBestaand, array_flip(setup_env_sleutels()));
        $inhoud    = setup_env_samenstellen($envWaarden, $overige);
        $resultaat = setup_env_schrijven($inhoud, $envWaarden);

        if ($resultaat['ok']) {
            $tekst = 'De instellingen staan in .env.';
            if ($resultaat['backup'] !== '') {
                $tekst .= ' De vorige versie is bewaard als ' . $resultaat['backup'] . '.';
            }
            $_SESSION['setup_melding'] = [
                'soort'  => 'success',
                'tekst'  => $tekst,
                'punten' => $envWaarschuwingen,
            ];
            // Alleen doorlopen als de database ook echt antwoordt. Zo niet, dan
            // terug naar dit formulier — dat leest .env opnieuw in en laat de
            // foutmelding zien bij de gegevens die het probleem veroorzaken.
            $verbinding = setup_db_verbinden($envWaarden);
            header('Location: ' . setup_stap_url($verbinding['ok'] ? 3 : 2));
            exit;
        }

        $envSchrijffout = $resultaat['fout'];
        $envHandmatig   = $inhoud;
    }
}

// Stap 2 — de database alsnog aanmaken, met de gegevens die nu in .env staan.
if ($actie === 'database_aanmaken') {
    $stap      = 2;
    $resultaat = setup_db_database_aanmaken([
        'DB_HOST'    => DB_HOST,
        'DB_PORT'    => DB_PORT,
        'DB_SOCKET'  => DB_SOCKET,
        'DB_NAME'    => DB_NAME,
        'DB_USER'    => DB_USER,
        'DB_PASS'    => DB_PASS,
        'DB_CHARSET' => DB_CHARSET,
    ]);
    $_SESSION['setup_melding'] = $resultaat['ok']
        ? ['soort' => 'success', 'tekst' => 'De database ' . DB_NAME . ' is aangemaakt.', 'punten' => []]
        : ['soort' => 'danger', 'tekst' => 'De database kon niet worden aangemaakt: ' . $resultaat['fout'], 'punten' => []];
    header('Location: ' . setup_stap_url($resultaat['ok'] ? 3 : 2));
    exit;
}

// Controle of .env van buitenaf te downloaden is; kan vanaf stap 2 en stap 5.
if ($actie === 'env_bereikbaarheid') {
    $terug     = (int)($_POST['terug'] ?? 2);
    $terug     = ($terug >= 1 && $terug <= 5) ? $terug : 2;
    $resultaat = setup_env_bereikbaar();
    $soort     = match ($resultaat['status']) {
        'dicht' => 'success',
        'open'  => 'danger',
        default => 'warning',
    };
    if ($resultaat['status'] === 'open') {
        $resultaat['tekst'] .= ' Repareer dit vóór u verdergaat: het databasewachtwoord, APP_KEY en'
            . ' OTP_PEPPER liggen nu voor iedereen op straat. Onder Apache doet .htaccess dit;'
            . ' onder nginx staat het blok in docs/nginx.voorbeeld.conf.';
    }
    $_SESSION['setup_melding'] = ['soort' => $soort, 'tekst' => $resultaat['tekst'], 'punten' => []];
    header('Location: ' . setup_stap_url($terug));
    exit;
}

// Stap 3 — db.sql uitvoeren.
$installatieResultaten = [];
$installatieFout       = '';
if ($actie === 'schema_installeren') {
    $stap = 3;
    $test = setup_db_test();
    if (!$test['ok']) {
        $installatieFout = 'Geen verbinding met de database: ' . $test['fout'];
    } else {
        $sqlBestand = APP_ROOT . '/db.sql';
        $sql        = is_readable($sqlBestand) ? (string)file_get_contents($sqlBestand) : '';
        if ($sql === '') {
            $installatieFout = 'db.sql is niet gevonden of niet leesbaar (' . $sqlBestand . ').';
        } else {
            foreach (setup_sql_statements($sql) as $statement) {
                try {
                    db()->exec($statement);
                    $installatieResultaten[] = ['ok' => true, 'label' => setup_statement_label($statement), 'fout' => ''];
                } catch (Throwable $e) {
                    $installatieResultaten[] = [
                        'ok'    => false,
                        'label' => setup_statement_label($statement),
                        'fout'  => $e->getMessage(),
                    ];
                }
            }
            instellingen(true);
        }
    }
}

// Stap 4 — eerste beheerder aanmaken.
$beheerderFouten = [];
$beheerderNaam   = trim((string)($_POST['naam'] ?? ''));
$beheerderEmail  = normaliseer_email((string)($_POST['email'] ?? ''));

if ($actie === 'beheerder_aanmaken') {
    $stap        = 4;
    $wachtwoord  = (string)($_POST['wachtwoord'] ?? '');
    $herhaling   = (string)($_POST['wachtwoord_herhaling'] ?? '');
    $aantal      = setup_aantal_beheerders();

    if ($aantal < 0) {
        $beheerderFouten[] = 'De tabel beheerders bestaat nog niet. Voer eerst stap 3 uit.';
    } elseif ($aantal > 0 && !setup_ontgrendeld()) {
        $beheerderFouten[] = 'Er bestaat al een beheerder. Maak het bestand '
            . SETUP_ONTGRENDEL_BESTAND . ' aan om er nog een toe te voegen.';
    }
    if ($beheerderNaam === '') {
        $beheerderFouten[] = 'Vul een naam in.';
    }
    if (!geldig_email($beheerderEmail)) {
        $beheerderFouten[] = 'Vul een geldig e-mailadres in.';
    }
    if (strlen($wachtwoord) < 12) {
        $beheerderFouten[] = 'Het wachtwoord moet minimaal 12 tekens lang zijn.';
    }
    if (!hash_equals($wachtwoord, $herhaling)) {
        $beheerderFouten[] = 'De twee wachtwoorden zijn niet gelijk.';
    }

    if (!$beheerderFouten) {
        try {
            db()->prepare('INSERT INTO beheerders (naam, email, wachtwoord_hash, rol, actief)
                           VALUES (:naam, :email, :hash, :rol, 1)
                           ON DUPLICATE KEY UPDATE
                               naam = VALUES(naam),
                               wachtwoord_hash = VALUES(wachtwoord_hash),
                               rol = VALUES(rol),
                               actief = 1')
                ->execute([
                    ':naam'  => mb_substr($beheerderNaam, 0, 150),
                    ':email' => $beheerderEmail,
                    ':hash'  => password_hash($wachtwoord, PASSWORD_DEFAULT),
                    ':rol'   => 'eigenaar',
                ]);
            header('Location: ' . setup_stap_url(5));
            exit;
        } catch (Throwable $e) {
            $beheerderFouten[] = 'Opslaan mislukt: ' . $e->getMessage();
        }
    }
}

// ─── Weergave ────────────────────────────────────────────────────────────────

$melding = $_SESSION['setup_melding'] ?? null;
unset($_SESSION['setup_melding']);

setup_kop('Stap ' . $stap, $stap);

if (is_array($melding)) {
    ?>
    <div class="alert alert-<?= h((string)($melding['soort'] ?? 'info')) ?>">
        <?= h((string)($melding['tekst'] ?? '')) ?>
        <?php if (!empty($melding['punten'])): ?>
            <ul class="mb-0 mt-2 small">
                <?php foreach ((array)$melding['punten'] as $punt): ?>
                    <li><?= h((string)$punt) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <?php
}

switch ($stap) {

    // ═══ Stap 1 — controle ═══════════════════════════════════════════════════
    case 1:
        $logsPad   = APP_ROOT . '/logs';
        $projectOk = is_writable(APP_ROOT);
        $extensies = ['pdo_mysql', 'curl', 'mbstring', 'openssl', 'json'];
        $phpOk     = PHP_VERSION_ID >= 80100;
        $alleExtOk = true;
        foreach ($extensies as $ext) {
            if (!extension_loaded($ext)) {
                $alleExtOk = false;
            }
        }
        $logsOk   = setup_map_schrijfbaar($logsPad);
        $gebruiker = setup_webserver_gebruiker();
        $allesOk  = $phpOk && $alleExtOk && $logsOk;
        ?>
        <h2 class="h5 mb-3">Stap 1 — controle van de omgeving</h2>

        <p>Eerst kijkt de wizard of de server alles heeft wat het portaal nodig heeft.
            De opslagmap en <code>.env</code> komen in de volgende stap aan bod.</p>

        <ul class="list-unstyled setup-lijst">
            <?php
            setup_punt(
                $phpOk,
                'PHP-versie ' . PHP_VERSION . ' (vereist: 8.1 of hoger)',
                'Vraag uw hostingpartij om PHP 8.1 of nieuwer, of kies een nieuwere PHP-versie in het hostingpaneel.'
            );

            foreach ($extensies as $ext) {
                setup_punt(
                    extension_loaded($ext),
                    'PHP-extensie ' . $ext,
                    'Installeer de extensie, bijvoorbeeld met <code>sudo apt install php8.3-'
                        . h($ext === 'pdo_mysql' ? 'mysql' : $ext) . '</code>, en herstart PHP-FPM of Apache.'
                );
            }

            setup_punt(
                $logsOk,
                'Map logs/ is schrijfbaar (' . $logsPad . ')',
                'Maak de map aan en geef de webserver schrijfrechten:<br>'
                    . '<code>mkdir -p ' . h($logsPad) . ' &amp;&amp; chown ' . h($gebruiker) . ' ' . h($logsPad) . '</code>'
            );
            ?>
        </ul>

        <p class="small text-body-secondary mb-0">
            PHP draait hier als <code><?= h($gebruiker) ?></code>. Gebruik die naam in de
            <code>chown</code>-opdrachten hierboven.
        </p>

        <?php if (!$projectOk): ?>
            <div class="alert alert-warning mt-4 mb-0">
                <h3 class="h6">De projectmap is niet beschrijfbaar</h3>
                <p class="mb-2">De wizard kan <code>.env</code> dan niet zelf wegschrijven. Dat hoeft
                    geen probleem te zijn — in de volgende stap krijgt u de inhoud te zien om zelf
                    te plaatsen. Wilt u het de wizard laten doen, voer dan uit:</p>
                <pre class="mb-0">chown <?= h($gebruiker) ?> <?= h(APP_ROOT) ?></pre>
            </div>
        <?php endif; ?>

        <hr class="my-4">
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="<?= h(setup_stap_url(1)) ?>">Opnieuw controleren</a>
            <a class="btn btn-primary <?= $allesOk ? '' : 'disabled' ?>" href="<?= h(setup_stap_url(2)) ?>">
                Verder naar de instellingen
            </a>
        </div>
        <?php if (!$allesOk): ?>
            <p class="small text-body-secondary mt-2 mb-0">
                Los eerst de punten met een kruisje op. U kunt daarna op “Opnieuw controleren” klikken.
            </p>
        <?php endif; ?>
        <?php
        break;

    // ═══ Stap 3 — database ═══════════════════════════════════════════════════
    case 3:
        $test = setup_db_test();
        ?>
        <h2 class="h5 mb-3">Stap 3 — database inrichten</h2>

        <table class="table table-sm">
            <tbody>
                <tr><th class="w-25">Server</th><td><code><?= h(DB_HOST) ?></code></td></tr>
                <tr><th>Database</th><td><code><?= h(DB_NAME) ?></code></td></tr>
                <tr><th>Gebruiker</th><td><code><?= h(DB_USER) ?></code></td></tr>
                <tr><th>Tekenset</th><td><code><?= h(DB_CHARSET) ?></code></td></tr>
            </tbody>
        </table>

        <?php if ($test['ok']): ?>
            <p class="text-success"><strong>&#10003;</strong> De verbinding met de database werkt.</p>
        <?php else: ?>
            <div class="alert alert-danger">
                <h3 class="h6">Geen verbinding met de database</h3>
                <p class="mb-2"><code><?= h($test['fout']) ?></code></p>
                <p class="mb-2">Controleer <code>DB_HOST</code>, <code>DB_NAME</code>, <code>DB_USER</code>
                    en <code>DB_PASS</code> in <code>.env</code>. Bestaat de database nog niet, maak hem dan aan:</p>
                <pre>CREATE DATABASE <?= h(DB_NAME) ?> CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON <?= h(DB_NAME) ?>.* TO '<?= h(DB_USER) ?>'@'localhost';</pre>
            </div>
        <?php endif; ?>

        <?php if ($installatieFout !== ''): ?>
            <div class="alert alert-danger"><?= h($installatieFout) ?></div>
        <?php endif; ?>

        <?php if ($installatieResultaten): ?>
            <?php
            $gelukt   = count(array_filter($installatieResultaten, static fn(array $r): bool => $r['ok']));
            $mislukt  = count($installatieResultaten) - $gelukt;
            $tabellen = setup_aantal_tabellen();
            ?>
            <div class="alert <?= $mislukt === 0 ? 'alert-success' : 'alert-warning' ?>">
                <?= (int)$gelukt ?> van de <?= count($installatieResultaten) ?> statements uitgevoerd.
                De database bevat nu <strong><?= (int)$tabellen ?></strong> tabellen.
            </div>
            <ul class="list-unstyled setup-lijst">
                <?php foreach ($installatieResultaten as $resultaat): ?>
                    <?php setup_punt($resultaat['ok'], $resultaat['label'], h($resultaat['fout'])); ?>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <hr class="my-4">
        <div class="d-flex flex-wrap gap-2">
            <?php if ($test['ok']): ?>
                <form method="post" action="<?= h(setup_stap_url(3)) ?>" class="d-inline">
                    <?= setup_csrf_field() ?>
                    <input type="hidden" name="actie" value="schema_installeren">
                    <button class="btn btn-primary" type="submit">Database inrichten</button>
                </form>
            <?php endif; ?>
            <a class="btn btn-outline-secondary" href="<?= h(setup_stap_url(2)) ?>">Terug</a>
            <?php if (tabel_bestaat('beheerders')): ?>
                <a class="btn btn-success" href="<?= h(setup_stap_url(4)) ?>">Verder naar de beheerder</a>
            <?php endif; ?>
        </div>
        <p class="small text-body-secondary mt-3 mb-0">
            <code>db.sql</code> gebruikt overal <code>CREATE TABLE IF NOT EXISTS</code> en een
            <code>INSERT … ON DUPLICATE KEY UPDATE</code>. U kunt deze stap dus veilig herhalen:
            bestaande tabellen en gegevens blijven ongemoeid.
        </p>
        <?php
        break;

    // ═══ Stap 4 — eerste beheerder ═══════════════════════════════════════════
    case 4:
        $aantal = setup_aantal_beheerders();
        ?>
        <h2 class="h5 mb-3">Stap 4 — eerste beheerder</h2>

        <?php if ($aantal < 0): ?>
            <div class="alert alert-danger">
                De tabel <code>beheerders</code> bestaat nog niet.
                <a href="<?= h(setup_stap_url(3)) ?>">Voer eerst stap 3 uit.</a>
            </div>
        <?php else: ?>
            <?php if ($aantal > 0 && !setup_ontgrendeld()): ?>
                <div class="alert alert-warning">
                    Er bestaat al een beheerder. Log in via
                    <a href="<?= h(url('admin/login.php')) ?>">admin/login.php</a>, of maak het bestand
                    <code><?= h(SETUP_ONTGRENDEL_BESTAND) ?></code> aan om hier een extra beheerder toe te voegen.
                </div>
            <?php else: ?>
                <?php foreach ($beheerderFouten as $fout): ?>
                    <div class="alert alert-danger"><?= h($fout) ?></div>
                <?php endforeach; ?>

                <p>Deze beheerder krijgt de rol <strong>eigenaar</strong> en kan later via
                    Beheer → Beheerders extra beheerders toevoegen.</p>

                <form method="post" action="<?= h(setup_stap_url(4)) ?>" autocomplete="off">
                    <?= setup_csrf_field() ?>
                    <input type="hidden" name="actie" value="beheerder_aanmaken">

                    <div class="mb-3">
                        <label class="form-label" for="naam">Naam</label>
                        <input class="form-control" type="text" id="naam" name="naam" maxlength="150"
                               required value="<?= h($beheerderNaam) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="email">E-mailadres</label>
                        <input class="form-control" type="email" id="email" name="email" maxlength="190"
                               required value="<?= h($beheerderEmail) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="wachtwoord">Wachtwoord</label>
                        <input class="form-control" type="password" id="wachtwoord" name="wachtwoord"
                               minlength="12" required autocomplete="new-password">
                        <div class="form-text">Minimaal 12 tekens. Gebruik bij voorkeur een wachtwoordmanager.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="wachtwoord_herhaling">Wachtwoord herhalen</label>
                        <input class="form-control" type="password" id="wachtwoord_herhaling"
                               name="wachtwoord_herhaling" minlength="12" required autocomplete="new-password">
                    </div>

                    <button class="btn btn-primary" type="submit">Beheerder aanmaken</button>
                    <a class="btn btn-outline-secondary" href="<?= h(setup_stap_url(3)) ?>">Terug</a>
                </form>
            <?php endif; ?>
        <?php endif; ?>
        <?php
        break;

    // ═══ Stap 5 — klaar ══════════════════════════════════════════════════════
    case 5:
    default:
        ?>
        <h2 class="h5 mb-3">Stap 5 — de installatie is klaar</h2>

        <p>Het portaal is ingericht. Log in op het beheerdersgedeelte en vul daar als eerste
            de Microsoft Graph-gegevens in, zodat er inlogcodes verstuurd kunnen worden.</p>

        <div class="d-flex flex-wrap gap-2 mb-4">
            <a class="btn btn-primary" href="<?= h(url('admin/login.php')) ?>">Beheer — inloggen</a>
            <a class="btn btn-outline-secondary" href="<?= h(url('index.php')) ?>">Naar het portaal</a>
        </div>

        <div class="alert alert-danger">
            <h3 class="h6">Doe dit nu meteen</h3>
            <ol class="mb-0">
                <li>Verwijder <code>setup.php</code> van de server, of scherm het af:
                    <pre class="mt-2 mb-2">rm <?= h(APP_ROOT) ?>/setup.php</pre>
                </li>
                <li>Verwijder het bestand <code><?= h(SETUP_ONTGRENDEL_BESTAND) ?></code> als u dat had aangemaakt.</li>
                <li>Zorg dat <code>.env</code> nooit publiek te downloaden is. De meegeleverde
                    <code>.htaccess</code> blokkeert dit onder Apache; onder nginx staat de regel in
                    <code>docs/nginx.voorbeeld.conf</code>. Controleer het door
                    <code><?= h(url('.env')) ?></code> in de browser te openen — u hoort een 403 of 404 te krijgen.</li>
                <li>Zet de dagelijkse opschoontaak klaar (zie <code>cron_opschonen.php</code>).</li>
            </ol>
        </div>

        <h3 class="h6">Volgende stappen</h3>
        <ol>
            <li>Beheer → Instellingen: Microsoft Graph invullen en een testmail versturen
                (<code>docs/GRAPH-SETUP.md</code>).</li>
            <li>Beheer → Jaargangen: het eerste jaar aanmaken.</li>
            <li>Beheer → Bestanden: het videobestand koppelen.</li>
            <li>Beheer → Toegang: de e-mailadressen importeren (<code>docs/NIEUW-JAAR.md</code>).</li>
        </ol>
        <?php
        break;
}

setup_voet();
