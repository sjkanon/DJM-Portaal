<?php

/**
 * Gedeelde opmaak voor het publieke portaal (inloggen, codeverificatie, downloads).
 *
 * Gebruik:
 *   pagina_start('Inloggen');            // of pagina_start('Inloggen', ['smal' => true])
 *   ... inhoud ...
 *   pagina_eind();
 *
 * Op deze pagina's staat bewust geen JavaScript: inloggen en downloaden werken
 * volledig met gewone formulieren en links. Dat scheelt een bestand dat stuk
 * kan gaan en werkt in elke browser.
 */

if (!function_exists('db')) {
    require_once dirname(__DIR__) . '/config.php';
}
require_once __DIR__ . '/opmaak.php';

/**
 * @param array{smal?:bool} $opties  smal: smalle kaart (440px) voor de inlogstappen.
 */
function pagina_start(string $titel, array $opties = []): void
{
    stuur_security_headers();
    $smal  = (bool)($opties['smal'] ?? false);
    $kleur = branding_kleur();
    $logo  = branding_logo();
    $naam  = portaal_naam();
    ?>
<!DOCTYPE html>
<html lang="nl" data-bs-theme="light">

<head>
    <?php djm_head($titel . ' — ' . $naam, $kleur); ?>
</head>

<body class="djm-portaal">
    <main>
        <div class="djm-kaart card<?= $smal ? ' djm-kaart-smal' : '' ?>">
            <div class="djm-kop">
                <?php if ($logo !== ''): ?>
                    <img src="<?= h($logo) ?>" alt="<?= h($naam) ?>">
                <?php else: ?>
                    <div class="fs-4 fw-semibold"><?= h($naam) ?></div>
                <?php endif; ?>
                <div class="djm-kop-titel small mt-2"><?= h($titel) ?></div>
            </div>
            <div class="card-body p-4 p-md-5">
                <?php foreach (function_exists('flash_ophalen') ? flash_ophalen() : [] as $melding): ?>
                    <div class="alert alert-<?= h($melding['type']) ?> py-2 small">
                        <?= h($melding['bericht']) ?>
                    </div>
                <?php endforeach; ?>
    <?php
}

function pagina_eind(): void
{
    ?>
            </div>
        </div>
    </main>
    <footer>
        <?= h(portaal_naam()) ?> · <?= date('Y') ?>
    </footer>
</body>

</html>
    <?php
}

/**
 * Contactregel voor bezoekers die er niet in komen. Toont niets als er in
 * Beheer > Instellingen geen contactadres is ingevuld.
 */
function toon_contact(): void
{
    $adres = trim(instelling('contact_email', ''));
    if ($adres === '' || !geldig_email($adres)) {
        return;
    }
    $tekst = trim(instelling('contact_tekst', 'Lukt het inloggen niet? Neem contact met ons op.'));

    echo '<p class="text-secondary small mb-0">'
        . ($tekst !== '' ? h($tekst) . ' ' : '')
        . '<a class="link-secondary" href="mailto:' . h(rawurlencode($adres)) . '">' . h($adres) . '</a>'
        . '</p>';
}

/** Toont een foutmelding als alert-blok. */
function toon_fout(string $bericht): void
{
    if ($bericht === '') {
        return;
    }
    echo '<div class="alert alert-danger py-2 small d-flex align-items-start gap-2" role="alert">'
        . '<i class="bi bi-exclamation-triangle-fill"></i><div>' . h($bericht) . '</div></div>';
}

function toon_melding(string $bericht, string $type = 'success'): void
{
    if ($bericht === '') {
        return;
    }
    echo '<div class="alert alert-' . h($type) . ' py-2 small" role="alert">' . h($bericht) . '</div>';
}
