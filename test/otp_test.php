<?php
/**
 * Functionele test van de inlogcodelogica: eenmalig gebruik, pogingenlimiet,
 * intrekken van oude codes, browserbinding en throttling.
 * Draait binnen de testcontainer, met Mailpit als mailserver.
 */


// Deze testscripts horen uitsluitend op de commandoregel te draaien. Ze wijzigen
// of wissen gegevens; wordt de map test/ per ongeluk meegeüpload naar een
// server, dan mag een bezoeker ze nooit via de browser kunnen starten.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

require_once '/app/config.php';
require_once '/app/includes/toegang_helper.php';
require_once '/app/includes/otp.php';

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

/** Leest de zojuist aangemaakte code niet uit — die kennen we niet. We zetten
 *  daarom zelf een bekende code klaar met dezelfde hashfunctie. */
function code_klaarzetten(string $email, string $code, string $challenge, string $vervalt = '+10 MINUTE'): int
{
    $deelnemer = deelnemer_op_email($email);
    db()->prepare('INSERT INTO login_codes (email, deelnemer_id, code_hash, challenge_id, verloopt_op)
                   VALUES (:e, :d, :h, :c, NOW() + INTERVAL ' . $vervalt . ')')
        ->execute([
            ':e' => $email, ':d' => (int)$deelnemer['id'],
            ':h' => otp_hash($code), ':c' => $challenge,
        ]);
    return (int)db()->lastInsertId();
}

db()->exec('DELETE FROM login_codes');
db()->exec('DELETE FROM aanvraag_limiet');
$_SESSION = [];

echo "\n── Aanvragen ───────────────────────────────────────────────\n";
$resultaat = otp_aanvragen('ouder@example.nl');
toets('bekend adres krijgt een code', 'verstuurd', $resultaat['status']);
toets('en die is echt verstuurd', true, $resultaat['echt'] ?? null);
toets('code staat in de database', 1,
    (int)db()->query("SELECT COUNT(*) FROM login_codes WHERE email='ouder@example.nl'")->fetchColumn());
toets('code_hash is geen leesbare code', 0,
    (int)db()->query("SELECT COUNT(*) FROM login_codes WHERE code_hash REGEXP '^[0-9]{6}$'")->fetchColumn());

$onbekend = otp_aanvragen('niemand@example.nl');
toets('onbekend adres geeft hetzelfde antwoord', 'verstuurd', $onbekend['status']);
toets('maar er is niets verstuurd', false, $onbekend['echt'] ?? null);
toets('en er staat geen code klaar', 0,
    (int)db()->query("SELECT COUNT(*) FROM login_codes WHERE email='niemand@example.nl'")->fetchColumn());

$ongeldig = otp_aanvragen('geen-adres');
toets('ongeldig adres wordt geweigerd', 'ongeldig', $ongeldig['status']);

echo "\n── Nieuwe code trekt de vorige in ──────────────────────────\n";
db()->exec('DELETE FROM login_codes');
$_SESSION = [];
otp_aanvragen('ouder@example.nl');
otp_aanvragen('ouder@example.nl');
toets('twee codes aangemaakt', 2,
    (int)db()->query("SELECT COUNT(*) FROM login_codes WHERE email='ouder@example.nl'")->fetchColumn());
toets('slechts één is nog bruikbaar', 1,
    (int)db()->query("SELECT COUNT(*) FROM login_codes
                      WHERE email='ouder@example.nl' AND ingetrokken_op IS NULL AND gebruikt_op IS NULL")->fetchColumn());

echo "\n── Verifiëren ──────────────────────────────────────────────\n";
db()->exec('DELETE FROM login_codes');
$_SESSION['otp_challenge'] = 'testchallenge0123456789abcdef012';
code_klaarzetten('ouder@example.nl', '123456', 'testchallenge0123456789abcdef012');

$f = [];
toets('foute code wordt geweigerd', null, otp_verifieren('ouder@example.nl', '999999', $f));
toets('met een melding over de resterende pogingen', 1, (int)str_contains($f[0] ?? '', 'poging'));
toets('poging is geteld', 1,
    (int)db()->query("SELECT pogingen FROM login_codes ORDER BY id DESC LIMIT 1")->fetchColumn());

$deelnemer = otp_verifieren('ouder@example.nl', '123456', $f);
toets('juiste code geeft de deelnemer terug', 'ouder@example.nl', $deelnemer['email'] ?? null);
toets('code is nu verbruikt', null, otp_verifieren('ouder@example.nl', '123456', $f));

echo "\n── Pogingenlimiet ──────────────────────────────────────────\n";
db()->exec('DELETE FROM login_codes');
code_klaarzetten('ouder@example.nl', '111111', 'testchallenge0123456789abcdef012');
for ($i = 1; $i <= 5; $i++) {
    otp_verifieren('ouder@example.nl', '000000', $f);
}
toets('na vijf fouten is de code ingetrokken', 1,
    (int)db()->query("SELECT COUNT(*) FROM login_codes WHERE ingetrokken_op IS NOT NULL")->fetchColumn());
toets('de juiste code werkt daarna niet meer', null, otp_verifieren('ouder@example.nl', '111111', $f));

echo "\n── Binding aan de browser ──────────────────────────────────\n";
db()->exec('DELETE FROM login_codes');
code_klaarzetten('ouder@example.nl', '222222', 'eenheelanderechallengewaarde1234');
toets('code uit een andere browser werkt niet', null, otp_verifieren('ouder@example.nl', '222222', $f));
toets('met een duidelijke uitleg', 1, (int)str_contains($f[0] ?? '', 'browser'));
$_SESSION['otp_challenge'] = 'eenheelanderechallengewaarde1234';
toets('in de juiste browser werkt hij wel', 'ouder@example.nl',
    (otp_verifieren('ouder@example.nl', '222222', $f)['email'] ?? null));

echo "\n── Verlopen code ───────────────────────────────────────────\n";
db()->exec('DELETE FROM login_codes');
code_klaarzetten('ouder@example.nl', '333333', 'testchallenge0123456789abcdef012', '-1 MINUTE');
$_SESSION['otp_challenge'] = 'testchallenge0123456789abcdef012';
toets('verlopen code werkt niet', null, otp_verifieren('ouder@example.nl', '333333', $f));

echo "\n── Throttling ──────────────────────────────────────────────\n";
db()->exec('DELETE FROM aanvraag_limiet');
db()->exec('DELETE FROM login_codes');
instelling_opslaan('otp_max_per_email', '3');
instelling_opslaan('otp_max_per_ip', '100');
$statussen = [];
for ($i = 0; $i < 5; $i++) {
    $statussen[] = otp_aanvragen('ouder@example.nl')['status'];
}
toets('eerste drie aanvragen gaan door', ['verstuurd', 'verstuurd', 'verstuurd'], array_slice($statussen, 0, 3));
toets('daarna volgt de limiet', ['limiet', 'limiet'], array_slice($statussen, 3, 2));

db()->exec('DELETE FROM aanvraag_limiet');
$statussenOnbekend = [];
for ($i = 0; $i < 5; $i++) {
    $statussenOnbekend[] = otp_aanvragen('sonde' . $i . '@example.nl')['status'];
}
instelling_opslaan('otp_max_per_ip', '10');
db()->exec('DELETE FROM aanvraag_limiet');
$_SESSION = [];
$perIp = [];
for ($i = 0; $i < 12; $i++) {
    $perIp[] = otp_aanvragen('sonde' . $i . '@example.nl')['status'];
}
toets('ook onbekende adressen tellen mee voor de IP-limiet', 'limiet', $perIp[11]);
toets('de limiet slaat na tien aanvragen toe', 'limiet', $perIp[10]);

echo "\n── Looptijd (geen timing-lek) ──────────────────────────────\n";
db()->exec('DELETE FROM aanvraag_limiet');
instelling_opslaan('otp_max_per_email', '999');
instelling_opslaan('otp_max_per_ip', '999');
$t1 = microtime(true);
otp_aanvragen('ouder@example.nl');
$bekend = microtime(true) - $t1;
$t2 = microtime(true);
otp_aanvragen('bestaatniet@example.nl');
$onbekendTijd = microtime(true) - $t2;
$verschil = abs($bekend - $onbekendTijd);
printf("  bekend: %.0f ms · onbekend: %.0f ms · verschil: %.0f ms\n",
    $bekend * 1000, $onbekendTijd * 1000, $verschil * 1000);
toets('beide duren minstens 400 ms', true, $bekend >= 0.4 && $onbekendTijd >= 0.4);

// Instellingen terugzetten.
instelling_opslaan('otp_max_per_email', '3');
instelling_opslaan('otp_max_per_ip', '10');
db()->exec('DELETE FROM aanvraag_limiet');
db()->exec('DELETE FROM login_codes');

echo "\n────────────────────────────────────────────────────────────\n";
printf("  %d geslaagd, %d mislukt\n\n", $goed, $fout);
exit($fout === 0 ? 0 : 1);
