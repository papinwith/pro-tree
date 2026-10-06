"""Step 1 - download openly licensed photos of each species from GBIF.

Only CC0 and CC-BY photos are kept (these come mostly from iNaturalist
research-grade observations). The GBIF species name that each photo was
filed under is saved as `gbif_label` - that is the independent "answer key"
used to grade both the teacher (Qwen) and the student (tree).

Output: ml/data/raw/<slug>/<n>.jpg (longest side 512 px) and
ml/data/images.csv (path, gbif_label, license, creator, source_url).
Resumable: species that already have enough photos are skipped.
"""
import argparse
import csv
import io
import re
import time
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

import requests
from PIL import Image

ROOT = Path(__file__).resolve().parent
DATA = ROOT / "data"
API = "https://api.gbif.org/v1"
LICENSES = ["CC0_1_0", "CC_BY_4_0"]
HEADERS = {"User-Agent": "tree-siam-ml/1.0 (student project; papinwith01@gmail.com)"}


def slug(name: str) -> str:
    return re.sub(r"[^a-z0-9]+", "_", name.lower()).strip("_")


def taxon_key(name: str):
    r = requests.get(f"{API}/species/match", params={"name": name}, headers=HEADERS, timeout=30)
    r.raise_for_status()
    j = r.json()
    if j.get("matchType") in ("NONE",) or "usageKey" not in j:
        return None
    return j["usageKey"]


def photo_urls(key: int, want: int):
    """Yield (url, license, creator, occurrence_url) for up to ~want*3 candidates."""
    offset, seen = 0, set()
    while offset < want * 6:
        r = requests.get(
            f"{API}/occurrence/search",
            params={"taxonKey": key, "mediaType": "StillImage", "license": LICENSES, "limit": 100, "offset": offset},
            headers=HEADERS,
            timeout=60,
        )
        r.raise_for_status()
        j = r.json()
        for occ in j.get("results", []):
            for m in occ.get("media", []):
                url = m.get("identifier")
                if m.get("type") == "StillImage" and url and url not in seen:
                    seen.add(url)
                    yield url, m.get("license") or occ.get("license"), m.get("creator") or m.get("rightsHolder") or "", f"https://www.gbif.org/occurrence/{occ.get('key')}"
        if j.get("endOfRecords"):
            break
        offset += 100


def with_retries(fn, *args, tries=4):
    """GBIF/CDN calls occasionally fail with a transient network or TLS error."""
    for attempt in range(tries):
        try:
            return fn(*args)
        except requests.RequestException as e:
            if attempt == tries - 1:
                raise
            print(f"  retry after {type(e).__name__}")
            time.sleep(3 * (attempt + 1))


def main() -> None:
    global DATA
    ap = argparse.ArgumentParser()
    ap.add_argument("--per-species", type=int, default=40)
    ap.add_argument("--min-side", type=int, default=224, help="skip photos smaller than this")
    ap.add_argument("--species", default=str(ROOT / "species.csv"), help="species CSV (needs a scientific column; taxon_key is used if present)")
    ap.add_argument("--data", default=str(DATA), help="folder for photos and images.csv")
    ap.add_argument("--threads", type=int, default=16)
    args = ap.parse_args()

    DATA = Path(args.data)
    species = list(csv.DictReader(open(args.species, encoding="utf-8-sig")))
    DATA.mkdir(parents=True, exist_ok=True)
    index_path = DATA / "images.csv"
    rows = list(csv.DictReader(open(index_path, encoding="utf-8"))) if index_path.exists() else []
    have = {}
    for r in rows:
        have[r["gbif_label"]] = have.get(r["gbif_label"], 0) + 1

    for sp in species:
        name = sp["scientific"]
        # genus + species only, e.g. "Cassia fistula"
        binomial = " ".join(name.split()[:2])
        if have.get(binomial, 0) >= args.per_species:
            print(f"skip   {binomial}: already {have[binomial]}")
            continue
        try:
            key = int(sp["taxon_key"]) if sp.get("taxon_key") else with_retries(taxon_key, binomial.replace(" sp.", ""))  # "Vanda sp." -> whole genus
        except requests.RequestException as e:
            print(f"FAILED {binomial}: {type(e).__name__} - run the script again to retry")
            continue
        if key is None:
            print(f"NOTFND {binomial}: not found in GBIF")
            continue
        out_dir = DATA / "raw" / slug(binomial)
        out_dir.mkdir(parents=True, exist_ok=True)
        got = have.get(binomial, 0)
        cands = []
        known_sources = {r["source_url"] for r in rows if r.get("gbif_label") == binomial}  # top-ups must not download the same photo twice
        try:
            for c in photo_urls(key, args.per_species):
                if c[3] in known_sources:
                    continue
                cands.append(c)
                if len(cands) >= args.per_species * 3:
                    break
        except requests.RequestException as e:
            print(f"  {binomial}: photo list interrupted ({type(e).__name__}); using the {len(cands)} found")

        def grab(c):
            try:
                resp = requests.get(c[0], headers=HEADERS, timeout=15)
                resp.raise_for_status()
                img = Image.open(io.BytesIO(resp.content)).convert("RGB")
            except Exception:
                return None
            if min(img.size) < args.min_side:
                return None
            img.thumbnail((512, 512))
            return img

        with ThreadPoolExecutor(max_workers=args.threads) as pool:
            for c, img in zip(cands, pool.map(grab, cands)):
                if img is None or got >= args.per_species:
                    continue
                path = out_dir / f"{got:03d}.jpg"
                img.save(path, quality=90)
                rows.append({"path": path.relative_to(DATA).as_posix(), "gbif_label": binomial, "license": c[1], "creator": c[2], "source_url": c[3]})
                got += 1
        have[binomial] = got
        print(f"fetched {binomial}: {got} photos")
        with open(index_path, "w", encoding="utf-8", newline="") as fh:
            w = csv.DictWriter(fh, fieldnames=["path", "gbif_label", "license", "creator", "source_url"])
            w.writeheader()
            w.writerows(rows)

    print(f"\n{len(rows)} photos total in {index_path}")


if __name__ == "__main__":
    main()
