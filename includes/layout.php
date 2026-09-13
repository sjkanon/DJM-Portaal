<?php

/**
 * Gedeelde opmaak voor het publieke portaal (inloggen, codeverificatie, downloads).
 *
 * Alle pagina's hebben dezelfde opbouw: een navigatiebalk met het logo, een kop
 * in de merkkleur met de paginatitel en daaronder de inhoud.
 *
 * Gebruik:
 *   portaal_start('Inloggen', ['smal' => true, 'intro' => '…']);
 *   ... inhoud ...
 *   portaal_eind();
 *
 * Op deze pagina's staat bewust geen JavaScript: inloggen en downloaden werken
 * volledig met gewone formulieren en links. Dat scheelt een bestand dat stuk
 * kan gaan en werkt in elke browser. De navigatiebalk heeft daarom ook niets om
 * in te klappen; op een smal scherm valt alleen het e-mailadres weg.
 */

if (!function_exists('db')) {
    require_once dirname(__DIR__) . '/config.php';
}
require_once __DIR__ . '/opmaak.php';

/**
 * @param array{email?:string, intro?:string, smal?:bool} $opties
 *   email  adres van de ingelogde deelnemer; toont het adres en een uitlogknop in de balk.
 *   intro  korte toelichting onder de titel in de kop.
 *   smal   smalle, gecentreerde kaart voor de inlogstappen.
 */
function portaal_start(string $titel, array $opties = []): void
{
    stuur_security_headers();
    $email = (string)($opties['email'] ?? '');
    $intro = (string)($opties['intro'] ?? '');
    $smal  = (bool)($opties['smal'] ?? false);
    $kleur = branding_kleur();
    $logo  = branding_logo();
    $naam  = portaal_naam();

    // portaal_eind() moet weten of er nog een kaart dicht moet.
    $GLOBALS['djm_portaal_smal'] = $smal;
    ?>
<!DOCTYPE html>
<html lang="nl" data-bs-theme="light">

<head>
    <?php djm_head($titel . ' — ' . $naam, $kleur); ?>
</head>

<body class="djm-portaal">
    <nav class="navbar navbar-djm djm-portaal-nav">
        <div class="container-xl flex-nowrap gap-3">
            <a class="navbar-brand d-flex align-items-center gap-2 me-0"
               href="<?= h(url($email !== '' ? 'portaal/index.php' : 'index.php')) ?>">
                <?php if ($logo !== ''): ?>
                    <img src="<?= h($logo) ?>" alt="<?= h($naam) ?>" height="34">
                <?php else: ?>
                    <i class="bi bi-collection-play"></i><span class="fw-semibold"><?= h($naam) ?></span>
                <?php endif; ?>
            </a>
            <?php if ($email !== ''): ?>
                <div class="d-flex align-items-center gap-3 ms-auto">
                    <span class="djm-portaal-gebruiker small d-none d-md-inline text-truncate">
                        <i class="bi bi-person-circle me-1"></i><?= h($email) ?>
                    </span>
                    <!-- Uitloggen wijzigt de sessie en gaat daarom via POST met een CSRF-token;
                         zie de toelichting boven in logout.php. -->
                    <form method="post" action="<?= h(url('logout.php')) ?>" class="m-0">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-djm-omlijnd text-nowrap">
                            <i class="bi bi-box-arrow-right me-1"></i>Uitloggen
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </nav>

    <header class="djm-paginakop<?= $smal ? ' djm-paginakop-smal' : '' ?>">
        <div class="container-xl">
            <h1 class="h2 fw-bold mb-2"><?= h($titel) ?></h1>
            <?php if ($intro !== ''): ?>
                <p class="djm-paginakop-intro mb-0"><?= h($intro) ?></p>
            <?php endif; ?>
        </div>
    </header>

    <main class="container-xl pb-5<?= $smal ? ' djm-smal' : '' ?>">
        <?php if ($smal): ?>
            <div class="kaart p-4 p-md-5">
        <?php endif; ?>
        <?php toon_flash(); ?>
    <?php
}

function portaal_eind(): void
{
    ?>
        <?php if (!empty($GLOBALS['djm_portaal_smal'])): ?>
            </div>
        <?php endif; ?>
    </main>
    <footer>
        <?= h(portaal_naam()) ?> · <?= date('Y') ?>
    </footer>
</body>

</html>
    <?php
}

/** Eenmalige meldingen uit de sessie, als alert-blokken. */
function toon_flash(): void
{
    foreach (function_exists('flash_ophalen') ? flash_ophalen() : [] as $melding) {
        echo '<div class="alert alert-' . h($melding['type']) . ' py-2 small">'
            . h($melding['bericht']) . '</div>';
    }
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
