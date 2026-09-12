<?php

/**
 * Portaaloverzicht: alle jaargangen waar de ingelogde deelnemer recht op heeft,
 * met per bestand een verse, ondertekende downloadlink.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/toegang_helper.php';
require_once dirname(__DIR__) . '/includes/download_helper.php';
require_once dirname(__DIR__) . '/includes/layout.php';

vereis_installatie();

$deelnemer  = vereis_deelnemer();
$deelnemerId = (int)$deelnemer['id'];
$jaargangen = deelnemer_jaargangen($deelnemerId);

pagina_start('Uw video\'s');
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 pb-3 mb-4 border-bottom">
    <div class="small text-secondary text-break">
        <i class="bi bi-person-circle me-1"></i>
        Ingelogd als <span class="fw-semibold"><?= h((string)$deelnemer['email']) ?></span>
    </div>
    <!-- Uitloggen wijzigt de sessie en gaat daarom via POST met een CSRF-token;
         zie de toelichting boven in logout.php. -->
    <form method="post" action="<?= h(url('logout.php')) ?>" class="m-0">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-box-arrow-right me-1"></i>Uitloggen
        </button>
    </form>
</div>

<?php if (!$jaargangen): ?>

    <div class="text-center py-4">
        <i class="bi bi-camera-reels fs-1 text-secondary opacity-50"></i>
        <h1 class="h5 mt-3">Er staan op dit moment geen video's voor u klaar.</h1>
        <p class="text-secondary small mb-2">
            Klopt dat niet? Neem dan contact met ons op, dan kijken wij het na.
            Vermeld daarbij het e-mailadres waarmee u bent ingelogd.
        </p>
        <?php toon_contact(); ?>
    </div>

<?php else: ?>

    <p class="text-secondary small">
        Het gaat om grote bestanden van meerdere gigabytes. Downloaden gaat het prettigst
        op een snelle, vaste verbinding en met voldoende vrije ruimte op uw computer.
        Valt de download halverwege weg? Start hem dan gewoon opnieuw &mdash; hij wordt
        hervat op het punt waar hij was gebleven.
    </p>

    <?php foreach ($jaargangen as $jaargang): ?>
        <section class="mb-4 pb-1">
            <h2 class="h4 mb-1">
                <span class="fw-bold"><?= h((string)$jaargang['jaar']) ?></span>
                &middot; <?= h((string)$jaargang['titel']) ?>
            </h2>

            <?php if (trim((string)($jaargang['omschrijving'] ?? '')) !== ''): ?>
                <p class="text-secondary small mb-3">
                    <?= nl2br(h((string)$jaargang['omschrijving'])) ?>
                </p>
            <?php endif; ?>

            <?php if (!$jaargang['bestanden']): ?>
                <div class="alert alert-info py-2 small mb-0">
                    De video wordt binnenkort toegevoegd.
                </div>
            <?php else: ?>
                <div class="list-group">
                    <?php foreach ($jaargang['bestanden'] as $bestand): ?>
                        <div class="list-group-item d-flex flex-wrap align-items-center justify-content-between gap-3">
                            <div>
                                <div class="fw-semibold"><?= h((string)$bestand['titel']) ?></div>
                                <div class="small text-secondary">
                                    <?= h(formatteer_bytes((int)$bestand['bytes'])) ?>
                                </div>
                            </div>
                            <a class="btn btn-djm djm-actie"
                               href="<?= h(download_link((int)$bestand['id'], $deelnemerId)) ?>">
                                <i class="bi bi-download me-1"></i>Downloaden
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>

<?php endif; ?>

<?php
pagina_eind();
