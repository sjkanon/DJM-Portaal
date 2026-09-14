<?php

/**
 * Achteraf uitlezen hoe ver een download kwam, uit het log van de webserver.
 *
 * Levert de webserver zelf uit — X-Accel-Redirect of X-Sendfile — dan komt er
 * geen byte langs PHP en kan het portaal niet zien hoe ver een bezoeker kwam.
 * De webserver weet het wél: hij noteert per verzoek hoeveel hij verstuurd
 * heeft. Deze module zoekt die getallen op en schrijft ze alsnog in
 * `download_log`, zodat het downloadlogboek ook bij de snelle uitlevering
 * antwoord geeft op "tot waar is hij gekomen?".
 *
 * De koppeling is exact, geen benadering. Elke downloadlink draagt een
 * handtekening (`s=`) die uniek is voor één bestand, één deelnemer en één
 * vervalmoment; `download_log.sleutel` bewaart daar de eerste zestien tekens
 * van, en die staan ook in de logregel. Matchen op tijdstip en IP-adres zou
 * misgaan zodra twee mensen achter dezelfde router tegelijk downloaden.
 *
 * Alles hier is optioneel en mag nooit een fout opleveren: staat het log er
 * niet, is het onleesbaar of heeft het een ander formaat, dan blijft de regel
 * gewoon op "niet gemeten" staan.
 */

if (!function_exists('db')) {
    require_once dirname(__DIR__) . '/config.php';
}

/** Nooit meer dan dit van een logbestand lezen: alleen de staart telt. */
const WEBSERVERLOG_MAX_BYTES = 8388608;

/**
 * Downloads ouder dan een week proberen we niet meer op te zoeken.
 *
 * Webserverlogs roteren dagelijks en gaan uiteindelijk in een archief. Wat daar
 * niet meer in staat, vinden we nooit — en zonder deze grens zou elke keer dat
 * iemand het downloadtabblad opent opnieuw het hele log doorzocht worden voor
 * regels die er nooit meer in zullen staan.
 */
const WEBSERVERLOG_MAX_OUDERDOM = 604800;

/**
 * De logbestanden die we mogen doorzoeken, nieuwste eerst.
 *
 * `WEBSERVER_LOG` in .env wijst het aan. Staat die leeg, dan proberen we de
 * indeling van hostingpanelen als Plesk: naast de documentroot een map `logs`
 * met daarin een map per site. Het geroteerde bestand (`.processed`) gaat mee,
 * want een download van gisteren staat daar inmiddels in.
 *
 * Let op: dit moet het log van de *voorste* webserver zijn. Staat nginx voor
 * Apache, dan is `access_ssl_log` (Apache) het verkeerde bestand — daar staat
 * alleen het lege antwoord waarmee PHP de uitlevering doorgaf, een kilobyte of
 * zes. Alleen `proxy_access_ssl_log` (nginx) heeft de echte aantallen.
 *
 * @return string[] bestaande, leesbare paden
 */
function webserverlog_paden(): array
{
    $ingesteld = trim((string)env('WEBSERVER_LOG', ''));

    if ($ingesteld !== '') {
        $kandidaten = [$ingesteld, $ingesteld . '.processed'];
    } else {
        $basis = dirname(APP_ROOT) . '/logs/' . basename(APP_ROOT);
        $kandidaten = [
            $basis . '/proxy_access_ssl_log',
            $basis . '/proxy_access_ssl_log.processed',
            $basis . '/proxy_access_log',
        ];
    }

    $paden = [];
    foreach ($kandidaten as $pad) {
        if (@is_file($pad) && @is_readable($pad)) {
            $paden[] = $pad;
        }
    }

    return $paden;
}

/** Is het uitlezen van het webserverlog hier bruikbaar? */
function webserverlog_beschikbaar(): bool
{
    return webserverlog_paden() !== [];
}

/**
 * Haalt uit één logregel de handtekening, de status en het aantal bytes.
 *
 * Verwacht het gangbare combined-formaat:
 *   ip - - [datum] "GET /download.php?b=1&t=..&s=.. HTTP/2.0" 200 6998877816 "ref" "ua"
 *
 * @return array{sleutel: string, status: int, bytes: int}|null
 */
function webserverlog_regel_ontleden(string $regel): ?array
{
    if (strpos($regel, '/download.php?') === false) {
        return null;
    }
    if (!preg_match('~"(?:GET|HEAD) (/download\.php\?\S*) HTTP/[\d.]+" (\d{3}) (\d+)~', $regel, $m)) {
        return null;
    }

    parse_str((string)parse_url($m[1], PHP_URL_QUERY), $vraag);
    $handtekening = (string)($vraag['s'] ?? '');
    if (!preg_match('/^[0-9a-f]{16,}$/', $handtekening)) {
        return null;
    }

    return [
        'sleutel' => substr($handtekening, 0, 16),
        'status'  => (int)$m[2],
        'bytes'   => (int)$m[3],
    ];
}

/**
 * Zoekt per sleutel op hoeveel bytes de webserver verstuurd heeft.
 *
 * Bytes van meerdere regels met dezelfde sleutel worden opgeteld: dat is precies
 * wat een hervatte download is — twee verzoeken op dezelfde link die samen het
 * bestand vormen. De aanroeper begrenst de uitkomst op de bestandsgrootte,
 * zodat twee volledige downloads op dezelfde link geen dubbele grootte opleveren.
 *
 * @param  string[] $sleutels
 * @return array<string, array{status: int, bytes: int}>
 */
function webserverlog_zoeken(array $sleutels): array
{
    $gezocht = array_fill_keys($sleutels, true);
    $gevonden = [];

    foreach (webserverlog_paden() as $pad) {
        $handvat = @fopen($pad, 'rb');
        if ($handvat === false) {
            continue;
        }

        // Alleen de staart: een logboek van maanden hoeven we niet door voor een
        // download van gisteren.
        $grootte = (int)@filesize($pad);
        if ($grootte > WEBSERVERLOG_MAX_BYTES) {
            fseek($handvat, $grootte - WEBSERVERLOG_MAX_BYTES);
            fgets($handvat);                       // halve eerste regel weggooien
        }

        while (($regel = fgets($handvat)) !== false) {
            $deel = webserverlog_regel_ontleden($regel);
            if ($deel === null || !isset($gezocht[$deel['sleutel']])) {
                continue;
            }
            $sleutel = $deel['sleutel'];
            $gevonden[$sleutel] = [
                'status' => $deel['status'],
                'bytes'  => (int)($gevonden[$sleutel]['bytes'] ?? 0) + $deel['bytes'],
            ];
        }
        fclose($handvat);
    }

    return $gevonden;
}

/**
 * Vult voor de meegegeven logboekregels alsnog in hoe ver ze kwamen.
 *
 * Werkt alleen op regels die de webserver uitleverde en die nog niet gemeten
 * zijn. Wat gevonden wordt, gaat meteen de database in: dan is het eenmalig werk
 * en maakt het niet uit dat het logbestand morgen geroteerd is.
 *
 * @param  array $rijen regels uit download_log, met sleutel, reden en bestand_bytes
 * @return array<int, array{bytes_verzonden: int, afgerond: int, reden: string}> per logboek-id
 */
function webserverlog_verrijken(array $rijen): array
{
    $grens = time() - WEBSERVERLOG_MAX_OUDERDOM;

    $openstaand = [];
    foreach ($rijen as $rij) {
        $sleutel = (string)($rij['sleutel'] ?? '');
        if ($sleutel === '' || (string)($rij['reden'] ?? '') !== 'webserver') {
            continue;
        }
        // Twee keer op dezelfde knop klikken geeft twee regels met dezelfde
        // sleutel. De rijen komen nieuwste eerst binnen; de eerste die we
        // tegenkomen is dus de juiste om het logboekgetal aan te hangen.
        if (isset($openstaand[$sleutel])) {
            continue;
        }
        // Voorbij de bewaartermijn van het log valt er niets meer te vinden.
        // Zonder deze grens doorzoekt elke paginalading het hele log opnieuw
        // voor regels die er nooit meer in zullen staan.
        $gestart = strtotime((string)($rij['gestart_op'] ?? '')) ?: 0;
        if ($gestart > 0 && $gestart < $grens) {
            continue;
        }
        $openstaand[$sleutel] = $rij;
    }
    if ($openstaand === [] || !webserverlog_beschikbaar()) {
        return [];
    }

    $gevonden = webserverlog_zoeken(array_keys($openstaand));
    if ($gevonden === []) {
        return [];
    }

    $bijgewerkt = [];
    foreach ($gevonden as $sleutel => $meting) {
        $rij      = $openstaand[$sleutel];
        $verwacht = (int)($rij['bestand_bytes'] ?? 0);
        $bytes    = $verwacht > 0 ? min($meting['bytes'], $verwacht) : $meting['bytes'];

        // Zonder noemer kunnen we niets concluderen: dan noteren we wel de bytes,
        // maar laten we de reden met rust.
        if ($verwacht <= 0) {
            continue;
        }

        $afgerond = $bytes >= $verwacht;
        // Ook hier weet niemand wíé de verbinding verbrak — nginx net zomin als
        // PHP. "client_gestopt" draagt in het beheer het eerlijke label
        // "verbinding verbroken".
        $reden = $afgerond ? 'voltooid' : 'client_gestopt';

        try {
            db()->prepare(
                'UPDATE download_log
                    SET bytes_verzonden = :bytes, afgerond = :afgerond, reden = :reden
                  WHERE id = :id AND reden = :was'
            )->execute([
                ':bytes'    => $bytes,
                ':afgerond' => $afgerond ? 1 : 0,
                ':reden'    => $reden,
                ':id'       => (int)$rij['id'],
                ':was'      => 'webserver',
            ]);
        } catch (Throwable $e) {
            app_log('verrijken uit het webserverlog mislukt', [
                'fout' => $e->getMessage(),
                'id'   => (int)$rij['id'],
            ]);
            continue;
        }

        $bijgewerkt[(int)$rij['id']] = [
            'bytes_verzonden' => $bytes,
            'afgerond'        => $afgerond ? 1 : 0,
            'reden'           => $reden,
        ];
    }

    return $bijgewerkt;
}
