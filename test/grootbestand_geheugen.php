<?php
/**
 * Leest 16 MB uit het midden van het bestand van 5 GB, op dezelfde manier als
 * download_uitleveren_php(): in blokken van 8 KB vanaf een offset voorbij 4 GB.
 * Print het piekgeheugengebruik in MB.
 */

// Deze testscripts horen uitsluitend op de commandoregel te draaien. Ze wijzigen
// of wissen gegevens; wordt de map test/ per ongeluk meegeüpload naar een
// server, dan mag een bezoeker ze nooit via de browser kunnen starten.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$pad = '/app/opslag/2028/musical-2028.mp4';
if (!is_file($pad)) {
    echo "0\n";
    exit(1);
}

$handvat = fopen($pad, 'rb');
fseek($handvat, 4000000000);          // voorbij de 32-bit grens
$gelezen = 0;
for ($i = 0; $i < 2000; $i++) {
    $blok = fread($handvat, 8192);
    if ($blok === false || $blok === '') {
        break;
    }
    $gelezen += strlen($blok);
}
fclose($handvat);

fwrite(STDERR, "gelezen: $gelezen bytes vanaf offset 4000000000\n");
echo (int)ceil(memory_get_peak_usage(true) / 1048576), "\n";
