<?php

/**
 * Beheerdersaccounts: de rem op herhaalde pogingen, uitnodigingen en
 * wachtwoordherstel.
 *
 * Een beheerder kiest zijn wachtwoord altijd zelf, via een link per e-mail.
 * Niemand anders ziet of kent het, ook niet wie het account aanmaakt.
 *
 * Zo'n link bevat een selector, waarmee de rij wordt opgezocht, en een geheim
 * deel waarvan alleen een HMAC in de database staat. Wie de tabel kan lezen
 * (een back-up, een lek), haalt daar dus geen werkende link uit.
 *
 * Dit bestand hangt bewust niet af van de OTP-helpers van het portaal, en laadt
 * de mailfuncties pas als er echt iets verstuurd wordt: een probleem daarin mag
 * het inloggen van beheerders niet blokkeren.
 */

if (!function_exists('db')) {
    require_once dirname(__DIR__) . '/config.php';
}

/** Geldigheid van een link uit "wachtwoord vergeten" of "resetlink sturen". */
const BEHEERDER_RESET_MINUTEN = 60;

/** Geldigheid van een uitnodiging: ruimer, want die ligt soms een weekend in de mailbox. */
const BEHEERDER_UITNODIGING_MINUTEN = 72 * 60;

const BEHEERDER_WACHTWOORD_MINIMUM = 12;

/** Lengtes in hexadecimale tekens: 9 en 32 willekeurige bytes. */
const BEHEERDER_SELECTOR_LENGTE = 18;
const BEHEERDER_GEHEIM_LENGTE   = 64;

// ─── Rem op herhaalde pogingen ───────────────────────────────────────────────
// Werkt met de tabel aanvraag_limiet, net als de rem van het portaal, maar met
// eigen functies en sleutels.

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

/** Wist de teller, bijvoorbeeld na een geslaagde inlog. */
function admin_limiet_wissen(string $sleutel): void
{
    try {
        db()->prepare('DELETE FROM aanvraag_limiet WHERE sleutel = :s')->execute([':s' => $sleutel]);
    } catch (Throwable $e) {
        // niet blokkerend
    }
}

/**
 * Sleutel voor een rem per e-mailadres. In de tabel komt alleen een hash: daar
 * hoeft geen e-mailadres in te staan. Zie limiet_sleutel() in config.php, dat
 * hetzelfde doet voor de rem van het portaal.
 */
function admin_email_sleutel(string $voorvoegsel, string $email): string
{
    return limiet_sleutel($voorvoegsel, $email);
}

/**
 * Sleutel voor de rem per account bij het inloggen. De rem per IP-adres houdt
 * één aanvaller tegen, maar niet iemand die vanaf veel adressen tegelijk op
 * hetzelfde beheerdersaccount blijft gokken. Deze tweede teller sluit dat gat.
 */
function admin_account_sleutel(string $email): string
{
    return admin_email_sleutel('adminacc', $email);
}

// ─── Links om een wachtwoord te kiezen ───────────────────────────────────────

/**
 * Maakt de tabel aan als hij ontbreekt.
 *
 * setup.php voert db.sql alleen bij de installatie uit. Een installatie van
 * vóór deze functie heeft de tabel dus nog niet, en daar draait de wizard niet
 * meer. Houd deze definitie gelijk aan die in db.sql.
 */
function beheerder_tokens_tabel(): void
{
    static $aanwezig = false;
    if ($aanwezig || tabel_bestaat('beheerder_tokens')) {
        $aanwezig = true;
        return;
    }
    db()->exec(
        'CREATE TABLE IF NOT EXISTS beheerder_tokens (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            beheerder_id  INT UNSIGNED NOT NULL,
            doel          VARCHAR(20) NOT NULL,
            selector      CHAR(18) NOT NULL,
            token_hash    CHAR(64) NOT NULL,
            verloopt_op   DATETIME NOT NULL,
            gebruikt_op   DATETIME NULL,
            ip            VARBINARY(16) NULL,
            aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_selector (selector),
            KEY idx_beheerder (beheerder_id, gebruikt_op),
            KEY idx_verloopt (verloopt_op),
            CONSTRAINT fk_token_beheerder FOREIGN KEY (beheerder_id)
                REFERENCES beheerders (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $aanwezig = true;
}

function beheerder_token_hash(string $geheim): string
{
    return hash_hmac('sha256', $geheim, app_key());
}

/** Hoe lang een link geldig is, in woorden. */
function beheerder_link_geldigheid(string $doel): string
{
    return $doel === 'uitnodiging'
        ? intdiv(BEHEERDER_UITNODIGING_MINUTEN, 60) . ' uur'
        : BEHEERDER_RESET_MINUTEN . ' minuten';
}

/**
 * Staat het webadres van het portaal vast in APP_URL?
 *
 * Zonder APP_URL bouwt url() het adres uit de Host-header van het verzoek, en
 * die kiest wie het verzoek doet. Vraagt iemand zonder sessie een link aan met
 * een vervalste header, dan krijgt de echte beheerder een echte mail met een
 * link naar een andere site. Links die zonder sessie worden aangevraagd,
 * worden daarom alleen verstuurd als dit waar is.
 */
function beheerder_link_basis_vast(): bool
{
    return rtrim(env('APP_URL'), '/') !== '';
}

/**
 * Maakt een nieuwe link voor deze beheerder en geeft het volledige adres terug.
 * Een eerder verstuurde, nog ongebruikte link werkt daarna niet meer: per
 * beheerder is er hooguit één geldig.
 *
 * @param string $doel 'uitnodiging' of 'reset'
 */
function beheerder_link_maken(int $beheerderId, string $doel): string
{
    $doel     = $doel === 'uitnodiging' ? 'uitnodiging' : 'reset';
    $selector = bin2hex(random_bytes(intdiv(BEHEERDER_SELECTOR_LENGTE, 2)));
    $geheim   = bin2hex(random_bytes(intdiv(BEHEERDER_GEHEIM_LENGTE, 2)));
    $minuten  = $doel === 'uitnodiging' ? BEHEERDER_UITNODIGING_MINUTEN : BEHEERDER_RESET_MINUTEN;

    beheerder_links_intrekken($beheerderId);
    db()->prepare(sprintf(
        'INSERT INTO beheerder_tokens (beheerder_id, doel, selector, token_hash, verloopt_op, ip)
         VALUES (:b, :d, :s, :h, NOW() + INTERVAL %d MINUTE, :ip)',
        $minuten
    ))->execute([
        ':b'  => $beheerderId,
        ':d'  => $doel,
        ':s'  => $selector,
        ':h'  => beheerder_token_hash($geheim),
        ':ip' => client_ip_bin(),
    ]);

    return url('admin/wachtwoord_instellen.php') . '?t=' . $selector . $geheim;
}

/** Maakt alle nog ongebruikte links van deze beheerder ongeldig. */
function beheerder_links_intrekken(int $beheerderId): void
{
    beheerder_tokens_tabel();
    db()->prepare('DELETE FROM beheerder_tokens WHERE beheerder_id = :b AND gebruikt_op IS NULL')
        ->execute([':b' => $beheerderId]);
}

/**
 * Maakt een link en mailt die naar de beheerder zelf. Lukt het versturen niet,
 * dan wordt de link meteen weer ingetrokken: niemand heeft hem ontvangen.
 *
 * @param array  $beheerder rij uit `beheerders`
 * @param string $door      naam van wie de link verstuurt; leeg bij "wachtwoord vergeten"
 */
function beheerder_link_versturen(array $beheerder, string $doel, string $door = '', array &$fouten = []): bool
{
    require_once __DIR__ . '/email_helper.php';

    $id     = (int)$beheerder['id'];
    $link   = beheerder_link_maken($id, $doel);
    $gelukt = verstuur_beheerder_link_mail(
        (string)$beheerder['email'],
        (string)$beheerder['naam'],
        $link,
        $doel,
        beheerder_link_geldigheid($doel),
        $door,
        $fouten
    );
    if (!$gelukt) {
        beheerder_links_intrekken($id);
    }
    return $gelukt;
}

/**
 * Zoekt de beheerder bij een link. Geeft null bij een onbekende, verlopen of al
 * gebruikte link, en bij een uitgeschakeld account.
 *
 * @return array|null rij uit `beheerders`, aangevuld met token_id en doel
 */
function beheerder_link_controleren(string $token): ?array
{
    $token = strtolower($token);
    if (strlen($token) !== BEHEERDER_SELECTOR_LENGTE + BEHEERDER_GEHEIM_LENGTE || !ctype_xdigit($token)) {
        return null;
    }
    beheerder_tokens_tabel();
    $stmt = db()->prepare(
        'SELECT b.*, t.id AS token_id, t.doel, t.token_hash
           FROM beheerder_tokens t
           JOIN beheerders b ON b.id = t.beheerder_id
          WHERE t.selector = :s
            AND t.gebruikt_op IS NULL
            AND t.verloopt_op > NOW()
            AND b.actief = 1'
    );
    $stmt->execute([':s' => substr($token, 0, BEHEERDER_SELECTOR_LENGTE)]);
    $rij = $stmt->fetch();

    // Het geheime deel vergelijken in constante tijd.
    $geheim = substr($token, BEHEERDER_SELECTOR_LENGTE);
    if (!$rij || !hash_equals((string)$rij['token_hash'], beheerder_token_hash($geheim))) {
        return null;
    }
    unset($rij['token_hash']);
    return $rij;
}

/** Controleert een nieuw wachtwoord; geeft de foutmeldingen terug. */
function beheerder_wachtwoord_fouten(string $wachtwoord, string $herhaling, string $email): array
{
    $fouten = [];
    if (mb_strlen($wachtwoord) < BEHEERDER_WACHTWOORD_MINIMUM) {
        $fouten[] = 'Het wachtwoord moet minimaal ' . BEHEERDER_WACHTWOORD_MINIMUM . ' tekens lang zijn.';
    } elseif (strcasecmp(trim($wachtwoord), $email) === 0) {
        $fouten[] = 'Kies een wachtwoord dat niet gelijk is aan uw e-mailadres.';
    }
    if (!hash_equals($wachtwoord, $herhaling)) {
        $fouten[] = 'De twee wachtwoorden zijn niet gelijk.';
    }
    return $fouten;
}

/**
 * Slaat het nieuwe wachtwoord op en maakt de link ongeldig. Geeft false als de
 * link intussen al gebruikt is: van twee gelijktijdige verzoeken wint er één.
 *
 * @param array $link uitkomst van beheerder_link_controleren()
 */
function beheerder_wachtwoord_opslaan(array $link, string $wachtwoord): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $claim = $pdo->prepare('UPDATE beheerder_tokens SET gebruikt_op = NOW() WHERE id = :id AND gebruikt_op IS NULL');
        $claim->execute([':id' => (int)$link['token_id']]);
        if ($claim->rowCount() !== 1) {
            $pdo->rollBack();
            return false;
        }
        $pdo->prepare('UPDATE beheerders SET wachtwoord_hash = :h WHERE id = :id')
            ->execute([':h' => password_hash($wachtwoord, PASSWORD_DEFAULT), ':id' => (int)$link['id']]);
        $pdo->prepare('DELETE FROM beheerder_tokens WHERE beheerder_id = :b AND gebruikt_op IS NULL')
            ->execute([':b' => (int)$link['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // Wie net een nieuw wachtwoord heeft gekozen, hoeft niet te wachten op de
    // rem van eerdere foute inlogpogingen.
    admin_limiet_wissen(admin_account_sleutel((string)$link['email']));
    return true;
}

/**
 * Nog geldige links per beheerder: [beheerder_id => ['doel' => …, 'verloopt_op' => …]].
 */
function beheerder_open_links(): array
{
    try {
        beheerder_tokens_tabel();
        $links = [];
        $rijen = db()->query(
            'SELECT beheerder_id, doel, verloopt_op FROM beheerder_tokens
              WHERE gebruikt_op IS NULL AND verloopt_op > NOW()'
        )->fetchAll();
        foreach ($rijen as $rij) {
            $links[(int)$rij['beheerder_id']] = $rij;
        }
        return $links;
    } catch (Throwable $e) {
        app_log('openstaande beheerderslinks ophalen mislukt', ['fout' => $e->getMessage()]);
        return [];
    }
}
