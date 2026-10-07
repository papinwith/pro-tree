"""Fetches extra photos for chosen species so that each plant part (flower, fruit, leaf, bark, whole plant) is represented.
Candidates come from GBIF as in 01_fetch_images.py; CLIP (tag_organs.py's prompts) says which part each photo shows; photos that are
not of a plant are dropped; per part only as many are kept as are missing up to --per-part. New photos go to their OWN folder
(--out) with their own images.csv + organs.csv, so a training run in progress is never disturbed - merge it into the next run.

    python ml/balance_fetch.py --species ml/species_exhibit.csv --have ml/data_th --out ml/data_th_parts --per-part 40
"""
import argparse, csv, importlib.util, io, re
from collections import Counter
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
import requests, torch, open_clip
from PIL import Image

ROOT = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("fetch", ROOT / "01_fetch_images.py"); fetch = importlib.util.module_from_spec(spec); spec.loader.exec_module(fetch)
spec = importlib.util.spec_from_file_location("tag", ROOT / "tag_organs.py"); tag = importlib.util.module_from_spec(spec); spec.loader.exec_module(tag)
Image.MAX_IMAGE_PIXELS = None


def grab(c):
    urls = [c[0].replace("/original.", "/medium.", 1), c[0]] if "inaturalist-open-data" in c[0] and "/original." in c[0] else [c[0]]
    for u in urls:
        try:
            r = requests.get(u, headers=fetch.HEADERS, timeout=15); r.raise_for_status()
            im = Image.open(io.BytesIO(r.content)).convert("RGB")
            if min(im.size) < 224: return None
            im.thumbnail((512, 512)); return im
        except Exception:
            continue
    return None


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--species", required=True); ap.add_argument("--have", required=True); ap.add_argument("--out", required=True)
    ap.add_argument("--per-part", type=int, default=40); ap.add_argument("--candidates", type=int, default=250)
    ap.add_argument("--device", default="cpu")
    a = ap.parse_args()
    have, out = Path(a.have), Path(a.out); out.mkdir(parents=True, exist_ok=True)
    known = {}
    for r in csv.DictReader(open(have / "images.csv", encoding="utf-8")): known.setdefault(r["gbif_label"], set()).add(r["source_url"])
    organs_have = Counter()
    for r in csv.DictReader(open(have / "organs.csv", encoding="utf-8")): organs_have[(r["gbif_label"], r["organ"])] += 1
    model, _, pre = open_clip.create_model_and_transforms("ViT-B-32", pretrained="laion2b_s34b_b79k", device=a.device); model.eval()
    tok = open_clip.get_tokenizer("ViT-B-32")
    with torch.no_grad():
        t = model.encode_text(tok(list(tag.PARTS.values())).to(a.device)); t /= t.norm(dim=-1, keepdim=True)
    names = list(tag.PARTS)
    img_rows, org_rows = [], []
    for sp in csv.DictReader(open(a.species, encoding="utf-8-sig")):
        b = " ".join(sp["scientific"].split()[:2])
        key = int(sp["taxon_key"]) if sp.get("taxon_key") else fetch.taxon_key(b)
        need = {p: max(0, a.per_part - organs_have[(b, p)]) for p in names if p != "other"}
        cands = [c for c in fetch.photo_urls(key, a.candidates) if c[3] not in known.get(b, set())]
        with ThreadPoolExecutor(16) as pool: ims = list(pool.map(grab, cands))
        pairs = [(c, im) for c, im in zip(cands, ims) if im is not None]
        scored = []
        for i in range(0, len(pairs), 32):
            chunk = pairs[i:i + 32]
            with torch.no_grad():
                f = model.encode_image(torch.stack([pre(im) for _, im in chunk]).to(a.device)); f /= f.norm(dim=-1, keepdim=True)
                p = (100 * f @ t.T).softmax(-1)
            for (c, im), pr in zip(chunk, p):
                conf, k = pr.max(0); scored.append((c, im, names[int(k)], float(conf)))
        folder = out / "raw" / fetch.slug(b); folder.mkdir(parents=True, exist_ok=True)
        kept = Counter(); n = 0
        for c, im, part, conf in sorted(scored, key=lambda s: -s[3]):
            if part == "other" or conf < 0.45 or kept[part] >= need.get(part, 0): continue
            path = folder / f"{n:03d}.jpg"; im.save(path, quality=90)
            rel = path.relative_to(out).as_posix()
            img_rows.append({"path": rel, "gbif_label": b, "license": c[1], "creator": c[2], "source_url": c[3]})
            org_rows.append({"path": rel, "gbif_label": b, "organ": part, "confidence": f"{conf:.2f}"}); kept[part] += 1; n += 1
        print(f"{b}: candidates {len(cands)}, usable {len(pairs)}, kept {n} -> {dict(kept)}  (wanted {need})", flush=True)
    for name, rows, cols in (("images.csv", img_rows, ["path", "gbif_label", "license", "creator", "source_url"]), ("organs.csv", org_rows, ["path", "gbif_label", "organ", "confidence"])):
        with open(out / name, "w", encoding="utf-8", newline="") as fh:
            w = csv.DictWriter(fh, fieldnames=cols); w.writeheader(); w.writerows(rows)
    print("done:", len(img_rows), "new photos in", out)

if __name__ == "__main__":
    main()
