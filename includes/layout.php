<?php

/**
 * Gedeelde opmaak voor het publieke portaal (inloggen, codeverificatie, downloads).
 *
 * Gebruik:
 *   pagina_start('Inloggen');            // of pagina_start('Inloggen', ['smal' => true])
 *   ... inhoud ...
 *   pagina_eind();
 */

if (!function_exists('db')) {
    require_once dirname(__DIR__) . '/config.php';
}

function branding_kleur(): string
{
    $kleur = instelling('branding_kleur', '#0d6efd');
    return preg_match('/^#[0-9A-Fa-f]{6}$/', $kleur) ? $kleur : '#0d6efd';
}

function branding_logo(): string
{
    $logo = trim(instelling('branding_logo_url', ''));
    if ($logo !== '') {
        return $logo;
    }
    return is_file(APP_ROOT . '/assets/logo.png') ? url('assets/logo.png') : '';
}

/**
 * @param array{smal?:bool, breed?:bool, terug?:string} $opties
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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= h($titel) ?> — <?= h($naam) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --djm: <?= h($kleur) ?>;
        }

        body {
            background: linear-gradient(160deg, #0f172a 0%, #1e293b 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }

        main {
            flex: 1 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 16px;
        }

        .djm-kaart {
            width: 100%;
            max-width: <?= $smal ? '440px' : '820px' ?>;
            background: #fff;
            border: 0;
            border-radius: 16px;
            box-shadow: 0 24px 60px rgba(0, 0, 0, .35);
            overflow: hidden;
        }

        .djm-kop {
            background: var(--djm);
            padding: 28px 24px;
            text-align: center;
            color: #fff;
        }

        .djm-kop img {
            max-height: 52px;
            max-width: 240px;
        }

        .btn-djm {
            background: var(--djm);
            border-color: var(--djm);
            color: #fff;
        }

        .btn-djm:hover,
        .btn-djm:focus {
            background: var(--djm);
            border-color: var(--djm);
            color: #fff;
            filter: brightness(.92);
        }

        .djm-code-invoer {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 2rem;
            letter-spacing: .6rem;
            text-align: center;
        }

        footer {
            flex-shrink: 0;
            padding: 18px;
            text-align: center;
            color: rgba(255, 255, 255, .55);
            font-size: .8rem;
        }
    </style>
</head>

<body>
    <main>
        <div class="djm-kaart card">
            <div class="djm-kop">
                <?php if ($logo !== ''): ?>
                    <img src="<?= h($logo) ?>" alt="<?= h($naam) ?>">
                <?php else: ?>
                    <div class="fs-4 fw-semibold"><?= h($naam) ?></div>
                <?php endif; ?>
                <div class="opacity-75 small mt-2"><?= h($titel) ?></div>
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

/** Toont een foutmelding als alert-blok. */
function toon_fout(string $bericht): void
{
    if ($bericht === '') {
        return;
    }
    echo '<div class="alert alert-danger py-2 small d-flex align-items-start gap-2">'
        . '<i class="bi bi-exclamation-triangle-fill"></i><div>' . h($bericht) . '</div></div>';
}

function toon_melding(string $bericht, string $type = 'success'): void
{
    if ($bericht === '') {
        return;
    }
    echo '<div class="alert alert-' . h($type) . ' py-2 small">' . h($bericht) . '</div>';
}
