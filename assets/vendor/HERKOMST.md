# Meegeleverde bibliotheken

Deze map bevat onveranderde kopieën van Bootstrap en Bootstrap Icons. Ze staan
hier zodat het portaal geen CDN nodig heeft: valt het internet weg, blokkeert
een firewall jsdelivr of verdwijnt er een versie, dan blijft de site werken.
Ook de Content-Security-Policy in `config.php` kan daardoor alle externe bronnen
weigeren.

| Bestand | Versie | Bron |
| --- | --- | --- |
| `bootstrap.min.css` | 5.3.3 | `https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css` |
| `bootstrap.bundle.min.js` | 5.3.3 | `https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js` |
| `bootstrap-icons.css` | 1.11.3 | `https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css` |
| `fonts/bootstrap-icons.woff2` | 1.11.3 | `https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff2` |

Licentie: MIT (Bootstrap en Bootstrap Icons, © The Bootstrap Authors).

## Eén wijziging

Uit `bootstrap-icons.css` is de terugval naar `bootstrap-icons.woff` verwijderd.
Dat bestand leveren we niet mee — elke browser van na 2015 gebruikt toch woff2 —
en zonder die aanpassing zou een oude browser een bestand opvragen dat er niet
is.

## Bijwerken

Nieuwe versie ophalen en de controlegetallen vergelijken met `SHA256SUMS`:

    cd assets/vendor
    B=https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist
    I=https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font
    curl -sSfo bootstrap.min.css        "$B/css/bootstrap.min.css"
    curl -sSfo bootstrap.bundle.min.js  "$B/js/bootstrap.bundle.min.js"
    curl -sSfo bootstrap-icons.css      "$I/bootstrap-icons.min.css"
    curl -sSfo fonts/bootstrap-icons.woff2 "$I/fonts/bootstrap-icons.woff2"
    sha256sum -c SHA256SUMS

Haal daarna de woff-terugval opnieuw uit `bootstrap-icons.css`, werk `SHA256SUMS`
bij met `sha256sum bootstrap* fonts/* > SHA256SUMS`, en verhoog `VERSION` in de
projectroot — dat versienummer staat achter elke stijl-URL en zorgt ervoor dat
browsers het nieuwe bestand ophalen in plaats van het oude uit hun cache.
