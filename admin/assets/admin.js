/**
 * Gedrag van het beheerdersgedeelte.
 *
 * Dit bestand bestaat omdat de Content-Security-Policy geen inline JavaScript
 * toestaat: geen <script>-blokken in de pagina en geen onclick="…"-attributen.
 * Alles wat vroeger in zo'n attribuut stond, staat nu in een data-attribuut en
 * wordt hier aan het element gekoppeld.
 *
 *   <select data-auto-verzenden>        verstuurt zijn formulier bij een wijziging
 *   <form data-bevestig="tekst">        vraagt eerst om bevestiging
 *   <button data-kopieer="element-id">  kopieert de tekst van dat element
 */
(function () {
    'use strict';

    // Filter- en keuzelijsten die hun formulier meteen versturen.
    document.querySelectorAll('[data-auto-verzenden]').forEach(function (veld) {
        veld.addEventListener('change', function () {
            if (veld.form) {
                veld.form.submit();
            }
        });
    });

    // Formulieren die eerst een bevestiging vragen. De tekst staat in het
    // data-attribuut; regeleindes zijn daar als &#10; geschreven.
    document.querySelectorAll('form[data-bevestig]').forEach(function (formulier) {
        formulier.addEventListener('submit', function (gebeurtenis) {
            if (!window.confirm(formulier.getAttribute('data-bevestig'))) {
                gebeurtenis.preventDefault();
            }
        });
    });

    // Kopieerknoppen bij de serverconfiguratieblokken. navigator.clipboard
    // bestaat alleen in een beveiligde context; tijdens de installatie draait
    // het beheer vaak nog op http, dus is er een terugval met een tijdelijk
    // tekstveld.
    document.querySelectorAll('[data-kopieer]').forEach(function (knop) {
        knop.addEventListener('click', function () {
            var doel = document.getElementById(knop.dataset.kopieer);
            if (!doel) {
                return;
            }
            var tekst = doel.innerText;
            var gelukt = function () {
                var oud = knop.innerHTML;
                knop.innerHTML = '<i class="bi bi-check2 me-1"></i>Gekopieerd';
                setTimeout(function () { knop.innerHTML = oud; }, 1500);
            };

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(tekst).then(gelukt);
                return;
            }

            var veld = document.createElement('textarea');
            veld.value = tekst;
            veld.setAttribute('readonly', '');
            veld.style.position = 'absolute';
            veld.style.left = '-9999px';
            document.body.appendChild(veld);
            veld.select();
            try {
                document.execCommand('copy');
                gelukt();
            } catch (e) {
                /* niets: de beheerder kan de tekst ook met de hand selecteren */
            }
            document.body.removeChild(veld);
        });
    });
})();
