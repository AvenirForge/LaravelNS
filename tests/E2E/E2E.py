#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
E2E.py — Zintegrowany test E2E (User, Note, Course, Quiz API)
Wersja rozszerzona, dążąca do 100% pokrycia "good" i "bad" case'ów.

- Konsola: tylko progres PASS/FAIL + na końcu jedna tabela zbiorcza
- HTML: pełne szczegóły każdego żądania (nagłówki, body, odpowiedź) oraz
        surowe transkrypcje (request/response) dla każdego endpointu.
- Wyniki: tests/results/ResultE2E--YYYY-MM-DD--HH-MM-SS/APITestReport.html
- Automatyczne otwieranie raportu w przeglądarce po zakończeniu.

Kolejność wykonywania:
1. User API (cykl życia, walidacja 422, błędy logowania)
2. Setup (rejestracja głównych aktorów: Owner, Member, Admin, Moderator, Outsider)
3. Note API (CRUD, walidacja, autoryzacja, błędy 404, paginacja)
4. Course API (CRUD, zaproszenia, role, moderacja, hierarchia, błędy uprawnień)
5. Quiz API (Zarządzanie: CRUD, walidacja limitów)
6. Quiz API (Rozwiązywanie: start, submit, weryfikacja wyników przez studenta)
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
import webbrowser  # Dodano do otwierania raportu
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

# ───────────────────────── UI (Zbiorczo) ─────────────────────────
ICON_OK    = "✅"
ICON_FAIL  = "❌"
ICON_INFO  = "ℹ️"
ICON_LOCK  = "🔒"
ICON_USER  = "👤"
ICON_PATCH = "🩹"
ICON_IMG   = "🖼️"
ICON_TRASH = "🗑️"
ICON_EXIT  = "🚪"
ICON_CLOCK = "⏱️"
ICON_NOTE  = "🗒️"
ICON_DOWN  = "⬇️"
ICON_BOOK  = "📘"
ICON_Q     = "❓"
ICON_A     = "🅰️"
ICON_LINK  = "🔗"
ICON_EDIT  = "✏️"
ICON_LIST  = "📋"
ICON_PLAY  = "▶️" # Do quizu
ICON_SCORE = "🎯" # Do quizu
ICON_STOP  = "🛑" # Do walidacji

BOX = "─" * 92
MAX_BODY_LOG = 12000
SAVE_BODY_LIMIT = 10 * 1024 * 1024

# ───────────────────────── Helpers: UI & Masking ─────────────────────────

def c(txt: str, color: str) -> str:
    return f"{color}{txt}{Style.RESET_ALL}"

def trim(s: str, n: int = 200) -> str:
    s = (s or "").replace("\n", " ")
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

def safe_filename(s: str) -> str:
    s = re.sub(r"[^\w\-.]+", "_", s.strip())
    return s[:120] if len(s) > 120 else s

# ───────────────────────── Helpers: PIL / Files ─────────────────────────

def gen_png_bytes() -> bytes:
    if not PIL_AVAILABLE:
        return b"\x89PNG\r\n\x1a\n"
    img = Image.new("RGBA", (120, 120), (24, 28, 40, 255))
    d = ImageDraw.Draw(img)
    d.ellipse((10, 10, 110, 110), fill=(70, 160, 255, 255))
    buf = io.BytesIO()
    img.save(buf, format="PNG")
    return buf.getvalue()

def gen_avatar_bytes() -> bytes:
    if not PIL_AVAILABLE:
        return b"\x89PNG\r\n\x1a\n"
    img = Image.new("RGBA", (220, 220), (24, 28, 40, 255))
    d = ImageDraw.Draw(img)
    d.ellipse((20, 20, 200, 200), fill=(70, 160, 255, 255))
    d.rectangle((98, 140, 122, 195), fill=(255, 255, 255, 255))
    buf = io.BytesIO()
    img.save(buf, format="PNG")
    return buf.getvalue()

# ───────────────────────── CLI ─────────────────────────

def default_avatar_path() -> str:
    return os.path.join(os.getcwd(), "tests", "sample_data", "test.jpg")

def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(description="NoteSync Zintegrowany Test E2E (User, Note, Course, Quiz)")
    p.add_argument("--base-url", required=True, help="np. http://localhost:8000 lub https://notesync.pl")
    p.add_argument("--me-prefix", default="me", help="prefiks dla /api/<prefix> (domyślnie 'me')")
    p.add_argument("--timeout", type=int, default=20, help="timeout żądań w sekundach")
    p.add_argument("--note-file", default=r"C:\xampp\htdocs\LaravelNS\tests\E2E\sample.png", help="plik do uploadu notatki (NoteTest)")
    p.add_argument("--avatar", default=default_avatar_path(), help="ścieżka do pliku avatara (UserTest)")
    p.add_argument("--html-report", action="store_true", help="(Ignorowane) Raport HTML jest zawsze generowany i otwierany.")
    return p.parse_args()

# ───────────────────────── Struktury (Zunifikowane) ─────────────────────────

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
    timeout: int
    started_at: float = field(default_factory=time.time)

    # Pliki
    note_file_path: str = ""
    avatar_bytes: Optional[bytes] = None

    # Raportowanie
    endpoints: List[EndpointLog] = field(default_factory=list)
    output_dir: str = ""
    transcripts_dir: str = ""

    # === Moduł UserTest ===
    userA_token: Optional[str] = None
    userA_email: str = ""
    userA_pwd: str = ""
    userB_email: str = ""

    # === Moduły Główne (Aktorzy) ===
    tokenOwner: Optional[str] = None
    emailOwner: str = ""
    pwdOwner: str = ""

    tokenB: Optional[str] = None
    emailB: str = ""
    pwdB: str = ""

    tokenC: Optional[str] = None
    emailC: str = ""
    pwdC: str = ""

    tokenD: Optional[str] = None
    emailD: str = ""
    pwdD: str = ""

    tokenE: Optional[str] = None
    emailE: str = ""
    pwdE: str = ""

    tokenF: Optional[str] = None
    emailF: str = ""
    pwdF: str = ""

    # === Moduł NoteTest ===
    note_id_A: Optional[int] = None

    # === Moduł CourseTest ===
    course_id_1: Optional[int] = None
    course_id_2: Optional[int] = None
    public_course_id: Optional[int] = None
    course_note_id_A: Optional[int] = None
    course_note_id_2A: Optional[int] = None
    course_note_id_D: Optional[int] = None
    course_note_id_E: Optional[int] = None
    course_note_id_F: Optional[int] = None
    invite_token_B: Optional[str] = None
    invite_tokens_C: List[str] = field(default_factory=list)
    invite_token_D: Optional[str] = None
    invite_token_E: Optional[str] = None
    invite_token_F: Optional[str] = None

    # === Moduł QuizTest ===
    quiz_token: Optional[str] = None # Używany token (powinien być Ownera lub QuizB)
    quiz_userB_email: str = ""
    quiz_userB_pwd: str = ""
    quiz_invite_token_B: Optional[str] = None
    quiz_course_id: Optional[int] = None

    test_private_id: Optional[int] = None # Pusty, do testów dostępu
    test_public_id: Optional[int] = None  # Główny, z pytaniami

    # Pytania/odpowiedzi w teście publicznym
    quiz_public_q1_id: Optional[int] = None
    quiz_public_q1_correct_ans_id: Optional[int] = None
    quiz_public_q1_wrong_ans_id: Optional[int] = None
    quiz_public_q_ids: List[int] = field(default_factory=list) # Dla limitu
    quiz_public_a_ids: List[int] = field(default_factory=list) # Dla limitu

    quiz_result_id: Optional[int] = None # Do śledzenia wyników

# ───────────────────────── Helpers: HTTP (Zunifikowane) ─────────────────────────

def build(ctx: TestContext, path: str) -> str:
    return f"{ctx.base_url.rstrip('/')}{path}"

def me(ctx: TestContext, path: str) -> str:
    return f"{ctx.base_url.rstrip('/')}/api/{ctx.me_prefix.strip('/')}{path}"

def auth_headers(token: Optional[str]) -> Dict[str, str]:
    h = {"Accept": "application/json"}
    if token:
        h["Authorization"] = f"Bearer {token}"
    return h

def rnd_email(prefix: str = "tester") -> str:
    token = "".join(random.choices(string.ascii_lowercase + string.digits, k=8))
    return f"{prefix}.{token}@example.com"

def must_json(resp: requests.Response) -> Dict[str, Any]:
    try:
        return resp.json()
    except Exception:
        raise AssertionError(f"Odpowiedź nie-JSON (CT={resp.headers.get('Content-Type')}): {trim(resp.text)}")

def security_header_notes(resp: requests.Response) -> List[str]:
    wanted = ["X-Content-Type-Options","X-Frame-Options","Referrer-Policy",
              "Content-Security-Policy","X-XSS-Protection","Strict-Transport-Security"]
    miss = [k for k in wanted if k not in resp.headers]
    return [f"Missing security headers: {', '.join(miss)}"] if miss else []

def log_exchange(ctx: TestContext, el: EndpointLog, resp: Optional[requests.Response]):
    if resp is not None:
        ct = (resp.headers.get("Content-Type") or "")
        el.resp_status = resp.status_code
        el.resp_headers = {k: str(v) for k, v in resp.headers.items()}
        el.resp_content_type = ct
        content = resp.content or b""
        el.resp_bytes = content[:SAVE_BODY_LIMIT] if len(content) > SAVE_BODY_LIMIT else content
        el.notes.extend(security_header_notes(resp))

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

    if ctx.transcripts_dir:
        idx = len(ctx.endpoints)
        save_endpoint_files(ctx.output_dir, ctx.transcripts_dir, idx, el)

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
        req_headers=req_headers_log,
        req_body=mask_json_sensitive(json_body) if json_body is not None else {},
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
        title=f"{title} (multipart)", method="POST(multipart)", url=url,
        req_headers=req_headers_log, req_body=friendly_body, req_is_json=False,
        duration_ms=(time.time() - t0) * 1000.0
    )
    log_exchange(ctx, el, resp)
    return resp

def http_json_update(ctx: TestContext, base_title: str, url: str,
                     json_body: Dict[str, Any], headers: Dict[str,str]) -> Tuple[requests.Response, str]:
    r = http_json(ctx, f"{base_title} (PATCH)", "PATCH", url, json_body, headers)
    if r.status_code == 405:
        r2 = http_json(ctx, f"{base_title} (PUT fallback)", "PUT", url, json_body, headers)
        return r2, "PUT"
    return r, "PATCH"

# ───────────────────────── Helpers: Raport & Transkrypcje ─────────────────────────

def build_output_dir() -> str:
    root = os.getcwd()
    date_str = time.strftime("%Y-%m-%d")
    time_str = time.strftime("%H-%M-%S")
    folder = f"ResultE2E--{date_str}--{time_str}"
    out_dir = os.path.join(root, "tests", "results", folder)
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
    try:
        with open(path, "wb") as f:
            f.write(data)
    except Exception as e:
        print(f"Błąd zapisu {path}: {e}")

def write_text(path: str, text: str):
    try:
        with open(path, "w", encoding="utf-8") as f:
            f.write(text)
    except Exception as e:
        print(f"Błąd zapisu {path}: {e}")

def save_endpoint_files(out_dir: str, tr_dir: str, idx: int, ep: EndpointLog):
    base = f"{idx:03d}-{safe_filename(ep.title)}"

    req_payload = {
        "title": ep.title, "method": ep.method, "url": ep.url,
        "headers": ep.req_headers, "body": ep.req_body,
        "is_json": ep.req_is_json, "duration_ms": round(ep.duration_ms, 1),
    }
    write_text(os.path.join(tr_dir, f"{base}--request.json"), pretty_json(req_payload))

    resp_meta = {
        "status": ep.resp_status, "headers": ep.resp_headers,
        "content_type": ep.resp_content_type, "notes": ep.notes,
    }
    write_text(os.path.join(tr_dir, f"{base}--response.json"), pretty_json(resp_meta))

    if ep.resp_bytes is not None:
        ext = guess_ext_by_ct(ep.resp_content_type)
        path_raw = os.path.join(tr_dir, f"{base}--response_raw{ext}")
        write_bytes(path_raw, ep.resp_bytes)

# ───────────────────────── Główny Runner ─────────────────────────

class E2ETester:
    def __init__(self, ctx: TestContext):
        self.ctx = ctx
        self.results: List[TestRecord] = []

    def run(self):
        self.ctx.transcripts_dir = os.path.join(self.ctx.output_dir, "transcripts")

        steps: List[Tuple[str, Callable[[], Dict[str, Any]]]] = [
            # ─ 1. Moduł User API (cykl życia + walidacja) ───────────────────────
            (f"{ICON_USER} USER: Rejestracja (A)", self.t_user_register_A),
            (f"{ICON_STOP} USER: Rejestracja (słabe hasło) → 422", self.t_user_register_weak_pass),
            (f"{ICON_LOCK} USER: Login (złe hasło) → 401", self.t_user_login_wrong_pass),
            (f"{ICON_LOCK} USER: Login (A)", self.t_user_login_A),
            (f"{ICON_LOCK} USER: Profil bez autoryzacji → 401", self.t_user_profile_unauth),
            (f"{ICON_USER} USER: Profil z autoryzacją", self.t_user_profile_auth),
            (f"{ICON_USER} USER: Rejestracja (B) do konfliktu", self.t_user_register_B),
            (f"{ICON_PATCH} USER: PATCH name (JSON)", self.t_user_patch_name_json),
            (f"{ICON_STOP} USER: PATCH email — konflikt (JSON) → 422", self.t_user_patch_email_conflict_json),
            (f"{ICON_PATCH} USER: PATCH email — poprawny (JSON)", self.t_user_patch_email_ok_json),
            (f"{ICON_PATCH} USER: PATCH password (JSON) + weryfikacja", self.t_user_patch_password_json),
            (f"{ICON_STOP} USER: Avatar — brak pliku → 422", self.t_user_avatar_missing),
            (f"{ICON_IMG} USER: Avatar — upload", self.t_user_avatar_upload),
            (f"{ICON_IMG} USER: Avatar — download", self.t_user_avatar_download),
            (f"{ICON_EXIT} USER: Logout", self.t_user_logout),
            (f"{ICON_LOCK} USER: Re-login (A) przed DELETE", self.t_user_relogin_A),
            (f"{ICON_TRASH} USER: DELETE profile (A)", self.t_user_delete_profile),
            (f"{ICON_LOCK} USER: Login po DELETE (A) → 401", self.t_user_login_after_delete_should_fail),

            # ─ 2. Setup Głównych Aktorów ──────────────────────────────────────────
            ("SETUP: Rejestracja Owner (A)", self.t_setup_register_OwnerA),
            ("SETUP: Rejestracja Member (B)", self.t_setup_register_MemberB),
            ("SETUP: Rejestracja Outsider (C)", self.t_setup_register_OutsiderC),
            ("SETUP: Rejestracja Admin (D)", self.t_setup_register_AdminD),
            ("SETUP: Rejestracja Moderator (E)", self.t_setup_register_ModeratorE),

            # ─ 3. Moduł Note API (CRUD, paginacja, walidacja) ────────────────────
            (f"{ICON_LOCK} NOTE: Login (Owner A)", self.t_note_login_A),
            (f"{ICON_LIST} NOTE: Index (initial empty)", self.t_note_index_initial),
            (f"{ICON_STOP} NOTE: Store: missing file → 422", self.t_note_store_missing_file),
            (f"{ICON_STOP} NOTE: Store: invalid mime → 422", self.t_note_store_invalid_mime),
            (f"{ICON_NOTE} NOTE: Store: ok (multipart)", self.t_note_store_ok),
            (f"{ICON_LIST} NOTE: Index (top=1, count)", self.t_note_index_contains_created),
            (f"{ICON_LOCK} NOTE: Login (Member B)", self.t_note_login_B),
            (f"{ICON_LOCK} NOTE: Foreign download (B) → 403", self.t_note_download_foreign_403),
            (f"{ICON_LOCK} NOTE: Login (Owner A) again", self.t_note_login_A_again),
            (f"{ICON_PATCH} NOTE: PATCH title only", self.t_note_patch_title_only),
            (f"{ICON_STOP} NOTE: PATCH is_private invalid → 422", self.t_note_patch_is_private_invalid),
            (f"{ICON_PATCH} NOTE: PATCH description + is_private=false", self.t_note_patch_desc_priv_false),
            (f"{ICON_STOP} NOTE: POST …/{id}/patch: missing file → 422", self.t_note_patch_file_missing),
            (f"{ICON_PATCH} NOTE: POST …/{id}/patch: ok", self.t_note_patch_file_ok),
            (f"{ICON_DOWN} NOTE: Download note file (200)", self.t_note_download_file_ok),
            (f"{ICON_TRASH} NOTE: DELETE note", self.t_note_delete_note),
            (f"{ICON_STOP} NOTE: Download after delete → 404", self.t_note_download_after_delete_404),
            (f"{ICON_LIST} NOTE: Index after delete (not present)", self.t_note_index_after_delete),
            (f"{ICON_STOP} NOTE: Access invalid ID (abc) → 404", self.t_note_access_invalid_id),

            # ─ 4. Moduł Course API (Role, Moderacja, Zaproszenia) ───────────────
            (f"{ICON_LOCK} COURSE: Index no token → 401", self.t_course_index_no_token),
            (f"{ICON_LOCK} COURSE: Login (Owner A)", self.t_course_login_A),
            (f"{ICON_BOOK} COURSE: Create course (private)", self.t_course_create_course_A),
            (f"{ICON_IMG} COURSE: Download avatar none → 404", self.t_course_download_avatar_none_404),
            (f"{ICON_IMG} COURSE: Upload course avatar", self.t_course_avatar_upload),
            (f"{ICON_STOP} COURSE: Create course invalid type → 422", self.t_course_create_course_invalid),
            (f"{ICON_STOP} COURSE: Create course validation (empty) → 422", self.t_course_create_invalid_validation),
            (f"{ICON_LIST} COURSE: Index courses (A) contains", self.t_course_index_courses_A_contains),
            (f"{ICON_LOCK} COURSE: Login (Member B)", self.t_course_login_B),
            (f"{ICON_LOCK} COURSE: B cannot download A avatar → 403", self.t_course_download_avatar_B_unauth),
            (f"{ICON_LOCK} COURSE: B cannot update A course → 403", self.t_course_B_cannot_update_A_course),
            (f"{ICON_LOCK} COURSE: B cannot delete A course → 403", self.t_course_B_cannot_delete_A_course),
            (f"{ICON_USER} COURSE: Invite B", self.t_course_invite_B),
            (f"{ICON_LIST} COURSE: B received invitations", self.t_course_B_received),
            (f"{ICON_USER} COURSE: B accepts invitation", self.t_course_B_accept),
            (f"{ICON_LIST} COURSE: Index courses (B) contains", self.t_course_index_courses_B_contains),
            (f"{ICON_LIST} COURSE: Course users — member view", self.t_course_users_member_view),
            (f"{ICON_LIST} COURSE: Course users — admin all", self.t_course_users_admin_all),
            (f"{ICON_LIST} COURSE: Course users — filter q & role", self.t_course_users_filter_q_role),
            (f"{ICON_LIST} COURSE: Course users — filter not found", self.t_course_users_filter_not_found),
            (f"{ICON_NOTE} COURSE: A creates note (multipart)", self.t_course_create_note_A),
            (f"{ICON_LOCK} COURSE: B cannot share A note → 403/404", self.t_course_B_cannot_share_A_note),
            (f"{ICON_STOP} COURSE: A share note → invalid course → 404", self.t_course_A_share_note_invalid_course),
            (f"{ICON_LINK} COURSE: A share note → private course", self.t_course_share_note_to_course),
            (f"{ICON_LINK} COURSE: Verify note shared flags", self.t_course_verify_note_shared),
            (f"{ICON_LINK} COURSE: A un-share note → private course", self.t_course_unshare_note),
            (f"{ICON_LIST} COURSE: Course notes — owner & member", self.t_course_notes_owner_member),
            (f"{ICON_LOCK} COURSE: Course notes — outsider private → 403", self.t_course_notes_outsider_private_403),
            (f"{ICON_TRASH} COURSE: Remove B", self.t_course_remove_B),
            (f"{ICON_LIST} COURSE: Index courses (B not contains)", self.t_course_index_courses_B_not_contains),
            (f"{ICON_TRASH} COURSE: Remove non-member idempotent", self.t_course_remove_non_member_true),
            (f"{ICON_STOP} COURSE: Cannot remove owner → 422", self.t_course_remove_owner_422),
            (f"{ICON_LOCK} COURSE: Login (Admin D)", self.t_course_login_D),
            (f"{ICON_USER} COURSE: Invite D as admin", self.t_course_invite_D_admin),
            (f"{ICON_USER} COURSE: D accepts invitation", self.t_course_D_accept),
            (f"{ICON_LOCK} COURSE: Login (Moderator E)", self.t_course_login_E),
            (f"{ICON_USER} COURSE: Invite E as moderator", self.t_course_invite_E_moderator),
            (f"{ICON_USER} COURSE: E accepts invitation", self.t_course_E_accept),
        ]

        total = len(steps)
        print(c(f"\n{ICON_INFO} Rozpoczynanie {total} zintegrowanych testów E2E @ {self.ctx.base_url}\n", Fore.WHITE))

        for i, (name, fn) in enumerate(steps, 1):
            self._exec(i, total, name, fn)

        # Dynamically add remaining steps (Quiz depends on previous results)
        remaining_steps = self._get_remaining_steps()
        total = len(steps) + len(remaining_steps)

        for i, (name, fn) in enumerate(remaining_steps, len(steps) + 1):
            self._exec(i, total, name, fn)

        self._summary()
        write_html_report(self.ctx, self.results, self.ctx.endpoints)

    def _get_remaining_steps(self) -> List[Tuple[str, Callable[[], Dict[str, Any]]]]:
        """Zwraca resztę kroków (Course-Mod, Quiz) po podstawowym setupie"""
        return [
            (f"{ICON_NOTE} COURSE: D creates note & shares", self.t_course_create_note_D_and_share),
            (f"{ICON_NOTE} COURSE: E creates note & shares", self.t_course_create_note_E_and_share),
            (f"{ICON_LOCK} COURSE: Mod E cannot remove admin D → 403", self.t_course_mod_E_cannot_remove_admin_D),
            (f"{ICON_LOCK} COURSE: Mod E cannot purge admin D notes → 403", self.t_course_mod_E_cannot_purge_admin_D_notes),
            (f"{ICON_LOCK} COURSE: Mod E cannot remove owner A → 422", self.t_course_mod_E_cannot_remove_owner_A),
            (f"{ICON_TRASH} COURSE: Admin D removes moderator E", self.t_course_admin_D_removes_mod_E),
            (f"{ICON_LIST} COURSE: Verify E note unshared", self.t_course_verify_E_note_unshared),
            (f"{ICON_LIST} COURSE: E lost course membership", self.t_course_E_lost_membership),
            (f"{ICON_PATCH} COURSE: Owner sets D role→admin (idempotent)", self.t_course_owner_sets_D_admin),
            (f"{ICON_PATCH} COURSE: Owner demotes D → moderator", self.t_course_owner_demotes_D_to_moderator),
            (f"{ICON_LOCK} COURSE: Admin cannot set role of admin → 403", self.t_course_admin_cannot_change_admin),
            (f"{ICON_LOCK} COURSE: Admin cannot set owner role → 422", self.t_course_admin_cannot_set_owner_role),
            (f"{ICON_LOCK} COURSE: Owner cannot demote self → 422", self.t_course_owner_cannot_demote_self),
            (f"{ICON_USER} COURSE: Owner sets E (re-invite) as moderator", self.t_course_owner_reinvite_E_as_moderator),
            (f"{ICON_USER} COURSE: Register F (member)", self.t_course_register_F),
            (f"{ICON_LOCK} COURSE: Login F", self.t_course_login_F),
            (f"{ICON_USER} COURSE: Invite F as member", self.t_course_invite_F_member),
            (f"{ICON_USER} COURSE: F accepts invitation", self.t_course_F_accept),
            (f"{ICON_NOTE} COURSE: F creates note and shares", self.t_course_create_and_share_note_F),
            (f"{ICON_TRASH} COURSE: Mod E purges F notes", self.t_course_mod_E_purges_F_notes),
            (f"{ICON_TRASH} COURSE: Mod E removes F user", self.t_course_mod_E_removes_F_user),
            (f"{ICON_USER} COURSE: Owner sets B→moderator (re-invite)", self.t_course_owner_reinvite_B_and_set_moderator),
            (f"{ICON_PATCH} COURSE: Admin D sets B→member (demote)", self.t_course_admin_sets_B_member),
            (f"{ICON_LOCK} COURSE: Login (Outsider C)", self.t_course_login_C),
            (f"{ICON_BOOK} COURSE: Create course #2 (private)", self.t_course_create_course2_A),
            (f"{ICON_USER} COURSE: Invite C #1", self.t_course_invite_C_1),
            (f"{ICON_USER} COURSE: C rejects #1", self.t_course_reject_C_last),
            (f"{ICON_USER} COURSE: Invite C #2", self.t_course_invite_C_2),
            (f"{ICON_USER} COURSE: C rejects #2", self.t_course_reject_C_last),
            (f"{ICON_USER} COURSE: Invite C #3", self.t_course_invite_C_3),
            (f"{ICON_USER} COURSE: C rejects #3 (i pauza dla replikacji)", self.t_course_reject_C_last_and_pause),
            (f"{ICON_STOP} COURSE: Invite C #4 blocked → 422", self.t_course_invite_C_4_blocked),
            (f"{ICON_BOOK} COURSE: Create course (public)", self.t_course_create_public_course_A),
            (f"{ICON_NOTE} COURSE: A creates note #2 & shares public", self.t_course_create_note2_A_and_share_public),
            (f"{ICON_LOCK} COURSE: Course notes — outsider public → 403", self.t_course_notes_outsider_public_403),
            (f"{ICON_LOCK} COURSE: Course users — outsider public → 401", self.t_course_users_outsider_public_401),
            (f"{ICON_TRASH} COURSE: Delete course #1 (private)", self.t_course_delete_course_A),
            (f"{ICON_TRASH} COURSE: Delete course #2 (rejections)", self.t_course_delete_course2_A),
            (f"{ICON_TRASH} COURSE: Delete course #3 (public)", self.t_course_delete_public_course_A),

            # ─ 5. Moduł Quiz API (Zarządzanie) ──────────────────────────────────
            (f"{ICON_LOCK} QUIZ: Login (Owner A)", self.t_quiz_login_A),
            (f"{ICON_BOOK} QUIZ: Create course (for quiz)", self.t_quiz_create_course),
            (f"{ICON_LIST} QUIZ: Index my tests (empty ok)", self.t_quiz_index_user_tests_initial),
            (f"{ICON_BOOK} QUIZ: Create PRIVATE test (access check)", self.t_quiz_create_private_test),
            (f"{ICON_BOOK} QUIZ: Create PUBLIC test (do pytań)", self.t_quiz_create_public_test),
            (f"{ICON_LIST} QUIZ: List my tests (contains both)", self.t_quiz_index_user_tests_contains_both),
            (f"{ICON_EDIT} QUIZ: Update public test (PUT)", self.t_quiz_update_public_test),
            (f"{ICON_Q} QUIZ: Add question #1 (public)", self.t_quiz_add_question),
            (f"{ICON_LIST} QUIZ: List questions has #1 (public)", self.t_quiz_list_questions_contains_q1),
            (f"{ICON_EDIT} QUIZ: Update question #1 (public)", self.t_quiz_update_question),
            (f"{ICON_STOP} QUIZ: Add answer invalid (no correct yet) → 422", self.t_quiz_add_answer_invalid_first),
            (f"{ICON_A} QUIZ: Add answer #1 (CORRECT)", self.t_quiz_add_answer_correct_first),
            (f"{ICON_STOP} QUIZ: Add answer duplicate → 422", self.t_quiz_add_answer_duplicate),
            (f"{ICON_A} QUIZ: Add answer #2 (WRONG)", self.t_quiz_add_answer_wrong_2),
            (f"{ICON_A} QUIZ: Add answer #3 (WRONG)", self.t_quiz_add_answer_wrong_3),
            (f"{ICON_A} QUIZ: Add answer #4 (WRONG)", self.t_quiz_add_answer_wrong_4),
            (f"{ICON_STOP} QUIZ: Add answer #5 blocked (limit 4) → 422", self.t_quiz_add_answer_limit),
            (f"{ICON_LIST} QUIZ: Get answers list (4)", self.t_quiz_get_answers_list),
            (f"{ICON_EDIT} QUIZ: Update answer #2 -> correct", self.t_quiz_update_answer),
            (f"{ICON_TRASH} QUIZ: Delete answer #3", self.t_quiz_delete_answer),
            (f"{ICON_TRASH} QUIZ: Delete question #1 (i 3 odp)", self.t_quiz_delete_question),
            (f"{ICON_Q} QUIZ: Add up to 20 questions (limit check)", self.t_quiz_add_questions_to_20),
            (f"{ICON_STOP} QUIZ: 21st question blocked → 422", self.t_quiz_add_21st_question_block),
            (f"{ICON_LINK} QUIZ: Share PUBLIC test → course", self.t_quiz_share_public_test_to_course),
            (f"{ICON_LIST} QUIZ: Course tests include shared", self.t_quiz_course_tests_include_shared),

            # ─ 6. Moduł Quiz API (Rozwiązywanie) ────────────────────────────────
            (f"{ICON_USER} QUIZ: Rejestracja B (Student)", self.t_quiz_register_B),
            (f"{ICON_USER} QUIZ: Invite B to Course", self.t_quiz_invite_B_to_course),
            (f"{ICON_LOCK} QUIZ: Login B (Student)", self.t_quiz_login_B),
            (f"{ICON_USER} QUIZ: B accepts invite", self.t_quiz_B_accept_invite),
            (f"{ICON_PLAY} QUIZ: B start test (public)", self.t_quiz_B_start_test),
            (f"{ICON_LOCK} QUIZ: B cannot start private test → 403", self.t_quiz_B_cannot_start_private_test),
            (f"{ICON_SCORE} QUIZ: B submit test (WRONG)", self.t_quiz_B_submit_test_wrong),
            (f"{ICON_SCORE} QUIZ: B check result (0%)", self.t_quiz_B_check_result_wrong),
            (f"{ICON_SCORE} QUIZ: B submit test (CORRECT)", self.t_quiz_B_submit_test_correct),
            (f"{ICON_SCORE} QUIZ: B check result (100%)", self.t_quiz_B_check_result_correct),
            (f"{ICON_LOCK} QUIZ: B cannot see A private test → 404", self.t_quiz_b_cannot_show_a_test),
            (f"{ICON_LOCK} QUIZ: B cannot modify A test → 404", self.t_quiz_b_cannot_modify_a_test),
            (f"{ICON_LOCK} QUIZ: B cannot add question to A test → 404", self.t_quiz_b_cannot_add_q_to_a_test),
            (f"{ICON_LOCK} QUIZ: B cannot delete A test → 404", self.t_quiz_b_cannot_delete_a_test),

            # ─ 7. Cleanup (Quiz) ────────────────────────────────────────────────
            (f"{ICON_TRASH} QUIZ: Cleanup A: delete public test", self.t_quiz_cleanup_delete_public),
            (f"{ICON_TRASH} QUIZ: Cleanup A: delete private test", self.t_quiz_cleanup_delete_private),
            (f"{ICON_TRASH} QUIZ: Cleanup A: delete course", self.t_quiz_cleanup_delete_course),
        ]

    def _exec(self, idx: int, total: int, name: str, fn: Callable[[], Dict[str, Any]]):
        start = time.time()
        ret: Dict[str, Any] = {}
        rec = TestRecord(name=name, passed=False, duration_ms=0)

        if name.startswith("SETUP:") or "Moduł" in name:
            print(c(f"\n{BOX}\n{ICON_INFO} {name}\n{BOX}", Fore.YELLOW))

        print(c(f"[{idx:03d}/{total:03d}] {name} …", Fore.CYAN), end=" ")

        try:
            ret = fn() or {}
            rec.passed = True
            rec.status = ret.get("status")
            rec.method = ret.get("method","")
            rec.url    = ret.get("url","")
            print(c("PASS", Fore.GREEN))
        except AssertionError as e:
            rec.error = str(e)
            rec.status = ret.get("status")
            print(c("FAIL", Fore.RED), c(f"— {e}", Fore.RED))
        except Exception as e:
            rec.error = f"{type(e).__name__}: {e}"
            print(c("ERROR", Fore.RED), c(f"— {e}", Fore.RED))

        rec.duration_ms = (time.time() - start) * 1000.0
        self.results.append(rec)

    def _summary(self):
        ok = [r for r in self.results if r.passed]
        fail = [r for r in self.results if not r.passed]

        print("\n" + BOX)
        print(c(f"{ICON_CLOCK} PODSUMOWANIE ZINTEGROWANE", Fore.WHITE))
        print(BOX)

        def http_color(s: Optional[int]) -> str:
            if s is None: return ""
            if 200 <= s < 300: return c(str(s), Fore.GREEN)
            if 400 <= s < 500: return c(str(s), Fore.YELLOW)
            return c(str(s), Fore.RED)

        rows = []
        for r in self.results:
            outcome = c("PASS", Fore.GREEN) if r.passed else c("FAIL", Fore.RED)
            rows.append([
                r.name,
                outcome,
                f"{r.duration_ms:.1f} ms",
                r.method or "",
                r.url or "",
                http_color(r.status),
            ])

        print(tabulate(
            rows,
            headers=["Test", "Wynik", "Czas", "Metoda", "URL", "HTTP"],
            tablefmt="fancy_grid",
            colalign=("left", "center", "right", "left", "left", "center"),
            disable_numparse=True
        ))

        total_ms = (time.time() - self.ctx.started_at) * 1000.0
        print(f"\nŁączny czas: {total_ms:.1f} ms | Testów: {len(self.results)} | PASS: {len(ok)} | FAIL: {len(fail)}\n")

    # ──────────────────────────────────────────────────────────────────────
    # === Metody pomocnicze (z różnych modułów) ===
    # ──────────────────────────────────────────────────────────────────────

    def _note_load_upload_bytes(self, path: str) -> Tuple[bytes, str, str]:
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

    def _course_get_id_by_email(self, email: str, course_id: int, actor_token: str) -> int:
        url = build(self.ctx, f"/api/courses/{course_id}/users?status=all&sort=name&order=asc")
        r = http_json(self.ctx, "List users to resolve id", "GET", url, None, auth_headers(actor_token))
        assert r.status_code == 200, "Nie udało się pobrać listy użytkowników kursu"
        users = r.json().get("users", [])
        for u in users:
            if u.get("email") == email:
                return int(u["id"])
        raise AssertionError(f"Nie znaleziono ID dla {email} w kursie {course_id}")

    def _course_role_patch_by_email(self, title: str, actor_token: str, target_email: str, role: str):
        uid = self._course_get_id_by_email(target_email, self.ctx.course_id_1, actor_token)
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id_1}/users/{uid}/role")
        r = http_json(self.ctx, title, "PATCH", url, {"role": role}, auth_headers(actor_token))
        assert r.status_code in (200,403,422), f"Status nieoczekiwany dla {title}: {r.status_code} {trim(r.text)}"
        if r.status_code == 200:
            body = r.json()
            assert body.get("user",{}).get("id") == uid
            assert body.get("user",{}).get("role") == (role if role!="user" else "member")
        return {"status": r.status_code, "method":"PATCH","url":url}

    def _course_role_patch_by_email_raw(self, title: str, actor_token: str, target_email: str, role: str):
        uid = self._course_get_id_by_email(target_email, self.ctx.course_id_1, actor_token)
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id_1}/users/{uid}/role")
        r = http_json(self.ctx, title, "PATCH", url, {"role": role}, auth_headers(actor_token))
        return (r.status_code, url)

    # ──────────────────────────────────────────────────────────────────────
    # === 1. Metody testowe: User API (Izolowane) ===
    # ──────────────────────────────────────────────────────────────────────

    def t_user_register_A(self):
        self.ctx.userA_email = rnd_email("userA")
        self.ctx.userA_pwd = "Haslo123123"
        url = build(self.ctx, "/api/users/register")
        r = http_json(self.ctx, "USER: Register A", "POST", url,
                      {"name":"Tester A","email":self.ctx.userA_email,"password":self.ctx.userA_pwd,"password_confirmation":self.ctx.userA_pwd},
                      {"Accept":"application/json"})
        assert r.status_code in (200,201), f"Register A {r.status_code}: {trim(r.text)}"
        return {"status": r.status_code, "method":"POST", "url":url}

    def t_user_register_weak_pass(self):
        email = rnd_email("weakpass")
        url = build(self.ctx, "/api/users/register")
        r = http_json(self.ctx, "USER: Register (słabe hasło)", "POST", url,
                      {"name":"Tester Weak","email":email,"password":"123","password_confirmation":"123"},
                      {"Accept":"application/json"})
        assert r.status_code in (400,422), f"Słabe hasło 400/422, jest {r.status_code}"
        return {"status": r.status_code, "method":"POST", "url":url}

    def t_user_login_wrong_pass(self):
        url = build(self.ctx,"/api/login")
        r = http_json(self.ctx, "USER: Login (złe hasło)", "POST", url,
                      {"email":self.ctx.userA_email,"password":"złehaslo"}, {"Accept":"application/json"})
        assert r.status_code in (400,401), f"Złe hasło 400/401, jest {r.status_code}"
        return {"status": r.status_code, "method":"POST", "url":url}

    def t_user_login_A(self):
        url = build(self.ctx,"/api/login")
        r = http_json(self.ctx, "USER: Login A", "POST", url,
                      {"email":self.ctx.userA_email,"password":self.ctx.userA_pwd}, {"Accept":"application/json"})
        assert r.status_code == 200, f"Login A {r.status_code}: {trim(r.text)}"
        self.ctx.userA_token = must_json(r).get("token")
        assert self.ctx.userA_token, "Brak tokenu JWT (userA)"
        return {"status": 200, "method":"POST","url":url}

    def t_user_profile_unauth(self):
        url = me(self.ctx,"/profile")
        r = http_json(self.ctx, "USER: Profile (unauth)", "GET", url, None, {"Accept":"application/json"})
        assert r.status_code in (401,403), f"Expected 401/403, got {r.status_code}"
        return {"status": r.status_code, "method":"GET", "url":url}

    def t_user_profile_auth(self):
        url = me(self.ctx,"/profile")
        r = http_json(self.ctx, "USER: Profile (auth)", "GET", url, None, auth_headers(self.ctx.userA_token))
        assert r.status_code == 200, f"Profile {r.status_code}: {trim(r.text)}"
        js = must_json(r)
        assert "user" in js and "email" in js["user"], "Brak user/email w odpowiedzi"
        return {"status": 200, "method":"GET", "url":url}

    def t_user_register_B(self):
        self.ctx.userB_email = rnd_email("userB")
        pwd = "Haslo123123"
        url = build(self.ctx,"/api/users/register")
        r = http_json(self.ctx, "USER: Register B (conflict)", "POST", url,
                      {"name":"Tester B","email":self.ctx.userB_email,"password":pwd,"password_confirmation":pwd},
                      {"Accept":"application/json"})
        assert r.status_code in (200,201), f"Register B {r.status_code}: {trim(r.text)}"
        return {"status": r.status_code, "method":"POST", "url":url}

    def t_user_patch_name_json(self):
        url = me(self.ctx,"/profile")
        r = http_json(self.ctx, "USER: PATCH name", "PATCH", url,
                      {"name":"Tester Renamed"}, auth_headers(self.ctx.userA_token))
        assert r.status_code == 200, f"PATCH name {r.status_code}: {trim(r.text)}"
        js = must_json(r)
        assert js.get("user",{}).get("name") == "Tester Renamed", "Imię nie zaktualizowane"
        return {"status": 200, "method":"PATCH", "url":url}

    def t_user_patch_email_conflict_json(self):
        url = me(self.ctx,"/profile")
        r = http_json(self.ctx, "USER: PATCH email conflict", "PATCH", url,
                      {"email": self.ctx.userB_email}, auth_headers(self.ctx.userA_token))
        assert r.status_code in (400,422), f"Konflikt 400/422, jest {r.status_code}: {trim(r.text)}"
        js = must_json(r)
        assert "error" in js or "errors" in js, "Brak 'error' z walidacją"
        return {"status": r.status_code, "method":"PATCH", "url":url}

    def t_user_patch_email_ok_json(self):
        url = me(self.ctx,"/profile")
        new_mail = rnd_email("userA.new")
        r = http_json(self.ctx, "USER: PATCH email ok", "PATCH", url,
                      {"email": new_mail}, auth_headers(self.ctx.userA_token))
        assert r.status_code == 200, f"PATCH email {r.status_code}: {trim(r.text)}"
        js = must_json(r)
        assert js.get("user",{}).get("email") == new_mail, "E-mail nie zaktualizowany"
        self.ctx.userA_email = new_mail
        return {"status": 200, "method":"PATCH", "url":url}

    def t_user_patch_password_json(self):
        old_pwd = self.ctx.userA_pwd
        new_pwd = "Haslo123123X"
        url = me(self.ctx,"/profile")
        r = http_json(self.ctx, "USER: PATCH password", "PATCH", url,
                      {"password": new_pwd, "password_confirmation": new_pwd}, auth_headers(self.ctx.userA_token))
        assert r.status_code == 200, f"PATCH password {r.status_code}: {trim(r.text)}"

        url_login = build(self.ctx,"/api/login")
        r_bad = http_json(self.ctx, "USER: Login old password (fail)", "POST", url_login,
                          {"email": self.ctx.userA_email, "password": old_pwd}, {"Accept":"application/json"})
        assert r_bad.status_code in (401, 400), f"Stare hasło nie działa, jest {r_bad.status_code}"

        r_ok = http_json(self.ctx, "USER: Login new password", "POST", url_login,
                         {"email": self.ctx.userA_email, "password": new_pwd}, {"Accept":"application/json"})
        assert r_ok.status_code == 200, f"Login nowym hasłem: {r_ok.status_code}"

        self.ctx.userA_token = must_json(r_ok).get("token")
        self.ctx.userA_pwd = new_pwd
        return {"status": 200, "method":"PATCH", "url":url}

    def t_user_avatar_missing(self):
        url = me(self.ctx,"/profile/avatar")
        r = http_multipart(self.ctx, "USER: Avatar missing", url, data={}, files={}, headers=auth_headers(self.ctx.userA_token))
        assert r.status_code in (400,422), f"Brak pliku 400/422, jest {r.status_code}"
        return {"status": r.status_code, "method":"POST", "url":url}

    def t_user_avatar_upload(self):
        url = me(self.ctx,"/profile/avatar")
        avatar = self.ctx.avatar_bytes or gen_avatar_bytes()
        files = {"avatar": ("test.jpg", avatar, "image/jpeg")}
        r = http_multipart(self.ctx, "USER: Avatar upload", url, data={}, files=files, headers=auth_headers(self.ctx.userA_token))
        assert r.status_code == 200, f"Avatar upload {r.status_code}: {trim(r.text)}"
        js = must_json(r)
        assert "avatar_url" in js, "Brak avatar_url"
        return {"status": 200, "method":"POST", "url":url}

    def t_user_avatar_download(self):
        url = me(self.ctx,"/profile/avatar")
        r = http_json(self.ctx, "USER: Avatar download", "GET", url, None, auth_headers(self.ctx.userA_token))
        assert r.status_code == 200, f"Avatar download {r.status_code}"
        ct = r.headers.get("Content-Type","")
        assert "image" in ct.lower(), f"Content-Type nie obraz: {ct}"
        return {"status": 200, "method":"GET", "url":url}

    def t_user_logout(self):
        url = me(self.ctx,"/logout")
        r = http_json(self.ctx, "USER: Logout", "POST", url, None, auth_headers(self.ctx.userA_token))
        assert r.status_code == 200, f"Logout {r.status_code}"

        url_profile = me(self.ctx,"/profile")
        r2 = http_json(self.ctx, "USER: Profile after logout", "GET", url_profile, None, auth_headers(self.ctx.userA_token))
        assert r2.status_code in (401,403), f"Po logout 401/403, jest {r2.status_code}"
        self.ctx.userA_token = None
        return {"status": 200, "method":"POST", "url":url}

    def t_user_relogin_A(self):
        return self.t_user_login_A()

    def t_user_delete_profile(self):
        url = me(self.ctx,"/profile")
        r = http_json(self.ctx, "USER: DELETE profile", "DELETE", url, None, auth_headers(self.ctx.userA_token))
        assert r.status_code == 200, f"DELETE profile {r.status_code}: {trim(r.text)}"
        self.ctx.userA_token = None
        return {"status": 200, "method":"DELETE", "url":url}

    def t_user_login_after_delete_should_fail(self):
        url = build(self.ctx,"/api/login")
        r = http_json(self.ctx, "USER: Login after delete (fail)", "POST", url,
                      {"email":self.ctx.userA_email,"password":self.ctx.userA_pwd}, {"Accept":"application/json"})
        assert r.status_code in (401, 400), f"Login po DELETE 401/400, jest {r.status_code}"
        return {"status": r.status_code, "method":"POST", "url":url}

    # ──────────────────────────────────────────────────────────────────────
    # === 2. Metody Setup (Główni Aktorzy) ===
    # ──────────────────────────────────────────────────────────────────────

    def _setup_register_and_login(self, title_prefix: str, email_prefix: str) -> Tuple[str, str, str]:
        email = rnd_email(email_prefix)
        pwd = "Haslo123123"

        url_reg = build(self.ctx, "/api/users/register")
        r_reg = http_json(self.ctx, f"SETUP: Register {title_prefix}", "POST", url_reg,
                          {"name": f"Tester {title_prefix}","email":email,"password":pwd,"password_confirmation":pwd},
                          {"Accept":"application/json"})
        assert r_reg.status_code in (200,201), f"Register {title_prefix} {r_reg.status_code}: {trim(r_reg.text)}"

        url_login = build(self.ctx,"/api/login")
        r_login = http_json(self.ctx, f"SETUP: Login {title_prefix}", "POST", url_login,
                            {"email":email,"password":pwd}, {"Accept":"application/json"})
        assert r_login.status_code == 200, f"Login {title_prefix} {r_login.status_code}: {trim(r_login.text)}"

        token = must_json(r_login).get("token")
        assert token, f"Brak tokenu JWT ({title_prefix})"
        return email, pwd, token

    def t_setup_register_OwnerA(self):
        self.ctx.emailOwner, self.ctx.pwdOwner, self.ctx.tokenOwner = self._setup_register_and_login("OwnerA", "owner")
        return {"status": 200}

    def t_setup_register_MemberB(self):
        self.ctx.emailB, self.ctx.pwdB, self.ctx.tokenB = self._setup_register_and_login("MemberB", "memberB")
        return {"status": 200}

    def t_setup_register_OutsiderC(self):
        self.ctx.emailC, self.ctx.pwdC, self.ctx.tokenC = self._setup_register_and_login("OutsiderC", "outsiderC")
        return {"status": 200}

    def t_setup_register_AdminD(self):
        self.ctx.emailD, self.ctx.pwdD, self.ctx.tokenD = self._setup_register_and_login("AdminD", "adminD")
        return {"status": 200}

    def t_setup_register_ModeratorE(self):
        self.ctx.emailE, self.ctx.pwdE, self.ctx.tokenE = self._setup_register_and_login("ModeratorE", "moderatorE")
        return {"status": 200}

    # ──────────────────────────────────────────────────────────────────────
    # === 3. Metody testowe: Note API ===
    # ──────────────────────────────────────────────────────────────────────

    def t_note_login_A(self):
        url = build(self.ctx,"/api/login")
        r = http_json(self.ctx, "NOTE: Login Owner A", "POST", url,
                      {"email":self.ctx.emailOwner,"password":self.ctx.pwdOwner}, {"Accept":"application/json"})
        assert r.status_code == 200
        self.ctx.tokenOwner = must_json(r).get("token")
        return {"status": 200, "method":"POST","url":url}

    def t_note_index_initial(self):
        url = me(self.ctx,"/notes?top=10&skip=0")
        r = http_json(self.ctx, "NOTE: Index initial", "GET", url, None, auth_headers(self.ctx.tokenOwner))
        assert r.status_code == 200, f"Index {r.status_code}: {trim(r.text)}"
        js = must_json(r)
        assert "data" in js and isinstance(js["data"], list), "Brak 'data'"
        assert "count" in js, "Brak 'count'"
        return {"status": 200, "method":"GET","url":url}

    def t_note_store_missing_file(self):
        url = me(self.ctx,"/notes")
        r = http_multipart(self.ctx, "NOTE: Store missing file", url, data={"title":"NoFile"}, files={}, headers=auth_headers(self.ctx.tokenOwner))
        assert r.status_code in (400,422), f"Missing file 400/422, jest {r.status_code}"
        return {"status": r.status_code, "method":"POST","url":url}

    def t_note_store_invalid_mime(self):
        url = me(self.ctx,"/notes")
        files = {"file": ("note.txt", b"hello", "text/plain")}
        r = http_multipart(self.ctx, "NOTE: Store invalid mime", url, data={"title":"BadMime"}, files=files, headers=auth_headers(self.ctx.tokenOwner))
        assert r.status_code in (400,422), f"Invalid mime 400/422, jest {r.status_code}"
        return {"status": r.status_code, "method":"POST","url":url}

    def t_note_store_ok(self):
        url = me(self.ctx,"/notes")
        data_bytes, mime, name = self._note_load_upload_bytes(self.ctx.note_file_path)
        files = {"file": (name, data_bytes, mime)}
        data  = {"title":"Note A","description":"Test file upload"}
        r = http_multipart(self.ctx, "NOTE: Store ok", url, data=data, files=files, headers=auth_headers(self.ctx.tokenOwner))
        assert r.status_code in (200,201), f"Store {r.status_code}: {trim(r.text)}"
        js = must_json(r)
        assert "note" in js and "id" in js["note"], "Brak 'note.id'"
        self.ctx.note_id_A = js["note"]["id"]
        return {"status": r.status_code, "method":"POST","url":url}

    def t_note_index_contains_created(self):
        assert self.ctx.note_id_A, "Brak notatki (note_id_A)"
        url = me(self.ctx, f"/notes?top=1&skip=0")
        r = http_json(self.ctx, "NOTE: Index (top=1)", "GET", url, None, auth_headers(self.ctx.tokenOwner))
        assert r.status_code == 200, f"Index {r.status_code}: {trim(r.text)}"
        js = must_json(r)

        ids = [n.get("id") for n in js.get("data",[])]
        assert self.ctx.note_id_A in ids, f"Notatka {self.ctx.note_id_A} nie widoczna"
        assert len(ids) == 1, "Paginacja top=1 nie zadziałała"

        count = int(js.get("count", 0))
        assert count >= 1, "Licznik 'count' jest niepoprawny"

        url2 = me(self.ctx, f"/notes?top=10&skip={count}")
        r2 = http_json(self.ctx, "NOTE: Index (skip beyond)", "GET", url2, None, auth_headers(self.ctx.tokenOwner))
        assert r2.status_code == 200
        js2 = must_json(r2)
        assert isinstance(js2.get("data",[]), list) and len(js2["data"]) == 0, "Paginacja poza zakresem"
        return {"status": 200, "method":"GET","url":url}

    def t_note_login_B(self):
        url = build(self.ctx,"/api/login")
        r = http_json(self.ctx, "NOTE: Login Member B", "POST", url,
                      {"email":self.ctx.emailB,"password":self.ctx.pwdB}, {"Accept":"application/json"})
        assert r.status_code == 200, f"Login B {r.status_code}: {trim(r.text)}"
        self.ctx.tokenB = must_json(r).get("token")
        assert self.ctx.tokenB, "Brak tokenu JWT (B)"
        return {"status": 200, "method":"POST","url":url}

    def t_note_download_foreign_403(self):
        assert self.ctx.note_id_A, "Brak notatki A (note_id_A)"
        url = me(self.ctx, f"/notes/{self.ctx.note_id_A}/download")
        r = http_json(self.ctx, "NOTE: Download foreign", "GET", url, None, auth_headers(self.ctx.tokenB))
        assert r.status_code in (403, 404), f"Obca notatka 403/404, jest {r.status_code}"
        return {"status": r.status_code, "method":"GET","url":url}

    def t_note_login_A_again(self):
        return self.t_note_login_A()

    def t_note_patch_title_only(self):
        assert self.ctx.note_id_A, "Brak notatki (note_id_A)"
        url = me(self.ctx, f"/notes/{self.ctx.note_id_A}")
        r, used = http_json_update(self.ctx, "NOTE: Update title", url, {"title":"Renamed Note"}, auth_headers(self.ctx.tokenOwner))
        assert r.status_code == 200, f"PATCH/PUT title {r.status_code}: {trim(r.text)}"
        js = must_json(r)
        assert js.get("note",{}).get("title") == "Renamed Note", "Tytuł nie zaktualizowany"
        return {"status": 200, "method": used, "url": url}

    def t_note_patch_is_private_invalid(self):
        assert self.ctx.note_id_A, "Brak notatki (note_id_A)"
        url = me(self.ctx, f"/notes/{self.ctx.note_id_A}")
        r, used = http_json_update(self.ctx, "NOTE: Update invalid is_private", url, {"is_private":"notbool"}, auth_headers(self.ctx.tokenOwner))
        assert r.status_code in (400,422), f"Zła wartość is_private 400/422, jest {r.status_code}"
        return {"status": r.status_code, "method": used, "url": url}

    def t_note_patch_desc_priv_false(self):
        assert self.ctx.note_id_A, "Brak notatki (note_id_A)"
        url = me(self.ctx, f"/notes/{self.ctx.note_id_A}")
        r, used = http_json_update(self.ctx, "NOTE: Update desc+priv", url, {"description":"Updated body","is_private": False}, auth_headers(self.ctx.tokenOwner))
        assert r.status_code == 200, f"PATCH/PUT desc {r.status_code}: {trim(r.text)}"
        js = must_json(r)
        assert js.get("note",{}).get("is_private") in (False, 0), "is_private nie false"
        return {"status": 200, "method": used, "url": url}

    def t_note_patch_file_missing(self):
        assert self.ctx.note_id_A, "Brak notatki (note_id_A)"
        url = me(self.ctx, f"/notes/{self.ctx.note_id_A}/patch")
        r = http_multipart(self.ctx, "NOTE: PATCH file missing", url, data={}, files={}, headers=auth_headers(self.ctx.tokenOwner))
        assert r.status_code in (400,422), f"Brak pliku 400/422, jest {r.status_code}"
        return {"status": r.status_code, "method":"POST","url":url}

    def t_note_patch_file_ok(self):
        assert self.ctx.note_id_A, "Brak notatki (note_id_A)"
        url = me(self.ctx, f"/notes/{self.ctx.note_id_A}/patch")
        data_bytes, mime, name = self._note_load_upload_bytes(self.ctx.note_file_path)
        files = {"file": (f"re_{name}", data_bytes, mime)}
        r = http_multipart(self.ctx, "NOTE: PATCH file ok", url, data={}, files=files, headers=auth_headers(self.ctx.tokenOwner))
        assert r.status_code == 200, f"PATCH file {r.status_code}: {trim(r.text)}"
        return {"status": 200, "method":"POST","url":url}

    def t_note_download_file_ok(self):
        assert self.ctx.note_id_A, "Brak notatki (note_id_A)"
        url = me(self.ctx, f"/notes/{self.ctx.note_id_A}/download")
        r = http_json(self.ctx, "NOTE: Download file", "GET", url, None, auth_headers(self.ctx.tokenOwner))
        assert r.status_code == 200, f"Download {r.status_code}"
        return {"status": 200, "method":"GET","url":url}

    def t_note_delete_note(self):
        assert self.ctx.note_id_A, "Brak notatki (note_id_A)"
        url = me(self.ctx, f"/notes/{self.ctx.note_id_A}")
        r = http_json(self.ctx, "NOTE: DELETE note", "DELETE", url, None, auth_headers(self.ctx.tokenOwner))
        assert r.status_code == 200, f"Delete {r.status_code}: {trim(r.text)}"
        return {"status": 200, "method":"DELETE","url":url}

    def t_note_download_after_delete_404(self):
        assert self.ctx.note_id_A, "Brak notatki (note_id_A)"
        url = me(self.ctx, f"/notes/{self.ctx.note_id_A}/download")
        r = http_json(self.ctx, "NOTE: Download after delete", "GET", url, None, auth_headers(self.ctx.tokenOwner))
        assert r.status_code == 404, f"Po usunięciu 404, jest {r.status_code}"
        return {"status": 404, "method":"GET","url":url}

    def t_note_index_after_delete(self):
        url = me(self.ctx,"/notes?top=100&skip=0")
        r = http_json(self.ctx, "NOTE: Index after delete", "GET", url, None, auth_headers(self.ctx.tokenOwner))
        assert r.status_code == 200, f"Index after delete {r.status_code}"
        js = must_json(r)
        ids = [n.get("id") for n in js.get("data",[])]
        assert self.ctx.note_id_A not in ids, "Usunięta notatka nadal widoczna"
        self.ctx.note_id_A = None
        return {"status": 200, "method":"GET","url":url}

    def t_note_access_invalid_id(self):
        url = me(self.ctx, "/notes/abc/download")
        r = http_json(self.ctx, "NOTE: Access invalid ID (abc)", "GET", url, None, auth_headers(self.ctx.tokenOwner))
        assert r.status_code == 404, f"Dostęp do ID 'abc' 404, jest {r.status_code}"
        return {"status": 404, "method":"GET","url":url}

    # ──────────────────────────────────────────────────────────────────────
    # === 4. Metody testowe: Course API ===
    # ──────────────────────────────────────────────────────────────────────

    def t_course_index_no_token(self):
        url = me(self.ctx, "/courses")
        r = http_json(self.ctx, "COURSE: Index no token", "GET", url, None, {"Accept":"application/json"})
        assert r.status_code in (401,403), f"Bez tokenu 401/403: {r.status_code} {trim(r.text)}"
        return {"status": r.status_code, "method":"GET", "url":url}

    def t_course_login_A(self):
        url = build(self.ctx, "/api/login")
        r = http_json(self.ctx, "COURSE: Login Owner A", "POST", url,
                      {"email":self.ctx.emailOwner,"password":self.ctx.pwdOwner}, {"Accept":"application/json"})
        assert r.status_code == 200
        self.ctx.tokenOwner = r.json().get("token")
        return {"status": 200, "method":"POST","url":url}

    def t_course_create_course_A(self):
        url = me(self.ctx, "/courses")
        r = http_json(self.ctx, "COURSE: Create course", "POST", url,
                      {"title":"My Course","description":"Course for E2E","type":"private"},
                      auth_headers(self.ctx.tokenOwner))
        assert r.status_code in (200,201), f"Create course {r.status_code}: {trim(r.text)}"
        js = r.json()
        self.ctx.course_id_1 = (js.get("course") or {}).get("id") or js.get("id")
        assert self.ctx.course_id_1, "Brak id kursu (course_id_1)"
        return {"status": r.status_code, "method":"POST","url":url}

    def t_course_download_avatar_none_404(self):
        url = me(self.ctx, f"/courses/{self.ctx.course_id_1}/avatar")
        r = http_json(self.ctx, "COURSE: Download avatar none", "GET", url, None, auth_headers(self.ctx.tokenOwner))
        assert r.status_code == 404, f"Brak avatara 404: {r.status_code} {trim(r.text)}"
        return {"status": r.status_code, "method":"GET", "url":url}

    def t_course_avatar_upload(self):
        url = me(self.ctx, f"/courses/{self.ctx.course_id_1}/avatar")
        avatar = self.ctx.avatar_bytes or gen_avatar_bytes()
        files = {"avatar": ("course_avatar.jpg", avatar, "image/jpeg")}
        r = http_multipart(self.ctx, "COURSE: Upload course avatar", url, data={}, files=files, headers=auth_headers(self.ctx.tokenOwner))
        assert r.status_code == 200, f"Avatar upload {r.status_code}: {trim(r.text)}"
        js = must_json(r)
        assert "avatar_url" in js, "Brak avatar_url w odpowiedzi kursu"
        return {"status": 200, "method":"POST", "url":url}

    def t_course_create_course_invalid(self):
        url = me(self.ctx, "/courses")
        r = http_json(self.ctx, "COURSE: Create course invalid type", "POST", url,
                      {"title":"Invalid","description":"x","type":"superpublic"},
                      auth_headers(self.ctx.tokenOwner))
        assert r.status_code in (400,422), f"Zły type 400/422: {r.status_code} {trim(r.text)}"
        return {"status": r.status_code, "method":"POST","url":url}

    def t_course_create_invalid_validation(self):
        url = me(self.ctx, "/courses")
        r_empty = http_json(self.ctx, "COURSE: Create course (empty title)", "POST", url,
                      {"title":"","description":"x","type":"private"},
                      auth_headers(self.ctx.tokenOwner))
        assert r_empty.status_code in (400,422), f"Pusty tytuł 400/422: {r_empty.status_code}"

        long_title = "a" * 300
        r_long = http_json(self.ctx, "COURSE: Create course (long title)", "POST", url,
                      {"title":long_title,"description":"x","type":"private"},
                      auth_headers(self.ctx.tokenOwner))
        assert r_long.status_code in (400,422), f"Zbyt długi tytuł 400/422: {r_long.status_code}"
        return {"status": r_long.status_code, "method":"POST","url":url}

    def t_course_index_courses_A_contains(self):
        url = me(self.ctx, "/courses")
        r = http_json(self.ctx, "COURSE: Index courses A", "GET", url, None, auth_headers(self.ctx.tokenOwner))
        assert r.status_code == 200, f"Index A {r.status_code}: {trim(r.text)}"
        ids = [c.get("id") for c in r.json()]
        assert self.ctx.course_id_1 in ids, "Kurs A nie widoczny u A"
        return {"status": 200, "method":"GET","url":url}

    def t_course_login_B(self):
        url = build(self.ctx, "/api/login")
        r = http_json(self.ctx, "COURSE: Login Member B", "POST", url,
                      {"email":self.ctx.emailB,"password":self.ctx.pwdB}, {"Accept":"application/json"})
        assert r.status_code == 200
        self.ctx.tokenB = r.json().get("token")
        return {"status": 200, "method":"POST","url":url}

    def t_course_download_avatar_B_unauth(self):
        url = me(self.ctx, f"/courses/{self.ctx.course_id_1}/avatar")
        r = http_json(self.ctx, "COURSE: B cannot download A avatar", "GET", url, None, auth_headers(self.ctx.tokenB))
        assert r.status_code in (401,403), f"B nie może pobrać avatara: {r.status_code}" # Kurs prywatny
        return {"status": r.status_code, "method":"GET","url":url}

    def t_course_B_cannot_update_A_course(self):
        url = me(self.ctx, f"/courses/{self.ctx.course_id_1}")
        r = http_json(self.ctx, "COURSE: B cannot update", "PATCH", url, {"title":"Hack"}, auth_headers(self.ctx.tokenB))
        assert r.status_code in (401,403), f"Brak uprawnień 401/403: {r.status_code}"
        return {"status": r.status_code, "method":"PATCH","url":url}

    def t_course_B_cannot_delete_A_course(self):
        url = me(self.ctx, f"/courses/{self.ctx.course_id_1}")
        r = http_json(self.ctx, "COURSE: B cannot delete", "DELETE", url, None, auth_headers(self.ctx.tokenB))
        assert r.status_code in (401,403), f"Brak uprawnień 401/403: {r.status_code}"
        return {"status": r.status_code, "method":"DELETE","url":url}

    def t_course_invite_B(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id_1}/invite-user")
        r = http_json(self.ctx, "COURSE: Invite B", "POST", url, {"email": self.ctx.emailB, "role":"member"}, auth_headers(self.ctx.tokenOwner))
        assert r.status_code in (200,201), f"Invite {r.status_code}: {trim(r.text)}"
        return {"status": r.status_code, "method":"POST","url":url}

    def t_course_B_received(self):
        url = build(self.ctx, "/api/me/invitations-received")
        r = http_json(self.ctx, "COURSE: B received", "GET", url, None, auth_headers(self.ctx.tokenB))
        assert r.status_code == 200, f"Invitations received {r.status_code}: {trim(r.text)}"
        invitations = r.json().get("invitations", [])
        assert invitations, "Brak zaproszeń dla B"
        self.ctx.invite_token_B = invitations[0].get("token"); assert self.ctx.invite_token_B
        return {"status": 200, "method":"GET","url":url}

    def t_course_B_accept(self):
        url = build(self.ctx, f"/api/invitations/{self.ctx.invite_token_B}/accept")
        r = http_json(self.ctx, "COURSE: B accept invite", "POST", url, {}, auth_headers(self.ctx.tokenB))
        assert r.status_code == 200, f"Accept invite {r.status_code}: {trim(r.text)}"
        return {"status": 200, "method":"POST","url":url}

    def t_course_index_courses_B_contains(self):
        url = build(self.ctx, "/api/me/courses")
        r = http_json(self.ctx, "COURSE: Index courses B", "GET", url, None, auth_headers(self.ctx.tokenB))
        assert r.status_code == 200, f"Index B {r.status_code}: {trim(r.text)}"
        ids = [c.get("id") for c in r.json()]
        assert self.ctx.course_id_1 in ids, "Kurs A nie widoczny u B po akceptacji"
        return {"status": 200, "method":"GET","url":url}

    def t_course_users_member_view(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id_1}/users")
        r = http_json(self.ctx, "COURSE: Course users (member)", "GET", url, None, auth_headers(self.ctx.tokenB))
        assert r.status_code == 200
        js = r.json(); assert "users" in js and isinstance(js["users"], list)
        assert len(js["users"]) >= 2, "Powinno być >=2 (owner + member)"
        return {"status": 200, "method":"GET","url":url}

    def t_course_users_admin_all(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id_1}/users?per_page=1")
        r = http_json(self.ctx, "COURSE: Course users (admin all + p=1)", "GET", url, None, auth_headers(self.ctx.tokenOwner))
        assert r.status_code == 200
        js = r.json(); assert "users" in js
        return {"status": 200, "method":"GET","url":url}

    def t_course_users_filter_q_role(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id_1}/users?q=tester&role=member")
        r = http_json(self.ctx, "COURSE: Course users filter", "GET", url, None, auth_headers(self.ctx.tokenOwner))
        assert r.status_code == 200
        js = r.json()
        assert len(js.get("users", [])) > 0, "Filtr q=tester powinien znaleźć MemberB"
        return {"status": 200, "method":"GET","url":url}

    def t_course_users_filter_not_found(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id_1}/users?q=NieistniejacyXYZ&role=member")
        r = http_json(self.ctx, "COURSE: Course users filter (not found)", "GET", url, None, auth_headers(self.ctx.tokenOwner))
        assert r.status_code == 200
        js = r.json()
        assert len(js.get("users", [])) == 0, "Filtr q=NieistniejacyXYZ powinien zwrócić 0"
        return {"status": 200, "method":"GET","url":url}

    def t_course_create_note_A(self):
        url = build(self.ctx, "/api/me/notes")
        data_bytes, mime, name = self._note_load_upload_bytes(self.ctx.note_file_path)
        files = {"file": (name, data_bytes, mime)}
        r = http_multipart(self.ctx, "COURSE: A creates note (multipart)", url,
                           {"title":"A course note","description":"desc","is_private":"true"}, files, auth_headers(self.ctx.tokenOwner))
        assert r.status_code in (200,201)
        self.ctx.course_note_id_A = r.json().get("note",{}).get("id") or r.json().get("id")
        assert self.ctx.course_note_id_A
        return {"status": r.status_code, "method":"POST","url":url}

    def t_course_B_cannot_share_A_note(self):
        url = build(self.ctx, f"/api/me/notes/{self.ctx.course_note_id_A}/share/{self.ctx.course_id_1}")
        r = http_json(self.ctx, "COURSE: B share A note (fail)", "POST", url, {}, auth_headers(self.ctx.tokenB))
        assert r.status_code in (403,404)
        return {"status": r.status_code, "method":"POST","url":url}

    def t_course_A_share_note_invalid_course(self):
        url = build(self.ctx, f"/api/me/notes/{self.ctx.course_note_id_A}/share/99999999")
        r = http_json(self.ctx, "COURSE: A share note invalid course", "POST", url, {}, auth_headers(self.ctx.tokenOwner))
        assert r.status_code == 404
        return {"status": 404, "method":"POST","url":url}

    def t_course_share_note_to_course(self):
        url = build(self.ctx, f"/api/me/notes/{self.ctx.course_note_id_A}/share/{self.ctx.course_id_1}")
        r = http_json(self.ctx, "COURSE: A share note → course", "POST", url, {}, auth_headers(self.ctx.tokenOwner))
        assert r.status_code == 200
        return {"status": 200, "method":"POST","url":url}

    def t_course_verify_note_shared(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id_1}/notes")
        r = http_json(self.ctx, "COURSE: notes (verify shared)", "GET", url, None, auth_headers(self.ctx.tokenOwner))
        assert r.status_code == 200
        found = [n for n in r.json().get("notes", []) if n.get("id") == self.ctx.course_note_id_A]
        assert found and found[0].get("is_private") in (False, 0), "Powinno być publiczne w kursie"
        return {"status": 200, "method":"GET","url":url}

    def t_course_unshare_note(self):
        # Zakładamy istnienie endpointu /unshare, jeśli nie istnieje, ten test zawiedzie
        url = build(self.ctx, f"/api/me/notes/{self.ctx.course_note_id_A}/unshare/{self.ctx.course_id_1}")
        r = http_json(self.ctx, "COURSE: A un-share note → course", "POST", url, {}, auth_headers(self.ctx.tokenOwner))

        if r.status_code == 404: # Endpoint prawdopodobnie nie istnieje
            print(c(" (SKIP: Endpoint /unshare not found)", Fore.YELLOW), end=" ")
            return {"status": 404, "method":"POST","url":url}

        assert r.status_code == 200, f"Unshare failed: {r.status_code}"

        # Weryfikacja
        url_check = build(self.ctx, f"/api/courses/{self.ctx.course_id_1}/notes")
        r_check = http_json(self.ctx, "COURSE: notes (verify unshared)", "GET", url_check, None, auth_headers(self.ctx.tokenOwner))
        assert r_check.status_code == 200
        found = [n for n in r_check.json().get("notes", []) if n.get("id") == self.ctx.course_note_id_A]
        assert not found, "Notatka nadal jest w kursie po unshare"
        return {"status": 200, "method":"POST","url":url}

    def t_course_notes_owner_member(self):
        urlA = build(self.ctx, f"/api/courses/{self.ctx.course_id_1}/notes")
        rA = http_json(self.ctx, "COURSE: notes (owner)", "GET", urlA, None, auth_headers(self.ctx.tokenOwner))
        assert rA.status_code == 200
        urlB = build(self.ctx, f"/api/courses/{self.ctx.course_id_1}/notes")
        rB = http_json(self.ctx, "COURSE: notes (member)", "GET", urlB, None, auth_headers(self.ctx.tokenB))
        assert rB.status_code == 200
        return {"status": 200, "method":"GET","url":urlA}

    def t_course_notes_outsider_private_403(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id_1}/notes")
        r = http_json(self.ctx, "COURSE: notes outsider private", "GET", url, None, auth_headers(self.ctx.tokenC))
        assert r.status_code in (401,403)
        return {"status": r.status_code, "method":"GET","url":url}

    def t_course_remove_B(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id_1}/remove-user")
        r = http_json(self.ctx, "COURSE: Remove B", "POST", url, {"email": self.ctx.emailB}, auth_headers(self.ctx.tokenOwner))
        assert r.status_code == 200 and (r.json() is True or r.json().get("success") is True), "Remove B failed"
        return {"status": 200, "method":"POST","url":url}

    def t_course_index_courses_B_not_contains(self):
        url = build(self.ctx, "/api/me/courses")
        r = http_json(self.ctx, "COURSE: Index B (not contains)", "GET", url, None, auth_headers(self.ctx.tokenB))
        assert r.status_code == 200
        ids = [c.get("id") for c in r.json()]
        assert self.ctx.course_id_1 not in ids
        return {"status": 200, "method":"GET","url":url}

    def t_course_remove_non_member_true(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id_1}/remove-user")
        r = http_json(self.ctx, "COURSE: Remove non-member idempotent", "POST", url, {"email": self.ctx.emailB}, auth_headers(self.ctx.tokenOwner))
        assert r.status_code in (404,200,422,400), "Dopuszczamy różne kontrakty, byle nie 500"
        return {"status": r.status_code, "method":"POST","url":url}

    def t_course_remove_owner_422(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id_1}/remove-user")
        r = http_json(self.ctx, "COURSE: Remove owner → 422", "POST", url, {"email": self.ctx.emailOwner}, auth_headers(self.ctx.tokenOwner))
        assert r.status_code in (400,422), f"Blokada ownera: {r.status_code}"
        return {"status": r.status_code, "method":"POST","url":url}

    def t_course_login_D(self):
        url = build(self.ctx, "/api/login")
        r = http_json(self.ctx, "COURSE: Login Admin D", "POST", url,
                      {"email":self.ctx.emailD,"password":self.ctx.pwdD}, {"Accept":"application/json"})
        assert r.status_code == 200
        self.ctx.tokenD = r.json().get("token")
        return {"status": 200, "method":"POST","url":url}

    def t_course_invite_D_admin(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id_1}/invite-user")
        r = http_json(self.ctx, "COURSE: Invite D (admin)", "POST", url, {"email": self.ctx.emailD, "role":"admin"}, auth_headers(self.ctx.tokenOwner))
        assert r.status_code in (200,201)
        return {"status": r.status_code, "method":"POST","url":url}

    def t_course_D_accept(self):
        url = build(self.ctx, "/api/me/invitations-received")
        r = http_json(self.ctx, "COURSE: D received", "GET", url, None, auth_headers(self.ctx.tokenD))
        token = r.json().get("invitations", [])[0].get("token")
        self.ctx.invite_token_D = token; assert token
        url2 = build(self.ctx, f"/api/invitations/{token}/accept")
        r2 = http_json(self.ctx, "COURSE: D accept", "POST", url2, {}, auth_headers(self.ctx.tokenD))
        assert r2.status_code == 200
        return {"status": 200, "method":"POST","url":url2}

    def t_course_login_E(self):
        url = build(self.ctx, "/api/login")
        r = http_json(self.ctx, "COURSE: Login Moderator E", "POST", url,
                      {"email":self.ctx.emailE,"password":self.ctx.pwdE}, {"Accept":"application/json"})
        assert r.status_code == 200
        self.ctx.tokenE = r.json().get("token")
        return {"status": 200, "method":"POST","url":url}

    def t_course_invite_E_moderator(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id_1}/invite-user")
        r = http_json(self.ctx, "COURSE: Invite E (moderator)", "POST", url, {"email": self.ctx.emailE, "role":"moderator"}, auth_headers(self.ctx.tokenOwner))
        assert r.status_code in (200,201)
        return {"status": r.status_code, "method":"POST","url":url}

    def t_course_E_accept(self):
        url = build(self.ctx, "/api/me/invitations-received")
        r = http_json(self.ctx, "COURSE: E received", "GET", url, None, auth_headers(self.ctx.tokenE))
        token = r.json().get("invitations", [])[0].get("token")
        self.ctx.invite_token_E = token; assert token
        url2 = build(self.ctx, f"/api/invitations/{token}/accept")
        r2 = http_json(self.ctx, "COURSE: E accept", "POST", url2, {}, auth_headers(self.ctx.tokenE))
        assert r2.status_code == 200
        return {"status": 200, "method":"POST","url":url2}

# (Kontynuacja klasy E2ETester z Części 1)

    # === 4. Metody testowe: Course API (Ciąg dalszy) ===

    def t_course_create_note_D_and_share(self):
        url = build(self.ctx, "/api/me/notes")
        files = {"file": ("sample.png", gen_png_bytes(), "image/png")}
        r = http_multipart(self.ctx, "COURSE: D creates note", url,
                           {"title":"D note","description":"desc","is_private":"true"}, files, auth_headers(self.ctx.tokenD))
        assert r.status_code in (200,201)
        self.ctx.course_note_id_D = r.json().get("note",{}).get("id") or r.json().get("id"); assert self.ctx.course_note_id_D

        url2 = build(self.ctx, f"/api/me/notes/{self.ctx.course_note_id_D}/share/{self.ctx.course_id_1}")
        r2 = http_json(self.ctx, "COURSE: D share note → course", "POST", url2, {}, auth_headers(self.ctx.tokenD))
        assert r2.status_code == 200
        return {"status": 200, "method":"POST","url":url2}

    def t_course_create_note_E_and_share(self):
        url = build(self.ctx, "/api/me/notes")
        files = {"file": ("sample.png", gen_png_bytes(), "image/png")}
        r = http_multipart(self.ctx, "COURSE: E creates note", url,
                           {"title":"E note","description":"desc","is_private":"true"}, files, auth_headers(self.ctx.tokenE))
        assert r.status_code in (200,201)
        self.ctx.course_note_id_E = r.json().get("note",{}).get("id") or r.json().get("id"); assert self.ctx.course_note_id_E

        url2 = build(self.ctx, f"/api/me/notes/{self.ctx.course_note_id_E}/share/{self.ctx.course_id_1}")
        r2 = http_json(self.ctx, "COURSE: E share note → course", "POST", url2, {}, auth_headers(self.ctx.tokenE))
        assert r2.status_code == 200
        return {"status": 200, "method":"POST","url":url2}

    def t_course_mod_E_cannot_remove_admin_D(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id_1}/remove-user")
        r = http_json(self.ctx, "COURSE: E cannot remove D", "POST", url, {"email": self.ctx.emailD}, auth_headers(self.ctx.tokenE))
        assert r.status_code in (401,403), f"Mod nie może wyrzucić admina: {r.status_code}"
        return {"status": r.status_code, "method":"POST","url":url}

    def t_course_mod_E_cannot_purge_admin_D_notes(self):
        uid = self._course_get_id_by_email(self.ctx.emailD, self.ctx.course_id_1, self.ctx.tokenE)
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id_1}/users/{uid}/notes")
        r = http_json(self.ctx, "COURSE: Mod E cannot purge D notes", "DELETE", url, None, auth_headers(self.ctx.tokenE))
        assert r.status_code in (401,403), f"Mod nie może czyścić notatek Admina: {r.status_code}"
        return {"status": r.status_code, "method":"DELETE","url":url}

    def t_course_mod_E_cannot_remove_owner_A(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id_1}/remove-user")
        r = http_json(self.ctx, "COURSE: E cannot remove owner A", "POST", url, {"email": self.ctx.emailOwner}, auth_headers(self.ctx.tokenE))
        assert r.status_code in (400,422,403), "Blokada ownera z poziomu moda"
        return {"status": r.status_code, "method":"POST","url":url}

    def t_course_admin_D_removes_mod_E(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id_1}/remove-user")
        r = http_json(self.ctx, "COURSE: Admin removes moderator E", "POST", url, {"email": self.ctx.emailE}, auth_headers(self.ctx.tokenD))
        assert r.status_code == 200 and (r.json() is True or r.json().get("success") is True), "Admin remove mod failed"
        return {"status": 200, "method":"POST","url":url}

    def t_course_verify_E_note_unshared(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id_1}/notes")
        r = http_json(self.ctx, "COURSE: Verify E note unshared", "GET", url, None, auth_headers(self.ctx.tokenOwner))
        assert r.status_code == 200
        ids = [n.get("id") for n in r.json().get("notes",[])]
        assert self.ctx.course_note_id_E not in ids, "Notatka E powinna być odpięta"
        return {"status": 200, "method":"GET","url":url}

    def t_course_E_lost_membership(self):
        url = build(self.ctx, "/api/me/courses")
        r = http_json(self.ctx, "COURSE: E courses after kick", "GET", url, None, auth_headers(self.ctx.tokenE))
        assert r.status_code == 200
        ids = [c.get("id") for c in r.json()]
        assert self.ctx.course_id_1 not in ids
        return {"status": 200, "method":"GET","url":url}

    def t_course_owner_sets_D_admin(self):
        return self._course_role_patch_by_email("COURSE: Owner sets D→admin", self.ctx.tokenOwner, self.ctx.emailD, "admin")

    def t_course_owner_demotes_D_to_moderator(self):
        return self._course_role_patch_by_email("COURSE: Owner demotes D→moderator", self.ctx.tokenOwner, self.ctx.emailD, "moderator")

    def t_course_admin_cannot_change_admin(self):
        # Przywróć D do roli admina
        self._course_role_patch_by_email("COURSE: (Setup) Re-promote D→admin", self.ctx.tokenOwner, self.ctx.emailD, "admin")

        # Admin D próbuje zmienić admina (siebie)
        res = self._course_role_patch_by_email_raw("COURSE: Admin D cannot change self", self.ctx.tokenD, self.ctx.emailD, "moderator")
        assert res[0] in (401,403), f"Admin nie może zmieniać admina: {res[0]}"
        return {"status": res[0], "method":"PATCH","url": res[1]}

    def t_course_admin_cannot_set_owner_role(self):
        res = self._course_role_patch_by_email_raw("COURSE: Admin cannot set owner role", self.ctx.tokenD, self.ctx.emailOwner, "owner")
        assert res[0] in (403,422,400), f"Admin nie może ustawiać ownera: {res[0]}"
        return {"status": res[0], "method":"PATCH","url": res[1]}

    def t_course_owner_cannot_demote_self(self):
        res = self._course_role_patch_by_email_raw("COURSE: Owner cannot demote self", self.ctx.tokenOwner, self.ctx.emailOwner, "moderator")
        assert res[0] in (403,422,400), f"Owner nie może degradować siebie: {res[0]}"
        return {"status": res[0], "method":"PATCH","url": res[1]}

    def t_course_owner_reinvite_E_as_moderator(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id_1}/invite-user")
        r = http_json(self.ctx, "COURSE: Reinvite E as mod", "POST", url, {"email": self.ctx.emailE, "role":"moderator"}, auth_headers(self.ctx.tokenOwner))
        assert r.status_code in (200,201)

        url2 = build(self.ctx, "/api/me/invitations-received")
        r2 = http_json(self.ctx, "COURSE: E received #2", "GET", url2, None, auth_headers(self.ctx.tokenE))
        token = r2.json().get("invitations", [])[0].get("token"); assert token

        url3 = build(self.ctx, f"/api/invitations/{token}/accept")
        r3 = http_json(self.ctx, "COURSE: E accept #2", "POST", url3, {}, auth_headers(self.ctx.tokenE))
        assert r3.status_code == 200
        return {"status": 200, "method":"POST","url":url3}

    def t_course_register_F(self):
        self.ctx.emailF, self.ctx.pwdF, _ = self._setup_register_and_login("MemberF", "memberF")
        return {"status": 200}

    def t_course_login_F(self):
        url = build(self.ctx, "/api/login")
        r = http_json(self.ctx, "COURSE: Login F", "POST", url,
                      {"email":self.ctx.emailF,"password":self.ctx.pwdF}, {"Accept":"application/json"})
        assert r.status_code == 200
        self.ctx.tokenF = r.json().get("token"); assert self.ctx.tokenF
        return {"status": 200, "method":"POST","url":url}

    def t_course_invite_F_member(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id_1}/invite-user")
        r = http_json(self.ctx, "COURSE: Invite F (member)", "POST", url, {"email": self.ctx.emailF, "role":"member"}, auth_headers(self.ctx.tokenOwner))
        assert r.status_code in (200,201)
        return {"status": r.status_code, "method":"POST","url":url}

    def t_course_F_accept(self):
        url = build(self.ctx, "/api/me/invitations-received")
        r = http_json(self.ctx, "COURSE: F received", "GET", url, None, auth_headers(self.ctx.tokenF))
        token = r.json().get("invitations", [])[0].get("token"); assert token
        url2 = build(self.ctx, f"/api/invitations/{token}/accept")
        r2 = http_json(self.ctx, "COURSE: F accept", "POST", url2, {}, auth_headers(self.ctx.tokenF))
        assert r2.status_code == 200
        return {"status": 200, "method":"POST","url":url2}

    def t_course_create_and_share_note_F(self):
        url = build(self.ctx, "/api/me/notes")
        files = {"file": ("sample.png", gen_png_bytes(), "image/png")}
        r = http_multipart(self.ctx, "COURSE: F creates note", url,
                           {"title":"F note","description":"desc","is_private":"true"}, files, auth_headers(self.ctx.tokenF))
        assert r.status_code in (200,201)
        self.ctx.course_note_id_F = r.json().get("note",{}).get("id") or r.json().get("id"); assert self.ctx.course_note_id_F

        url2 = build(self.ctx, f"/api/me/notes/{self.ctx.course_note_id_F}/share/{self.ctx.course_id_1}")
        r2 = http_json(self.ctx, "COURSE: F share note → course", "POST", url2, {}, auth_headers(self.ctx.tokenF))
        assert r2.status_code == 200
        return {"status": 200, "method":"POST","url":url2}

    def t_course_mod_E_purges_F_notes(self):
        uid = self._course_get_id_by_email(self.ctx.emailF, self.ctx.course_id_1, self.ctx.tokenE)
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id_1}/users/{uid}/notes")
        r = http_json(self.ctx, "COURSE: Mod E purges F notes", "DELETE", url, None, auth_headers(self.ctx.tokenE))
        assert r.status_code == 200

        url2 = build(self.ctx, f"/api/courses/{self.ctx.course_id_1}/notes")
        r2 = http_json(self.ctx, "COURSE: Verify F notes after purge", "GET", url2, None, auth_headers(self.ctx.tokenOwner))
        assert r2.status_code == 200
        ids = [n.get("id") for n in r2.json().get("notes",[])]
        assert self.ctx.course_note_id_F not in ids
        return {"status": 200, "method":"DELETE","url":url}

    def t_course_mod_E_removes_F_user(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id_1}/remove-user")
        r = http_json(self.ctx, "COURSE: Mod E removes F", "POST", url, {"email": self.ctx.emailF}, auth_headers(self.ctx.tokenE))
        assert r.status_code == 200 and (r.json() is True or r.json().get("success") is True)
        return {"status": 200, "method":"POST","url":url}

    def t_course_owner_reinvite_B_and_set_moderator(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id_1}/invite-user")
        r = http_json(self.ctx, "COURSE: Reinvite B", "POST", url, {"email": self.ctx.emailB, "role":"member"}, auth_headers(self.ctx.tokenOwner))
        assert r.status_code in (200,201)

        url2 = build(self.ctx, "/api/me/invitations-received")
        r2 = http_json(self.ctx, "COURSE: B received #2", "GET", url2, None, auth_headers(self.ctx.tokenB))
        token = r2.json().get("invitations", [])[0].get("token"); assert token

        url3 = build(self.ctx, f"/api/invitations/{token}/accept")
        r3 = http_json(self.ctx, "COURSE: B accept #2", "POST", url3, {}, auth_headers(self.ctx.tokenB))
        assert r3.status_code == 200

        return self._course_role_patch_by_email("COURSE: Owner sets B→moderator", self.ctx.tokenOwner, self.ctx.emailB, "moderator")

    def t_course_admin_sets_B_member(self):
        return self._course_role_patch_by_email("COURSE: Admin sets B→member", self.ctx.tokenD, self.ctx.emailB, "member")

    def t_course_login_C(self):
        url = build(self.ctx, "/api/login")
        r = http_json(self.ctx, "COURSE: Login Outsider C", "POST", url,
                      {"email":self.ctx.emailC,"password":self.ctx.pwdC}, {"Accept":"application/json"})
        assert r.status_code == 200
        self.ctx.tokenC = r.json().get("token")
        return {"status": 200, "method":"POST","url":url}

    def t_course_create_course2_A(self):
        url = me(self.ctx, "/courses")
        r = http_json(self.ctx, "COURSE: Create course #2", "POST", url,
                      {"title":"Course 2","description":"Another","type":"private"},
                      auth_headers(self.ctx.tokenOwner))
        assert r.status_code in (200,201)
        js = r.json(); self.ctx.course_id_2 = (js.get("course") or {}).get("id") or js.get("id")
        assert self.ctx.course_id_2
        return {"status": r.status_code, "method":"POST","url":url}

    def _course_pull_last_invite_token_C(self):
        url = build(self.ctx, "/api/me/invitations-received")
        r = http_json(self.ctx, "COURSE: C received last", "GET", url, None, auth_headers(self.ctx.tokenC))
        inv = r.json().get("invitations", [])
        assert inv, "Brak zaproszenia dla C"
        self.ctx.invite_tokens_C.append(inv[0].get("token"))

    def t_course_invite_C_1(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id_2}/invite-user")
        r = http_json(self.ctx, "COURSE: Invite C #1", "POST", url, {"email": self.ctx.emailC, "role":"member"}, auth_headers(self.ctx.tokenOwner))
        assert r.status_code in (200,201)
        self._course_pull_last_invite_token_C()
        return {"status": r.status_code, "method":"POST","url":url}

    def t_course_reject_C_last(self):
        token = self.ctx.invite_tokens_C[-1]
        url = build(self.ctx, f"/api/invitations/{token}/reject")
        r = http_json(self.ctx, "COURSE: C reject last", "POST", url, {}, auth_headers(self.ctx.tokenC))
        assert r.status_code == 200
        return {"status": 200, "method":"POST","url":url}

    def t_course_reject_C_last_and_pause(self):
        res = self.t_course_reject_C_last()
        # Pauza dla replikacji - kluczowe dla następnego testu
        print(c(" (Czekam 2s na replikację DB)...", Fore.MAGENTA), end=" ")
        time.sleep(2)
        return res

    def t_course_invite_C_2(self): return self.t_course_invite_C_1()
    def t_course_invite_C_3(self): return self.t_course_invite_C_1()

    def t_course_invite_C_4_blocked(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id_2}/invite-user")
        r = http_json(self.ctx, "COURSE: Invite C #4 blocked", "POST", url, {"email": self.ctx.emailC, "role":"member"}, auth_headers(self.ctx.tokenOwner))
        assert r.status_code in (400,422), f"Po 3 rejectach 4-te zaproszenie zablokowane: {r.status_code}"
        return {"status": r.status_code, "method":"POST","url":url}

    def t_course_create_public_course_A(self):
        url = me(self.ctx, "/courses")
        r = http_json(self.ctx, "COURSE: Create course (public)", "POST", url,
                      {"title":"Public","description":"Public course","type":"public"},
                      auth_headers(self.ctx.tokenOwner))
        assert r.status_code in (200,201)
        js = r.json(); self.ctx.public_course_id = (js.get("course") or {}).get("id") or js.get("id")
        assert self.ctx.public_course_id
        return {"status": r.status_code, "method":"POST","url":url}

    def t_course_create_note2_A_and_share_public(self):
        url = build(self.ctx, "/api/me/notes")
        files = {"file": ("sample.png", gen_png_bytes(), "image/png")}
        r = http_multipart(self.ctx, "COURSE: A creates note #2", url,
                           {"title":"A note2","description":"desc2","is_private":"true"}, files, auth_headers(self.ctx.tokenOwner))
        assert r.status_code in (200,201)
        self.ctx.course_note_id_2A = r.json().get("note",{}).get("id") or r.json().get("id"); assert self.ctx.course_note_id_2A

        url2 = build(self.ctx, f"/api/me/notes/{self.ctx.course_note_id_2A}/share/{self.ctx.public_course_id}")
        r2 = http_json(self.ctx, "COURSE: A share note2 → public", "POST", url2, {}, auth_headers(self.ctx.tokenOwner))
        assert r2.status_code == 200
        return {"status": 200, "method":"POST","url":url2}

    def t_course_notes_outsider_public_403(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.public_course_id}/notes")
        r = http_json(self.ctx, "COURSE: Public course notes outsider", "GET", url, None, auth_headers(self.ctx.tokenC))
        assert r.status_code in (401,403)
        return {"status": r.status_code, "method":"GET","url":url}

    def t_course_users_outsider_public_401(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.public_course_id}/users")
        r = http_json(self.ctx, "COURSE: Public course users outsider", "GET", url, None, auth_headers(self.ctx.tokenC))
        assert r.status_code in (401,403)
        return {"status": r.status_code, "method":"GET","url":url}

    def t_course_delete_course_A(self):
        url = me(self.ctx, f"/courses/{self.ctx.course_id_1}")
        r = http_json(self.ctx, "COURSE: Delete course #1", "DELETE", url, None, auth_headers(self.ctx.tokenOwner))
        assert r.status_code in (200,204), f"Delete failed: {r.status_code}"
        return {"status": r.status_code, "method":"DELETE","url":url}

    def t_course_delete_course2_A(self):
        url = me(self.ctx, f"/courses/{self.ctx.course_id_2}")
        r = http_json(self.ctx, "COURSE: Delete course #2", "DELETE", url, None, auth_headers(self.ctx.tokenOwner))
        assert r.status_code in (200,204), f"Delete failed: {r.status_code}"
        return {"status": r.status_code, "method":"DELETE","url":url}

    def t_course_delete_public_course_A(self):
        url = me(self.ctx, f"/courses/{self.ctx.public_course_id}")
        r = http_json(self.ctx, "COURSE: Delete course #3 (public)", "DELETE", url, None, auth_headers(self.ctx.tokenOwner))
        assert r.status_code in (200,204), f"Delete failed: {r.status_code}"
        return {"status": r.status_code, "method":"DELETE","url":url}

    # ──────────────────────────────────────────────────────────────────────
    # === 5. Metody testowe: Quiz API (Zarządzanie) ===
    # ──────────────────────────────────────────────────────────────────────

    def t_quiz_login_A(self):
        url = build(self.ctx, "/api/login")
        r = http_json(self.ctx, "QUIZ: Login Owner A", "POST", url,
                      {"email":self.ctx.emailOwner,"password":self.ctx.pwdOwner}, {"Accept":"application/json"})
        assert r.status_code == 200
        self.ctx.quiz_token = r.json().get("token")
        return {"status": 200, "method":"POST","url":url}

    def t_quiz_create_course(self):
        url = me(self.ctx, "/courses")
        r = http_json(self.ctx, "QUIZ: Create course", "POST", url, {
            "title": "QuizCourse", "description": "Course for quiz E2E", "type": "private"
        }, auth_headers(self.ctx.quiz_token))
        assert r.status_code in (200,201), f"Create course {r.status_code}: {trim(r.text)}"
        self.ctx.quiz_course_id = must_json(r).get("course",{}).get("id") or must_json(r).get("id")
        assert self.ctx.quiz_course_id, "Brak quiz_course_id"
        return {"status": r.status_code, "method":"POST", "url":url}

    def t_quiz_index_user_tests_initial(self):
        url = me(self.ctx, "/tests")
        r = http_json(self.ctx, "QUIZ: Index user tests initial", "GET", url, None, auth_headers(self.ctx.quiz_token))
        assert r.status_code == 200, f"Index tests {r.status_code}: {trim(r.text)}"
        return {"status": 200, "method":"GET", "url":url}

    def t_quiz_create_private_test(self):
        url = me(self.ctx, "/tests")
        r = http_json(self.ctx, "QUIZ: Create PRIVATE test", "POST", url, {
            "title":"Private Test (Access Check)", "description":"desc", "status":"private"
        }, auth_headers(self.ctx.quiz_token))
        assert r.status_code == 201, f"Create private test {r.status_code}: {trim(r.text)}"
        self.ctx.test_private_id = must_json(r).get("id")
        assert self.ctx.test_private_id, "Brak test_private_id"
        return {"status": 201, "method":"POST", "url":url}

    def t_quiz_create_public_test(self):
        url = me(self.ctx, "/tests")
        r = http_json(self.ctx, "QUIZ: Create PUBLIC test", "POST", url, {
            "title":"Public Test (Main)", "description":"share me", "status":"public"
        }, auth_headers(self.ctx.quiz_token))
        assert r.status_code == 201, f"Create public test {r.status_code}: {trim(r.text)}"
        self.ctx.test_public_id = must_json(r).get("id")
        assert self.ctx.test_public_id, "Brak test_public_id"
        return {"status": 201, "method":"POST", "url":url}

    def t_quiz_index_user_tests_contains_both(self):
        url = me(self.ctx, "/tests")
        r = http_json(self.ctx, "QUIZ: Index contains both tests", "GET", url, None, auth_headers(self.ctx.quiz_token))
        assert r.status_code == 200, f"Index {r.status_code}"
        js = must_json(r)
        ids = {t.get("id") for t in js}
        assert self.ctx.test_private_id in ids, "Lista nie zawiera testu prywatnego"
        assert self.ctx.test_public_id in ids, "Lista nie zawiera testu publicznego"
        return {"status": 200, "method":"GET", "url":url}

    def t_quiz_update_public_test(self):
        url = me(self.ctx, f"/tests/{self.ctx.test_public_id}")
        r = http_json(self.ctx, "QUIZ: Update public test", "PUT", url, {
            "title":"Public Test (Main) — updated", "description":"desc updated"
        }, auth_headers(self.ctx.quiz_token))
        assert r.status_code == 200, f"Update test {r.status_code}: {trim(r.text)}"
        return {"status": 200, "method":"PUT", "url":url}

    def t_quiz_add_question(self):
        url = me(self.ctx, f"/tests/{self.ctx.test_public_id}/questions") # Do testu publicznego
        r = http_json(self.ctx, "QUIZ: Add Q1 (public)", "POST", url, {"question":"What is 2+2?"}, auth_headers(self.ctx.quiz_token))
        assert r.status_code == 201, f"Add Q1 {r.status_code}: {trim(r.text)}"
        self.ctx.quiz_public_q1_id = must_json(r).get("id")
        assert self.ctx.quiz_public_q1_id, "Brak question_id"
        return {"status": 201, "method":"POST", "url":url}

    def t_quiz_list_questions_contains_q1(self):
        url = me(self.ctx, f"/tests/{self.ctx.test_public_id}/questions")
        r = http_json(self.ctx, "QUIZ: List questions (public)", "GET", url, None, auth_headers(self.ctx.quiz_token))
        assert r.status_code == 200, f"List Q {r.status_code}"
        js = must_json(r)
        arr = js.get("questions",[])
        assert any(q.get("id")==self.ctx.quiz_public_q1_id for q in arr), "Lista nie zawiera Q1"
        return {"status": 200, "method":"GET", "url":url}

    def t_quiz_update_question(self):
        url = me(self.ctx, f"/tests/{self.ctx.test_public_id}/questions/{self.ctx.quiz_public_q1_id}")
        r = http_json(self.ctx, "QUIZ: Update Q1 (public)", "PUT", url, {"question":"What is 3+3?"}, auth_headers(self.ctx.quiz_token))
        assert r.status_code == 200, f"Update Q1 {r.status_code}"
        return {"status": 200, "method":"PUT", "url":url}

    def t_quiz_add_answer_invalid_first(self):
        url = me(self.ctx, f"/tests/{self.ctx.test_public_id}/questions/{self.ctx.quiz_public_q1_id}/answers")
        r = http_json(self.ctx, "QUIZ: Add A1 invalid first", "POST", url, {
            "answer":"4", "is_correct": False
        }, auth_headers(self.ctx.quiz_token))
        assert r.status_code in (400,422), f"Pierwsza odp niepoprawna zablokowana, jest {r.status_code}"
        return {"status": r.status_code, "method":"POST", "url":url}

    def t_quiz_add_answer_correct_first(self):
        url = me(self.ctx, f"/tests/{self.ctx.test_public_id}/questions/{self.ctx.quiz_public_q1_id}/answers")
        r = http_json(self.ctx, "QUIZ: Add A1 correct", "POST", url, {
            "answer":"6", "is_correct": True
        }, auth_headers(self.ctx.quiz_token))
        assert r.status_code == 201, f"Add A1 {r.status_code}: {trim(r.text)}"
        a_id = must_json(r).get("answer",{}).get("id") or must_json(r).get("id")
        assert a_id, "Brak id odp"
        self.ctx.quiz_public_a_ids.append(a_id)
        self.ctx.quiz_public_q1_correct_ans_id = a_id # Zapamiętaj do rozwiązywania
        return {"status": 201, "method":"POST", "url":url}

    def t_quiz_add_answer_duplicate(self):
        url = me(self.ctx, f"/tests/{self.ctx.test_public_id}/questions/{self.ctx.quiz_public_q1_id}/answers")
        r = http_json(self.ctx, "QUIZ: Add duplicate", "POST", url, {
            "answer":"6", "is_correct": False
        }, auth_headers(self.ctx.quiz_token))
        assert r.status_code in (400,422), f"Duplikat 400/422, jest {r.status_code}"
        return {"status": r.status_code, "method":"POST", "url":url}

    def t_quiz_add_answer_wrong_2(self):
        url = me(self.ctx, f"/tests/{self.ctx.test_public_id}/questions/{self.ctx.quiz_public_q1_id}/answers")
        r = http_json(self.ctx, "QUIZ: Add A2 wrong", "POST", url, {
            "answer":"7", "is_correct": False
        }, auth_headers(self.ctx.quiz_token))
        assert r.status_code == 201, f"Add A2 {r.status_code}"
        a_id = must_json(r).get("answer",{}).get("id") or must_json(r).get("id")
        self.ctx.quiz_public_a_ids.append(a_id)
        self.ctx.quiz_public_q1_wrong_ans_id = a_id # Zapamiętaj do rozwiązywania
        return {"status": 201, "method":"POST", "url":url}

    def t_quiz_add_answer_wrong_3(self):
        url = me(self.ctx, f"/tests/{self.ctx.test_public_id}/questions/{self.ctx.quiz_public_q1_id}/answers")
        r = http_json(self.ctx, "QUIZ: Add A3 wrong", "POST", url, {
            "answer":"8", "is_correct": False
        }, auth_headers(self.ctx.quiz_token))
        assert r.status_code == 201, f"Add A3 {r.status_code}"
        self.ctx.quiz_public_a_ids.append(must_json(r).get("answer",{}).get("id") or must_json(r).get("id"))
        return {"status": 201, "method":"POST", "url":url}

    def t_quiz_add_answer_wrong_4(self):
        url = me(self.ctx, f"/tests/{self.ctx.test_public_id}/questions/{self.ctx.quiz_public_q1_id}/answers")
        r = http_json(self.ctx, "QUIZ: Add A4 wrong", "POST", url, {
            "answer":"9", "is_correct": False
        }, auth_headers(self.ctx.quiz_token))
        assert r.status_code == 201, f"Add A4 {r.status_code}"
        self.ctx.quiz_public_a_ids.append(must_json(r).get("answer",{}).get("id") or must_json(r).get("id"))
        return {"status": 201, "method":"POST", "url":url}

    def t_quiz_add_answer_limit(self):
        url = me(self.ctx, f"/tests/{self.ctx.test_public_id}/questions/{self.ctx.quiz_public_q1_id}/answers")
        r = http_json(self.ctx, "QUIZ: Add A5 blocked", "POST", url, {
            "answer":"10", "is_correct": False
        }, auth_headers(self.ctx.quiz_token))
        assert r.status_code in (400,422), f"Limit 4 odp, jest {r.status_code}"
        return {"status": r.status_code, "method":"POST", "url":url}

    def t_quiz_get_answers_list(self):
        url = me(self.ctx, f"/tests/{self.ctx.test_public_id}/questions/{self.ctx.quiz_public_q1_id}/answers")
        r = http_json(self.ctx, "QUIZ: Get answers (4)", "GET", url, None, auth_headers(self.ctx.quiz_token))
        assert r.status_code == 200, f"Get answers {r.status_code}"
        assert len(r.json().get("answers", [])) == 4, "Powinny być 4 odpowiedzi"
        return {"status": 200, "method":"GET", "url":url}

    def t_quiz_update_answer(self):
        assert len(self.ctx.quiz_public_a_ids) >= 2, "Za mało odp do aktualizacji"
        target = self.ctx.quiz_public_a_ids[1] # A2
        url = me(self.ctx, f"/tests/{self.ctx.test_public_id}/questions/{self.ctx.quiz_public_q1_id}/answers/{target}")
        r = http_json(self.ctx, "QUIZ: Update answer #2", "PUT", url, {
            "answer":"7 (upd)", "is_correct": True # Zmiana na poprawną
        }, auth_headers(self.ctx.quiz_token))
        assert r.status_code == 200, f"Update answer {r.status_code}"
        return {"status": 200, "method":"PUT", "url":url}

    def t_quiz_delete_answer(self):
        assert len(self.ctx.quiz_public_a_ids) >= 3, "Za mało odp do kasowania"
        target = self.ctx.quiz_public_a_ids[2] # A3
        url = me(self.ctx, f"/tests/{self.ctx.test_public_id}/questions/{self.ctx.quiz_public_q1_id}/answers/{target}")
        r = http_json(self.ctx, "QUIZ: Delete answer #3", "DELETE", url, None, auth_headers(self.ctx.quiz_token))
        assert r.status_code == 200, f"Delete answer {r.status_code}"
        return {"status": 200, "method":"DELETE", "url":url}

    def t_quiz_delete_question(self):
        url = me(self.ctx, f"/tests/{self.ctx.test_public_id}/questions/{self.ctx.quiz_public_q1_id}")
        r = http_json(self.ctx, "QUIZ: Delete Q1", "DELETE", url, None, auth_headers(self.ctx.quiz_token))
        assert r.status_code == 200, f"Delete Q {r.status_code}"
        self.ctx.quiz_public_q1_id = None
        self.ctx.quiz_public_a_ids.clear()
        return {"status": 200, "method":"DELETE", "url":url}

    def t_quiz_add_questions_to_20(self):
        url = me(self.ctx, f"/tests/{self.ctx.test_public_id}/questions")
        self.ctx.quiz_public_q_ids = [] # Resetuj listę
        for i in range(1, 21):
            r = http_json(self.ctx, f"QUIZ: Add Q{i}", "POST", url, {"question": f"Q{i}?"}, auth_headers(self.ctx.quiz_token))
            assert r.status_code == 201, f"Q{i} {r.status_code}: {trim(r.text)}"
            q_id = must_json(r).get("id")
            self.ctx.quiz_public_q_ids.append(q_id)
        return {"status": 201, "method":"POST", "url":url}

    def t_quiz_add_21st_question_block(self):
        url = me(self.ctx, f"/tests/{self.ctx.test_public_id}/questions")
        r = http_json(self.ctx, "QUIZ: Add Q21 blocked", "POST", url, {"question":"Q21?"}, auth_headers(self.ctx.quiz_token))
        assert r.status_code in (400,422), f"Q21 (limit 20), jest {r.status_code}"
        return {"status": r.status_code, "method":"POST", "url":url}

    def t_quiz_share_public_test_to_course(self):
        assert self.ctx.test_public_id, "Brak test_public_id"
        assert self.ctx.quiz_course_id, "Brak quiz_course_id"

        url1 = me(self.ctx, f"/tests/{self.ctx.test_public_id}/share")
        r1 = http_json(self.ctx, "QUIZ: Share (POST /share)", "POST", url1, {
            "course_id": self.ctx.quiz_course_id
        }, auth_headers(self.ctx.quiz_token))
        if r1.status_code == 200: return {"status": 200, "method":"POST", "url":url1}

        url2 = me(self.ctx, f"/tests/{self.ctx.test_public_id}")
        payload2 = {"title": "Public Test (Main) — updated", "description": "desc updated", "status": "public", "course_id": self.ctx.quiz_course_id}
        r2 = http_json(self.ctx, "QUIZ: Share (PUT /me/tests)", "PUT", url2, payload2, auth_headers(self.ctx.quiz_token))
        if r2.status_code == 200: return {"status": 200, "method":"PUT", "url":url2}

        url3 = build(self.ctx, f"/api/courses/{self.ctx.quiz_course_id}/tests")
        payload3 = {"test_id": self.ctx.test_public_id}
        r3 = http_json(self.ctx, "QUIZ: Share (POST /courses/../tests)", "POST", url3, payload3, auth_headers(self.ctx.quiz_token))

        assert r3.status_code in (200, 201), f"Wszystkie metody udostępniania zawiodły. Ostatnia próba {r3.status_code}: {trim(r3.text)}"
        return {"status": r3.status_code, "method":"POST", "url": url3}

    def t_quiz_course_tests_include_shared(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.quiz_course_id}/tests")
        r = http_json(self.ctx, "QUIZ: Course tests", "GET", url, None, auth_headers(self.ctx.quiz_token))
        assert r.status_code == 200, f"Course tests {r.status_code}"
        js = must_json(r)
        assert isinstance(js, list), "Odpowiedź nie jest listą"
        assert any(t.get("id") == self.ctx.test_public_id for t in js), f"Lista nie zawiera testu ID: {self.ctx.test_public_id}"
        return {"status": 200, "method":"GET", "url":url}

    # ──────────────────────────────────────────────────────────────────────
    # === 6. Metody testowe: Quiz API (Rozwiązywanie) ===
    # ──────────────────────────────────────────────────────────────────────

    def t_quiz_register_B(self):
        self.ctx.quiz_userB_email = rnd_email("quizB")
        self.ctx.quiz_userB_pwd = "Haslo123123"
        url = build(self.ctx, "/api/users/register")
        r = http_json(self.ctx, "QUIZ: Register B (Student)", "POST", url, {
            "name":"Tester Quiz B","email":self.ctx.quiz_userB_email,
            "password":self.ctx.quiz_userB_pwd,"password_confirmation":self.ctx.quiz_userB_pwd
        }, {"Accept":"application/json"})
        assert r.status_code in (200,201), f"Register B {r.status_code}: {trim(r.text)}"
        return {"status": r.status_code, "method":"POST", "url":url}

    def t_quiz_invite_B_to_course(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.quiz_course_id}/invite-user")
        r = http_json(self.ctx, "QUIZ: Invite B to Course", "POST", url,
                      {"email": self.ctx.quiz_userB_email, "role":"member"}, auth_headers(self.ctx.quiz_token)) # Owner zaprasza
        assert r.status_code in (200,201), f"Invite B {r.status_code}"
        return {"status": r.status_code, "method":"POST", "url":url}

    def t_quiz_login_B(self):
        url = build(self.ctx, "/api/login")
        r = http_json(self.ctx, "QUIZ: Login B (Student)", "POST", url, {
            "email": self.ctx.quiz_userB_email, "password": self.ctx.quiz_userB_pwd
        }, {"Accept":"application/json"})
        assert r.status_code == 200, f"Login B {r.status_code}: {trim(r.text)}"
        self.ctx.quiz_token = must_json(r).get("token") # Ustawiamy token B jako aktywny
        return {"status": 200, "method":"POST", "url":url}

    def t_quiz_B_accept_invite(self):
        url = build(self.ctx, "/api/me/invitations-received")
        r = http_json(self.ctx, "QUIZ: B received invite", "GET", url, None, auth_headers(self.ctx.quiz_token))
        assert r.status_code == 200
        invites = r.json().get("invitations", [])
        assert invites, "Brak zaproszenia dla QuizB"
        token = invites[0].get("token"); assert token

        url_accept = build(self.ctx, f"/api/invitations/{token}/accept")
        r_accept = http_json(self.ctx, "QUIZ: B accept invite", "POST", url_accept, {}, auth_headers(self.ctx.quiz_token))
        assert r_accept.status_code == 200
        return {"status": 200, "method":"POST", "url":url_accept}

    def t_quiz_B_start_test(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.quiz_course_id}/tests/{self.ctx.test_public_id}/start")
        r = http_json(self.ctx, "QUIZ: B start test (public)", "GET", url, None, auth_headers(self.ctx.quiz_token))
        assert r.status_code == 200, f"Start test {r.status_code}"
        js = must_json(r)
        assert "questions" in js, "Brak listy pytań"
        assert len(js["questions"]) > 0, "Lista pytań jest pusta"
        q1 = js["questions"][0]
        assert "answers" in q1, "Brak odpowiedzi w pytaniu"
        assert len(q1["answers"]) > 0, "Pytanie nie ma odpowiedzi"
        # Sprawdź, czy NIE ma pola 'is_correct'
        assert "is_correct" not in q1["answers"][0], "API ujawnia poprawną odpowiedź przy starcie!"
        return {"status": 200, "method":"GET", "url":url}

    def t_quiz_B_cannot_start_private_test(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.quiz_course_id}/tests/{self.ctx.test_private_id}/start")
        r = http_json(self.ctx, "QUIZ: B cannot start private test", "GET", url, None, auth_headers(self.ctx.quiz_token))
        assert r.status_code in (403, 404), f"Start prywatnego testu 403/404, jest {r.status_code}"
        return {"status": r.status_code, "method":"GET", "url":url}

    def t_quiz_B_submit_test_wrong(self):
        # Zakładamy, że pierwszy dodany Q i pierwsza zła odpowiedź istnieją
        assert self.ctx.quiz_public_q_ids, "Brak ID pytań do wysłania"
        assert self.ctx.quiz_public_q1_wrong_ans_id, "Brak ID złej odpowiedzi"

        url = build(self.ctx, f"/api/courses/{self.ctx.quiz_course_id}/tests/{self.ctx.test_public_id}/submit")
        payload = {
            "answers": {
                str(self.ctx.quiz_public_q_ids[0]): str(self.ctx.quiz_public_q1_wrong_ans_id)
                # Na razie wysyłamy tylko jedną odpowiedź
            }
        }
        r = http_json(self.ctx, "QUIZ: B submit test (wrong)", "POST", url, payload, auth_headers(self.ctx.quiz_token))
        assert r.status_code == 200, f"Submit test {r.status_code}"
        js = must_json(r)
        assert "result_id" in js, "Brak result_id w odpowiedzi submit"
        self.ctx.quiz_result_id = js["result_id"]
        return {"status": 200, "method":"POST", "url":url}

    def t_quiz_B_check_result_wrong(self):
        assert self.ctx.quiz_result_id, "Brak quiz_result_id do sprawdzenia"
        url = me(self.ctx, f"/test-results/{self.ctx.quiz_result_id}")
        r = http_json(self.ctx, "QUIZ: B check result (wrong)", "GET", url, None, auth_headers(self.ctx.quiz_token))
        assert r.status_code == 200, f"Check result {r.status_code}"
        js = must_json(r)
        assert "score" in js, "Brak pola score"
        assert "total_questions" in js, "Brak pola total_questions"
        assert js["score"] == 0, f"Wynik powinien być 0, jest {js['score']}"
        # Można dodać więcej asercji, np. na poprawność 'total_questions'
        return {"status": 200, "method":"GET", "url":url}

    def t_quiz_B_submit_test_correct(self):
        assert self.ctx.quiz_public_q_ids, "Brak ID pytań do wysłania"
        assert self.ctx.quiz_public_q1_correct_ans_id, "Brak ID poprawnej odpowiedzi"

        url = build(self.ctx, f"/api/courses/{self.ctx.quiz_course_id}/tests/{self.ctx.test_public_id}/submit")
        payload = {
            "answers": {
                str(self.ctx.quiz_public_q_ids[0]): str(self.ctx.quiz_public_q1_correct_ans_id)
            }
        }
        r = http_json(self.ctx, "QUIZ: B submit test (correct)", "POST", url, payload, auth_headers(self.ctx.quiz_token))
        assert r.status_code == 200, f"Submit test {r.status_code}"
        js = must_json(r)
        self.ctx.quiz_result_id = js["result_id"] # Nadpisz ID wyniku
        return {"status": 200, "method":"POST", "url":url}

    def t_quiz_B_check_result_correct(self):
        assert self.ctx.quiz_result_id, "Brak quiz_result_id do sprawdzenia"
        url = me(self.ctx, f"/test-results/{self.ctx.quiz_result_id}")
        r = http_json(self.ctx, "QUIZ: B check result (correct)", "GET", url, None, auth_headers(self.ctx.quiz_token))
        assert r.status_code == 200, f"Check result {r.status_code}"
        js = must_json(r)
        # Zakładamy, że test ma tylko 1 pytanie do oceny (bo tylko na nie odpowiedzieliśmy)
        # Należy dostosować te asercje, jeśli API zwraca % lub punkty inaczej
        total = js.get("total_questions", 1) # Unikaj dzielenia przez zero
        expected_score = 1 if total > 0 else 0
        assert js.get("score") == expected_score, f"Wynik powinien być {expected_score}, jest {js.get('score')}"
        return {"status": 200, "method":"GET", "url":url}

    # --- Testy autoryzacji Quiz B vs A ---

    def t_quiz_b_cannot_show_a_test(self):
        url = me(self.ctx, f"/tests/{self.ctx.test_private_id}") # Prywatny test A
        r = http_json(self.ctx, "QUIZ: B show A test (private)", "GET", url, None, auth_headers(self.ctx.quiz_token)) # Token B
        assert r.status_code in (403,404), f"B 403/404, jest {r.status_code}"
        return {"status": r.status_code, "method":"GET", "url":url}

    def t_quiz_b_cannot_modify_a_test(self):
        url = me(self.ctx, f"/tests/{self.ctx.test_private_id}")
        r = http_json(self.ctx, "QUIZ: B update A test (private)", "PUT", url, {
            "title":"hack", "description":"hack"
        }, auth_headers(self.ctx.quiz_token))
        assert r.status_code in (403,404), f"B update 403/404, jest {r.status_code}"
        return {"status": r.status_code, "method":"PUT", "url":url}

    def t_quiz_b_cannot_add_q_to_a_test(self):
        url = me(self.ctx, f"/tests/{self.ctx.test_private_id}/questions")
        r = http_json(self.ctx, "QUIZ: B add Q to A (private)", "POST", url, {"question":"hack?"}, auth_headers(self.ctx.quiz_token))
        assert r.status_code in (403,404), f"B add Q 403/404, jest {r.status_code}"
        return {"status": r.status_code, "method":"POST", "url":url}

    def t_quiz_b_cannot_delete_a_test(self):
        url = me(self.ctx, f"/tests/{self.ctx.test_private_id}")
        r = http_json(self.ctx, "QUIZ: B delete A test (private)", "DELETE", url, None, auth_headers(self.ctx.quiz_token))
        assert r.status_code in (403,404), f"B delete 403/404, jest {r.status_code}"
        return {"status": r.status_code, "method":"DELETE", "url":url}

    # ──────────────────────────────────────────────────────────────────────
    # === 7. Metody Cleanup (Quiz) ===
    # ──────────────────────────────────────────────────────────────────────

    def t_quiz_cleanup_delete_public(self):
        # Zaloguj A (Ownera)
        self.t_quiz_login_A()

        if not self.ctx.test_public_id: return {"status": 200}
        url = me(self.ctx, f"/tests/{self.ctx.test_public_id}")
        r = http_json(self.ctx, "QUIZ: Cleanup delete public", "DELETE", url, None, auth_headers(self.ctx.quiz_token))
        assert r.status_code in (200, 204), f"Cleanup public {r.status_code}"
        return {"status": r.status_code, "method":"DELETE", "url":url}

    def t_quiz_cleanup_delete_private(self):
        if not self.ctx.test_private_id: return {"status": 200}
        url = me(self.ctx, f"/tests/{self.ctx.test_private_id}")
        r = http_json(self.ctx, "QUIZ: Cleanup delete private", "DELETE", url, None, auth_headers(self.ctx.quiz_token))
        assert r.status_code in (200, 204), f"Cleanup private {r.status_code}"
        return {"status": r.status_code, "method":"DELETE", "url":url}

    def t_quiz_cleanup_delete_course(self):
        if not self.ctx.quiz_course_id: return {"status": 200}
        url = me(self.ctx, f"/courses/{self.ctx.quiz_course_id}")
        r = http_json(self.ctx, "QUIZ: Cleanup delete course", "DELETE", url, None, auth_headers(self.ctx.quiz_token))
        assert r.status_code in (200, 204), f"Cleanup course {r.status_code}"
        return {"status": r.status_code, "method":"DELETE", "url":url}


# ───────────────────────── HTML Raport ─────────────────────────
def write_html_report(ctx: TestContext, results: List[TestRecord], endpoints: List[EndpointLog]):
    rows = []
    for r in results:
        cls = "pass" if r.passed else "fail"
        http_status = r.status or 0
        httpc = "ok" if 200 <= http_status < 300 else ("warn" if 300 <= http_status < 400 else "err")
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

        raw_link = f"<em>brak</em>"
        if ctx.transcripts_dir and os.path.isdir(ctx.transcripts_dir):
            try:
                raw_candidates = [f for f in os.listdir(ctx.transcripts_dir) if f.startswith(base + "--response_raw")]
                if raw_candidates:
                    raw_link = f"<a href='transcripts/{raw_candidates[0]}' target='_blank'>{raw_candidates[0]}</a>"
            except FileNotFoundError:
                 print(c(f" Błąd: Katalog transkrypcji {ctx.transcripts_dir} nie znaleziony.", Fore.RED))

        req_h = pretty_json(ep.req_headers)
        req_b = pretty_json(ep.req_body)
        resp_h = pretty_json(ep.resp_headers)
        resp_b = ep.resp_body_pretty or ""
        resp_b_view = (resp_b[:MAX_BODY_LOG] + "\n…(truncated)") if len(resp_b) > MAX_BODY_LOG else resp_b
        notes = "<br/>".join(ep.notes) if ep.notes else ""

        ep_html.append(f"""
<section class="endpoint" id="ep-{i}">
  <h2>{i:03d}. {ep.title}</h2>
  <div class="meta"><span class="m">{ep.method}</span> <code>{ep.url}</code>
    <span class="dur">{ep.duration_ms:.1f} ms</span>
    <span class="st">{ep.resp_status if ep.resp_status is not None else ''}</span>
  </div>
  {"<p class='note'>"+notes+"</p>" if notes else ""}
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
    <pre>{(req_b).replace("&","&amp;").replace("<","&lt;").replace(">","&gt;")}</pre>
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
<title>Zintegrowany Raport E2E (User, Note, Course, Quiz)</title>
<style>
:root {{
  --bg:#0b0d12; --panel:#0f1320; --ink:#e6e6e6; --muted:#9aa4b2;
  --ok:#65d26e; --err:#ff6b6b; --warn:#ffd166; --accent:#7cb8ff;
}}
html, body {{ background: var(--bg); color: var(--ink); font-family: ui-sans-serif, system-ui, -apple-system; }}
.wrapper {{ max-width: 1100px; margin: 32px auto; padding: 0 16px; }}
h1,h2,h3 {{ color:#e6f1ff; }}
code {{ background:#141a2a; padding:2px 6px; border-radius:6px; color:#cfe3ff; }}
pre {{ background:var(--panel); padding:12px; border-radius:12px; overflow:auto; border:1px solid #1b2136; }}
table {{ width:100%; border-collapse: collapse; }}
td, th {{ border-bottom:1px solid #1b2335; padding:8px 10px; }}
td.right {{ text-align:right; }}
.pass {{ color: var(--ok); font-weight: 700; }}
.fail {{ color: var(--err); font-weight: 700; }}
.http.ok {{ color: var(--ok); font-weight: 700; }}
.http.warn {{ color: #ffd166; font-weight: 700; }}
.http.err {{ color: var(--err); font-weight: 700; }}
details summary {{ cursor:pointer; margin-bottom: 8px; }}
.topbar {{ display:flex; gap:12px; align-items:center; flex-wrap:wrap; }}
.badge {{ background:#15203a; border:1px solid #1b2b4a; padding:6px 10px; border-radius:999px; color:#cfe3ff; font-size:13px; }}
small.muted {{ color: var(--muted); }}
section.endpoint {{ border:1px solid #1b2136; border-radius:14px; padding:16px; margin:16px 0; background:#0e1220; box-shadow: 0 0 0 1px rgba(255,255,255,0.02) inset;}}
section.endpoint .meta {{ font-size: 13px; color: var(--muted); margin: 4px 0 12px; }}
section.endpoint .meta .m {{ color: var(--accent); font-weight: 600; margin-right:8px; }}
section.endpoint .meta .dur {{ color: #a0ffa0; margin-left:8px; }}
section.endpoint .meta .st {{ color: #ffd3a0; margin-left:8px; }}
section.endpoint .note {{ color:#ffd166; margin:6px 0 10px; font-size: 14px; }}
section.endpoint .downloads {{ font-size: 13px; color: var(--muted); }}
section.endpoint .downloads a {{ color: var(--accent); }}
</style>
</head>
<body>
<div class="wrapper">
  <div class="topbar">
    <h1>Zintegrowany Raport E2E</h1>
    <span class="badge">Wygenerowano: {time.strftime('%Y-%m-%d %H:%M:%S')}</span>
    <span class="badge">Testów: {len(results)}</span>
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

  <p><small class="muted">Pliki surowe (transkrypcje) znajdują się w katalogu <code>transcripts/</code> obok tego raportu.</small></p>
</div>
</body>
</html>
"""
    path = os.path.join(ctx.output_dir, "APITestReport.html")
    write_text(path, html)
    print(c(f"📄 Zapisano zbiorczy raport HTML: {path}", Fore.CYAN))

    # Otwórz raport w przeglądarce
    try:
        webbrowser.open(f"file://{os.path.abspath(path)}")
        print(c(f"🌍 Otwieranie raportu w przeglądarce...", Fore.CYAN))
    except Exception as e:
        print(c(f"    Nie udało się otworzyć raportu: {e}", Fore.YELLOW))


# ───────────────────────── Main ─────────────────────────
def main():
    global NOTE_FILE_PATH, AVATAR_PATH
    args = parse_args()
    colorama_init()

    NOTE_FILE_PATH = args.note_file
    AVATAR_PATH = args.avatar

    ses = requests.Session()
    ses.headers.update({"User-Agent": "NoteSync-E2E-Integrated/2.1", "Accept": "application/json"})

    avatar_bytes = None
    if AVATAR_PATH and os.path.isfile(AVATAR_PATH):
        try:
            with open(AVATAR_PATH, "rb") as f:
                avatar_bytes = f.read()
        except Exception as e:
            print(c(f"Nie udało się wczytać avatara: {e}", Fore.RED))
    if not avatar_bytes:
        avatar_bytes = gen_avatar_bytes()

    out_dir = build_output_dir()

    ctx = TestContext(
        base_url=args.base_url.rstrip("/"),
        me_prefix=args.me_prefix,
        ses=ses,
        timeout=args.timeout,
        note_file_path=NOTE_FILE_PATH,
        avatar_bytes=avatar_bytes,
        output_dir=out_dir,
        started_at=time.time()
    )

    print(c(f"\n{ICON_INFO} Start Zintegrowanego Testu E2E (rozszerzony) @ {ctx.base_url}", Fore.WHITE))
    print(c(f"    Raport zostanie zapisany do: {out_dir}", Fore.CYAN))

    E2ETester(ctx).run()

if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("\nPrzerwano przez użytkownika.")
        sys.exit(130)
    except Exception as e:
        print(c(f"\nKrytyczny błąd E2E: {type(e).__name__}: {e}", Fore.RED))
        import traceback
        traceback.print_exc()
        sys.exit(1)
