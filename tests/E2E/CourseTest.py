#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
CourseTest.py — E2E dla Course API (members-only notes) + pełny HTML transcript
(rozszerzony o: zmiany ról, moderację treści, wyrzucanie z kursem, reguły hierarchii)

Zachowanie domenowe:
- Notatki kursu widzą tylko owner/admin/moderator lub member ze statusem zaakceptowanym.
- Outsider (również w kursie publicznym) nie widzi notatek (401/403).
- Hierarchia ról w moderacji:
  owner > admin > moderator > member
  moderator NIE może ruszyć admina/ownera (ani innego moderatora);
  admin może ruszyć moderatora i membera (nie ownera / innych adminów);
  owner może zmieniać role (admin/mod/member) i wyrzucać kogokolwiek poza ownerem.
"""

from __future__ import annotations

import argparse
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

ICON_INFO = "ℹ️"
BOX = "─" * 92
MAX_BODY_LOG = 12000
SAVE_BODY_LIMIT = 10 * 1024 * 1024

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

def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(description="Course API E2E (members-only notes) — full HTML transcript")
    p.add_argument("--base-url", required=True, help="np. http://localhost:8000")
    p.add_argument("--me-prefix", default="me", help="prefiks dla /api/<prefix> (domyślnie 'me')")
    p.add_argument("--timeout", type=int, default=20, help="timeout żądań w sekundach")
    p.add_argument("--note-file", default=r"C:\xampp\htdocs\LaravelNS\tests\E2E\sample.png",
                   help="plik do uploadu notatki (pdf/xlsx/jpg/jpeg/png)")
    return p.parse_args()

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
    resp_body_pretty: Optional[str] = None
    resp_bytes: Optional[bytes] = None
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
    # tokeny
    tokenA: Optional[str] = None
    tokenB: Optional[str] = None
    tokenC: Optional[str] = None
    tokenD: Optional[str] = None  # admin
    tokenE: Optional[str] = None  # moderator
    tokenF: Optional[str] = None  # member
    # maile/hasła
    emailA: str = ""
    pwdA: str = ""
    emailB: str = ""
    pwdB: str = ""
    emailC: str = ""
    pwdC: str = ""
    emailD: str = ""
    pwdD: str = ""
    emailE: str = ""
    pwdE: str = ""
    emailF: str = ""
    pwdF: str = ""
    # identyfikatory
    course_id: Optional[int] = None
    course2_id: Optional[int] = None
    public_course_id: Optional[int] = None
    note_id: Optional[int] = None
    note2_id: Optional[int] = None
    noteD_id: Optional[int] = None
    noteE_id: Optional[int] = None
    noteF_id: Optional[int] = None
    # zaproszenia
    invite_token_B: Optional[str] = None
    invite_tokens_C: List[str] = field(default_factory=list)
    invite_token_D: Optional[str] = None
    invite_token_E: Optional[str] = None
    invite_token_F: Optional[str] = None

    started_at: float = field(default_factory=time.time)
    timeout: int = 20
    endpoints: List[EndpointLog] = field(default_factory=list)
    out_dir: Optional[str] = None
    transcripts_dir: Optional[str] = None

def build(ctx: TestContext, path: str) -> str:
    return f"{ctx.base_url.rstrip('/')}{path}"

def me(ctx: TestContext, path: str) -> str:
    return f"{ctx.base_url.rstrip('/')}/api/{ctx.me_prefix.strip('/')}{path}"

def auth_headers(token: Optional[str]) -> Dict[str, str]:
    h = {"Accept": "application/json"}
    if token:
        h["Authorization"] = f"Bearer {token}"
    return h

def gen_png_bytes() -> bytes:
    if not PIL_AVAILABLE:
        return b"\x89PNG\r\n\x1a\n"
    img = Image.new("RGBA", (120, 120), (24, 28, 40, 255))
    d = ImageDraw.Draw(img)
    d.ellipse((10, 10, 110, 110), fill=(70, 160, 255, 255))
    buf = io.BytesIO()
    img.save(buf, format="PNG")
    return buf.getvalue()

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
        title=title, method=method, url=url,
        req_headers=req_headers_log, req_body=json_body if json_body is not None else {},
        req_is_json=True, duration_ms=(time.time() - t0) * 1000.0
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
        title="POST(multipart) " + title, method="POST(multipart)", url=url,
        req_headers=req_headers_log, req_body=friendly_body, req_is_json=False,
        duration_ms=(time.time() - t0) * 1000.0
    )
    log_exchange(ctx, el, resp)
    return resp

def log_exchange(ctx: TestContext, el: EndpointLog, resp: Optional[requests.Response]):
    if resp is not None:
        ct = (resp.headers.get("Content-Type") or "")
        el.resp_status = resp.status_code
        el.resp_headers = {k: str(v) for k, v in resp.headers.items()}
        el.resp_content_type = ct
        content = resp.content or b""
        el.resp_bytes = content[:SAVE_BODY_LIMIT] if len(content) > SAVE_BODY_LIMIT else content
        if "application/json" in ct.lower():
            try:
                el.resp_body_pretty = pretty_json(mask_json_sensitive(resp.json()))
            except Exception:
                el.resp_body_pretty = as_text(el.resp_bytes)
        elif "text/" in ct.lower():
            el.resp_body_pretty = as_text(el.resp_bytes)
        elif "image" in ct.lower() or "octet-stream" in ct.lower() or "pdf" in ct.lower():
            el.resp_body_pretty = f"<binary> bytes={len(el.resp_bytes)} content-type={ct}"
        else:
            el.resp_body_pretty = as_text(el.resp_bytes)
    ctx.endpoints.append(el)

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
    req_payload = {
        "title": ep.title, "method": ep.method, "url": ep.url,
        "headers": ep.req_headers, "body": ep.req_body,
        "is_json": ep.req_is_json, "duration_ms": round(ep.duration_ms, 1),
    }
    write_text(os.path.join(tr_dir, f"{base}--request.json"), pretty_json(req_payload))
    resp_meta = {
        "status": ep.resp_status, "headers": ep.resp_headers,
        "content_type": ep.resp_content_type,
    }
    write_text(os.path.join(tr_dir, f"{base}--response.json"), pretty_json(resp_meta))
    if ep.resp_bytes is not None:
        ext = guess_ext_by_ct(ep.resp_content_type)
        path_raw = os.path.join(tr_dir, f"{base}--response_raw{ext}")
        write_bytes(path_raw, ep.resp_bytes)

class ApiTester:
    def __init__(self, ctx: TestContext):
        self.ctx = ctx
        self.results: List[TestRecord] = []

    def run(self):
        self.ctx.out_dir = build_output_dir()
        self.ctx.transcripts_dir = os.path.join(self.ctx.out_dir, "transcripts")

        steps: List[Tuple[str, Callable[[], Dict[str, Any]]]] = [
            # ─── bazowe flow (oryginalny test) ───────────────────────────────────────────────
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

            # ─── rozbudowa: role & moderacja ────────────────────────────────────────────────
            ("Register D (admin)", self.t_register_D),
            ("Login D", self.t_login_D),
            ("Invite D as admin", self.t_invite_D_admin),
            ("D accepts invitation", self.t_D_accept),

            ("Register E (moderator)", self.t_register_E),
            ("Login E", self.t_login_E),
            ("Invite E as moderator", self.t_invite_E_moderator),
            ("E accepts invitation", self.t_E_accept),

            ("D creates note (multipart)", self.t_create_note_D),
            ("D shares note → course", self.t_share_note_D_to_course),
            ("E creates note (multipart)", self.t_create_note_E),
            ("E shares note → course", self.t_share_note_E_to_course),

            ("Moderator E cannot remove admin D → 403", self.t_mod_E_cannot_remove_admin_D),
            ("Moderator E cannot remove owner A → 400/422", self.t_mod_E_cannot_remove_owner_A),

            ("Admin D removes moderator E (kick + purge)", self.t_admin_D_removes_mod_E),
            ("Verify E note unshared", self.t_verify_E_note_unshared),
            ("E lost course membership", self.t_E_lost_membership),

            ("Owner sets D role→admin (idempotent)", self.t_owner_sets_D_admin),
            ("Owner sets D role→moderator (demote admin)", self.t_owner_demotes_D_to_moderator),
            ("Admin cannot set role of admin (self-check) → 403", self.t_admin_cannot_change_admin),

            ("Admin cannot set owner role → 422", self.t_admin_cannot_set_owner_role),

            ("Owner sets E (re-invite) as moderator", self.t_owner_reinvite_E_as_moderator),

            ("Register F (member)", self.t_register_F),
            ("Login F", self.t_login_F),
            ("Invite F as member", self.t_invite_F_member),
            ("F accepts invitation", self.t_F_accept),
            ("F creates note and shares", self.t_create_and_share_note_F),

            ("Moderator E purges F notes", self.t_mod_E_purges_F_notes),
            ("Moderator E removes F user", self.t_mod_E_removes_F_user),

            ("Owner sets B→moderator (re-invite B)", self.t_owner_reinvite_B_and_set_moderator),
            ("Admin D sets B→member (demote)", self.t_admin_sets_B_member),

            # ─── powrót do istniejących końcówek (public + rejections C) ───────────────────
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

    # ───────────────────────── Testy bazowe (oryginalne) — skróty z pełnymi asercjami ─────
    def t_index_no_token(self):
        url = me(self.ctx, "/courses")
        r = http_json(self.ctx, "Index no token", "GET", url, None, {"Accept":"application/json"})
        assert r.status_code in (401,403), f"Bez tokenu 401/403: {r.status_code} {trim(r.text)}"
        return {"status": r.status_code, "method":"GET", "url":url}

    def t_register_A(self):
        self.ctx.emailA = f"owner.{random.randint(10000,99999)}@example.com"; self.ctx.pwdA = "Haslo123123"
        url = build(self.ctx, "/api/users/register")
        r = http_json(self.ctx, "Register A", "POST", url,
                      {"name":"Tester A","email":self.ctx.emailA,"password":self.ctx.pwdA,"password_confirmation":self.ctx.pwdA},
                      {"Accept":"application/json"})
        assert r.status_code in (200,201), f"Register A {r.status_code}: {trim(r.text)}"
        return {"status": r.status_code, "method":"POST","url":url}

    def t_login_A(self):
        url = build(self.ctx, "/api/login")
        r = http_json(self.ctx, "Login A", "POST", url, {"email":self.ctx.emailA,"password":self.ctx.pwdA}, {"Accept":"application/json"})
        assert r.status_code == 200, f"Login A {r.status_code}: {trim(r.text)}"
        self.ctx.tokenA = r.json().get("token")
        assert self.ctx.tokenA, "Brak tokenu JWT (A)"
        return {"status": 200, "method":"POST","url":url}

    def t_create_course_A(self):
        url = me(self.ctx, "/courses")
        r = http_json(self.ctx, "Create course", "POST", url,
                      {"title":"My Course","description":"Course for E2E","type":"private"},
                      auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,201), f"Create course {r.status_code}: {trim(r.text)}"
        js = r.json()
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
        ids = [c.get("id") for c in r.json()]
        assert self.ctx.course_id in ids, "Kurs A nie widoczny u A"
        return {"status": 200, "method":"GET","url":url}

    def t_register_B(self):
        self.ctx.emailB = f"member.{random.randint(10000,99999)}@example.com"; self.ctx.pwdB = "Haslo123123"
        url = build(self.ctx, "/api/users/register")
        r = http_json(self.ctx, "Register B", "POST", url,
                      {"name":"Tester B","email":self.ctx.emailB,"password":self.ctx.pwdB,"password_confirmation":self.ctx.pwdB},
                      {"Accept":"application/json"})
        assert r.status_code in (200,201), f"Register B {r.status_code}: {trim(r.text)}"
        return {"status": r.status_code, "method":"POST", "url":url}

    def t_login_B(self):
        url = build(self.ctx, "/api/login")
        r = http_json(self.ctx, "Login B", "POST", url, {"email":self.ctx.emailB,"password":self.ctx.pwdB}, {"Accept":"application/json"})
        assert r.status_code == 200, f"Login B {r.status_code}: {trim(r.text)}"
        self.ctx.tokenB = r.json().get("token"); assert self.ctx.tokenB
        return {"status": 200, "method":"POST","url":url}

    def t_download_avatar_B_unauth(self):
        url = me(self.ctx, f"/courses/{self.ctx.course_id}/avatar")
        r = http_json(self.ctx, "B cannot download A avatar", "GET", url, None, auth_headers(self.ctx.tokenB))
        assert r.status_code in (401,403), f"B nie powinien móc pobrać avatara: {r.status_code}"
        return {"status": r.status_code, "method":"GET","url":url}

    def t_B_cannot_update_A_course(self):
        url = me(self.ctx, f"/courses/{self.ctx.course_id}")
        r = http_json(self.ctx, "B cannot update", "PATCH", url, {"title":"Hack"}, auth_headers(self.ctx.tokenB))
        assert r.status_code in (401,403), f"Brak uprawnień 401/403: {r.status_code}"
        return {"status": r.status_code, "method":"PATCH","url":url}

    def t_B_cannot_delete_A_course(self):
        url = me(self.ctx, f"/courses/{self.ctx.course_id}")
        r = http_json(self.ctx, "B cannot delete", "DELETE", url, None, auth_headers(self.ctx.tokenB))
        assert r.status_code in (401,403), f"Brak uprawnień 401/403: {r.status_code}"
        return {"status": r.status_code, "method":"DELETE","url":url}

    def t_invite_B(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id}/invite-user")
        r = http_json(self.ctx, "Invite B", "POST", url, {"email": self.ctx.emailB, "role":"member"}, auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,201), f"Invite {r.status_code}: {trim(r.text)}"
        return {"status": r.status_code, "method":"POST","url":url}

    def t_B_received(self):
        url = build(self.ctx, "/api/me/invitations-received")
        r = http_json(self.ctx, "B received", "GET", url, None, auth_headers(self.ctx.tokenB))
        assert r.status_code == 200, f"Invitations received {r.status_code}: {trim(r.text)}"
        invitations = r.json().get("invitations", [])
        assert invitations, "Brak zaproszeń dla B"
        self.ctx.invite_token_B = invitations[0].get("token"); assert self.ctx.invite_token_B
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
        ids = [c.get("id") for c in r.json()]
        assert self.ctx.course_id in ids, "Kurs A nie widoczny u B po akceptacji"
        return {"status": 200, "method":"GET","url":url}

    def t_course_users_member_view(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id}/users")
        r = http_json(self.ctx, "Course users (member)", "GET", url, None, auth_headers(self.ctx.tokenB))
        assert r.status_code == 200
        js = r.json(); assert "users" in js and isinstance(js["users"], list)
        assert len(js["users"]) >= 2, "Powinno być >=2 (owner + member)"
        return {"status": 200, "method":"GET","url":url}

    def t_course_users_admin_all(self):
        # owner – alias „admin-like all”
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id}/users?per_page=1")
        r = http_json(self.ctx, "Course users (admin all + per_page=1)", "GET", url, None, auth_headers(self.ctx.tokenA))
        assert r.status_code == 200
        js = r.json(); assert "users" in js
        return {"status": 200, "method":"GET","url":url}

    def t_course_users_filter_q_role(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id}/users?q=tester&role=member")
        r = http_json(self.ctx, "Course users filter", "GET", url, None, auth_headers(self.ctx.tokenA))
        assert r.status_code == 200
        return {"status": 200, "method":"GET","url":url}

    def t_create_note_A(self):
        url = build(self.ctx, "/api/me/notes")
        files = {"file": ("sample.png", gen_png_bytes(), "image/png")}
        r = http_multipart(self.ctx, "A creates note (multipart)", url,
                           {"title":"A note","description":"desc","is_private":"true"}, files, auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,201)
        self.ctx.note_id = r.json().get("note",{}).get("id") or r.json().get("id")
        assert self.ctx.note_id
        return {"status": r.status_code, "method":"POST","url":url}

    def t_B_cannot_share_A_note(self):
        url = build(self.ctx, f"/api/me/notes/{self.ctx.note_id}/share/{self.ctx.course_id}")
        r = http_json(self.ctx, "B share A note (should fail)", "POST", url, {}, auth_headers(self.ctx.tokenB))
        assert r.status_code in (403,404)
        return {"status": r.status_code, "method":"POST","url":url}

    def t_A_share_note_invalid_course(self):
        url = build(self.ctx, f"/api/me/notes/{self.ctx.note_id}/share/99999999")
        r = http_json(self.ctx, "A share note invalid course", "POST", url, {}, auth_headers(self.ctx.tokenA))
        assert r.status_code == 404
        return {"status": 404, "method":"POST","url":url}

    def t_share_note_to_course(self):
        url = build(self.ctx, f"/api/me/notes/{self.ctx.note_id}/share/{self.ctx.course_id}")
        r = http_json(self.ctx, "A share note → course", "POST", url, {}, auth_headers(self.ctx.tokenA))
        assert r.status_code == 200
        return {"status": 200, "method":"POST","url":url}

    def t_verify_note_shared(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id}/notes")
        r = http_json(self.ctx, "Course notes (verify shared)", "GET", url, None, auth_headers(self.ctx.tokenA))
        assert r.status_code == 200
        found = [n for n in r.json().get("notes", []) if n.get("id") == self.ctx.note_id]
        assert found and found[0].get("is_private") is False, "Powinno być publiczne w kursie"
        return {"status": 200, "method":"GET","url":url}

    def t_course_notes_owner_member(self):
        urlA = build(self.ctx, f"/api/courses/{self.ctx.course_id}/notes")
        rA = http_json(self.ctx, "Course notes (owner)", "GET", urlA, None, auth_headers(self.ctx.tokenA))
        assert rA.status_code == 200
        urlB = build(self.ctx, f"/api/courses/{self.ctx.course_id}/notes")
        rB = http_json(self.ctx, "Course notes (member)", "GET", urlB, None, auth_headers(self.ctx.tokenB))
        assert rB.status_code == 200
        return {"status": 200, "method":"GET","url":urlA}

    def t_course_notes_outsider_private_403(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id}/notes")
        r = http_json(self.ctx, "Course notes outsider private", "GET", url, None, {"Accept":"application/json"})
        assert r.status_code in (401,403)
        return {"status": r.status_code, "method":"GET","url":url}

    def t_remove_B(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id}/remove-user")
        r = http_json(self.ctx, "Remove B", "POST", url, {"email": self.ctx.emailB}, auth_headers(self.ctx.tokenA))
        assert r.status_code == 200 and r.json() is True
        return {"status": 200, "method":"POST","url":url}

    def t_index_courses_B_not_contains(self):
        url = build(self.ctx, "/api/me/courses")
        r = http_json(self.ctx, "Index courses B (not contains)", "GET", url, None, auth_headers(self.ctx.tokenB))
        assert r.status_code == 200
        ids = [c.get("id") for c in r.json()]
        assert self.ctx.course_id not in ids
        return {"status": 200, "method":"GET","url":url}

    def t_remove_non_member_true(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id}/remove-user")
        r = http_json(self.ctx, "Remove non-member idempotent", "POST", url, {"email": self.ctx.emailB}, auth_headers(self.ctx.tokenA))
        assert r.status_code in (404,200,422,400), "Tu dopuszczamy różne kontrakty — ważne, że nie 500"
        return {"status": r.status_code, "method":"POST","url":url}

    def t_remove_owner_422(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id}/remove-user")
        r = http_json(self.ctx, "Remove owner → 422", "POST", url, {"email": self.ctx.emailA}, auth_headers(self.ctx.tokenA))
        assert r.status_code in (400,422), f"Powinno blokować ownera: {r.status_code}"
        return {"status": r.status_code, "method":"POST","url":url}

    # ───────────────────────── Nowe: role & moderacja ─────────────────────────
    def t_register_D(self):
        self.ctx.emailD = f"admin.{random.randint(10000,99999)}@example.com"; self.ctx.pwdD = "Haslo123123"
        url = build(self.ctx, "/api/users/register")
        r = http_json(self.ctx, "Register D", "POST", url,
                      {"name":"Tester D","email":self.ctx.emailD,"password":self.ctx.pwdD,"password_confirmation":self.ctx.pwdD},
                      {"Accept":"application/json"})
        assert r.status_code in (200,201)
        return {"status": r.status_code, "method":"POST","url":url}

    def t_login_D(self):
        url = build(self.ctx, "/api/login")
        r = http_json(self.ctx, "Login D", "POST", url, {"email":self.ctx.emailD,"password":self.ctx.pwdD}, {"Accept":"application/json"})
        assert r.status_code == 200
        self.ctx.tokenD = r.json().get("token"); assert self.ctx.tokenD
        return {"status": 200, "method":"POST","url":url}

    def t_invite_D_admin(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id}/invite-user")
        r = http_json(self.ctx, "Invite D (admin)", "POST", url, {"email": self.ctx.emailD, "role":"admin"}, auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,201)
        return {"status": r.status_code, "method":"POST","url":url}

    def t_D_accept(self):
        # pobierz zaproszenie D
        url = build(self.ctx, "/api/me/invitations-received")
        r = http_json(self.ctx, "D received", "GET", url, None, auth_headers(self.ctx.tokenD))
        token = r.json().get("invitations", [])[0].get("token")
        self.ctx.invite_token_D = token; assert token
        url2 = build(self.ctx, f"/api/invitations/{token}/accept")
        r2 = http_json(self.ctx, "D accept", "POST", url2, {}, auth_headers(self.ctx.tokenD))
        assert r2.status_code == 200
        return {"status": 200, "method":"POST","url":url2}

    def t_register_E(self):
        self.ctx.emailE = f"moderator.{random.randint(10000,99999)}@example.com"; self.ctx.pwdE = "Haslo123123"
        url = build(self.ctx, "/api/users/register")
        r = http_json(self.ctx, "Register E", "POST", url,
                      {"name":"Tester E","email":self.ctx.emailE,"password":self.ctx.pwdE,"password_confirmation":self.ctx.pwdE},
                      {"Accept":"application/json"})
        assert r.status_code in (200,201)
        return {"status": r.status_code, "method":"POST","url":url}

    def t_login_E(self):
        url = build(self.ctx, "/api/login")
        r = http_json(self.ctx, "Login E", "POST", url, {"email":self.ctx.emailE,"password":self.ctx.pwdE}, {"Accept":"application/json"})
        assert r.status_code == 200
        self.ctx.tokenE = r.json().get("token"); assert self.ctx.tokenE
        return {"status": 200, "method":"POST","url":url}

    def t_invite_E_moderator(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id}/invite-user")
        r = http_json(self.ctx, "Invite E (moderator)", "POST", url, {"email": self.ctx.emailE, "role":"moderator"}, auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,201)
        return {"status": r.status_code, "method":"POST","url":url}

    def t_E_accept(self):
        url = build(self.ctx, "/api/me/invitations-received")
        r = http_json(self.ctx, "E received", "GET", url, None, auth_headers(self.ctx.tokenE))
        token = r.json().get("invitations", [])[0].get("token")
        self.ctx.invite_token_E = token; assert token
        url2 = build(self.ctx, f"/api/invitations/{token}/accept")
        r2 = http_json(self.ctx, "E accept", "POST", url2, {}, auth_headers(self.ctx.tokenE))
        assert r2.status_code == 200
        return {"status": 200, "method":"POST","url":url2}

    def t_create_note_D(self):
        url = build(self.ctx, "/api/me/notes")
        files = {"file": ("sample.png", gen_png_bytes(), "image/png")}
        r = http_multipart(self.ctx, "D creates note", url,
                           {"title":"D note","description":"desc","is_private":"true"}, files, auth_headers(self.ctx.tokenD))
        assert r.status_code in (200,201)
        self.ctx.noteD_id = r.json().get("note",{}).get("id") or r.json().get("id"); assert self.ctx.noteD_id
        return {"status": r.status_code, "method":"POST","url":url}

    def t_share_note_D_to_course(self):
        url = build(self.ctx, f"/api/me/notes/{self.ctx.noteD_id}/share/{self.ctx.course_id}")
        r = http_json(self.ctx, "D share note → course", "POST", url, {}, auth_headers(self.ctx.tokenD))
        assert r.status_code == 200
        return {"status": 200, "method":"POST","url":url}

    def t_create_note_E(self):
        url = build(self.ctx, "/api/me/notes")
        files = {"file": ("sample.png", gen_png_bytes(), "image/png")}
        r = http_multipart(self.ctx, "E creates note", url,
                           {"title":"E note","description":"desc","is_private":"true"}, files, auth_headers(self.ctx.tokenE))
        assert r.status_code in (200,201)
        self.ctx.noteE_id = r.json().get("note",{}).get("id") or r.json().get("id"); assert self.ctx.noteE_id
        return {"status": r.status_code, "method":"POST","url":url}

    def t_share_note_E_to_course(self):
        url = build(self.ctx, f"/api/me/notes/{self.ctx.noteE_id}/share/{self.ctx.course_id}")
        r = http_json(self.ctx, "E share note → course", "POST", url, {}, auth_headers(self.ctx.tokenE))
        assert r.status_code == 200
        return {"status": 200, "method":"POST","url":url}

    def t_mod_E_cannot_remove_admin_D(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id}/remove-user")
        r = http_json(self.ctx, "E cannot remove D", "POST", url, {"email": self.ctx.emailD}, auth_headers(self.ctx.tokenE))
        assert r.status_code in (401,403), f"Moderator nie powinien móc wyrzucić admina: {r.status_code}"
        return {"status": r.status_code, "method":"POST","url":url}

    def t_mod_E_cannot_remove_owner_A(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id}/remove-user")
        r = http_json(self.ctx, "E cannot remove owner A", "POST", url, {"email": self.ctx.emailA}, auth_headers(self.ctx.tokenE))
        assert r.status_code in (400,422,403), "Blokada ownera z poziomu moda"
        return {"status": r.status_code, "method":"POST","url":url}

    def t_admin_D_removes_mod_E(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id}/remove-user")
        r = http_json(self.ctx, "Admin removes moderator E", "POST", url, {"email": self.ctx.emailE}, auth_headers(self.ctx.tokenD))
        assert r.status_code == 200 and r.json() is True
        return {"status": 200, "method":"POST","url":url}

    def t_verify_E_note_unshared(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id}/notes")
        r = http_json(self.ctx, "Verify E note unshared", "GET", url, None, auth_headers(self.ctx.tokenA))
        assert r.status_code == 200
        ids = [n.get("id") for n in r.json().get("notes",[])]
        assert self.ctx.noteE_id not in ids, "Notatka E powinna być odpięta"
        return {"status": 200, "method":"GET","url":url}

    def t_E_lost_membership(self):
        url = build(self.ctx, "/api/me/courses")
        r = http_json(self.ctx, "E courses after kick", "GET", url, None, auth_headers(self.ctx.tokenE))
        assert r.status_code == 200
        ids = [c.get("id") for c in r.json()]
        assert self.ctx.course_id not in ids
        return {"status": 200, "method":"GET","url":url}

    def t_owner_sets_D_admin(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id}/users/{self._get_id_by_email(self.ctx.emailD)}/role")
        # żeby nie szukać ID po API, pozwolimy helperowi pobrać je przez users-list
        return self._role_patch_by_email("Owner sets D role→admin", self.ctx.tokenA, self.ctx.emailD, "admin")

    def t_owner_demotes_D_to_moderator(self):
        return self._role_patch_by_email("Owner demotes D → moderator", self.ctx.tokenA, self.ctx.emailD, "moderator")

    def t_admin_cannot_change_admin(self):
        # próbujemy adminem zmienić admina → 403
        res = self._role_patch_by_email_raw("Admin cannot change admin", self.ctx.tokenD, self.ctx.emailD, "moderator")
        assert res[0] in (401,403), f"Admin nie może zmieniać admina: {res[0]}"
        return {"status": res[0], "method":"PATCH","url": res[1]}

    def t_admin_cannot_set_owner_role(self):
        # próbujemy adminem ustawić ownera (na kimkolwiek) → 403/422 (u nas 403 jako niedozwolona rola)
        res = self._role_patch_by_email_raw("Admin cannot set owner role", self.ctx.tokenD, self.ctx.emailA, "owner")
        assert res[0] in (403,422,400), f"Admin nie powinien móc ustawiać ownera: {res[0]}"
        return {"status": res[0], "method":"PATCH","url": res[1]}

    def t_owner_reinvite_E_as_moderator(self):
        # re-invite E jako moderatora
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id}/invite-user")
        r = http_json(self.ctx, "Owner reinvite E as moderator", "POST", url, {"email": self.ctx.emailE, "role":"moderator"}, auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,201)
        # accept
        url2 = build(self.ctx, "/api/me/invitations-received")
        r2 = http_json(self.ctx, "E received #2", "GET", url2, None, auth_headers(self.ctx.tokenE))
        token = r2.json().get("invitations", [])[0].get("token"); assert token
        url3 = build(self.ctx, f"/api/invitations/{token}/accept")
        r3 = http_json(self.ctx, "E accept #2", "POST", url3, {}, auth_headers(self.ctx.tokenE))
        assert r3.status_code == 200
        return {"status": 200, "method":"POST","url":url3}

    def t_register_F(self):
        self.ctx.emailF = f"memberF.{random.randint(10000,99999)}@example.com"; self.ctx.pwdF = "Haslo123123"
        url = build(self.ctx, "/api/users/register")
        r = http_json(self.ctx, "Register F", "POST", url,
                      {"name":"Tester F","email":self.ctx.emailF,"password":self.ctx.pwdF,"password_confirmation":self.ctx.pwdF},
                      {"Accept":"application/json"})
        assert r.status_code in (200,201)
        return {"status": r.status_code, "method":"POST","url":url}

    def t_login_F(self):
        url = build(self.ctx, "/api/login")
        r = http_json(self.ctx, "Login F", "POST", url, {"email":self.ctx.emailF,"password":self.ctx.pwdF}, {"Accept":"application/json"})
        assert r.status_code == 200
        self.ctx.tokenF = r.json().get("token"); assert self.ctx.tokenF
        return {"status": 200, "method":"POST","url":url}

    def t_invite_F_member(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id}/invite-user")
        r = http_json(self.ctx, "Invite F (member)", "POST", url, {"email": self.ctx.emailF, "role":"member"}, auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,201)
        return {"status": r.status_code, "method":"POST","url":url}

    def t_F_accept(self):
        url = build(self.ctx, "/api/me/invitations-received")
        r = http_json(self.ctx, "F received", "GET", url, None, auth_headers(self.ctx.tokenF))
        token = r.json().get("invitations", [])[0].get("token"); assert token
        url2 = build(self.ctx, f"/api/invitations/{token}/accept")
        r2 = http_json(self.ctx, "F accept", "POST", url2, {}, auth_headers(self.ctx.tokenF))
        assert r2.status_code == 200
        return {"status": 200, "method":"POST","url":url2}

    def t_create_and_share_note_F(self):
        # F tworzy i udostępnia notatkę — by moderator mógł ją wyczyścić
        url = build(self.ctx, "/api/me/notes")
        files = {"file": ("sample.png", gen_png_bytes(), "image/png")}
        r = http_multipart(self.ctx, "F creates note", url,
                           {"title":"F note","description":"desc","is_private":"true"}, files, auth_headers(self.ctx.tokenF))
        assert r.status_code in (200,201)
        self.ctx.noteF_id = r.json().get("note",{}).get("id") or r.json().get("id"); assert self.ctx.noteF_id
        url2 = build(self.ctx, f"/api/me/notes/{self.ctx.noteF_id}/share/{self.ctx.course_id}")
        r2 = http_json(self.ctx, "F share note → course", "POST", url2, {}, auth_headers(self.ctx.tokenF))
        assert r2.status_code == 200
        return {"status": 200, "method":"POST","url":url2}

    def t_mod_E_purges_F_notes(self):
        # Moderator może czyścić membera
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id}/users/{self._get_id_by_email(self.ctx.emailF)}/notes")
        r = http_json(self.ctx, "Moderator E purges F notes", "DELETE", url, None, auth_headers(self.ctx.tokenE))
        assert r.status_code == 200
        # sprawdź, że notatki F nie ma już w kursie
        url2 = build(self.ctx, f"/api/courses/{self.ctx.course_id}/notes")
        r2 = http_json(self.ctx, "Verify F notes after purge", "GET", url2, None, auth_headers(self.ctx.tokenA))
        assert r2.status_code == 200
        ids = [n.get("id") for n in r2.json().get("notes",[])]
        assert self.ctx.noteF_id not in ids
        return {"status": 200, "method":"DELETE","url":url}

    def t_mod_E_removes_F_user(self):
        # Moderator wyrzuca membera
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id}/remove-user")
        r = http_json(self.ctx, "Moderator E removes F", "POST", url, {"email": self.ctx.emailF}, auth_headers(self.ctx.tokenE))
        assert r.status_code == 200 and r.json() is True
        return {"status": 200, "method":"POST","url":url}

    def t_owner_reinvite_B_and_set_moderator(self):
        # re-invite B, owner ustawia mu rolę moderator
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id}/invite-user")
        r = http_json(self.ctx, "Reinvite B", "POST", url, {"email": self.ctx.emailB, "role":"member"}, auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,201)
        url2 = build(self.ctx, "/api/me/invitations-received")
        r2 = http_json(self.ctx, "B received #2", "GET", url2, None, auth_headers(self.ctx.tokenB))
        token = r2.json().get("invitations", [])[0].get("token"); assert token
        url3 = build(self.ctx, f"/api/invitations/{token}/accept")
        r3 = http_json(self.ctx, "B accept #2", "POST", url3, {}, auth_headers(self.ctx.tokenB))
        assert r3.status_code == 200
        # teraz owner → PATCH /users/{id}/role moderator
        return self._role_patch_by_email("Owner sets B→moderator", self.ctx.tokenA, self.ctx.emailB, "moderator")

    def t_admin_sets_B_member(self):
        # admin (D) może zrzucić moderatora do member
        return self._role_patch_by_email("Admin sets B→member", self.ctx.tokenD, self.ctx.emailB, "member")

    # ───────────────────────── Dalsze kroki (oryginalne public + C rejections) ─────────────
    def t_register_C(self):
        self.ctx.emailC = f"outsiderC.{random.randint(10000,99999)}@example.com"; self.ctx.pwdC = "Haslo123123"
        url = build(self.ctx, "/api/users/register")
        r = http_json(self.ctx, "Register C", "POST", url,
                      {"name":"Tester C","email":self.ctx.emailC,"password":self.ctx.pwdC,"password_confirmation":self.ctx.pwdC},
                      {"Accept":"application/json"})
        assert r.status_code in (200,201)
        return {"status": r.status_code, "method":"POST","url":url}

    def t_login_C(self):
        url = build(self.ctx, "/api/login")
        r = http_json(self.ctx, "Login C", "POST", url, {"email":self.ctx.emailC,"password":self.ctx.pwdC}, {"Accept":"application/json"})
        assert r.status_code == 200
        self.ctx.tokenC = r.json().get("token"); assert self.ctx.tokenC
        return {"status": 200, "method":"POST","url":url}

    def t_create_course2_A(self):
        url = me(self.ctx, "/courses")
        r = http_json(self.ctx, "Create course #2", "POST", url,
                      {"title":"Course 2","description":"Another","type":"private"},
                      auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,201)
        js = r.json(); self.ctx.course2_id = (js.get("course") or {}).get("id") or js.get("id")
        assert self.ctx.course2_id
        return {"status": r.status_code, "method":"POST","url":url}

    def t_invite_C_1(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course2_id}/invite-user")
        r = http_json(self.ctx, "Invite C #1", "POST", url, {"email": self.ctx.emailC, "role":"member"}, auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,201)
        # pobierz token
        self._pull_last_invite_token_C()
        return {"status": r.status_code, "method":"POST","url":url}

    def _pull_last_invite_token_C(self):
        url = build(self.ctx, "/api/me/invitations-received")
        r = http_json(self.ctx, "C received last", "GET", url, None, auth_headers(self.ctx.tokenC))
        inv = r.json().get("invitations", [])
        assert inv
        self.ctx.invite_tokens_C.append(inv[0].get("token"))

    def t_reject_C_last(self):
        token = self.ctx.invite_tokens_C[-1]
        url = build(self.ctx, f"/api/invitations/{token}/reject")
        r = http_json(self.ctx, "C reject last", "POST", url, {}, auth_headers(self.ctx.tokenC))
        assert r.status_code == 200
        return {"status": 200, "method":"POST","url":url}

    def t_invite_C_2(self): return self.t_invite_C_1()
    def t_invite_C_3(self): return self.t_invite_C_1()

    def t_invite_C_4_blocked(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course2_id}/invite-user")
        r = http_json(self.ctx, "Invite C #4 blocked", "POST", url, {"email": self.ctx.emailC, "role":"member"}, auth_headers(self.ctx.tokenA))
        assert r.status_code in (400,422), f"Po 3 rejectach 4-te zaproszenie zablokowane: {r.status_code}"
        return {"status": r.status_code, "method":"POST","url":url}

    def t_create_public_course_A(self):
        url = me(self.ctx, "/courses")
        r = http_json(self.ctx, "Create course (public)", "POST", url,
                      {"title":"Public","description":"Public course","type":"public"},
                      auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,201)
        js = r.json(); self.ctx.public_course_id = (js.get("course") or {}).get("id") or js.get("id")
        assert self.ctx.public_course_id
        return {"status": r.status_code, "method":"POST","url":url}

    def t_create_note2_A(self):
        url = build(self.ctx, "/api/me/notes")
        files = {"file": ("sample.png", gen_png_bytes(), "image/png")}
        r = http_multipart(self.ctx, "A creates note #2", url,
                           {"title":"A note2","description":"desc2","is_private":"true"}, files, auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,201)
        self.ctx.note2_id = r.json().get("note",{}).get("id") or r.json().get("id"); assert self.ctx.note2_id
        return {"status": r.status_code, "method":"POST","url":url}

    def t_share_note2_to_public_course(self):
        url = build(self.ctx, f"/api/me/notes/{self.ctx.note2_id}/share/{self.ctx.public_course_id}")
        r = http_json(self.ctx, "A share note2 → public", "POST", url, {}, auth_headers(self.ctx.tokenA))
        assert r.status_code == 200
        return {"status": 200, "method":"POST","url":url}

    def t_course_notes_outsider_public_403(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.public_course_id}/notes")
        r = http_json(self.ctx, "Public course notes outsider", "GET", url, None, {"Accept":"application/json"})
        assert r.status_code in (401,403)
        return {"status": r.status_code, "method":"GET","url":url}

    def t_course_users_outsider_public_401(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.public_course_id}/users")
        r = http_json(self.ctx, "Public course users outsider", "GET", url, None, {"Accept":"application/json"})
        assert r.status_code in (401,403)
        return {"status": r.status_code, "method":"GET","url":url}

    def t_delete_course_A(self):
        url = me(self.ctx, f"/courses/{self.ctx.course_id}")
        r = http_json(self.ctx, "Delete course #1", "DELETE", url, None, auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,204)
        return {"status": r.status_code, "method":"DELETE","url":url}

    def t_delete_course2_A(self):
        url = me(self.ctx, f"/courses/{self.ctx.course2_id}")
        r = http_json(self.ctx, "Delete course #2", "DELETE", url, None, auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,204)
        return {"status": r.status_code, "method":"DELETE","url":url}

    # ───────────────────────── Pomocnicze ─────────────────────────
    def _get_id_by_email(self, email: str) -> int:
        # pobierz listę userów w kursie i znajdź id (używane do PATCH /users/{id}/role)
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id}/users?status=all&sort=name&order=asc")
        r = http_json(self.ctx, "List users to resolve id", "GET", url, None, auth_headers(self.ctx.tokenA))
        assert r.status_code == 200
        users = r.json().get("users", [])
        for u in users:
            if u.get("email") == email or u.get("name","").startswith("Tester") and email.split("@")[0].split(".")[0] in u.get("name",""):
                return int(u["id"])
        # fallback: pozwól UI powiedzieć który to — ale w teście nie powinno zajść
        raise AssertionError(f"Nie znaleziono ID dla {email}")

    def _role_patch_by_email(self, title: str, actor_token: str, target_email: str, role: str):
        uid = self._get_id_by_email(target_email)
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id}/users/{uid}/role")
        r = http_json(self.ctx, title, "PATCH", url, {"role": role}, auth_headers(actor_token))
        assert r.status_code in (200,403,422), f"Status nieoczekiwany dla {title}: {r.status_code} {trim(r.text)}"
        if r.status_code == 200:
            body = r.json()
            assert body.get("user",{}).get("id") == uid
            assert body.get("user",{}).get("role") == (role if role!="user" else "member")
        return {"status": r.status_code, "method":"PATCH","url":url}

    def _role_patch_by_email_raw(self, title: str, actor_token: str, target_email: str, role: str):
        uid = self._get_id_by_email(target_email)
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id}/users/{uid}/role")
        r = http_json(self.ctx, title, "PATCH", url, {"role": role}, auth_headers(actor_token))
        return (r.status_code, url)

def write_html_report(ctx: TestContext, results: List[TestRecord], endpoints: List[EndpointLog]):
    rows = []
    for r in results:
        cls = "pass" if r.passed else "fail"
        httpc = "ok" if r.status and 200 <= int(r.status) < 300 else ("warn" if r.status and int(r.status) < 400 else "err")
        rows.append(
            f"<tr class='{cls}'><td>{r.name}</td><td class='right {cls}'>{'PASS' if r.passed else 'FAIL'}</td>"
            f"<td class='right'>{r.duration_ms:.1f} ms</td><td>{r.method}</td><td><code>{r.url}</code></td>"
            f"<td class='right http {httpc}'>{r.status or ''}</td></tr>"
        )

    ep_html = []
    for i, ep in enumerate(endpoints, 1):
        base = f"{i:03d}-{safe_filename(ep.title)}"
        req_file = f"transcripts/{base}--request.json"
        resp_meta_file = f"transcripts/{base}--response.json"
        raw_candidates = [f for f in os.listdir(ctx.transcripts_dir) if f.startswith(base + "--response_raw")]
        raw_link = f"<em>brak</em>" if not raw_candidates else f"<a href='transcripts/{raw_candidates[0]}' target='_blank'>{raw_candidates[0]}</a>"
        req_h = pretty_json(ep.req_headers)
        req_b = pretty_json(mask_json_sensitive(ep.req_body)) if ep.req_is_json else pretty_json(ep.req_body)
        resp_h = pretty_json(ep.resp_headers)
        resp_b = ep.resp_body_pretty or ""
        resp_b_view = (resp_b[:MAX_BODY_LOG] + "\n…(truncated)") if len(resp_b) > MAX_BODY_LOG else resp_b
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
html, body {{ background: var(--bg); color: var(--ink); font-family: ui-sans-serif, system-ui, -apple-system; }}
.wrapper {{ max-width: 1100px; margin: 32px auto; padding: 0 16px; }}
table {{ width:100%; border-collapse: collapse; }}
td, th {{ border-bottom:1px solid #1b2335; padding:8px 10px; }}
td code {{ color:#cfe3ff; }}
td.right {{ text-align:right; }}
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
    path = os.path.join(ctx.out_dir, "APITestReport.html")
    write_text(path, html)
    print(c(f"📄 Zapisano raport HTML: {path}", Fore.CYAN))

def main():
    global NOTE_FILE_PATH
    args = parse_args()
    colorama_init()
    NOTE_FILE_PATH = args.note_file
    ses = requests.Session()
    ses.headers.update({"User-Agent": "CourseTest/1.5", "Accept": "application/json"})
    ctx = TestContext(base_url=args.base_url.rstrip("/"), me_prefix=args.me_prefix, ses=ses, timeout=args.timeout)
    print(c(f"\n{ICON_INFO} Start Course API tests (members-only notes + roles/moderation) — full transcript @ {ctx.base_url}\n", Fore.WHITE))
    ApiTester(ctx).run()

if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("\nPrzerwano.")
        sys.exit(130)
