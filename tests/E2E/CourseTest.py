#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
CourseTest.py — rozszerzony E2E dla sekcji Course (Laravel + JWT):

Scenariusze pozytywne (happy path):
- Rejestracja/logowanie A i B (oraz C do testów zaproszeń)
- A: tworzy kurs (type=private)
- A: pobranie listy kursów (owner + pivot union)
- A: tworzy notatkę (multipart, bez is_private — jak w NoteTest)
- A: udostępnia notatkę do kursu i weryfikuje flagi
- A: zaprasza B → B akceptuje → B widzi kurs
- A: usuwa B z kursu → B już nie widzi kursu
- A: usuwa kurs

Scenariusze negatywne i bezpieczeństwo:
- Brak tokenu na /api/me/courses → 401
- A: pobranie avatara bez avatara → 404
- B: brak uprawnień do pobrania avatara kursu A → 401
- Zły `type` przy tworzeniu kursu → 400
- B: próba aktualizacji/usuń kursu A → 401
- B: próba zaproszenia kogokolwiek → 401
- B: próba udostępnienia notatki A → 404
- A: share notatki do nieistniejącego kursu → 404
- remove-user na nie-członku → true (idempotencja)
- remove-user na ownerze → 422
- (Limit): zaproszenie C 3× → C odrzuca 3× → 4. invite = 422

Raport HTML w:
  ./tests/E2E/results/Course/ResultE2E--YYYY-MM-DD--HH-MM-SS/APITestReport.html
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
ICON_NOTE = "📝"
ICON_LINK = "🔗"
ICON_TRASH= "🗑️"
ICON_CLOCK= "⏱️"
BOX = "─" * 92
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
    p = argparse.ArgumentParser(description="Course API E2E (extended)")
    p.add_argument("--base-url", required=True, help="np. http://localhost:8000")
    p.add_argument("--me-prefix", default="me", help="prefiks dla /api/<prefix> (domyślnie 'me')")
    p.add_argument("--timeout", type=int, default=20, help="timeout żądań w sekundach")
    p.add_argument("--note-file", default=r"C:\xampp\htdocs\LaravelNS\tests\E2E\sample.png",
                   help="plik do uploadu notatki (pdf/xlsx/jpg/jpeg/png)")
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
    tokenC: Optional[str] = None
    emailA: str = ""
    pwdA: str = ""
    emailB: str = ""
    pwdB: str = ""
    emailC: str = ""
    pwdC: str = ""
    course_id: Optional[int] = None
    course2_id: Optional[int] = None
    note_id: Optional[int] = None
    invite_token_B: Optional[str] = None
    invite_tokens_C: List[str] = field(default_factory=list)
    started_at: float = field(default_factory=time.time)
    timeout: int = 20
    endpoints: List[EndpointLog] = field(default_factory=list)

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
    if not PIL_AVAILABLE:
        return b"\x89PNG\r\n\x1a\n"
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
        ct = (resp.headers.get("Content-Type") or "")
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

# ───────────────────────── Output dir ─────────────────────────
def build_output_dir() -> str:
    root = os.getcwd()
    date_str = time.strftime("%Y-%m-%d")
    time_str = time.strftime("%H-%M-%S")
    folder = f"ResultE2E--{date_str}--{time_str}"
    out_dir = os.path.join(root, "tests", "E2E", "results", "Course", folder)
    os.makedirs(out_dir, exist_ok=True)
    return out_dir

# ───────────────────────── Runner ─────────────────────────
class ApiTester:
    def __init__(self, ctx: TestContext):
        self.ctx = ctx
        self.results: List[TestRecord] = []

    def run(self):
        steps: List[Tuple[str, Callable[[], Dict[str, Any]]]] = [
            ("🔒 Index without token → 401", self.t_index_no_token),

            ("👤 Rejestracja A", self.t_register_A),
            ("🔒 Login A", self.t_login_A),

            ("🏫 Create course", self.t_create_course_A),
            ("🖼️ Download avatar (none) → 404", self.t_download_avatar_none_404),

            ("🏫 Create course invalid type → 400", self.t_create_course_invalid),
            ("🏫 Index courses (A contains)", self.t_index_courses_A_contains),

            ("👤 Rejestracja B", self.t_register_B),
            ("🔒 Login B", self.t_login_B),

            ("🖼️ B cannot download A avatar → 401", self.t_download_avatar_B_unauth),

            ("✏️ B cannot update A course → 401", self.t_B_cannot_update_A_course),
            ("🗑️ B cannot delete A course → 401", self.t_B_cannot_delete_A_course),

            ("✉️ Invite B", self.t_invite_B),
            ("📥 B received invitations", self.t_B_received),
            ("✉️ B accepts invitation", self.t_B_accept),

            ("🏫 Index courses (B contains)", self.t_index_courses_B_contains),

            ("📝 A creates note (multipart)", self.t_create_note_A),
            ("🔗 B cannot share A note → 404/403", self.t_B_cannot_share_A_note),
            ("🔗 A share note to non-existing course → 404", self.t_A_share_note_invalid_course),
            ("🔗 A share note → course", self.t_share_note_to_course),
            ("🔍 Verify note shared flags", self.t_verify_note_shared),

            ("🗑️ Remove B from course", self.t_remove_B),
            ("🏫 Index courses (B not contains)", self.t_index_courses_B_not_contains),
            ("🗑️ Remove non-member (idempotent true)", self.t_remove_non_member_true),
            ("🗑️ Cannot remove owner → 422", self.t_remove_owner_422),

            # Limit 3 rejections per email → 4th invite blocked
            ("👤 Rejestracja C", self.t_register_C),
            ("🔒 Login C", self.t_login_C),
            ("🏫 Create second course", self.t_create_course2_A),
            ("✉️ Invite C #1", self.t_invite_C_1),
            ("🚫 C rejects #1", self.t_reject_C_last),
            ("✉️ Invite C #2", self.t_invite_C_2),
            ("🚫 C rejects #2", self.t_reject_C_last),
            ("✉️ Invite C #3", self.t_invite_C_3),
            ("🚫 C rejects #3", self.t_reject_C_last),
            ("✉️ Invite C #4 blocked → 422", self.t_invite_C_4_blocked),

            ("🗑️ Delete course (A)", self.t_delete_course_A),
            ("🗑️ Delete second course (A)", self.t_delete_course2_A),
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
    def t_index_no_token(self):
        url = me(self.ctx, "/courses")
        r = http_json(self.ctx, "Index no token", "GET", url, None, {"Accept":"application/json"})
        assert r.status_code in (401,403), f"Bez tokenu powinno być 401/403: {r.status_code} {trim(r.text)}"
        return {"status": r.status_code, "method":"GET", "url":url}

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

    def t_create_course_A(self):
        url = me(self.ctx, "/courses")
        r = http_json(self.ctx, "Create course", "POST", url,
                      {"title":"My Course","description":"Course for E2E","type":"private"},
                      auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,201), f"Create course {r.status_code}: {trim(r.text)}"
        js = must_json(r)
        self.ctx.course_id = (js.get("course") or {}).get("id") or js.get("id")
        assert self.ctx.course_id, "Brak id kursu"
        return {"status": r.status_code, "method":"POST","url":url}

    def t_download_avatar_none_404(self):
        url = me(self.ctx, f"/courses/{self.ctx.course_id}/avatar")
        r = http_json(self.ctx, "Download avatar none", "GET", url, None, auth_headers(self.ctx.tokenA))
        assert r.status_code == 404, f"Brak avatara powinien dać 404: {r.status_code} {trim(r.text)}"
        return {"status": r.status_code, "method":"GET", "url":url}

    def t_create_course_invalid(self):
        url = me(self.ctx, "/courses")
        r = http_json(self.ctx, "Create course invalid", "POST", url,
                      {"title":"Invalid","description":"x","type":"superpublic"},
                      auth_headers(self.ctx.tokenA))
        assert r.status_code in (400,422), f"Zły type powinien dać 400/422: {r.status_code} {trim(r.text)}"
        return {"status": r.status_code, "method":"POST","url":url}

    def t_index_courses_A_contains(self):
        url = me(self.ctx, "/courses")
        r = http_json(self.ctx, "Index courses A", "GET", url, None, auth_headers(self.ctx.tokenA))
        assert r.status_code == 200, f"Index A {r.status_code}: {trim(r.text)}"
        ids = [c.get("id") for c in must_json(r)]
        assert self.ctx.course_id in ids, "Kurs A nie widoczny u A"
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

    def t_download_avatar_B_unauth(self):
        url = me(self.ctx, f"/courses/{self.ctx.course_id}/avatar")
        r = http_json(self.ctx, "B download avatar unauthorized", "GET", url, None, auth_headers(self.ctx.tokenB))
        assert r.status_code in (401,403), f"B bez uprawnień powinien dostać 401/403: {r.status_code} {trim(r.text)}"
        return {"status": r.status_code, "method":"GET","url":url}

    def t_B_cannot_update_A_course(self):
        url = me(self.ctx, f"/courses/{self.ctx.course_id}")
        r = http_json(self.ctx, "B cannot update A course", "PATCH", url,
                      {"title":"Hacked by B","description":"nope","type":"private"},
                      auth_headers(self.ctx.tokenB))
        assert r.status_code in (401,403), f"Brak uprawnień powinien dać 401/403: {r.status_code} {trim(r.text)}"
        return {"status": r.status_code, "method":"PUT","url":url}

    def t_B_cannot_delete_A_course(self):
        url = me(self.ctx, f"/courses/{self.ctx.course_id}")
        r = http_json(self.ctx, "B cannot delete", "DELETE", url, None, auth_headers(self.ctx.tokenB))
        assert r.status_code in (401,403), f"Brak uprawnień powinien dać 401/403: {r.status_code} {trim(r.text)}"
        return {"status": r.status_code, "method":"DELETE","url":url}

    def t_invite_B(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id}/invite-user")
        r = http_json(self.ctx, "Invite B", "POST", url,
                      {"email": self.ctx.emailB, "role":"member"},
                      auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,201), f"Invite {r.status_code}: {trim(r.text)}"
        return {"status": r.status_code, "method":"POST","url":url}

    def t_B_received(self):
        url = build(self.ctx, "/api/me/invitations-received")
        r = http_json(self.ctx, "B received", "GET", url, None, auth_headers(self.ctx.tokenB))
        assert r.status_code == 200, f"Invitations received {r.status_code}: {trim(r.text)}"
        js = must_json(r)
        invitations = js.get("invitations", [])
        assert invitations, "Brak zaproszeń dla B"
        self.ctx.invite_token_B = invitations[0].get("token")
        assert self.ctx.invite_token_B, "Brak tokenu zaproszenia"
        return {"status": 200, "method":"GET","url":url}

    def t_B_accept(self):
        url = build(self.ctx, f"/api/invitations/{self.ctx.invite_token_B}/accept")
        r = http_json(self.ctx, "Accept invite", "POST", url, {}, auth_headers(self.ctx.tokenB))
        assert r.status_code == 200, f"Accept invite {r.status_code}: {trim(r.text)}"
        return {"status": 200, "method":"POST","url":url}

    def t_index_courses_B_contains(self):
        url = build(self.ctx, "/api/me/courses")
        r = http_json(self.ctx, "Index courses B", "GET", url, None, auth_headers(self.ctx.tokenB))
        assert r.status_code == 200, f"Index B {r.status_code}: {trim(r.text)}"
        ids = [c.get("id") for c in must_json(r)]
        assert self.ctx.course_id in ids, "Kurs A nie widoczny u B po akceptacji"
        return {"status": 200, "method":"GET","url":url}

    # ── Notes
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
        return gen_png_bytes(), "image/png", "gen.png"

    def t_create_note_A(self):
        url = me(self.ctx, "/notes")
        data_bytes, mime, name = self._load_upload_bytes(NOTE_FILE_PATH)
        files = {"file": (name, data_bytes, mime)}
        data  = {"title":"First Note","description":"Course share candidate"}
        r = http_multipart(self.ctx, "Create note (multipart)", url, data=data, files=files, headers=auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,201), f"Create note {r.status_code}: {trim(r.text)}"
        js = must_json(r)
        note_obj = js.get("note") or js
        self.ctx.note_id = note_obj.get("id")
        assert self.ctx.note_id, "Brak note_id"
        return {"status": r.status_code, "method":"POST","url":url}

    def t_B_cannot_share_A_note(self):
        url = me(self.ctx, f"/notes/{self.ctx.note_id}/share/{self.ctx.course_id}")
        r = http_json(self.ctx, "B tries share A note", "POST", url, {}, auth_headers(self.ctx.tokenB))
        assert r.status_code in (403,404), f"B nie powinien móc udostępnić notatki A: {r.status_code} {trim(r.text)}"
        return {"status": r.status_code, "method":"POST","url":url}

    def t_A_share_note_invalid_course(self):
        url = me(self.ctx, f"/notes/{self.ctx.note_id}/share/999999")
        r = http_json(self.ctx, "A share note invalid course", "POST", url, {}, auth_headers(self.ctx.tokenA))
        assert r.status_code == 404, f"Nieistniejący kurs powinien dać 404: {r.status_code} {trim(r.text)}"
        return {"status": r.status_code, "method":"POST","url":url}

    def t_share_note_to_course(self):
        url = me(self.ctx, f"/notes/{self.ctx.note_id}/share/{self.ctx.course_id}")
        r = http_json(self.ctx, "Share note → course", "POST", url, {}, auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,201), f"Share note {r.status_code}: {trim(r.text)}"
        return {"status": r.status_code, "method":"POST","url":url}

    def t_verify_note_shared(self):
        url = me(self.ctx, f"/notes/{self.ctx.note_id}")
        r = http_json(self.ctx, "Verify note", "GET", url, None, auth_headers(self.ctx.tokenA))
        assert r.status_code == 200, f"Verify note {r.status_code}: {trim(r.text)}"
        js = must_json(r)
        assert js.get("is_private") in (False, 0), "Po share is_private powinno być false"
        assert js.get("course_id") == self.ctx.course_id, "Notatka nie przypisana do kursu"
        return {"status": 200, "method":"GET","url":url}

    # ── Membership ops
    def t_remove_B(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id}/remove-user")
        r = http_json(self.ctx, "Remove B", "POST", url, {"email": self.ctx.emailB}, auth_headers(self.ctx.tokenA))
        assert r.status_code == 200, f"Remove user {r.status_code}: {trim(r.text)}"
        js = must_json(r)
        assert js is True or js == True, "Odpowiedź powinna być literalnym true"
        return {"status": 200, "method":"POST","url":url}

    def t_index_courses_B_not_contains(self):
        url = build(self.ctx, "/api/me/courses")
        r = http_json(self.ctx, "Index courses B (after remove)", "GET", url, None, auth_headers(self.ctx.tokenB))
        assert r.status_code == 200, f"Index B after remove {r.status_code}: {trim(r.text)}"
        ids = [c.get("id") for c in must_json(r)]
        assert self.ctx.course_id not in ids, "B nadal widzi kurs po usunięciu"
        return {"status": 200, "method":"GET","url":url}

    def t_remove_non_member_true(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id}/remove-user")
        r = http_json(self.ctx, "Remove non-member (idempotent)", "POST", url, {"email": self.ctx.emailB}, auth_headers(self.ctx.tokenA))
        assert r.status_code == 200, f"Remove non-member {r.status_code}: {trim(r.text)}"
        js = must_json(r)
        assert js is True or js == True, "Idempotentny remove powinien zwrócić true"
        return {"status": 200, "method":"POST","url":url}

    def t_remove_owner_422(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id}/remove-user")
        r = http_json(self.ctx, "Remove owner blocked", "POST", url, {"email": self.ctx.emailA}, auth_headers(self.ctx.tokenA))
        assert r.status_code in (400,422), f"Remove owner powinno być 422/400: {r.status_code} {trim(r.text)}"
        return {"status": r.status_code, "method":"POST","url":url}

    # ── Invite limit for C
    def t_register_C(self):
        self.ctx.emailC = rnd_email(); self.ctx.pwdC = "Haslo123123"
        url = build(self.ctx, "/api/users/register")
        r = http_json(self.ctx, "Register C", "POST", url,
                      {"name":"Tester C","email":self.ctx.emailC,"password":self.ctx.pwdC,"password_confirmation":self.ctx.pwdC},
                      {"Accept":"application/json"})
        assert r.status_code in (200,201), f"Register C {r.status_code}: {trim(r.text)}"
        return {"status": r.status_code, "method":"POST", "url":url}

    def t_login_C(self):
        url = build(self.ctx,"/api/login")
        r = http_json(self.ctx, "Login C", "POST", url, {"email":self.ctx.emailC,"password":self.ctx.pwdC}, {"Accept":"application/json"})
        assert r.status_code == 200, f"Login C {r.status_code}: {trim(r.text)}"
        self.ctx.tokenC = must_json(r).get("token")
        assert self.ctx.tokenC, "Brak tokenu JWT (C)"
        return {"status": 200, "method":"POST","url":url}

    def t_create_course2_A(self):
        url = me(self.ctx, "/courses")
        r = http_json(self.ctx, "Create course 2", "POST", url,
                      {"title":"My Course 2","description":"Course for invite-limit","type":"private"},
                      auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,201), f"Create course2 {r.status_code}: {trim(r.text)}"
        js = must_json(r)
        self.ctx.course2_id = (js.get("course") or {}).get("id") or js.get("id")
        assert self.ctx.course2_id, "Brak id kursu 2"
        return {"status": r.status_code, "method":"POST","url":url}

    def _invite_C(self, tag: str):
        url = build(self.ctx, f"/api/courses/{self.ctx.course2_id}/invite-user")
        r = http_json(self.ctx, f"Invite C {tag}", "POST", url,
                      {"email": self.ctx.emailC, "role":"member"},
                      auth_headers(self.ctx.tokenA))
        return r, url

    def _C_last_invitation_token(self) -> str:
        url = build(self.ctx, "/api/me/invitations-received")
        r = http_json(self.ctx, "C received", "GET", url, None, auth_headers(self.ctx.tokenC))
        assert r.status_code == 200, f"Invitations received C {r.status_code}: {trim(r.text)}"
        invitations = must_json(r).get("invitations", [])
        assert invitations, "Brak zaproszeń dla C"
        return invitations[0].get("token")

    def t_invite_C_1(self):
        r, url = self._invite_C("#1")
        assert r.status_code in (200,201), f"Invite C #1 {r.status_code}: {trim(r.text)}"
        self.ctx.invite_tokens_C.append(self._C_last_invitation_token())
        return {"status": r.status_code, "method":"POST","url":url}

    def t_reject_C_last(self):
        token = self.ctx.invite_tokens_C[-1]
        url = build(self.ctx, f"/api/invitations/{token}/reject")
        r = http_json(self.ctx, "C rejects", "POST", url, {}, auth_headers(self.ctx.tokenC))
        assert r.status_code == 200, f"Reject C {r.status_code}: {trim(r.text)}"
        return {"status": 200, "method":"POST","url":url}

    def t_invite_C_2(self):
        r, url = self._invite_C("#2")
        assert r.status_code in (200,201), f"Invite C #2 {r.status_code}: {trim(r.text)}"
        self.ctx.invite_tokens_C.append(self._C_last_invitation_token())
        return {"status": r.status_code, "method":"POST","url":url}

    def t_invite_C_3(self):
        r, url = self._invite_C("#3")
        assert r.status_code in (200,201), f"Invite C #3 {r.status_code}: {trim(r.text)}"
        self.ctx.invite_tokens_C.append(self._C_last_invitation_token())
        return {"status": r.status_code, "method":"POST","url":url}

    def t_invite_C_4_blocked(self):
        r, url = self._invite_C("#4 blocked")
        assert r.status_code in (400,422), f"4th invite po 3x reject powinien dać 422/400: {r.status_code} {trim(r.text)}"
        return {"status": r.status_code, "method":"POST","url":url}

    # ── Cleanup
    def t_delete_course_A(self):
        url = me(self.ctx, f"/courses/{self.ctx.course_id}")
        r = http_json(self.ctx, "Delete course", "DELETE", url, None, auth_headers(self.ctx.tokenA))
        assert r.status_code == 200, f"Delete course {r.status_code}: {trim(r.text)}"
        return {"status": 200, "method":"DELETE","url":url}

    def t_delete_course2_A(self):
        url = me(self.ctx, f"/courses/{self.ctx.course2_id}")
        r = http_json(self.ctx, "Delete course2", "DELETE", url, None, auth_headers(self.ctx.tokenA))
        assert r.status_code == 200, f"Delete course2 {r.status_code}: {trim(r.text)}"
        return {"status": 200, "method":"DELETE","url":url}

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

        # HTML raport
        write_html_report(self.ctx, self.results, self.ctx.endpoints)

# ───────────────────────── HTML Raport ─────────────────────────
def write_html_report(ctx: TestContext, results: List[TestRecord], endpoints: List[EndpointLog]):
    out_dir = build_output_dir()
    path = os.path.join(out_dir, "APITestReport.html")

    ep_html = []
    for i, ep in enumerate(endpoints, 1):
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
<title>Course API Test Report (extended)</title>
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
<h1>Course API Test (extended)</h1>

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
    ses.headers.update({"User-Agent": "CourseTest/1.1", "Accept": "application/json"})

    ctx = TestContext(
        base_url=args.base_url.rstrip("/"),
        me_prefix=args.me_prefix,
        ses=ses,
        timeout=args.timeout,
    )

    print(c(f"\n{ICON_INFO} Start Course API tests (extended) @ {ctx.base_url}\n", Fore.WHITE))

    ApiTester(ctx).run()

if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("\nPrzerwano.")
        sys.exit(130)
