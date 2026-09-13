<?php

/**
 * Beheer — upload in delen, het eindpunt achter admin/assets/upload.js.
 *
 * Geen pagina maar een JSON-API voor Beheer › Bestanden. Het verloop staat in
 * includes/bestand_helper.php. Elk verzoek is een POST met het CSRF-token in
 * de header X-CSRF-Token:
 *
 *   ?actie=start                     JSON {jaargang, naam, bytes, gewijzigd}
 *   ?actie=deel&sleutel=…&offset=…   ruwe bytes, optioneel header X-Deel-Sha256
 *   ?actie=afronden&sleutel=…        JSON {koppelen, titel, bestandsnaam, sortering, actief}
 *   ?actie=annuleren&sleutel=…
 *
 * Antwoord: 200 met JSON, of een foutstatus met {fout: "…"} (en soms
 * {ontvangen: n}). Wat de browser bij welke status doet, staat in upload.js.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/bestand_helper.php';
vereis_installatie();

function upload_antwoord(int $status, array $gegevens): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($gegevens, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

stuur_security_headers();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    upload_antwoord(405, ['fout' => 'Alleen POST is toegestaan.']);
}

// Zonder sessie antwoordt vereis_beheerder() met 401 in plaats van een
// doorverwijzing, omdat upload.js om JSON vraagt.
$beheerder = vereis_beheerder();
if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    // Bewust 403 en niet 419 zoals vereis_csrf(): Apache kent 419 niet en maakt
    // er een 500 van, en dan kan upload.js de fout niet meer herkennen.
    upload_antwoord(403, ['fout' => 'Uw sessie is verlopen.']);
}

$actie   = (string)($_GET['actie'] ?? '');
$sleutel = (string)($_GET['sleutel'] ?? '');

try {
    if ($actie === 'deel') {
        $upload = upload_ophalen($sleutel) ?? throw new UploadFout(404, 'Deze upload bestaat niet meer.');
        $lengte = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($lengte <= 0) {
            throw new UploadFout(411, 'Een stuk zonder lengte kan niet worden verwerkt.');
        }
        // Groter dan post_max_size: PHP heeft de body dan al weggegooid.
        $postMax = ini_bytes((string)ini_get('post_max_size'));
        if ($postMax > 0 && $lengte > $postMax) {
            throw new UploadFout(413, 'Dit stuk is te groot voor de server.', ['deelgrootte' => upload_deelgrootte()]);
        }

        // Schrijven kan even duren; laat andere tabbladen van deze beheerder
        // intussen gewoon laden. De sessie is hierboven al bijgewerkt, dus een
        // upload van uren houdt de beheerder ingelogd.
        session_write_close();
        @set_time_limit(300);

        $ontvangen = upload_deel_schrijven($upload, (int)($_GET['offset'] ?? -1), $lengte,
            isset($_SERVER['HTTP_X_DEEL_SHA256']) ? (string)$_SERVER['HTTP_X_DEEL_SHA256'] : null);
        upload_antwoord(200, ['ontvangen' => $ontvangen]);
    }

    $invoer = json_decode((string)file_get_contents('php://input', false, null, 0, 65536), true);
    $invoer = is_array($invoer) ? $invoer : [];

    if ($actie === 'start') {
        upload_antwoord(200, upload_start(
            (int)$beheerder['id'],
            (int)($invoer['jaargang'] ?? 0),
            (string)($invoer['naam'] ?? ''),
            (int)($invoer['bytes'] ?? 0),
            (int)($invoer['gewijzigd'] ?? 0)
        ));
    }

    if ($actie === 'afronden') {
        $upload = upload_ophalen($sleutel) ?? throw new UploadFout(404, 'Deze upload bestaat niet meer.');
        $koppeling = empty($invoer['koppelen']) ? null : [
            'titel'        => (string)($invoer['titel'] ?? ''),
            'bestandsnaam' => (string)($invoer['bestandsnaam'] ?? ''),
            'sortering'    => (int)($invoer['sortering'] ?? 0),
            'actief'       => !empty($invoer['actief']),
        ];
        @set_time_limit(300);
        $uitkomst = upload_afronden($upload, $koppeling);

        if ($uitkomst['gekoppeld']) {
            flash('success', 'Bestand geüpload en gekoppeld: ' . $uitkomst['pad'] . ' (' . formatteer_bytes($uitkomst['bytes']) . ').');
        } elseif ($uitkomst['koppelen_mislukt']) {
            flash('warning', 'Het bestand ' . $uitkomst['pad'] . ' staat in de opslagmap, maar koppelen is mislukt. '
                . 'Kies het hieronder bij "Kiezen uit de opslagmap".');
        } else {
            flash('success', 'Bestand geüpload: ' . $uitkomst['pad'] . '. Kies het hieronder om het te koppelen.');
        }
        upload_antwoord(200, $uitkomst);
    }

    if ($actie === 'annuleren') {
        $upload = upload_ophalen($sleutel);
        if ($upload !== null) {
            upload_verwijderen($upload);
        }
        upload_antwoord(200, ['geannuleerd' => true]);
    }

    upload_antwoord(400, ['fout' => 'Onbekende actie.']);
} catch (UploadFout $e) {
    upload_antwoord($e->status, ['fout' => $e->getMessage()] + $e->extra);
} catch (Throwable $e) {
    app_log('upload mislukt', ['actie' => $actie, 'fout' => $e->getMessage()]);
    upload_antwoord(500, ['fout' => 'Er ging iets mis op de server.']);
}
