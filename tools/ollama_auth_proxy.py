"""Password-protects a local Ollama so it can be reached through a tunnel.

Listens on 127.0.0.1:11435 and forwards to Ollama on 127.0.0.1:11434, but only
for requests carrying `Authorization: Bearer <token>`; everything else gets 401.
Point the tunnel at port 11435 (NOT 11434) and put the same token in the
deployed site's OLLAMA_API_KEY variable.

    set OLLAMA_PROXY_TOKEN=<long random string>
    python tools/ollama_auth_proxy.py
"""
import hmac
import os
import sys
import urllib.error
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

TOKEN = os.environ.get("OLLAMA_PROXY_TOKEN", "")
UPSTREAM = os.environ.get("OLLAMA_UPSTREAM", "http://127.0.0.1:11434")
PORT = int(os.environ.get("OLLAMA_PROXY_PORT", "11435"))
ALLOWED = ("/api/chat", "/api/version")  # all the website needs


class Handler(BaseHTTPRequestHandler):
    def _deny(self, code: int, msg: str) -> None:
        self.send_response(code)
        self.send_header("Content-Length", str(len(msg)))
        self.end_headers()
        self.wfile.write(msg.encode())

    def _handle(self) -> None:
        given = self.headers.get("Authorization", "")
        if not hmac.compare_digest(given.encode(), f"Bearer {TOKEN}".encode()):
            return self._deny(401, "unauthorized")
        if self.path.split("?")[0] not in ALLOWED:
            return self._deny(404, "not found")
        body = self.rfile.read(int(self.headers.get("Content-Length") or 0)) if self.command == "POST" else None
        req = urllib.request.Request(UPSTREAM + self.path, data=body, method=self.command, headers={"Content-Type": "application/json"})
        try:
            with urllib.request.urlopen(req, timeout=600) as r:
                data, code = r.read(), r.status
        except urllib.error.HTTPError as e:
            data, code = e.read(), e.code
        except Exception:
            return self._deny(502, "ollama unreachable")
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    do_GET = do_POST = _handle

    def log_message(self, *a):  # keep the console quiet; no request bodies in logs
        pass


if __name__ == "__main__":
    if len(TOKEN) < 24:
        sys.exit("Set OLLAMA_PROXY_TOKEN to a random string of at least 24 characters.")
    print(f"Ollama auth proxy on 127.0.0.1:{PORT} -> {UPSTREAM}")
    ThreadingHTTPServer(("127.0.0.1", PORT), Handler).serve_forever()
