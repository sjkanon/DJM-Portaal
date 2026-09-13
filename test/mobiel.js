/**
 * Controleert op telefoonformaat of de pagina zelf niet horizontaal scrolt.
 * Brede tabellen horen in hun eigen scrollbare kader te zitten; scrolt de hele
 * pagina mee, dan valt de opmaak buiten beeld en is de pagina onbruikbaar.
 */
const puppeteer = require('puppeteer');
const BASIS = process.env.BASIS || 'http://web:8080';

const PAGINAS = [
    ['/index.php', false],
    ['/admin/login.php', false],
    ['/admin/index.php', true],
    ['/admin/jaargangen.php', true],
    ['/admin/bestanden.php', true],
    ['/admin/bestandscontrole.php', true],
    ['/admin/toegang.php', true],
    ['/admin/deelnemers.php', true],
    ['/admin/logboek.php', true],
    ['/admin/instellingen.php', true],
    ['/admin/handleiding.php', true],
];

(async () => {
    const browser = await puppeteer.launch({
        executablePath: '/usr/bin/chromium-browser',
        args: ['--no-sandbox', '--disable-dev-shm-usage'],
    });
    const pagina = await browser.newPage();
    await pagina.setViewport({ width: 390, height: 844, isMobile: true });

    // Inloggen als beheerder.
    await pagina.goto(`${BASIS}/admin/login.php`, { waitUntil: 'domcontentloaded' });
    await pagina.type('input[name="email"]', 'beheer@example.nl');
    await pagina.type('input[name="wachtwoord"]', 'EenHeelLangWachtwoord123');
    await Promise.all([
        pagina.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 }),
        pagina.evaluate(() => document.querySelector('form').submit()),
    ]);

    let goed = 0, fout = 0;
    for (const [pad] of PAGINAS) {
        const resp = await pagina.goto(BASIS + pad, { waitUntil: 'domcontentloaded' });
        if (resp && resp.status() >= 400) continue;
        await new Promise(r => setTimeout(r, 300));

        const meting = await pagina.evaluate(() => {
            const overschot = document.documentElement.scrollWidth - document.documentElement.clientWidth;
            // Elementen die zelf breder zijn dan het scherm en niet in een
            // scrollbaar kader zitten, zijn de boosdoeners.
            const uitstekend = [...document.querySelectorAll('body *')]
                .filter(e => {
                    const r = e.getBoundingClientRect();
                    return r.width > 0 && r.right > window.innerWidth + 2;
                })
                .filter(e => !e.closest('.table-responsive, .offcanvas, [style*="overflow"]'))
                .slice(0, 3)
                .map(e => e.tagName.toLowerCase() + (e.className ? '.' + String(e.className).split(' ')[0] : ''));
            const tabellen = [...document.querySelectorAll('.table-responsive')]
                .map(e => ({ scrollt: e.scrollWidth > e.clientWidth }));
            return { overschot, uitstekend, tabellen: tabellen.length,
                     scrollbaar: tabellen.filter(t => t.scrollt).length };
        });

        if (meting.overschot > 2) {
            console.log(`  ✗ ${pad} scrolt horizontaal (${meting.overschot}px te breed)`);
            if (meting.uitstekend.length) console.log(`      veroorzaakt door: ${meting.uitstekend.join(', ')}`);
            fout++;
        } else {
            const extra = meting.tabellen
                ? ` — ${meting.scrollbaar}/${meting.tabellen} tabel(len) scrollen zelf`
                : '';
            console.log(`  ✓ ${pad} past op het scherm${extra}`);
            goed++;
        }
    }

    await browser.close();
    console.log(`\n  ${goed} geslaagd, ${fout} mislukt\n`);
    process.exit(fout ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
