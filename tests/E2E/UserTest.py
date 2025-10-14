#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
UserTest.py — E2E test sekcji User (Laravel + JWT), czysty output konsolowy:
- tylko progres PASS/FAIL w trakcie
- na końcu jedna tabelka (bez kolumn 'Bajty' i 'Uwagi')

HTML: pełne szczegóły każdego endpointu (nagłówki + ciała + odpowiedzi)

Raport HTML zapisuje się do:
  ./tests/results/User/ResultE2E--YYYY-MM-DD--HH-MM-SS/APITestReport.html
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
ICON_LOCK = "🔒"
ICON_USER = "👤"
ICON_PATCH= "🩹"
ICON_IMG  = "🖼️"
ICON_TRASH= "🗑️"
ICON_EXIT = "🚪"
ICON_CLOCK= "⏱️"
BOX = "─" * 84

MAX_BODY_LOG = 8000  # limit logowanego body (HTML)

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
def default_avatar_path() -> str:
    # domyślnie ./tests/sample_data/test.jpg (względem katalogu uruchomienia)
    return os.path.join(os.getcwd(), "tests", "sample_data", "test.jpg")

def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(description="NoteSync User API E2E")
    p.add_argument("--base-url", required=True, help="np. http://localhost:8000")
    p.add_argument("--me-prefix", default="me", help="prefiks dla /api/<prefix> (domyślnie 'me')")
    p.add_argument("--timeout", type=int, default=20, help="timeout żądań w sekundach")
    p.add_argument("--avatar", default=default_avatar_path(), help="ścieżka do pliku avatara (domyślnie ./tests/sample_data/test.jpg)")
    # flaga zachowana dla kompatybilności — raport i tak zapisujemy do ./tests/results/User/...
    p.add_argument("--html-report", action="store_true", help="generuj raport HTML (domyślnie do ./tests/results/User/...)")
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
    token: Optional[str] = None
    userA: Tuple[str, str] = ("","")  # (email, pass)
    userB: Tuple[str, str] = ("","")
    started_at: float = field(default_factory=time.time)
    timeout: int = 20
    avatar_bytes: Optional[bytes] = None
    endpoints: List[EndpointLog] = field(default_factory=list)
    output_dir: str = ""  # gdzie zapiszemy raport

# ───────────────────────── Helpers ─────────────────────────
def build(ctx: TestContext, path: str) -> str:
    return f"{ctx.base_url.rstrip('/')}{path}"

def me(ctx: TestContext, path: str) -> str:
    return f"{ctx.base_url.rstrip('/')}/api/{ctx.me_prefix.strip('/')}{path}"

def auth_headers(ctx: TestContext) -> Dict[str, str]:
    h = {"Accept": "application/json"}
    if ctx.token:
        h["Authorization"] = f"Bearer {ctx.token}"
    return h

def rnd_email() -> str:
    token = "".join(random.choices(string.ascii_lowercase + string.digits, k=8))
    return f"tester.{token}@example.com"

def must_json(resp: requests.Response) -> Dict[str, Any]:
    try:
        return resp.json()
    except Exception:
        raise AssertionError(f"Odpowiedź nie-JSON (CT={resp.headers.get('Content-Type')}): {trim(resp.text)}")

def gen_avatar_bytes() -> bytes:
    from PIL import Image, ImageDraw  # type: ignore
    img = Image.new("RGBA", (220, 220), (24, 28, 40, 255))
    d = ImageDraw.Draw(img)
    d.ellipse((20, 20, 200, 200), fill=(70, 160, 255, 255))
    d.rectangle((98, 140, 122, 195), fill=(255, 255, 255, 255))
    buf = io.BytesIO()
    img.save(buf, format="PNG")
    return buf.getvalue()

def security_header_notes(resp: requests.Response) -> List[str]:
    wanted = ["X-Content-Type-Options","X-Frame-Options","Referrer-Policy",
              "Content-Security-Policy","X-XSS-Protection","Strict-Transport-Security"]
    miss = [k for k in wanted if k not in resp.headers]
    return [f"Missing security headers: {', '.join(miss)}"] if miss else []

# centralne logowanie — wyłącznie do HTML (konsola pozostaje minimalistyczna)
def log_exchange(ctx: TestContext, el: EndpointLog, resp: Optional[requests.Response]):
    if resp is not None:
        ct = resp.headers.get("Content-Type","")
        if "application/json" in ct.lower():
            try:
                b = mask_json_sensitive(resp.json())
                resp_body = pretty_json(b)
            except Exception:
                resp_body = as_text(resp.content)
        elif "image" in ct.lower():
            resp_body = f"<binary image> bytes={len(resp.content)} content-type={ct}"
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
    time_str = time.strftime("%H-%M-%S")  # bez dwukropków
    folder = f"ResultE2E--{date_str}--{time_str}"
    out_dir = os.path.join(root, "tests", "results", "User", folder)
    os.makedirs(out_dir, exist_ok=True)
    return out_dir

# ───────────────────────── Runner ─────────────────────────
class ApiTester:
    def __init__(self, ctx: TestContext):
        self.ctx = ctx
        self.results: List[TestRecord] = []

    def run(self):
        steps: List[Tuple[str, Callable[[], Dict[str, Any]]]] = [
            (f"{ICON_USER} Rejestracja (A)", self.t_register_A),
            (f"{ICON_LOCK} Login (A)", self.t_login_A),
            (f"{ICON_LOCK} Profil bez autoryzacji", self.t_profile_unauth),
            (f"{ICON_LOCK} Profil z autoryzacją", self.t_profile_auth),
            (f"{ICON_USER} Rejestracja (B) do konfliktu email", self.t_register_B),
            (f"{ICON_PATCH} PATCH name (JSON)", self.t_patch_name_json),
            (f"{ICON_PATCH} PATCH email — konflikt (JSON)", self.t_patch_email_conflict_json),
            (f"{ICON_PATCH} PATCH email — poprawny (JSON)", self.t_patch_email_ok_json),
            (f"{ICON_PATCH} PATCH password (JSON) + weryfikacja", self.t_patch_password_json),
            (f"{ICON_IMG} Avatar — brak pliku", self.t_avatar_missing),
            (f"{ICON_IMG} Avatar — upload", self.t_avatar_upload),
            (f"{ICON_IMG} Avatar — download", self.t_avatar_download),
            (f"{ICON_EXIT} Logout", self.t_logout),
            (f"{ICON_LOCK} Re-login (A) przed DELETE", self.t_relogin_A),
            (f"{ICON_TRASH} DELETE profile (A)", self.t_delete_profile),
            (f"{ICON_LOCK} Login po DELETE (A) powinien się nie udać", self.t_login_after_delete_should_fail),
        ]
        total = len(steps)
        for idx, (name, fn) in enumerate(steps, 1):
            self._exec(idx, total, name, fn)
        self._summary()

    def _exec(self, idx: int, total: int, name: str, fn: Callable[[], Dict[str, Any]]):
        start = time.time()
        rec = TestRecord(name=name, passed=False, duration_ms=0)
        # Progress header
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
            # ret może nie istnieć, więc zabezpieczenie:
            rec.status = ret.get("status") if 'ret' in locals() else None
            print(c("FAIL", Fore.RED), c(f"— {e}", Fore.RED))
        except Exception as e:
            rec.error = f"{type(e).__name__}: {e}"
            print(c("ERROR", Fore.RED), c(f"— {e}", Fore.RED))
        rec.duration_ms = (time.time() - start) * 1000.0
        self.results.append(rec)

    # ───────────────────────── Testy ─────────────────────────
    def t_register_A(self):
        email = rnd_email()
        pwd   = "Haslo123123"
        url = build(self.ctx, "/api/users/register")
        r = http_json(self.ctx, "Register A", "POST", url,
                      {"name":"Tester A","email":email,"password":pwd,"password_confirmation":pwd},
                      {"Accept":"application/json"})
        assert r.status_code in (200,201), f"Register A {r.status_code}: {trim(r.text)}"
        self.ctx.userA = (email, pwd)
        return {"status": r.status_code, "method":"POST", "url":url}

    def t_login_A(self):
        email, pwd = self.ctx.userA
        url = build(self.ctx,"/api/login")
        r = http_json(self.ctx, "Login A", "POST", url, {"email":email,"password":pwd}, {"Accept":"application/json"})
        assert r.status_code == 200, f"Login A {r.status_code}: {trim(r.text)}"
        self.ctx.token = must_json(r).get("token")
        assert self.ctx.token, "Brak tokenu JWT"
        return {"status": 200, "method":"POST","url":url}

    def t_profile_unauth(self):
        url = me(self.ctx,"/profile")
        r = http_json(self.ctx, "Profile (unauth)", "GET", url, None, {"Accept":"application/json"})
        assert r.status_code in (401,403), f"Expected 401/403, got {r.status_code}"
        return {"status": r.status_code, "method":"GET", "url":url}

    def t_profile_auth(self):
        url = me(self.ctx,"/profile")
        r = http_json(self.ctx, "Profile (auth)", "GET", url, None, auth_headers(self.ctx))
        assert r.status_code == 200, f"Profile {r.status_code}: {trim(r.text)}"
        js = must_json(r)
        assert "user" in js and "email" in js["user"], "Brak user/email w odpowiedzi"
        return {"status": 200, "method":"GET", "url":url}

    def t_register_B(self):
        email = rnd_email()
        pwd   = "Haslo123123"
        url = build(self.ctx,"/api/users/register")
        r = http_json(self.ctx, "Register B", "POST", url,
                      {"name":"Tester B","email":email,"password":pwd,"password_confirmation":pwd},
                      {"Accept":"application/json"})
        assert r.status_code in (200,201), f"Register B {r.status_code}: {trim(r.text)}"
        self.ctx.userB = (email, pwd)
        return {"status": r.status_code, "method":"POST", "url":url}

    def t_patch_name_json(self):
        url = me(self.ctx,"/profile")
        r = http_json(self.ctx, "PATCH name", "PATCH", url,
                      {"name":"Tester Renamed"}, auth_headers(self.ctx))
        assert r.status_code == 200, f"PATCH name {r.status_code}: {trim(r.text)}"
        js = must_json(r)
        assert js.get("user",{}).get("name") == "Tester Renamed", "Imię nie zostało zaktualizowane"
        return {"status": 200, "method":"PATCH", "url":url}

    def t_patch_email_conflict_json(self):
        url = me(self.ctx,"/profile")
        conflict_email = self.ctx.userB[0]
        r = http_json(self.ctx, "PATCH email conflict", "PATCH", url,
                      {"email": conflict_email}, auth_headers(self.ctx))
        assert r.status_code in (400,422), f"Spodziewano 400/422 przy konflikcie, jest {r.status_code}: {trim(r.text)}"
        js = must_json(r)
        assert "error" in js, "Brak 'error' z walidacją"
        return {"status": r.status_code, "method":"PATCH", "url":url}

    def t_patch_email_ok_json(self):
        url = me(self.ctx,"/profile")
        new_mail = f"ok.{random.randint(1000,9999)}@example.com"
        r = http_json(self.ctx, "PATCH email ok", "PATCH", url,
                      {"email": new_mail}, auth_headers(self.ctx))
        assert r.status_code == 200, f"PATCH email {r.status_code}: {trim(r.text)}"
        js = must_json(r)
        assert js.get("user",{}).get("email") == new_mail, "E-mail nie został zaktualizowany"
        self.ctx.userA = (new_mail, self.ctx.userA[1])
        return {"status": 200, "method":"PATCH", "url":url}

    def t_patch_password_json(self):
        email, old_pwd = self.ctx.userA
        new_pwd = "Haslo123123X"
        url = me(self.ctx,"/profile")
        r = http_json(self.ctx, "PATCH password", "PATCH", url,
                      {"password": new_pwd, "password_confirmation": new_pwd}, auth_headers(self.ctx))
        assert r.status_code == 200, f"PATCH password {r.status_code}: {trim(r.text)}"
        # stare hasło nie działa
        url_login = build(self.ctx,"/api/login")
        r_bad = http_json(self.ctx, "Login old password (should fail)", "POST", url_login,
                          {"email": email, "password": old_pwd}, {"Accept":"application/json"})
        assert r_bad.status_code == 401, f"Stare hasło nie powinno działać, jest {r_bad.status_code}"
        # nowe działa
        r_ok = http_json(self.ctx, "Login new password", "POST", url_login,
                         {"email": email, "password": new_pwd}, {"Accept":"application/json"})
        assert r_ok.status_code == 200, f"Login nowym hasłem: {r_ok.status_code}"
        self.ctx.token = must_json(r_ok).get("token")
        assert self.ctx.token, "Brak tokenu po zmianie hasła"
        self.ctx.userA = (email, new_pwd)
        return {"status": 200, "method":"PATCH", "url":url}

    def t_avatar_missing(self):
        url = me(self.ctx,"/profile/avatar")
        r = http_multipart(self.ctx, "Avatar missing", url, data={}, files={}, headers=auth_headers(self.ctx))
        assert r.status_code in (400,422), f"Brak pliku avatar powinien dać 400/422, jest {r.status_code}"
        return {"status": r.status_code, "method":"POST", "url":url}

    def t_avatar_upload(self):
        url = me(self.ctx,"/profile/avatar")
        # preferuj plik z ./tests/sample_data/test.jpg
        if self.ctx.avatar_bytes:
            avatar = self.ctx.avatar_bytes
        elif PIL_AVAILABLE:
            avatar = gen_avatar_bytes()
        else:
            avatar = b"\x89PNG\r\n\x1a\n"  # fallback
        files = {"avatar": ("test.jpg", avatar, "image/jpeg")}
        r = http_multipart(self.ctx, "Avatar upload", url, data={}, files=files, headers=auth_headers(self.ctx))
        assert r.status_code == 200, f"Avatar upload {r.status_code}: {trim(r.text)}"
        js = must_json(r)
        assert "avatar_url" in js, "Brak avatar_url"
        return {"status": 200, "method":"POST", "url":url}

    def t_avatar_download(self):
        url = me(self.ctx,"/profile/avatar")
        r = http_json(self.ctx, "Avatar download", "GET", url, None, auth_headers(self.ctx))
        assert r.status_code == 200, f"Avatar download {r.status_code}"
        ct = r.headers.get("Content-Type","")
        assert "image" in ct.lower(), f"Content-Type nie wygląda na obraz: {ct}"
        return {"status": 200, "method":"GET", "url":url}

    def t_logout(self):
        url = me(self.ctx,"/logout")
        r = http_json(self.ctx, "Logout", "POST", url, None, auth_headers(self.ctx))
        assert r.status_code == 200, f"Logout {r.status_code}"
        # Po logout próba GET profilu → 401/403
        url_profile = me(self.ctx,"/profile")
        r2 = http_json(self.ctx, "Profile after logout", "GET", url_profile, None, auth_headers(self.ctx))
        assert r2.status_code in (401,403), f"Po logout spodziewano 401/403, jest {r2.status_code}"
        return {"status": 200, "method":"POST", "url":url}

    def t_relogin_A(self):
        email, pwd = self.ctx.userA
        url = build(self.ctx,"/api/login")
        r = http_json(self.ctx, "Relogin A", "POST", url, {"email":email,"password":pwd}, {"Accept":"application/json"})
        assert r.status_code == 200, f"Re-login {r.status_code}: {trim(r.text)}"
        self.ctx.token = must_json(r).get("token")
        return {"status": 200, "method":"POST", "url":url}

    def t_delete_profile(self):
        url = me(self.ctx,"/profile")
        r = http_json(self.ctx, "DELETE profile", "DELETE", url, None, auth_headers(self.ctx))
        assert r.status_code == 200, f"DELETE profile {r.status_code}: {trim(r.text)}"
        return {"status": 200, "method":"DELETE", "url":url}

    def t_login_after_delete_should_fail(self):
        email, pwd = self.ctx.userA
        url = build(self.ctx,"/api/login")
        r = http_json(self.ctx, "Login after delete (should fail)", "POST", url, {"email":email,"password":pwd}, {"Accept":"application/json"})
        assert r.status_code == 401, f"Login po DELETE powinien dać 401, jest {r.status_code}"
        return {"status": r.status_code, "method":"POST", "url":url}

    # ───────────────────────── Podsumowanie ─────────────────────────
    def _summary(self):
        ok = [r for r in self.results if r.passed]
        fail = [r for r in self.results if not r.passed]

        print("\n" + BOX)
        print(c(f"{ICON_CLOCK} PODSUMOWANIE", Fore.WHITE))
        print(BOX)

        # Kolory tylko w kolumnach 'Wynik' i 'HTTP'
        def http_color(s: Optional[int]) -> str:
            if s is None: return ""
            if 200 <= s < 300: return c(str(s), Fore.GREEN)
            if 400 <= s < 500: return c(str(s), Fore.YELLOW)
            return c(str(s), Fore.RED)

        rows = []
        for r in self.results:
            outcome = c("PASS", Fore.GREEN) if r.passed else c("FAIL", Fore.RED)
            rows.append([
                r.name,                          # Test
                outcome,                         # Wynik
                f"{r.duration_ms:.1f} ms",       # Czas
                r.method or "",                  # Metoda
                r.url or "",                     # URL
                http_color(r.status),            # HTTP
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

        write_html_report(self.ctx, self.results)

# ───────────────────────── HTML Raport ─────────────────────────
def write_html_report(ctx: TestContext, results: List[TestRecord]):
    # buduj katalog docelowy ./tests/results/User/ResultE2E--DATE--TIME
    out_dir = ctx.output_dir or build_output_dir()
    ctx.output_dir = out_dir
    path = os.path.join(out_dir, "APITestReport.html")

    # Endpointy (szczegóły)
    ep_html = []
    for i, ep in enumerate(ctx.endpoints, 1):
        req_h = pretty_json(ep.req_headers)
        if ep.req_is_json:
            req_b = pretty_json(mask_json_sensitive(ep.req_body))
        else:
            req_b = pretty_json(ep.req_body)
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

    # Wyniki (tabela)
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
<title>NoteSync — User API Test Report</title>
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
<h1>NoteSync — User API Test</h1>

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
    args = parse_args()
    colorama_init()

    ses = requests.Session()
    ses.headers.update({"User-Agent": "NoteSync-UserTest/1.3", "Accept": "application/json"})

    # Wczytaj avatar z ./tests/sample_data/test.jpg (domyślnie)
    avatar_bytes = None
    if args.avatar and os.path.isfile(args.avatar):
        with open(args.avatar, "rb") as f:
            avatar_bytes = f.read()
    elif PIL_AVAILABLE:
        avatar_bytes = gen_avatar_bytes()
    else:
        avatar_bytes = b"\x89PNG\r\n\x1a\n"  # mini PNG jako awaryjny fallback

    ctx = TestContext(
        base_url=args.base_url.rstrip("/"),
        me_prefix=args.me_prefix,
        ses=ses,
        timeout=args.timeout,
        avatar_bytes=avatar_bytes,
        output_dir=build_output_dir(),  # ustaw katalog docelowy z datą/godziną
    )

    print(c(f"\n{ICON_INFO} Start User API tests @ {ctx.base_url}\n", Fore.WHITE))

    ApiTester(ctx).run()

if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("\nPrzerwano.")
        sys.exit(130)
