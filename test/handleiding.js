/**
 * Schermafdrukken voor Beheer › Handleiding (admin/handleiding.php).
 * Draait in een container met Puppeteer; zie test/handleiding.sh.
 * Een nieuwe afbeelding in de handleiding? Voeg hem hier toe.
 * Env: BASIS, MAILPIT, J2026, OUDER, DEELNEMER.
 */
const puppeteer = require('puppeteer');

const BASIS = process.env.BASIS || 'http://web:8080';
const MAILPIT = process.env.MAILPIT || 'http://mail:8025';
const UIT = '/shots';
const FORMAAT = { type: 'webp', quality: 82 };
const J = process.env.J2026;
const OUDER = process.env.OUDER;
const DEELNEMER = process.env.DEELNEMER;
const BEHEERDER = { email: 'beheer@example.nl', wachtwoord: 'EenHeelLangWachtwoord123' };
const wacht = ms => new Promise(r => setTimeout(r, ms));

if (!J || !OUDER || !DEELNEMER) {
    console.error('J2026, OUDER en DEELNEMER zijn verplicht');
    process.exit(1);
}

async function laatsteMail() {
    const lijst = await (await fetch(`${MAILPIT}/api/v1/messages`)).json();
    if (!lijst.total) return null;
    return (await fetch(`${MAILPIT}/api/v1/message/${lijst.messages[0].ID}`)).json();
}

async function schiet(pagina, naam, { breed = 1240, hoog = 820, vol = true } = {}) {
    await pagina.setViewport({ width: breed, height: hoog, deviceScaleFactor: 2 });
    await wacht(700);
    await pagina.screenshot({ path: `${UIT}/${naam}.webp`, fullPage: vol, ...FORMAAT });
    console.log(`  ${naam}.webp`);
}

async function element(pagina, selector, naam) {
    await pagina.setViewport({ width: 1240, height: 820, deviceScaleFactor: 2 });
    await wacht(500);
    const el = await pagina.$(selector);
    if (!el) { console.log(`  (niet gevonden: ${selector})`); return; }
    await el.screenshot({ path: `${UIT}/${naam}.webp`, ...FORMAAT });
    console.log(`  ${naam}.webp`);
}

/** Schermafdruk van de .kaart waarin een kop met deze tekst staat. */
async function kaartMetKop(pagina, tekst, naam) {
    await pagina.setViewport({ width: 1240, height: 820, deviceScaleFactor: 2 });
    await wacht(500);
    const handle = await pagina.evaluateHandle(t => {
        const kop = [...document.querySelectorAll('h2')].find(e => e.textContent.includes(t));
        return kop ? kop.closest('.kaart') : null;
    }, tekst);
    const el = handle.asElement();
    if (!el) { console.log(`  (kaart niet gevonden: ${tekst})`); return; }
    await el.screenshot({ path: `${UIT}/${naam}.webp`, ...FORMAAT });
    console.log(`  ${naam}.webp`);
}

async function ga(pagina, pad) {
    await pagina.goto(`${BASIS}/${pad}`, { waitUntil: 'networkidle0' });
}

async function verstuur(pagina, formSelector) {
    await Promise.all([
        pagina.waitForNavigation({ waitUntil: 'networkidle0', timeout: 20000 }),
        pagina.evaluate(s => {
            HTMLFormElement.prototype.submit.call(document.querySelector(s));
        }, formSelector),
    ]);
}

(async () => {
    const browser = await puppeteer.launch({
        executablePath: '/usr/bin/chromium-browser',
        args: ['--no-sandbox', '--disable-dev-shm-usage', '--lang=nl-NL'],
    });

    // ── Portaal, als deelnemer ──────────────────────────────────────────
    await fetch(`${MAILPIT}/api/v1/messages`, { method: 'DELETE' }).catch(() => {});
    const ouder = await browser.newPage();
    await ouder.setViewport({ width: 1240, height: 820, deviceScaleFactor: 2 });
    await ga(ouder, 'index.php');
    await ouder.type('input[name="email"]', OUDER);
    await schiet(ouder, 'portaal-start', { vol: false });
    await verstuur(ouder, 'form:has(input[name="email"])');
    await schiet(ouder, 'portaal-code', { vol: false });

    let mail = null;
    for (let i = 0; i < 10 && !mail; i++) { await wacht(700); mail = await laatsteMail(); }
    let code = '';
    if (mail) {
        const tekst = ((mail.HTML || '') + (mail.Text || '')).replace(/<[^>]+>/g, ' ');
        code = (tekst.match(/\b\d{6}\b/) || [''])[0];
        const mp = await browser.newPage();
        await mp.setViewport({ width: 720, height: 600, deviceScaleFactor: 2 });
        await mp.setContent(mail.HTML || `<pre>${mail.Text}</pre>`, { waitUntil: 'networkidle0' });
        await schiet(mp, 'mail-inlogcode', { breed: 720, hoog: 600 });
        await mp.close();
    }
    if (code) {
        await ouder.type('input[name="code"]', code);
        await verstuur(ouder, 'form:has(input[name="code"])');
        await schiet(ouder, 'portaal-overzicht', { vol: false });
        await schiet(ouder, 'portaal-overzicht-mobiel', { breed: 390, hoog: 844, vol: false });
    } else {
        console.log('  ! geen inlogcode gevonden');
    }

    // ── Beheer ──────────────────────────────────────────────────────────
    const b = await browser.newPage();
    await b.setViewport({ width: 1240, height: 820, deviceScaleFactor: 2 });
    await ga(b, 'admin/login.php');
    await schiet(b, 'beheer-inloggen', { vol: false });
    await b.type('input[name="email"]', BEHEERDER.email);
    await b.type('input[name="wachtwoord"]', BEHEERDER.wachtwoord);
    await verstuur(b, 'form:has(input[name="wachtwoord"])');

    await ga(b, 'admin/index.php');                   await schiet(b, 'beheer-overzicht');
    await ga(b, 'admin/jaargangen.php');              await schiet(b, 'beheer-jaargangen', { vol: false });
    await ga(b, `admin/jaargangen.php?id=${J}`);      await schiet(b, 'beheer-jaargang-bewerken', { vol: false });
    await ga(b, `admin/bestanden.php?jaargang=${J}`); await schiet(b, 'beheer-bestanden');
    await ga(b, 'admin/bestandscontrole.php');        await schiet(b, 'beheer-controle', { hoog: 1000, vol: false });
    await ga(b, `admin/toegang.php?jaargang=${J}`);   await schiet(b, 'beheer-toegang', { hoog: 1000, vol: false });

    // Importvoorbeeld: stap 2, daarna annuleren zodat er niets wordt opgeslagen.
    await b.type('#adressen', 'marieke.jansen@example.nl\n"Visser, Joost" <joost.visser@example.nl>\nnieuw.gezin@example.nl\nklaas@@example.nl\nlinda.oost@example.nl');
    await verstuur(b, 'form:has(#adressen)');
    await kaartMetKop(b, 'Stap 2', 'beheer-toegang-controleren');
    const annuleren = await b.$('button[value="annuleren"]');
    if (annuleren) {
        await Promise.all([b.waitForNavigation({ waitUntil: 'networkidle0' }).catch(() => {}), annuleren.click()]);
    }

    // Herinnering: bevestigingsstap, daarna annuleren.
    await ga(b, `admin/toegang.php?jaargang=${J}`);
    if (await b.$('form:has(input[value="herinnering_voorbeeld"])')) {
        await verstuur(b, 'form:has(input[value="herinnering_voorbeeld"])');
        await element(b, '#herinnering', 'beheer-herinnering');
        const annuleer = await b.$('button[value="herinnering_annuleren"]');
        if (annuleer) {
            await Promise.all([b.waitForNavigation({ waitUntil: 'networkidle0' }).catch(() => {}), annuleer.click()]);
        }
    }

    await ga(b, 'admin/deelnemers.php');                   await schiet(b, 'beheer-deelnemers', { hoog: 900, vol: false });
    await ga(b, `admin/deelnemers.php?id=${DEELNEMER}`);   await schiet(b, 'beheer-deelnemer');
    await ga(b, 'admin/logboek.php?tab=logins');           await schiet(b, 'beheer-logboek-logins', { vol: false });
    await ga(b, 'admin/logboek.php?tab=mails');            await schiet(b, 'beheer-logboek-mails', { vol: false });
    await ga(b, 'admin/logboek.php?tab=downloads');        await schiet(b, 'beheer-logboek-downloads', { vol: false });
    await ga(b, 'admin/beheerders.php');                   await schiet(b, 'beheer-beheerders', { vol: false });
    await ga(b, 'admin/instellingen.php');
    for (const id of ['portaal', 'email', 'inloggen', 'sjablonen', 'testen']) {
        await element(b, `#${id}`, `beheer-instellingen-${id}`);
    }
    await ga(b, `admin/toegang.php?jaargang=${J}`);
    await schiet(b, 'beheer-toegang-mobiel', { breed: 390, hoog: 844, vol: false });

    await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
