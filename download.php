<?php

/**
 * Uitlevering van een videobestand.
 *
 * Volgorde: sessie -> autorisatie via deelnemer_bestand() -> handtekening ->
 * pad uit de database -> logboek -> uitleveren. Er komt nooit een pad uit de
 * URL; alleen een bestand-id dat via de database naar een pad wordt vertaald.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/toegang_helper.php';
require_once __DIR__ . '/includes/download_helper.php';

vereis_installatie();

$deelnemer = vereis_deelnemer();

/** Minimale foutpagina: geen CDN, geen layout, want dit is een downloadendpoint. */
function download_foutpagina(int $code, string $kop, string $tekst): void
{
    http_response_code($code);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="nl"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . h($kop) . '</title></head>'
        . '<body style="font-family:sans-serif;max-width:34rem;margin:4rem auto;padding:0 1rem;color:#1e293b">'
        . '<h1 style="font-size:1.4rem">' . h($kop) . '</h1>'
        . '<p>' . h($tekst) . '</p>'
        . '<p><a href="' . h(url('portaal/index.php')) . '">Terug naar het overzicht</a></p>'
        . '</body></html>';
    exit;
}

// ─── Autorisatie: mag deze deelnemer dit bestand hebben? ─────────────────────
$bestandId = (int)($_GET['b'] ?? 0);
$bestand   = $bestandId > 0 ? deelnemer_bestand((int)$deelnemer['id'], $bestandId) : null;

if ($bestand === null) {
    download_foutpagina(
        404,
        'Bestand niet gevonden',
        'Dit bestand bestaat niet of u heeft er geen toegang toe.'
    );
}

// ─── Handtekening: tweede laag naast de sessie ───────────────────────────────
// Ontbreekt of verloopt de handtekening, dan is dat geen fout maar gewoon een
// oude link: terug naar het overzicht, daar staat een verse knop.
$vervalt      = (int)($_GET['t'] ?? 0);
$handtekening = (string)($_GET['s'] ?? '');

if (!download_handtekening_geldig($bestandId, (int)$deelnemer['id'], $vervalt, $handtekening)) {
    flash('warning', 'De downloadlink is verlopen. Klik opnieuw op downloaden.');
    header('Location: ' . url('portaal/index.php'));
    exit;
}

// ─── Pad: uitsluitend id -> database -> opslag_absoluut_pad() ────────────────
$absoluutPad = opslag_absoluut_pad((string)$bestand['pad']);

if ($absoluutPad === null) {
    // Het bestand staat wel in de database maar niet meer op schijf: verplaatst,
    // hernoemd of verwijderd door de beheerder.
    app_log('downloadbestand ontbreekt op schijf', [
        'bestand_id' => $bestandId,
        'pad'        => (string)$bestand['pad'],
    ]);
    download_foutpagina(
        404,
        'Bestand niet beschikbaar',
        'Het bestand staat op dit moment niet klaar. Neem contact met ons op, dan zetten wij het opnieuw voor u klaar.'
    );
}

// ─── Uitleveren ─────────────────────────────────────────────────────────────
$methode = download_methode();
$logId   = download_loggen($bestand, $deelnemer, $methode);

download_uitleveren($bestand, $absoluutPad, $methode, $logId);
