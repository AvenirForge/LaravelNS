#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
CourseTest.py — E2E dla Course API (members-only notes) + pełny HTML transcript

Co nowego (naprawa zapisu do HTML):
- Raport HTML zawiera tabelę wyników oraz sekcję ze szczegółami każdego request/response.
- Dodatkowo ZAPISUJEMY SUROWE PLIKI na dysku:
  ./tests/E2E/results/Course/ResultE2E--YYYY-MM-DD--HH-MM-SS/
    ├─ APITestReport.html
    └─ transcripts/
       ├─ 001-Index_no_token--request.json
       ├─ 001-Index_no_token--response.json
       ├─ 002-Register_A--request.json
       ├─ 002-Register_A--response.json
       └─ ...

Zachowanie domenowe:
- Notatki kursu widzą tylko owner/admin/moderator lub member ze statusem zaakceptowanym.
- Outsider (również w kursie publicznym) nie widzi notatek (401/403).
"""

from __future__ import annotations

import argparse
import base64
import io
import json
import os
import random
import re
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

# ───────────────────────── Stałe UI ─────────────────────────
ICON_INFO = "ℹ️"
BOX = "─" * 92
MAX_BODY_LOG = 12000  # log w HTML (przycięty)
SAVE_BODY_LIMIT = 10 * 1024 * 1024  # limit zapisywanych binarnych odpowiedzi (10MB)

# ───────────────────────── Utils ─────────────────────────
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
    return s

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

def safe_filename(s: str) -> str:
    s = re.sub(r"[^\w\-.]+", "_", s.strip())
    return s[:120] if len(s) > 120 else s

# ───────────────────────── CLI ─────────────────────────
def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(description="Course API E2E (members-only notes) — full HTML transcript")
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
    resp_body_pretty: Optional[str] = None   # do HTML (tekstowy podgląd)
    resp_bytes: Optional[bytes] = None       # surowe bajty do zapisu
    resp_content_type: Optional[str] = None
    duration_ms: float = 0.0

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
    public_course_id: Optional[int] = None
    note_id: Optional[int] = None
    note2_id: Optional[int] = None
    invite_token_B: Optional[str] = None
    invite_tokens_C: List[str] = field(default_factory=list)
    started_at: float = field(default_factory=time.time)
    timeout: int = 20
    endpoints: List[EndpointLog] = field(default_factory=list)
    out_dir: Optional[str] = None
    transcripts_dir: Optional[str] = None

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

# ───────────────────────── HTTP + log ─────────────────────────
def log_exchange(ctx: TestContext, el: EndpointLog, resp: Optional[requests.Response]):
    if resp is not None:
        ct = (resp.headers.get("Content-Type") or "")
        el.resp_status = resp.status_code
        el.resp_headers = {k: str(v) for k, v in resp.headers.items()}
        el.resp_content_type = ct

        # Zachowaj SUROWE bajty (w granicach limitu)
        content = resp.content or b""
        if len(content) > SAVE_BODY_LIMIT:
            el.resp_bytes = content[:SAVE_BODY_LIMIT]
        else:
            el.resp_bytes = content

        # Przygotuj „pretty preview” do HTML
        if "application/json" in ct.lower():
            try:
                b = mask_json_sensitive(resp.json())
                el.resp_body_pretty = pretty_json(b)
            except Exception:
                el.resp_body_pretty = as_text(el.resp_bytes)
        elif "text/" in ct.lower():
            el.resp_body_pretty = as_text(el.resp_bytes)
        elif "image" in ct.lower() or "octet-stream" in ct.lower() or "pdf" in ct.lower():
            el.resp_body_pretty = f"<binary> bytes={len(el.resp_bytes)} content-type={ct}"
        else:
            el.resp_body_pretty = as_text(el.resp_bytes)

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
        title="POST(multipart) " + title,
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
    os.makedirs(os.path.join(out_dir, "transcripts"), exist_ok=True)
    return out_dir

def guess_ext_by_ct(ct: Optional[str]) -> str:
    if not ct: return ".bin"
    ct = ct.lower()
    if "json" in ct: return ".json"
    if "text/" in ct: return ".txt"
    if "pdf" in ct: return ".pdf"
    if "png" in ct: return ".png"
    if "jpeg" in ct or "jpg" in ct: return ".jpg"
    if "octet-stream" in ct: return ".bin"
    return ".bin"

def write_bytes(path: str, data: bytes):
    with open(path, "wb") as f:
        f.write(data)

def write_text(path: str, text: str):
    with open(path, "w", encoding="utf-8") as f:
        f.write(text)

def save_endpoint_files(out_dir: str, idx: int, ep: EndpointLog):
    base = f"{idx:03d}-{safe_filename(ep.title)}"
    tr_dir = os.path.join(out_dir, "transcripts")
    os.makedirs(tr_dir, exist_ok=True)

    # Request zapisujemy jako JSON (meta: headers + body)
    req_payload = {
        "title": ep.title,
        "method": ep.method,
        "url": ep.url,
        "headers": ep.req_headers,
        "body": ep.req_body,
        "is_json": ep.req_is_json,
        "duration_ms": round(ep.duration_ms, 1),
    }
    write_text(os.path.join(tr_dir, f"{base}--request.json"), pretty_json(req_payload))

    # Response — zapis nagłówków i „ładnego” widoku JSON/TXT
    resp_meta = {
        "status": ep.resp_status,
        "headers": ep.resp_headers,
        "content_type": ep.resp_content_type,
    }
    write_text(os.path.join(tr_dir, f"{base}--response.json"), pretty_json(resp_meta))

    # Jeśli mamy bytes — zapisz raw z rozszerzeniem wywnioskowanym z Content-Type
    if ep.resp_bytes is not None:
        ext = guess_ext_by_ct(ep.resp_content_type)
        path_raw = os.path.join(tr_dir, f"{base}--response_raw{ext}")
        write_bytes(path_raw, ep.resp_bytes)

# ───────────────────────── Runner ─────────────────────────
class ApiTester:
    def __init__(self, ctx: TestContext):
        self.ctx = ctx
        self.results: List[TestRecord] = []

    def run(self):
        self.ctx.out_dir = build_output_dir()
        self.ctx.transcripts_dir = os.path.join(self.ctx.out_dir, "transcripts")

        steps: List[Tuple[str, Callable[[], Dict[str, Any]]]] = [
            ("Index no token", self.t_index_no_token),

            ("Register A", self.t_register_A),
            ("Login A", self.t_login_A),

            ("Create course (private)", self.t_create_course_A),
            ("Download avatar none → 404", self.t_download_avatar_none_404),

            ("Create course invalid type → 400/422", self.t_create_course_invalid),
            ("Index courses (A)", self.t_index_courses_A_contains),

            ("Register B", self.t_register_B),
            ("Login B", self.t_login_B),

            ("B cannot download A avatar → 401/403", self.t_download_avatar_B_unauth),

            ("B cannot update A course → 401/403", self.t_B_cannot_update_A_course),
            ("B cannot delete A course → 401/403", self.t_B_cannot_delete_A_course),

            ("Invite B", self.t_invite_B),
            ("B received invitations", self.t_B_received),
            ("B accepts invitation", self.t_B_accept),

            ("Index courses (B)", self.t_index_courses_B_contains),

            ("Course users — member view", self.t_course_users_member_view),
            ("Course users — admin all + per_page=1", self.t_course_users_admin_all),
            ("Course users — filter q & role", self.t_course_users_filter_q_role),

            ("A creates note (multipart)", self.t_create_note_A),
            ("B cannot share A note → 403/404", self.t_B_cannot_share_A_note),
            ("A share note → invalid course → 404", self.t_A_share_note_invalid_course),
            ("A share note → private course", self.t_share_note_to_course),
            ("Verify note shared flags", self.t_verify_note_shared),

            ("Course notes — owner & member (private)", self.t_course_notes_owner_member),
            ("Course notes — outsider private → 401/403", self.t_course_notes_outsider_private_403),

            ("Remove B", self.t_remove_B),
            ("Index courses (B not contains)", self.t_index_courses_B_not_contains),
            ("Remove non-member idempotent", self.t_remove_non_member_true),
            ("Cannot remove owner → 400/422", self.t_remove_owner_422),

            ("Register C", self.t_register_C),
            ("Login C", self.t_login_C),
            ("Create course #2 (private)", self.t_create_course2_A),
            ("Invite C #1", self.t_invite_C_1),
            ("C rejects #1", self.t_reject_C_last),
            ("Invite C #2", self.t_invite_C_2),
            ("C rejects #2", self.t_reject_C_last),
            ("Invite C #3", self.t_invite_C_3),
            ("C rejects #3", self.t_reject_C_last),
            ("Invite C #4 blocked → 400/422", self.t_invite_C_4_blocked),

            ("Create course (public)", self.t_create_public_course_A),
            ("A creates note #2 (multipart)", self.t_create_note2_A),
            ("A share note #2 → public course", self.t_share_note2_to_public_course),
            ("Course notes — outsider public → 401/403", self.t_course_notes_outsider_public_403),
            ("Course users — outsider public → 401/403", self.t_course_users_outsider_public_401),

            ("Delete course #1", self.t_delete_course_A),
            ("Delete course #2", self.t_delete_course2_A),
        ]
        total = len(steps)
        for i, (name, fn) in enumerate(steps, 1):
            self._exec(i, total, name, fn)

        # Po wszystkich krokach zapisz raport i wszystkie transkrypty
        write_html_report(self.ctx, self.results, self.ctx.endpoints)

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
        assert r.status_code in (401,403), f"Bez tokenu 401/403: {r.status_code} {trim(r.text)}"
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
        assert r.status_code in (400,422), f"Zły type 400/422: {r.status_code} {trim(r.text)}"
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
        assert r.status_code in (401,403), f"B bez uprawnień 401/403: {r.status_code} {trim(r.text)}"
        return {"status": r.status_code, "method":"GET","url":url}

    def t_B_cannot_update_A_course(self):
        url = me(self.ctx, f"/courses/{self.ctx.course_id}")
        r = http_json(self.ctx, "B cannot update A course", "PATCH", url,
                      {"title":"Hacked by B","description":"nope","type":"private"},
                      auth_headers(self.ctx.tokenB))
        assert r.status_code in (401,403), f"Brak uprawnień 401/403: {r.status_code} {trim(r.text)}"
        return {"status": r.status_code, "method":"PATCH","url":url}

    def t_B_cannot_delete_A_course(self):
        url = me(self.ctx, f"/courses/{self.ctx.course_id}")
        r = http_json(self.ctx, "B cannot delete", "DELETE", url, None, auth_headers(self.ctx.tokenB))
        assert r.status_code in (401,403), f"Brak uprawnień 401/403: {r.status_code} {trim(r.text)}"
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
        r = http_json(self.ctx, "B accept invite", "POST", url, {}, auth_headers(self.ctx.tokenB))
        assert r.status_code == 200, f"Accept invite {r.status_code}: {trim(r.text)}"
        return {"status": 200, "method":"POST","url":url}

    def t_index_courses_B_contains(self):
        url = build(self.ctx, "/api/me/courses")
        r = http_json(self.ctx, "Index courses B", "GET", url, None, auth_headers(self.ctx.tokenB))
        assert r.status_code == 200, f"Index B {r.status_code}: {trim(r.text)}"
        ids = [c.get("id") for c in must_json(r)]
        assert self.ctx.course_id in ids, "Kurs A nie widoczny u B po akceptacji"
        return {"status": 200, "method":"GET","url":url}

    # ── Users in course
    def t_course_users_member_view(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id}/users")
        r = http_json(self.ctx, "Course users (member)", "GET", url, None, auth_headers(self.ctx.tokenB))
        assert r.status_code == 200, f"Member list users 200: {r.status_code} {trim(r.text)}"
        js = must_json(r)
        assert "users" in js and isinstance(js["users"], list), "Brak listy users"
        assert len(js["users"]) >= 2, "Powinno być >=2 (owner + member)"
        return {"status": 200, "method":"GET","url":url}

    def t_course_users_admin_all(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id}/users?status=all&per_page=1&sort=joined&order=desc")
        r = http_json(self.ctx, "Course users (admin status=all, per_page=1)", "GET", url, None, auth_headers(self.ctx.tokenA))
        assert r.status_code == 200, f"Admin list users 200: {r.status_code} {trim(r.text)}"
        js = must_json(r)
        assert js["filters"]["status"] == "all", "Admin powinien móc status=all"
        assert js["pagination"]["per_page"] == 1, "per_page=1"
        assert len(js["users"]) == 1, "Powinna wrócić 1 pozycja"
        return {"status": 200, "method":"GET","url":url}

    def t_course_users_filter_q_role(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id}/users?q=Tester%20B&role=member")
        r = http_json(self.ctx, "Course users (filter q & role)", "GET", url, None, auth_headers(self.ctx.tokenA))
        assert r.status_code == 200, f"Filter users 200: {r.status_code} {trim(r.text)}"
        js = must_json(r)
        assert all(u.get("role") in ("member","user") for u in js["users"]), "Filtr role=member nie zadziałał"
        assert any("Tester" in (u.get("name") or "") for u in js["users"]), "Filtr q po name/email nie zadziałał"
        return {"status": 200, "method":"GET","url":url}

    # ── Notes lifecycle
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
        r = http_multipart(self.ctx, "Create note", url, data=data, files=files, headers=auth_headers(self.ctx.tokenA))
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
        assert r.status_code == 404, f"Nieistniejący kurs 404: {r.status_code} {trim(r.text)}"
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
        assert js.get("course_id") == self.ctx.course_id, "Notatka nie przypisana do kursu"
        return {"status": 200, "method":"GET","url":url}

    def t_course_notes_owner_member(self):
        # Owner
        url_o = build(self.ctx, f"/api/courses/{self.ctx.course_id}/notes?sort=created_at&order=desc")
        ro = http_json(self.ctx, "Course notes (owner, private course)", "GET", url_o, None, auth_headers(self.ctx.tokenA))
        assert ro.status_code == 200, f"Owner notes list 200: {ro.status_code} {trim(ro.text)}"
        jso = must_json(ro)
        assert "notes" in jso and isinstance(jso["notes"], list), "Brak notes (owner)"
        assert any(n.get("id") == self.ctx.note_id for n in jso["notes"]), "Brak notatki ownera w kursie"

        # Member (B)
        url_m = build(self.ctx, f"/api/courses/{self.ctx.course_id}/notes?q=First&per_page=1")
        rm = http_json(self.ctx, "Course notes (member, private course)", "GET", url_m, None, auth_headers(self.ctx.tokenB))
        assert rm.status_code == 200, f"Member notes list 200: {rm.status_code} {trim(rm.text)}"
        jsm = must_json(rm)
        assert jsm["pagination"]["per_page"] == 1, "Paginacja per_page=1 (member)"
        assert any("First" in (n.get("title") or "") for n in jsm["notes"]), "Filtr q=First nie zadziałał"
        return {"status": 200, "method":"GET","url":url_o}

    def t_course_notes_outsider_private_403(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id}/notes")
        r = http_json(self.ctx, "Notes outsider (private) → 401/403", "GET", url, None, {"Accept":"application/json"})
        assert r.status_code in (401,403), f"Outsider do prywatnego kursu 401/403: {r.status_code}"
        return {"status": r.status_code, "method":"GET","url":url}

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
        assert r.status_code in (400,422), f"Remove owner 422/400: {r.status_code} {trim(r.text)}"
        return {"status": r.status_code, "method":"POST","url":url}

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
        assert r.status_code in (400,422), f"4th invite po 3x reject 422/400: {r.status_code} {trim(r.text)}"
        return {"status": r.status_code, "method":"POST","url":url}

    def t_create_public_course_A(self):
        url = me(self.ctx, "/courses")
        r = http_json(self.ctx, "Create course (public)", "POST", url,
                      {"title":"Public Course","description":"Members-only notes policy","type":"public"},
                      auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,201), f"Create public course {r.status_code}: {trim(r.text)}"
        js = must_json(r)
        self.ctx.public_course_id = (js.get("course") or {}).get("id") or js.get("id")
        assert self.ctx.public_course_id, "Brak id kursu publicznego"
        return {"status": r.status_code, "method":"POST","url":url}

    def t_create_note2_A(self):
        url = me(self.ctx, "/notes")
        data_bytes, mime, name = self._load_upload_bytes(NOTE_FILE_PATH)
        files = {"file": (name, data_bytes, mime)}
        data  = {"title":"Public Note (but members-only view)","description":"Shared to public course"}
        r = http_multipart(self.ctx, "Create second note", url, data=data, files=files, headers=auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,201), f"Create note2 {r.status_code}: {trim(r.text)}"
        js = must_json(r)
        note_obj = js.get("note") or js
        self.ctx.note2_id = note_obj.get("id")
        assert self.ctx.note2_id, "Brak note2_id"
        return {"status": r.status_code, "method":"POST","url":url}

    def t_share_note2_to_public_course(self):
        url = me(self.ctx, f"/notes/{self.ctx.note2_id}/share/{self.ctx.public_course_id}")
        r = http_json(self.ctx, "Share note2 → public course", "POST", url, {}, auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,201), f"Share note2 {r.status_code}: {trim(r.text)}"
        return {"status": r.status_code, "method":"POST","url":url}

    def t_course_notes_outsider_public_403(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.public_course_id}/notes")
        r = http_json(self.ctx, "Notes outsider (public course) → 401/403", "GET", url, None, {"Accept":"application/json"})
        assert r.status_code in (401,403), f"Outsider do publicznego kursu 401/403: {r.status_code} {trim(r.text)}"
        return {"status": r.status_code, "method":"GET","url":url}

    def t_course_users_outsider_public_401(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.public_course_id}/users")
        r = http_json(self.ctx, "Users outsider (public course) → 401/403", "GET", url, None, {"Accept":"application/json"})
        assert r.status_code in (401,403), f"Outsider users public course 401/403: {r.status_code} {trim(r.text)}"
        return {"status": r.status_code, "method":"GET","url":url}

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

# ───────────────────────── Raport HTML ─────────────────────────
def write_html_report(ctx: TestContext, results: List[TestRecord], endpoints: List[EndpointLog]):
    if not ctx.out_dir:
        ctx.out_dir = build_output_dir()
    if not ctx.transcripts_dir:
        ctx.transcripts_dir = os.path.join(ctx.out_dir, "transcripts")
        os.makedirs(ctx.transcripts_dir, exist_ok=True)

    # Zapisz surowe pliki per-endpoint zanim wygenerujemy HTML
    for i, ep in enumerate(endpoints, 1):
        save_endpoint_files(ctx.out_dir, i, ep)

    # Przygotuj HTML
    path = os.path.join(ctx.out_dir, "APITestReport.html")

    # Tabela wyników
    def http_color(s: Optional[int]) -> str:
        if s is None: return ""
        if 200 <= s < 300: return f"<span class='http ok'>{s}</span>"
        if 400 <= s < 500: return f"<span class='http warn'>{s}</span>"
        return f"<span class='http err'>{s}</span>"

    rows = []
    for r in results:
        outcome = f"<span class='pass'>PASS</span>" if r.passed else f"<span class='fail'>FAIL</span>"
        rows.append(f"""
<tr class="{ 'pass' if r.passed else 'fail' }">
  <td>{r.name}</td>
  <td class="center">{outcome}</td>
  <td class="right">{r.duration_ms:.1f} ms</td>
  <td>{(r.method or '')}</td>
  <td><code>{(r.url or '')}</code></td>
  <td class="center">{http_color(r.status)}</td>
</tr>
""")

    # Sekcja endpointów
    ep_html = []
    for i, ep in enumerate(endpoints, 1):
        base = f"{i:03d}-{safe_filename(ep.title)}"
        req_file = f"transcripts/{base}--request.json"
        resp_meta_file = f"transcripts/{base}--response.json"

        # Znajdź ewentualny surowy plik odpowiedzi (wg CT)
        raw_candidates = [f for f in os.listdir(ctx.transcripts_dir) if f.startswith(base + "--response_raw")]
        raw_link = f"<em>brak</em>"
        if raw_candidates:
            raw_link = f"<a href='transcripts/{raw_candidates[0]}' target='_blank'>{raw_candidates[0]}</a>"

        req_h = pretty_json(ep.req_headers)
        req_b = pretty_json(mask_json_sensitive(ep.req_body)) if ep.req_is_json else pretty_json(ep.req_body)
        resp_h = pretty_json(ep.resp_headers)
        resp_b = ep.resp_body_pretty or ""

        if len(resp_b) > MAX_BODY_LOG:
            resp_b_view = resp_b[:MAX_BODY_LOG] + "\n…(truncated)"
        else:
            resp_b_view = resp_b

        ep_html.append(f"""
<section class="endpoint" id="ep-{i}">
  <h2>{i:03d}. {ep.title}</h2>
  <div class="meta"><span class="m">{ep.method}</span> <code>{ep.url}</code>
    <span class="dur">{ep.duration_ms:.1f} ms</span>
    <span class="st">{ep.resp_status if ep.resp_status is not None else ''}</span>
  </div>
  <p class="downloads">
    📥 Pliki: <a href="{req_file}" target="_blank">request.json</a> ·
    <a href="{resp_meta_file}" target="_blank">response.json</a> ·
    raw: {raw_link}
  </p>
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
    <pre>{(resp_b_view).replace("&","&amp;").replace("<","&lt;").replace(">","&gt;")}</pre>
  </details>
</section>
""")

    html = f"""<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8" />
<title>Course API Test Report — full transcript</title>
<style>
:root {{
  --bg:#0b0d12; --panel:#0f1320; --ink:#e6e6e6; --muted:#9aa4b2;
  --ok:#65d26e; --err:#ff6b6b; --warn:#ffd166; --accent:#7cb8ff;
}}
html,body {{ background:var(--bg); color:var(--ink); font-family: ui-sans-serif,system-ui,Segoe UI,Roboto,Arial; }}
.wrapper {{ margin:24px; }}
h1,h2,h3 {{ color:#e6f1ff; margin: 0.3em 0; }}
code {{ background:#141a2a; padding:2px 6px; border-radius:6px; }}
pre {{ background:var(--panel); padding:12px; border-radius:12px; overflow:auto; border:1px solid #1b2136; }}
section.endpoint {{ border:1px solid #1b2136; border-radius:14px; padding:16px; margin:16px 0; background:#0e1220; }}
section.endpoint .meta {{ font-size: 13px; color: var(--muted); margin: 6px 0 8px; }}
section.endpoint .meta .m {{ color: var(--accent); font-weight: 600; margin-right:8px; }}
section.endpoint .meta .dur {{ color: #a0ffa0; margin-left:8px; }}
section.endpoint .meta .st {{ color: #ffd3a0; margin-left:8px; }}
section.endpoint .downloads {{ font-size: 13px; color: var(--muted); margin: 2px 0 12px; }}
table {{ width:100%; border-collapse:collapse; margin-top: 12px; font-size: 14px; }}
th, td {{ border:1px solid #1b2136; padding:10px; text-align:left; vertical-align: top; }}
th {{ background:#10162a; color:#d4e2ff; }}
td.center {{ text-align: center; }}
td.right {{ text-align: right; }}
.pass {{ color: var(--ok); font-weight: 700; }}
.fail {{ color: var(--err); font-weight: 700; }}
.http.ok {{ color: var(--ok); font-weight: 700; }}
.http.warn {{ color: #ffd166; font-weight: 700; }}
.http.err {{ color: var(--err); font-weight: 700; }}
details summary {{ cursor:pointer; margin-bottom: 8px; }}
.topbar {{ display:flex; gap:12px; align-items:center; }}
.badge {{ background:#15203a; border:1px solid #1b2b4a; padding:6px 10px; border-radius:999px; color:#cfe3ff; font-size:13px; }}
small.muted {{ color: var(--muted); }}
</style>
</head>
<body>
<div class="wrapper">
  <div class="topbar">
    <h1>Course API Test — pełny transcript</h1>
    <span class="badge">Wygenerowano: {time.strftime('%Y-%m-%d %H:%M:%S')}</span>
    <span class="badge">Endpointów: {len(endpoints)}</span>
  </div>

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

  <p><small class="muted">Pliki surowe: katalog <code>transcripts/</code> obok tego raportu.</small></p>
</div>
</body>
</html>
"""
    write_text(path, html)
    print(c(f"📄 Zapisano raport HTML: {path}", Fore.CYAN))

# ───────────────────────── Main ─────────────────────────
def main():
    global NOTE_FILE_PATH
    args = parse_args()
    colorama_init()

    NOTE_FILE_PATH = args.note_file

    ses = requests.Session()
    ses.headers.update({"User-Agent": "CourseTest/1.4", "Accept": "application/json"})

    ctx = TestContext(
        base_url=args.base_url.rstrip("/"),
        me_prefix=args.me_prefix,
        ses=ses,
        timeout=args.timeout,
    )

    print(c(f"\n{ICON_INFO} Start Course API tests (members-only notes) — full transcript @ {ctx.base_url}\n", Fore.WHITE))

    ApiTester(ctx).run()

if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("\nPrzerwano.")
        sys.exit(130)
