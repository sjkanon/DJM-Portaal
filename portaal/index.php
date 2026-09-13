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
$email      = (string)$deelnemer['email'];

portaal_start('Uw video\'s', [
    'email' => $email,
    'intro' => $jaargangen
        ? 'Het gaat om grote bestanden van meerdere gigabytes. Download ze bij voorkeur op een snelle, '
          . 'vaste verbinding. Valt een download halverwege weg? Start hem dan opnieuw — hij wordt '
          . 'hervat op het punt waar hij was gebleven.'
        : '',
]);
?>

<?php if (!$jaargangen): ?>

    <section class="kaart p-4 p-md-5 text-center">
        <i class="bi bi-camera-reels fs-1 text-secondary opacity-50"></i>
        <h2 class="h5 mt-3">Er staan op dit moment geen video's voor u klaar.</h2>
        <p class="text-secondary small mb-2">
            Klopt dat niet? Neem dan contact met ons op, dan kijken wij het na.
            Vermeld daarbij het e-mailadres waarmee u bent ingelogd
            (<span class="fw-semibold text-break"><?= h($email) ?></span>).
        </p>
        <?php toon_contact(); ?>
    </section>

<?php else: ?>

    <!-- Eén kaart per jaargang, nieuwste eerst. Het raster zet er zoveel naast
         elkaar als er passen; een enkele jaargang krijgt de volle breedte. -->
    <div class="djm-jaargangen">
        <?php foreach ($jaargangen as $jaargang):
            $aantal = count($jaargang['bestanden']);
            $totaal = array_sum(array_map(static fn(array $b): int => (int)$b['bytes'], $jaargang['bestanden']));
            ?>
            <section class="kaart djm-jaargang">
                <div class="djm-jaargang-kop">
                    <span class="djm-jaargang-jaar"><?= h((string)$jaargang['jaar']) ?></span>
                    <div class="min-w-0">
                        <h2 class="h5 fw-semibold mb-0 text-break"><?= h((string)$jaargang['titel']) ?></h2>
                        <?php if ($aantal > 0): ?>
                            <div class="small text-secondary">
                                <?= $aantal === 1 ? '1 video' : $aantal . ' video\'s' ?>
                                &middot; <?= h(formatteer_bytes($totaal)) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (trim((string)($jaargang['omschrijving'] ?? '')) !== ''): ?>
                    <p class="text-secondary small mb-0">
                        <?= nl2br(h((string)$jaargang['omschrijving'])) ?>
                    </p>
                <?php endif; ?>

                <?php if (!$jaargang['bestanden']): ?>
                    <div class="alert alert-info py-2 small mb-0">
                        De video wordt binnenkort toegevoegd.
                    </div>
                <?php else: ?>
                    <div class="djm-bestanden">
                        <?php foreach ($jaargang['bestanden'] as $bestand): ?>
                            <div class="djm-bestand">
                                <div class="djm-bestand-icoon"><i class="bi bi-film"></i></div>
                                <div class="djm-bestand-naam min-w-0">
                                    <div class="fw-semibold text-break"><?= h((string)$bestand['titel']) ?></div>
                                    <div class="small text-secondary">
                                        <?= h(formatteer_bytes((int)$bestand['bytes'])) ?>
                                    </div>
                                </div>
                                <a class="btn btn-djm text-nowrap"
                                   href="<?= h(download_link((int)$bestand['id'], $deelnemerId)) ?>">
                                    <i class="bi bi-download me-1"></i>Downloaden
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </div>

<?php endif; ?>

<?php
portaal_eind();
