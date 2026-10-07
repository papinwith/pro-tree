"""Tags every photo with the plant part it mostly shows (flower / fruit / leaf / bark / whole plant / other) using CLIP zero-shot,
so the training set can be balanced instead of being whatever people happened to photograph.

    python ml/tag_organs.py --data ml/data_th --species ml/species_exhibit.csv      # writes <data>/organs.csv (path,organ,confidence)
Runs on the CPU by default so it does not fight the trainer for the GPU (--device cuda to change)."""
import argparse, csv
from pathlib import Path
import torch, open_clip
from PIL import Image

PARTS = {
    "flower": "a close-up photo of a flower or flower buds of a plant",
    "fruit": "a close-up photo of fruit or seeds on a plant",
    "leaf": "a close-up photo of the leaves of a plant",
    "bark": "a close-up photo of the bark or trunk of a tree",
    "whole": "a photo of a whole tree or whole plant in its surroundings",
    "other": "a photo that is not mainly of a plant, such as a person, an animal, a building or a signboard",
}

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--data", required=True)
    ap.add_argument("--species", default="", help="only tag photos of the species in this CSV (default: all)")
    ap.add_argument("--device", default="cpu")
    ap.add_argument("--out", default="")
    a = ap.parse_args()
    data = Path(a.data)
    only = {" ".join(r["scientific"].split()[:2]) for r in csv.DictReader(open(a.species, encoding="utf-8-sig"))} if a.species else None
    rows = [r for r in csv.DictReader(open(data / "images.csv", encoding="utf-8")) if only is None or r["gbif_label"] in only]
    model, _, pre = open_clip.create_model_and_transforms("ViT-B-32", pretrained="laion2b_s34b_b79k", device=a.device)
    tok = open_clip.get_tokenizer("ViT-B-32"); model.eval()
    with torch.no_grad():
        t = model.encode_text(tok(list(PARTS.values())).to(a.device)); t /= t.norm(dim=-1, keepdim=True)
    out = Path(a.out) if a.out else data / "organs.csv"
    names = list(PARTS)
    with open(out, "w", encoding="utf-8", newline="") as fh:
        w = csv.writer(fh); w.writerow(["path", "gbif_label", "organ", "confidence"])
        for i in range(0, len(rows), 32):
            chunk = rows[i:i + 32]; ims = []
            for r in chunk:
                try: ims.append(pre(Image.open(data / r["path"]).convert("RGB")))
                except Exception: ims.append(torch.zeros(3, 224, 224))
            with torch.no_grad():
                f = model.encode_image(torch.stack(ims).to(a.device)); f /= f.norm(dim=-1, keepdim=True)
                p = (100 * f @ t.T).softmax(-1)
            for r, pr in zip(chunk, p):
                c, k = pr.max(0); w.writerow([r["path"], r["gbif_label"], names[int(k)], f"{float(c):.2f}"])
            print(f"{min(i + 32, len(rows))}/{len(rows)}", flush=True)

if __name__ == "__main__":
    main()
