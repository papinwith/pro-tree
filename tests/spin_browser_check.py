"""The 360-degree spin in headless Chromium: the visitor's viewer and the admin's capture/upload, against a fake server.

Viewer: keeps turning by itself, drag takes over and it turns again after a pause, the pause button and arrow keys work,
frames are really blended, "reduce motion" stops the automatic turning, and a broken frame leaves the still photo.
Capture: a video is turned into evenly spaced JPEG frames in the browser; the upload goes start -> small batches ->
commit, and a failed batch means no commit (the live spin is never half replaced).

    python tests/spin_browser_check.py
"""
import http.server
import io
import json
import os
import subprocess
import sys
import tempfile
import threading
import time
from pathlib import Path
from urllib.parse import parse_qs, urlparse

from PIL import Image, ImageDraw

ROOT = Path(__file__).resolve().parent.parent
JS = ROOT / "public" / "assets" / "js"
N = 24


def frame_jpeg(i: int) -> bytes:
    """A frame whose colour and bar position depend on i (so frames are visibly different)."""
    im = Image.new("RGB", (480, 320), ((i * 10) % 255, 90 + (i * 7) % 120, 255 - (i * 9) % 200))
    ImageDraw.Draw(im).rectangle([i * 18, 100, i * 18 + 30, 260], fill=(255, 255, 255))
    buf = io.BytesIO()
    im.save(buf, "JPEG", quality=85)
    return buf.getvalue()


FRAMES = [frame_jpeg(i) for i in range(N)]

VIEWER_PAGE = """<!doctype html><meta charset=utf-8><title>viewer</title>
<style>body{margin:20px;font-family:sans-serif}.box{width:420px}</style>
<div class="box"><div id="s" data-spin data-src="/frame?mode=__MODE__&i=" data-count="24" data-poster="/frame?mode=ok&i=0"
  data-label="360" data-hint="drag" data-play-label="play" data-pause-label="pause" data-loading-label="{n}/{total}"></div></div>
<script src="/js/spin-viewer.js"></script>
<script>
const sleep = ms => new Promise(r => setTimeout(r, ms));
const report = o => fetch('/report', { method: 'POST', body: JSON.stringify(o) });
function ptr(type, x, id) { return new PointerEvent(type, { pointerId: id || 1, clientX: x, clientY: 50, bubbles: true, pointerType: 'touch', isPrimary: true }); }
(async function () { const out = { mode: '__MODE__' }; try { await main(out); } catch (e) { out.scriptError = String(e && e.stack || e); await report(out); } })();
async function main(out) {
  const el = document.getElementById('s');
  out.reduceMotionMatches = matchMedia('(prefers-reduced-motion: reduce)').matches;
  await sleep(300);
  const v = el._spin;
  for (let i = 0; i < 100 && !(v && v.loaded === v.count) && !el.classList.contains('spin-failed'); i++) await sleep(100);
  out.loaded = v.loaded; out.failed = el.classList.contains('spin-failed');
  out.canvasHidden = v.canvas.hidden || v.canvas.style.visibility === 'hidden';
  out.posterVisible = !!v.posterImg && v.posterImg.style.display !== 'none';
  if (out.failed) { await report(out); return; }
  out.toggleVisible = !v.button.hidden; out.statusText = v.status.textContent;
  out.canvasSize = [v.canvas.width, v.canvas.height];

  let a0 = v.angle; await sleep(1500); out.autoplayMoved = v.angle - a0;
  if (out.mode === 'reduced') { // drag must still work, but nothing moves by itself
a0 = v.angle; const c = v.canvas, w = c.clientWidth;
    c.dispatchEvent(ptr('pointerdown', 100)); c.dispatchEvent(ptr('pointermove', 100 + w / 4));
    out.dragMovedWhenReduced = v.angle - a0; c.dispatchEvent(ptr('pointerup', 100 + w / 4));
    await sleep(4000);                                  // the swipe's inertia dies away ...
    const settled = v.angle; await sleep(3500);          // ... and from then on nothing moves it (no automatic turning, no resume)
    out.keptStillAfterDrag = Math.abs(v.angle - settled) < 0.05; await report(out); return;
  }
  const c = v.canvas, w = c.clientWidth;
  // drag half the width to the right: half a turn backwards
  let before = v.angle;
  c.dispatchEvent(ptr('pointerdown', 100)); c.dispatchEvent(ptr('pointermove', 100 + w / 2));
  out.dragDelta = v.angle - before; out.dragExpected = -v.count / 2;
  let held = v.angle; await sleep(900); out.holdDrift = Math.abs(v.angle - held);   // finger down: it must not turn by itself
  c.dispatchEvent(ptr('pointerup', 100 + w / 2));
  await sleep(1200); out.stillWithinIdle = true;
  await sleep(2300);                                  // idle period (2.5 s) is over by now
  let r0 = v.angle; await sleep(1500); out.resumedMoved = Math.abs(v.angle - r0);
  // pause button
  v.button.click(); await sleep(200); let p0 = v.angle; await sleep(1500); out.pausedDrift = Math.abs(v.angle - p0);
  out.labelWhenPaused = v.button.getAttribute('aria-label'); out.pressedWhenPaused = v.button.getAttribute('aria-pressed');
  v.button.click(); await sleep(200); p0 = v.angle; await sleep(1500); out.playedMoved = Math.abs(v.angle - p0);
  out.labelWhenPlaying = v.button.getAttribute('aria-label');
  // arrow keys step exactly one frame
  v.button.click(); await sleep(300);                  // pause first so nothing else moves it
  v.userPaused = false; v.autoplay = true;             // as if resumed, then a key press must still take over
  let k0 = v.angle; c.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true })); out.keyDelta = v.angle - k0;
  k0 = v.angle; c.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true })); out.keyDeltaLeft = v.angle - k0;
  // blending: halfway between frame 0 and 1 the canvas is the average of the two photos
  v.setUserPaused(true); v.velocity = 0;
  function px(img) { const t = document.createElement('canvas'); t.width = 40; t.height = 30; const x = t.getContext('2d'); x.drawImage(img, 0, 0, 40, 30); return x.getImageData(5, 5, 1, 1).data; }
  v.angle = 0.5; v.draw();
  const mix = v.ctx.getImageData(5, 5, 1, 1).data;
  const sc = document.createElement('canvas'); sc.width = v.canvas.width; sc.height = v.canvas.height;
  const sx = sc.getContext('2d'); sx.drawImage(v.images[0], 0, 0, sc.width, sc.height); const A = sx.getImageData(5, 5, 1, 1).data;
  sx.drawImage(v.images[1], 0, 0, sc.width, sc.height); const B = sx.getImageData(5, 5, 1, 1).data;
  v.angle = 0; v.draw(); const f0 = v.ctx.getImageData(5, 5, 1, 1).data;
  out.blend = { mixed: Array.from(mix).slice(0, 3), frame0: Array.from(f0).slice(0, 3), frame1: Array.from(B).slice(0, 3) };
  v.angle = v.count + 0.25; v.draw(); out.wrapOk = Number.isFinite(v.currentFrame) && v.currentFrame >= 0 && v.currentFrame < v.count;
  await report(out);
}
</script>"""

CAPTURE_PAGE = """<!doctype html><meta charset=utf-8><title>capture</title>
<script src="/js/spin-viewer.js"></script>
<section data-spin-capture data-tree-id="9" data-endpoint="/upload?scenario=__SCENARIO__" data-csrf="tok">
  <input type="file" data-spin-video accept="video/*"><input type="file" data-spin-photos accept="image/*" multiple>
  <input type="number" data-spin-count value="24"><div data-spin-preview></div><i data-spin-bar></i><p data-spin-status></p>
  <button data-spin-save hidden>save</button><button data-spin-delete data-confirm="x">del</button>
</section>
<script src="/js/spin-capture.js"></script>
<script>
const sleep = ms => new Promise(r => setTimeout(r, ms));
const report = o => fetch('/report', { method: 'POST', body: JSON.stringify(o) });
window.confirm = () => true;
async function makeVideo() {          // a 3 s clip: the background turns through colours, so frames differ
  const c = document.createElement('canvas'); c.width = 640; c.height = 360; const x = c.getContext('2d');
  const rec = new MediaRecorder(c.captureStream(20), { mimeType: 'video/webm' }); const parts = [];
  rec.ondataavailable = e => parts.push(e.data);
  const done = new Promise(r => rec.onstop = r); rec.start(); const t0 = performance.now();
  while (performance.now() - t0 < 3000) { const f = (performance.now() - t0) / 3000; x.fillStyle = `hsl(${f * 300},70%,50%)`; x.fillRect(0, 0, 640, 360); x.fillStyle = '#fff'; x.fillRect(f * 600, 100, 30, 160); await sleep(40); }
  rec.stop(); await done; return new File([new Blob(parts, { type: 'video/webm' })], 'walk.webm', { type: 'video/webm' });
}
(async function () {
  const saved = sessionStorage.getItem('first-run');
  if (saved) { const o = JSON.parse(saved); o.reloadedAfterSave = true; await report(o); return; }   // second load = the reload after saving
  const out = { scenario: '__SCENARIO__' }, sec = document.querySelector('[data-spin-capture]');
  const file = await makeVideo(); out.videoBytes = file.size;
  const dt = new DataTransfer(); dt.items.add(file);
  const input = sec.querySelector('[data-spin-video]'); input.files = dt.files; input.dispatchEvent(new Event('change'));
  const save = sec.querySelector('[data-spin-save]'), status = sec.querySelector('[data-spin-status]');
  for (let i = 0; i < 300 && save.hidden && !/ไม่สำเร็จ/.test(status.textContent); i++) await sleep(100);
  out.previewReady = !save.hidden; out.statusAfterPrepare = status.textContent.slice(0, 80);
  out.previewHasViewer = !!sec.querySelector('[data-spin-preview] canvas');
  if (!out.previewReady) { await report(out); return; }
  // what frames were produced?
  const frames = await window.SpinCapture.framesFromVideo(file, 12);
  out.frameCount = frames.length; out.allJpeg = frames.every(b => b.type === 'image/jpeg' && b.size > 500);
  const cols = [];
  for (const b of frames) { const bm = await createImageBitmap(b); out.maxSide = Math.max(out.maxSide || 0, bm.width, bm.height); const t = document.createElement('canvas'); t.width = 4; t.height = 4; const x = t.getContext('2d'); x.drawImage(bm, 0, 0, 4, 4); cols.push(Array.from(x.getImageData(0, 0, 1, 1).data).slice(0, 3)); }
  out.distinctColours = new Set(cols.map(c => c.map(v => Math.round(v / 24)).join(','))).size;
  if (__RELOAD__) sessionStorage.setItem('first-run', JSON.stringify(out));   // survives the reload that follows a successful save
  save.click();
  for (let i = 0; i < 150 && !/ไม่สำเร็จ/.test(status.textContent); i++) await sleep(100);   // on success the page reloads and the code above reports
  out.statusAtEnd = status.textContent.slice(0, 120);
  await report(out);
})();
</script>"""


def find_chromium() -> str:
    cache = Path(os.environ.get("LOCALAPPDATA", str(Path.home()))) / "ms-playwright"
    for d in sorted(cache.glob("chromium-*"), reverse=True):
        for exe in list(d.glob("chrome-win*/chrome.exe")) + list(d.glob("chrome-linux*/chrome")):
            return str(exe)
    sys.exit("No Chromium found in Playwright's cache")


def run(page_name: str, page_html: str, flags=None, timeout=90, upload_scenario="ok", _retry=False):
    report, done, calls = {}, threading.Event(), []

    class H(http.server.BaseHTTPRequestHandler):
        def log_message(self, *a):
            pass

        def _send(self, code, body, ctype):
            self.send_response(code)
            self.send_header("Content-Type", ctype)
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        def do_GET(self):
            u = urlparse(self.path)
            q = parse_qs(u.query)
            if u.path == "/page":
                return self._send(200, page_html.encode(), "text/html; charset=utf-8")
            if u.path == "/frame":
                i = int(q["i"][0])
                if q.get("mode", [""])[0] == "fail" and i == 3:
                    return self._send(404, b"nope", "text/plain")
                return self._send(200, FRAMES[i % N], "image/jpeg")
            if u.path.startswith("/js/"):
                f = JS / u.path[4:]
                return self._send(200, f.read_bytes(), "application/javascript") if f.is_file() else self._send(404, b"", "text/plain")
            self._send(404, b"", "text/plain")

        def do_POST(self):
            u = urlparse(self.path)
            q = parse_qs(u.query)
            body = self.rfile.read(int(self.headers.get("Content-Length") or 0))
            if u.path == "/report":
                report.update(json.loads(body))
                done.set()
                return self._send(200, b"ok", "text/plain")
            if u.path == "/upload":
                text = body.decode("latin-1")
                action = "start" if 'name="action"\r\n\r\nstart' in text else "frames" if 'name="action"\r\n\r\nframes' in text else "commit" if 'name="action"\r\n\r\ncommit' in text else "delete" if 'name="action"\r\n\r\ndelete' in text else "?"
                nfiles = text.count('name="frames[]"')
                calls.append({"action": action, "files": nfiles, "csrf": 'name="csrf_token"\r\n\r\ntok' in text, "tree": 'name="tree_id"\r\n\r\n9' in text})
                scenario = q.get("scenario", ["ok"])[0]
                if action == "start":
                    return self._send(200, json.dumps({"ok": True, "token": "0123456789abcdef"}).encode(), "application/json")
                if action == "frames" and scenario == "failbatch2" and sum(1 for c in calls if c["action"] == "frames") == 2:
                    return self._send(200, json.dumps({"ok": False, "error": "เฟรมที่ 8 ใช้ไม่ได้"}).encode(), "application/json")
                return self._send(200, json.dumps({"ok": True, "saved": nfiles, "frames": 12}).encode(), "application/json")
            self._send(404, b"", "text/plain")

    server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), H)
    port = server.server_address[1]
    threading.Thread(target=server.serve_forever, daemon=True).start()
    profile = Path(tempfile.gettempdir()) / "spin_check_profile"
    chrome = subprocess.Popen([find_chromium(), "--headless=new", "--disable-gpu", "--autoplay-policy=no-user-gesture-required", f"--user-data-dir={profile}"] + (flags or []) + [f"http://127.0.0.1:{port}/page"],
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        done.wait(timeout=timeout if _retry else 150)
    finally:
        chrome.terminate()
        server.shutdown()
    if not report and not _retry:
        return run(page_name, page_html, flags, timeout, upload_scenario, _retry=True)
    return report, calls


results = []


def check(label, ok, detail=""):
    results.append(bool(ok))
    print(("PASS  " if ok else "FAIL  ") + label + ("" if ok else f"   {detail}"))


# ------------------------------------------------------------------ viewer, normal motion
r, _ = run("viewer", VIEWER_PAGE.replace("__MODE__", "ok"))
check("all 24 frames load, the still photo gives way to the canvas, the pause button appears and the loading text is gone",
      r.get("loaded") == N and not r.get("failed") and not r.get("canvasHidden") and not r.get("posterVisible") and r.get("toggleVisible") and r.get("statusText") == "", json.dumps(r)[:300])
check("it turns by itself without being touched (more than 2 frames in 1.5 s)", r.get("autoplayMoved", 0) > 2, str(r.get("autoplayMoved")))
check("dragging half the width turns it half a revolution (backwards for a rightward drag)", abs(r.get("dragDelta", 0) - r.get("dragExpected", 99)) < 1.5, f"{r.get('dragDelta')} vs {r.get('dragExpected')}")
check("while a finger is down it does not turn by itself", r.get("holdDrift", 99) < 0.3, str(r.get("holdDrift")))
check("after the finger lifts and a short idle it starts turning again on its own", r.get("resumedMoved", 0) > 1.5, str(r.get("resumedMoved")))
check("the pause button really stops it, says so for screen readers, and play starts it again",
      r.get("pausedDrift", 99) < 0.3 and r.get("labelWhenPaused") == "play" and r.get("pressedWhenPaused") == "true" and r.get("playedMoved", 0) > 1.5 and r.get("labelWhenPlaying") == "pause", json.dumps({k: r.get(k) for k in ("pausedDrift", "labelWhenPaused", "pressedWhenPaused", "playedMoved", "labelWhenPlaying")}))
check("arrow keys step exactly one frame each way", r.get("keyDelta") == 1 and r.get("keyDeltaLeft") == -1, f"{r.get('keyDelta')} {r.get('keyDeltaLeft')}")
b = r.get("blend", {})
mix, f0, f1 = b.get("mixed", [0, 0, 0]), b.get("frame0", [0, 0, 0]), b.get("frame1", [0, 0, 0])
check("halfway between two photos the picture is a real blend of both (the turn is smooth with few photos)",
      all(abs(mix[k] - (f0[k] + f1[k]) / 2) <= 6 for k in range(3)) and mix != f0 and mix != f1, str(b))
check("angles beyond a full turn wrap around to a valid frame", r.get("wrapOk"))

# ------------------------------------------------------------------ reduced motion
r, _ = run("viewer", VIEWER_PAGE.replace("__MODE__", "reduced"), flags=["--force-prefers-reduced-motion"])
if r.get("reduceMotionMatches"):
    check("'reduce motion' on: it does NOT turn by itself", abs(r.get("autoplayMoved", 99)) < 0.3, str(r.get("autoplayMoved")))
    check("...but dragging still works, and it stays where the visitor left it", abs(r.get("dragMovedWhenReduced", 0)) > 2 and r.get("keptStillAfterDrag"), json.dumps(r)[:200])
else:
    print("SKIP  this Chromium did not honour --force-prefers-reduced-motion, reduced-motion behaviour not tested")

# ------------------------------------------------------------------ a broken frame
r, _ = run("viewer", VIEWER_PAGE.replace("__MODE__", "fail"))
check("a frame that cannot be loaded: the viewer gives up cleanly, the still photo stays, no half-working spin", r.get("failed") and r.get("posterVisible") and r.get("canvasHidden"), json.dumps(r)[:240])

# ------------------------------------------------------------------ admin capture + upload
r, calls = run("capture", CAPTURE_PAGE.replace("__SCENARIO__", "ok").replace("__RELOAD__", "true"), timeout=120)
check("a video is turned into frames in the browser and previewed with the same viewer, before anything is uploaded",
      r.get("previewReady") and r.get("previewHasViewer"), json.dumps(r)[:300])
check("after a successful save the page reloads (to show the new spin)", r.get("reloadedAfterSave"), json.dumps(r)[:200])
check("12 frames asked for -> 12 JPEG frames, none bigger than 720 px, and they are different pictures", r.get("frameCount") == 12 and r.get("allJpeg") and r.get("maxSide", 999) <= 720 and r.get("distinctColours", 0) >= 6, f"{r.get('frameCount')} {r.get('maxSide')} {r.get('distinctColours')}")
actions = [c["action"] for c in calls]
check("upload order is start -> frames (in small batches) -> commit, every request carrying the CSRF token and the tree id",
      actions[:1] == ["start"] and actions[-1:] == ["commit"] and "frames" in actions and all(c["csrf"] and c["tree"] for c in calls), str(actions))
check("no batch carries more than 6 frames (PHP allows only 20 files per request)", all(c["files"] <= 6 for c in calls if c["action"] == "frames") and sum(c["files"] for c in calls if c["action"] == "frames") == 24, str([(c["action"], c["files"]) for c in calls]))

r, calls = run("capture", CAPTURE_PAGE.replace("__SCENARIO__", "failbatch2").replace("__RELOAD__", "false"), timeout=120)
actions = [c["action"] for c in calls]
check("a batch that fails stops the upload: NO commit is sent, so the live spin is never half replaced, and the admin is told", "commit" not in actions and "ไม่สำเร็จ" in r.get("statusAtEnd", ""), f"{actions} {r.get('statusAtEnd')}")

total, ok = len(results), sum(results)
print(f"\n{ok} passed, {total - ok} failed")
sys.exit(0 if ok == total else 1)
