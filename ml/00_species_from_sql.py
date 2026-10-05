"""Step 0 - list the species the student model must learn.

Reads the demo species out of docs/install.sql and writes ml/species.csv
(id, name_th, name_en, name_common, scientific). Edit species.csv by hand to
add or remove species before step 1 - the scientific name is what matters.

To use the real catalogue instead, export `SELECT id, name, name_en,
name_common, name_scientific FROM species` from the database to the same
columns.
"""
import csv
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent
SQL = ROOT.parent / "docs" / "install.sql"

TOKEN = re.compile(r"'((?:[^'\\]|\\.|'')*)'|(NULL)")


def values_of(line: str) -> list:
    tail = line[line.index("VALUES (") + len("VALUES ("):]
    out = []
    for quoted, null in TOKEN.findall(tail):
        out.append(None if null else quoted.replace("\\'", "'").replace("''", "'"))
    return out


def main() -> None:
    rows = []
    for line in SQL.read_text(encoding="utf-8").splitlines():
        if not line.startswith("INSERT INTO `species` "):
            continue
        v = values_of(line)
        # id, category_code, species_code, classification_id, name, name_en, name_zh, name_common, name_scientific
        rows.append({"id": v[0], "name_th": v[4], "name_en": v[5], "name_common": v[7], "scientific": v[8]})
    with open(ROOT / "species.csv", "w", encoding="utf-8-sig", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=["id", "name_th", "name_en", "name_common", "scientific"])
        w.writeheader()
        w.writerows(rows)
    print(f"{len(rows)} species -> ml/species.csv")


if __name__ == "__main__":
    main()
