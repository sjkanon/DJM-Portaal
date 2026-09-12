<?php
// Verwijdert de testjaargang van 5 GB weer. Het sparse bestand blijft staan.
$_SERVER['SCRIPT_NAME'] = '/index.php';
require '/app/config.php';
db()->exec("DELETE FROM jaargangen WHERE jaar = 2028");
echo "jaargang 2028 verwijderd\n";
