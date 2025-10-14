#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
NoteTest.py — E2E test sekcji Notes (Laravel + JWT), konsola minimalistyczna:
- progres PASS/FAIL w trakcie
- na końcu jedna tabela (bez nadmiarowych kolumn)

HTML: pełne szczegóły każdego endpointu (nagłówki + ciała + odpowiedzi), maskowanie tokenów/haseł.

Trasy (wg routes):
  GET    /api/me/notes
  POST   /api/me/notes                      (store, multipart)
  PATCH  /api/me/notes/{id}                 (edit, JSON)  ← preferowane
  PUT    /api/me/notes/{id}                 (edit, JSON)  ← fallback, jeśli brak PATCH
  POST   /api/me/notes/{id}/patch           (patchFile, multipart)
  GET    /api/me/notes/{id}/download
  DELETE /api/me/notes/{id}

Raport HTML zapisuje się do:
  ./tests/E2E/result/Note/ResultE2E--YYYY-MM-DD--HH-MM-SS/APITestReport.html
"""

from __future__ import annotations

import argparse
import io
import json
import os
import random
import string
import sys
import time
from dataclasses import dataclass, field
from typing import Any, Callable, Dict, List, Optional, Tuple

import requests
from colorama import Fore, Style, init as colorama_init
from tabulate import tabulate

try:
    from PIL import Image, ImageDraw
    PIL_AVAILABLE = True
except Exception:
    PIL_AVAILABLE = False

# ───────────────────────── UI ─────────────────────────
ICON_OK   = "✅"
ICON_FAIL = "❌"
ICON_INFO = "ℹ️"
ICON_USER = "👤"
ICON_LOCK = "🔒"
ICON_PATCH= "🩹"
ICON_IMG  = "🖼️"
ICON_TRASH= "🗑️"
ICON_CLOCK= "⏱️"
BOX = "─" * 84
MAX_BODY_LOG = 8000

def c(txt: str, color: str) -> str:
    return f"{color}{txt}{Style.RESET_ALL}"

def trim(s: str, n: int = 180) -> str:
    s = s.replace("\n", " ")
    return s if len(s) <= n else s[: n - 1] + "…"

def pretty_json(obj: Any) -> str:
    try:
        return json.dumps(obj, ensure_ascii=False, indent=2)
    except Exception:
        return str(obj)

def as_text(b: bytes) -> str:
    try:
        s = b.decode("utf-8", errors="replace")
    except Exception:
        s = str(b)
    return s if len(s) <= MAX_BODY_LOG else s[:MAX_BODY_LOG] + "\n…(truncated)"

def mask_token(v: str) -> str:
    if not isinstance(v, str): return v
    if v.lower().startswith("bearer "):
        t = v.split(" ", 1)[1]
        if len(t) <= 12:
            return "Bearer ******"
        return "Bearer " + t[:6] + "…" + t[-4:]
    return v

SENSITIVE_KEYS = {"password", "password_confirmation", "token"}

def mask_json_sensitive(data: Any) -> Any:
    if isinstance(data, dict):
        return {k: ("***" if k in SENSITIVE_KEYS else mask_json_sensitive(v)) for k, v in data.items()}
    if isinstance(data, list):
        return [mask_json_sensitive(x) for x in data]
    return data

def mask_headers_sensitive(h: Dict[str,str]) -> Dict[str,str]:
    out = {}
    for k, v in h.items():
        if k.lower() in ("authorization", "cookie", "set-cookie"):
            out[k] = mask_token(v) if k.lower()=="authorization" else "<hidden>"
        else:
            out[k] = v
    return out

# ───────────────────────── CLI ─────────────────────────
def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(description="NoteSync Notes API E2E")
    p.add_argument("--base-url", required=True, help="np. http://localhost:8000")
    p.add_argument("--me-prefix", default="me", help="prefiks dla /api/<prefix> (domyślnie 'me')")
    p.add_argument("--timeout", type=int, default=20, help="timeout żądań w sekundach")
    p.add_argument("--note-file", default=r"C:\xampp\htdocs\LaravelNS\tests\E2E\sample.png", help="plik do uploadu notatki (pdf/xlsx/jpg/jpeg/png)")
    p.add_argument("--html-report", action="store_true", help="(flaga utrzymana dla kompatybilności) — raport i tak zapisujemy do /tests/E2E/result/Note/ResultE2E--DATA--GODZINA")
    return p.parse_args()

# ───────────────────────── Struktury ─────────────────────────
@dataclass
class EndpointLog:
    title: str
    method: str
    url: str
    req_headers: Dict[str, Any]
    req_body: Any
    req_is_json: bool
    resp_status: Optional[int] = None
    resp_headers: Dict[str, Any] = field(default_factory=dict)
    resp_body: Optional[str] = None
    duration_ms: float = 0.0
    notes: List[str] = field(default_factory=list)

@dataclass
class TestRecord:
    name: str
    passed: bool
    duration_ms: float
    method: str = ""
    url: str = ""
    status: Optional[int] = None
    error: Optional[str] = None

@dataclass
class TestContext:
    base_url: str
    me_prefix: str
    ses: requests.Session
    tokenA: Optional[str] = None
    tokenB: Optional[str] = None
    emailA: str = ""
    pwdA: str = ""
    emailB: str = ""
    pwdB: str = ""
    note_id: Optional[int] = None
    started_at: float = field(default_factory=time.time)
    timeout: int = 20
    endpoints: List[EndpointLog] = field(default_factory=list)
    note_file_path: str = ""
    output_dir: str = ""  # gdzie zapiszemy raport

# ───────────────────────── Helpers ─────────────────────────
def build(ctx: TestContext, path: str) -> str:
    return f"{ctx.base_url.rstrip('/')}{path}"

def me(ctx: TestContext, path: str) -> str:
    return f"{ctx.base_url.rstrip('/')}/api/{ctx.me_prefix.strip('/')}{path}"

def auth_headers(token: Optional[str]) -> Dict[str, str]:
    h = {"Accept": "application/json"}
    if token:
        h["Authorization"] = f"Bearer {token}"
    return h

def rnd_email() -> str:
    token = "".join(random.choices(string.ascii_lowercase + string.digits, k=8))
    return f"tester.{token}@example.com"

def must_json(resp: requests.Response) -> Dict[str, Any]:
    try:
        return resp.json()
    except Exception:
        raise AssertionError(f"Odpowiedź nie-JSON (CT={resp.headers.get('Content-Type')}): {trim(resp.text)}")

def gen_png_bytes() -> bytes:
    from PIL import Image, ImageDraw  # type: ignore
    img = Image.new("RGBA", (120, 120), (24, 28, 40, 255))
    d = ImageDraw.Draw(img)
    d.ellipse((10, 10, 110, 110), fill=(70, 160, 255, 255))
    buf = io.BytesIO()
    img.save(buf, format="PNG")
    return buf.getvalue()

def security_header_notes(resp: requests.Response) -> List[str]:
    wanted = ["X-Content-Type-Options","X-Frame-Options","Referrer-Policy",
              "Content-Security-Policy","X-XSS-Protection","Strict-Transport-Security"]
    miss = [k for k in wanted if k not in resp.headers]
    return [f"Missing security headers: {', '.join(miss)}"] if miss else []

def log_exchange(ctx: TestContext, el: EndpointLog, resp: Optional[requests.Response]):
    if resp is not None:
        ct = resp.headers.get("Content-Type","")
        if "application/json" in ct.lower():
            try:
                b = mask_json_sensitive(resp.json())
                resp_body = pretty_json(b)
            except Exception:
                resp_body = as_text(resp.content)
        elif "image" in ct.lower() or "octet-stream" in ct.lower():
            resp_body = f"<binary> bytes={len(resp.content)} content-type={ct}"
        else:
            resp_body = as_text(resp.content)

        el.resp_status  = resp.status_code
        el.resp_headers = {k: str(v) for k, v in resp.headers.items()}
        el.resp_body    = resp_body if len(resp_body) <= MAX_BODY_LOG else resp_body[:MAX_BODY_LOG] + "\n…(truncated)"
        el.notes.extend(security_header_notes(resp))
    ctx.endpoints.append(el)

def http_json(ctx: TestContext, title: str, method: str, url: str,
              json_body: Optional[Dict[str, Any]], headers: Dict[str,str]) -> requests.Response:
    hs = dict(headers or {})
    req_headers_log = mask_headers_sensitive(hs.copy())
    t0 = time.time()
    if method == "GET":
        resp = ctx.ses.get(url, headers=hs, timeout=ctx.timeout)
    elif method == "POST":
        resp = ctx.ses.post(url, headers=hs, json=json_body, timeout=ctx.timeout)
    elif method == "PATCH":
        resp = ctx.ses.patch(url, headers=hs, json=json_body, timeout=ctx.timeout)
    elif method == "PUT":
        resp = ctx.ses.put(url, headers=hs, json=json_body, timeout=ctx.timeout)
    elif method == "DELETE":
        resp = ctx.ses.delete(url, headers=hs, timeout=ctx.timeout)
    else:
        raise RuntimeError(f"Unsupported method {method}")
    el = EndpointLog(
        title=title,
        method=method,
        url=url,
        req_headers=req_headers_log,
        req_body=json_body if json_body is not None else {},
        req_is_json=True,
        duration_ms=(time.time() - t0) * 1000.0
    )
    log_exchange(ctx, el, resp)
    return resp

def http_multipart(ctx: TestContext, title: str, url: str,
                   data: Dict[str, Any], files: Dict[str, Tuple[str, bytes, str]],
                   headers: Dict[str,str]) -> requests.Response:
    hs = dict(headers or {})
    req_headers_log = mask_headers_sensitive(hs.copy())
    friendly_body = {"fields": mask_json_sensitive(data),
                     "files": {k: {"filename": v[0], "bytes": len(v[1]), "content_type": v[2]} for k, v in files.items()}}
    t0 = time.time()
    resp = ctx.ses.post(url, headers=hs, data=data, files=files, timeout=ctx.timeout)
    el = EndpointLog(
        title=title,
        method="POST(multipart)",
        url=url,
        req_headers=req_headers_log,
        req_body=friendly_body,
        req_is_json=False,
        duration_ms=(time.time() - t0) * 1000.0
    )
    log_exchange(ctx, el, resp)
    return resp

# 🔁 PATCH→PUT fallback dla update treści
def http_json_update(ctx: TestContext, base_title: str, url: str,
                     json_body: Dict[str, Any], headers: Dict[str,str]) -> Tuple[requests.Response, str]:
    # 1) próbuj PATCH
    r = http_json(ctx, f"{base_title} (PATCH)", "PATCH", url, json_body, headers)
    if r.status_code == 405:
        # 2) fallback PUT
        r2 = http_json(ctx, f"{base_title} (PUT fallback)", "PUT", url, json_body, headers)
        return r2, "PUT"
    return r, "PATCH"

# ───────────────────────── Output dir ─────────────────────────
def build_output_dir() -> str:
    root = os.getcwd()
    date_str = time.strftime("%Y-%m-%d")
    time_str = time.strftime("%H-%M-%S")  # bez dwukropków → Windows-safe
    folder = f"ResultE2E--{date_str}--{time_str}"
    out_dir = os.path.join(root, "tests", "E2E", "results", "Note", folder)
    os.makedirs(out_dir, exist_ok=True)
    return out_dir

# ───────────────────────── Runner ─────────────────────────
class ApiTester:
    def __init__(self, ctx: TestContext):
        self.ctx = ctx
        self.results: List[TestRecord] = []

    def run(self):
        steps: List[Tuple[str, Callable[[], Dict[str, Any]]]] = [
            ("👤 Rejestracja (A)", self.t_register_A),
            ("🔒 Login (A)", self.t_login_A),
            ("🗒️ Index (initial)", self.t_index_initial),
            ("🖼️ Store: missing file → 400/422", self.t_store_missing_file),
            ("🖼️ Store: invalid mime → 400/422", self.t_store_invalid_mime),
            ("🖼️ Store: ok (multipart)", self.t_store_ok),
            ("🗒️ Index contains created + pagination", self.t_index_contains_created),
            ("👤 Rejestracja (B)", self.t_register_B),
            ("🔒 Login (B)", self.t_login_B),
            ("🔒 Foreign download → 403", self.t_download_foreign_403),
            ("🔒 Back to A", self.t_login_A_again),
            ("🩹 PATCH title only", self.t_patch_title_only),
            ("🩹 PATCH is_private invalid → 400/422", self.t_patch_is_private_invalid),
            ("🩹 PATCH description + is_private=false", self.t_patch_desc_priv_false),
            ("🖼️ POST …/{id}/patch: missing file → 400/422", self.t_patch_file_missing),
            ("🖼️ POST …/{id}/patch: ok", self.t_patch_file_ok),
            ("⬇️ Download note file (200)", self.t_download_file_ok),
            ("🗑️ DELETE note", self.t_delete_note),
            ("⬇️ Download after delete → 404", self.t_download_after_delete_404),
            ("🗒️ Index after delete (not present)", self.t_index_after_delete),
        ]
        total = len(steps)
        for i, (name, fn) in enumerate(steps, 1):
            self._exec(i, total, name, fn)
        self._summary()

    def _exec(self, idx: int, total: int, name: str, fn: Callable[[], Dict[str, Any]]):
        start = time.time()
        rec = TestRecord(name=name, passed=False, duration_ms=0)
        print(c(f"[{idx:02d}/{total:02d}] {name} …", Fore.CYAN), end=" ")
        try:
            ret = fn() or {}
            rec.passed = True
            rec.status = ret.get("status")
            rec.method = ret.get("method","")
            rec.url    = ret.get("url","")
            print(c("PASS", Fore.GREEN))
        except AssertionError as e:
            rec.error = str(e)
            print(c("FAIL", Fore.RED), c(f"— {e}", Fore.RED))
        except Exception as e:
            rec.error = f"{type(e).__name__}: {e}"
            print(c("ERROR", Fore.RED), c(f"— {e}", Fore.RED))
        rec.duration_ms = (time.time() - start) * 1000.0
        self.results.append(rec)

    # ───────────────────────── Testy ─────────────────────────
    def t_register_A(self):
        self.ctx.emailA = rnd_email(); self.ctx.pwdA = "Haslo123123"
        url = build(self.ctx, "/api/users/register")
        r = http_json(self.ctx, "Register A", "POST", url,
                      {"name":"Tester A","email":self.ctx.emailA,"password":self.ctx.pwdA,"password_confirmation":self.ctx.pwdA},
                      {"Accept":"application/json"})
        assert r.status_code in (200,201), f"Register A {r.status_code}: {trim(r.text)}"
        return {"status": r.status_code, "method":"POST", "url":url}

    def t_login_A(self):
        url = build(self.ctx,"/api/login")
        r = http_json(self.ctx, "Login A", "POST", url, {"email":self.ctx.emailA,"password":self.ctx.pwdA}, {"Accept":"application/json"})
        assert r.status_code == 200, f"Login A {r.status_code}: {trim(r.text)}"
        self.ctx.tokenA = must_json(r).get("token")
        assert self.ctx.tokenA, "Brak tokenu JWT (A)"
        return {"status": 200, "method":"POST","url":url}

    def t_index_initial(self):
        url = me(self.ctx,"/notes?top=10&skip=0")
        r = http_json(self.ctx, "Index initial", "GET", url, None, auth_headers(self.ctx.tokenA))
        assert r.status_code == 200, f"Index {r.status_code}: {trim(r.text)}"
        js = must_json(r)
        assert "data" in js and isinstance(js["data"], list), "Brak listy 'data'"
        assert "count" in js, "Brak 'count'"
        return {"status": 200, "method":"GET","url":url}

    def t_store_missing_file(self):
        url = me(self.ctx,"/notes")
        r = http_multipart(self.ctx, "Store missing file", url, data={"title":"NoFile"}, files={}, headers=auth_headers(self.ctx.tokenA))
        assert r.status_code in (400,422), f"Missing file powinno dać 400/422, jest {r.status_code}"
        return {"status": r.status_code, "method":"POST","url":url}

    def t_store_invalid_mime(self):
        url = me(self.ctx,"/notes")
        fake = b"hello world"  # text/plain
        files = {"file": ("note.txt", fake, "text/plain")}
        data  = {"title":"BadMime"}
        r = http_multipart(self.ctx, "Store invalid mime", url, data=data, files=files, headers=auth_headers(self.ctx.tokenA))
        assert r.status_code in (400,422), f"Invalid mime powinno dać 400/422, jest {r.status_code}"
        return {"status": r.status_code, "method":"POST","url":url}

    def _load_upload_bytes(self, path: str) -> Tuple[bytes, str, str]:
        if path and os.path.isfile(path):
            name = os.path.basename(path)
            ext = os.path.splitext(path)[1].lower().lstrip(".")
            mime = {
                "png":"image/png","jpg":"image/jpeg","jpeg":"image/jpeg",
                "pdf":"application/pdf","xlsx":"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
            }.get(ext, "application/octet-stream")
            with open(path, "rb") as f:
                return f.read(), mime, name
        if PIL_AVAILABLE:
            return gen_png_bytes(), "image/png", "gen.png"
        return b"\x89PNG\r\n\x1a\n", "image/png", "raw.png"

    def t_store_ok(self):
        url = me(self.ctx,"/notes")
        data_bytes, mime, name = self._load_upload_bytes(NOTE_FILE_PATH)
        files = {"file": (name, data_bytes, mime)}
        # NIE wysyłamy 'is_private' na multipart → domyślne true po stronie backendu
        data  = {"title":"First Note","description":"Test file upload"}
        r = http_multipart(self.ctx, "Store ok", url, data=data, files=files, headers=auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,201), f"Store {r.status_code}: {trim(r.text)}"
        js = must_json(r)
        assert "note" in js and "id" in js["note"], "Brak 'note.id' w odpowiedzi"
        self.ctx.note_id = js["note"]["id"]
        return {"status": r.status_code, "method":"POST","url":url}

    def t_index_contains_created(self):
        assert self.ctx.note_id, "Brak utworzonej notatki"
        url = me(self.ctx, f"/notes?top=50&skip=0")
        r = http_json(self.ctx, "Index contains", "GET", url, None, auth_headers(self.ctx.tokenA))
        assert r.status_code == 200, f"Index {r.status_code}: {trim(r.text)}"
        js = must_json(r)
        ids = [n.get("id") for n in js.get("data",[])]
        assert self.ctx.note_id in ids, f"Utworzona notatka id={self.ctx.note_id} nie jest widoczna na liście"
        # paginacja poza zakresem
        count = int(js.get("count", 0))
        url2 = me(self.ctx, f"/notes?top=10&skip={count}")
        r2 = http_json(self.ctx, "Index beyond", "GET", url2, None, auth_headers(self.ctx.tokenA))
        assert r2.status_code == 200, f"Index beyond {r2.status_code}"
        js2 = must_json(r2)
        assert isinstance(js2.get("data",[]), list) and len(js2["data"]) == 0, "Paginacja 'skip>=count' powinna dać pustą listę"
        return {"status": 200, "method":"GET","url":url}

    def t_register_B(self):
        self.ctx.emailB = rnd_email(); self.ctx.pwdB = "Haslo123123"
        url = build(self.ctx, "/api/users/register")
        r = http_json(self.ctx, "Register B", "POST", url,
                      {"name":"Tester B","email":self.ctx.emailB,"password":self.ctx.pwdB,"password_confirmation":self.ctx.pwdB},
                      {"Accept":"application/json"})
        assert r.status_code in (200,201), f"Register B {r.status_code}: {trim(r.text)}"
        return {"status": r.status_code, "method":"POST", "url":url}

    def t_login_B(self):
        url = build(self.ctx,"/api/login")
        r = http_json(self.ctx, "Login B", "POST", url, {"email":self.ctx.emailB,"password":self.ctx.pwdB}, {"Accept":"application/json"})
        assert r.status_code == 200, f"Login B {r.status_code}: {trim(r.text)}"
        self.ctx.tokenB = must_json(r).get("token")
        assert self.ctx.tokenB, "Brak tokenu JWT (B)"
        return {"status": 200, "method":"POST","url":url}

    def t_download_foreign_403(self):
        assert self.ctx.note_id, "Brak notatki A"
        url = me(self.ctx, f"/notes/{self.ctx.note_id}/download")
        r = http_json(self.ctx, "Download foreign", "GET", url, None, auth_headers(self.ctx.tokenB))
        assert r.status_code == 403, f"Obca notatka powinna zwrócić 403, jest {r.status_code}"
        return {"status": 403, "method":"GET","url":url}

    def t_login_A_again(self):
        url = build(self.ctx,"/api/login")
        r = http_json(self.ctx, "Login A again", "POST", url, {"email":self.ctx.emailA,"password":self.ctx.pwdA}, {"Accept":"application/json"})
        assert r.status_code == 200, f"Re-login A {r.status_code}"
        self.ctx.tokenA = must_json(r).get("token")
        return {"status": 200, "method":"POST","url":url}

    def t_patch_title_only(self):
        assert self.ctx.note_id, "Brak notatki"
        url = me(self.ctx, f"/notes/{self.ctx.note_id}")
        r, used = http_json_update(self.ctx, "Update title only", url, {"title":"Renamed Note"}, auth_headers(self.ctx.tokenA))
        assert r.status_code == 200, f"PATCH/PUT title {r.status_code}: {trim(r.text)}"
        js = must_json(r)
        assert js.get("note",{}).get("title") == "Renamed Note", "Tytuł nie zaktualizowany"
        return {"status": 200, "method": used, "url": url}

    def t_patch_is_private_invalid(self):
        assert self.ctx.note_id, "Brak notatki"
        url = me(self.ctx, f"/notes/{self.ctx.note_id}")
        r, used = http_json_update(self.ctx, "Update invalid is_private", url, {"is_private":"notbool"}, auth_headers(self.ctx.tokenA))
        assert r.status_code in (400,422), f"Zła wartość is_private powinna dać 400/422, jest {r.status_code}"
        return {"status": r.status_code, "method": used, "url": url}

    def t_patch_desc_priv_false(self):
        assert self.ctx.note_id, "Brak notatki"
        url = me(self.ctx, f"/notes/{self.ctx.note_id}")
        r, used = http_json_update(self.ctx, "Update desc + is_private=false", url, {"description":"Updated body","is_private": False}, auth_headers(self.ctx.tokenA))
        assert r.status_code == 200, f"PATCH/PUT desc {r.status_code}: {trim(r.text)}"
        js = must_json(r)
        assert js.get("note",{}).get("description") == "Updated body", "Opis nie zaktualizowany"
        assert js.get("note",{}).get("is_private") in (False, 0), "is_private nie false"
        return {"status": 200, "method": used, "url": url}

    def t_patch_file_missing(self):
        assert self.ctx.note_id, "Brak notatki"
        url = me(self.ctx, f"/notes/{self.ctx.note_id}/patch")
        r = http_multipart(self.ctx, "PATCH file missing", url, data={}, files={}, headers=auth_headers(self.ctx.tokenA))
        assert r.status_code in (400,422), f"Brak pliku powinien dać 400/422, jest {r.status_code}"
        return {"status": r.status_code, "method":"POST","url":url}

    def t_patch_file_ok(self):
        assert self.ctx.note_id, "Brak notatki"
        url = me(self.ctx, f"/notes/{self.ctx.note_id}/patch")
        data_bytes, mime, name = self._load_upload_bytes(NOTE_FILE_PATH)
        files = {"file": (f"re_{name}", data_bytes, mime)}
        r = http_multipart(self.ctx, "PATCH file ok", url, data={}, files=files, headers=auth_headers(self.ctx.tokenA))
        assert r.status_code == 200, f"PATCH file {r.status_code}: {trim(r.text)}"
        return {"status": 200, "method":"POST","url":url}

    def t_download_file_ok(self):
        assert self.ctx.note_id, "Brak notatki"
        url = me(self.ctx, f"/notes/{self.ctx.note_id}/download")
        r = http_json(self.ctx, "Download file", "GET", url, None, auth_headers(self.ctx.tokenA))
        assert r.status_code == 200, f"Download {r.status_code}"
        return {"status": 200, "method":"GET","url":url}

    def t_delete_note(self):
        assert self.ctx.note_id, "Brak notatki"
        url = me(self.ctx, f"/notes/{self.ctx.note_id}")
        r = http_json(self.ctx, "DELETE note", "DELETE", url, None, auth_headers(self.ctx.tokenA))
        assert r.status_code == 200, f"Delete {r.status_code}: {trim(r.text)}"
        return {"status": 200, "method":"DELETE","url":url}

    def t_download_after_delete_404(self):
        assert self.ctx.note_id, "Brak notatki"
        url = me(self.ctx, f"/notes/{self.ctx.note_id}/download")
        r = http_json(self.ctx, "Download after delete", "GET", url, None, auth_headers(self.ctx.tokenA))
        assert r.status_code == 404, f"Po usunięciu powinniśmy dostać 404, jest {r.status_code}"
        return {"status": 404, "method":"GET","url":url}

    def t_index_after_delete(self):
        url = me(self.ctx,"/notes?top=100&skip=0")
        r = http_json(self.ctx, "Index after delete", "GET", url, None, auth_headers(self.ctx.tokenA))
        assert r.status_code == 200, f"Index after delete {r.status_code}"
        js = must_json(r)
        ids = [n.get("id") for n in js.get("data",[])]
        assert self.ctx.note_id not in ids, "Usunięta notatka nadal widoczna"
        return {"status": 200, "method":"GET","url":url}

    # ───────────────────────── Podsumowanie ─────────────────────────
    def _summary(self):
        ok = [r for r in self.results if r.passed]
        fail = [r for r in self.results if not r.passed]

        print("\n" + BOX)
        print(c(f"{ICON_CLOCK} PODSUMOWANIE", Fore.WHITE))
        print(BOX)

        def http_color(s: Optional[int]) -> str:
            if s is None: return ""
            if 200 <= s < 300: return c(str(s), Fore.GREEN)
            if 400 <= s < 500: return c(str(s), Fore.YELLOW)
            return c(str(s), Fore.RED)

        rows = []
        for r in self.results:
            outcome = c("PASS", Fore.GREEN) if r.passed else c("FAIL", Fore.RED)
            rows.append([r.name, outcome, f"{r.duration_ms:.1f} ms", r.method or "", r.url or "", http_color(r.status)])

        print(tabulate(
            rows,
            headers=["Test", "Wynik", "Czas", "Metoda", "URL", "HTTP"],
            tablefmt="fancy_grid",
            colalign=("left", "center", "right", "left", "left", "center"),
            disable_numparse=True
        ))

        total_ms = (time.time() - self.ctx.started_at) * 1000.0
        print(f"\nŁączny czas: {total_ms:.1f} ms | Testów: {len(self.results)} | PASS: {len(ok)} | FAIL: {len(fail)}\n")

        write_html_report(self.ctx, self.results)

# ───────────────────────── HTML Raport ─────────────────────────
def write_html_report(ctx: TestContext, results: List[TestRecord]):
    # buduj katalog docelowy
    out_dir = ctx.output_dir or build_output_dir()
    ctx.output_dir = out_dir  # zapamiętaj w kontekście
    path = os.path.join(out_dir, "APITestReport.html")

    ep_html = []
    for i, ep in enumerate(ctx.endpoints, 1):
        req_h = pretty_json(ep.req_headers)
        req_b = pretty_json(mask_json_sensitive(ep.req_body)) if ep.req_is_json else pretty_json(ep.req_body)
        resp_h = pretty_json(ep.resp_headers)
        resp_b = ep.resp_body or ""
        notes  = "<br/>".join(ep.notes) if ep.notes else ""
        ep_html.append(f"""
<section class="endpoint">
  <h2>{i}. {ep.title}</h2>
  <div class="meta"><span class="m">{ep.method}</span> <code>{ep.url}</code> <span class="dur">{ep.duration_ms:.1f} ms</span> <span class="st">{ep.resp_status if ep.resp_status is not None else ''}</span></div>
  {"<p class='note'>"+notes+"</p>" if notes else ""}
  <details open>
    <summary>Request</summary>
    <h3>Headers</h3>
    <pre>{(req_h).replace("&","&amp;").replace("<","&lt;").replace(">","&gt;")}</pre>
    <h3>Body</h3>
    <pre>{(req_b[:MAX_BODY_LOG] + ("\\n…(truncated)" if len(req_b)>MAX_BODY_LOG else "")).replace("&","&amp;").replace("<","&lt;").replace(">","&gt;")}</pre>
  </details>
  <details open>
    <summary>Response</summary>
    <h3>Headers</h3>
    <pre>{(resp_h).replace("&","&amp;").replace("<","&lt;").replace(">","&gt;")}</pre>
    <h3>Body</h3>
    <pre>{(resp_b[:MAX_BODY_LOG] + ("\\n…(truncated)" if len(resp_b)>MAX_BODY_LOG else "")).replace("&","&amp;").replace("<","&lt;").replace(">","&gt;")}</pre>
  </details>
</section>
""")

    rows = []
    for r in results:
        rows.append(f"""
<tr class="{ 'pass' if r.passed else 'fail' }">
  <td>{r.name}</td>
  <td>{'PASS' if r.passed else 'FAIL'}</td>
  <td>{r.duration_ms:.1f} ms</td>
  <td>{(r.method or '')}</td>
  <td><code>{(r.url or '')}</code></td>
  <td>{r.status or ''}</td>
</tr>
""")

    html = f"""<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8" />
<title>NoteSync — Notes API Test Report</title>
<style>
:root {{
  --bg:#0b0d12; --panel:#0f1320; --ink:#e6e6e6; --muted:#9aa4b2;
  --ok:#65d26e; --err:#ff6b6b; --warn:#ffd166; --accent:#7cb8ff;
}}
html,body {{ background:var(--bg); color:var(--ink); font-family: ui-sans-serif,system-ui,Segoe UI,Roboto,Arial; }}
.wrapper {{ margin:24px; }}
h1,h2,h3 {{ color:#e6f1ff; }}
code {{ background:#141a2a; padding:2px 6px; border-radius:6px; }}
pre {{ background:var(--panel); padding:12px; border-radius:12px; overflow:auto; border:1px solid #1b2136; }}
section.endpoint {{ border:1px solid #1b2136; border-radius:14px; padding:16px; margin:16px 0; background:#0e1220; box-shadow: 0 0 0 1px rgba(255,255,255,0.02) inset;}}
section.endpoint .meta {{ font-size: 13px; color: var(--muted); margin: 4px 0 12px; }}
section.endpoint .meta .m {{ color: var(--accent); font-weight: 600; margin-right:8px; }}
section.endpoint .meta .dur {{ color: #a0ffa0; margin-left:8px; }}
section.endpoint .meta .st {{ color: #ffd3a0; margin-left:8px; }}
section.endpoint .note {{ color:#ffd166; margin:6px 0 10px; }}
table {{ width:100%; border-collapse:collapse; margin-top: 20px; }}
th, td {{ border:1px solid #1b2136; padding:10px; text-align:left; }}
th {{ background:#10162a; color:#d4e2ff; }}
tr.pass {{ background: rgba(101,210,110,.06); }}
tr.fail {{ background: rgba(255,107,107,.08); }}
.summary {{ margin-top:24px; color: var(--muted); }}
details summary {{ cursor:pointer; margin-bottom: 8px; }}
</style>
</head>
<body>
<div class="wrapper">
<h1>NoteSync — Notes API Test</h1>

<h2>Wyniki</h2>
<table>
<thead>
<tr><th>Test</th><th>Wynik</th><th>Czas</th><th>Metoda</th><th>URL</th><th>HTTP</th></tr>
</thead>
<tbody>
{''.join(rows)}
</tbody>
</table>

<h2>Endpointy — Szczegóły</h2>
{''.join(ep_html)}

<p class="summary">Raport wygenerowano: {time.strftime('%Y-%m-%d %H:%M:%S')}</p>
</div>
</body>
</html>
"""
    with open(path, "w", encoding="utf-8") as f:
        f.write(html)
    print(c(f"📄 Zapisano raport HTML: {path}", Fore.CYAN))

# ───────────────────────── Main ─────────────────────────
def main():
    global NOTE_FILE_PATH
    args = parse_args()
    colorama_init()

    NOTE_FILE_PATH = args.note_file

    ses = requests.Session()
    ses.headers.update({"User-Agent": "NoteSync-NotesTest/1.4", "Accept": "application/json"})

    ctx = TestContext(
        base_url=args.base_url.rstrip("/"),
        me_prefix=args.me_prefix,
        ses=ses,
        timeout=args.timeout,
        note_file_path=NOTE_FILE_PATH,
        output_dir=build_output_dir(),  # ustaw katalog docelowy z datą/godziną
    )

    print(c(f"\n{ICON_INFO} Start Notes API tests @ {ctx.base_url}\n", Fore.WHITE))

    ApiTester(ctx).run()

if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("\nPrzerwano.")
        sys.exit(130)
