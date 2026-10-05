"""Step 2 - the teacher (Qwen vision, via Ollama) labels every photo.

For each photo Qwen is shown the list of species the student must learn and
asked which one it sees (or "none"). Its answer is the TEACHER LABEL the
student is trained on. The GBIF label from step 1 is never shown to Qwen; it is
only used afterwards to report how good the teacher is.

Output: ml/data/teacher_labels.csv (path, gbif_label, teacher_label, confidence).
Resumable: photos already labelled are skipped, so you can stop and continue.
"""
import argparse
import base64
import csv
import json
import time
from pathlib import Path

import requests

ROOT = Path(__file__).resolve().parent
DATA = ROOT / "data"


def build_prompt(names: list, allow_none: bool) -> str:
    """The question put to the teacher (shared with ml/live_viewer.py)."""
    listing = "\n".join(f"{i + 1}. {n}" for i, n in enumerate(names))
    return (
        "You are a botanist. Which ONE of these species is shown in the photo?\n"
        f"{listing}\n"
        + (
            'If none of them clearly matches, answer "none". '
            if allow_none
            else "The photo is definitely one of them - pick the single closest match. "
        )
        + 'Reply with JSON only: {"species": "<exact scientific name from the list>", "confidence": "high|medium|low"}'
    )


def ask(url: str, model: str, names: list, img_path: Path, timeout: int, allow_none: bool) -> dict:
    prompt = build_prompt(names, allow_none)
    body = {
        "model": model,
        "messages": [{"role": "user", "content": prompt, "images": [base64.b64encode(img_path.read_bytes()).decode()]}],
        "stream": False,
        "format": "json",
        "think": False,
        "keep_alive": "30m",
        "options": {"temperature": 0, "num_predict": 60},
    }
    r = requests.post(f"{url}/api/chat", json=body, timeout=timeout)
    r.raise_for_status()
    return json.loads(r.json()["message"]["content"])


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--url", default="http://localhost:11434")
    ap.add_argument("--model", default="qwen2.5vl:7b")
    ap.add_argument("--limit", type=int, default=0, help="label at most N new photos (0 = all)")
    ap.add_argument("--timeout", type=int, default=300)
    ap.add_argument("--allow-none", action="store_true", help='let the teacher answer "none" (default: it must pick the closest species)')
    ap.add_argument("--sample", type=int, default=0, help="only label N photos per species (for a quick accuracy check)")
    args = ap.parse_args()

    names = [" ".join(r["scientific"].split()[:2]) for r in csv.DictReader(open(ROOT / "species.csv", encoding="utf-8-sig"))]
    images = list(csv.DictReader(open(DATA / "images.csv", encoding="utf-8")))
    out_path = DATA / "teacher_labels.csv"
    done = {}
    if out_path.exists():
        done = {r["path"]: r for r in csv.DictReader(open(out_path, encoding="utf-8"))}

    todo = [r for r in images if r["path"] not in done]
    if args.sample:
        seen = {}
        picked = []
        for r in todo:
            if seen.get(r["gbif_label"], 0) < args.sample:
                seen[r["gbif_label"]] = seen.get(r["gbif_label"], 0) + 1
                picked.append(r)
        todo = picked
    if args.limit:
        todo = todo[: args.limit]
    print(f"{len(done)} already labelled, {len(todo)} to do")

    valid = {n.lower(): n for n in names}
    t0 = time.time()
    for i, r in enumerate(todo, 1):
        try:
            ans = ask(args.url, args.model, names, DATA / r["path"], args.timeout, args.allow_none)
        except Exception as e:  # keep going; failed photos are retried next run
            print(f"  ERROR {r['path']}: {e}")
            continue
        raw = str(ans.get("species", "none")).strip()
        label = valid.get(" ".join(raw.lower().split()[:2]), "none")
        done[r["path"]] = {"path": r["path"], "gbif_label": r["gbif_label"], "teacher_label": label, "confidence": str(ans.get("confidence", ""))}
        if i % 10 == 0 or i == len(todo):
            per = (time.time() - t0) / i
            print(f"  {i}/{len(todo)}  {per:.1f}s/photo  ~{per * (len(todo) - i) / 60:.0f} min left")
            with open(out_path, "w", encoding="utf-8", newline="") as fh:
                w = csv.DictWriter(fh, fieldnames=["path", "gbif_label", "teacher_label", "confidence"])
                w.writeheader()
                w.writerows(done.values())

    with open(out_path, "w", encoding="utf-8", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=["path", "gbif_label", "teacher_label", "confidence"])
        w.writeheader()
        w.writerows(done.values())

    rows = list(done.values())
    if rows:
        agree = sum(r["teacher_label"] == r["gbif_label"] for r in rows)
        none = sum(r["teacher_label"] == "none" for r in rows)
        print(f"\nTeacher report: {agree}/{len(rows)} = {100 * agree / len(rows):.1f}% match the GBIF label; {none} answered 'none'")


if __name__ == "__main__":
    main()
