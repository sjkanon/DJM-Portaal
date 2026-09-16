<?php

/**
 * Eenheidstests op de beveiligingshelpers.
 *
 * Draait op de commandoregel in de testcontainer:
 *     docker compose exec -T web php /app/test/beveiliging.php
 */


// Deze testscripts horen uitsluitend op de commandoregel te draaien. Ze wijzigen
// of wissen gegevens; wordt de map test/ per ongeluk meegeüpload naar een
// server, dan mag een bezoeker ze nooit via de browser kunnen starten.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
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

// ─── Vertrouwde proxy's ──────────────────────────────────────────────────────
echo "  Vertrouwde proxy's\n";

// Bereikcontroles: de kern van "welke proxy mag ik geloven".
toets('IPv4 binnen /8', true, ip_in_bereik('10.1.2.3', '10.0.0.0/8'));
toets('IPv4 buiten /8', false, ip_in_bereik('11.1.2.3', '10.0.0.0/8'));
toets('IPv4 binnen /24', true, ip_in_bereik('192.168.1.7', '192.168.1.0/24'));
toets('IPv4 buiten /24', false, ip_in_bereik('192.168.2.7', '192.168.1.0/24'));
toets('los adres gelijk', true, ip_in_bereik('10.1.2.3', '10.1.2.3'));
toets('los adres ongelijk', false, ip_in_bereik('10.1.2.4', '10.1.2.3'));
toets('IPv6 binnen /32', true, ip_in_bereik('2001:db8::5', '2001:db8::/32'));
toets('IPv6 buiten /32', false, ip_in_bereik('2001:db9::5', '2001:db8::/32'));
toets('IPv4 valt nooit in een IPv6-bereik', false, ip_in_bereik('10.1.2.3', '2001:db8::/32'));
toets('onzin als bereik telt niet mee', false, ip_in_bereik('10.1.2.3', 'geen bereik'));
toets('te groot prefix telt niet mee', false, ip_in_bereik('10.1.2.3', '10.0.0.0/33'));

// Zonder TRUSTED_PROXIES (de stand in deze testcontainer) mag X-Forwarded-For
// niets veranderen: iedereen kan die header zelf meesturen.
$_SERVER['REMOTE_ADDR']          = '203.0.113.9';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
toets('zonder TRUSTED_PROXIES telt alleen REMOTE_ADDR', '203.0.113.9', client_ip());
unset($_SERVER['HTTP_X_FORWARDED_FOR']);

// De volledige matrix mét een proxylijst staat in test/proxy_test.php; die moet
// in een eigen proces draaien omdat vertrouwde_proxies() de lijst cachet.

// ─── Sleutels van de rem ─────────────────────────────────────────────────────
echo "  Rate-limitsleutels\n";
// De tabel aanvraag_limiet telt alleen; er hoort geen e-mailadres of IP-adres
// in te staan dat later uit een back-up te lezen is.
$sleutel = limiet_sleutel('code', 'ouder@example.nl');
toets('het adres staat niet in de sleutel', false, str_contains($sleutel, 'ouder@example.nl'));
toets('het soort blijft leesbaar', true, str_starts_with($sleutel, 'code:'));
toets('zelfde adres geeft dezelfde sleutel', $sleutel, limiet_sleutel('code', 'ouder@example.nl'));
toets(
    'een ander adres geeft een andere sleutel',
    false,
    $sleutel === limiet_sleutel('code', 'andere@example.nl')
);
toets(
    'hetzelfde adres in een ander soort telt apart',
    false,
    $sleutel === limiet_sleutel('ip', 'ouder@example.nl')
);
toets('de sleutel past in de kolom', true, strlen(limiet_sleutel('adminreset-ip', '2001:db8::5')) <= 190);

// ─── Beheerderssessies ───────────────────────────────────────────────────────
echo "  Beheerderssessies\n";
require_once dirname(__DIR__) . '/includes/auth.php';
$hashEen  = password_hash('wachtwoord-een', PASSWORD_DEFAULT);
$hashTwee = password_hash('wachtwoord-twee', PASSWORD_DEFAULT);
toets('dezelfde hash geeft dezelfde stempel', beheerder_stempel($hashEen), beheerder_stempel($hashEen));
toets(
    'een nieuw wachtwoord geeft een andere stempel',
    false,
    hash_equals(beheerder_stempel($hashEen), beheerder_stempel($hashTwee))
);
// De stempel gaat de sessie in; op gedeelde hosting is dat bestand niet altijd
// alleen van ons, dus er mag geen stuk van de wachtwoordhash in te lezen zijn.
toets(
    'de stempel bevat geen stuk van de hash',
    false,
    str_contains(beheerder_stempel($hashEen), substr($hashEen, 7, 16))
);

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
