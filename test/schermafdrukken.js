/**
 * Maakt schermafdrukken van het portaal en het beheer, ingelogd en wel.
 * Draait in een container met Puppeteer; zie test/schermafdrukken.sh.
 */
const puppeteer = require('puppeteer');

const BASIS = process.env.BASIS || 'http://web:8080';
const UIT = '/shots';

const BEHEERDER = { email: 'beheer@example.nl', wachtwoord: 'EenHeelLangWachtwoord123' };

/** Leest de nieuwste inlogcode uit Mailpit. */
async function codeUitMailpit() {
    const basis = process.env.MAILPIT || 'http://mail:8025';
    try {
        const lijst = await (await fetch(`${basis}/api/v1/messages`)).json();
        if (!lijst.total) return '';
        const bericht = await (await fetch(`${basis}/api/v1/message/${lijst.messages[0].ID}`)).json();
        const tekst = ((bericht.HTML || '') + (bericht.Text || '')).replace(/<[^>]+>/g, ' ');
        const treffer = tekst.match(/\b\d{6}\b/);
        return treffer ? treffer[0] : '';
    } catch (e) {
        console.log('  (inlogcode ophalen mislukt: ' + e.message + ')');
        return '';
    }
}

async function schiet(pagina, naam, breed = 1280, hoog = 900) {
    await pagina.setViewport({ width: breed, height: hoog });
    await new Promise(r => setTimeout(r, 900));   // even wachten op de webfonts
    await pagina.screenshot({ path: `${UIT}/${naam}.png`, fullPage: true });
    console.log(`  ${naam}.png`);
}

/** Verzamelt fouten uit de console en mislukte verzoeken per pagina. */
function bewaak(pagina, meldingen) {
    pagina.on('console', m => {
        if (m.type() === 'error') meldingen.push(`console: ${m.text()}`);
    });
    pagina.on('requestfailed', r => meldingen.push(`verzoek mislukt: ${r.url()}`));
    pagina.on('response', r => {
        if (r.status() >= 400) meldingen.push(`HTTP ${r.status()}: ${r.url()}`);
    });
}

(async () => {
    const browser = await puppeteer.launch({
        executablePath: '/usr/bin/chromium-browser',
        args: ['--no-sandbox', '--disable-dev-shm-usage'],
    });
    const meldingen = [];

    // ── Beheer ───────────────────────────────────────────────────────────
    const beheer = await browser.newPage();
    bewaak(beheer, meldingen);
    await beheer.goto(`${BASIS}/admin/login.php`, { waitUntil: 'domcontentloaded' });
    await schiet(beheer, 'beheer-inloggen');

    await beheer.type('input[name="email"]', BEHEERDER.email);
    await beheer.type('input[name="wachtwoord"]', BEHEERDER.wachtwoord);
    await Promise.all([
        beheer.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 }),
        beheer.evaluate(() => document.querySelector('form').submit()),
    ]);

    const paginas = [
        ['index.php', 'beheer-overzicht'],
        ['jaargangen.php', 'beheer-jaargangen'],
        ['bestanden.php', 'beheer-bestanden'],
        ['bestandscontrole.php', 'beheer-bestandscontrole'],
        ['toegang.php', 'beheer-toegang'],
        ['deelnemers.php', 'beheer-deelnemers'],
        ['logboek.php', 'beheer-logboek'],
        ['instellingen.php', 'beheer-instellingen'],
    ];
    for (const [pad, naam] of paginas) {
        const resp = await beheer.goto(`${BASIS}/admin/${pad}`, { waitUntil: 'domcontentloaded' });
        if (resp && resp.status() >= 400) { console.log(`  (overgeslagen: ${pad} gaf ${resp.status()})`); continue; }
        await schiet(beheer, naam);
    }

    // Het beheer wordt ook op een telefoon gebruikt (een bestuurslid dat snel
    // even kijkt wie de video nog niet heeft opgehaald), dus die kant ook vastleggen.
    for (const [pad, naam] of [['index.php', 'beheer-overzicht-mobiel'],
                               ['toegang.php', 'beheer-toegang-mobiel'],
                               ['bestandscontrole.php', 'beheer-controle-mobiel']]) {
        const resp = await beheer.goto(`${BASIS}/admin/${pad}`, { waitUntil: 'domcontentloaded' });
        if (resp && resp.status() >= 400) continue;
        await schiet(beheer, naam, 390, 844);
    }

    // ── Portaal, als ingelogde ouder ─────────────────────────────────────
    const ouder = await browser.newPage();
    bewaak(ouder, meldingen);
    await ouder.goto(`${BASIS}/index.php`, { waitUntil: 'domcontentloaded' });
    await ouder.type('input[name="email"]', process.env.OUDER || 'ouder@example.nl');
    await Promise.all([
        ouder.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 }),
        ouder.evaluate(() => document.querySelector('form').submit()),
    ]);
    await schiet(ouder, 'portaal-code-invoeren', 440, 800);

    // De code staat in de testmailserver; die halen we hier op, net zoals een
    // ouder hem in zijn mailbox zou lezen.
    const code = await codeUitMailpit();
    if (code) {
        await ouder.type('input[name="code"]', code);
        await Promise.all([
            ouder.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 }),
            ouder.evaluate(() => document.querySelector('form').submit()),
        ]);
        await schiet(ouder, 'portaal-overzicht');
        await schiet(ouder, 'portaal-overzicht-mobiel', 390, 844);
    }

    await browser.close();

    if (meldingen.length) {
        console.log('\nMeldingen uit de browser:');
        [...new Set(meldingen)].forEach(m => console.log('  ! ' + m));
        process.exit(1);
    }
    console.log('\nGeen browserfouten of mislukte verzoeken.');

    // Zonder inlogcode is de portaalflow niet doorlopen; dat is geen geslaagde run.
    if (!code) {
        console.log('  ! geen inlogcode gevonden — portaalflow niet doorlopen');
        process.exit(1);
    }
})().catch(e => { console.error(e); process.exit(1); });
