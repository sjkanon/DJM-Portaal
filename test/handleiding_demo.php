<?php
/**
 * Vult de TESTdatabase met voorbeeldgegevens voor de schermafdrukken van
 * Beheer › Handleiding. Wist daarvoor jaargangen, deelnemers en logboeken,
 * net als smoke.php. Alle namen en adressen zijn verzonnen (@example.nl,
 * IP-adressen uit het documentatiebereik 192.0.2.0/24).
 *
 * Draaien via test/handleiding.sh. Laatste regel van de uitvoer:
 *   <jaargang-id 2026> <e-mailadres ouder> <deelnemer-id ouder>
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

require_once '/app/config.php';
require_once '/app/includes/toegang_helper.php';

$pdo = db();
foreach (['download_log', 'mail_log', 'login_log', 'login_codes', 'remember_tokens',
          'aanvraag_limiet', 'toegang', 'deelnemers', 'jaargang_bestanden', 'jaargangen'] as $tabel) {
    $pdo->exec('DELETE FROM ' . $tabel);
}

// Mail via Mailpit, net als test/mailinstellingen.php.
foreach ([
    'email_methode' => 'smtp', 'smtp_host' => 'mail', 'smtp_poort' => '1025', 'smtp_beveiliging' => 'geen',
    'smtp_gebruikersnaam' => '', 'smtp_wachtwoord' => '',
    'email_van_adres' => 'noreply@example.nl', 'email_van_naam' => 'Deventer Jeugd Musical',
    'portaal_ingeschakeld' => '1',
    // Waar {contact} in een bericht naar verwijst; zonder dit weigert
    // Beheer › Bericht een tekst met {contact} erin.
    'contact_email' => 'secretariaat@example.nl',
] as $sleutel => $waarde) {
    instelling_opslaan($sleutel, $waarde);
}

$pdo->prepare('INSERT INTO beheerders (naam, email, wachtwoord_hash, rol, actief) VALUES (:n, :e, :w, :r, 1)
               ON DUPLICATE KEY UPDATE wachtwoord_hash = VALUES(wachtwoord_hash), actief = 1')
    ->execute([':n' => 'Secretariaat DJM', ':e' => 'beheer@example.nl',
               ':w' => password_hash('EenHeelLangWachtwoord123', PASSWORD_DEFAULT), ':r' => 'eigenaar']);

// Voor Beheer › Beheerders: iemand met een openstaande uitnodiging en een
// uitgeschakeld account, zodat het scherm meer laat zien dan één regel.
require_once '/app/includes/beheerder_helper.php';
$beheerderZetten = $pdo->prepare('INSERT INTO beheerders (naam, email, wachtwoord_hash, rol, actief, laatst_ingelogd_op)
                                  VALUES (:n, :e, :w, :r, :a, :l)
                                  ON DUPLICATE KEY UPDATE naam = VALUES(naam), actief = VALUES(actief),
                                      laatst_ingelogd_op = VALUES(laatst_ingelogd_op)');
foreach ([
    ['Jan de Vries', 'jan.devries@example.nl', 1, null],
    ['Oud-bestuurslid', 'oud.bestuur@example.nl', 0, date('Y-m-d H:i:s', strtotime('-8 months'))],
] as [$naam, $adres, $actief, $laatst]) {
    $beheerderZetten->execute([':n' => $naam, ':e' => $adres, ':w' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                               ':r' => 'beheerder', ':a' => $actief, ':l' => $laatst]);
}
$jan = (int)$pdo->query("SELECT id FROM beheerders WHERE email = 'jan.devries@example.nl'")->fetchColumn();
beheerder_link_maken($jan, 'uitnodiging');
beheerder_links_intrekken((int)$pdo->query("SELECT id FROM beheerders WHERE email = 'oud.bestuur@example.nl'")->fetchColumn());

// ─── Jaargangen ──────────────────────────────────────────────────────────────
$jaargang = [];
foreach ([
    [2027, 'Matilda', 'matilda-2027', 'In voorbereiding.', 0, null],
    [2026, 'De Kleine Zeemeermin', 'de-kleine-zeemeermin-2026', 'Opgenomen tijdens de voorstellingen van 12 en 13 juni 2026 in de Deventer Schouwburg.', 1, null],
    [2025, 'Annie', 'annie-2025', 'Registratie van de premièrevoorstelling, 14 juni 2025.', 1, '2026-12-31 23:59:00'],
    [2024, 'Grease', 'grease-2024', null, 0, null],
] as [$jaar, $titel, $slug, $omschrijving, $gepubliceerd, $verloopt]) {
    $pdo->prepare('INSERT INTO jaargangen (jaar, titel, slug, omschrijving, gepubliceerd, verloopt_op)
                   VALUES (:j, :t, :s, :o, :g, :v)')
        ->execute([':j' => $jaar, ':t' => $titel, ':s' => $slug, ':o' => $omschrijving, ':g' => $gepubliceerd, ':v' => $verloopt]);
    $jaargang[$jaar] = (int)$pdo->lastInsertId();
}

// ─── Bestanden: sparse, dus zonder schijfruimte ─────────────────────────────
$bestand = [];
foreach ([
    [2026, 'Volledige registratie (Full HD)', 'DJM De Kleine Zeemeermin 2026.mp4', '2026/zeemeermin-2026-fullhd.mp4', 7_912_448_128, 1, true],
    [2026, 'Kleinere versie (voor trage verbindingen)', 'DJM De Kleine Zeemeermin 2026 (klein).mp4', '2026/zeemeermin-2026-klein.mp4', 2_147_893_248, 2, true],
    [2025, 'Volledige registratie (Full HD)', 'DJM Annie 2025.mp4', '2025/annie-2025-fullhd.mp4', 6_843_502_592, 1, true],
    // Staat bewust niet op schijf: zo laat Beheer › Controle een ontbrekend bestand zien.
    [2024, 'Volledige registratie', 'DJM Grease 2024.mp4', '2024/grease-2024.mp4', 5_998_120_960, 1, false],
] as [$jaar, $titel, $naam, $pad, $bytes, $sortering, $opSchijf]) {
    if ($opSchijf) {
        $absoluut = rtrim(opslag_pad(), '/') . '/' . $pad;
        @mkdir(dirname($absoluut), 0755, true);
        $handvat = fopen($absoluut, 'c');
        ftruncate($handvat, $bytes);
        fclose($handvat);
    }
    $pdo->prepare('INSERT INTO jaargang_bestanden (jaargang_id, titel, bestandsnaam, pad, bytes, mime, sortering, actief)
                   VALUES (:j, :t, :n, :p, :b, :m, :s, 1)')
        ->execute([':j' => $jaargang[$jaar], ':t' => $titel, ':n' => $naam, ':p' => $pad, ':b' => $bytes,
                   ':m' => 'video/mp4', ':s' => $sortering]);
    $bestand[$pad] = ['id' => (int)$pdo->lastInsertId(), 'naam' => $naam, 'bytes' => $bytes];
}

// ─── Deelnemers ──────────────────────────────────────────────────────────────
$namen = ['Marieke Jansen', 'Pieter de Boer', 'Fatima El Amrani', 'Joost Visser', 'Sanne Bakker', 'Ruben Smit',
    'Lotte Meijer', 'Daan Mulder', 'Noor Hendriks', 'Thijs van Dijk', 'Eva Bos', 'Kees Vos', 'Yara Peters',
    'Bram Dekker', 'Anouk van Leeuwen', 'Mehmet Yılmaz', 'Iris Brouwer', 'Wouter de Graaf', 'Femke Kok',
    'Lars Jacobs', 'Sophie de Wit', 'Hanna Willems', 'Tim van der Linden', 'Esther Schouten'];
$nu      = new DateTimeImmutable('now');
$moment  = fn(string $verschil) => $nu->modify($verschil)->format('Y-m-d H:i:s');
$ip      = fn(int $n) => inet_pton('192.0.2.' . $n);
$browser = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/128.0 Safari/537.36';

$ids = [];
foreach ($namen as $i => $naam) {
    $email = str_replace(['ı', ' van der ', ' van ', ' de ', ' '], ['i', ' vander', ' van', ' de', '.'], $naam);
    $email = mb_strtolower($email) . '@example.nl';
    $id    = (int)deelnemer_aanmaken_of_ophalen($email, $naam)['id'];
    $ids[$email] = $id;

    toegang_toekennen($id, $jaargang[2026], 'Secretariaat DJM');
    if ($i % 3 !== 2) {
        toegang_toekennen($id, $jaargang[2025], 'Secretariaat DJM');
    }
    foreach ([[2026, '-24 days'], [2025, '-380 days']] as [$jaar, $verschil]) {
        $pdo->prepare('UPDATE toegang SET toegevoegd_op = :t WHERE deelnemer_id = :d AND jaargang_id = :j')
            ->execute([':t' => $moment($verschil), ':d' => $id, ':j' => $jaargang[$jaar]]);
    }
    $pdo->prepare('INSERT INTO mail_log (ontvanger, onderwerp, soort, status, methode, verzonden_op)
                   VALUES (:o, :s, :k, :st, :m, :t)')
        ->execute([':o' => $email, ':s' => 'Uw video staat klaar — Deventer Jeugd Musical', ':k' => 'uitnodiging',
                   ':st' => 'verzonden', ':m' => 'graph', ':t' => $moment('-24 days')]);

    // 0–11 hebben opgehaald, 12–16 zijn ingelogd zonder download, de rest nooit.
    if ($i <= 16) {
        $ingelogd = $moment('-' . (22 - $i) . ' days -' . (3 + $i) . ' hours');
        $pdo->prepare('UPDATE deelnemers SET laatst_ingelogd_op = :t WHERE id = :id')->execute([':t' => $ingelogd, ':id' => $id]);
        $pdo->prepare('INSERT INTO mail_log (ontvanger, onderwerp, soort, status, methode, verzonden_op)
                       VALUES (:o, :s, :k, :st, :m, :t)')
            ->execute([':o' => $email, ':s' => 'Uw inlogcode voor Deventer Jeugd Musical', ':k' => 'inlogcode',
                       ':st' => 'verzonden', ':m' => 'graph', ':t' => $ingelogd]);
        foreach (['code_aangevraagd', 'code_ok'] as $soort) {
            $pdo->prepare('INSERT INTO login_log (email, soort, gelukt, ip, user_agent, tijdstip)
                           VALUES (:e, :s, 1, :ip, :ua, :t)')
                ->execute([':e' => $email, ':s' => $soort, ':ip' => $ip(20 + $i), ':ua' => $browser, ':t' => $ingelogd]);
        }
    }
    if ($i <= 11) {
        $gekozen  = $i % 4 === 3 ? $bestand['2026/zeemeermin-2026-klein.mp4'] : $bestand['2026/zeemeermin-2026-fullhd.mp4'];
        $afgerond = $i !== 5;
        $pdo->prepare('INSERT INTO download_log (deelnemer_id, bestand_id, jaargang_id, email, bestandsnaam, ip, user_agent,
                                                 methode, bytes_verzonden, afgerond, gestart_op)
                       VALUES (:d, :b, :j, :e, :n, :ip, :ua, :m, :by, :a, :t)')
            ->execute([':d' => $id, ':b' => $gekozen['id'], ':j' => $jaargang[2026], ':e' => $email, ':n' => $gekozen['naam'],
                       ':ip' => $ip(20 + $i), ':ua' => $browser, ':m' => 'xaccel',
                       ':by' => $afgerond ? $gekozen['bytes'] : intdiv($gekozen['bytes'], 3), ':a' => (int)$afgerond,
                       ':t' => $moment('-' . (22 - $i) . ' days -' . (2 + $i) . ' hours')]);
    }
}

// Een geblokkeerd adres, een mislukte mail door een typefout, een foute code.
$pdo->prepare('UPDATE deelnemers SET geblokkeerd = 1 WHERE email = :e')->execute([':e' => 'esther.schouten@example.nl']);
$pdo->prepare('INSERT INTO mail_log (ontvanger, onderwerp, soort, status, methode, foutmelding, verzonden_op)
               VALUES (:o, :s, :k, :st, :m, :f, :t)')
    ->execute([':o' => 'lars.jacobs@exmaple.nl', ':s' => 'Uw video staat klaar — Deventer Jeugd Musical', ':k' => 'uitnodiging',
               ':st' => 'mislukt', ':m' => 'graph', ':f' => 'Graph gaf 400: ErrorInvalidRecipients — het adres bestaat niet.',
               ':t' => $moment('-24 days')]);
$pdo->prepare('INSERT INTO login_log (email, soort, gelukt, detail, ip, user_agent, tijdstip)
               VALUES (:e, :s, 0, :d, :ip, :ua, :t)')
    ->execute([':e' => 'pieter.deboer@example.nl', ':s' => 'code_fout', ':d' => 'code onjuist (poging 1 van 5)',
               ':ip' => $ip(21), ':ua' => $browser, ':t' => $moment('-21 days -1 hours')]);

$ouder = 'marieke.jansen@example.nl';
echo 'voorbeeldgegevens klaargezet: ', count($ids), " deelnemers\n";
echo $jaargang[2026], ' ', $ouder, ' ', $ids[$ouder], "\n";
