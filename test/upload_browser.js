/**
 * De upload in delen in een echte browser: admin/assets/upload.js tegen
 * admin/upload.php. Draait in een container met Puppeteer; zie test/upload_test.sh.
 * Env: BASIS, JAARGANG.
 *
 * Twee dingen die curl niet kan nabootsen:
 *   1. de verbinding valt midden in een upload weg — het script moet het zelf
 *      opnieuw proberen en de upload afmaken;
 *   2. het tabblad wordt halverwege ververst — wie hetzelfde bestand opnieuw
 *      kiest, moet verdergaan in plaats van opnieuw beginnen.
 */
const puppeteer = require('puppeteer');
const crypto = require('crypto');
const fs = require('fs');

const BASIS = process.env.BASIS || 'http://web:8080';
const JAARGANG = process.env.JAARGANG;
const BEHEERDER = { email: 'beheer@example.nl', wachtwoord: 'EenHeelLangWachtwoord123' };
const MB = 1024 * 1024;

let goed = 0;
let fout = 0;
function toets(omschrijving, ok, uitleg = '') {
    if (ok) { goed++; console.log(`  ✓ ${omschrijving}`); }
    else { fout++; console.log(`  ✗ ${omschrijving}${uitleg ? `\n      ${uitleg}` : ''}`); }
}

/** Houdt elke melding van het uploadvak bij; de laatste blijft maar even staan. */
async function volgMeldingen(pagina) {
    await pagina.evaluate(() => {
        window.meldingen = [];
        const vak = document.querySelector('[data-upload-melding]');
        new MutationObserver(() => {
            if (!vak.hidden) window.meldingen.push(vak.textContent);
        }).observe(vak, { childList: true, characterData: true, subtree: true, attributes: true });
    });
}

async function wachtOpKlaar(pagina) {
    await pagina.waitForFunction(
        () => (window.meldingen || []).some(m => m.startsWith('Klaar:')),
        { timeout: 60000 },
    );
    return pagina.evaluate(() => window.meldingen);
}

(async () => {
    if (!JAARGANG) {
        console.error('JAARGANG is verplicht');
        process.exit(1);
    }
    fs.writeFileSync('/tmp/netwerkfout.mp4', crypto.randomBytes(20 * MB));
    fs.writeFileSync('/tmp/verversen.mp4', crypto.randomBytes(20 * MB));

    const browser = await puppeteer.launch({
        executablePath: '/usr/bin/chromium-browser',
        args: ['--no-sandbox', '--disable-dev-shm-usage', '--lang=nl-NL'],
    });
    const p = await browser.newPage();
    const jsFouten = [];
    p.on('pageerror', e => jsFouten.push(e.message));
    p.on('console', m => {
        // Afgebroken verzoeken zijn hier de bedoeling.
        if (m.type() === 'error' && !/Failed to load resource|ERR_FAILED/.test(m.text())) {
            jsFouten.push(m.text());
        }
    });
    p.on('dialog', d => d.accept());

    await p.goto(`${BASIS}/admin/login.php`, { waitUntil: 'domcontentloaded' });
    await p.type('input[name="email"]', BEHEERDER.email);
    await p.type('input[name="wachtwoord"]', BEHEERDER.wachtwoord);
    await Promise.all([
        p.waitForNavigation({ waitUntil: 'domcontentloaded' }),
        p.evaluate(() => document.querySelector('form').submit()),
    ]);

    // Verzoeken voor een stuk na het eerste kunnen we laten mislukken.
    let onderbreek = null;
    let afgebroken = 0;
    await p.setRequestInterception(true);
    p.on('request', verzoek => {
        const adres = verzoek.url();
        const laterStuk = adres.includes('actie=deel') && !/[?&]offset=0(&|$)/.test(adres);
        if (laterStuk && (onderbreek === 'altijd' || (onderbreek === 'eenmaal' && afgebroken === 0))) {
            afgebroken++;
            verzoek.abort('failed');
            return;
        }
        verzoek.continue();
    });

    const pagina = `${BASIS}/admin/bestanden.php?jaargang=${JAARGANG}`;

    // ── 1. Verbinding valt weg ──────────────────────────────────────────────
    await p.goto(pagina, { waitUntil: 'networkidle0' });
    onderbreek = 'eenmaal';
    await (await p.$('#upload-bestand')).uploadFile('/tmp/netwerkfout.mp4');
    await p.type('#titel-upload', 'Browsertest netwerkfout');
    await volgMeldingen(p);
    const herladen1 = p.waitForNavigation({ waitUntil: 'networkidle0', timeout: 60000 });
    await p.click('[data-upload-start]');
    let meldingen = await wachtOpKlaar(p);
    toets('verbinding weg: een stuk is echt mislukt', afgebroken === 1, `afgebroken: ${afgebroken}`);
    toets('verbinding weg: melding over een nieuwe poging',
        meldingen.some(m => m.includes('Geen verbinding met de server')), JSON.stringify(meldingen));
    await herladen1;
    let tekst = await p.evaluate(() => document.body.innerText);
    toets('verbinding weg: upload toch afgerond en gekoppeld', tekst.includes('Bestand geüpload en gekoppeld'));
    toets('het bestand staat bij de gekoppelde bestanden', tekst.includes('Browsertest netwerkfout'));

    // ── 2. Tabblad verversen halverwege ─────────────────────────────────────
    onderbreek = 'altijd';
    afgebroken = 0;
    await (await p.$('#upload-bestand')).uploadFile('/tmp/verversen.mp4');
    await volgMeldingen(p);
    await p.click('[data-upload-start]');
    await p.waitForFunction(
        () => (window.meldingen || []).some(m => m.includes('Geen verbinding')),
        { timeout: 20000 },
    );
    onderbreek = null;
    await p.reload({ waitUntil: 'networkidle0' });   // de beforeunload-vraag wordt geaccepteerd
    tekst = await p.evaluate(() => document.body.innerText);
    toets('na verversen: onafgemaakte upload zichtbaar', tekst.includes('Onafgemaakte upload'));

    await (await p.$('#upload-bestand')).uploadFile('/tmp/verversen.mp4');
    await p.type('#titel-upload', 'Browsertest verversen');
    await volgMeldingen(p);
    const herladen2 = p.waitForNavigation({ waitUntil: 'networkidle0', timeout: 60000 });
    await p.click('[data-upload-start]');
    meldingen = await wachtOpKlaar(p);
    toets('na verversen: gaat verder in plaats van opnieuw',
        meldingen.some(m => m.includes('gaat verder bij')), JSON.stringify(meldingen));
    await herladen2;
    tekst = await p.evaluate(() => document.body.innerText);
    toets('na verversen: upload afgerond en gekoppeld', tekst.includes('Browsertest verversen'));
    toets('na verversen: geen onafgemaakte upload meer', !tekst.includes('Onafgemaakte upload'));

    toets('geen JavaScript-fouten', jsFouten.length === 0, jsFouten.join(' | '));

    await browser.close();
    console.log(`  browser: ${goed} geslaagd, ${fout} mislukt`);
    process.exit(fout === 0 ? 0 : 1);
})().catch(e => { console.error(e); process.exit(1); });
