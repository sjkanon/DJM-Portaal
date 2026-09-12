<?php

/**
 * Controle van de uitleveringslaag: de juiste serverconfiguratie tonen, en die
 * configuratie daarna echt uitproberen.
 *
 * "Technische status" laat zien welke methode gedetecteerd wórdt. Dat is niet
 * hetzelfde als bewijzen dat hij wérkt: een ontbrekend `internal`-blok in nginx
 * of een niet-geladen mod_xsendfile levert een lege download op. Zonder deze
 * test merkt de beheerder dat pas bij de eerste ouder die een video van 5 GB
 * probeert op te halen.
 *
 * De zelftest legt daarom een klein testbestand in de opslagmap, haalt het via
 * zelftest.php over HTTP op — exact dezelfde uitleveringslaag als download.php
 * gebruikt — en ruimt het daarna weer op.
 */

if (!function_exists('db')) {
    require_once dirname(__DIR__) . '/config.php';
}
require_once __DIR__ . '/download_helper.php';

/** Submap in de opslagmap waarin het testbestand tijdelijk staat. */
const ZELFTEST_MAP = 'zelftest';

/** Naam van het testbestand. Geen video-extensie: het is geen jaargangbestand. */
const ZELFTEST_BESTAND = 'uitlevering-zelftest.bin';

/** Grootte van het testbestand: groot genoeg voor een bereikaanvraag, klein genoeg om niets te merken. */
const ZELFTEST_BYTES = 65536;

/** Hoe lang een ondertekende zelftest-URL geldig is. */
const ZELFTEST_GELDIG_SECONDEN = 120;

/** Bereik dat de hervattest opvraagt. */
const ZELFTEST_RANGE_START = 1000;
const ZELFTEST_RANGE_EIND  = 1999;

const ZELFTEST_CONNECT_TIMEOUT = 8;
const ZELFTEST_TIMEOUT         = 25;

// ─── Paden en ondertekening ──────────────────────────────────────────────────

/**
 * Naam waaronder het testbestand wordt aangeboden.
 *
 * De uitleveringsmethode zit in de naam verwerkt omdat Content-Disposition het
 * enige is dat alle drie de routes ongeschonden doorgeven: nginx laat bij een
 * X-Accel-Redirect de meeste headers van PHP vallen, en dan is een eigen
 * X-header geen bruikbaar signaal meer.
 */
function zelftest_downloadnaam(string $methode): string
{
    return 'uitlevering-zelftest-' . $methode . '.bin';
}

/** Leest de methode terug uit de Content-Disposition van het antwoord. */
function zelftest_methode_uit_headers(array $headers): string
{
    $velden = [
        (string)($headers['x-djm-zelftest'] ?? ''),
        (string)($headers['content-disposition'] ?? ''),
    ];

    foreach ($velden as $veld) {
        if ($veld === '') {
            continue;
        }
        if (in_array($veld, ['xaccel', 'xsendfile', 'php'], true)) {
            return $veld;
        }
        if (preg_match('/uitlevering-zelftest-(xaccel|xsendfile|php)\.bin/', $veld, $m)) {
            return $m[1];
        }
    }

    return '';
}

/** Pad van het testbestand, relatief ten opzichte van de opslagmap. */
function zelftest_relatief_pad(): string
{
    return ZELFTEST_MAP . '/' . ZELFTEST_BESTAND;
}

/**
 * Handtekening over een zelftest-URL.
 *
 * Zelfde patroon als download_link(): het endpoint heeft geen sessie, want het
 * verzoek komt van de server zelf. De handtekening met APP_KEY is dus de enige
 * toegangscontrole — daarom kortlevend, en daarom levert het endpoint nooit
 * iets anders uit dan dit ene testbestand.
 */
function zelftest_handtekening(int $vervalt): string
{
    return hash_hmac('sha256', 'uitlevering-zelftest|' . $vervalt, app_key());
}

function zelftest_handtekening_geldig(int $vervalt, string $handtekening): bool
{
    if ($vervalt <= time() || $handtekening === '') {
        return false;
    }

    return hash_equals(zelftest_handtekening($vervalt), $handtekening);
}

/** Ondertekende URL naar het zelftest-endpoint. */
function zelftest_url(string $basisUrl): string
{
    $vervalt = time() + ZELFTEST_GELDIG_SECONDEN;

    return rtrim($basisUrl, '/') . '/zelftest.php'
        . '?t=' . $vervalt . '&s=' . zelftest_handtekening($vervalt);
}

// ─── Testbestand ─────────────────────────────────────────────────────────────

/**
 * Legt het testbestand klaar in de opslagmap.
 *
 * @param string $fout Wordt gevuld als het niet lukt.
 * @return string|null Absoluut pad, of null bij een fout.
 */
function zelftest_bestand_aanmaken(string &$fout): ?string
{
    $map = opslag_pad() . '/' . ZELFTEST_MAP;

    if (!is_dir($map) && !@mkdir($map, 0775, true) && !is_dir($map)) {
        $fout = 'De map ' . $map . ' kon niet worden aangemaakt. '
            . 'De opslagmap is niet schrijfbaar voor de webserver.';
        return null;
    }

    $pad = $map . '/' . ZELFTEST_BESTAND;
    if (@file_put_contents($pad, random_bytes(ZELFTEST_BYTES)) !== ZELFTEST_BYTES) {
        $fout = 'Het testbestand kon niet in ' . $map . ' worden geschreven.';
        return null;
    }

    return $pad;
}

/** Ruimt het testbestand en zijn map weer op. */
function zelftest_opruimen(): void
{
    $map = opslag_pad() . '/' . ZELFTEST_MAP;
    @unlink($map . '/' . ZELFTEST_BESTAND);
    @rmdir($map);
}

// ─── HTTP ────────────────────────────────────────────────────────────────────

/**
 * Eén HTTP-verzoek van de server naar zichzelf.
 *
 * @return array{status:int,headers:array<string,string>,body:string,fout:string,onveilig:bool}
 */
function zelftest_verzoek(string $url, string $range = '', bool $verifieer = true): array
{
    $uit = [
        'status'   => 0,
        'headers'  => [],
        'body'     => '',
        'fout'     => '',
        'onveilig' => !$verifieer,
    ];

    if (!function_exists('curl_init')) {
        $uit['fout'] = 'De PHP-extensie curl ontbreekt; de zelftest kan geen verzoek doen.';
        return $uit;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        $uit['fout'] = 'cURL kon niet worden gestart.';
        return $uit;
    }

    $headers = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => ZELFTEST_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => ZELFTEST_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => $verifieer,
        CURLOPT_SSL_VERIFYHOST => $verifieer ? 2 : 0,
        CURLOPT_HTTPHEADER     => $range !== '' ? ['Range: ' . $range] : [],
        CURLOPT_USERAGENT      => 'DJM-Portaal-zelftest/' . APP_VERSION,
        CURLOPT_HEADERFUNCTION => function ($verbinding, string $regel) use (&$headers): int {
            $deel = explode(':', $regel, 2);
            if (count($deel) === 2) {
                $headers[strtolower(trim($deel[0]))] = trim($deel[1]);
            }
            return strlen($regel);
        },
    ]);

    $body   = curl_exec($ch);
    $errno  = curl_errno($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $fout   = curl_error($ch);
    curl_close($ch);

    // Een zelfondertekend of nog niet uitgerold certificaat mag de zelftest niet
    // blokkeren: we proberen het dan nog één keer zonder controle en melden dat
    // erbij, zodat de beheerder weet dat er ook nog een certificaatprobleem is.
    // Numeriek, niet via de CURLE_-constanten: welke daarvan PHP definieert
    // verschilt per versie en per build.
    //   35 SSL_CONNECT_ERROR   51 SSL_PEER_CERTIFICATE   58 SSL_CERTPROBLEM
    //   60 PEER_FAILED_VERIFICATION   77 SSL_CACERT_BADFILE   83 SSL_ISSUER_ERROR
    $certificaatfouten = [35, 51, 58, 60, 77, 83];
    if ($verifieer && in_array($errno, $certificaatfouten, true)) {
        $opnieuw = zelftest_verzoek($url, $range, false);
        $opnieuw['onveilig'] = true;
        return $opnieuw;
    }

    if ($errno !== 0) {
        $uit['fout'] = $fout !== '' ? $fout : ('cURL-fout ' . $errno);
        return $uit;
    }

    $uit['status']  = $status;
    $uit['headers'] = $headers;
    $uit['body']    = is_string($body) ? $body : '';

    return $uit;
}

// ─── De zelftest ─────────────────────────────────────────────────────────────

/**
 * Draait de volledige zelftest van de uitleveringslaag.
 *
 * @param string|null $basisUrl Alleen meegeven als de server zijn eigen APP_URL
 *                              niet kan bereiken (zoals in de testomgeving).
 * @return array{methode:string,gemeld:string,basis:string,stappen:array<int,array{naam:string,gelukt:bool|null,detail:string}>,gelukt:bool,onveilig:bool,antwoord:bool}
 */
function zelftest_uitvoeren(?string $basisUrl = null): array
{
    $basisUrl = rtrim($basisUrl ?? app_base_url(), '/');

    $resultaat = [
        'methode'  => download_methode(),
        'gemeld'   => '',
        'basis'    => $basisUrl,
        'stappen'  => [],
        'gelukt'   => false,
        'onveilig' => false,
        'antwoord' => false,
    ];

    /** Voegt een stap toe. $gelukt null betekent: overgeslagen, niet van toepassing. */
    $stap = function (string $naam, ?bool $gelukt, string $detail) use (&$resultaat): void {
        $resultaat['stappen'][] = ['naam' => $naam, 'gelukt' => $gelukt, 'detail' => $detail];
    };

    try {
        // ─── 1. Testbestand klaarzetten ─────────────────────────────────────
        $fout = '';
        $pad  = zelftest_bestand_aanmaken($fout);
        if ($pad === null) {
            $stap('Testbestand klaarzetten', false, $fout);
            return $resultaat;
        }
        $inhoud = (string)@file_get_contents($pad);
        $stap(
            'Testbestand klaarzetten',
            true,
            formatteer_bytes(ZELFTEST_BYTES) . ' in ' . opslag_pad() . '/' . zelftest_relatief_pad()
        );

        $url = zelftest_url($basisUrl);

        // ─── 2. Volledige download ──────────────────────────────────────────
        $antwoord = zelftest_verzoek($url);
        $resultaat['onveilig'] = $resultaat['onveilig'] || $antwoord['onveilig'];

        if ($antwoord['fout'] !== '') {
            $stap('Volledige download', false, zelftest_verbindingsfout($antwoord['fout'], $basisUrl));
            return $resultaat;
        }

        // Het antwoord zegt welke route de bytes écht heeft uitgeleverd. Dat weegt
        // zwaarder dan onze eigen detectie: die kijkt naar $_SERVER van het
        // huidige proces, en dat is bij een CLI-aanroep een ander proces dan het
        // proces dat de download afhandelt.
        $resultaat['antwoord'] = true;
        $resultaat['gemeld']   = zelftest_methode_uit_headers($antwoord['headers']);
        if ($resultaat['gemeld'] !== '') {
            $resultaat['methode'] = $resultaat['gemeld'];
        }

        if ($antwoord['status'] !== 200) {
            $stap('Volledige download', false, 'Verwacht HTTP 200, gekregen HTTP ' . $antwoord['status'] . '.');
            return $resultaat;
        }
        if ($antwoord['body'] !== $inhoud) {
            $stap('Volledige download', false, sprintf(
                'HTTP 200, maar de inhoud klopt niet: %s ontvangen van de %s die klaarstond.'
                    . ' Dit is het beeld van een verkeerd gekoppelde uitleveringsroute — bij nginx wijst'
                    . ' `alias` in het internal-blok dan niet naar de opslagmap.',
                formatteer_bytes(strlen($antwoord['body'])),
                formatteer_bytes(strlen($inhoud))
            ));
            return $resultaat;
        }

        $lengte = (int)($antwoord['headers']['content-length'] ?? 0);
        $stap('Volledige download', true, sprintf(
            'HTTP 200, %s, inhoud identiek%s.',
            formatteer_bytes(strlen($antwoord['body'])),
            $lengte === ZELFTEST_BYTES ? ', Content-Length klopt' : ' (Content-Length: ' . $lengte . ')'
        ));

        // ─── 3. Hervatten (HTTP Range) ──────────────────────────────────────
        $range    = 'bytes=' . ZELFTEST_RANGE_START . '-' . ZELFTEST_RANGE_EIND;
        $verwacht = substr($inhoud, ZELFTEST_RANGE_START, ZELFTEST_RANGE_EIND - ZELFTEST_RANGE_START + 1);
        $antwoord = zelftest_verzoek($url, $range);

        if ($antwoord['fout'] !== '') {
            $stap('Hervatten (HTTP Range)', false, $antwoord['fout']);
        } elseif ($antwoord['status'] !== 206) {
            $stap('Hervatten (HTTP Range)', false, sprintf(
                'Verwacht HTTP 206, gekregen HTTP %d. Een afgebroken download begint dan weer bij nul —'
                    . ' bij een video van meerdere gigabytes is dat het verschil tussen wel en niet lukken.',
                $antwoord['status']
            ));
        } elseif ($antwoord['body'] !== $verwacht) {
            $stap('Hervatten (HTTP Range)', false, 'HTTP 206, maar de teruggestuurde bytes zijn niet het gevraagde bereik.');
        } else {
            $bereik = (string)($antwoord['headers']['content-range'] ?? '');
            $accept = strtolower((string)($antwoord['headers']['accept-ranges'] ?? ''));
            $stap('Hervatten (HTTP Range)', true, sprintf(
                'HTTP 206, de juiste %d bytes%s%s.',
                strlen($antwoord['body']),
                $bereik !== '' ? ', Content-Range: ' . $bereik : '',
                $accept === 'bytes' ? ', Accept-Ranges aangekondigd' : ''
            ));
        }

        // ─── 4. Onmogelijk bereik ───────────────────────────────────────────
        $antwoord = zelftest_verzoek($url, 'bytes=' . ZELFTEST_BYTES . '-');
        if ($antwoord['fout'] !== '') {
            $stap('Onmogelijk bereik afwijzen', false, $antwoord['fout']);
        } elseif ($antwoord['status'] !== 416) {
            $stap('Onmogelijk bereik afwijzen', false,
                'Verwacht HTTP 416, gekregen HTTP ' . $antwoord['status'] . '.');
        } else {
            $stap('Onmogelijk bereik afwijzen', true, 'HTTP 416, zoals het hoort.');
        }

        // ─── 5. Niet rechtstreeks bereikbaar ────────────────────────────────
        zelftest_directe_toegang($basisUrl, $resultaat['methode'], $stap);
    } finally {
        zelftest_opruimen();
    }

    $mislukt = false;
    foreach ($resultaat['stappen'] as $s) {
        if ($s['gelukt'] === false) {
            $mislukt = true;
        }
    }
    $resultaat['gelukt'] = !$mislukt;

    return $resultaat;
}

/**
 * Controleert dat het testbestand niet met een gewone URL op te halen is.
 *
 * Dit is de kant die de uitleveringstest zelf niet dekt: bij nginx bewijst het
 * dat `internal` in het locatieblok staat, en bij een opslagmap binnen de
 * webroot dat de deny-regel werkt. Zonder die twee zijn alle video's publiek,
 * terwijl de download via het portaal gewoon lijkt te werken.
 */
function zelftest_directe_toegang(string $basisUrl, string $methode, callable $stap): void
{
    $relatief = zelftest_relatief_pad();
    $urls     = [];

    if ($methode === 'xaccel') {
        $prefix = trim(env('XACCEL_PREFIX', '/beveiligd/'), '/');
        if ($prefix !== '') {
            $urls['de X-Accel-locatie'] = $basisUrl . '/' . $prefix . '/' . $relatief;
        }
    }

    $opslag = realpath(opslag_pad());
    if ($opslag !== false && str_starts_with($opslag, APP_ROOT . DIRECTORY_SEPARATOR)) {
        $binnen = trim(str_replace('\\', '/', substr($opslag, strlen(APP_ROOT))), '/');
        $urls['de opslagmap'] = $basisUrl . '/' . $binnen . '/' . $relatief;
    }

    if ($urls === []) {
        $stap(
            'Niet rechtstreeks bereikbaar',
            null,
            'Overgeslagen: de opslagmap staat buiten de webroot en deze methode legt geen eigen URL bloot.'
        );
        return;
    }

    $problemen = [];
    $gecontroleerd = [];
    foreach ($urls as $omschrijving => $url) {
        $antwoord = zelftest_verzoek($url);
        if ($antwoord['fout'] !== '') {
            continue;
        }
        $gecontroleerd[] = $omschrijving . ' (HTTP ' . $antwoord['status'] . ')';
        if ($antwoord['status'] === 200) {
            $problemen[] = $omschrijving . ' levert het bestand gewoon uit: ' . $url;
        }
    }

    if ($problemen !== []) {
        $stap('Niet rechtstreeks bereikbaar', false, implode(' — ', $problemen)
            . ' Iedereen met de juiste URL kan de video\'s zo ophalen, zonder in te loggen.');
        return;
    }

    $stap('Niet rechtstreeks bereikbaar', true,
        'Geweigerd via ' . implode(' en ', $gecontroleerd) . '.');
}

/** Maakt van een cURL-fout een melding waar de beheerder iets mee kan. */
function zelftest_verbindingsfout(string $fout, string $basisUrl): string
{
    return $fout . ' — de server moet zijn eigen adres (' . $basisUrl . ') kunnen bereiken. '
        . 'Klopt APP_URL in .env, en laat de firewall of DNS het toe dat de server zichzelf opvraagt?';
}

// ─── Serverconfiguratie ──────────────────────────────────────────────────────

/**
 * Het configuratieblok dat bij een uitleveringsmethode hoort, met de paden van
 * déze installatie al ingevuld.
 *
 * @return array{titel:string,waar:string,taal:string,tekst:string,uitleg:string}
 */
function uitlevering_serverconfig(string $methode): array
{
    $opslag = opslag_pad();

    if ($methode === 'xaccel') {
        $prefix = '/' . trim(env('XACCEL_PREFIX', '/beveiligd/'), '/') . '/';

        return [
            'titel' => 'nginx — X-Accel-Redirect',
            'waar'  => 'In het server-blok van uw nginx-configuratie, bijvoorbeeld /etc/nginx/sites-available/djm-portaal',
            'taal'  => 'nginx',
            'tekst' => "location {$prefix} {\n"
                . "    internal;\n"
                . "    alias {$opslag}/;\n\n"
                . "    # Grote bestanden efficiënt uitleveren.\n"
                . "    sendfile     on;\n"
                . "    tcp_nopush   on;\n"
                . "    aio          threads;\n\n"
                . "    # Hervatten van afgebroken downloads.\n"
                . "    add_header Accept-Ranges bytes;\n"
                . "}",
            'uitleg' => 'Het pad achter `location` moet exact XACCEL_PREFIX uit .env zijn, inclusief '
                . 'de slash aan het begin én aan het eind. Vergeet de afsluitende slash bij `alias` niet — '
                . 'zonder die slash plakt nginx de padnamen verkeerd aan elkaar en krijgt de bezoeker een 404. '
                . '`internal` zorgt ervoor dat niemand deze locatie rechtstreeks in de adresbalk kan openen. '
                . 'Activeren met: sudo nginx -t && sudo systemctl reload nginx',
        ];
    }

    if ($methode === 'xsendfile') {
        return [
            'titel' => 'Apache — X-Sendfile',
            'waar'  => 'In het VirtualHost-blok van uw Apache-configuratie, bijvoorbeeld /etc/apache2/sites-available/djm-portaal.conf',
            'taal'  => 'apache',
            'tekst' => "<IfModule mod_xsendfile.c>\n"
                . "    XSendFile On\n"
                . "    XSendFilePath {$opslag}\n"
                . "</IfModule>",
            'uitleg' => 'Zonder de regel XSendFilePath weigert mod_xsendfile het pad en krijgt de bezoeker '
                . 'een lege download. Installeren en activeren op Debian of Ubuntu: '
                . 'sudo apt install libapache2-mod-xsendfile && sudo a2enmod xsendfile && sudo systemctl reload apache2. '
                . 'Controleren of de module draait: apachectl -M | grep xsendfile',
        ];
    }

    return [
        'titel' => 'PHP levert zelf uit',
        'waar'  => 'In php.ini, .user.ini of het hostingpaneel',
        'taal'  => 'ini',
        'tekst' => "max_execution_time = 0\n"
            . "output_buffering = Off\n"
            . "zlib.output_compression = Off\n"
            . "memory_limit = 256M",
        'uitleg' => 'Deze methode heeft geen serverconfiguratie nodig, maar PHP moet wel lang genoeg mogen '
            . 'draaien en niets mogen bufferen: een video van 8 GB over een trage lijn duurt makkelijk een uur. '
            . 'Het bestand wordt in blokken van 8 KB gelezen, dus de memory_limit hierboven is ruim voldoende. '
            . 'Staat er een reverse proxy voor, zet dan ook daar het bufferen uit.',
    ];
}
