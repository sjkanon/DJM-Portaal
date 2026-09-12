<?php

/**
 * Draait client_ip() en https_actief() door met een ingestelde proxylijst.
 *
 * Apart van test/beveiliging.php omdat vertrouwde_proxies() de lijst statisch
 * cachet: één lijst per proces. Aanroepen met de lijst als omgevingsvariabele:
 *
 *     docker compose exec -T -e TRUSTED_PROXIES="10.0.0.0/8,172.16.0.0/12" \
 *         web php /app/test/proxy_test.php
 */


// Deze testscripts horen uitsluitend op de commandoregel te draaien. Ze wijzigen
// of wissen gegevens; wordt de map test/ per ongeluk meegeüpload naar een
// server, dan mag een bezoeker ze nooit via de browser kunnen starten.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require_once dirname(__DIR__) . '/config.php';

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

/** Zet de omgeving van één verzoek klaar en geeft het gevonden IP-adres terug. */
function via(string $remote, ?string $xff): string
{
    $_SERVER['REMOTE_ADDR'] = $remote;
    if ($xff === null) {
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    } else {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = $xff;
    }
    return client_ip();
}

echo "  Proxylijst: " . implode(', ', vertrouwde_proxies()) . "\n";
if (vertrouwde_proxies() === []) {
    echo "    ✗ TRUSTED_PROXIES is niet meegegeven; deze test zegt zo niets.\n";
    exit(1);
}

toets('via een vertrouwde proxy telt het echte adres', '203.0.113.7', via('10.0.0.5', '203.0.113.7'));
toets('keten van twee eigen proxys', '203.0.113.7', via('10.0.0.5', '203.0.113.7, 172.16.0.9'));
toets('bezoeker die links een adres bijschrijft wordt genegeerd', '203.0.113.7', via('10.0.0.5', '1.1.1.1, 203.0.113.7'));
toets('onzin in de header wordt overgeslagen', '203.0.113.7', via('10.0.0.5', 'geen-ip, 203.0.113.7'));
toets('alleen eigen proxys: terugvallen op REMOTE_ADDR', '10.0.0.5', via('10.0.0.5', '172.16.0.9'));
toets('IPv6-bezoeker', '2001:db8::1234', via('10.0.0.5', '2001:db8::1234'));
toets('IPv6 tussen vierkante haken', '2001:db8::1234', via('10.0.0.5', '[2001:db8::1234]'));
toets('geen header: REMOTE_ADDR', '10.0.0.5', via('10.0.0.5', null));
toets('rechtstreeks binnengekomen: header genegeerd', '203.0.113.9', via('203.0.113.9', '1.1.1.1'));

// X-Forwarded-Proto: alleen van een vertrouwde proxy.
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
unset($_SERVER['HTTPS']);
$_SERVER['SERVER_PORT'] = 80;

$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
toets('X-Forwarded-Proto van een vreemde is waardeloos', false, https_actief());

$_SERVER['REMOTE_ADDR'] = '10.0.0.5';
toets('X-Forwarded-Proto van de eigen proxy telt wel', true, https_actief());

printf("\n  %d geslaagd, %d mislukt\n", $goed, $fout);
exit($fout === 0 ? 0 : 1);
