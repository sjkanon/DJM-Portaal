<?php

/**
 * Gedeelde bouwstenen voor de opmaak van portaal, beheer en installatie.
 *
 * Hier staat alles wat alle drie de layouts nodig hebben: de merkkleur, het
 * logo, het favicon en het complete <head>-blok. De layouts zelf gaan daarna
 * alleen nog over hun eigen structuur.
 *
 * Belangrijk voor het beheer op afstand: de stijlbestanden komen uit assets/
 * van deze installatie, niet van een CDN. Valt het internet weg of verdwijnt
 * er een CDN-versie, dan blijft het portaal gewoon werken.
 */

if (!function_exists('instelling')) {
    require_once dirname(__DIR__) . '/config.php';
}

/** Kleur waarop we terugvallen als er niets (geldigs) is ingesteld. */
const DJM_STANDAARD_KLEUR = '#0d6efd';

/**
 * De ingestelde merkkleur, of de standaardkleur als de instelling ontbreekt of
 * onzin bevat. Alle plekken die de merkkleur gebruiken lopen via deze functie,
 * zodat er maar één plek is waar de controle staat.
 */
function branding_kleur(): string
{
    $kleur = trim(instelling('branding_kleur', DJM_STANDAARD_KLEUR));
    return preg_match('/^#[0-9A-Fa-f]{6}$/', $kleur) ? $kleur : DJM_STANDAARD_KLEUR;
}

/**
 * Zwarte of witte tekst op de merkkleur, wat op die achtergrond leesbaar is.
 *
 * Een beheerder mag elke kleur kiezen. Bij een lichte keuze (geel, lichtgroen)
 * zou vaste witte tekst onleesbaar worden, en daar kijkt niemand meer naar om
 * zodra de site live staat. Daarom rekenen we het uit volgens de WCAG-formule
 * voor relatieve luminantie in plaats van het te gokken.
 */
function branding_tekstkleur(?string $kleur = null): string
{
    return branding_luminantie($kleur ?? branding_kleur()) > 0.45 ? '#212529' : '#ffffff';
}

/** Dezelfde tekstkleur, iets gedempt — voor ondertitels en inactieve menu-items. */
function branding_tekstkleur_zacht(?string $kleur = null): string
{
    return branding_tekstkleur($kleur) === '#ffffff'
        ? 'rgba(255, 255, 255, .82)'
        : 'rgba(33, 37, 41, .72)';
}

/** Relatieve luminantie (0 = zwart, 1 = wit) van een kleur als #rrggbb. */
function branding_luminantie(string $kleur): float
{
    $kanalen = [
        hexdec(substr($kleur, 1, 2)),
        hexdec(substr($kleur, 3, 2)),
        hexdec(substr($kleur, 5, 2)),
    ];
    $wegingen = [0.2126, 0.7152, 0.0722];
    $totaal   = 0.0;

    foreach ($kanalen as $i => $waarde) {
        $v = $waarde / 255;
        // sRGB terugrekenen naar lineair licht; onder deze grens is de curve recht.
        $lineair = $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        $totaal += $wegingen[$i] * $lineair;
    }

    return $totaal;
}

// ─── Het geüploade logo ──────────────────────────────────────────────────────
//
// Een logo komt niet meer in assets/ te staan, maar in `branding/` binnen de
// opslagmap, en gaat naar buiten via logo.php. Reden: een SVG is geen plaatje
// maar XML en mag scripts bevatten. In een <img>-tag voert geen browser die
// uit, maar wie de URL rechtstreeks opent wél — en dan binnen onze eigen
// origin, met de rechten van een ingelogde beheerder. De opschoning bij de
// upload haalt scripts eruit, maar dat is een filter op tekst en filters
// hebben gaten. De harde grens is de Content-Security-Policy die logo.php zelf
// meestuurt; daarvoor moeten de bytes wél langs PHP komen. De regels in
// .htaccess en de voorbeeldconfiguraties doen hetzelfde, maar die gelden alleen
// als de webserver ze uitvoert: .htaccess doet niets onder nginx, en op Plesk
// levert nginx statische bestanden vaak zelf uit.

/** Maximale grootte van een geüpload logo: 2 MB. */
const LOGO_MAX_BYTES = 2097152;

/** Extensies waaronder een geüpload logo kan staan. */
const LOGO_EXTENSIES = ['png', 'jpg', 'webp', 'svg'];

/** De map waarin een geüpload logo terechtkomt; niet publiek benaderbaar. */
function logo_map(): string
{
    return opslag_pad() . '/branding';
}

/**
 * Waar logo's vóór deze versie stonden. Een bestaande installatie houdt zijn
 * logo gewoon; het verhuist zodra er een nieuw bestand wordt geüpload.
 */
function logo_oude_map(): string
{
    return APP_ROOT . '/assets';
}

/** Absoluut pad van het geüploade logo, of null als er geen is. */
function logo_pad(): ?string
{
    foreach ([logo_map(), logo_oude_map()] as $map) {
        foreach (LOGO_EXTENSIES as $ext) {
            $pad = $map . '/logo.' . $ext;
            if (is_file($pad)) {
                return $pad;
            }
        }
    }
    return null;
}

/** Bestandsnaam van het geüploade logo (`logo.png`, …), of null. */
function logo_bestandsnaam(): ?string
{
    $pad = logo_pad();
    return $pad === null ? null : basename($pad);
}

/** Gooit elk eerder geüpload logo weg, in beide mappen: er blijft er hooguit één. */
function logo_bestanden_verwijderen(): void
{
    foreach ([logo_map(), logo_oude_map()] as $map) {
        foreach (LOGO_EXTENSIES as $ext) {
            $pad = $map . '/logo.' . $ext;
            if (is_file($pad)) {
                @unlink($pad);
            }
        }
    }
}

/** Mimetype van het logo, op basis van de extensie die we zelf hebben gezet. */
function logo_mime(string $pad): string
{
    return [
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'webp' => 'image/webp',
        'svg'  => 'image/svg+xml',
    ][strtolower((string)pathinfo($pad, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
}

/** Het adres van het logo, of een lege string als er geen logo is. */
function branding_logo(): string
{
    $logo = trim(instelling('branding_logo_url', ''));
    if ($logo !== '') {
        return $logo;
    }
    return logo_pad() !== null ? url('logo.php') : '';
}

/**
 * Favicon als data-URI, in de merkkleur. Zo hoort het tabblad bij de rest van
 * de huisstijl in plaats van bij de standaardkleur van dit project.
 */
function djm_favicon(string $kleur): string
{
    $svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'>"
        . "<rect width='32' height='32' rx='7' fill='" . $kleur . "'/>"
        . "<path d='M12 9.5v13l10-6.5z' fill='" . branding_tekstkleur($kleur) . "'/>"
        . '</svg>';

    return 'data:image/svg+xml,' . rawurlencode($svg);
}

/**
 * Adres van een eigen stijl- of scriptbestand, met het versienummer erachter.
 *
 * Na een update haalt de browser het bestand daardoor opnieuw op in plaats van
 * een oude versie uit zijn cache te blijven gebruiken.
 */
function djm_asset(string $pad): string
{
    // Ook de wijzigingstijd, zodat een aangepast bestand zonder nieuwe versie
    // (een hotfix, of tijdens ontwikkeling) niet uit de cache blijft komen.
    $gewijzigd = @filemtime(APP_ROOT . '/' . $pad);
    return url($pad) . '?v=' . rawurlencode(APP_VERSION . ($gewijzigd ? '-' . $gewijzigd : ''));
}

/**
 * Het complete <head>-blok, inclusief de merkkleur als CSS-variabelen.
 *
 * $kleur wordt apart meegegeven omdat setup.php dit ook gebruikt; daar is er
 * nog geen database om een ingestelde kleur uit te lezen.
 */
function djm_head(string $titel, string $kleur = DJM_STANDAARD_KLEUR): void
{
    ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="<?= h($kleur) ?>">
    <link rel="icon" href="<?= h(djm_favicon($kleur)) ?>">
    <title><?= h($titel) ?></title>
    <link rel="stylesheet" href="<?= h(djm_asset('assets/vendor/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= h(djm_asset('assets/vendor/bootstrap-icons.css')) ?>">
    <link rel="stylesheet" href="<?= h(djm_asset('assets/djm.css')) ?>">
    <style>
        :root {
            --djm: <?= h($kleur) ?>;
            --djm-tekst: <?= h(branding_tekstkleur($kleur)) ?>;
            --djm-tekst-zacht: <?= h(branding_tekstkleur_zacht($kleur)) ?>;
        }
    </style>
    <?php
}
