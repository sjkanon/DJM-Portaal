<?php

/**
 * Beheer — Handleiding.
 *
 * Hoe het portaal werkt en wat een beheerder in elk scherm doet, met
 * schermafdrukken. Staat in het beheer zelf, zodat wie het portaal ook beheert
 * de uitleg heeft die bij déze versie hoort.
 *
 * Bijhouden:
 *   - Elk scherm uit admin_menu() heeft hier een blok met data-scherm="…".
 *     test/audit.sh faalt als dat ontbreekt; de knop "Uitleg" op elk scherm
 *     springt naar #scherm-<naam>.
 *   - De schermafdrukken in assets/handleiding/ maakt test/handleiding.sh
 *     opnieuw. Draai dat na elke zichtbare wijziging in portaal of beheer.
 *   - docs/BEHEER.md is de tekstversie voor wie (nog) niet kan inloggen.
 */

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/includes/layout.php';
vereis_installatie();
$beheerder = vereis_beheerder();

/**
 * Een schermafdruk uit assets/handleiding/ in een browserkader.
 *
 * Opties: adres (pad in de balk), bijschrift, hoog (lange pagina afkappen),
 * smal (telefoonformaat). Ontbreekt het bestand, dan wordt er niets getoond.
 */
function handleiding_figuur(string $naam, string $alt, array $opties = []): void
{
    $pad = 'assets/handleiding/' . $naam . '.webp';
    if (!is_file(APP_ROOT . '/' . $pad)) {
        return;
    }
    $bron    = djm_asset($pad);
    $klassen = trim('djm-hl-figuur' . (!empty($opties['hoog']) ? ' hoog' : '') . (!empty($opties['smal']) ? ' smal' : ''));
    // Breedte en hoogte vooraf, zodat de browser de ruimte al reserveert. Zonder
    // dat hebben de lazy geladen afbeeldingen nog geen hoogte als de knop
    // "Uitleg" naar #scherm-… springt, en schuift de pagina daarna onder de
    // lezer vandaan.
    $maten   = @getimagesize(APP_ROOT . '/' . $pad) ?: [0, 0];
    ?>
    <figure class="<?= h($klassen) ?>">
        <a href="<?= h($bron) ?>" target="_blank" rel="noopener" title="Open de schermafdruk op ware grootte">
            <?php if (!empty($opties['adres'])): ?>
                <span class="djm-hl-balk"><?= h((string)$opties['adres']) ?></span>
            <?php endif; ?>
            <img src="<?= h($bron) ?>" alt="<?= h($alt) ?>" loading="lazy"
                <?php if ($maten[0] > 0): ?>width="<?= (int)$maten[0] ?>" height="<?= (int)$maten[1] ?>"<?php endif; ?>>
        </a>
        <?php if (!empty($opties['bijschrift'])): ?>
            <figcaption><?= h((string)$opties['bijschrift']) ?></figcaption>
        <?php endif; ?>
    </figure>
    <?php
}

/** Kop van een hoofdstuk, met voor wie het bedoeld is. */
function handleiding_kop(int $nummer, string $titel, array $rollen): void
{
    ?>
    <div class="djm-hl-kop">
        <span class="djm-hl-nr">Hoofdstuk <?= (int)$nummer ?></span>
        <h2><?= h($titel) ?></h2>
        <?php foreach ($rollen as $rol): ?>
            <span class="djm-hl-rol <?= $rol === 'technisch' ? 'djm-hl-rol-t' : 'djm-hl-rol-v' ?>">
                <?= $rol === 'technisch' ? 'Technisch' : 'Vereniging' ?>
            </span>
        <?php endforeach; ?>
    </div>
    <?php
}

/** Kop van een beheerscherm, met een knop die het scherm opent. */
function handleiding_scherm_kop(string $bestand, string $titel): void
{
    ?>
    <div class="djm-hl-scherm-kop">
        <h3><?= h($titel) ?></h3>
        <code class="pad">/admin/<?= h($bestand) ?></code>
        <a class="btn btn-sm btn-outline-secondary ms-auto" href="<?= h(url('admin/' . $bestand)) ?>">
            Openen <i class="bi bi-arrow-right-short"></i>
        </a>
    </div>
    <?php
}

// Van welke versie zijn de schermafdrukken? test/handleiding.sh schrijft dat weg.
$schermVersie = '';
$schermDatum  = '';
$versiePad    = APP_ROOT . '/assets/handleiding/versie.txt';
if (is_file($versiePad)) {
    [$schermVersie, $schermDatum] = array_pad(array_map('trim', file($versiePad) ?: []), 2, '');
}

$hoofdstukken = [
    'werking'     => 'Hoe het portaal werkt',
    'overdracht'  => 'Overdracht',
    'schermen'    => 'Het beheer, scherm voor scherm',
    'nieuw-jaar'  => 'Een nieuw jaar online zetten',
    'beheerders'  => 'Beheerders',
    'taken'       => 'Terugkerende taken',
    'vragen'      => 'Vragen van ouders',
    'technisch'   => 'Technisch beheer',
    'documenten'  => 'Documentatie',
];

admin_start('Handleiding', 'Hoe het portaal werkt en wat u in elk scherm doet — voor iedereen die het beheer doet of overneemt.');
?>

<div class="row g-4">
    <div class="col-lg-3 d-none d-lg-block">
        <nav class="djm-hl-inhoud" aria-label="Inhoud van de handleiding">
            <ol>
                <?php foreach ($hoofdstukken as $anker => $titel): ?>
                    <li>
                        <a href="#<?= h($anker) ?>"><?= h($titel) ?></a>
                        <?php if ($anker === 'schermen'): ?>
                            <ul>
                                <?php foreach (admin_menu() as $bestand => [$label, $icoon]): ?>
                                    <li><a href="#scherm-<?= h(basename($bestand, '.php')) ?>"><?= h($label) ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        </nav>
    </div>

    <div class="col-lg-9 min-w-0">
        <nav class="djm-sectienav d-lg-none" aria-label="Inhoud van de handleiding">
            <?php foreach ($hoofdstukken as $anker => $titel): ?>
                <a href="#<?= h($anker) ?>"><?= h($titel) ?></a>
            <?php endforeach; ?>
        </nav>

        <div class="kaart p-4 mb-4">
            <h2 class="h6 text-uppercase text-muted mb-3">Ik wil…</h2>
            <div class="djm-hl-snel">
                <a href="#nieuw-jaar">De video van dit jaar online zetten<small>Hoofdstuk 4</small></a>
                <a href="#vraag-geen-code">Een ouder helpen die geen code krijgt<small>Hoofdstuk 7</small></a>
                <a href="#scherm-toegang">Zien wie de video nog niet heeft<small>Toegang → ophaalstatus</small></a>
                <a href="#beheerders">Iemand beheerder maken<small>Hoofdstuk 5</small></a>
                <a href="#storing">Uitzoeken waarom niemand kan inloggen<small>Hoofdstuk 8</small></a>
                <a href="#overdracht">Het beheer overdragen<small>Hoofdstuk 2</small></a>
            </div>
            <?php if ($schermVersie !== ''): ?>
                <p class="text-muted small mt-3 mb-0">
                    <i class="bi bi-camera me-1"></i>De schermafdrukken zijn gemaakt met versie <?= h($schermVersie) ?>
                    <?php if ($schermDatum !== ''): ?>op <?= h(date('d-m-Y', strtotime($schermDatum) ?: time())) ?><?php endif; ?>,
                    met verzonnen voorbeeldgegevens.
                    <?php if ($schermVersie !== APP_VERSION): ?>
                        Dit portaal draait versie <?= h(APP_VERSION) ?>; een scherm kan er dus iets anders uitzien.
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>

        <!-- ═══ 1 ═══════════════════════════════════════════════════════ -->
        <section class="kaart p-4 mb-4 djm-sectie" id="werking">
            <?php handleiding_kop(1, 'Hoe het portaal werkt', ['vereniging', 'technisch']); ?>
            <div class="djm-hl-tekst">
                <p>Ouders en deelnemers hebben <strong>geen account en geen wachtwoord</strong>. Hun e-mailadres is hun
                    identiteit: staat het op de lijst van een jaargang, dan kunnen ze inloggen met een code die ze per
                    mail krijgen. Zo ziet dat er voor hen uit:</p>
            </div>
            <div class="row row-cols-2 row-cols-md-4 g-3 djm-hl-stroom my-2">
                <div class="col">
                    <?php handleiding_figuur('portaal-start', 'Startpagina van het portaal met een ingevuld e-mailadres'); ?>
                    <div class="fw-semibold"><span class="djm-hl-stap">1</span>E-mailadres invullen</div>
                    <div class="small text-muted">De melding is altijd hetzelfde, bekend adres of niet.</div>
                </div>
                <div class="col">
                    <?php handleiding_figuur('mail-inlogcode', 'E-mail met een inlogcode van zes cijfers'); ?>
                    <div class="fw-semibold"><span class="djm-hl-stap">2</span>Code per mail</div>
                    <div class="small text-muted">Zes cijfers, standaard tien minuten geldig.</div>
                </div>
                <div class="col">
                    <?php handleiding_figuur('portaal-code', 'Pagina om de inlogcode in te voeren'); ?>
                    <div class="fw-semibold"><span class="djm-hl-stap">3</span>Code invoeren</div>
                    <div class="small text-muted">In dezelfde browser als waarin de code is aangevraagd.</div>
                </div>
                <div class="col">
                    <?php handleiding_figuur('portaal-overzicht', 'Overzicht van de eigen jaargangen met downloadknoppen'); ?>
                    <div class="fw-semibold"><span class="djm-hl-stap">4</span>Downloaden</div>
                    <div class="small text-muted">Alleen de jaren waarvoor dit adres is toegevoegd.</div>
                </div>
            </div>
            <div class="djm-hl-tekst">
                <p>De video's staan in een <strong>afgeschermde opslagmap</strong> op de server en zijn nooit met een
                    directe link te openen: elke download loopt via het portaal, dat eerst controleert of de bezoeker is
                    ingelogd en toegang heeft. Beheerders loggen apart in op <code>/admin/</code>, met e-mailadres en
                    wachtwoord.</p>
                <p>De downloadknop levert een <strong>ondertekende link van twaalf uur</strong> op, die alleen werkt voor
                    dit ene bestand en deze ene deelnemer — doorsturen naar iemand anders heeft dus geen zin. Is hij
                    verlopen, dan geeft het portaal er vanzelf een nieuwe voor in de plaats en gaat de download gewoon
                    door. Een onderbroken download is te hervatten, ook een dag later en ook met een downloadmanager.</p>
            </div>

            <h3 class="h6 mt-4">De onderdelen</h3>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>Onderdeel</th><th>Wat</th><th>Wie</th></tr>
                    </thead>
                    <tbody>
                        <tr><td class="fw-semibold">Webserver + PHP</td><td>De applicatie zelf</td><td><span class="djm-hl-rol djm-hl-rol-t">Technisch</span></td></tr>
                        <tr><td class="fw-semibold">Database</td><td>Jaargangen, deelnemers, toegang, logboeken, instellingen</td><td><span class="djm-hl-rol djm-hl-rol-t">Technisch</span></td></tr>
                        <tr><td class="fw-semibold">Opslagmap</td><td>De videobestanden, per jaar een map. Op deze server: <code><?= h(opslag_pad()) ?></code></td><td><span class="djm-hl-rol djm-hl-rol-v">Vereniging</span></td></tr>
                        <tr><td class="fw-semibold"><code>.env</code></td><td>Databasewachtwoord en de geheime sleutels <code>APP_KEY</code> en <code>OTP_PEPPER</code></td><td><span class="djm-hl-rol djm-hl-rol-t">Technisch</span></td></tr>
                        <tr><td class="fw-semibold">App-registratie</td><td>In Microsoft Entra: mag mail versturen namens de afzenderpostbus</td><td><span class="djm-hl-rol djm-hl-rol-t">Technisch</span></td></tr>
                        <tr><td class="fw-semibold">Afzenderpostbus</td><td>Het Microsoft 365-adres waar de inlogcodes vandaan komen</td><td><span class="djm-hl-rol djm-hl-rol-t">Technisch</span></td></tr>
                        <tr><td class="fw-semibold">Nachtelijke taak</td><td><code>cron_opschonen.php</code> ruimt verlopen codes en oude logregels op</td><td><span class="djm-hl-rol djm-hl-rol-t">Technisch</span></td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ═══ 2 ═══════════════════════════════════════════════════════ -->
        <section class="kaart p-4 mb-4 djm-sectie" id="overdracht">
            <?php handleiding_kop(2, 'Overdracht: wat moet de nieuwe beheerder hebben?', ['vereniging', 'technisch']); ?>
            <p class="djm-hl-tekst">Loop deze lijst na bij elke overdracht. Deel wachtwoorden via een wachtwoordmanager,
                niet per mail of WhatsApp.</p>
            <div class="row g-4">
                <div class="col-md-6">
                    <h3 class="h6">Beheerder van de vereniging</h3>
                    <ul class="mb-0">
                        <li>Een beheeraccount op eigen naam: een andere beheerder voegt u toe via Beheer › Beheerders
                            (<a href="#beheerders">hoofdstuk 5</a>). Deel geen accounts.</li>
                        <li>Het adres van het portaal en van <code>/admin/</code>.</li>
                        <li>Een contactadres bij <a href="<?= h(url('admin/instellingen.php')) ?>#portaal">Instellingen → Portaal</a>
                            dat uitkomt bij iemand die nog actief is.</li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <h3 class="h6">Technisch beheerder</h3>
                    <ul class="mb-0">
                        <li>Toegang tot de server of het hostingpaneel.</li>
                        <li>Databasenaam, gebruiker en wachtwoord.</li>
                        <li>Een kopie van <code>.env</code> in de wachtwoordmanager.</li>
                        <li>Toegang tot Microsoft Entra en de <strong>vervaldatum van het client secret</strong>.</li>
                        <li>Waar de back-ups staan en hoe u ze terugzet.</li>
                    </ul>
                </div>
            </div>
            <div class="alert alert-warning mt-4 mb-0 djm-hl-tekst">
                <strong>Vertrekt er iemand?</strong> Schakel diens beheeraccount uit, trek zo nodig diens toegang tot de server in, en vervang
                het client secret als die persoon het ooit in handen heeft gehad.
            </div>
        </section>

        <!-- ═══ 3 ═══════════════════════════════════════════════════════ -->
        <section class="kaart p-4 mb-4 djm-sectie" id="schermen">
            <?php handleiding_kop(3, 'Het beheer, scherm voor scherm', ['vereniging']); ?>
            <p class="djm-hl-tekst">Per onderdeel uit het menu: wat u ziet en wat u er doet. Klik op een schermafdruk om
                hem op ware grootte te openen. Op elk scherm brengt de knop <strong>Uitleg</strong> u naar het juiste
                stuk hieronder.</p>

            <div class="djm-hl-scherm djm-sectie" id="scherm-index" data-scherm="index.php">
                <?php handleiding_scherm_kop('index.php', 'Overzicht'); ?>
                <p class="djm-hl-tekst">Kerncijfers, de laatste downloads en inlogpogingen, en <strong>waarschuwingen</strong>.
                    Een gele of rode balk hier betekent bijna altijd dat inloggen voor ouders niet goed werkt: e-mail niet
                    ingesteld, een verkeerd <code>APP_URL</code>, een proxy die niet is ingesteld. Geef zo'n melding door
                    aan de technisch beheerder.</p>
                <?php handleiding_figuur('beheer-overzicht', 'Beheeroverzicht met kerncijfers en laatste activiteit', [
                    'adres' => '/admin/index.php', 'hoog' => true,
                    'bijschrift' => 'Het overzicht zonder waarschuwingen: zo hoort het eruit te zien.',
                ]); ?>
            </div>

            <div class="djm-hl-scherm djm-sectie" id="scherm-jaargangen" data-scherm="jaargangen.php">
                <?php handleiding_scherm_kop('jaargangen.php', 'Jaargangen'); ?>
                <div class="djm-hl-tekst">
                    <p>Eén regel per jaar, met titel, omschrijving en zichtbaarheid.</p>
                    <ul>
                        <li><strong>Gepubliceerd</strong>: alleen gepubliceerde jaargangen zien ouders. Een jaargang die nog
                            niet gepubliceerd is, staat als <em>concept</em> in de lijst.</li>
                        <li><strong>Zichtbaar vanaf</strong> en <strong>Verloopt op</strong>: optionele datums. Een verlopen
                            jaargang verdwijnt vanzelf uit het portaal.</li>
                        <li><strong>Verwijderen</strong> wist de jaargang, de koppelingen en de toegang, maar
                            <strong>niet</strong> het videobestand op schijf. Wilt u alleen dat niemand er meer bij kan,
                            depubliceer dan liever.</li>
                    </ul>
                </div>
                <?php handleiding_figuur('beheer-jaargangen', 'Lijst van jaargangen met status', [
                    'adres' => '/admin/jaargangen.php',
                    'bijschrift' => '2027 staat als concept klaar; 2025 verloopt eind 2026. Rechts maakt u een nieuwe jaargang aan.',
                ]); ?>
            </div>

            <div class="djm-hl-scherm djm-sectie" id="scherm-bestanden" data-scherm="bestanden.php">
                <?php handleiding_scherm_kop('bestanden.php', 'Bestanden'); ?>
                <div class="djm-hl-tekst">
                    <p>Hier zet u de video online en koppelt u hem aan een jaargang. Kies de jaargang, sleep de video in het
                        vak <strong>Video uploaden</strong> en klik op <strong>Uploaden</strong>. Geef hem een <strong>titel</strong>
                        (de tekst op de downloadknop) en eventueel een <strong>downloadnaam</strong> (hoe het bestand bij de ouder
                        op de computer komt); na afloop is hij meteen gekoppeld. Meerdere bestanden per jaar kan, bijvoorbeeld
                        een kleinere versie voor trage verbindingen.</p>
                    <ul>
                        <li><strong>Ook grote bestanden.</strong> De video gaat in stukken naar de server. Valt de verbinding
                            weg, dan probeert het portaal het zelf opnieuw. U ziet hoeveel er binnen is en hoe lang het nog
                            duurt.</li>
                        <li><strong>Tabblad per ongeluk dicht?</strong> Open Bestanden opnieuw en kies hetzelfde bestand: de
                            upload gaat verder waar hij was. Een onafgemaakte upload staat boven het uploadvak en wordt na een
                            week zonder voortgang vanzelf weggegooid.</li>
                        <li><strong>Houd het tabblad open</strong> en laat de computer niet slapen tot de upload klaar is.
                            Met <em>Pauzeren</em> onderbreekt u hem, met <em>Doorgaan</em> gaat hij verder.</li>
                        <li><strong>Kiezen uit de opslagmap</strong> is voor een bestand dat al op de server staat,
                            bijvoorbeeld na ontkoppelen. Daaronder kunt u bestanden die aan geen enkele jaargang hangen
                            definitief verwijderen.</li>
                    </ul>
                </div>
                <?php handleiding_figuur('beheer-bestanden', 'Gekoppelde bestanden van jaargang 2026', [
                    'adres' => '/admin/bestanden.php?jaargang=…', 'hoog' => true,
                ]); ?>
            </div>

            <div class="djm-hl-scherm djm-sectie" id="scherm-bestandscontrole" data-scherm="bestandscontrole.php">
                <?php handleiding_scherm_kop('bestandscontrole.php', 'Controle'); ?>
                <p class="djm-hl-tekst">Vergelijkt elk gekoppeld bestand met wat er echt op schijf staat: <em>in orde</em>,
                    <em>grootte wijkt af</em>, <em>ontbreekt</em>, <em>onleesbaar</em> of <em>leeg</em>. Kijk hier na het
                    uploaden van een nieuwe video en als een ouder meldt dat een download niet werkt. Deze pagina wijzigt
                    nooit iets op schijf.</p>
                <?php handleiding_figuur('beheer-controle', 'Bestandscontrole met een ontbrekend bestand', [
                    'adres' => '/admin/bestandscontrole.php',
                    'bijschrift' => 'Eén bestand ontbreekt (de video van 2024, verderop in de lijst). Het blok "Wat u moet doen" zegt hoe u dat oplost.',
                ]); ?>
            </div>

            <div class="djm-hl-scherm djm-sectie" id="scherm-toegang" data-scherm="toegang.php">
                <?php handleiding_scherm_kop('toegang.php', 'Toegang'); ?>
                <p class="djm-hl-tekst">Het belangrijkste scherm voor het jaarlijkse werk. Kies bovenin de jaargang.
                    Daaronder ziet u wie er toegang heeft, met per adres de <strong>ophaalstatus</strong>:
                    <em>opgehaald</em>, <em>ingelogd maar niet opgehaald</em> of <em>nooit ingelogd</em>.</p>
                <?php handleiding_figuur('beheer-toegang', 'Toegangslijst van 2026 met ophaalstatus', [
                    'adres' => '/admin/toegang.php?jaargang=…',
                    'bijschrift' => 'Van de 24 adressen hebben er 12 de video opgehaald, 5 zijn ingelogd zonder te downloaden en 7 hebben nog nooit ingelogd.',
                ]); ?>

                <h4 class="h6 mt-4">Adressen toevoegen</h4>
                <p class="djm-hl-tekst">Plak adressen (één per regel; <code>"Naam" &lt;adres&gt;</code> mag ook) of upload
                    een CSV, en klik op <strong>Controleren</strong>. Er wordt dan nog niets opgeslagen: u ziet eerst welke
                    adressen nieuw zijn, welke al toegang hadden en welke ongeldig zijn. Pas na bevestigen worden ze
                    toegevoegd, eventueel met een uitnodigingsmail.</p>
                <?php handleiding_figuur('beheer-toegang-controleren', 'Controlestap bij het importeren van e-mailadressen', [
                    'bijschrift' => 'De typefout klaas@@example.nl wordt als ongeldig herkend en niet toegevoegd.',
                ]); ?>

                <h4 class="h6 mt-4">Herinnering sturen</h4>
                <div class="row g-4 align-items-start">
                    <div class="col-md-8 djm-hl-tekst">
                        <p>Rechtsboven de lijst kiest u een groep, <em>wie de video nog niet ophaalde</em> of <em>wie nog
                            nooit inlogde</em>, en klikt u op <strong>Herinnering versturen</strong>. U ziet eerst hoeveel
                            mails er gaan voordat u bevestigt. Wie de afgelopen 24 uur al gemaild is, of geblokkeerd is,
                            slaat het portaal standaard over.</p>
                        <p class="mb-0">Per adres kunt u in de lijst ook een uitnodiging opnieuw sturen of de toegang
                            intrekken. <strong>Exporteren als CSV</strong> geeft de hele lijst met ophaalstatus.</p>
                    </div>
                    <div class="col-md-4">
                        <?php handleiding_figuur('beheer-toegang-mobiel', 'Het toegangsscherm op een telefoon', [
                            'smal' => true, 'bijschrift' => 'Het beheer werkt ook op een telefoon.',
                        ]); ?>
                    </div>
                </div>
                <?php handleiding_figuur('beheer-herinnering', 'Bevestigingsstap voor een herinneringsmail', [
                    'bijschrift' => 'De bevestigingsstap. Hier gaat nog niets de deur uit.',
                ]); ?>
            </div>

            <div class="djm-hl-scherm djm-sectie" id="scherm-deelnemers" data-scherm="deelnemers.php">
                <?php handleiding_scherm_kop('deelnemers.php', 'Deelnemers'); ?>
                <div class="djm-hl-tekst">
                    <p>Alle e-mailadressen in het portaal, over alle jaren heen. Zoek op adres of naam en klik op
                        <strong>Openen</strong>. Per deelnemer kunt u:</p>
                    <ul>
                        <li>de naam aanpassen en toegang per jaargang aan- of uitvinken. Het <strong>e-mailadres zelf is
                            niet te wijzigen</strong>: klopt het niet, verwijder de deelnemer dan en voeg het juiste adres
                            toe via Toegang;</li>
                        <li>de downloads en inlogpogingen van die persoon bekijken;</li>
                        <li><strong>blokkeren</strong>: geen inlogcodes meer. De toegang blijft bewaard, dus deblokkeren zet
                            alles terug;</li>
                        <li><strong>verwijderen</strong> (AVG): wist de deelnemer, alle toegang en openstaande codes. Het
                            downloadlogboek blijft bewaard als verantwoording. Niet terug te draaien.</li>
                    </ul>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <?php handleiding_figuur('beheer-deelnemers', 'Lijst van deelnemers', ['adres' => '/admin/deelnemers.php']); ?>
                    </div>
                    <div class="col-md-6">
                        <?php handleiding_figuur('beheer-deelnemer', 'Detailpagina van één deelnemer', [
                            'adres' => '/admin/deelnemers.php?id=…', 'hoog' => true,
                        ]); ?>
                    </div>
                </div>
            </div>

            <div class="djm-hl-scherm djm-sectie" id="scherm-logboek" data-scherm="logboek.php">
                <?php handleiding_scherm_kop('logboek.php', 'Logboek'); ?>
                <p class="djm-hl-tekst">Drie tabbladen: <strong>inloggen</strong>, <strong>e-mail</strong> (met de
                    foutmelding als versturen mislukte) en <strong>downloads</strong> (hoeveel er verzonden is en of de
                    download is afgerond). Filter op adres en datum. Oude regels ruimt de nachtelijke taak zelf op, na de
                    bewaartermijn uit de instellingen.</p>
                <p class="djm-hl-tekst">Bij <strong>Downloads</strong> staat per regel <strong>hoe ver</strong> iemand
                    gekomen is — een balkje met het percentage van de bestandsgrootte — en <strong>waarom</strong> het
                    stopte:</p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-2">
                        <tbody>
                            <tr><td><span class="badge text-bg-success">voltooid</span></td>
                                <td class="small">Alle bytes verstuurd.</td></tr>
                            <tr><td><span class="badge text-bg-warning">verbinding verbroken</span></td>
                                <td class="small">De verbinding viel weg. Dat kan de bezoeker zijn, maar ook iets
                                    tussen ons en de bezoeker in — het portaal ziet alleen dát hij wegviel.</td></tr>
                            <tr><td><span class="badge text-bg-danger">server brak af</span></td>
                                <td class="small">Onze kant stopte: een leesfout op de schijf, een tijdslimiet of een
                                    afgeschoten proces. Altijd werk voor de technisch beheerder.</td></tr>
                            <tr><td><span class="badge text-bg-info">bezig</span></td>
                                <td class="small">Loopt nog — de voortgang wordt tijdens het downloaden bijgewerkt — of
                                    het proces is afgeschoten zonder zich af te melden. Kijk naar het tijdstip.</td></tr>
                            <tr><td><span class="badge text-bg-secondary">niet gemeten</span></td>
                                <td class="small">De webserver leverde uit (X-Accel of X-Sendfile). Er kwam dan geen
                                    byte langs het portaal.</td></tr>
                        </tbody>
                    </table>
                </div>
                <p class="djm-hl-tekst">Bovenaan staat een blok dat de vraag beantwoordt <em>waar gaat het mis?</em>
                    Stoppen de afgebroken downloads elke keer ergens anders, dan ligt het aan de verbindingen van de
                    bezoekers en is hervatten het antwoord. Stoppen ze allemaal <strong>rond hetzelfde punt</strong>,
                    dan zit er een grens op de server; het blok kleurt rood en noemt de meest voorkomende oorzaak: een
                    nginx die vóór Apache staat en na één gigabyte stopt met lezen
                    (<code>proxy_buffering off;</code>). Dat blok overrulet de losse regels: staat er tien keer
                    <em>verbinding verbroken</em> op precies dezelfde plek, dan waren dat niet tien bezoekers die
                    toevallig tegelijk afhaakten.</p>
                <p class="djm-hl-tekst small text-muted">Meten kan alleen als het portaal zelf uitlevert. Staat
                    <code>DELIVERY_MODE</code> in <code>.env</code> op <code>xaccel</code> of <code>xsendfile</code>,
                    dan doet de webserver het werk — sneller en robuuster, maar het portaal ziet niet hoe ver iemand
                    kwam en alles staat op <em>niet gemeten</em>. Wilt u het onderzoeken, zet hem dan tijdelijk op
                    <code>php</code>.</p>
                <?php handleiding_figuur('beheer-logboek-mails', 'Het maillogboek', [
                    'adres' => '/admin/logboek.php?tab=mails',
                    'bijschrift' => 'Het eerste wat u opent als een ouder zegt geen mail te krijgen. Een mail die niet verstuurd kon worden, staat hier als "mislukt", met de foutmelding erbij.',
                ]); ?>
                <div class="row g-3">
                    <div class="col-md-6">
                        <?php handleiding_figuur('beheer-logboek-logins', 'Logboek met inlogpogingen', ['bijschrift' => 'Inlogpogingen.']); ?>
                    </div>
                    <div class="col-md-6">
                        <?php handleiding_figuur('beheer-logboek-downloads', 'Logboek met downloads', ['bijschrift' => 'Downloads, met hoe ver elke download kwam en bovenaan de analyse van waar het misgaat.']); ?>
                    </div>
                </div>
            </div>

            <div class="djm-hl-scherm djm-sectie" id="scherm-instellingen" data-scherm="instellingen.php">
                <?php handleiding_scherm_kop('instellingen.php', 'Instellingen'); ?>
                <p class="djm-hl-tekst">Eén lange pagina met onderdelen. De meeste zet u één keer goed en daarna niet meer.</p>
                <div class="row g-3">
                    <div class="col-md-6">
                        <?php handleiding_figuur('beheer-instellingen-portaal', 'Instellingen: portaal', [
                            'bijschrift' => 'Portaal: naam, merkkleur, logo, welkomsttekst en contactadres. Zet "Portaal ingeschakeld" uit tijdens onderhoud: dan kan niemand een code aanvragen.',
                        ]); ?>
                    </div>
                    <div class="col-md-6">
                        <?php handleiding_figuur('beheer-instellingen-testen', 'Instellingen: testen', [
                            'bijschrift' => 'Testen: testmail, Graph-diagnose en de uitleveringszelftest. Uw eerste stop bij een storing.',
                        ]); ?>
                    </div>
                    <div class="col-md-6">
                        <?php handleiding_figuur('beheer-instellingen-email', 'Instellingen: e-mail', [
                            'bijschrift' => 'E-mail: afzender en de Microsoft Graph-gegevens, met SMTP als terugvaloptie.',
                        ]); ?>
                    </div>
                    <div class="col-md-6">
                        <?php handleiding_figuur('beheer-instellingen-sjablonen', 'Instellingen: mailsjablonen', [
                            'bijschrift' => 'Mailsjablonen: de tekst van de inlogcode- en uitnodigingsmail, met invulvelden als {naam} en {jaar}.',
                        ]); ?>
                    </div>
                </div>
                <?php handleiding_figuur('beheer-instellingen-inloggen', 'Instellingen: inloggen', [
                    'bijschrift' => 'Inloggen: geldigheid van codes, limieten, sessieduur en bewaartermijn. De standaardwaarden zijn bewust gekozen; wijzig ze alleen als u weet waarom.',
                ]); ?>
            </div>

            <div class="djm-hl-scherm djm-sectie" id="scherm-beheerders" data-scherm="beheerders.php">
                <?php handleiding_scherm_kop('beheerders.php', 'Beheerders'); ?>
                <p class="djm-hl-tekst">Wie er in het beheer mag. Voeg iemand toe met naam en e-mailadres: die krijgt een
                    uitnodiging en kiest daarin zelf een wachtwoord. Per beheerder ziet u wanneer die het laatst inlogde en
                    of er nog een link openstaat, en kunt u een nieuwe link sturen of het account uitschakelen. Meer in
                    <a href="#beheerders">hoofdstuk 5</a>.</p>
                <?php handleiding_figuur('beheer-beheerders', 'Lijst van beheerders met het formulier om er een toe te voegen', [
                    'adres' => '/admin/beheerders.php',
                    'bijschrift' => 'Jan heeft zijn uitnodiging nog niet gebruikt; tot wanneer de link werkt, staat erbij. Een uitgeschakeld account staat onderaan en is met één klik weer in te schakelen.',
                ]); ?>
            </div>
        </section>

        <!-- ═══ 4 ═══════════════════════════════════════════════════════ -->
        <section class="kaart p-4 mb-4 djm-sectie" id="nieuw-jaar">
            <?php handleiding_kop(4, 'Een nieuw jaar online zetten', ['vereniging']); ?>
            <p class="djm-hl-tekst">Reken op een kwartier werk, plus de tijd die het uploaden van de video kost.</p>
            <ol class="djm-hl-stappen">
                <li><div><strong>Jaargang aanmaken</strong><span><a href="#scherm-jaargangen">Jaargangen</a> → Nieuwe
                    jaargang. Laat <em>Gepubliceerd</em> nog uit.</span></div></li>
                <li><div><strong>Video uploaden</strong><span><a href="#scherm-bestanden">Bestanden</a> → kies de
                    jaargang, sleep de video in het uploadvak en klik op <em>Uploaden</em>. Houd het tabblad open; na afloop
                    is de video meteen gekoppeld. Kijk daarna in <a href="#scherm-bestandscontrole">Controle</a> of hij
                    <em>in orde</em> is.</span></div></li>
                <li><div><strong>Eerst uzelf toevoegen en testen</strong><span><a href="#scherm-toegang">Toegang</a> →
                    alleen uw eigen adres, met uitnodiging. Publiceer de jaargang, log in als ouder en start de
                    download.</span></div></li>
                <li><div><strong>De hele lijst importeren</strong><span>Werkt de test? Plak dan alle adressen, controleer
                    het voorbeeld en bevestig met uitnodigingsmail.</span></div></li>
            </ol>
        </section>

        <!-- ═══ 5 ═══════════════════════════════════════════════════════ -->
        <section class="kaart p-4 mb-4 djm-sectie" id="beheerders">
            <?php handleiding_kop(5, 'Beheerders toevoegen, resetten en uitschakelen', ['vereniging', 'technisch']); ?>
            <div class="djm-hl-tekst">
                <p>Dit gaat via <a href="<?= h(url('admin/beheerders.php')) ?>">Beheer › Beheerders</a>. Alle beheerders
                    hebben dezelfde rechten.</p>
                <ul>
                    <li><strong>Toevoegen:</strong> vul naam en e-mailadres in. De nieuwe beheerder krijgt een e-mail en kiest
                        daarin zelf een wachtwoord van minimaal 12 tekens. De link is 72 uur geldig en werkt één keer.
                        Niemand anders ziet of kent het wachtwoord.</li>
                    <li><strong>Wachtwoord vergeten:</strong> klik op de inlogpagina van het beheer op <em>Wachtwoord
                        vergeten?</em>. De link is 60 minuten geldig. Een andere beheerder kan hem ook sturen met
                        <strong>Resetlink sturen</strong>; hij gaat altijd naar het adres van de beheerder zelf.</li>
                    <li><strong>Uitschakelen:</strong> werkt direct, ook als die persoon op dat moment is ingelogd, en maakt
                        een openstaande link ongeldig. Uw eigen account kunt u niet uitschakelen, zodat er altijd iemand
                        overblijft. Verwijderen kan niet: zo blijft het logboek te herleiden.</li>
                </ul>
                <p class="mb-0">Elke handeling staat in het logboek onder <em>Inloggen</em>, met wie hem deed.</p>
            </div>

            <h3 class="h6 mt-4">Als niemand er meer in komt</h3>
            <p class="djm-hl-tekst">Werkt de e-mail niet (bijvoorbeeld door een verlopen client secret) en weet geen enkele
                beheerder zijn wachtwoord nog, dan kan alleen de technisch beheerder helpen, via de server.</p>

            <h3 class="h6 mt-4">Via de installatiewizard</h3>
            <ol class="djm-hl-tekst">
                <li>Staat <code>setup.php</code> niet meer op de server, zet hem dan tijdelijk terug.</li>
                <li>Maak in de projectmap een leeg bestand <code>setup.toegestaan</code> aan.</li>
                <li>Open <code><?= h(url('setup.php')) ?>?stap=4</code> en vul naam, e-mailadres en een wachtwoord van
                    minimaal 12 tekens in. Een bestaand e-mailadres krijgt zo een nieuw wachtwoord.</li>
                <li><strong>Verwijder daarna meteen <code>setup.toegestaan</code> én <code>setup.php</code>.</strong>
                    Zolang het bestand er staat, kan iedereen de wizard openen, ook het scherm dat <code>.env</code>
                    overschrijft.</li>
            </ol>

            <h3 class="h6 mt-4">Of rechtstreeks in de database</h3>
<pre class="djm-hl-code"><span class="c"># wachtwoord omzetten naar een hash</span>
php -r 'echo password_hash("HIER-HET-WACHTWOORD", PASSWORD_DEFAULT), "\n";'

<span class="c">-- nieuwe beheerder (e-mailadres in kleine letters)</span>
INSERT INTO beheerders (naam, email, wachtwoord_hash, rol, actief)
VALUES ('Voornaam Achternaam', 'naam@example.nl', '&lt;hash&gt;', 'beheerder', 1);

<span class="c">-- wachtwoord resetten</span>
UPDATE beheerders SET wachtwoord_hash = '&lt;hash&gt;' WHERE email = 'naam@example.nl';

<span class="c">-- uitschakelen: werkt direct, ook als die persoon nu is ingelogd</span>
UPDATE beheerders SET actief = 0 WHERE email = 'naam@example.nl';

<span class="c">-- wie is er beheerder?</span>
SELECT naam, email, actief, laatst_ingelogd_op FROM beheerders;</pre>
            <p class="small text-muted djm-hl-tekst mb-0">Schakel vertrokken beheerders liever uit dan dat u ze
                verwijdert: dan blijft het logboek te herleiden.</p>
        </section>

        <!-- ═══ 6 ═══════════════════════════════════════════════════════ -->
        <section class="kaart p-4 mb-4 djm-sectie" id="taken">
            <?php handleiding_kop(6, 'Terugkerende taken', ['vereniging', 'technisch']); ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr><th>Wanneer</th><th>Wat</th><th>Wie</th></tr>
                    </thead>
                    <tbody>
                        <tr><td class="fw-semibold">Elk jaar, na de voorstelling</td><td>Nieuwe jaargang online zetten (<a href="#nieuw-jaar">hoofdstuk 4</a>)</td><td><span class="djm-hl-rol djm-hl-rol-v">Vereniging</span></td></tr>
                        <tr><td class="fw-semibold">Paar weken na de uitnodiging</td><td>Herinnering sturen aan wie nog niet heeft opgehaald</td><td><span class="djm-hl-rol djm-hl-rol-v">Vereniging</span></td></tr>
                        <tr><td class="fw-semibold">Na elke nieuwe video</td><td>Controle: staan alle bestanden er, en heel?</td><td><span class="djm-hl-rol djm-hl-rol-v">Vereniging</span></td></tr>
                        <tr><td class="fw-semibold">Maandelijks</td><td>Overzicht op waarschuwingen, logboek op mislukte mails</td><td><span class="djm-hl-rol djm-hl-rol-v">Vereniging</span></td></tr>
                        <tr><td class="fw-semibold">Ruim vóór de vervaldatum</td><td><strong>Client secret vernieuwen</strong> in Microsoft Entra en invullen bij Instellingen → E-mail</td><td><span class="djm-hl-rol djm-hl-rol-t">Technisch</span></td></tr>
                        <tr><td class="fw-semibold">Wekelijks, automatisch</td><td>Back-up van de database; de opslagmap na elke nieuwe jaargang</td><td><span class="djm-hl-rol djm-hl-rol-t">Technisch</span></td></tr>
                        <tr><td class="fw-semibold">Elk kwartaal</td><td>Draait de nachtelijke taak nog? Kijk in <code>logs/cron.log</code></td><td><span class="djm-hl-rol djm-hl-rol-t">Technisch</span></td></tr>
                        <tr><td class="fw-semibold">Na elke update</td><td>Deze handleiding bekijken: wat is er veranderd?</td><td><span class="djm-hl-rol djm-hl-rol-v">Vereniging</span></td></tr>
                        <tr><td class="fw-semibold">Als de afgesproken termijn om is</td><td>Oude jaargang laten verlopen of depubliceren</td><td><span class="djm-hl-rol djm-hl-rol-v">Vereniging</span></td></tr>
                    </tbody>
                </table>
            </div>
            <div class="alert alert-warning mt-4 mb-0 djm-hl-tekst">
                <strong>Het client secret is de enige taak waardoor het portaal ongemerkt stopt.</strong> Het portaal
                waarschuwt niet vooraf. Verloopt het, dan gaat er geen mail meer uit en kan niemand inloggen. Zet de
                vervaldatum in een agenda die niet aan één persoon hangt.
            </div>
        </section>

        <!-- ═══ 7 ═══════════════════════════════════════════════════════ -->
        <section class="kaart p-4 mb-4 djm-sectie" id="vragen">
            <?php handleiding_kop(7, 'Vragen van ouders en deelnemers', ['vereniging']); ?>

            <details class="djm-hl-vraag" id="vraag-geen-code" open>
                <summary>"Ik krijg geen inlogcode"</summary>
                <div>
                    <p>Open <a href="<?= h(url('admin/logboek.php?tab=mails')) ?>">Logboek → E-mail</a> en zoek op het adres.</p>
                    <ul>
                        <li><strong>Mislukt</strong>: het ligt aan de mailinstellingen of aan een typefout in het adres. De
                            foutmelding staat erbij; geef die zo nodig door aan de technisch beheerder.</li>
                        <li><strong>Verzonden</strong>: de mail is de deur uit. Laat de ouder in de spam kijken.</li>
                        <li><strong>Niets te vinden</strong>: het adres heeft geen toegang tot een zichtbare jaargang. Het
                            portaal vertelt dat met opzet niet aan de bezoeker. Zoek het adres op bij
                            <a href="#scherm-deelnemers">Deelnemers</a>: staat het er zonder typefout, is het niet
                            geblokkeerd, heeft het een vinkje bij een gepubliceerde jaargang?</li>
                    </ul>
                    <p>Te vaak achter elkaar een code aangevraagd? Dan houdt het portaal tijdelijk de boot af. Laat de ouder
                        een uur wachten.</p>
                </div>
            </details>
            <details class="djm-hl-vraag">
                <summary>"Mijn code werkt niet"</summary>
                <div>
                    <p>Een code is <?= (int)instelling_int('otp_geldigheid_minuten', 10) ?> minuten geldig, werkt één keer
                        en vervalt na <?= (int)instelling_int('otp_max_pogingen', 5) ?> foute pogingen. En hij werkt alleen
                        in <strong>dezelfde browser</strong> als waarin hij is aangevraagd: code op de laptop aangevraagd en
                        op de telefoon ingetypt, of de mail doorgestuurd naar opa, dat lukt niet. Laat een nieuwe code
                        aanvragen op het apparaat waarop gedownload wordt.</p>
                </div>
            </details>
            <details class="djm-hl-vraag">
                <summary>"Ik gebruik een ander e-mailadres"</summary>
                <div>
                    <p>Een e-mailadres is niet te wijzigen. Voeg het nieuwe adres toe via
                        <a href="#scherm-toegang">Toegang</a>, bij dezelfde jaargangen. Is het oude adres nergens meer voor
                        nodig, verwijder die deelnemer dan bij <a href="#scherm-deelnemers">Deelnemers</a>.</p>
                </div>
            </details>
            <details class="djm-hl-vraag">
                <summary>"Ik heb een <code>index.php</code> gedownload in plaats van de video"</summary>
                <div>
                    <p>Dat kon gebeuren in oudere versies. Een downloadlink was toen vijf minuten geldig; klikte iemand
                        later, of hervatte de browser een download van gisteren, dan stuurde het portaal door naar het
                        overzicht — en de browser bewaarde <em>die pagina</em> als bestand. Nu is een link
                        <strong>twaalf uur</strong> geldig en geeft het portaal bij een verlopen link vanzelf een nieuwe
                        af, zodat de download gewoon begint of hervat wordt. Laat het bestandje van een paar kilobyte
                        weggooien en opnieuw op <strong>Downloaden</strong> klikken.</p>
                    <p>Gebeurt het toch nog, schakel dan de technisch beheerder in: in <code>logs/app.log</code> staat
                        dan een regel over een downloadlink die meteen weer werd afgekeurd. Dat betekent dat de
                        serverklok niet gelijkloopt of dat er twee servers met een verschillende <code>APP_KEY</code>
                        draaien.</p>
                </div>
            </details>
            <details class="djm-hl-vraag">
                <summary>"De download stopt halverwege" of "de video is kapot"</summary>
                <div>
                    <ol>
                        <li><a href="#scherm-bestandscontrole">Controle</a>: staat het bestand er, en klopt de grootte?</li>
                        <li><a href="<?= h(url('admin/logboek.php?tab=downloads')) ?>">Logboek → Downloads</a>: stopt het bij
                            iedereen rond hetzelfde punt, dan is het een serverinstelling (technisch beheerder). Verschilt
                            het per keer, dan ligt het meestal aan de verbinding van de ouder.</li>
                        <li>Adviseer een stabiele verbinding. Downloads zijn te hervatten, ook met een downloadmanager —
                            laat de onderbroken download <em>hervatten</em> in plaats van opnieuw starten.</li>
                        <li>Staat er in <code>logs/app.log</code> een regel
                            <code>bestandsgrootte wijkt af van de database</code>, dan is het bestand op schijf een ander
                            dan bij het koppelen: opnieuw uploaden en opnieuw koppelen.</li>
                    </ol>
                </div>
            </details>
            <details class="djm-hl-vraag">
                <summary>"Ik wil dat mijn gegevens worden verwijderd"</summary>
                <div>
                    <p><a href="#scherm-deelnemers">Deelnemers</a> → het adres → <strong>Deelnemer verwijderen</strong>.
                        Vermeld in uw antwoord dat het downloadlogboek, met het e-mailadres als momentopname, bewaard
                        blijft tot de bewaartermijn van <?= (int)instelling_int('log_bewaartermijn_dagen', 365) ?> dagen
                        verstreken is.</p>
                </div>
            </details>
            <details class="djm-hl-vraag">
                <summary>"Iemand anders heeft mijn video gedownload"</summary>
                <div>
                    <p>Bekijk bij de deelnemer de inlogpogingen en downloads, met tijdstip en IP-adres. Blokkeer het adres
                        zo nodig en schakel de technisch beheerder in als er iets niet klopt.</p>
                </div>
            </details>
        </section>

        <!-- ═══ 8 ═══════════════════════════════════════════════════════ -->
        <section class="kaart p-4 mb-4 djm-sectie" id="technisch">
            <?php handleiding_kop(8, 'Technisch beheer', ['technisch']); ?>

            <h3 class="h6 djm-sectie" id="storing">Niemand kan inloggen: eerste controle</h3>
            <ol class="djm-hl-stappen">
                <li><div><strong>Overzicht</strong><span>Staat er een gele of rode waarschuwing? Die noemt meestal de oorzaak.</span></div></li>
                <li><div><strong>Instellingen → Testen → Graph-configuratie controleren</strong><span>Een fout over het token betekent meestal een verlopen client secret.</span></div></li>
                <li><div><strong>Instellingen → Testen → Testmail versturen</strong><span>Naar uw eigen adres.</span></div></li>
                <li><div><strong>Logboek → E-mail</strong><span>De foutmelding bij de laatste mislukte mail.</span></div></li>
                <li><div><strong><code>logs/</code> op de server</strong><span>Het applicatie- en foutlogboek.</span></div></li>
                <li><div><strong>Formulier doet niets?</strong><span>Controleer <code>APP_URL</code> in <code>.env</code>: die moet exact het adres zijn waarop het portaal draait. Leeg mag ook, maar dan verstuurt <em>Wachtwoord vergeten?</em> geen link.</span></div></li>
            </ol>

            <h3 class="h6 mt-4">Back-ups</h3>
            <p class="djm-hl-tekst">Onvervangbaar zijn de <strong>database</strong>, de <strong>opslagmap</strong> en
                <strong><code>.env</code></strong>.</p>
<pre class="djm-hl-code">mysqldump -u &lt;gebruiker&gt; -p &lt;database&gt; | gzip &gt; djm-$(date +%F).sql.gz</pre>
            <ul class="djm-hl-tekst">
                <li>De opslagmap verandert maar één keer per jaar: een kopie na elke nieuwe jaargang is genoeg, mits de
                    originele video ook ergens anders staat.</li>
                <li>Bewaar <code>.env</code> in de wachtwoordmanager, niet naast de back-up op dezelfde server. Zonder
                    <code>APP_KEY</code> en <code>OTP_PEPPER</code> werken bestaande codes en cookies niet meer.</li>
                <li>Zet een back-up één keer per jaar echt terug op een testomgeving. Een back-up die nooit is
                    teruggezet, is geen back-up maar hoop.</li>
            </ul>

            <h3 class="h6 mt-4">Een nieuwe versie installeren</h3>
            <ol class="djm-hl-stappen">
                <li><div><strong>Lees de changelog</strong><span>Staat er iets over de database, <code>.env</code> of de webserverconfiguratie?</span></div></li>
                <li><div><strong>Maak een back-up</strong><span>Database en <code>.env</code>.</span></div></li>
                <li><div><strong>Zet de nieuwe bestanden neer</strong><span>Met <code>git pull</code> of <code>rsync</code>. Laat <code>.env</code>, <code>opslag/</code> en <code>logs/</code> staan en upload <code>test/</code> niet.</span></div></li>
                <li><div><strong>Database bijwerken als dat nodig is</strong><span><code>db.sql</code> opnieuw uitvoeren is veilig, maar past bestaande tabellen niet aan. Volg bij een databasewijziging de instructie uit de changelog.</span></div></li>
                <li><div><strong>Controleren</strong><span>Overzicht zonder waarschuwingen, <em>Uitproberen</em> onder Testen helemaal groen, één keer zelf inloggen en een download starten — en deze handleiding doorlezen op wat er nieuw is.</span></div></li>
            </ol>

            <h3 class="h6 mt-4">De nachtelijke taak</h3>
<pre class="djm-hl-code">0 4 * * * /usr/bin/php <?= h(APP_ROOT) ?>/cron_opschonen.php &gt;&gt; <?= h(APP_ROOT) ?>/logs/cron.log 2&gt;&amp;1</pre>
            <p class="djm-hl-tekst mb-0">Controleer het pad naar PHP met <code>which php</code>. Draait de taak niet, dan
                blijft het portaal werken, maar worden logboeken langer bewaard dan de ingestelde termijn — een AVG-punt.
                Op Plesk gebruikt u <em>Geplande taken</em> in het paneel.</p>
        </section>

        <!-- ═══ 9 ═══════════════════════════════════════════════════════ -->
        <section class="kaart p-4 mb-4 djm-sectie" id="documenten">
            <?php handleiding_kop(9, 'Documentatie', ['technisch']); ?>
            <p class="djm-hl-tekst">Deze documenten staan in de projectmap op de server en in de repository. Ze zijn met
                opzet niet via de website te openen.</p>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr><th>Bestand</th><th>Onderwerp</th></tr>
                    </thead>
                    <tbody>
                        <tr><td><code>README.md</code></td><td>Wat het portaal is, functionaliteit, beveiliging</td></tr>
                        <tr><td><code>docs/BEHEER.md</code></td><td>De tekstversie van deze handleiding, voor wie (nog) niet kan inloggen</td></tr>
                        <tr><td><code>docs/NIEUW-JAAR.md</code></td><td>Stap voor stap een nieuwe jaargang toevoegen</td></tr>
                        <tr><td><code>docs/INSTALLATIE.md</code></td><td>Server inrichten, <code>.env</code>, webserver, Plesk, probleemoplossing</td></tr>
                        <tr><td><code>docs/GRAPH-SETUP.md</code></td><td>Microsoft 365-koppeling, client secret, foutmeldingen</td></tr>
                        <tr><td><code>docs/ARCHITECTUUR.md</code></td><td>Voor ontwikkelaars: opbouw en afspraken in de code</td></tr>
                        <tr><td><code>CHANGELOG.md</code></td><td>Wat er per versie is veranderd</td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<?php
admin_eind();
