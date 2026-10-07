"""Merges several photo folders into one training folder WITHOUT copying photos: the new images.csv points to the others with
relative paths ("../data_th/raw/..."). Also writes the matching species list.
    python ml/merge_data.py --out ml/data_th4 --species ml/species_th.csv --extra-species ml/species_user_extra.csv data_th data_th_parts data_th_phoenix data_th_survey"""
import argparse, csv
from pathlib import Path

def main():
    ap = argparse.ArgumentParser(); ap.add_argument("sources", nargs="+"); ap.add_argument("--out", required=True)
    ap.add_argument("--species", required=True); ap.add_argument("--extra-species", default="")
    a = ap.parse_args(); out = Path(a.out); out.mkdir(parents=True, exist_ok=True); root = out.parent
    rows, seen = [], set()
    for s in a.sources:
        f = root / s / "images.csv"
        if not f.exists(): print("skip (no images.csv):", s); continue
        n = 0
        for r in csv.DictReader(open(f, encoding="utf-8")):
            key = r["source_url"]
            if key in seen or not (root / s / r["path"]).exists(): continue
            seen.add(key); r["path"] = f"../{s}/{r['path']}"; rows.append(r); n += 1
        print(f"{s}: {n} photos")
    with open(out / "images.csv", "w", encoding="utf-8", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=["path", "gbif_label", "license", "creator", "source_url"]); w.writeheader(); w.writerows(rows)
    sp = list(csv.DictReader(open(a.species, encoding="utf-8-sig")))
    have = {" ".join(r["scientific"].split()[:2]) for r in sp}
    if a.extra_species:
        for r in csv.DictReader(open(a.extra_species, encoding="utf-8-sig")):
            if " ".join(r["scientific"].split()[:2]) not in have: sp.append({**{k: "" for k in sp[0]}, **{k: v for k, v in r.items() if k in sp[0]}})
    for i, r in enumerate(sp, 1): r["id"] = i
    with open(out.parent / (out.name + "_species.csv"), "w", encoding="utf-8-sig", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=list(sp[0])); w.writeheader(); w.writerows(sp)
    print(len(rows), "photos,", len(sp), "species ->", out)

if __name__ == "__main__":
    main()
