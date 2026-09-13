<?php

/**
 * DJM Portaal — dagelijkse opschoontaak.
 *
 * Verwijdert verlopen inlogcodes, verlopen remember-tokens, oude limietvensters
 * en logregels die de bewaartermijn hebben overschreden. Onvoltooide downloads
 * ouder dan zeven dagen worden op afgerond gezet, zodat de statistieken kloppen.
 * Uploads via Beheer › Bestanden die een week stilliggen, gaan met hun halve
 * bestand weg.
 *
 * Dit script draait uitsluitend op de commandoregel.
 *
 * ── Crontab ────────────────────────────────────────────────────────────────
 * Draait elke nacht om 04:00. Voeg toe met `crontab -e` (als de gebruiker die
 * ook de webserver draait, bijvoorbeeld www-data):
 *
 *     0 4 * * * /usr/bin/php /var/www/djm-portaal/cron_opschonen.php >> /var/www/djm-portaal/logs/cron.log 2>&1
 *
 * Pas het pad naar php en naar de projectmap aan. Op shared hosting staat php
 * vaak op een ander pad, bijvoorbeeld /usr/local/bin/php83 — controleer dat met
 * `which php`. Veel hostingpanelen hebben ook een grafische cronjob-beheerder;
 * gebruik daar hetzelfde commando.
 * ───────────────────────────────────────────────────────────────────────────
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Dit script draait alleen op de commandoregel.\n");
}

require_once __DIR__ . '/config.php';

/** Schrijft een regel naar de uitvoer met tijdstempel. */
function cron_regel(string $tekst): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $tekst . PHP_EOL;
}

/** Voert een opschoonstatement uit en meldt hoeveel regels het raakte. */
function cron_stap(string $omschrijving, string $sql, array $parameters = []): int
{
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($parameters);
        $aantal = $stmt->rowCount();
        cron_regel(sprintf('%-46s %6d regel(s)', $omschrijving, $aantal));
        return $aantal;
    } catch (Throwable $e) {
        cron_regel($omschrijving . ' — MISLUKT: ' . $e->getMessage());
        app_log('cron_opschonen: ' . $omschrijving . ' mislukt', ['fout' => $e->getMessage()]);
        return 0;
    }
}

// ─── Start ───────────────────────────────────────────────────────────────────

$start = microtime(true);
cron_regel('Opschonen gestart — ' . APP_NAME . ' ' . APP_VERSION);

if (!db_beschikbaar()) {
    cron_regel('FOUT: geen verbinding met de database. Controleer .env.');
    exit(1);
}
if (!tabel_bestaat('instellingen')) {
    cron_regel('FOUT: de database is nog niet ingericht. Draai eerst setup.php.');
    exit(1);
}

$bewaartermijn = max(7, instelling_int('log_bewaartermijn_dagen', 365));
cron_regel('Bewaartermijn logboeken: ' . $bewaartermijn . ' dagen');

$totaal = 0;

// ─── Inlogcodes ──────────────────────────────────────────────────────────────
// Codes zijn standaard tien minuten geldig. Alles wat verlopen, gebruikt of
// ingetrokken is en ouder dan 24 uur, kan weg.
$totaal += cron_stap(
    'Verlopen/gebruikte inlogcodes',
    'DELETE FROM login_codes
      WHERE aangemaakt_op < (NOW() - INTERVAL 24 HOUR)
        AND (verloopt_op < NOW() OR gebruikt_op IS NOT NULL OR ingetrokken_op IS NOT NULL)'
);

// ─── Remember-tokens ─────────────────────────────────────────────────────────
$totaal += cron_stap(
    'Verlopen onthoud-dit-apparaat-tokens',
    'DELETE FROM remember_tokens WHERE verloopt_op < NOW()'
);

// ─── Links voor beheerders ───────────────────────────────────────────────────
// Verlopen of gebruikte links zijn nergens meer voor nodig. De tabel bestaat op
// oudere installaties pas zodra er voor het eerst een link is gemaakt.
if (tabel_bestaat('beheerder_tokens')) {
    $totaal += cron_stap(
        'Verlopen/gebruikte beheerderslinks',
        'DELETE FROM beheerder_tokens
          WHERE verloopt_op < NOW() OR gebruikt_op < (NOW() - INTERVAL 24 HOUR)'
    );
}

// ─── Onafgemaakte uploads ────────────────────────────────────────────────────
// Een upload via Beheer › Bestanden die een week niets meer ontving, wordt niet
// meer afgemaakt. Het halve bestand kan gigabytes groot zijn; weg ermee.
require_once __DIR__ . '/includes/bestand_helper.php';
try {
    $aantal = upload_opruimen(UPLOAD_VERLOOP_DAGEN);
    cron_regel(sprintf('%-46s %6d upload(s)', 'Onafgemaakte uploads (' . UPLOAD_VERLOOP_DAGEN . ' dagen stil)', $aantal));
    $totaal += $aantal;
} catch (Throwable $e) {
    cron_regel('Onafgemaakte uploads — MISLUKT: ' . $e->getMessage());
    app_log('cron_opschonen: uploads opruimen mislukt', ['fout' => $e->getMessage()]);
}

// ─── Rate limiting ───────────────────────────────────────────────────────────
$totaal += cron_stap(
    'Oude limietvensters',
    'DELETE FROM aanvraag_limiet WHERE venster_start < (NOW() - INTERVAL 24 HOUR)'
);

// ─── Logboeken ───────────────────────────────────────────────────────────────
// De bewaartermijn is een geheel getal uit de instellingen; hij wordt met %d in
// de query gezet omdat MySQL geen parameter toestaat op elke plek in INTERVAL.
$totaal += cron_stap(
    'Inlogboek buiten bewaartermijn',
    sprintf('DELETE FROM login_log WHERE tijdstip < (NOW() - INTERVAL %d DAY)', $bewaartermijn)
);

$totaal += cron_stap(
    'Mailboek buiten bewaartermijn',
    sprintf('DELETE FROM mail_log WHERE verzonden_op < (NOW() - INTERVAL %d DAY)', $bewaartermijn)
);

$totaal += cron_stap(
    'Downloadboek buiten bewaartermijn',
    sprintf('DELETE FROM download_log WHERE gestart_op < (NOW() - INTERVAL %d DAY)', $bewaartermijn)
);

// ─── Blijven hangen downloads ────────────────────────────────────────────────
// Een download die na zeven dagen nog niet is afgerond, is dat ook nooit meer
// geworden (browser afgesloten, verbinding verbroken). Afsluiten in plaats van
// verwijderen: de gebeurtenis zelf blijft zichtbaar in het logboek.
$totaal += cron_stap(
    'Onvoltooide downloads afgesloten',
    'UPDATE download_log
        SET afgerond = 1
      WHERE afgerond = 0
        AND gestart_op < (NOW() - INTERVAL 7 DAY)'
);

// ─── Afronden ────────────────────────────────────────────────────────────────
cron_regel(sprintf(
    'Klaar — %d regel(s) opgeruimd in %.2f seconden.',
    $totaal,
    microtime(true) - $start
));

exit(0);
