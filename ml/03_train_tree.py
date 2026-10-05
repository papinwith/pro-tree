"""Step 3 - train the student model "tree" from the teacher's labels.

Student: MobileNetV3-Small (ImageNet weights), fine-tuned on the photos with
the labels Qwen gave them (teacher_labels.csv; photos Qwen answered "none" for
are left out). Photos are split 70/15/15 into train/val/test, per species.

Grading: the test split is scored against the GBIF label (the independent
answer key), and also against the teacher label, so you can see how much of the
student's errors were inherited from the teacher.

Output in ml/models/: tree.pt, tree.onnx, tree.labels.json (class names, input
size, normalisation and the confidence threshold below which the model should
say "not sure" instead of answering).
"""
import argparse
import csv
import json
import random
import time
from collections import Counter, defaultdict
from pathlib import Path

import numpy as np
import torch
import torch.nn as nn
from PIL import Image
from torchvision import models, transforms as T

ROOT = Path(__file__).resolve().parent
DATA = ROOT / "data"
OUT = ROOT / "models"
SIZE = 224
MEAN, STD = [0.485, 0.456, 0.406], [0.229, 0.224, 0.225]


class PhotoSet(torch.utils.data.Dataset):
    def __init__(self, items, train: bool):
        self.items = items  # (path to a photo, class index) - photos are read from disk on demand, so any size of set fits
        self.tf = (
            T.Compose([T.RandomResizedCrop(SIZE, scale=(0.5, 1.0)), T.RandomHorizontalFlip(), T.ColorJitter(0.3, 0.3, 0.3, 0.05), T.ToTensor(), T.Normalize(MEAN, STD)])
            if train
            else T.Compose([T.Resize(256), T.CenterCrop(SIZE), T.ToTensor(), T.Normalize(MEAN, STD)])
        )

    def __len__(self):
        return len(self.items)

    def __getitem__(self, i):
        path, y = self.items[i]
        img = Image.open(path).convert("RGB")
        img.thumbnail((320, 320))
        return self.tf(img), y


def split(rows, seed):
    by = defaultdict(list)
    for r in rows:
        by[r["gbif_label"]].append(r)
    rng = random.Random(seed)
    parts = {"train": [], "val": [], "test": []}
    for lst in by.values():
        rng.shuffle(lst)
        n = len(lst)
        n_test, n_val = max(1, round(n * 0.15)), max(1, round(n * 0.15))
        parts["test"] += lst[:n_test]
        parts["val"] += lst[n_test : n_test + n_val]
        parts["train"] += lst[n_test + n_val :]
    return parts


def write_progress(path: Path, state: dict) -> None:
    """Atomically writes the state ml/train_monitor.py shows (never leaves a half-written file)."""
    def clean(v):  # NaN / infinity are not valid JSON and would break the browser's parser
        if isinstance(v, float) and (v != v or v in (float("inf"), float("-inf"))):
            return None
        if isinstance(v, dict):
            return {k: clean(x) for k, x in v.items()}
        if isinstance(v, list):
            return [clean(x) for x in v]
        return v

    # Reporting must never be able to stop the training: on Windows replacing the file fails while the
    # monitor page is reading it, so retry briefly and otherwise just skip this update.
    try:
        tmp = path.with_suffix(".tmp")
        tmp.write_text(json.dumps(clean(state), ensure_ascii=False, allow_nan=False), encoding="utf-8")
        for attempt in range(8):
            try:
                tmp.replace(path)
                return
            except PermissionError:
                time.sleep(0.05 * (attempt + 1))
    except OSError:
        pass


@torch.no_grad()
def predict(model, loader, device):
    model.eval()
    probs, ys = [], []
    for x, y in loader:
        probs.append(torch.softmax(model(x.to(device)), 1).cpu())
        ys.append(y)
    return torch.cat(probs), torch.cat(ys)


def main() -> None:
    global DATA
    ap = argparse.ArgumentParser()
    ap.add_argument("--head-epochs", type=int, default=5)
    ap.add_argument("--epochs", type=int, default=20)
    ap.add_argument("--batch", type=int, default=32)
    ap.add_argument("--seed", type=int, default=1)
    ap.add_argument("--labels", choices=["teacher", "agree"], default="teacher",
                    help="teacher: learn from every label Qwen gave; agree: only photos where Qwen matched the GBIF label")
    ap.add_argument("--name", default="tree", help="output model name (ml/models/<name>.onnx)")
    ap.add_argument("--source", choices=["teacher", "gbif"], default="teacher",
                    help="teacher: learn from teacher_labels.csv; gbif: learn straight from the GBIF names in images.csv (no teacher)")
    ap.add_argument("--species", default=str(ROOT / "species.csv"), help="species CSV listing the classes")
    ap.add_argument("--data", default=str(DATA), help="folder holding the photos and the CSV")
    ap.add_argument("--workers", type=int, default=4, help="photo-loading processes")
    ap.add_argument("--progress", default="", help="progress JSON for ml/train_monitor.py (default: <data>/progress.json)")
    args = ap.parse_args()
    random.seed(args.seed)
    torch.manual_seed(args.seed)
    DATA = Path(args.data)
    device = "cuda" if torch.cuda.is_available() else "cpu"
    print("device:", device)

    classes = sorted({" ".join(r["scientific"].split()[:2]) for r in csv.DictReader(open(args.species, encoding="utf-8-sig"))})
    cidx = {c: i for i, c in enumerate(classes)}
    source_csv = "images.csv" if args.source == "gbif" else "teacher_labels.csv"
    rows = [r for r in csv.DictReader(open(DATA / source_csv, encoding="utf-8")) if r["gbif_label"] in cidx]
    parts = split(rows, args.seed)
    print({k: len(v) for k, v in parts.items()}, "classes:", len(classes))

    def load(rs, label_key):
        out = []
        for r in rs:
            if label_key == "teacher_label":
                if r["teacher_label"] not in cidx:
                    continue  # teacher said "none": nothing to learn from
                if args.labels == "agree" and r["teacher_label"] != r["gbif_label"]:
                    continue  # keep only photos the teacher got right
            out.append((DATA / r["path"], cidx[r[label_key]]))
        return out

    train = load(parts["train"], "gbif_label" if args.source == "gbif" else "teacher_label")  # the student studies from the teacher (or the GBIF names)
    val = load(parts["val"], "gbif_label")  # exams use the independent answer key
    test = load(parts["test"], "gbif_label")
    print(f"student trains on {len(train)} photos, labels={args.labels} ({len(parts['train']) - len(train)} dropped)")
    if not train:
        raise SystemExit("no teacher labels to learn from - run 02_teacher_label.py first")

    mk = lambda ds, shuffle: torch.utils.data.DataLoader(ds, batch_size=args.batch, shuffle=shuffle, num_workers=args.workers,
                                                          persistent_workers=args.workers > 0, pin_memory=device == "cuda")
    tl, vl, sl = mk(PhotoSet(train, True), True), mk(PhotoSet(val, False), False), mk(PhotoSet(test, False), False)

    model = models.mobilenet_v3_small(weights=models.MobileNet_V3_Small_Weights.IMAGENET1K_V1)
    model.classifier[3] = nn.Linear(model.classifier[3].in_features, len(classes))
    model.to(device)
    crit = nn.CrossEntropyLoss(label_smoothing=0.1)

    best, best_state = -1.0, None
    total = args.head_epochs + args.epochs

    # live view: a fixed handful of exam photos the student is asked about after every epoch
    progress_path = Path(args.progress) if args.progress else DATA / "progress.json"
    rng = random.Random(7)
    sample = rng.sample(val, min(24, len(val)))
    sample_loader = torch.utils.data.DataLoader(PhotoSet(sample, False), batch_size=len(sample), shuffle=False)
    started = time.time()
    state = {"status": "starting", "model": args.name, "classes": len(classes), "train_photos": len(train), "val_photos": len(val),
             "total_epochs": total, "epoch": 0, "history": [], "samples": [], "started": started}
    write_progress(progress_path, state)
    for epoch in range(total):
        head_only = epoch < args.head_epochs
        if epoch == 0 or epoch == args.head_epochs:
            for p in model.features.parameters():
                p.requires_grad = not head_only
            params = [p for p in model.parameters() if p.requires_grad]
            opt = torch.optim.AdamW(params, lr=1e-3 if head_only else 1e-4, weight_decay=1e-4)
            sched = torch.optim.lr_scheduler.CosineAnnealingLR(opt, T_max=max(1, (args.head_epochs if head_only else args.epochs)))
        model.train()
        loss_sum = 0.0
        state.update(status="training", epoch=epoch + 1, phase="head" if head_only else "full", batches=len(tl), batch=0, elapsed=time.time() - started)
        for bi, (x, y) in enumerate(tl, 1):
            opt.zero_grad()
            loss = crit(model(x.to(device)), y.to(device))
            loss.backward()
            opt.step()
            loss_sum += loss.item() * len(y)
            if bi % 10 == 0 or bi == len(tl):
                state.update(batch=bi, running_loss=loss_sum / (bi * args.batch), elapsed=time.time() - started)
                write_progress(progress_path, state)
        sched.step()
        p, y = predict(model, vl, device)
        acc = (p.argmax(1) == y).float().mean().item()
        print(f"epoch {epoch + 1:2d}/{total} {'head' if head_only else 'full'}  loss {loss_sum / len(train):.3f}  val acc {acc:.3f}")
        if acc >= best:
            best, best_state = acc, {k: v.detach().cpu().clone() for k, v in model.state_dict().items()}
        # ask the student about the sample photos and record what it says against the answer key
        sp, sy = predict(model, sample_loader, device)
        top = sp.topk(min(3, len(classes)), 1)
        previous = {x["path"]: x["top3"][0]["name"] for x in state["samples"]}
        state["samples"] = [
            {"path": Path(path).relative_to(DATA).as_posix(), "truth": classes[int(t)], "previous": previous.get(Path(path).relative_to(DATA).as_posix()),
             "top3": [{"name": classes[int(i)], "p": round(float(pp), 3)} for pp, i in zip(top.values[k], top.indices[k])]}
            for k, ((path, _), t) in enumerate(zip(sample, sy))
        ]
        state["history"].append({"epoch": epoch + 1, "phase": "head" if head_only else "full", "loss": loss_sum / len(train), "val_acc": acc,
                                 "sample_correct": sum(1 for x in state["samples"] if x["top3"][0]["name"] == x["truth"])})
        state.update(elapsed=time.time() - started, best_val=best)
        write_progress(progress_path, state)

    model.load_state_dict(best_state)
    model.to(device)

    # confidence threshold: the lowest one that makes accepted val answers >= 90% right
    pv, yv = predict(model, vl, device)
    conf, pred = pv.max(1)
    threshold = 1.01  # never answer, unless a better one is found
    for t in np.linspace(0.05, 0.95, 91):
        keep = conf >= t
        if keep.sum() >= 5 and (pred[keep] == yv[keep]).float().mean() >= 0.9:
            threshold = float(t)
            break

    ps, ys = predict(model, sl, device)
    cs, preds = ps.max(1)
    top1 = (preds == ys).float().mean().item()
    top3 = (ps.topk(min(3, len(classes)), 1).indices == ys[:, None]).any(1).float().mean().item()
    keep = cs >= threshold
    answered = keep.float().mean().item()
    acc_answered = (preds[keep] == ys[keep]).float().mean().item() if keep.any() else float("nan")
    # how often the student agrees with the teacher on the test photos
    teach = [cidx.get(r.get("teacher_label", ""), -1) for r in parts["test"]]
    agree = float(np.mean([a == b for a, b in zip(preds.tolist(), teach)])) if args.source == "teacher" else float("nan")
    print("\n=== TEST (graded by GBIF answer key, photos the student never saw) ===")
    print(f"photos {len(ys)}   top-1 {top1:.1%}   top-3 {top3:.1%}")
    print(f"confidence threshold {threshold:.2f}: answers {answered:.1%} of photos, {acc_answered:.1%} of those correct")
    if args.source == "teacher":
        print(f"student agrees with the teacher on {agree:.1%} of test photos")
    per = [(c, (preds[ys == i] == i).float().mean().item(), int((ys == i).sum())) for i, c in enumerate(classes) if (ys == i).any()]
    per.sort(key=lambda x: x[1])
    shown = per if len(per) <= 40 else per[:15]
    print("per-species test accuracy" + ("" if len(per) <= 40 else f" (the 15 worst of {len(per)})") + ":")
    for c, a, n in shown:
        print(f"  {c:32s} {a:.0%}  ({n} photos)")

    OUT.mkdir(exist_ok=True)
    model.cpu().eval()
    torch.save({"state_dict": model.state_dict(), "classes": classes}, OUT / f"{args.name}.pt")
    torch.onnx.export(model, torch.zeros(1, 3, SIZE, SIZE), OUT / f"{args.name}.onnx", input_names=["image"], output_names=["logits"], opset_version=17, dynamo=False)
    json.dump(
        {"name": args.name, "labels_mode": args.labels if args.source == "teacher" else "gbif", "classes": classes, "input_size": SIZE, "mean": MEAN, "std": STD, "resize": 256, "confidence_threshold": threshold,
         "test": {"top1": top1, "top3": top3, "answered": answered, "accuracy_when_answered": acc_answered, "photos": len(ys)}},
        open(OUT / f"{args.name}.labels.json", "w", encoding="utf-8"), ensure_ascii=False, indent=2,
    )
    print(f"saved ml/models/{args.name}.pt, .onnx, .labels.json")
    state.update(status="done", elapsed=time.time() - started, test={"top1": top1, "top3": top3, "answered": answered, "accuracy_when_answered": acc_answered,
                                                                     "photos": len(ys), "threshold": threshold})
    write_progress(progress_path, state)


if __name__ == "__main__":
    main()
