<?php

/**
 * Beheer — Overzicht.
 *
 * Kerncijfers, waarschuwingen over de configuratie, de laatste activiteit en
 * een korte routebeschrijving voor het toevoegen van een nieuw jaar.
 */

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/toegang_helper.php';
vereis_installatie();
$beheerder = vereis_beheerder();

// ─── Defensieve queryhelpers ─────────────────────────────────────────────────
// Een verse installatie kan lege of ontbrekende tabellen hebben; het overzicht
// moet er dan nog steeds netjes uitzien.

function overzicht_getal(string $sql, array $params = []): int
{
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        app_log('overzicht-query mislukt', ['fout' => $e->getMessage()]);
        return 0;
    }
}

function overzicht_rijen(string $sql, array $params = []): array
{
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        app_log('overzicht-query mislukt', ['fout' => $e->getMessage()]);
        return [];
    }
}

// ─── Kerncijfers ─────────────────────────────────────────────────────────────
$aantalJaargangen = overzicht_getal('SELECT COUNT(*) FROM jaargangen');
$aantalZichtbaar  = overzicht_getal('SELECT COUNT(*) FROM jaargangen j WHERE ' . sql_jaargang_zichtbaar('j'));
$aantalDeelnemers = overzicht_getal('SELECT COUNT(*) FROM deelnemers');
$aantalGeblokkeerd = overzicht_getal('SELECT COUNT(*) FROM deelnemers WHERE geblokkeerd = 1');

$downloads30 = overzicht_getal(
    'SELECT COUNT(*) FROM download_log WHERE gestart_op >= (NOW() - INTERVAL 30 DAY)'
);
$codes7 = overzicht_getal(
    "SELECT COUNT(*) FROM mail_log
     WHERE soort = 'inlogcode' AND status = 'verzonden'
       AND verzonden_op >= (NOW() - INTERVAL 7 DAY)"
);

// ─── Waarschuwing: is de e-mail ingesteld? ───────────────────────────────────
$mailMethode   = instelling('email_methode', 'graph');
$mailOntbreekt = [];
if (trim(instelling('email_van_adres')) === '') {
    $mailOntbreekt[] = 'afzenderadres';
}
if ($mailMethode === 'graph') {
    foreach ([
        'graph_tenant_id'     => 'tenant-id',
        'graph_client_id'     => 'client-id',
        'graph_client_secret' => 'client secret',
    ] as $sleutel => $label) {
        if (trim(instelling($sleutel)) === '') {
            $mailOntbreekt[] = $label;
        }
    }
}

// ─── Waarschuwing: draaien we op afgeleide sleutels? ─────────────────────────
$sleutelsOntbreken = [];
if (env('APP_KEY') === '') {
    $sleutelsOntbreken[] = 'APP_KEY';
}
if (env('OTP_PEPPER') === '') {
    $sleutelsOntbreken[] = 'OTP_PEPPER';
}

// ─── Laatste activiteit ──────────────────────────────────────────────────────
$laatsteDownloads = overzicht_rijen(
    'SELECT gestart_op, email, bestandsnaam, afgerond
     FROM download_log
     ORDER BY id DESC
     LIMIT 10'
);

$laatsteLogins = overzicht_rijen(
    'SELECT tijdstip, email, soort, gelukt, ip
     FROM login_log
     ORDER BY id DESC
     LIMIT 10'
);

$kaarten = [
    [
        'label'   => 'Jaargangen',
        'waarde'  => (string)$aantalJaargangen,
        'toelichting' => $aantalZichtbaar . ' nu zichtbaar',
        'icoon'   => 'bi-calendar3',
        'link'    => url('admin/jaargangen.php'),
    ],
    [
        'label'   => 'Deelnemers',
        'waarde'  => (string)$aantalDeelnemers,
        'toelichting' => $aantalGeblokkeerd > 0 ? $aantalGeblokkeerd . ' geblokkeerd' : 'met een e-mailadres op de lijst',
        'icoon'   => 'bi-people',
        'link'    => url('admin/deelnemers.php'),
    ],
    [
        'label'   => 'Downloads',
        'waarde'  => (string)$downloads30,
        'toelichting' => 'in de laatste 30 dagen',
        'icoon'   => 'bi-download',
        'link'    => url('admin/logboek.php'),
    ],
    [
        'label'   => 'Inlogcodes',
        'waarde'  => (string)$codes7,
        'toelichting' => 'verstuurd in de laatste 7 dagen',
        'icoon'   => 'bi-envelope-check',
        'link'    => url('admin/logboek.php'),
    ],
];

admin_start('Overzicht', 'Welkom terug, ' . (string)$beheerder['naam'] . '.');
?>

<!-- ─── Kerncijfers ─────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <?php foreach ($kaarten as $kaart): ?>
        <div class="col-6 col-lg-3">
            <a class="text-decoration-none text-reset" href="<?= h($kaart['link']) ?>">
                <div class="kaart p-3 h-100">
                    <div class="d-flex align-items-center justify-content-between">
                        <span class="text-muted small text-uppercase"><?= h($kaart['label']) ?></span>
                        <i class="bi <?= h($kaart['icoon']) ?> text-muted"></i>
                    </div>
                    <div class="fs-2 fw-semibold lh-1 mt-2"><?= h($kaart['waarde']) ?></div>
                    <div class="text-muted small mt-1"><?= h($kaart['toelichting']) ?></div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<!-- ─── Waarschuwingen ──────────────────────────────────────────────────── -->
<?php if ($mailOntbreekt): ?>
    <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2">
        <div class="flex-grow-1">
            <strong><i class="bi bi-envelope-exclamation me-1"></i>E-mail is nog niet ingesteld.</strong>
            Zonder werkende e-mail kunnen deelnemers geen inlogcode ontvangen.
            Ontbreekt: <?= h(implode(', ', $mailOntbreekt)) ?>
            (methode: <?= h($mailMethode) ?>).
        </div>
        <a class="btn btn-sm btn-warning" href="<?= h(url('admin/instellingen.php')) ?>">
            <i class="bi bi-gear me-1"></i>Naar instellingen
        </a>
    </div>
<?php endif; ?>

<?php if ($sleutelsOntbreken): ?>
    <div class="alert alert-warning">
        <strong><i class="bi bi-key me-1"></i>Geen eigen sleutels ingesteld.</strong>
        <?= h(implode(' en ', $sleutelsOntbreken)) ?>
        <?= count($sleutelsOntbreken) === 1 ? 'ontbreekt' : 'ontbreken' ?> in <code>.env</code>.
        De installatie draait nu op sleutels die zijn afgeleid van de databasegegevens.
        Dat werkt, maar is minder veilig: zodra de databasegegevens wijzigen, vervallen alle
        openstaande inlogcodes en onthoud-cookies.
        <div class="small mt-2">
            Tip: zet in <code>.env</code> bijvoorbeeld
            <code>APP_KEY=<?= h(bin2hex(random_bytes(16))) ?></code>
            en <code>OTP_PEPPER=<?= h(bin2hex(random_bytes(16))) ?></code>
            (elke installatie een eigen, willekeurige waarde).
        </div>
    </div>
<?php endif; ?>

<!-- ─── Snel een nieuw jaar toevoegen ───────────────────────────────────── -->
<div class="kaart p-3 p-lg-4 mb-4">
    <h2 class="h6 text-uppercase text-muted mb-3">
        <i class="bi bi-stars me-1"></i>Snel een nieuw jaar toevoegen
    </h2>
    <div class="row g-3">
        <div class="col-md-6 col-xl-3">
            <div class="d-flex gap-2">
                <span class="badge rounded-pill text-bg-secondary align-self-start">1</span>
                <div>
                    <div class="fw-semibold">Video klaarzetten</div>
                    <p class="text-muted small mb-2">
                        Zet het videobestand via SFTP in de opslagmap. Dat is de aanbevolen route
                        voor grote bestanden; ze hoeven dan niet door de browser.
                    </p>
                    <code class="pad"><?= h(opslag_pad()) ?></code>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="d-flex gap-2">
                <span class="badge rounded-pill text-bg-secondary align-self-start">2</span>
                <div>
                    <div class="fw-semibold">Jaargang aanmaken</div>
                    <p class="text-muted small mb-2">Jaar, titel en de zichtbaarheidsperiode vastleggen.</p>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= h(url('admin/jaargangen.php')) ?>">
                        <i class="bi bi-calendar3 me-1"></i>Jaargangen
                    </a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="d-flex gap-2">
                <span class="badge rounded-pill text-bg-secondary align-self-start">3</span>
                <div>
                    <div class="fw-semibold">Bestand koppelen</div>
                    <p class="text-muted small mb-2">Kies het bestand uit de opslagmap en geef het een downloadnaam.</p>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= h(url('admin/bestanden.php')) ?>">
                        <i class="bi bi-film me-1"></i>Bestanden
                    </a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="d-flex gap-2">
                <span class="badge rounded-pill text-bg-secondary align-self-start">4</span>
                <div>
                    <div class="fw-semibold">E-mailadressen importeren</div>
                    <p class="text-muted small mb-2">Plak de lijst van dat jaar; alleen die adressen krijgen toegang.</p>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= h(url('admin/toegang.php')) ?>">
                        <i class="bi bi-person-check me-1"></i>Toegang
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ─── Laatste activiteit ──────────────────────────────────────────────── -->
<div class="row g-3">
    <div class="col-xl-6">
        <div class="kaart h-100">
            <div class="p-3 border-bottom d-flex align-items-center justify-content-between">
                <h2 class="h6 mb-0"><i class="bi bi-download me-1"></i>Laatste downloads</h2>
                <a class="small text-decoration-none" href="<?= h(url('admin/logboek.php')) ?>">Logboek</a>
            </div>
            <?php if (!$laatsteDownloads): ?>
                <div class="p-4 text-center text-muted small">
                    Er is nog niets gedownload.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm tabel-compact mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Datum</th>
                                <th scope="col">E-mailadres</th>
                                <th scope="col">Bestand</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($laatsteDownloads as $rij): ?>
                                <tr>
                                    <td class="text-nowrap small"><?= h(formatteer_datum($rij['gestart_op'])) ?></td>
                                    <td class="small"><?= h((string)($rij['email'] ?? '') ?: '—') ?></td>
                                    <td class="small">
                                        <?= h((string)($rij['bestandsnaam'] ?? '') ?: '—') ?>
                                        <?php if ((int)($rij['afgerond'] ?? 0) !== 1): ?>
                                            <span class="badge text-bg-light border ms-1" title="Niet afgerond">afgebroken</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-xl-6">
        <div class="kaart h-100">
            <div class="p-3 border-bottom d-flex align-items-center justify-content-between">
                <h2 class="h6 mb-0"><i class="bi bi-shield-check me-1"></i>Laatste inlogpogingen</h2>
                <a class="small text-decoration-none" href="<?= h(url('admin/logboek.php')) ?>">Logboek</a>
            </div>
            <?php if (!$laatsteLogins): ?>
                <div class="p-4 text-center text-muted small">
                    Nog geen inlogpogingen geregistreerd.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm tabel-compact mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Datum</th>
                                <th scope="col">E-mailadres</th>
                                <th scope="col">Soort</th>
                                <th scope="col">IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($laatsteLogins as $rij): ?>
                                <tr>
                                    <td class="text-nowrap small"><?= h(formatteer_datum($rij['tijdstip'])) ?></td>
                                    <td class="small"><?= h((string)($rij['email'] ?? '') ?: '—') ?></td>
                                    <td class="small">
                                        <span class="badge text-bg-<?= (int)$rij['gelukt'] === 1 ? 'success' : 'secondary' ?>">
                                            <?= h((string)$rij['soort']) ?>
                                        </span>
                                    </td>
                                    <td class="small text-muted"><?= h(ip_leesbaar($rij['ip'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
admin_eind();
