<?php
/**
 * Rooktest: richt de database in, vult testgegevens en controleert de kernlogica.
 * Draaien via test/draaien.sh — niet bedoeld voor productie.
 */

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require_once '/app/config.php';

$goed = 0;
$fout = 0;

function toets(string $omschrijving, $verwacht, $werkelijk): void
{
    global $goed, $fout;
    $ok = $verwacht === $werkelijk;
    $ok ? $goed++ : $fout++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $omschrijving,
        $ok ? '' : sprintf("\n      verwacht: %s\n      kreeg:    %s",
            var_export($verwacht, true), var_export($werkelijk, true)));
}

function toets_waar(string $omschrijving, $werkelijk): void
{
    toets($omschrijving, true, (bool)$werkelijk);
}

echo "\n── Database inrichten ──────────────────────────────────────\n";
$pdo = db();
$sql = file_get_contents('/app/db.sql');
foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) {
    $stmt = trim(preg_replace('/^\s*--.*$/m', '', $stmt));
    if ($stmt === '' || $stmt === ';') {
        continue;
    }
    $pdo->exec(rtrim($stmt, ";\n "));
}
$tabellen = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
toets('alle tabellen aangemaakt', 12, count($tabellen));
toets_waar('standaardinstellingen geladen', instelling('portaal_naam') === 'Deventer Jeugd Musical');

echo "\n── Testgegevens ────────────────────────────────────────────\n";
require_once '/app/includes/toegang_helper.php';

$pdo->exec("DELETE FROM jaargangen");
$pdo->exec("DELETE FROM deelnemers");
// Ook de tellers van de rate limiting: zonder dit loopt de derde testrun binnen
// een kwartier tegen de limiet van drie inlogcodes per adres aan, en lijkt het
// alsof het versturen stuk is.
$pdo->exec("DELETE FROM aanvraag_limiet");

$pdo->prepare('INSERT INTO jaargangen (jaar, titel, slug, omschrijving, gepubliceerd)
               VALUES (2026, :t, :s, :o, 1)')
    ->execute([':t' => 'De Kleine Zeemeermin', ':s' => 'de-kleine-zeemeermin-2026', ':o' => "Uitvoering juni 2026.\nTwee acts."]);
$jaargangId = (int)$pdo->lastInsertId();

$pdo->prepare('INSERT INTO jaargangen (jaar, titel, slug, gepubliceerd) VALUES (2025, :t, :s, 0)')
    ->execute([':t' => 'Annie', ':s' => 'annie-2025']);
$conceptId = (int)$pdo->lastInsertId();

@mkdir('/app/opslag/2026', 0775, true);
file_put_contents('/app/opslag/2026/musical-2026.mp4', str_repeat('DJM-TEST', 4096)); // 32 KB
$grootte = filesize('/app/opslag/2026/musical-2026.mp4');

$pdo->prepare('INSERT INTO jaargang_bestanden (jaargang_id, titel, bestandsnaam, pad, bytes, mime)
               VALUES (:j, :t, :b, :p, :g, :m)')
    ->execute([
        ':j' => $jaargangId, ':t' => 'Volledige registratie',
        ':b' => 'DJM 2026 - De Kleine Zeemeermin.mp4',
        ':p' => '2026/musical-2026.mp4', ':g' => $grootte, ':m' => 'video/mp4',
    ]);
$bestandId = (int)$pdo->lastInsertId();

$deelnemer = deelnemer_aanmaken_of_ophalen('ouder@example.nl', 'Test Ouder');
$vreemde   = deelnemer_aanmaken_of_ophalen('vreemde@example.nl', null);
toegang_toekennen((int)$deelnemer['id'], $jaargangId, 'rooktest');
toets_waar('deelnemer aangemaakt', (int)$deelnemer['id'] > 0);

echo "\n── Toegangslogica ──────────────────────────────────────────\n";
toets('bekend adres met toegang', true, email_heeft_toegang('ouder@example.nl'));
toets('adres zonder toegang', false, email_heeft_toegang('vreemde@example.nl'));
toets('onbekend adres', false, email_heeft_toegang('niemand@example.nl'));
toets('hoofdletters worden genormaliseerd', true, email_heeft_toegang('Ouder@Example.NL'));

$jaargangen = deelnemer_jaargangen((int)$deelnemer['id']);
toets('één zichtbare jaargang', 1, count($jaargangen));
toets('bestand meegeleverd', 1, count($jaargangen[0]['bestanden'] ?? []));

toegang_toekennen((int)$deelnemer['id'], $conceptId, 'rooktest');
toets('concept-jaargang blijft verborgen', 1, count(deelnemer_jaargangen((int)$deelnemer['id'])));

$pdo->prepare('UPDATE jaargangen SET gepubliceerd = 1, verloopt_op = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = :id')
    ->execute([':id' => $conceptId]);
toets('verlopen jaargang blijft verborgen', 1, count(deelnemer_jaargangen((int)$deelnemer['id'])));

toets_waar('eigen bestand toegankelijk', deelnemer_bestand((int)$deelnemer['id'], $bestandId) !== null);
toets('bestand van een ander niet toegankelijk', null, deelnemer_bestand((int)$vreemde['id'], $bestandId));

$pdo->prepare('UPDATE deelnemers SET geblokkeerd = 1 WHERE id = :id')->execute([':id' => (int)$deelnemer['id']]);
toets('geblokkeerde deelnemer krijgt niets', null, deelnemer_bestand((int)$deelnemer['id'], $bestandId));
toets('geblokkeerde deelnemer krijgt geen code', false, email_heeft_toegang('ouder@example.nl'));
$pdo->prepare('UPDATE deelnemers SET geblokkeerd = 0 WHERE id = :id')->execute([':id' => (int)$deelnemer['id']]);

echo "\n── Padafscherming ──────────────────────────────────────────\n";
toets('geldig pad', true, opslag_absoluut_pad('2026/musical-2026.mp4') !== null);
toets('padtraversal geweigerd', null, opslag_absoluut_pad('../config.php'));
toets('absoluut pad geweigerd', null, opslag_absoluut_pad('/etc/passwd'));
toets('leeg pad geweigerd', null, opslag_absoluut_pad(''));
toets('nulbyte geweigerd', null, opslag_absoluut_pad("2026/musical-2026.mp4\0.txt"));

echo "\n── OTP ─────────────────────────────────────────────────────\n";
if (is_file('/app/includes/otp.php')) {
    require_once '/app/includes/otp.php';
    $code = otp_code_genereren();
    toets('code is zes cijfers', 1, preg_match('/^\d{6}$/', $code));
    toets_waar('hash is niet de code zelf', otp_hash($code) !== $code);
    toets('hash is deterministisch', otp_hash($code), otp_hash($code));
    toets('hash is 64 tekens', 64, strlen(otp_hash($code)));

    $codes = [];
    for ($i = 0; $i < 200; $i++) {
        $codes[otp_code_genereren()] = true;
    }
    toets_waar('codes zijn voldoende gevarieerd', count($codes) > 150);
} else {
    echo "  – includes/otp.php nog niet aanwezig, overgeslagen\n";
}

echo "\n── Downloadlinks ───────────────────────────────────────────\n";
if (is_file('/app/includes/download_helper.php')) {
    require_once '/app/includes/download_helper.php';
    $vervalt = time() + 300;
    $hand = hash_hmac('sha256', $bestandId . '|' . (int)$deelnemer['id'] . '|' . $vervalt, app_key());
    toets('geldige handtekening', true,
        download_handtekening_geldig($bestandId, (int)$deelnemer['id'], $vervalt, $hand));
    toets('gemanipuleerde handtekening', false,
        download_handtekening_geldig($bestandId, (int)$deelnemer['id'], $vervalt, str_repeat('a', 64)));
    toets('handtekening van een ander bestand', false,
        download_handtekening_geldig($bestandId + 1, (int)$deelnemer['id'], $vervalt, $hand));
    toets('handtekening van een andere deelnemer', false,
        download_handtekening_geldig($bestandId, (int)$vreemde['id'], $vervalt, $hand));
    $oud = time() - 10;
    toets('verlopen handtekening', false, download_handtekening_geldig(
        $bestandId, (int)$deelnemer['id'], $oud,
        hash_hmac('sha256', $bestandId . '|' . (int)$deelnemer['id'] . '|' . $oud, app_key())
    ));
} else {
    echo "  – includes/download_helper.php nog niet aanwezig, overgeslagen\n";
}

echo "\n── Beheerder ───────────────────────────────────────────────\n";
$pdo->prepare('INSERT INTO beheerders (naam, email, wachtwoord_hash, rol) VALUES (:n, :e, :w, :r)
               ON DUPLICATE KEY UPDATE wachtwoord_hash = VALUES(wachtwoord_hash)')
    ->execute([':n' => 'Testbeheerder', ':e' => 'beheer@example.nl',
               ':w' => password_hash('EenHeelLangWachtwoord123', PASSWORD_DEFAULT), ':r' => 'eigenaar']);
$rij = $pdo->query("SELECT * FROM beheerders WHERE email = 'beheer@example.nl'")->fetch();
toets('wachtwoord verifieert', true, password_verify('EenHeelLangWachtwoord123', $rij['wachtwoord_hash']));
toets('verkeerd wachtwoord', false, password_verify('fout', $rij['wachtwoord_hash']));

echo "\n────────────────────────────────────────────────────────────\n";
printf("  %d geslaagd, %d mislukt\n\n", $goed, $fout);
exit($fout === 0 ? 0 : 1);
