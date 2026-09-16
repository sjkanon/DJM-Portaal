<?php

/**
 * Levert het geüploade logo uit.
 *
 * Het logo staat in `branding/` binnen de opslagmap en is daar niet publiek
 * benaderbaar; dit is de enige weg naar buiten. Dat is met opzet: een SVG is
 * geen plaatje maar XML en mag scripts bevatten. Staat zo'n bestand ergens in
 * de webroot, dan kan iemand de URL rechtstreeks openen en draait wat erin zit
 * binnen onze eigen origin — met de rechten van wie er op dat moment is
 * ingelogd. De opschoning bij de upload haalt scripts eruit, maar dat is een
 * filter op tekst en filters hebben gaten.
 *
 * De harde grens staat hieronder: een Content-Security-Policy met `sandbox`,
 * die van dit antwoord een eigen, machteloze origin maakt. Die headers horen
 * ook bij een 404 te staan, want een 404 van vandaag is het logo van morgen.
 *
 * Geen sessie en geen database: een logo is niet geheim, staat op elke
 * inlogpagina en wordt ook door mailprogramma's opgehaald.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/opmaak.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");

$pad = logo_pad();

if ($pad === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit("Er is geen logo ingesteld.\n");
}

$grootte   = (int)@filesize($pad);
$gewijzigd = (int)@filemtime($pad);

// Een logo verandert bijna nooit, maar áls het verandert moet iedereen het
// meteen zien. Vandaar een ETag over pad, grootte en wijzigingstijd: bij een
// nieuw bestand is het een andere waarde en haalt de browser het opnieuw op.
$etag = '"' . substr(hash('sha256', $pad . '|' . $grootte . '|' . $gewijzigd), 0, 24) . '"';

header('Cache-Control: public, max-age=86400');
header('ETag: ' . $etag);
if ($gewijzigd > 0) {
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $gewijzigd) . ' GMT');
}

if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Type: ' . logo_mime($pad));
header('Content-Length: ' . $grootte);
// `inline`: dit is een plaatje in een pagina, geen download. De naam komt van
// ons en bestaat uit `logo.` plus een extensie uit LOGO_EXTENSIES.
header('Content-Disposition: inline; filename="' . basename($pad) . '"');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    exit;
}

readfile($pad);
