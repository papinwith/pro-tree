"""Put a trained model on the website - but only if it is good enough.

Measures the model on photos it never trained on (same check as ml/gate_check.py) and refuses to publish unless,
at the website's confidence gate (default 90 %), the answers it would give are right at least --target of the time
(default 90 %) over at least --min-answers photos. If it passes, copies the model, the label file (with the gate)
and the photographer credits into public/assets/models/, where tree-model.js picks them up after the next deploy.

    python ml/publish_model.py --name tree_sea
    python ml/publish_model.py --name tree_sea --dry-run      # only report
"""
import argparse
import csv
import importlib.util
import json
import shutil
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent
OUT = ROOT.parent / "public" / "assets" / "models"


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--name", default="tree_sea")
    ap.add_argument("--species", default=str(ROOT / "species_sea_fetched.csv"))
    ap.add_argument("--data", default=str(ROOT / "data_sea"))
    ap.add_argument("--gate", type=float, default=0.90, help="confidence the website requires before it trusts the model")
    ap.add_argument("--target", type=float, default=0.90, help="required accuracy of the answers given at the gate")
    ap.add_argument("--min-answers", type=int, default=100, help="how many test photos must reach the gate for the figure to mean anything")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    spec = importlib.util.spec_from_file_location("gate_check", ROOT / "gate_check.py")
    gate = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(gate)
    _, test, _ = gate.evaluate(args.name, args.species, args.data)

    sel = [x for x in test if x[0] >= args.gate]
    acc = sum(ok for _, ok, _ in sel) / len(sel) if sel else 0.0
    print(f"{args.name}: {len(test)} test photos, top-1 {sum(ok for _, ok, _ in test) / len(test):.1%}")
    print(f"at the {args.gate:.0%} gate it would answer {len(sel) / len(test):.1%} of photos ({len(sel)}), {acc:.1%} of them right")
    if len(sel) < args.min_answers:
        sys.exit(f"NOT PUBLISHED: only {len(sel)} test photos reach the gate (need {args.min_answers}) - too few to trust the accuracy figure.")
    if acc < args.target:
        sys.exit(f"NOT PUBLISHED: {acc:.1%} is below the required {args.target:.0%}.")
    print(f"OK: meets the {args.target:.0%} requirement.")
    if args.dry_run:
        return

    meta = json.load(open(ROOT / "models" / f"{args.name}.labels.json", encoding="utf-8"))
    meta["name"] = "tree"
    meta["app_threshold"] = args.gate
    meta.pop("confidence_threshold", None)
    meta["published_check"] = {"test_photos": len(test), "answers_at_gate": len(sel), "accuracy_at_gate": round(acc, 4)}
    OUT.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(ROOT / "models" / f"{args.name}.onnx", OUT / "tree.onnx")
    json.dump(meta, open(OUT / "tree.labels.json", "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    rows = list(csv.DictReader(open(Path(args.data) / "images.csv", encoding="utf-8")))
    classes = set(meta["classes"])
    with open(OUT / "credits.csv", "w", encoding="utf-8-sig", newline="") as fh:
        w = csv.writer(fh)
        w.writerow(["species", "license", "photographer", "source"])
        for r in rows:
            if r["gbif_label"] in classes:
                w.writerow([r["gbif_label"], r["license"], r["creator"], r["source_url"]])
    print(f"Published to {OUT}. Run tests/tree_browser_check.py, then commit and push to deploy.")


if __name__ == "__main__":
    main()
