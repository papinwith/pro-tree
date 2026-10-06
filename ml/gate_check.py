"""How sure must the student be before its answer can be trusted?

Re-asks a trained model about its held-out TEST photos (same split as training) and prints, for a range
of confidence thresholds, how many photos it would answer and how many of those answers are right.
Also reports the species of the catalogue (ml/species.csv) on their own.

    python ml/gate_check.py --name tree_sea --species ml/species_sea_fetched.csv --data ml/data_sea --target 0.90
"""
import argparse
import csv
import importlib.util
import json
from pathlib import Path

import torch
import torch.nn as nn
from PIL import Image
from torchvision import models, transforms as T

ROOT = Path(__file__).resolve().parent


def evaluate(name: str, species: str, data_dir: str):
    """Asks the trained model about its validation and test photos. Returns (val, test, catalogue) where val/test are
    lists of (confidence, was_right, true_species) and catalogue is the set of species named in ml/species.csv."""
    data = Path(data_dir)
    spec = importlib.util.spec_from_file_location("train_tree", ROOT / "03_train_tree.py")
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)

    meta = json.load(open(ROOT / "models" / f"{name}.labels.json", encoding="utf-8"))
    classes = meta["classes"]
    cidx = {c: i for i, c in enumerate(classes)}
    rows = [r for r in csv.DictReader(open(data / "images.csv", encoding="utf-8")) if r["gbif_label"] in cidx]
    parts = mod.split(rows, 1)

    net = models.mobilenet_v3_small()
    net.classifier[3] = nn.Linear(net.classifier[3].in_features, len(classes))
    net.load_state_dict(torch.load(ROOT / "models" / f"{name}.pt", map_location="cpu")["state_dict"])
    net.eval()
    tf = T.Compose([T.Resize(256), T.CenterCrop(224), T.ToTensor(), T.Normalize(meta["mean"], meta["std"])])

    def run(items):
        out = []
        with torch.no_grad():
            for r in items:
                img = Image.open(data / r["path"]).convert("RGB")
                img.thumbnail((320, 320))
                p = torch.softmax(net(tf(img)[None]), 1)[0]
                conf, idx = p.max(0)
                out.append((float(conf), classes[int(idx)] == r["gbif_label"], r["gbif_label"]))
        return out

    val, test = run(parts["val"]), run(parts["test"])
    catalogue = {" ".join(r["scientific"].split()[:2]) for r in csv.DictReader(open(ROOT / "species.csv", encoding="utf-8-sig"))}

    return val, test, catalogue


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--name", default="tree_sea")
    ap.add_argument("--species", default=str(ROOT / "species_sea_fetched.csv"))
    ap.add_argument("--data", default=str(ROOT / "data_sea"))
    ap.add_argument("--target", type=float, default=0.90, help="required accuracy of the answers the model gives")
    args = ap.parse_args()
    val, test, catalogue = evaluate(args.name, args.species, args.data)

    def table(label, res):
        print(f"\n{label}: {len(res)} photos, top-1 {sum(ok for _, ok, _ in res) / len(res):.1%}")
        print("  threshold  answers  right-of-answered")
        for t in (0.5, 0.6, 0.7, 0.8, 0.85, 0.9, 0.95):
            sel = [x for x in res if x[0] >= t]
            if sel:
                print(f"   {t:>5.0%}    {len(sel) / len(res):>6.1%}   {sum(ok for _, ok, _ in sel) / len(sel):>6.1%}   ({len(sel)} photos)")
            else:
                print(f"   {t:>5.0%}    {0:>6.1%}   -")

    table("TEST, all species", test)
    table("TEST, only the 27 catalogue species", [x for x in test if x[2] in catalogue])

    # the threshold the app should use: the lowest one whose accuracy reaches the target on VALIDATION photos
    # (chosen on val, then checked on test, so the test numbers stay honest)
    chosen = None
    for t in [i / 100 for i in range(50, 100)]:
        sel = [x for x in val if x[0] >= t]
        if len(sel) >= 30 and sum(ok for _, ok, _ in sel) / len(sel) >= args.target:
            chosen = t
            break
    if chosen is None:
        print(f"\nNo threshold reaches {args.target:.0%} accuracy on the validation photos: the model cannot be trusted on its own.")
        return
    sel = [x for x in test if x[0] >= chosen]
    acc = sum(ok for _, ok, _ in sel) / len(sel) if sel else float("nan")
    print(f"\nThreshold chosen on validation photos for >= {args.target:.0%}: {chosen:.0%}")
    print(f"On the TEST photos that gives: answers {len(sel) / len(test):.1%} of photos, {acc:.1%} of them right")


if __name__ == "__main__":
    main()
