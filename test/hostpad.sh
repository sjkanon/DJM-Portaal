#!/usr/bin/env bash
# Paden doorgeven aan Docker, ook vanuit Git Bash op Windows.
#
# Docker krijgt zijn paden van het besturingssysteem, niet van de shell. Dat
# levert onder Git Bash twee problemen op, allebei stil:
#
#   1. De werkmap heet daar /c/Users/… en dat pad bestaat alleen binnen msys.
#      Docker maakt er een lege map van, de container ziet niets en elke test
#      faalt met "Could not open input file".
#   2. Omgekeerd vertaalt msys ook het pad ín de container: van `-v …:/app`
#      maakt hij `-v …:C:/Program Files/Git/app`. Dat maakt zelfs echte mappen
#      aan op de plek waar je toevallig staat.
#
# hostpad() lost het eerste op, MSYS2_ARG_CONV_EXCL het tweede. De lijst noemt
# alleen containerpaden: /tmp blijft wél vertaald, want daar haalt curl zijn
# cookiejar vandaan en die krijgt hij als Windows-pad aangereikt.
#
# Op Linux en macOS doet dit bestand niets: cygpath bestaat daar niet en de
# omgevingsvariabele wordt door niets gelezen.

hostpad() {
    if command -v cygpath >/dev/null 2>&1; then
        cygpath -m "$1"
    else
        printf '%s' "$1"
    fi
}

# Ook formulierwaarden die op een pad lijken moeten met rust gelaten worden.
# `XACCEL_PREFIX=/beveiligd/` werd anders `XACCEL_PREFIX=C:/Program Files/Git/beveiligd/`,
# waarna de installatiewizard hem terecht weigerde en zwijgend niets opsloeg —
# vijfentwintig testfouten die niets met de code te maken hadden. Let op: voor
# zo'n `VAR=/pad`-argument moet de uitsluiting de naam mét isgelijkteken zijn;
# alleen het pad in de lijst zetten helpt niet, want msys vergelijkt met het
# hele argument.
#
# /tmp staat er bewust níét tussen: curl krijgt zijn cookiejar juist als
# Windows-pad aangereikt.
export MSYS2_ARG_CONV_EXCL='/app;/verse;/shots;/usr/src/app;/var/www;XACCEL_PREFIX=;OPSLAG_PAD='
