<?php

/**
 * Uitlevering van een videobestand.
 *
 * Volgorde: sessie -> autorisatie via deelnemer_bestand() -> handtekening ->
 * pad uit de database -> logboek -> uitleveren. Er komt nooit een pad uit de
 * URL; alleen een bestand-id dat via de database naar een pad wordt vertaald.
 *
 * Dit eindpunt stuurt nooit door naar een gewone pagina. Een browser en zeker
 * een downloadmanager volgen zo'n omleiding en bewaren de HTML die ze daar
 * vinden als bestand — dat is hoe deelnemers een 'index.php' van een paar
 * kilobyte in hun downloadmap kregen in plaats van hun video. Gaat er iets mis,
 * dan is het antwoord een foutstatus met een leesbare pagina; een
 * downloadprogramma ziet daaraan dat de download mislukt is en bewaart niets.
 * De enige uitzondering is de omleiding naar een verse downloadlink hieronder:
 * die komt óók weer op dit eindpunt uit en levert dus gewoon de video.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/toegang_helper.php';
require_once __DIR__ . '/includes/download_helper.php';

vereis_installatie();

/** Minimale foutpagina: geen CDN, geen layout, want dit is een downloadendpoint. */
function download_foutpagina(int $code, string $kop, string $tekst, string $knop = 'Terug naar het overzicht', string $doel = 'portaal/index.php'): void
{
    http_response_code($code);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, private');
    echo '<!DOCTYPE html><html lang="nl"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . h($kop) . '</title></head>'
        . '<body style="font-family:sans-serif;max-width:34rem;margin:4rem auto;padding:0 1rem;color:#1e293b">'
        . '<h1 style="font-size:1.4rem">' . h($kop) . '</h1>'
        . '<p>' . h($tekst) . '</p>'
        . '<p><a href="' . h(url($doel)) . '">' . h($knop) . '</a></p>'
        . '</body></html>';
    exit;
}

// ─── Sessie ─────────────────────────────────────────────────────────────────
// Een download van een paar gigabyte kan uren duren en wordt soms pas de
// volgende dag hervat; de sessie is dan allang verlopen. Staat er een
// onthoud-dit-apparaat-cookie, dan loggen we de deelnemer hier gewoon weer in,
// zodat het hervatten slaagt in plaats van op de inlogpagina te stranden.
herstel_uit_remember_cookie();

$deelnemer = huidige_deelnemer();

if ($deelnemer === null) {
    download_foutpagina(
        403,
        'U bent niet meer ingelogd',
        'Uw sessie is verlopen. Log opnieuw in, dan kunt u de download starten of hervatten.',
        'Opnieuw inloggen',
        'index.php?reden=sessie'
    );
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
// Een verlopen handtekening is geen fout: deze deelnemer is ingelogd en mag dit
// bestand hebben, dat is hierboven vastgesteld. Hij krijgt daarom een verse
// link — een omleiding die weer op dit eindpunt uitkomt en dus de video
// oplevert, ook als het verzoek van een downloadmanager komt die hervat.
$vervalt      = (int)($_GET['t'] ?? 0);
$handtekening = (string)($_GET['s'] ?? '');

if (!download_handtekening_geldig($bestandId, (int)$deelnemer['id'], $vervalt, $handtekening)) {
    if (!download_link_net_vernieuwd((string)($_GET['v'] ?? ''))) {
        header('Location: ' . download_link($bestandId, (int)$deelnemer['id'], true));
        exit;
    }
    // De verse link werkte ook niet. Dan klopt er iets niet aan de serverklok of
    // is APP_KEY gewijzigd; nog een keer doorsturen wordt een lus.
    app_log('verse downloadlink werd meteen weer afgekeurd', [
        'bestand_id'   => $bestandId,
        'deelnemer_id' => (int)$deelnemer['id'],
        'vervalt'      => $vervalt,
        'nu'           => time(),
    ]);
    download_foutpagina(
        410,
        'De downloadlink werkt niet',
        'Ga terug naar het overzicht en klik daar opnieuw op Downloaden. Blijft het misgaan, neem dan contact met ons op.'
    );
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

// Een HEAD is de vraag of hervatten kan, niet het ophalen zelf; die hoort niet
// als download in het logboek.
// De handtekening gaat mee het logboek in. Levert de webserver zelf uit, dan is
// dat later de sleutel om in zijn log op te zoeken hoeveel bytes er echt over de
// lijn gingen — zie includes/webserverlog_helper.php.
$logId = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD'
    ? 0
    : download_loggen($bestand, $deelnemer, $methode, $handtekening);

download_uitleveren($bestand, $absoluutPad, $methode, $logId);
