/**
 * Uploaden in delen, voor Beheer › Bestanden.
 *
 * Een videoregistratie van tientallen gigabytes past niet in één verzoek. Dit
 * script stuurt hem in stukken naar admin/upload.php; de afspraken aan de
 * serverkant staan in includes/bestand_helper.php. Het doet niets op pagina's
 * zonder [data-upload].
 *
 * Wat er mis kan gaan, en wat het script dan doet:
 *   - geen verbinding of een serverfout: na een pauze opnieuw, steeds wat
 *     langer wachten (tot een minuut), zolang het tabblad open is. Na elke
 *     derde mislukte poging worden de stukken kleiner;
 *   - de webserver vindt een stuk te groot (413): stukken halveren;
 *   - de server heeft meer of minder binnen dan gedacht (409): verder vanaf
 *     wat de server zegt;
 *   - uitgelogd of sessie verlopen (401/403): stoppen met uitleg. Wie de
 *     pagina ververst en hetzelfde bestand opnieuw kiest, gaat verder waar hij
 *     was: de server herkent het bestand aan naam, grootte en wijzigingsdatum.
 */
(function () {
    'use strict';

    const vak = document.querySelector('[data-upload]');
    if (!vak) {
        return;
    }

    const MIN_DEEL = 64 * 1024;
    const MAX_PAUZE = 60;

    const el = function (naam) {
        return vak.querySelector('[data-upload-' + naam + ']');
    };
    const invoer = el('invoer');
    const sleepvak = el('vak');
    const gekozen = el('gekozen');
    const koppelen = el('koppelen');
    const koppeling = el('koppeling');
    const voortgang = el('voortgang');
    const balk = el('balk');
    const statusTekst = el('status');
    const tijdTekst = el('tijd');
    const meldingVak = el('melding');
    const knopStart = el('start');
    const knopPauze = el('pauze');
    const knopAnnuleer = el('annuleer');

    const adres = vak.dataset.adres;
    const csrf = vak.dataset.csrf;
    const jaargang = Number(vak.dataset.jaargang);
    const extensies = (vak.dataset.extensies || '').split(',');
    const paginaTitel = document.title;
    // SHA-256 per stuk vangt beschadiging onderweg (een haperende proxy). De
    // browser rekent alleen in een beveiligde context; over http gaat het zonder.
    const kanHashen = window.isSecureContext && window.crypto && window.crypto.subtle;

    // leeg → gekozen → bezig ⇄ gepauzeerd → klaar. Na een fout die de gebruiker
    // moet oplossen, terug naar gekozen.
    let toestand = 'leeg';
    let bestand = null;
    let sleutel = null;
    let offset = 0;
    let deelgrootte = 0;
    let fouten = 0;
    let dubbelBevestigd = false;
    // Pauzeren en meteen doorgaan start een nieuwe lus; de oude herkent aan
    // dit nummer dat hij moet stoppen.
    let ronde = 0;
    let afbreker = null;
    let wekker = null;
    let metingen = [];
    let schermSlot = null;

    if (!window.fetch || !window.AbortController || !window.Blob || !Blob.prototype.slice) {
        melding('warning', 'Deze browser kan niet in delen uploaden. Gebruik een recente versie van Chrome, Edge, Firefox of Safari.');
        invoer.disabled = true;
        return;
    }

    // ── Weergave ─────────────────────────────────────────────────────────────

    /** Zelfde notatie als formatteer_bytes() in config.php. */
    function formatteerBytes(bytes) {
        if (bytes <= 0) {
            return '0 B';
        }
        const eenheden = ['B', 'KB', 'MB', 'GB', 'TB'];
        const macht = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), eenheden.length - 1);
        const cijfers = macht >= 2 ? 1 : 0;
        return (bytes / Math.pow(1024, macht)).toLocaleString('nl-NL', {
            minimumFractionDigits: cijfers,
            maximumFractionDigits: cijfers,
        }) + ' ' + eenheden[macht];
    }

    function formatteerDuur(seconden) {
        if (!isFinite(seconden) || seconden <= 0) {
            return '';
        }
        if (seconden < 60) {
            return 'nog minder dan een minuut';
        }
        const minuten = Math.round(seconden / 60);
        if (minuten < 60) {
            return 'nog ca. ' + minuten + ' min';
        }
        return 'nog ca. ' + Math.floor(minuten / 60) + ' uur ' + (minuten % 60) + ' min';
    }

    function melding(soort, tekst) {
        if (!tekst) {
            meldingVak.hidden = true;
            return;
        }
        meldingVak.className = 'alert alert-' + soort + ' py-2 small mt-3 mb-0';
        meldingVak.textContent = tekst;
        meldingVak.hidden = false;
    }

    function knoppen() {
        const loopt = toestand === 'bezig' || toestand === 'gepauzeerd';
        knopStart.hidden = loopt || toestand === 'klaar';
        knopStart.disabled = toestand !== 'gekozen';
        knopPauze.hidden = !loopt;
        knopPauze.innerHTML = toestand === 'gepauzeerd'
            ? '<i class="bi bi-play-fill me-1"></i>Doorgaan'
            : '<i class="bi bi-pause-fill me-1"></i>Pauzeren';
        knopAnnuleer.hidden = !loopt;
        invoer.disabled = loopt || toestand === 'klaar';
        sleepvak.classList.toggle('djm-upload-vak-uit', invoer.disabled);
        balk.classList.toggle('progress-bar-striped', toestand === 'bezig');
        balk.classList.toggle('progress-bar-animated', toestand === 'bezig');
    }

    function toonVoortgang() {
        const totaal = bestand.size;
        const procent = totaal ? offset / totaal * 100 : 0;
        voortgang.hidden = false;
        balk.style.width = procent.toFixed(1) + '%';
        balk.parentElement.setAttribute('aria-valuenow', String(Math.floor(procent)));
        statusTekst.textContent = formatteerBytes(offset) + ' van ' + formatteerBytes(totaal)
            + ' (' + procent.toLocaleString('nl-NL', { maximumFractionDigits: 1 }) + '%)';
        if (toestand === 'bezig') {
            document.title = '(' + Math.floor(procent) + '%) ' + paginaTitel;
        }

        // Snelheid over de laatste halve minuut, zodat de schatting niet bij
        // elk stuk heen en weer springt.
        const nu = performance.now();
        metingen.push([nu, offset]);
        while (metingen.length > 2 && nu - metingen[0][0] > 30000) {
            metingen.shift();
        }
        const snelheid = metingen.length >= 2
            ? (offset - metingen[0][1]) / ((nu - metingen[0][0]) / 1000)
            : 0;
        tijdTekst.textContent = snelheid > 0
            ? formatteerBytes(snelheid) + '/s · ' + formatteerDuur((totaal - offset) / snelheid)
            : '';
    }

    // ── Hulpjes ──────────────────────────────────────────────────────────────

    /** Wachten dat eerder afgebroken kan worden: bij pauzeren of als de verbinding terug is. */
    function wacht(seconden) {
        return new Promise(function (klaar) {
            const klok = setTimeout(klaar, seconden * 1000);
            wekker = function () {
                clearTimeout(klok);
                klaar();
            };
        });
    }

    /** Houdt het scherm aan, zodat een laptop niet in slaap valt midden in een upload. */
    async function houdWakker(aan) {
        try {
            if (schermSlot && schermSlot.released) {
                schermSlot = null;
            }
            if (aan && !schermSlot && navigator.wakeLock) {
                schermSlot = await navigator.wakeLock.request('screen');
            } else if (!aan && schermSlot) {
                await schermSlot.release();
                schermSlot = null;
            }
        } catch (e) {
            schermSlot = null;  // niet ondersteund of geweigerd; de upload werkt ook zonder
        }
    }

    async function sha256(buffer) {
        const samenvatting = await window.crypto.subtle.digest('SHA-256', buffer);
        return Array.from(new Uint8Array(samenvatting), function (b) {
            return b.toString(16).padStart(2, '0');
        }).join('');
    }

    /** Eén verzoek aan upload.php. Geeft {status, data}; status 0 = geen verbinding. */
    async function verzoek(actie, opties) {
        afbreker = new AbortController();
        const headers = { 'X-CSRF-Token': csrf, 'Accept': 'application/json' };
        let body = opties.body;
        if (opties.json) {
            headers['Content-Type'] = 'application/json';
            body = JSON.stringify(opties.json);
        } else {
            headers['Content-Type'] = 'application/octet-stream';
        }
        if (opties.sha256) {
            headers['X-Deel-Sha256'] = opties.sha256;
        }
        const zoek = new URLSearchParams(Object.assign({ actie: actie }, opties.zoek || {}));

        let antwoord;
        try {
            antwoord = await fetch(adres + '?' + zoek.toString(), {
                method: 'POST',
                headers: headers,
                body: body,
                credentials: 'same-origin',
                cache: 'no-store',
                signal: afbreker.signal,
            });
        } catch (e) {
            if (e.name === 'AbortError') {
                throw e;
            }
            return { status: 0, data: null };
        }
        let data = null;
        try {
            data = await antwoord.json();
        } catch (e) {
            data = null;  // geen JSON: een foutpagina van de webserver of een proxy
        }
        return { status: antwoord.status, data: data };
    }

    // ── Verloop ──────────────────────────────────────────────────────────────

    function kies(nieuw) {
        if (!nieuw || toestand === 'bezig' || toestand === 'gepauzeerd' || toestand === 'klaar') {
            return;
        }
        const extensie = nieuw.name.indexOf('.') >= 0 ? nieuw.name.split('.').pop().toLowerCase() : '';
        if (extensies.indexOf(extensie) < 0) {
            melding('danger', 'Dit bestandstype is niet toegestaan. Toegestaan: ' + extensies.join(', ') + '.');
            return;
        }
        bestand = nieuw;
        sleutel = null;
        offset = 0;
        deelgrootte = 0;
        dubbelBevestigd = false;
        el('naam').textContent = nieuw.name;
        el('grootte').textContent = formatteerBytes(nieuw.size);
        gekozen.hidden = false;
        voortgang.hidden = true;
        melding('', '');
        toestand = 'gekozen';
        knoppen();
    }

    function begin() {
        if (!bestand) {
            return;
        }
        toestand = 'bezig';
        ronde++;
        fouten = 0;
        metingen = [];
        melding('', '');
        knoppen();
        houdWakker(true);
        lus(ronde);
    }

    function pauzeer() {
        toestand = 'gepauzeerd';
        ronde++;
        if (afbreker) {
            afbreker.abort();
        }
        if (wekker) {
            wekker();
        }
        houdWakker(false);
        document.title = paginaTitel;
        tijdTekst.textContent = '';
        knoppen();
        melding('info', 'Gepauzeerd. Klik op Doorgaan om verder te gaan.');
    }

    /** Stoppen met een melding; de gebruiker moet eerst iets doen. */
    function stop(soort, tekst) {
        toestand = 'gekozen';
        ronde++;
        sleutel = null;
        houdWakker(false);
        document.title = paginaTitel;
        tijdTekst.textContent = '';
        knoppen();
        melding(soort, tekst);
    }

    function klaar(data) {
        toestand = 'klaar';
        houdWakker(false);
        offset = bestand.size;
        toonVoortgang();
        tijdTekst.textContent = '';
        balk.classList.add('bg-success');
        document.title = paginaTitel;
        knoppen();
        melding('success', 'Klaar: ' + data.pad + (data.gekoppeld ? ' is gekoppeld aan deze jaargang.' : ' staat in de opslagmap.'));
        setTimeout(function () {
            window.location.reload();
        }, 1500);
    }

    async function annuleer() {
        if (!window.confirm('De upload stoppen en wat er al op de server staat weggooien?')) {
            return;
        }
        const oudeSleutel = sleutel;
        toestand = 'leeg';
        ronde++;
        if (afbreker) {
            afbreker.abort();
        }
        if (wekker) {
            wekker();
        }
        houdWakker(false);
        document.title = paginaTitel;
        knoppen();
        knopStart.hidden = true;
        melding('info', 'De upload wordt geannuleerd…');

        if (oudeSleutel) {
            // Een stuk dat net nog onderweg was, houdt het bestand heel even vast (423).
            for (let poging = 0; poging < 5; poging++) {
                let r;
                try {
                    r = await verzoek('annuleren', { zoek: { sleutel: oudeSleutel }, json: {} });
                } catch (e) {
                    r = { status: 0 };
                }
                if (r.status !== 423 && r.status !== 0) {
                    break;
                }
                await new Promise(function (k) { setTimeout(k, 1000); });
            }
        }
        window.location.reload();
    }

    function afrondGegevens() {
        const veld = function (naam) {
            return koppeling.querySelector('[name="' + naam + '"]');
        };
        return {
            koppelen: koppelen.checked,
            titel: veld('titel').value,
            bestandsnaam: veld('bestandsnaam').value,
            sortering: Number(veld('sortering').value) || 0,
            actief: veld('actief').checked,
        };
    }

    async function lus(mijnRonde) {
        const actueel = function () {
            return toestand === 'bezig' && ronde === mijnRonde;
        };

        while (actueel()) {
            let stap = 'start';
            let r;
            try {
                if (!sleutel) {
                    r = await verzoek('start', {
                        json: { jaargang: jaargang, naam: bestand.name, bytes: bestand.size, gewijzigd: bestand.lastModified },
                    });
                } else if (offset >= bestand.size) {
                    stap = 'afronden';
                    r = await verzoek('afronden', { zoek: { sleutel: sleutel }, json: afrondGegevens() });
                } else {
                    stap = 'deel';
                    let inhoud = bestand.slice(offset, Math.min(offset + deelgrootte, bestand.size));
                    let hash = null;
                    if (kanHashen) {
                        inhoud = await inhoud.arrayBuffer();
                        hash = await sha256(inhoud);
                    }
                    if (!actueel()) {
                        return;
                    }
                    r = await verzoek('deel', { zoek: { sleutel: sleutel, offset: offset }, body: inhoud, sha256: hash });
                }
            } catch (e) {
                if (e && e.name === 'AbortError') {
                    return;
                }
                if (e && (e.name === 'NotReadableError' || e.name === 'NotFoundError')) {
                    stop('danger', 'Het bestand is niet meer te lezen. Staat het nog op dezelfde plek (of zit de '
                        + 'usb-schijf er nog in)? Kies het opnieuw; de upload gaat verder waar hij was.');
                    return;
                }
                r = { status: 0, data: null };
            }
            if (!actueel()) {
                return;
            }
            const data = r.data || {};

            // ── Gelukt ──
            if (r.status === 200 && r.data) {
                fouten = 0;
                if (stap === 'start') {
                    if (data.bestaat_al && data.ontvangen === 0 && !dubbelBevestigd) {
                        if (!window.confirm('In de opslagmap staat al een bestand ' + data.doel + ' van precies deze grootte.\n\n'
                            + 'Toch uploaden? Het bestaande bestand blijft staan; het nieuwe krijgt een nummer achter de naam.')) {
                            verzoek('annuleren', { zoek: { sleutel: data.sleutel }, json: {} }).catch(function () {});
                            stop('info', 'Niet geüpload: het bestand staat al in de opslagmap.');
                            return;
                        }
                        dubbelBevestigd = true;
                    }
                    sleutel = data.sleutel;
                    offset = data.ontvangen;
                    deelgrootte = deelgrootte ? Math.min(deelgrootte, data.deelgrootte) : data.deelgrootte;
                    metingen = [];
                    if (offset > 0) {
                        melding('info', 'Deze upload was al eerder begonnen en gaat verder bij ' + formatteerBytes(offset) + '.');
                    }
                    toonVoortgang();
                } else if (stap === 'deel') {
                    offset = data.ontvangen;
                    if (!meldingVak.hidden && meldingVak.classList.contains('alert-warning')) {
                        melding('', '');
                    }
                    toonVoortgang();
                } else {
                    klaar(data);
                    return;
                }
                continue;
            }

            // ── Niet gelukt ──
            if (typeof data.ontvangen === 'number') {
                offset = data.ontvangen;
            }
            if (r.status === 401 || r.status === 403) {
                stop('danger', (data.fout || 'U bent niet meer ingelogd.') + ' Ververs deze pagina, log zo nodig '
                    + 'opnieuw in en kies hetzelfde bestand: de upload gaat verder waar hij was.');
                return;
            }
            if (r.status === 409) {
                toonVoortgang();
                continue;
            }
            if ((r.status === 404 || r.status === 410) && stap !== 'start') {
                sleutel = null;  // opnieuw aanmelden; de server zoekt de upload zelf op
                continue;
            }
            if (r.status === 413) {
                if (deelgrootte <= MIN_DEEL) {
                    stop('danger', 'De webserver weigert zelfs kleine stukken. Vraag de technisch beheerder '
                        + 'de uploadlimiet (client_max_body_size of LimitRequestBody) te verhogen.');
                    return;
                }
                deelgrootte = Math.max(MIN_DEEL, Math.floor(deelgrootte / 2));
                continue;
            }
            if (r.status === 423 || r.status === 507 || (stap !== 'deel' && r.status >= 400 && r.status < 500)) {
                stop(r.status === 423 ? 'warning' : 'danger', data.fout || ('De server weigerde de upload (fout ' + r.status + ').'));
                return;
            }

            // Tijdelijk: geen verbinding, een serverfout, een beschadigd stuk.
            fouten++;
            if (stap === 'deel' && fouten % 3 === 0) {
                deelgrootte = Math.max(MIN_DEEL, Math.floor(deelgrootte / 2));
            }
            const pauze = Math.min(MAX_PAUZE, Math.pow(2, Math.min(fouten, 6)));
            const reden = r.status === 0 ? 'Geen verbinding met de server' : (data.fout || 'De server gaf fout ' + r.status);
            melding('warning', reden.replace(/\.$/, '') + '. Nieuwe poging over ' + pauze + ' seconden; u hoeft niets te doen.');
            tijdTekst.textContent = '';
            await wacht(pauze);
        }
    }

    // ── Koppelingen ──────────────────────────────────────────────────────────

    invoer.addEventListener('change', function () {
        kies(invoer.files[0]);
    });

    ['dragenter', 'dragover'].forEach(function (soort) {
        sleepvak.addEventListener(soort, function (e) {
            e.preventDefault();
            if (!invoer.disabled) {
                sleepvak.classList.add('djm-upload-vak-actief');
            }
        });
    });
    ['dragleave', 'drop'].forEach(function (soort) {
        sleepvak.addEventListener(soort, function (e) {
            e.preventDefault();
            sleepvak.classList.remove('djm-upload-vak-actief');
        });
    });
    sleepvak.addEventListener('drop', function (e) {
        if (!invoer.disabled && e.dataTransfer && e.dataTransfer.files.length) {
            kies(e.dataTransfer.files[0]);
        }
    });
    // Een bestand dat naast het vak valt, zou de browser openen en de pagina
    // (en een lopende upload) verlaten.
    window.addEventListener('dragover', function (e) { e.preventDefault(); });
    window.addEventListener('drop', function (e) { e.preventDefault(); });

    knopStart.addEventListener('click', begin);
    knopPauze.addEventListener('click', function () {
        if (toestand === 'bezig') {
            pauzeer();
        } else {
            begin();
        }
    });
    knopAnnuleer.addEventListener('click', annuleer);
    koppelen.addEventListener('change', function () {
        koppeling.disabled = !koppelen.checked;
    });

    window.addEventListener('online', function () {
        if (wekker) {
            wekker();
        }
    });
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible' && toestand === 'bezig') {
            houdWakker(true);
        }
    });
    window.addEventListener('beforeunload', function (e) {
        if (toestand === 'bezig') {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    knoppen();
})();
