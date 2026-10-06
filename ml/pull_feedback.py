"""Download the approved training photos collected by the website and add them to the training set.

The website keeps photos on which the teachers agreed (or an admin confirmed) in its database; this script fetches
the new ones through admin/training_samples_export.php and appends them to ml/data_sea/images.csv, after which you
retrain with ml/03_train_tree.py (see ml/README.md). A species the model does not know yet is only added to the
class list once it has --min-photos approved photos (a species with one or two photos cannot be learned).

    set TRAINING_EXPORT_TOKEN=<the same value as on the website>
    python ml/pull_feedback.py --url https://YOUR-SITE/admin/training_samples_export.php

State: ml/data_sea/feedback_last_id.txt remembers the last id downloaded, so reruns only fetch new photos.
"""
import argparse
import base64
import csv
import io
import os
import re
import sys
from pathlib import Path

import requests
from PIL import Image

ROOT = Path(__file__).resolve().parent
LICENSE = "exhibition-upload"


def slug(name: str) -> str:
    return re.sub(r"[^a-z0-9]+", "_", name.lower()).strip("_")


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--url", default=os.environ.get("FEEDBACK_URL", ""), help="the website's admin/training_samples_export.php")
    ap.add_argument("--data", default=str(ROOT / "data_sea"))
    ap.add_argument("--species", default=str(ROOT / "species_sea_fetched.csv"), help="the class list used for training")
    ap.add_argument("--min-photos", type=int, default=8)
    args = ap.parse_args()
    token = os.environ.get("TRAINING_EXPORT_TOKEN", "")
    if not args.url or len(token) < 24:
        sys.exit("Give --url (or FEEDBACK_URL) and set TRAINING_EXPORT_TOKEN to the website's token (at least 24 characters).")

    data = Path(args.data)
    state = data / "feedback_last_id.txt"
    last = int(state.read_text().strip()) if state.exists() else 0
    index = data / "images.csv"
    rows = list(csv.DictReader(open(index, encoding="utf-8"))) if index.exists() else []
    known_paths = {r["path"] for r in rows}
    new_rows = 0

    while True:
        r = requests.get(args.url, params={"since": last, "limit": 50}, headers={"Authorization": f"Bearer {token}"}, timeout=120)
        if r.status_code != 200:
            sys.exit(f"The website answered HTTP {r.status_code} (wrong URL or token, or the export is switched off).")
        samples = r.json().get("samples", [])
        if not samples:
            break
        for s in samples:
            name = s["name_scientific"]
            rel = f"raw/_feedback/{slug(name)}/{int(s['id']):06d}.jpg"
            last = max(last, int(s["id"]))
            if rel in known_paths:
                continue
            try:
                img = Image.open(io.BytesIO(base64.b64decode(s["image_b64"]))).convert("RGB")
            except Exception as e:
                print(f"  skipped sample {s['id']}: unreadable photo ({e})")
                continue
            img.thumbnail((512, 512))
            out = data / rel
            out.parent.mkdir(parents=True, exist_ok=True)
            img.save(out, quality=90)
            rows.append({"path": rel, "gbif_label": name, "license": LICENSE, "creator": "exhibition visitors/admins", "source_url": f"training_sample:{s['id']}"})
            known_paths.add(rel)
            new_rows += 1
        state.write_text(str(last))

    with open(index, "w", encoding="utf-8", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=["path", "gbif_label", "license", "creator", "source_url"])
        w.writeheader()
        w.writerows(rows)

    # species that now have enough photos join the class list
    counts = {}
    for row in rows:
        counts[row["gbif_label"]] = counts.get(row["gbif_label"], 0) + 1
    species_path = Path(args.species)
    species = list(csv.DictReader(open(species_path, encoding="utf-8-sig")))
    have = {" ".join(s["scientific"].split()[:2]) for s in species}
    added, waiting = [], []
    for name, n in sorted(counts.items()):
        if name in have:
            continue
        (added if n >= args.min_photos else waiting).append((name, n))
    for name, n in added:
        species.append({"id": len(species) + 1, "name_th": "", "name_en": "", "name_common": "", "scientific": name, "family": "", "photos": n})
    if added:
        fields = list(species[0].keys())
        with open(species_path, "w", encoding="utf-8-sig", newline="") as fh:
            w = csv.DictWriter(fh, fieldnames=fields, extrasaction="ignore")
            w.writeheader()
            w.writerows(species)

    print(f"{new_rows} new photos downloaded (last id {last}); {len(rows)} photos in {index}")
    if added:
        print(f"{len(added)} new species joined the class list:", ", ".join(f"{n} ({c})" for n, c in added))
    if waiting:
        print(f"{len(waiting)} species still waiting for {args.min_photos}+ photos:", ", ".join(f"{n} ({c})" for n, c in waiting))
    print("Now retrain:  python ml/03_train_tree.py --source gbif --species", species_path, "--data", data, "--name tree_sea")


if __name__ == "__main__":
    main()
