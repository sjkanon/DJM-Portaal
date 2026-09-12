<?php

/**
 * Draait de uitleveringszelftest vanaf de opdrachtregel, tegen een meegegeven
 * basis-URL.
 *
 * In het beheer gebruikt de zelftest APP_URL. In de testomgeving is dat adres
 * (localhost:8126) van binnen de containers niet te bereiken, dus geven we hier
 * het adres mee dat binnen het containernetwerk wél werkt.
 *
 *   php test/zelftest_cli.php http://nginx:8080
 */


// Deze testscripts horen uitsluitend op de commandoregel te draaien. Ze wijzigen
// of wissen gegevens; wordt de map test/ per ongeluk meegeüpload naar een
// server, dan mag een bezoeker ze nooit via de browser kunnen starten.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$basis = (string)($argv[1] ?? '');

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/uitlevering_helper.php';

$resultaat = zelftest_uitvoeren($basis !== '' ? $basis : null);

printf("methode: %s (gemeld door de server: %s)\n", $resultaat['methode'], $resultaat['gemeld'] ?: '—');
printf("adres:   %s\n\n", $resultaat['basis']);

foreach ($resultaat['stappen'] as $stap) {
    $merk = $stap['gelukt'] === true ? 'OK  ' : ($stap['gelukt'] === false ? 'FOUT' : 'OVER');
    printf("%s %-30s %s\n", $merk, $stap['naam'], $stap['detail']);
}

printf("\nresultaat: %s\n", $resultaat['gelukt'] ? 'geslaagd' : 'mislukt');

exit($resultaat['gelukt'] ? 0 : 1);
