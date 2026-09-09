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

/** Navigatie-items: bestand => [label, icoon] */
function admin_menu(): array
{
    return [
        'index.php'       => ['Overzicht',   'bi-speedometer2'],
        'jaargangen.php'  => ['Jaargangen',  'bi-calendar3'],
        'bestanden.php'   => ['Bestanden',   'bi-film'],
        'toegang.php'     => ['Toegang',     'bi-person-check'],
        'deelnemers.php'  => ['Deelnemers',  'bi-people'],
        'logboek.php'     => ['Logboek',     'bi-journal-text'],
        'instellingen.php' => ['Instellingen', 'bi-gear'],
    ];
}

function admin_start(string $titel, string $subtitel = ''): void
{
    stuur_security_headers();
    $huidig = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $naam   = portaal_naam();
    $kleur  = preg_match('/^#[0-9A-Fa-f]{6}$/', instelling('branding_kleur', '#0d6efd'))
        ? instelling('branding_kleur', '#0d6efd') : '#0d6efd';
    $beheerderNaam = (string)($_SESSION['beheerder_naam'] ?? '');
    ?>
<!DOCTYPE html>
<html lang="nl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= h($titel) ?> — Beheer <?= h($naam) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --djm: <?= h($kleur) ?>;
        }

        body {
            background: #f4f6f9;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }

        .navbar-djm {
            background: var(--djm);
        }

        .navbar-djm .nav-link,
        .navbar-djm .navbar-brand {
            color: rgba(255, 255, 255, .85);
        }

        .navbar-djm .nav-link:hover,
        .navbar-djm .nav-link.active {
            color: #fff;
        }

        .navbar-djm .nav-link.active {
            font-weight: 600;
            border-bottom: 2px solid #fff;
        }

        .btn-djm {
            background: var(--djm);
            border-color: var(--djm);
            color: #fff;
        }

        .btn-djm:hover {
            filter: brightness(.92);
            color: #fff;
        }

        .kaart {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
        }

        .tabel-compact td,
        .tabel-compact th {
            vertical-align: middle;
        }

        code.pad {
            font-size: .8rem;
            color: #6b7280;
            word-break: break-all;
        }
    </style>
</head>

<body>
    <nav class="navbar navbar-expand-lg navbar-djm mb-4">
        <div class="container-xl">
            <a class="navbar-brand fw-semibold" href="<?= h(url('admin/index.php')) ?>">
                <i class="bi bi-collection-play me-1"></i><?= h($naam) ?>
            </a>
            <button class="navbar-toggler border-0 text-white" type="button" data-bs-toggle="collapse"
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
                        <a class="nav-link" href="<?= h(url('admin/logout.php')) ?>">
                            <i class="bi bi-box-arrow-right me-1"></i><?= h($beheerderNaam ?: 'Uitloggen') ?>
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-xl pb-5">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <div>
                <h1 class="h4 mb-0"><?= h($titel) ?></h1>
                <?php if ($subtitel !== ''): ?>
                    <div class="text-muted small"><?= h($subtitel) ?></div>
                <?php endif; ?>
            </div>
            <div id="paginaActies"></div>
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
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
    <?php
}

/** Los opmaakblok voor de inlogpagina van het beheer. */
function admin_login_start(string $titel): void
{
    stuur_security_headers();
    $kleur = preg_match('/^#[0-9A-Fa-f]{6}$/', instelling('branding_kleur', '#0d6efd'))
        ? instelling('branding_kleur', '#0d6efd') : '#0d6efd';
    ?>
<!DOCTYPE html>
<html lang="nl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= h($titel) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            background: #0f172a;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }

        .kaart {
            width: 100%;
            max-width: 420px;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 20px 50px rgba(0, 0, 0, .4);
        }

        .kop {
            background: <?= h($kleur) ?>;
            color: #fff;
            padding: 24px;
            text-align: center;
        }

        .btn-djm {
            background: <?= h($kleur) ?>;
            border-color: <?= h($kleur) ?>;
            color: #fff;
        }
    </style>
</head>

<body>
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
