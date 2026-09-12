<?php

/**
 * Gedeelde opmaak voor het beheerdersgedeelte.
 *
 * Gebruik in elke adminpagina:
 *   require_once __DIR__ . '/includes/layout.php';
 *   $beheerder = vereis_beheerder();
 *   admin_start('Jaargangen');
 *   ... inhoud ...
 *   admin_eind();
 */

if (!function_exists('db')) {
    require_once dirname(dirname(__DIR__)) . '/config.php';
}
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/opmaak.php';

/** Navigatie-items: bestand => [label, icoon] */
function admin_menu(): array
{
    return [
        'index.php'       => ['Overzicht',   'bi-speedometer2'],
        'jaargangen.php'  => ['Jaargangen',  'bi-calendar3'],
        'bestanden.php'   => ['Bestanden',   'bi-film'],
        'bestandscontrole.php' => ['Controle', 'bi-hdd-stack'],
        'toegang.php'     => ['Toegang',     'bi-person-check'],
        'deelnemers.php'  => ['Deelnemers',  'bi-people'],
        'logboek.php'     => ['Logboek',     'bi-journal-text'],
        'instellingen.php' => ['Instellingen', 'bi-gear'],
    ];
}

/**
 * Bouwt het attribuut waarmee admin.js om een bevestiging vraagt.
 *
 * De Content-Security-Policy staat geen onsubmit="…" toe, dus zet de tekst in
 * een data-attribuut. Regeleindes moeten daarin als &#10; staan, anders komen
 * ze niet in de melding terecht.
 */
function bevestig_attribuut(string $tekst): string
{
    return ' data-bevestig="' . str_replace("\n", '&#10;', h($tekst)) . '"';
}

function admin_start(string $titel, string $subtitel = ''): void
{
    stuur_security_headers();
    $huidig = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $naam   = portaal_naam();
    $kleur  = branding_kleur();
    $beheerderNaam = (string)($_SESSION['beheerder_naam'] ?? '');
    ?>
<!DOCTYPE html>
<html lang="nl">

<head>
    <?php djm_head($titel . ' — Beheer ' . $naam, $kleur); ?>
</head>

<body class="djm-beheer">
    <nav class="navbar navbar-expand-xl navbar-djm mb-4">
        <div class="container-xl">
            <a class="navbar-brand fw-semibold" href="<?= h(url('admin/index.php')) ?>">
                <i class="bi bi-collection-play me-1"></i><?= h($naam) ?>
            </a>
            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse"
                data-bs-target="#adminNav"><i class="bi bi-list fs-3"></i></button>
            <div class="collapse navbar-collapse" id="adminNav">
                <ul class="navbar-nav me-auto">
                    <?php foreach (admin_menu() as $bestand => [$label, $icoon]): ?>
                        <li class="nav-item">
                            <a class="nav-link <?= $huidig === $bestand ? 'active' : '' ?>"
                                href="<?= h(url('admin/' . $bestand)) ?>">
                                <i class="bi <?= h($icoon) ?> me-1"></i><?= h($label) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link" href="<?= h(url('index.php')) ?>" target="_blank">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Portaal
                        </a>
                    </li>
                    <li class="nav-item">
                        <!-- Uitloggen wijzigt de sessie en gaat daarom via POST met een
                             CSRF-token; een <img src="…/logout.php"> op een andere site
                             kan de beheerder dan niet ongevraagd uitloggen. -->
                        <form method="post" action="<?= h(url('admin/logout.php')) ?>" class="d-inline">
                            <?= csrf_field() ?>
                            <button type="submit" class="nav-link btn btn-link text-decoration-none"
                                title="Uitloggen">
                                <i class="bi bi-box-arrow-right me-1"></i><span
                                    class="djm-afkappen djm-beheerdersnaam"><?= h($beheerderNaam ?: 'Uitloggen') ?></span>
                            </button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-xl pb-5">
        <div class="mb-3">
            <h1 class="h4 mb-0"><?= h($titel) ?></h1>
            <?php if ($subtitel !== ''): ?>
                <div class="text-muted small"><?= h($subtitel) ?></div>
            <?php endif; ?>
        </div>

        <?php foreach (flash_ophalen() as $melding): ?>
            <div class="alert alert-<?= h($melding['type']) ?> alert-dismissible fade show">
                <?= h($melding['bericht']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endforeach; ?>
    <?php
}

function admin_eind(): void
{
    ?>
    </div>
    <script src="<?= h(djm_asset('assets/vendor/bootstrap.bundle.min.js')) ?>"></script>
    <script src="<?= h(djm_asset('admin/assets/admin.js')) ?>"></script>
</body>

</html>
    <?php
}

/** Los opmaakblok voor de inlogpagina van het beheer. */
function admin_login_start(string $titel): void
{
    stuur_security_headers();
    $kleur = branding_kleur();
    ?>
<!DOCTYPE html>
<html lang="nl">

<head>
    <?php djm_head($titel, $kleur); ?>
</head>

<body class="djm-inlogkaart">
    <div class="card kaart">
        <div class="kop">
            <div class="fs-5 fw-semibold"><i class="bi bi-shield-lock me-2"></i><?= h($titel) ?></div>
        </div>
        <div class="card-body p-4">
    <?php
}

function admin_login_eind(): void
{
    ?>
        </div>
    </div>
</body>

</html>
    <?php
}
