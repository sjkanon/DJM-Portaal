<?php
/**
 * Zet een jaargang 2028 klaar met het sparse testbestand van 5 GB en geeft
 * ouder@example.nl toegang. Hoort bij test/grootbestand.sh.
 */
$_SERVER['SCRIPT_NAME'] = '/index.php';
require '/app/config.php';

const GROOT = 5368709120;   // 5 GiB

db()->exec("DELETE FROM jaargangen WHERE jaar = 2028");

db()->prepare('INSERT INTO jaargangen (jaar, titel, slug, gepubliceerd) VALUES (2028, :t, :s, 1)')
    ->execute([':t' => 'Grote productie', ':s' => 'grote-productie-2028']);
$jaargangId = (int)db()->lastInsertId();

db()->prepare('INSERT INTO jaargang_bestanden (jaargang_id, titel, bestandsnaam, pad, bytes, mime)
               VALUES (:j, :t, :b, :p, :g, :m)')
    ->execute([
        ':j' => $jaargangId,
        ':t' => 'Volledige registratie',
        ':b' => 'DJM 2028 - Grote productie.mp4',
        ':p' => '2028/musical-2028.mp4',
        ':g' => GROOT,
        ':m' => 'video/mp4',
    ]);
$bestandId = (int)db()->lastInsertId();

$deelnemerId = (int)db()->query("SELECT id FROM deelnemers WHERE email = 'ouder@example.nl'")->fetchColumn();
db()->prepare('INSERT IGNORE INTO toegang (deelnemer_id, jaargang_id) VALUES (:d, :j)')
    ->execute([':d' => $deelnemerId, ':j' => $jaargangId]);

db()->exec('DELETE FROM aanvraag_limiet');

$opSchijf = opslag_absoluut_pad('2028/musical-2028.mp4');
echo json_encode([
    'jaargang_id'  => $jaargangId,
    'bestand_id'   => $bestandId,
    'bytes_in_db'  => (int)db()->query("SELECT bytes FROM jaargang_bestanden WHERE id = $bestandId")->fetchColumn(),
    'bytes_op_schijf' => $opSchijf !== null ? (int)filesize($opSchijf) : 0,
]), "\n";
