<?php

/**
 * Eenheidstests op de beveiligingshelpers.
 *
 * Draait op de commandoregel in de testcontainer:
 *     docker compose exec -T web php /app/test/beveiliging.php
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/email_helper.php';
require_once dirname(__DIR__) . '/includes/download_helper.php';

$goed = 0;
$fout = 0;

function toets(string $omschrijving, $verwacht, $werkelijk): void
{
    global $goed, $fout;
    if ($verwacht === $werkelijk) {
        printf("    ✓ %s\n", $omschrijving);
        $goed++;
        return;
    }
    printf(
        "    ✗ %s\n        verwacht: %s\n        kreeg:    %s\n",
        $omschrijving,
        var_export($verwacht, true),
        var_export($werkelijk, true)
    );
    $fout++;
}

// ─── Mailheaders: geen regeleindes het protocol in ───────────────────────────
echo "  Mailheaders\n";
toets(
    'regeleinde uit een naam verdwijnt',
    'Jan JansenBcc: stiekem@example.net',
    mail_kopregel_veilig("Jan Jansen\r\nBcc: stiekem@example.net")
);
toets('nulbyte verdwijnt', 'Jan', mail_kopregel_veilig("Jan\0"));
toets(
    'adres met een ingesmokkelde header wordt geweigerd',
    '',
    mail_adres_veilig("ouder@example.nl\r\nBcc: stiekem@example.net")
);
toets('gewoon adres blijft heel', 'ouder@example.nl', mail_adres_veilig(' ouder@example.nl '));
toets('onzin is geen adres', '', mail_adres_veilig('geen adres'));

// ─── Paden blijven binnen de opslagmap ───────────────────────────────────────
echo "  Bestandspaden\n";
foreach (['../config.php', '../../etc/passwd', '/etc/passwd', "2026/..\0/x", '2026/../../config.php'] as $poging) {
    toets('geweigerd: ' . str_replace("\0", '\\0', $poging), null, opslag_absoluut_pad($poging));
}

// ─── Ondertekende downloadlinks ──────────────────────────────────────────────
echo "  Downloadlinks\n";
$vervalt = time() + 300;
$geldig  = hash_hmac('sha256', "7|42|$vervalt", app_key());
toets('eigen handtekening klopt', true, download_handtekening_geldig(7, 42, $vervalt, $geldig));
toets('ander bestand-id werkt niet', false, download_handtekening_geldig(8, 42, $vervalt, $geldig));
toets('andere deelnemer werkt niet', false, download_handtekening_geldig(7, 43, $vervalt, $geldig));
toets('verlopen link werkt niet', false, download_handtekening_geldig(7, 42, time() - 1, $geldig));
toets('lege handtekening werkt niet', false, download_handtekening_geldig(7, 42, $vervalt, ''));

// ─── Host-header ─────────────────────────────────────────────────────────────
echo "  Host-header\n";
$_SERVER['HTTP_HOST'] = 'portaal.example.nl';
toets('nette host wordt overgenomen', 'portaal.example.nl', veilige_host());
$_SERVER['HTTP_HOST'] = 'portaal.example.nl:8443';
toets('poortnummer mag', 'portaal.example.nl:8443', veilige_host());
$_SERVER['HTTP_HOST'] = 'kwaadaardig.nl/pad';
toets('host met een pad wordt geweigerd', 'localhost', veilige_host());
$_SERVER['HTTP_HOST'] = "evil.nl\r\nX: 1";
toets('host met een regeleinde wordt geweigerd', 'localhost', veilige_host());

// ─── CSV-export ──────────────────────────────────────────────────────────────
echo "  CSV-export\n";
require_once dirname(__DIR__) . '/includes/toegang_helper.php';
if (!function_exists('toegang_csv_veld')) {
    // De functie staat in admin/toegang.php; die pagina eist een beheerder, dus
    // halen we alleen de functie zelf op.
    $bron = (string)file_get_contents(dirname(__DIR__) . '/admin/toegang.php');
    if (preg_match('/function toegang_csv_veld\(.*?\n\}/s', $bron, $treffer)) {
        eval($treffer[0]);
    }
}
if (function_exists('toegang_csv_veld')) {
    toets('formule wordt onschadelijk gemaakt', "'=1+1", toegang_csv_veld('=1+1'));
    toets('plus wordt onschadelijk gemaakt', "'+HYPERLINK(\"x\")", toegang_csv_veld('+HYPERLINK("x")'));
    toets('gewone naam blijft heel', 'Jan Jansen', toegang_csv_veld('Jan Jansen'));
    toets('leeg blijft leeg', '', toegang_csv_veld(''));
} else {
    echo "    ✗ toegang_csv_veld() niet gevonden\n";
    $fout++;
}

printf("\n  %d geslaagd, %d mislukt\n", $goed, $fout);
exit($fout === 0 ? 0 : 1);
