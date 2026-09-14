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
 *
 * Eén regel geldt voor het hele downloadpad: een verzoek om een bestand krijgt
 * óf bytes van dat bestand, óf een foutstatus — nooit een HTML-pagina met
 * status 200, en nooit een omleiding naar een gewone pagina. Een browser of
 * downloadmanager bewaart zo'n pagina namelijk gewoon op schijf, en dan vindt
 * de deelnemer een bestand van vier kilobyte met de naam index.php in zijn
 * downloadmap in plaats van de video.
 */

if (!function_exists('db')) {
    require_once dirname(__DIR__) . '/config.php';
}

/**
 * Blokgrootte voor de PHP-uitlevering: 256 KB.
 *
 * Bij 8 KB gaat een bestand van 4 GB in een half miljoen rondjes door de lus,
 * elk met een eigen fread(), echo en flush(). Dat kost merkbaar processortijd
 * zonder dat er iets tegenover staat; het geheugengebruik blijft ook bij 256 KB
 * verwaarloosbaar, want er staat nooit meer dan één blok in het geheugen.
 */
const DOWNLOAD_BLOK = 262144;

/**
 * Hoe vaak de voortgang naar het logboek gaat: elke 64 MB.
 *
 * Vaak genoeg om bij een afgebroken download te zien hoe ver iemand kwam, en
 * zeldzaam genoeg om er geen database mee te belasten: bij een video van 7 GB
 * zijn het een kleine honderd kleine updates, verdeeld over een paar minuten.
 */
const DOWNLOAD_VOORTGANG = 67108864;

/**
 * Hoe lang een ondertekende downloadlink geldig blijft: twaalf uur.
 *
 * Dit stond op vijf minuten, en dat is te kort voor waar het portaal voor
 * bedoeld is: bestanden van meerdere gigabytes, die uren kunnen lopen en soms
 * een dag later hervat worden. Verloopt de link tóch, dan is dat geen fout —
 * download.php geeft dan een verse link af. De echte toegangscontrole is en
 * blijft de sessie plus deelnemer_bestand(); de handtekening is de tweede laag
 * die een gekopieerde link waardeloos maakt voor iemand anders.
 */
const DOWNLOAD_LINK_GELDIG = 43200;

// ─── Ondertekende downloadlinks ──────────────────────────────────────────────

/**
 * Bouwt een ondertekende downloadlink.
 *
 * De handtekening is een extra laag naast de sessie: hij bindt de link aan één
 * deelnemer en één bestand, zodat een gekopieerde link bij iemand anders niets
 * doet en een oude link uit de browsergeschiedenis vanzelf waardeloos wordt.
 *
 * @param bool $vernieuwd Zet v=<nu>: deze link is zojuist door download.php in
 *                        de plaats van een verlopen link gegeven. Wordt hij
 *                        meteen daarna alsnog afgekeurd, dan is er iets
 *                        structureel mis (de serverklok loopt terug, of twee
 *                        servers achter dezelfde naam hebben een verschillende
 *                        APP_KEY) en stopt download.php in plaats van nóg eens
 *                        door te sturen. Zonder die rem zou dat een oneindige
 *                        lus worden. Het is een tijdstip en geen vlaggetje,
 *                        want ook een vernieuwde link mag over twaalf uur
 *                        gewoon opnieuw vernieuwd worden.
 */
function download_link(int $bestandId, int $deelnemerId, bool $vernieuwd = false): string
{
    $vervalt      = time() + DOWNLOAD_LINK_GELDIG;
    $handtekening = hash_hmac('sha256', "$bestandId|$deelnemerId|$vervalt", app_key());

    return url('download.php') . '?b=' . $bestandId . '&t=' . $vervalt . '&s=' . $handtekening
        . ($vernieuwd ? '&v=' . time() : '');
}

/**
 * Is deze link net vernieuwd? Dan heeft nóg een keer vernieuwen geen zin.
 * Twee minuten speling, ruim genoeg voor een trage verbinding.
 */
function download_link_net_vernieuwd(string $v): bool
{
    $moment = (int)$v;

    return $moment > 0 && abs(time() - $moment) < 120;
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
 * Zorgt dat download_log de kolom `reden` heeft.
 *
 * `db.sql` draait alleen bij de installatie, dus een portaal dat al draaide
 * krijgt de kolom hier. Lukt dat niet — bijvoorbeeld omdat de databasegebruiker
 * geen ALTER mag — dan gaat het loggen gewoon door zonder die kolom; een
 * logboek mag nooit een download tegenhouden.
 */
function download_log_reden_kolom(): bool
{
    static $aanwezig = null;
    if ($aanwezig !== null) {
        return $aanwezig;
    }
    try {
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'download_log'
                AND column_name = 'reden'"
        );
        $stmt->execute();
        if ((int)$stmt->fetchColumn() === 0) {
            db()->exec('ALTER TABLE download_log ADD COLUMN reden VARCHAR(24) NULL AFTER afgerond');
        }
        $aanwezig = true;
    } catch (Throwable $e) {
        app_log('kolom reden toevoegen aan download_log mislukt', ['fout' => $e->getMessage()]);
        $aanwezig = false;
    }

    return $aanwezig;
}

/**
 * Schrijft de start van een download weg en geeft het logboek-id terug.
 * Geeft 0 terug als loggen mislukt; een kapot logboek mag een download niet
 * tegenhouden.
 */
function download_loggen(array $bestand, ?array $deelnemer, string $methode): int
{
    // Bij xaccel/xsendfile neemt de webserver de uitlevering over. Wij zien dan
    // geen enkele byte voorbijkomen en kunnen dus niet zeggen hoe ver iemand
    // kwam. Dat noteren we ook zo, in plaats van de volle grootte te doen alsof:
    // een logboek dat gokt, is erger dan een logboek dat "niet gemeten" zegt.
    $doorWebserver = $methode !== 'php';
    $metReden      = download_log_reden_kolom();

    $sql = 'INSERT INTO download_log
                (deelnemer_id, bestand_id, jaargang_id, email, bestandsnaam,
                 ip, user_agent, methode, bytes_verzonden, afgerond'
        . ($metReden ? ', reden' : '') . ')
            VALUES
                (:deelnemer, :bestand, :jaargang, :email, :bestandsnaam,
                 :ip, :ua, :methode, :bytes, :afgerond'
        . ($metReden ? ', :reden' : '') . ')';

    $waarden = [
        ':deelnemer'    => $deelnemer !== null ? (int)$deelnemer['id'] : null,
        ':bestand'      => (int)($bestand['id'] ?? 0) ?: null,
        ':jaargang'     => (int)($bestand['jaargang_id'] ?? 0) ?: null,
        ':email'        => $deelnemer !== null ? substr((string)$deelnemer['email'], 0, 190) : null,
        ':bestandsnaam' => substr((string)($bestand['bestandsnaam'] ?? ''), 0, 255),
        ':ip'           => client_ip_bin(),
        ':ua'           => client_user_agent(),
        ':methode'      => $methode,
        ':bytes'        => 0,
        ':afgerond'     => 0,
    ];
    if ($metReden) {
        $waarden[':reden'] = $doorWebserver ? 'webserver' : 'bezig';
    }

    try {
        db()->prepare($sql)->execute($waarden);

        return (int)db()->lastInsertId();
    } catch (Throwable $e) {
        app_log('download_log schrijven mislukt', ['fout' => $e->getMessage()]);
        return 0;
    }
}

/**
 * Werkt een logregel bij: tijdens de uitlevering de voortgang, aan het eind de
 * uitkomst.
 *
 * Een download kan een uur duren; de databaseverbinding kan dan verlopen zijn,
 * dus dit mag nooit een fatale fout opleveren.
 *
 * @param string $reden bezig | voltooid | client_gestopt | server_gestopt
 */
function download_log_bijwerken(?int $logId, int $bytesVerzonden, bool $afgerond, string $reden = 'bezig'): void
{
    if ($logId === null || $logId <= 0) {
        return;
    }
    $metReden = download_log_reden_kolom();

    $sql = 'UPDATE download_log
               SET bytes_verzonden = :bytes, afgerond = :afgerond'
        . ($metReden ? ', reden = :reden' : '') . '
             WHERE id = :id';

    $waarden = [
        ':bytes'    => max(0, $bytesVerzonden),
        ':afgerond' => $afgerond ? 1 : 0,
        ':id'       => $logId,
    ];
    if ($metReden) {
        $waarden[':reden'] = $reden;
    }

    try {
        db()->prepare($sql)->execute($waarden);
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

/**
 * Kenmerk van deze versie van het bestand: grootte plus wijzigingsmoment.
 *
 * Een downloadmanager die hervat, stuurt dit terug in If-Range. Klopt het niet
 * meer — de beheerder heeft het bestand vervangen of opnieuw geüpload — dan mag
 * hij niet verdergaan waar hij gebleven was, want dan plakt hij twee
 * verschillende video's aan elkaar en is het eindresultaat onafspeelbaar.
 *
 * @return array{0: string, 1: int} het etag (mét aanhalingstekens) en de mtime
 */
function download_kenmerk(string $absoluutPad, int $grootte): array
{
    $gewijzigd = (int)@filemtime($absoluutPad);

    return ['"' . dechex($grootte) . '-' . dechex(max(0, $gewijzigd)) . '"', $gewijzigd];
}

/**
 * Mag er hervat worden? Zonder If-Range wel; mét If-Range alleen als het
 * kenmerk nog klopt.
 */
function download_if_range_geldig(string $etag, int $gewijzigd): bool
{
    $ifRange = trim((string)($_SERVER['HTTP_IF_RANGE'] ?? ''));
    if ($ifRange === '') {
        return true;
    }
    if ($ifRange[0] === '"' || str_starts_with($ifRange, 'W/')) {
        return ltrim($ifRange, 'W/') === $etag;
    }

    // Geen etag maar een datum: die moet precies het wijzigingsmoment zijn.
    $datum = strtotime($ifRange);

    return $datum !== false && $gewijzigd > 0 && $datum === $gewijzigd;
}

/** Codeert elk padsegment afzonderlijk; de slashes blijven staan. */
function download_pad_coderen(string $relatiefPad): string
{
    $relatiefPad = ltrim(str_replace('\\', '/', $relatiefPad), '/');
    $segmenten   = array_map('rawurlencode', explode('/', $relatiefPad));

    return implode('/', $segmenten);
}

/**
 * Zet alle compressie en buffering uit die tussen fread() en de netwerkkaart
 * kan zitten.
 *
 * Comprimeert de server de uitvoer alsnog, dan klopt de aangekondigde
 * Content-Length niet met wat er over de lijn gaat. De browser kapt af op het
 * aangekondigde aantal bytes en de deelnemer houdt een halve, onafspeelbare
 * video over. De regels in .htaccess dekken alleen mod_php; onder PHP-FPM doen
 * ze niets, vandaar dat het hier nog eens gebeurt.
 */
function download_compressie_uit(): void
{
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    @ini_set('implicit_flush', '1');

    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
        @apache_setenv('dont-vary', '1');
    }
}

/**
 * Vraagt een nginx die vóór ons staat om deze ene reactie niet te bufferen.
 *
 * Op hostingpanelen als Plesk staat nginx als proxy voor Apache. Met de
 * standaardinstellingen schrijft hij het antwoord eerst naar een tijdelijk
 * bestand, en bij `proxy_max_temp_file_size 1024m` stopt hij na één gigabyte met
 * lezen. PHP loopt dan vast op een volle buffer, de webserver verbreekt de
 * verbinding — in het nginx-log `upstream prematurely closed connection` — en de
 * deelnemer houdt precies één gigabyte van een video van zeven over. Deze header
 * zet de buffering voor dit antwoord uit, zonder dat er iets aan de
 * serverinstellingen hoeft te veranderen. Staat er geen nginx voor, dan is het
 * een header die niemand leest.
 */
function download_buffering_uit(): void
{
    header('X-Accel-Buffering: no');
}

// ─── Uitlevering ─────────────────────────────────────────────────────────────

/**
 * Levert het bestand uit en beëindigt het script.
 *
 * @param int|null $logId regel in download_log die de PHP-methode bijwerkt.
 */
function download_uitleveren(array $bestand, string $absoluutPad, string $methode, ?int $logId = null): void
{
    $grootte  = (int)@filesize($absoluutPad);
    $verwacht = (int)($bestand['bytes'] ?? 0);

    if ($grootte <= 0) {
        $grootte = $verwacht;
    } elseif ($verwacht > 0 && $grootte !== $verwacht) {
        // Wat op schijf staat is leidend — kondigen we meer bytes aan dan we
        // hebben, dan wacht de browser eindeloos op de rest en blijft het
        // bestand onafgerond. Wel melden: dit betekent dat het bestand na het
        // koppelen is vervangen of maar half is overgezet. Beheer ›
        // Bestandscontrole laat hetzelfde zien.
        app_log('bestandsgrootte wijkt af van de database', [
            'bestand_id' => (int)($bestand['id'] ?? 0),
            'op_schijf'  => $grootte,
            'database'   => $verwacht,
        ]);
    }

    if ($methode === 'xaccel') {
        $prefix = rtrim(env('XACCEL_PREFIX', '/beveiligd/'), '/');
        header('X-Accel-Redirect: ' . $prefix . '/' . download_pad_coderen((string)($bestand['pad'] ?? '')));
        download_content_headers($bestand, $absoluutPad, $grootte);
        // Accept-Ranges, Content-Length en de daadwerkelijke bytes laat nginx
        // zelf afhandelen; hij vervangt onze Content-Length door de echte.
        exit;
    }

    if ($methode === 'xsendfile') {
        header('X-Sendfile: ' . $absoluutPad);
        download_buffering_uit();
        download_content_headers($bestand, $absoluutPad, $grootte);
        // mod_xsendfile honoreert Range-verzoeken wel, maar kondigt dat niet aan.
        // Zonder deze header gaan downloadmanagers ervan uit dat hervatten niet
        // kan, en beginnen ze na een onderbreking helemaal opnieuw.
        header('Accept-Ranges: bytes');
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
    download_compressie_uit();
    download_buffering_uit();
    set_time_limit(0);
    ignore_user_abort(true);

    [$etag, $gewijzigd] = download_kenmerk($absoluutPad, $grootte);

    header('Accept-Ranges: bytes');
    header('ETag: ' . $etag);
    if ($gewijzigd > 0) {
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $gewijzigd) . ' GMT');
    }

    $start = 0;
    $eind  = $grootte > 0 ? $grootte - 1 : 0;
    $range = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));

    // Hervatten op een bestand dat inmiddels vervangen is: negeer het bereik en
    // stuur de hele, nieuwe video. Beter opnieuw beginnen dan twee versies aan
    // elkaar geplakt.
    if ($range !== '' && !download_if_range_geldig($etag, $gewijzigd)) {
        $range = '';
    }

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

    // Downloadmanagers vragen eerst met HEAD of hervatten kan; ze willen alleen
    // de headers. Onder PHP-FPM zou de rest van deze functie de bytes gewoon
    // meesturen.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
        exit;
    }

    $handvat = @fopen($absoluutPad, 'rb');
    if ($handvat === false) {
        app_log('bestand kon niet geopend worden', ['pad' => $absoluutPad]);
        http_response_code(500);
        exit;
    }

    if ($start > 0) {
        fseek($handvat, $start);
    }

    $verzonden    = 0;
    $sindsLaatste = 0;
    $leesfout     = false;

    while ($verzonden < $lengte && !feof($handvat) && !connection_aborted()) {
        $blok = fread($handvat, (int)min(DOWNLOAD_BLOK, $lengte - $verzonden));
        if ($blok === false || $blok === '') {
            // Het bestand houdt eerder op dan de grootte belooft, of de schijf
            // geeft een fout. Dat ligt hoe dan ook aan onze kant.
            $leesfout = true;
            break;
        }
        echo $blok;
        flush();
        $verzonden    += strlen($blok);
        $sindsLaatste += strlen($blok);

        // Tussentijds wegschrijven hoe ver we zijn. Zonder dit staat er tijdens
        // een download van een uur nog niets in het logboek, en blijft er bij
        // een proces dat halverwege wordt afgeschoten "0 bytes" staan — precies
        // in het geval dat je wilt onderzoeken.
        if ($sindsLaatste >= DOWNLOAD_VOORTGANG) {
            download_log_bijwerken($logId, $verzonden, false, 'bezig');
            $sindsLaatste = 0;
        }
    }
    fclose($handvat);

    // Waarom stopte het? Dit is het verschil tussen "de ouder heeft een slechte
    // verbinding" en "onze server knipt downloads af", en dat scheelt een
    // beheerder een middag zoeken.
    if ($verzonden >= $lengte) {
        $reden = 'voltooid';
    } elseif (connection_aborted()) {
        $reden = 'client_gestopt';        // browser dicht, netwerk weg, geannuleerd
    } else {
        $reden = 'server_gestopt';        // leesfout, tijdslimiet, afgebroken proces
    }
    if ($leesfout) {
        app_log('uitlevering brak af op een leesfout', [
            'bestand_id' => (int)($bestand['id'] ?? 0),
            'verzonden'  => $verzonden,
            'verwacht'   => $lengte,
        ]);
    }

    download_log_bijwerken($logId, $verzonden, $verzonden >= $lengte, $reden);
    exit;
}

/** Ongeldige of onbereikbare Range: 416 met het toegestane bereik. */
function download_range_afwijzen(int $grootte): void
{
    http_response_code(416);
    header('Content-Range: bytes */' . $grootte);
    exit;
}
