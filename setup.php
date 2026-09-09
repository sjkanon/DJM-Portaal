<?php

/**
 * DJM Portaal — installatiewizard.
 *
 * Stap 1  omgevingscontrole (PHP, extensies, schrijfrechten, .env)
 * Stap 2  databaseverbinding testen en db.sql uitvoeren
 * Stap 3  eerste beheerder aanmaken
 * Stap 4  afronden
 *
 * Beveiliging: zodra er een actieve beheerder in de database staat doet dit
 * bestand niets meer, tenzij er in de projectroot een bestand
 * `setup.toegestaan` staat. Verwijder setup.php na de installatie.
 *
 * Dit bestand gebruikt met opzet niet includes/layout.php of admin/includes/layout.php:
 * die verwachten een werkende database, en die is er tijdens de installatie nog niet.
 */

require_once __DIR__ . '/config.php';

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

// ─── Opmaak ──────────────────────────────────────────────────────────────────

function setup_kop(string $titel, int $huidigeStap = 0): void
{
    $stappen = [1 => 'Controle', 2 => 'Database', 3 => 'Beheerder', 4 => 'Klaar'];
    ?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($titel) ?> — installatie DJM Portaal</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <style>
        body { background: #f5f6f8; }
        .setup-kaart { max-width: 780px; margin: 2.5rem auto; }
        .setup-lijst li { margin-bottom: .35rem; }
        code, pre { font-size: .875rem; }
        pre { background: #f1f3f5; padding: .75rem 1rem; border-radius: .375rem; overflow-x: auto; }
    </style>
</head>
<body>
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
$stap   = ($stap >= 1 && $stap <= 4) ? $stap : 1;

// Stap 2 — db.sql uitvoeren.
$installatieResultaten = [];
$installatieFout       = '';
if ($actie === 'schema_installeren') {
    $stap = 2;
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

// Stap 3 — eerste beheerder aanmaken.
$beheerderFouten = [];
$beheerderNaam   = trim((string)($_POST['naam'] ?? ''));
$beheerderEmail  = normaliseer_email((string)($_POST['email'] ?? ''));

if ($actie === 'beheerder_aanmaken') {
    $stap        = 3;
    $wachtwoord  = (string)($_POST['wachtwoord'] ?? '');
    $herhaling   = (string)($_POST['wachtwoord_herhaling'] ?? '');
    $aantal      = setup_aantal_beheerders();

    if ($aantal < 0) {
        $beheerderFouten[] = 'De tabel beheerders bestaat nog niet. Voer eerst stap 2 uit.';
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
            header('Location: ' . setup_stap_url(4));
            exit;
        } catch (Throwable $e) {
            $beheerderFouten[] = 'Opslaan mislukt: ' . $e->getMessage();
        }
    }
}

// ─── Weergave ────────────────────────────────────────────────────────────────

setup_kop('Stap ' . $stap, $stap);

switch ($stap) {

    // ═══ Stap 1 — controle ═══════════════════════════════════════════════════
    case 1:
        $envAanwezig  = is_file(APP_ROOT . '/.env');
        $logsPad      = APP_ROOT . '/logs';
        $opslag       = opslag_pad();
        $extensies    = ['pdo_mysql', 'curl', 'mbstring', 'openssl', 'json'];
        $phpOk        = PHP_VERSION_ID >= 80100;
        $alleExtOk    = true;
        foreach ($extensies as $ext) {
            if (!extension_loaded($ext)) {
                $alleExtOk = false;
            }
        }
        $logsOk   = setup_map_schrijfbaar($logsPad);
        $opslagOk = setup_map_schrijfbaar($opslag);
        $allesOk  = $phpOk && $alleExtOk && $logsOk && $opslagOk && $envAanwezig;
        ?>
        <h2 class="h5 mb-3">Stap 1 — controle van de omgeving</h2>

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
                    . '<code>mkdir -p ' . h($logsPad) . ' &amp;&amp; chown www-data:www-data ' . h($logsPad) . '</code>'
            );

            setup_punt(
                $opslagOk,
                'Opslagmap is schrijfbaar (' . $opslag . ')',
                'Maak de map aan en geef de webserver rechten:<br>'
                    . '<code>mkdir -p ' . h($opslag) . ' &amp;&amp; chown www-data:www-data ' . h($opslag) . '</code><br>'
                    . 'Wijs de map bij voorkeur buiten de webroot aan via <code>OPSLAG_PAD</code> in <code>.env</code>.'
            );

            setup_punt(
                $envAanwezig,
                'Bestand .env aanwezig',
                'Kopieer het voorbeeldbestand en vul het in:<br><code>cp .env.example .env</code>'
            );
            ?>
        </ul>

        <?php if (!$envAanwezig): ?>
            <div class="alert alert-warning mt-4">
                <h3 class="h6">Er is nog geen .env</h3>
                <p class="mb-2">Kopieer <code>.env.example</code> naar <code>.env</code> en vul in elk geval
                    de databasegegevens in. Gebruik onderstaande, zojuist gegenereerde sleutels:</p>
                <pre>APP_KEY=<?= h(bin2hex(random_bytes(32))) ?>

OTP_PEPPER=<?= h(bin2hex(random_bytes(32))) ?></pre>
                <p class="small mb-0">Bewaar deze waarden goed. Als <code>OTP_PEPPER</code> later verandert,
                    worden alle openstaande inlogcodes ongeldig.</p>
            </div>
        <?php else: ?>
            <?php if (env('APP_KEY') === '' || env('OTP_PEPPER') === ''): ?>
                <div class="alert alert-warning mt-4">
                    <h3 class="h6">APP_KEY en/of OTP_PEPPER is leeg</h3>
                    <p class="mb-2">
                        Het portaal werkt ook zonder, maar valt dan terug op sleutels die worden afgeleid
                        van de databasegegevens. Dat is merkbaar minder sterk: wie de databasegegevens
                        kent, kan downloadlinks ondertekenen en codehashes narekenen. Vul daarom deze
                        regels in <code>.env</code>:
                    </p>
                    <pre>APP_KEY=<?= h(bin2hex(random_bytes(32))) ?>

OTP_PEPPER=<?= h(bin2hex(random_bytes(32))) ?></pre>
                    <p class="small mb-0">Herlaad deze pagina na het opslaan.</p>
                </div>
            <?php else: ?>
                <p class="text-success mb-0"><strong>&#10003;</strong> APP_KEY en OTP_PEPPER zijn ingevuld.</p>
            <?php endif; ?>
        <?php endif; ?>

        <hr class="my-4">
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="<?= h(setup_stap_url(1)) ?>">Opnieuw controleren</a>
            <a class="btn btn-primary <?= $allesOk ? '' : 'disabled' ?>" href="<?= h(setup_stap_url(2)) ?>">
                Verder naar de database
            </a>
        </div>
        <?php if (!$allesOk): ?>
            <p class="small text-body-secondary mt-2 mb-0">
                Los eerst de punten met een kruisje op. U kunt daarna op “Opnieuw controleren” klikken.
            </p>
        <?php endif; ?>
        <?php
        break;

    // ═══ Stap 2 — database ═══════════════════════════════════════════════════
    case 2:
        $test = setup_db_test();
        ?>
        <h2 class="h5 mb-3">Stap 2 — database inrichten</h2>

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
                <form method="post" action="<?= h(setup_stap_url(2)) ?>" class="d-inline">
                    <?= setup_csrf_field() ?>
                    <input type="hidden" name="actie" value="schema_installeren">
                    <button class="btn btn-primary" type="submit">Database inrichten</button>
                </form>
            <?php endif; ?>
            <a class="btn btn-outline-secondary" href="<?= h(setup_stap_url(1)) ?>">Terug</a>
            <?php if (tabel_bestaat('beheerders')): ?>
                <a class="btn btn-success" href="<?= h(setup_stap_url(3)) ?>">Verder naar de beheerder</a>
            <?php endif; ?>
        </div>
        <p class="small text-body-secondary mt-3 mb-0">
            <code>db.sql</code> gebruikt overal <code>CREATE TABLE IF NOT EXISTS</code> en een
            <code>INSERT … ON DUPLICATE KEY UPDATE</code>. U kunt deze stap dus veilig herhalen:
            bestaande tabellen en gegevens blijven ongemoeid.
        </p>
        <?php
        break;

    // ═══ Stap 3 — eerste beheerder ═══════════════════════════════════════════
    case 3:
        $aantal = setup_aantal_beheerders();
        ?>
        <h2 class="h5 mb-3">Stap 3 — eerste beheerder</h2>

        <?php if ($aantal < 0): ?>
            <div class="alert alert-danger">
                De tabel <code>beheerders</code> bestaat nog niet.
                <a href="<?= h(setup_stap_url(2)) ?>">Voer eerst stap 2 uit.</a>
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

                <form method="post" action="<?= h(setup_stap_url(3)) ?>" autocomplete="off">
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
                    <a class="btn btn-outline-secondary" href="<?= h(setup_stap_url(2)) ?>">Terug</a>
                </form>
            <?php endif; ?>
        <?php endif; ?>
        <?php
        break;

    // ═══ Stap 4 — klaar ══════════════════════════════════════════════════════
    case 4:
    default:
        ?>
        <h2 class="h5 mb-3">Stap 4 — de installatie is klaar</h2>

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
