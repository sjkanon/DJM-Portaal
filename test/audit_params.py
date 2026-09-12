#!/usr/bin/env python3
"""
Zoekt named parameters die meer dan één keer in dezelfde query voorkomen.

Met PDO::ATTR_EMULATE_PREPARES => false mag dat niet: MySQL bindt elke naam
maar één keer, en de query faalt met SQLSTATE[HY093]. Dat gaat pas mis zodra
iemand het zoekveld echt gebruikt, dus het is met de hand makkelijk te missen.
"""
import re
import sys
from pathlib import Path

AANROEP = re.compile(r'\b(?:prepare|query|exec)\s*\(', re.I)


def query_tekst(bron: str, start: int) -> tuple[str, int]:
    """Leest de aaneengeschakelde tekstdelen binnen één aanroep."""
    diepte, i, delen = 0, start, []
    while i < len(bron):
        teken = bron[i]
        if teken == '(':
            diepte += 1
        elif teken == ')':
            diepte -= 1
            if diepte == 0:
                break
        elif teken in ('"', "'"):
            aanhaling, i = teken, i + 1
            deel = []
            while i < len(bron) and bron[i] != aanhaling:
                if bron[i] == '\\':
                    i += 1
                deel.append(bron[i])
                i += 1
            delen.append(''.join(deel))
        i += 1
    return ' '.join(delen), i


def main() -> int:
    bevindingen = 0
    for pad in sorted(Path('.').rglob('*.php')):
        if '.git' in pad.parts or 'test' in pad.parts:
            continue
        bron = pad.read_text(encoding='utf-8', errors='replace')
        for treffer in AANROEP.finditer(bron):
            tekst, _ = query_tekst(bron, treffer.end() - 1)
            if not re.search(r'\b(SELECT|UPDATE|DELETE|INSERT)\b', tekst, re.I):
                continue
            namen = re.findall(r':([a-zA-Z_][a-zA-Z0-9_]*)', tekst)
            for naam in sorted(set(namen)):
                if namen.count(naam) > 1:
                    regel = bron[:treffer.start()].count('\n') + 1
                    print(f"  ✗ {pad}:{regel}  :{naam} komt {namen.count(naam)}x "
                          f"voor in dezelfde query")
                    bevindingen += 1
    if bevindingen == 0:
        print("  ✓ elke named parameter komt maar één keer per query voor")
    return bevindingen


if __name__ == '__main__':
    sys.exit(1 if main() else 0)
