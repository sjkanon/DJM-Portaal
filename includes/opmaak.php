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

/** Het adres van het logo, of een lege string als er geen logo is. */
function branding_logo(): string
{
    $logo = trim(instelling('branding_logo_url', ''));
    if ($logo !== '') {
        return $logo;
    }
    return is_file(APP_ROOT . '/assets/logo.png') ? url('assets/logo.png') : '';
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
    return url($pad) . '?v=' . rawurlencode(APP_VERSION);
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
