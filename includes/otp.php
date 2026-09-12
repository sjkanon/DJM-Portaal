<?php

/**
 * Eenmalige inlogcodes (OTP): genereren, versturen, verifiëren en throttling.
 *
 * Uitgangspunten:
 *   - De code zelf wordt nooit opgeslagen, alleen hash_hmac('sha256', code, OTP_PEPPER).
 *   - Geen user enumeration: een onbekend adres levert exact dezelfde melding én
 *     ongeveer dezelfde looptijd op als een bekend adres.
 *   - Een code is gebonden aan de browsersessie via challenge_id.
 */

if (!function_exists('db')) {
    require_once dirname(__DIR__) . '/config.php';
}
if (!function_exists('deelnemer_op_email')) {
    require_once __DIR__ . '/toegang_helper.php';
}
if (!function_exists('verstuur_inlogcode_mail')) {
    require_once __DIR__ . '/email_helper.php';
}

/** Ondergrens voor de looptijd van otp_aanvragen(), in microseconden. */
const OTP_MIN_LOOPTIJD_US = 400000;

/** Venster voor de limiet per e-mailadres, in minuten. */
const OTP_VENSTER_EMAIL = 15;

/** Venster voor de limiet per IP-adres, in minuten. */
const OTP_VENSTER_IP = 60;

/** Venster en maximum voor het aantal codepogingen per IP-adres. */
const OTP_VENSTER_VERIFICATIE = 15;
const OTP_MAX_VERIFICATIES = 20;

// ─── Code en hash ────────────────────────────────────────────────────────────

/** Zes cijfers, inclusief voorloopnullen. */
function otp_code_genereren(): string
{
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/** Versleutelt de code met de pepper; alleen deze waarde gaat de database in. */
function otp_hash(string $code): string
{
    return hash_hmac('sha256', $code, otp_pepper());
}

// ─── Code aanvragen ──────────────────────────────────────────────────────────

/**
 * Vraagt een inlogcode aan voor $email.
 *
 * @param array $fouten Wordt gevuld met technische mailfouten (alleen voor het logboek).
 * @return array{status:string, echt?:bool} status: verstuurd | ongeldig | limiet | mailfout | fout
 */
function otp_aanvragen(string $email, array &$fouten = []): array
{
    $start  = microtime(true);
    $fouten = [];
    $email  = normaliseer_email($email);

    // De code wordt aan de sessie gebonden; die moet dus openstaan.
    ensure_session_started();

    if (!geldig_email($email)) {
        return otp_gelijke_looptijd($start, ['status' => 'ongeldig']);
    }

    try {
        // ── Rate limiting: per e-mailadres én per IP-adres ────────────────────
        $maxEmail = instelling_int('otp_max_per_email', 3);
        $maxIp    = instelling_int('otp_max_per_ip', 10);
        $ip       = client_ip();

        $emailSleutel = 'code:' . $email;
        $ipSleutel    = $ip !== '' ? 'ip:' . $ip : '';

        $binnenLimiet = otp_rate_limit_check($emailSleutel, $maxEmail, OTP_VENSTER_EMAIL)
            && ($ipSleutel === '' || otp_rate_limit_check($ipSleutel, $maxIp, OTP_VENSTER_IP));

        if (!$binnenLimiet) {
            log_login('code_aangevraagd', $email, false, 'limiet bereikt');
            return otp_gelijke_looptijd($start, ['status' => 'limiet']);
        }

        // Elke aanvraag telt mee, ook voor onbekende adressen. Anders kan iemand
        // ongelimiteerd adressen aftasten zolang die niet in de lijst staan.
        otp_rate_limit_tellen($emailSleutel, OTP_VENSTER_EMAIL);
        if ($ipSleutel !== '') {
            otp_rate_limit_tellen($ipSleutel, OTP_VENSTER_IP);
        }

        // Af en toe (±2%) het oude gruis opruimen; geen cronjob nodig.
        if (random_int(1, 50) === 1) {
            otp_opschonen();
        }

        // ── Mag dit adres inloggen? ──────────────────────────────────────────
        $deelnemer   = deelnemer_op_email($email);
        $geblokkeerd = $deelnemer !== null && (int)$deelnemer['geblokkeerd'] === 1;
        $magInloggen = $deelnemer !== null && !$geblokkeerd && email_heeft_toegang($email);

        if (!$magInloggen) {
            // Niets versturen, niets zichtbaars doen. De aanroeper toont dezelfde
            // melding als bij een bekend adres.
            log_login('code_aangevraagd', $email, false, 'onbekend adres');

            // De sessie krijgt wél hetzelfde spoor, zodat verifieer.php ook hier
            // gewoon een invoerscherm toont in plaats van terug te sturen — dat
            // laatste zou het verschil alsnog verraden.
            $_SESSION['otp_challenge'] = bin2hex(random_bytes(16));
            $_SESSION['otp_email']     = $email;

            return otp_gelijke_looptijd($start, ['status' => 'verstuurd', 'echt' => false]);
        }

        // ── Oude codes intrekken en een nieuwe klaarzetten ────────────────────
        otp_codes_intrekken($email);

        $code        = otp_code_genereren();
        $challengeId = bin2hex(random_bytes(16));          // 32 tekens
        $minuten     = max(1, instelling_int('otp_geldigheid_minuten', 10));

        $insert = db()->prepare(
            'INSERT INTO login_codes (email, deelnemer_id, code_hash, challenge_id, verloopt_op, ip)
             VALUES (:email, :deelnemer, :hash, :challenge, TIMESTAMPADD(MINUTE, :minuten, NOW()), :ip)'
        );
        $insert->bindValue(':email', $email);
        $insert->bindValue(':deelnemer', (int)$deelnemer['id'], PDO::PARAM_INT);
        $insert->bindValue(':hash', otp_hash($code));
        $insert->bindValue(':challenge', $challengeId);
        $insert->bindValue(':minuten', $minuten, PDO::PARAM_INT);
        $insert->bindValue(':ip', client_ip_bin());        // binaire string of null
        $insert->execute();
        $codeId = (int)db()->lastInsertId();

        // Bindt de code aan deze browser; verifieer.php controleert dit.
        $_SESSION['otp_challenge'] = $challengeId;
        $_SESSION['otp_email']     = $email;

        // ── Versturen ────────────────────────────────────────────────────────
        $naam = trim((string)($deelnemer['naam'] ?? ''));
        if (!verstuur_inlogcode_mail($email, $naam, $code, $minuten, $fouten)) {
            // Mislukt versturen mag de bezoeker wél zien — anders wacht iemand
            // eindeloos op een mail die nooit komt. De code is dan waardeloos.
            otp_code_intrekken_op_id($codeId);
            log_login('code_aangevraagd', $email, false, 'mail mislukt');
            app_log('inlogcode versturen mislukt', ['email' => $email, 'fouten' => $fouten]);
            return otp_gelijke_looptijd($start, ['status' => 'mailfout']);
        }

        log_login('code_aangevraagd', $email, true);
        return otp_gelijke_looptijd($start, ['status' => 'verstuurd', 'echt' => true]);
    } catch (Throwable $e) {
        app_log('otp_aanvragen mislukt', ['email' => $email, 'fout' => $e->getMessage()]);
        return otp_gelijke_looptijd($start, ['status' => 'fout']);
    }
}

/**
 * Houdt de looptijd van otp_aanvragen() voor bekende en onbekende adressen
 * ongeveer gelijk, zodat de responstijd niet verraadt of een adres bestaat.
 *
 * @param array $resultaat
 * @return array
 */
function otp_gelijke_looptijd(float $start, array $resultaat): array
{
    $verstreken = (microtime(true) - $start) * 1000000;
    $rest       = (int)round(OTP_MIN_LOOPTIJD_US - $verstreken);
    if ($rest > 0) {
        usleep($rest);
    }
    return $resultaat;
}

/** Trekt alle openstaande codes van een adres in. */
function otp_codes_intrekken(string $email, ?int $behoudId = null): void
{
    $sql = 'UPDATE login_codes SET ingetrokken_op = NOW()
            WHERE email = :email AND gebruikt_op IS NULL AND ingetrokken_op IS NULL';
    $params = [':email' => $email];
    if ($behoudId !== null) {
        $sql .= ' AND id <> :behoud';
        $params[':behoud'] = $behoudId;
    }
    db()->prepare($sql)->execute($params);
}

/** Trekt één specifieke code in. */
function otp_code_intrekken_op_id(int $codeId): void
{
    db()->prepare('UPDATE login_codes SET ingetrokken_op = NOW()
                   WHERE id = :id AND ingetrokken_op IS NULL')
        ->execute([':id' => $codeId]);
}

// ─── Code verifiëren ─────────────────────────────────────────────────────────

/**
 * Controleert een ingevoerde code voor $email.
 *
 * @param array $fout Wordt gevuld met de foutmelding(en) voor de bezoeker.
 * @return array|null De deelnemersrij bij succes, anders null.
 */
function otp_verifieren(string $email, string $code, array &$fout = []): ?array
{
    $fout  = [];
    $email = normaliseer_email($email);
    // Spaties en streepjes uit geplakte codes weghalen.
    $code  = preg_replace('/\D+/', '', $code) ?? '';

    $verlopenMelding = 'Deze code is verlopen of al gebruikt. Vraag een nieuwe code aan.';

    if ($email === '' || $code === '') {
        $fout[] = 'Vul de zescijferige code uit de e-mail in.';
        return null;
    }

    $stmt = db()->prepare(
        'SELECT * FROM login_codes
         WHERE email = :email
           AND gebruikt_op IS NULL
           AND ingetrokken_op IS NULL
           AND verloopt_op > NOW()
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute([':email' => $email]);
    $rij = $stmt->fetch();

    if (!$rij) {
        $fout[] = $verlopenMelding;
        return null;
    }

    $maxPogingen = max(1, instelling_int('otp_max_pogingen', 5));
    if ((int)$rij['pogingen'] >= $maxPogingen) {
        otp_code_intrekken_op_id((int)$rij['id']);
        log_login('code_fout', $email, false, 'te veel pogingen');
        $fout[] = 'Er is te vaak een verkeerde code ingevoerd. Vraag een nieuwe code aan.';
        return null;
    }

    // De code hoort bij de browsersessie waarin hij is aangevraagd. Zo is een
    // onderschepte code niet elders bruikbaar.
    if (instelling_bool('otp_bind_browser', true)) {
        $challenge = (string)($_SESSION['otp_challenge'] ?? '');
        if ($challenge === '' || !hash_equals((string)$rij['challenge_id'], $challenge)) {
            $fout[] = 'Deze code hoort bij een andere browser of apparaat. Vraag hier een nieuwe code aan.';
            return null;
        }
    }

    if (!hash_equals((string)$rij['code_hash'], otp_hash($code))) {
        db()->prepare('UPDATE login_codes SET pogingen = pogingen + 1 WHERE id = :id')
            ->execute([':id' => (int)$rij['id']]);
        log_login('code_fout', $email, false);

        $resterend = max(0, $maxPogingen - ((int)$rij['pogingen'] + 1));
        if ($resterend === 0) {
            otp_code_intrekken_op_id((int)$rij['id']);
            $fout[] = 'De code klopt niet. Vraag een nieuwe code aan.';
        } else {
            $fout[] = 'De code klopt niet. U heeft nog ' . $resterend
                . ($resterend === 1 ? ' poging.' : ' pogingen.');
        }
        return null;
    }

    // Goed: code eenmalig verbruiken en eventuele andere codes intrekken.
    db()->prepare('UPDATE login_codes SET gebruikt_op = NOW() WHERE id = :id')
        ->execute([':id' => (int)$rij['id']]);
    otp_codes_intrekken($email, (int)$rij['id']);

    $deelnemer = deelnemer_op_email($email);
    if ($deelnemer === null || (int)$deelnemer['geblokkeerd'] === 1) {
        log_login('geblokkeerd', $email, false);
        $fout[] = $verlopenMelding;
        return null;
    }

    return $deelnemer;
}

// ─── Rate limiting ───────────────────────────────────────────────────────────

/**
 * Mag er nog een aanvraag bij binnen dit venster?
 * Het venster wordt met de databaseklok bepaald, zodat PHP- en MySQL-tijdzones
 * niet uit elkaar kunnen lopen.
 */
function otp_rate_limit_check(string $sleutel, int $max, int $vensterMinuten): bool
{
    if ($max <= 0) {
        return true;                                   // 0 of minder = geen limiet
    }
    try {
        $stmt = db()->prepare(
            'SELECT teller FROM aanvraag_limiet
             WHERE sleutel = :sleutel
               AND TIMESTAMPDIFF(MINUTE, venster_start, NOW()) < :venster'
        );
        $stmt->bindValue(':sleutel', substr($sleutel, 0, 190));
        $stmt->bindValue(':venster', max(1, $vensterMinuten), PDO::PARAM_INT);
        $stmt->execute();
        $teller = $stmt->fetchColumn();
    } catch (Throwable $e) {
        app_log('rate limit lezen mislukt', ['fout' => $e->getMessage()]);
        return true;                                   // niet blokkerend
    }

    return $teller === false || (int)$teller < $max;
}

/** Telt een aanvraag mee; begint opnieuw op 1 als het venster verlopen is. */
function otp_rate_limit_tellen(string $sleutel, int $vensterMinuten): void
{
    $venster = max(1, $vensterMinuten);
    try {
        // De volgorde is van belang: MySQL werkt de kolommen van links naar
        // rechts bij, dus 'teller' rekent nog met de oude venster_start.
        $stmt = db()->prepare(
            'INSERT INTO aanvraag_limiet (sleutel, teller, venster_start)
             VALUES (:sleutel, 1, NOW())
             ON DUPLICATE KEY UPDATE
                 teller = IF(TIMESTAMPDIFF(MINUTE, venster_start, NOW()) >= :venster_a, 1, teller + 1),
                 venster_start = IF(TIMESTAMPDIFF(MINUTE, venster_start, NOW()) >= :venster_b, NOW(), venster_start)'
        );
        // Twee losse namen voor dezelfde waarde: PDO bindt elke naam één keer.
        $stmt->bindValue(':sleutel', substr($sleutel, 0, 190));
        $stmt->bindValue(':venster_a', $venster, PDO::PARAM_INT);
        $stmt->bindValue(':venster_b', $venster, PDO::PARAM_INT);
        $stmt->execute();
    } catch (Throwable $e) {
        app_log('rate limit bijwerken mislukt', ['fout' => $e->getMessage()]);
    }
}

/**
 * Rem op het invoeren van codes, per IP-adres.
 *
 * De pogingenteller per code beschermt één code, maar niet tegen iemand die
 * met veel adressen tegelijk gokt. Deze rem sluit dat gat: standaard maximaal
 * twintig codepogingen per kwartier vanaf hetzelfde IP-adres.
 */
function otp_verificatie_toegestaan(): bool
{
    $ip = client_ip();
    if ($ip === '') {
        return true;
    }
    return otp_rate_limit_check('verif:' . $ip, OTP_MAX_VERIFICATIES, OTP_VENSTER_VERIFICATIE);
}

/** Telt een codepoging mee voor de rem hierboven. */
function otp_verificatie_tellen(): void
{
    $ip = client_ip();
    if ($ip !== '') {
        otp_rate_limit_tellen('verif:' . $ip, OTP_VENSTER_VERIFICATIE);
    }
}

// ─── Opruimen ────────────────────────────────────────────────────────────────

/** Verwijdert verlopen codes en limietvensters ouder dan 24 uur. */
function otp_opschonen(): void
{
    try {
        db()->exec('DELETE FROM login_codes WHERE verloopt_op < (NOW() - INTERVAL 24 HOUR)');
        db()->exec('DELETE FROM aanvraag_limiet WHERE venster_start < (NOW() - INTERVAL 24 HOUR)');
    } catch (Throwable $e) {
        app_log('otp_opschonen mislukt', ['fout' => $e->getMessage()]);
    }
}
