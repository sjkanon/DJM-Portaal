<?php
// Laat de testomgeving via Mailpit mailen in plaats van via Graph.
$_SERVER['SCRIPT_NAME'] = '/index.php';
require_once '/app/config.php';
foreach ([
    'email_methode'    => 'smtp',
    'smtp_host'        => 'mail',
    'smtp_poort'       => '1025',
    'smtp_beveiliging' => 'geen',
    'smtp_gebruikersnaam' => '',
    'smtp_wachtwoord'  => '',
    'email_van_adres'  => 'noreply@djm.test',
    'email_van_naam'   => 'Deventer Jeugd Musical',
] as $sleutel => $waarde) {
    instelling_opslaan($sleutel, $waarde);
}
echo "mailinstellingen gezet\n";
