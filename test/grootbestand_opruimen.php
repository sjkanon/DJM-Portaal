<?php

// Deze testscripts horen uitsluitend op de commandoregel te draaien. Ze wijzigen
// of wissen gegevens; wordt de map test/ per ongeluk meegeüpload naar een
// server, dan mag een bezoeker ze nooit via de browser kunnen starten.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
// Verwijdert de testjaargang van 5 GB weer. Het sparse bestand blijft staan.
$_SERVER['SCRIPT_NAME'] = '/index.php';
require '/app/config.php';
db()->exec("DELETE FROM jaargangen WHERE jaar = 2028");
echo "jaargang 2028 verwijderd\n";
