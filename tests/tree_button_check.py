"""The identify button with the in-browser "tree" model, end to end in headless Chromium.

Serves the real public/assets/js files, a minimal copy of the button's markup and stubs for the two server
endpoints (identify_match.php, identify_tree.php), then checks three situations:
  A  tree is >= 90 % sure and the species exists in the system  -> answered locally, the server AI is NOT called
  B  tree is unsure (< 50 %)                                      -> the photo goes to the server AI as before
  C  the form wants the full write-up and the species is unknown  -> falls through to the server AI
Run by hand (needs ml/data_sea, ml/models and internet for the ONNX Runtime CDN):  python tests/tree_button_check.py
"""
import csv
import http.server
import importlib.util
import json
import os
import subprocess
import sys
import threading
from pathlib import Path
from urllib.parse import parse_qs

import torch
import torch.nn as nn
from PIL import Image
from torchvision import models, transforms as T

ROOT = Path(__file__).resolve().parent.parent
DATA = ROOT / "ml" / "data_sea"
ASSETS = ROOT / "public" / "assets"

PAGE = """<!doctype html><meta charset=utf-8><title>button check</title>
<form id="f"><input type="hidden" name="csrf_token" value="tok">
  <input type="file" id="image">
  <div data-ai-identify-for="image" data-endpoint="/admin/identify_tree.php" data-apply-label="use" id="w">
    <button type="button" data-ai-action="identify">go</button><div data-ai-output></div>
  </div>
</form>
<script>
function log(m) { try { navigator.sendBeacon('/log', String(m)); } catch (e) {} }
window.onerror = function (m, s, l) { log('error: ' + m + ' @' + l); };
window.addEventListener('unhandledrejection', function (e) { log('rejection: ' + (e.reason && e.reason.message || e.reason)); });
log('page loaded');
window.__calls = []; window.__matchId = 7;
var realFetch = window.fetch;
window.fetch = function (u, o) {
  u = String(u);
  if (u.indexOf('identify_match.php') >= 0) {
    window.__calls.push('match');
    return Promise.resolve(new Response(JSON.stringify({ ok: true, matched_species_id: window.__matchId, matched_species_name: window.__matchId ? 'ต้นทดสอบในระบบ' : null })));
  }
  if (u.indexOf('identify_tree.php') >= 0) {
    window.__calls.push('server');
    return Promise.resolve(new Response(JSON.stringify({ ok: true, result: { is_plant: true, name_th: 'คำตอบจากเซิร์ฟเวอร์', name_scientific: 'Server answer', confidence: 'low', confidence_pct: 30, alternatives: [], notes_th: '' } })));
  }
  return realFetch(u, o);
};
</script>
<script src="/assets/js/tree-model.js"></script>
<script src="/assets/js/ai-identify-button.js"></script>
<script>
async function scenario(photo, detail, matchId) {
  window.__calls = []; window.__matchId = matchId;
  log('scenario start: ' + photo + ' detail=' + detail);
  var w = document.getElementById('w'), input = document.getElementById('image'), out = w.querySelector('[data-ai-output]');
  if (detail) w.dataset.detail = detail; else delete w.dataset.detail;
  out.textContent = '';
  var blob = await (await realFetch('/photo?path=' + encodeURIComponent(photo))).blob();
  var dt = new DataTransfer(); dt.items.add(new File([blob], 'p.jpg', { type: 'image/jpeg' }));
  input.files = dt.files; input.dispatchEvent(new Event('change'));
  await new Promise(function (r) { setTimeout(r, 100); });
  var btn = w.querySelector('button'); btn.click();
  for (var i = 0; i < 600; i++) {   // up to 60 s
    await new Promise(function (r) { setTimeout(r, 100); });
    if (out.querySelector('.flash') && !btn.disabled) break;
  }
  log('scenario end: calls=' + window.__calls.join(',') + ' text=' + out.textContent.slice(0, 80));
  return { calls: window.__calls.slice(), text: out.textContent.slice(0, 400) };
}
(async function () {
  var cfg = await (await realFetch('/cfg.json')).json();
  var res = {};
  res.A = await scenario(cfg.sure, '', 7);
  res.B = await scenario(cfg.unsure, '', 7);
  res.C = await scenario(cfg.sure, 'full', null);
  res.D = await scenario(cfg.sure, 'full', 7);
  await fetch('/report', { method: 'POST', body: JSON.stringify(res) });
})();
</script>"""


def find_chromium() -> str:
    cache = Path(os.environ.get("LOCALAPPDATA", str(Path.home()))) / "ms-playwright"
    for d in sorted(cache.glob("chromium-*"), reverse=True):
        for exe in list(d.glob("chrome-win*/chrome.exe")) + list(d.glob("chrome-linux*/chrome")):
            return str(exe)
    sys.exit("No Chromium found in Playwright's cache")


def pick_photos():
    spec = importlib.util.spec_from_file_location("train_tree", ROOT / "ml" / "03_train_tree.py")
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    meta = json.load(open(ASSETS / "models" / "tree.labels.json", encoding="utf-8"))
    classes = meta["classes"]
    cidx = {c: i for i, c in enumerate(classes)}
    rows = [r for r in csv.DictReader(open(DATA / "images.csv", encoding="utf-8")) if r["gbif_label"] in cidx]
    net = models.mobilenet_v3_small()
    net.classifier[3] = nn.Linear(net.classifier[3].in_features, len(classes))
    net.load_state_dict(torch.load(ROOT / "ml" / "models" / "tree_sea.pt", map_location="cpu")["state_dict"])
    net.eval()
    tf = T.Compose([T.Resize(256), T.CenterCrop(224), T.ToTensor(), T.Normalize(meta["mean"], meta["std"])])
    sure = unsure = None
    with torch.no_grad():
        for r in mod.split(rows, 1)["test"]:
            img = Image.open(DATA / r["path"]).convert("RGB")
            img.thumbnail((320, 320))
            conf = float(torch.softmax(net(tf(img)[None]), 1)[0].max())
            if sure is None and conf >= 0.97:
                sure = r["path"]
            if unsure is None and conf < 0.35:
                unsure = r["path"]
            if sure and unsure:
                break
    return sure, unsure


def main() -> None:
    sure, unsure = pick_photos()
    if not (sure and unsure):
        sys.exit("could not find a very sure and a very unsure test photo")
    report, done = {}, threading.Event()

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
            if path == "/cfg.json":
                return self._send(json.dumps({"sure": sure, "unsure": unsure}).encode(), "application/json")
            if path == "/photo":
                return self._send((DATA / parse_qs(query)["path"][0]).read_bytes(), "image/jpeg")
            f = ASSETS / path.removeprefix("/assets/")
            if path.startswith("/assets/") and f.is_file():
                ctype = {".js": "application/javascript", ".json": "application/json"}.get(f.suffix, "application/octet-stream")
                return self._send(f.read_bytes(), ctype)
            self.send_response(404)
            self.end_headers()

        def do_POST(self):
            body = self.rfile.read(int(self.headers["Content-Length"])).decode(errors="replace")
            if self.path == "/log":
                print("  [browser]", body, flush=True)
                return self._send(b"ok", "text/plain")
            report["data"] = json.loads(body)
            self._send(b"ok", "text/plain")
            done.set()

    server = http.server.ThreadingHTTPServer(("127.0.0.1", 8798), H)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    profile = DATA / "_chrome_profile_button"
    chrome = subprocess.Popen([find_chromium(), "--headless=new", "--disable-gpu", f"--user-data-dir={profile}", "http://127.0.0.1:8798/"],
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        if not done.wait(timeout=150):
            sys.exit("The browser never reported back")
    finally:
        chrome.terminate()
        server.shutdown()

    r = report["data"]
    checks = [
        ("A  sure + known species -> answered locally, server AI not called", r["A"]["calls"] == ["match"] and "tree" in r["A"]["text"] and "ต้นทดสอบในระบบ" in r["A"]["text"]),
        ("B  unsure -> the server AI is asked", r["B"]["calls"] == ["server"] and "คำตอบจากเซิร์ฟเวอร์" in r["B"]["text"]),
        ("C  full write-up wanted + species unknown -> falls through to the server AI", r["C"]["calls"] == ["match", "server"]),
        ("D  full write-up wanted + species known -> answered locally", r["D"]["calls"] == ["match"]),
    ]
    ok = True
    for label, passed in checks:
        print(("PASS  " if passed else "FAIL  ") + label)
        ok &= passed
    if not ok:
        print(json.dumps(r, ensure_ascii=False, indent=1))
    sys.exit(0 if ok else 1)


if __name__ == "__main__":
    main()
