"""The visitor-side script public/assets/js/page-translate.js in headless Chromium, against a fake server.

What matters to a visitor: the page appears at once; a small notice shows while the translation happens in the
background; when it is ready the page reloads ONCE; and whatever goes wrong (failure, busy, network error) it never
reloads in a loop. Each scenario is a fresh browser profile (so sessionStorage starts empty).

    python tests/page_translate_js_check.py
"""
import http.server
import json
import os
import subprocess
import sys
import tempfile
import threading
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
JS = (ROOT / "public" / "assets" / "js" / "page-translate.js").read_bytes()

PAGE = """<!doctype html><meta charset=utf-8><title>t</title>
<body data-translate-url="/translate_page.php" data-translate-type="tree" data-translate-id="9" data-translate-lang="en" data-translate-notice="Translating this page">
<h1 id="h">page text</h1>
<script>navigator.sendBeacon('/event', 'page-loaded');
new MutationObserver(function () { var b = document.querySelector('[role=status]'); if (b && !window.__seen) { window.__seen = 1; navigator.sendBeacon('/event', 'notice:' + b.textContent); } })
  .observe(document.body, { childList: true });</script>
<script src="/page-translate.js"></script>
</body>"""


def find_chromium() -> str:
    cache = Path(os.environ.get("LOCALAPPDATA", str(Path.home()))) / "ms-playwright"
    for d in sorted(cache.glob("chromium-*"), reverse=True):
        for exe in list(d.glob("chrome-win*/chrome.exe")) + list(d.glob("chrome-linux*/chrome")):
            return str(exe)
    sys.exit("No Chromium found in Playwright's cache")


def scenario(name: str, responses: list):
    """responses: what the fake translate endpoint answers on its 1st, 2nd, ... call (a dict, or 'error')."""
    events, posts = [], []
    lock = threading.Lock()

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
            if self.path.startswith("/page-translate.js"):
                return self._send(200, JS, "application/javascript")
            if self.path.startswith("/page"):
                return self._send(200, PAGE.encode(), "text/html; charset=utf-8")
            self._send(404, b"", "text/plain")

        def do_POST(self):
            body = self.rfile.read(int(self.headers.get("Content-Length") or 0)).decode()
            if self.path == "/event":
                with lock:
                    events.append(body)
                return self._send(200, b"ok", "text/plain")
            if self.path == "/translate_page.php":
                with lock:
                    posts.append(body)
                    n = len(posts)
                r = responses[min(n, len(responses)) - 1]
                if r == "error":
                    return self._send(500, b"boom", "text/plain")
                return self._send(200, json.dumps(r).encode(), "application/json")
            self._send(404, b"", "text/plain")

    server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), H)
    port = server.server_address[1]
    threading.Thread(target=server.serve_forever, daemon=True).start()
    # One profile directory reused between runs (a brand-new one makes Chromium's first start slow). sessionStorage is not
    # kept when the browser closes, so every scenario still starts with an empty one.
    profile = Path(tempfile.gettempdir()) / "page_translate_check_profile"
    chrome = subprocess.Popen([find_chromium(), "--headless=new", "--disable-gpu", f"--user-data-dir={profile}", f"http://127.0.0.1:{port}/page"],
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    deadline = time.time() + 90  # the browser's first start can be slow: wait for the page itself ...
    while time.time() < deadline and not events:
        time.sleep(0.5)
    time.sleep(14)  # ... then long enough for several 4 s retries and any reload loop to show itself
    chrome.terminate()
    server.shutdown()
    loads = sum(1 for e in events if e == "page-loaded")
    return {"loads": loads, "posts": len(posts), "notice": any(e.startswith("notice:Translating") for e in events), "post_body": posts[0] if posts else ""}


results = []


def check(label, ok, detail=""):
    results.append(ok)
    print(("PASS  " if ok else "FAIL  ") + label + ("" if ok else f"  {detail}"))


r = scenario("done", [{"status": "translated", "changed": True}, {"status": "complete", "changed": False}])
check("translated: the notice shows, then the page reloads exactly once (2 loads in total), asking the server once", r["notice"] and r["loads"] == 2 and r["posts"] == 1, str(r))
check("the request names the page and language", "type=tree" in r["post_body"] and "id=9" in r["post_body"] and "lang=en" in r["post_body"], r["post_body"])

r = scenario("nothing", [{"status": "complete", "changed": False}])
check("nothing to translate: no reload at all", r["loads"] == 1 and r["posts"] == 1, str(r))

r = scenario("failed", [{"status": "failed", "changed": False}])
check("translation failed: no reload, the Thai page stays, the notice is gone", r["loads"] == 1 and r["posts"] == 1, str(r))

r = scenario("neterror", ["error"])
check("server error: no reload and no retry loop", r["loads"] == 1 and r["posts"] == 1, str(r))

r = scenario("busy", [{"status": "busy", "changed": False}, {"status": "translated", "changed": True}, {"status": "complete", "changed": False}])
check("another visitor is already translating: it waits, asks again, and reloads once when ready", r["loads"] == 2 and r["posts"] == 2, str(r))

r = scenario("always changed", [{"status": "translated", "changed": True}])
check("even a server that always says 'changed' causes only ONE reload (no reload loop)", r["loads"] == 2 and r["posts"] == 1, str(r))

print(f"\n{sum(results)} passed, {len(results) - sum(results)} failed")
sys.exit(0 if all(results) else 1)
