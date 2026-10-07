"""Step 0b - build a species list from what GBIF has the most open photos of.

By default: plants photographed in Thailand first, then filled up from its
neighbours (Vietnam, Malaysia, Laos, Cambodia, Myanmar, Singapore, Indonesia),
so the list is the plants a visitor in Thailand is most likely to meet. Only
species-rank taxa with CC0 / CC-BY photos are counted (those are the photos
step 1 can use).

    python ml/00b_species_from_gbif.py --count 300 --out ml/species_sea.csv

Output columns match ml/species.csv, plus taxon_key (GBIF speciesKey) and family.
"""
import argparse
import csv
from pathlib import Path

import requests
from concurrent.futures import ThreadPoolExecutor

API = "https://api.gbif.org/v1"
HEADERS = {"User-Agent": "tree-siam-ml/1.0 (student project)"}
PRIMARY = ["TH"]
NEIGHBOURS = ["VN", "MY", "LA", "KH", "MM", "SG", "ID"]
PLANTAE = 6


def facet(countries, limit):
    params = {"mediaType": "StillImage", "kingdomKey": PLANTAE, "rank": "SPECIES", "license": ["CC0_1_0", "CC_BY_4_0"],
              "country": countries, "facet": "speciesKey", "facetLimit": limit, "limit": 0}
    r = requests.get(f"{API}/occurrence/search", params=params, headers=HEADERS, timeout=120)
    r.raise_for_status()
    return [(int(c["name"]), c["count"]) for c in r.json()["facets"][0]["counts"]]


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--count", type=int, default=300)
    ap.add_argument("--out", default="ml/species_sea.csv")
    ap.add_argument("--min-photos", type=int, default=120, help="skip species with fewer open photos in the chosen countries")
    ap.add_argument("--include", default="", help="CSV (with a scientific column) of species that must be in the list, e.g. ml/species.csv")
    ap.add_argument("--thai-only", action="store_true", help="only species photographed in Thailand itself (no neighbouring countries)")
    args = ap.parse_args()

    counts = {}
    for k, c in facet(PRIMARY, args.count * 2):
        counts[k] = c
    if not args.thai_only:
        for k, c in facet(PRIMARY + NEIGHBOURS, args.count * 3):
            counts.setdefault(k, c)
    keys = [k for k in counts if counts[k] >= args.min_photos]

    def describe(k):
        for _ in range(3):
            try:
                info = requests.get(f"{API}/species/{k}", headers=HEADERS, timeout=30).json()
                name = info.get("canonicalName") or ""
                if len(name.split()) != 2:  # plain binomials only
                    return None
                v = requests.get(f"{API}/species/{k}/vernacularNames", params={"limit": 50}, headers=HEADERS, timeout=30).json().get("results", [])
                vern = next((x["vernacularName"] for x in v if x.get("language") == "eng"), "")
                return {"name_th": "", "name_en": vern, "name_common": vern, "scientific": name, "taxon_key": k,
                        "family": info.get("family", ""), "open_photos": counts[k]}
            except Exception:
                continue
        return None

    rows, seen = [], set()
    if args.include:  # the catalogue's own species always come first, whatever GBIF's ranking says
        for want in csv.DictReader(open(args.include, encoding="utf-8-sig")):
            name = " ".join(want["scientific"].split()[:2])
            m = requests.get(f"{API}/species/match", params={"name": name.replace(" sp.", ""), "rank": "SPECIES" if " sp." not in name else "GENUS"},
                             headers=HEADERS, timeout=30).json()
            key = m.get("speciesKey") or m.get("usageKey")
            if not key or not m.get("canonicalName"):
                print(f"  could not match {name} in GBIF - skipped")
                continue
            counts.setdefault(int(key), 0)
            info = describe(int(key))
            if info is None:  # genus-only entries such as "Vanda sp." keep their catalogue name
                info = {"name_th": want.get("name_th", ""), "name_en": want.get("name_en", ""), "name_common": want.get("name_common", ""),
                        "scientific": name, "taxon_key": int(key), "family": m.get("family", ""), "open_photos": 0}
            info["name_th"] = want.get("name_th", "") or info["name_th"]
            if info["scientific"].lower() not in seen:
                seen.add(info["scientific"].lower())
                rows.append({"id": len(rows) + 1, **info})
        print(f"  {len(rows)} catalogue species kept")
    with ThreadPoolExecutor(max_workers=12) as pool:
        for info in pool.map(describe, keys[: args.count * 2]):  # keep the TH-first order
            if info is None or info["scientific"].lower() in seen:
                continue
            seen.add(info["scientific"].lower())
            rows.append({"id": len(rows) + 1, **info})
            if len(rows) >= args.count:
                break
    for i, r in enumerate(rows, 1):
        r["id"] = i

    out = Path(args.out)
    with open(out, "w", encoding="utf-8-sig", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=list(rows[0]))
        w.writeheader()
        w.writerows(rows)
    print(f"{len(rows)} species -> {out}")
    for r in rows[:15]:
        print(f"  {r['scientific']:32s} {r['family']:16s} {r['name_common']}")


if __name__ == "__main__":
    main()
