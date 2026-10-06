"""ml/pull_feedback.py against a fake export endpoint (no website, no network, throwaway folder).

    python tests/pull_feedback_test.py
"""
import base64
import csv
import http.server
import io
import json
import os
import subprocess
import sys
import tempfile
import threading
from pathlib import Path
from urllib.parse import parse_qs, urlparse

from PIL import Image

ROOT = Path(__file__).resolve().parent.parent
TOKEN = "t" * 32


def jpeg(color) -> str:
    buf = io.BytesIO()
    Image.new("RGB", (800, 600), color).save(buf, "JPEG")
    return base64.b64encode(buf.getvalue()).decode()


SAMPLES = (
    [{"id": i, "name_scientific": "Newus plantus", "mime": "image/jpeg", "image_b64": jpeg((i * 20, 100, 50))} for i in range(1, 10)]   # 9 photos -> joins
    + [{"id": 10 + i, "name_scientific": "Rarus plantus", "mime": "image/jpeg", "image_b64": jpeg((10, i * 30, 50))} for i in range(2)]  # 2 photos -> waits
    + [{"id": 20, "name_scientific": "Cassia fistula", "mime": "image/jpeg", "image_b64": jpeg((200, 10, 10))}]                          # known species
    + [{"id": 21, "name_scientific": "Broken one", "mime": "image/jpeg", "image_b64": base64.b64encode(b"not an image").decode()}]
)
seen_auth = []

pass_n = fail_n = 0


def check(label: str, ok: bool, detail: str = "") -> None:
    global pass_n, fail_n
    if ok:
        pass_n += 1
        print("PASS ", label)
    else:
        fail_n += 1
        print("FAIL ", label, detail)


class H(http.server.BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def do_GET(self):
        seen_auth.append(self.headers.get("Authorization"))
        if self.headers.get("Authorization") != f"Bearer {TOKEN}":
            self.send_response(401)
            self.end_headers()
            return
        q = parse_qs(urlparse(self.path).query)
        since, limit = int(q["since"][0]), int(q["limit"][0])
        body = json.dumps({"ok": True, "samples": [s for s in SAMPLES if s["id"] > since][:limit]}).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)


server = http.server.ThreadingHTTPServer(("127.0.0.1", 8797), H)
threading.Thread(target=server.serve_forever, daemon=True).start()

with tempfile.TemporaryDirectory() as tmp:
    data = Path(tmp) / "data"
    data.mkdir()
    (data / "images.csv").write_text("path,gbif_label,license,creator,source_url\nraw/x/000.jpg,Cassia fistula,CC0,someone,u\n", encoding="utf-8")
    species = Path(tmp) / "species.csv"
    species.write_text("id,name_th,name_en,name_common,scientific,family,photos\n1,,,,Cassia fistula,,1\n", encoding="utf-8-sig")
    cmd = [sys.executable, str(ROOT / "ml" / "pull_feedback.py"), "--url", "http://127.0.0.1:8797/", "--data", str(data), "--species", str(species)]

    run = lambda token=TOKEN: subprocess.run(cmd, capture_output=True, text=True, env={**os.environ, "TRAINING_EXPORT_TOKEN": token, "PYTHONIOENCODING": "utf-8"})

    bad = run("w" * 32)
    check("a wrong token is rejected and nothing is written", bad.returncode != 0 and "401" in bad.stdout + bad.stderr and not (data / "feedback_last_id.txt").exists())
    short = run("short")
    check("a too-short token is refused before any request", short.returncode != 0 and "24 characters" in short.stdout + short.stderr)

    out = run()
    rows = list(csv.DictReader(open(data / "images.csv", encoding="utf-8")))
    new = [r for r in rows if r["license"] == "exhibition-upload"]
    check("the script ran and sent the token as a Bearer header", out.returncode == 0 and seen_auth[-1] == f"Bearer {TOKEN}", out.stderr[-300:])
    check("12 usable photos downloaded, the unreadable one skipped", len(new) == 12, str(len(new)))
    check("photos are saved as shrunken JPEGs under raw/_feedback/<species>/",
          all((data / r["path"]).is_file() and max(Image.open(data / r["path"]).size) <= 512 for r in new) and new[0]["path"].startswith("raw/_feedback/"))
    check("the last id is remembered", (data / "feedback_last_id.txt").read_text().strip() == "21")
    names = {r["scientific"] for r in csv.DictReader(open(species, encoding="utf-8-sig"))}
    check("a species with 9 photos joins the class list", "Newus plantus" in names)
    check("a species with only 2 photos waits, a known one is not added twice", "Rarus plantus" not in names and "Rarus plantus" in out.stdout and list(names).count("Cassia fistula") == 1)

    again = run()
    rows2 = list(csv.DictReader(open(data / "images.csv", encoding="utf-8")))
    check("running again downloads nothing new and duplicates nothing", "0 new photos" in again.stdout and len(rows2) == len(rows))

server.shutdown()
print(f"\n{pass_n} passed, {fail_n} failed")
sys.exit(0 if fail_n == 0 else 1)
