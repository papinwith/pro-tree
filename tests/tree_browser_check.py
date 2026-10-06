"""Does the model running in a browser answer like the Python one?

Takes held-out TEST photos, asks the trained model in Python (the reference), then asks the very same photos
through public/assets/js/tree-model.js in headless Chromium (ONNX Runtime Web, the code the website runs) and
compares: same top-1 species, size of the probability difference, and whether the 90 % gate would decide alike.
Needs internet once (the ONNX Runtime library comes from the CDN, as on the website) and a Chromium from
Playwright's cache. Uses ml/data_sea and ml/models (not in git), so it is run by hand (TREE_MODEL=<name> picks which trained model
is compared with the one published in public/assets/models, default tree_sea):

    python tests/tree_browser_check.py [--photos 60]
"""
import argparse
import csv
import http.server
import importlib.util
import json
import os
import subprocess
import sys
import threading
import time
from pathlib import Path

import torch
import torch.nn as nn
from PIL import Image
from torchvision import models, transforms as T

ROOT = Path(__file__).resolve().parent.parent
DATA = ROOT / "ml" / "data_sea"
ASSETS = ROOT / "public" / "assets"

PAGE = """<!doctype html><meta charset=utf-8><title>tree browser check</title>
<script>
function log(m) { try { navigator.sendBeacon('/log', String(m)); } catch (e) {} }
window.onerror = function (m, src, line) { log('error: ' + m + ' @' + line); };
window.addEventListener('unhandledrejection', function (e) { log('rejection: ' + (e.reason && e.reason.message || e.reason)); });
</script>
<script src="/assets/js/tree-model.js"></script>
<script>
(async function () {
  log('page started');
  var photos = await (await fetch('/expected.json')).json();
  var out = [];
  for (var i = 0; i < photos.length; i++) {
    try {
      var blob = await (await fetch('/photo?path=' + encodeURIComponent(photos[i].path))).blob();
      if (i === 0) log('first photo fetched, loading model...');
      var r = await window.TreeModel.classify(blob);
      if (i === 0) log('first answer ok');
      out.push({ path: photos[i].path, name: r.name, p: r.p, top3: r.top3 });
    } catch (e) { out.push({ path: photos[i].path, error: String(e) }); break; }
  }
  await fetch('/report', { method: 'POST', body: JSON.stringify(out) });
})();
</script>"""


def find_chromium() -> str:
    cache = Path(os.environ.get("LOCALAPPDATA", str(Path.home()))) / "ms-playwright"
    for d in sorted(cache.glob("chromium-*"), reverse=True):
        for exe in list(d.glob("chrome-win*/chrome.exe")) + list(d.glob("chrome-linux*/chrome")):
            return str(exe)
    sys.exit("No Chromium found in Playwright's cache")


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--photos", type=int, default=60)
    args = ap.parse_args()

    spec = importlib.util.spec_from_file_location("train_tree", ROOT / "ml" / "03_train_tree.py")
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)

    meta = json.load(open(ASSETS / "models" / "tree.labels.json", encoding="utf-8"))
    classes = meta["classes"]
    cidx = {c: i for i, c in enumerate(classes)}
    rows = [r for r in csv.DictReader(open(DATA / "images.csv", encoding="utf-8")) if r["gbif_label"] in cidx]
    test = mod.split(rows, 1)["test"][:: max(1, len(mod.split(rows, 1)["test"]) // args.photos)][: args.photos]

    net = models.mobilenet_v3_small()
    net.classifier[3] = nn.Linear(net.classifier[3].in_features, len(classes))
    net.load_state_dict(torch.load(ROOT / "ml" / "models" / (os.environ.get("TREE_MODEL", "tree_sea") + ".pt"), map_location="cpu")["state_dict"])
    net.eval()
    tf = T.Compose([T.Resize(256), T.CenterCrop(224), T.ToTensor(), T.Normalize(meta["mean"], meta["std"])])
    expected = []
    with torch.no_grad():
        for r in test:
            img = Image.open(DATA / r["path"]).convert("RGB")
            img.thumbnail((320, 320))
            p = torch.softmax(net(tf(img)[None]), 1)[0]
            conf, idx = p.max(0)
            expected.append({"path": r["path"], "truth": r["gbif_label"], "name": classes[int(idx)], "p": float(conf)})

    report = {}
    done = threading.Event()

    class H(http.server.BaseHTTPRequestHandler):
        def log_message(self, *a):
            pass

        def _send(self, body: bytes, ctype: str) -> None:
            self.send_response(200)
            self.send_header("Content-Type", ctype)
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        def do_GET(self):
            path, _, query = self.path.partition("?")
            if path == "/":
                return self._send(PAGE.encode(), "text/html; charset=utf-8")
            if path == "/expected.json":
                return self._send(json.dumps(expected).encode(), "application/json")
            if path == "/photo":
                from urllib.parse import parse_qs
                return self._send((DATA / parse_qs(query)["path"][0]).read_bytes(), "image/jpeg")
            f = ASSETS / path.removeprefix("/assets/")
            if path.startswith("/assets/") and f.is_file():
                ctype = {".js": "application/javascript", ".json": "application/json", ".onnx": "application/octet-stream"}.get(f.suffix, "application/octet-stream")
                return self._send(f.read_bytes(), ctype)
            self.send_response(404)
            self.end_headers()

        def do_POST(self):
            if self.path == "/log":
                print("  [browser]", self.rfile.read(int(self.headers["Content-Length"])).decode(errors="replace"), flush=True)
                return self._send(b"ok", "text/plain")
            report["data"] = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
            self._send(b"ok", "text/plain")
            done.set()

    server = http.server.ThreadingHTTPServer(("127.0.0.1", 8799), H)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    profile = ROOT / "ml" / "data_sea" / "_chrome_profile"
    chrome = subprocess.Popen([find_chromium(), "--headless=new", "--disable-gpu", f"--user-data-dir={profile}", "http://127.0.0.1:8799/"],
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        if not done.wait(timeout=240):
            sys.exit("The browser never reported back (no internet for the CDN, or the model failed to load?)")
    finally:
        chrome.terminate()
        server.shutdown()

    got = report["data"]
    bad = [g for g in got if "error" in g]
    if bad:
        sys.exit(f"Browser error on {bad[0]['path']}: {bad[0]['error']}")
    same = sum(g["name"] == e["name"] for g, e in zip(got, expected))
    diffs = sorted(abs(g["p"] - e["p"]) for g, e in zip(got, expected))
    gate = meta["app_threshold"]
    gate_same = sum((g["p"] >= gate) == (e["p"] >= gate) for g, e in zip(got, expected))
    py_right = sum(e["name"] == e["truth"] for e in expected)
    br_right = sum(g["name"] == e["truth"] for g, e in zip(got, expected))
    print(f"photos {len(got)}")
    print(f"same top-1 species in browser and Python: {same}/{len(got)}")
    print(f"probability difference: median {diffs[len(diffs) // 2]:.3f}, 90th percentile {diffs[int(len(diffs) * 0.9)]:.3f}, max {diffs[-1]:.3f}")
    print(f"same decision at the {gate:.0%} gate: {gate_same}/{len(got)}")
    print(f"correct vs GBIF: Python {py_right}/{len(got)}, browser {br_right}/{len(got)}")
    print("PASS" if same / len(got) >= 0.9 and gate_same / len(got) >= 0.95 else "FAIL (browser and Python disagree too often)")


if __name__ == "__main__":
    main()
