<?php

/**
 * Endpoint van de uitleveringszelftest.
 *
 * Levert één klein testbestand uit via exact dezelfde laag als download.php,
 * zodat Beheer > Instellingen kan vaststellen of X-Accel-Redirect of X-Sendfile
 * op deze server echt werkt — en niet alleen of hij gedetecteerd wordt.
 *
 * Geen sessie: het verzoek komt van de server zelf, met cURL vanuit
 * admin/instellingen.php. De ondertekening met APP_KEY is daarom de enige
 * toegangscontrole. Dat is verantwoord omdat dit endpoint nooit iets anders kan
 * uitleveren dan dat ene testbestand met willekeurige bytes — en dat bestaat
 * alleen tijdens de test: de zelftest maakt het aan en ruimt het daarna op.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/download_helper.php';
require_once __DIR__ . '/includes/uitlevering_helper.php';

vereis_installatie();

// ─── Handtekening ───────────────────────────────────────────────────────────
$vervalt      = (int)($_GET['t'] ?? 0);
$handtekening = (string)($_GET['s'] ?? '');

if (!zelftest_handtekening_geldig($vervalt, $handtekening)) {
    http_response_code(404);
    exit;
}

// ─── Pad: vast, nooit uit de URL ────────────────────────────────────────────
$absoluutPad = opslag_absoluut_pad(zelftest_relatief_pad());

if ($absoluutPad === null) {
    // Er loopt geen zelftest; het bestand hoort er dan ook niet te zijn.
    http_response_code(404);
    exit;
}

// ─── Uitleveren ─────────────────────────────────────────────────────────────
$methode = download_methode();

// De methode zit in de downloadnaam, want dat is het enige dat élke route
// ongeschonden doorgeeft: nginx gooit bij een X-Accel-Redirect de meeste
// headers van PHP weg, maar houdt Content-Disposition vast. Zo weet de zelftest
// ook op nginx welke route de bytes daadwerkelijk heeft uitgeleverd.
$bestand = [
    'id'           => 0,
    'jaargang_id'  => 0,
    'bestandsnaam' => zelftest_downloadnaam($methode),
    'pad'          => zelftest_relatief_pad(),
    'mime'         => 'application/octet-stream',
    'bytes'        => (int)@filesize($absoluutPad),
];

// Waar hij wél doorkomt (Apache, PHP zelf) is deze header explicieter.
header('X-DJM-Zelftest: ' . $methode);

// Geen download_loggen(): dit is geen deelnemer en geen jaargangbestand, en het
// logboek is er voor echte downloads.
download_uitleveren($bestand, $absoluutPad, $methode, null);
