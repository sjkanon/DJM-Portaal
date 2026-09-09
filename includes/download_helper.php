<?php

/**
 * Uitleveringslaag voor de videobestanden.
 *
 * Drie methoden, ingesteld via DELIVERY_MODE in .env:
 *   xaccel    nginx X-Accel-Redirect naar een internal location
 *   xsendfile Apache/Lighttpd X-Sendfile (mod_xsendfile)
 *   php       PHP levert zelf uit, met ondersteuning voor hervatten (HTTP Range)
 *
 * Bestandspaden komen nooit uit gebruikersinvoer: id -> database ->
 * opslag_absoluut_pad(). Deze module krijgt het bestand altijd al gecontroleerd
 * binnen.
 */

if (!function_exists('db')) {
    require_once dirname(__DIR__) . '/config.php';
}

/** Blokgrootte voor de PHP-uitlevering. */
const DOWNLOAD_BLOK = 8192;

// ─── Ondertekende downloadlinks ──────────────────────────────────────────────

/**
 * Bouwt een kortlevende, ondertekende downloadlink (5 minuten geldig).
 *
 * De handtekening is een extra laag naast de sessie: hij bindt de link aan één
 * deelnemer en één bestand, zodat een gekopieerde link bij iemand anders niets
 * doet en een oude link uit de browsergeschiedenis vanzelf waardeloos wordt.
 */
function download_link(int $bestandId, int $deelnemerId): string
{
    $vervalt      = time() + 300;
    $handtekening = hash_hmac('sha256', "$bestandId|$deelnemerId|$vervalt", app_key());

    return url('download.php') . '?b=' . $bestandId . '&t=' . $vervalt . '&s=' . $handtekening;
}

/**
 * Controleert een ondertekende downloadlink.
 *
 * Let op: dit vervángt de sessiecontrole niet. De echte autorisatie blijft
 * deelnemer_bestand(); dit is uitsluitend de tweede laag die voorkomt dat een
 * gedeelde of verlopen link nog bruikbaar is.
 */
function download_handtekening_geldig(int $bestandId, int $deelnemerId, int $vervalt, string $handtekening): bool
{
    if ($vervalt <= time() || $handtekening === '') {
        return false;
    }
    $verwacht = hash_hmac('sha256', "$bestandId|$deelnemerId|$vervalt", app_key());

    return hash_equals($verwacht, $handtekening);
}

// ─── Methodekeuze ────────────────────────────────────────────────────────────

/** Bepaalt hoe het bestand uitgeleverd wordt: xaccel, xsendfile of php. */
function download_methode(): string
{
    $modus = strtolower(trim(env('DELIVERY_MODE', 'auto')));

    if (in_array($modus, ['xaccel', 'xsendfile', 'php'], true)) {
        return $modus;
    }

    // auto: eerst nginx, dan Apache met mod_xsendfile, anders PHP zelf.
    if (stripos($_SERVER['SERVER_SOFTWARE'] ?? '', 'nginx') !== false) {
        return 'xaccel';
    }
    if (function_exists('apache_get_modules') && in_array('mod_xsendfile', apache_get_modules(), true)) {
        return 'xsendfile';
    }

    return 'php';
}

// ─── Mimetype ────────────────────────────────────────────────────────────────

/** Mimetype op basis van de bestandsextensie; anders de meegegeven standaard. */
function bestand_mime(string $pad, string $standaard): string
{
    $extensie = strtolower((string)pathinfo($pad, PATHINFO_EXTENSION));

    $kaart = [
        'mp4'  => 'video/mp4',
        'm4v'  => 'video/x-m4v',
        'mkv'  => 'video/x-matroska',
        'mov'  => 'video/quicktime',
        'avi'  => 'video/x-msvideo',
        'webm' => 'video/webm',
        'zip'  => 'application/zip',
        'pdf'  => 'application/pdf',
    ];

    if (isset($kaart[$extensie])) {
        return $kaart[$extensie];
    }

    $standaard = trim($standaard);

    return $standaard !== '' ? $standaard : 'application/octet-stream';
}

// ─── Logboek ─────────────────────────────────────────────────────────────────

/**
 * Schrijft de start van een download weg en geeft het logboek-id terug.
 * Geeft 0 terug als loggen mislukt; een kapot logboek mag een download niet
 * tegenhouden.
 */
function download_loggen(array $bestand, ?array $deelnemer, string $methode): int
{
    $grootte = (int)($bestand['bytes'] ?? 0);

    // Bij xaccel/xsendfile neemt de webserver de uitlevering over en zien wij
    // niet hoeveel er daadwerkelijk over de lijn ging. We noteren dan de volle
    // bestandsgrootte en markeren de regel meteen als afgerond; alleen de
    // PHP-methode werkt deze twee kolommen achteraf echt bij.
    $doorWebserver = $methode !== 'php';

    try {
        db()->prepare(
            'INSERT INTO download_log
                 (deelnemer_id, bestand_id, jaargang_id, email, bestandsnaam,
                  ip, user_agent, methode, bytes_verzonden, afgerond)
             VALUES
                 (:deelnemer, :bestand, :jaargang, :email, :bestandsnaam,
                  :ip, :ua, :methode, :bytes, :afgerond)'
        )->execute([
            ':deelnemer'    => $deelnemer !== null ? (int)$deelnemer['id'] : null,
            ':bestand'      => (int)($bestand['id'] ?? 0) ?: null,
            ':jaargang'     => (int)($bestand['jaargang_id'] ?? 0) ?: null,
            ':email'        => $deelnemer !== null ? substr((string)$deelnemer['email'], 0, 190) : null,
            ':bestandsnaam' => substr((string)($bestand['bestandsnaam'] ?? ''), 0, 255),
            ':ip'           => client_ip_bin(),
            ':ua'           => client_user_agent(),
            ':methode'      => $methode,
            ':bytes'        => $doorWebserver ? $grootte : 0,
            ':afgerond'     => $doorWebserver ? 1 : 0,
        ]);

        return (int)db()->lastInsertId();
    } catch (Throwable $e) {
        app_log('download_log schrijven mislukt', ['fout' => $e->getMessage()]);
        return 0;
    }
}

/**
 * Werkt een logregel bij na afloop van een PHP-uitlevering.
 * Een download kan een uur duren; de databaseverbinding kan dan verlopen zijn,
 * dus dit mag nooit een fatale fout opleveren.
 */
function download_log_bijwerken(?int $logId, int $bytesVerzonden, bool $afgerond): void
{
    if ($logId === null || $logId <= 0) {
        return;
    }
    try {
        db()->prepare(
            'UPDATE download_log
                SET bytes_verzonden = :bytes, afgerond = :afgerond
              WHERE id = :id'
        )->execute([
            ':bytes'    => max(0, $bytesVerzonden),
            ':afgerond' => $afgerond ? 1 : 0,
            ':id'       => $logId,
        ]);
    } catch (Throwable $e) {
        app_log('download_log bijwerken mislukt', ['fout' => $e->getMessage(), 'id' => $logId]);
    }
}

// ─── Headers ─────────────────────────────────────────────────────────────────

/** Content-headers die alle drie de methoden delen. */
function download_content_headers(array $bestand, string $absoluutPad, int $grootte): void
{
    $bestandsnaam = trim((string)($bestand['bestandsnaam'] ?? ''));
    if ($bestandsnaam === '') {
        $bestandsnaam = basename($absoluutPad);
    }

    // Nette naam voor moderne browsers (RFC 5987) plus een ASCII-variant als
    // vangnet voor oudere clients.
    $ascii = preg_replace('/[^\x20-\x7E]/', '_', $bestandsnaam);
    $ascii = str_replace(['"', '\\'], '_', (string)$ascii);

    header('Content-Type: ' . bestand_mime($absoluutPad, (string)($bestand['mime'] ?? '')));
    header('Content-Disposition: attachment; filename="' . $ascii . '"; '
        . "filename*=UTF-8''" . rawurlencode($bestandsnaam));
    header('Content-Length: ' . $grootte);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
}

/** Codeert elk padsegment afzonderlijk; de slashes blijven staan. */
function download_pad_coderen(string $relatiefPad): string
{
    $relatiefPad = ltrim(str_replace('\\', '/', $relatiefPad), '/');
    $segmenten   = array_map('rawurlencode', explode('/', $relatiefPad));

    return implode('/', $segmenten);
}

// ─── Uitlevering ─────────────────────────────────────────────────────────────

/**
 * Levert het bestand uit en beëindigt het script.
 *
 * @param int|null $logId regel in download_log die de PHP-methode bijwerkt.
 */
function download_uitleveren(array $bestand, string $absoluutPad, string $methode, ?int $logId = null): void
{
    $grootte = (int)@filesize($absoluutPad);
    if ($grootte <= 0) {
        $grootte = (int)($bestand['bytes'] ?? 0);
    }

    if ($methode === 'xaccel') {
        $prefix = rtrim(env('XACCEL_PREFIX', '/beveiligd/'), '/');
        header('X-Accel-Redirect: ' . $prefix . '/' . download_pad_coderen((string)($bestand['pad'] ?? '')));
        download_content_headers($bestand, $absoluutPad, $grootte);
        // Accept-Ranges en de daadwerkelijke bytes laat nginx zelf afhandelen.
        exit;
    }

    if ($methode === 'xsendfile') {
        header('X-Sendfile: ' . $absoluutPad);
        download_content_headers($bestand, $absoluutPad, $grootte);
        exit;
    }

    download_uitleveren_php($bestand, $absoluutPad, $grootte, $logId);
}

/** PHP levert zelf uit, in blokken, met ondersteuning voor hervatten. */
function download_uitleveren_php(array $bestand, string $absoluutPad, int $grootte, ?int $logId): void
{
    // De sessie is een bestandslock: zonder dit blijft de hele browser hangen
    // zolang de download loopt (en dat kan een uur zijn).
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    // Alles wat nog gebufferd staat weg, anders komt het vóór de videobytes in
    // de uitvoer terecht en klopt de Content-Length niet.
    while (ob_get_level()) {
        ob_end_clean();
    }
    set_time_limit(0);
    ignore_user_abort(true);

    header('Accept-Ranges: bytes');

    $start = 0;
    $eind  = $grootte > 0 ? $grootte - 1 : 0;
    $range = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));

    if ($range !== '') {
        // Alleen één enkele bereikaanvraag: bytes=start-eind, bytes=start- of
        // bytes=-laatste_n. Meervoudige bereiken (met komma's) ondersteunen we
        // bewust niet; downloadmanagers vallen dan terug op één bereik.
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m) || ($m[1] === '' && $m[2] === '')) {
            download_range_afwijzen($grootte);
        }

        if ($m[1] === '') {
            // bytes=-n : de laatste n bytes.
            $laatste = (int)$m[2];
            if ($laatste <= 0) {
                download_range_afwijzen($grootte);
            }
            $start = max(0, $grootte - $laatste);
            $eind  = $grootte - 1;
        } else {
            $start = (int)$m[1];
            $eind  = $m[2] === '' ? $grootte - 1 : (int)$m[2];
            if ($eind > $grootte - 1) {
                $eind = $grootte - 1;
            }
        }

        if ($grootte <= 0 || $start > $eind || $start >= $grootte) {
            download_range_afwijzen($grootte);
        }

        http_response_code(206);
        header("Content-Range: bytes $start-$eind/$grootte");
    }

    $lengte = $grootte > 0 ? ($eind - $start + 1) : 0;

    download_content_headers($bestand, $absoluutPad, $lengte);

    $handvat = @fopen($absoluutPad, 'rb');
    if ($handvat === false) {
        app_log('bestand kon niet geopend worden', ['pad' => $absoluutPad]);
        http_response_code(500);
        exit;
    }

    if ($start > 0) {
        fseek($handvat, $start);
    }

    $verzonden = 0;
    while ($verzonden < $lengte && !feof($handvat) && !connection_aborted()) {
        $blok = fread($handvat, (int)min(DOWNLOAD_BLOK, $lengte - $verzonden));
        if ($blok === false || $blok === '') {
            break;
        }
        echo $blok;
        flush();
        $verzonden += strlen($blok);
    }
    fclose($handvat);

    download_log_bijwerken($logId, $verzonden, $verzonden >= $lengte);
    exit;
}

/** Ongeldige of onbereikbare Range: 416 met het toegestane bereik. */
function download_range_afwijzen(int $grootte): void
{
    http_response_code(416);
    header('Content-Range: bytes */' . $grootte);
    exit;
}
