"""Turns the campus tree survey (trees.csv + photos/) into training photos, using ONLY rows where the surveyor wrote a scientific
name that is a real species/genus name; test entries ("Xx", "G1"...) and family-level or one-photo names are left out.
    python ml/import_survey.py --csv trees.csv --out ml/data_th_survey
"""
import argparse, csv, re
from pathlib import Path
from PIL import Image

USE = {"Phoenix sp.": "Phoenix sp."}   # surveyor's name -> class name (genus-level class, species unknown)

def main():
    ap = argparse.ArgumentParser(); ap.add_argument("--csv", default="trees.csv"); ap.add_argument("--out", default="ml/data_th_survey")
    a = ap.parse_args(); out = Path(a.out)
    rows = []
    for r in csv.DictReader(open(a.csv, encoding="utf-8-sig")):
        label = USE.get(r["scientific_name"].strip())
        if not label: continue
        for k in ("photo", "context_photo"):
            if r[k] and Path(r[k]).exists(): rows.append((label, r["tree_code"], k, Path(r[k])))
    index = []
    for i, (label, code, kind, src) in enumerate(rows):
        folder = out / "raw" / re.sub(r"[^a-z0-9]+", "_", label.lower()).strip("_"); folder.mkdir(parents=True, exist_ok=True)
        im = Image.open(src).convert("RGB"); im.thumbnail((512, 512))
        dst = folder / f"survey_{i:03d}.jpg"; im.save(dst, quality=90)
        index.append({"path": dst.relative_to(out).as_posix(), "gbif_label": label, "license": "own", "creator": "Siam University tree survey", "source_url": f"survey:{code}:{kind}"})
    with open(out / "images.csv", "w", encoding="utf-8", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=["path", "gbif_label", "license", "creator", "source_url"]); w.writeheader(); w.writerows(index)
    print(len(index), "survey photos ->", out)

if __name__ == "__main__":
    main()
