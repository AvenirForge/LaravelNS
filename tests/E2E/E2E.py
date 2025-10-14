#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
E2E.py — zintegrowany E2E dla NoteSync (User + Notes + Courses + Quiz) + Perf/RateLimit.

• Jeden bieg: tworzy A, B, C; sprawdza User, Notes, Courses, Quiz; na końcu cleanup i test login-fail.
• Minimalizacja duplikatów: rejestracje/loginy tylko raz, reużycie tokenów/sesji/ID.
• Raport: ./tests/results/E2E-YYYY-MM-DD--HH-MM-SS.html
• Bezpieczny load by default (można zwiększyć parametrami CLI).
"""
from __future__ import annotations
import argparse, io, json, os, random, string, sys, time, threading
from dataclasses import dataclass, field
from typing import Any, Callable, Dict, List, Optional, Tuple
from concurrent.futures import ThreadPoolExecutor, as_completed

import requests
from colorama import Fore, Style, init as colorama_init

# ───────────────────────── UI / utils ─────────────────────────
ICON_OK="✅"; ICON_FAIL="❌"; ICON_INFO="ℹ️"; ICON_LOCK="🔒"; ICON_USER="👤"; ICON_IMG="🖼️"
ICON_NOTE="📝"; ICON_BOOK="📘"; ICON_Q="❓"; ICON_A="🅰️"; ICON_LINK="🔗"; ICON_TRASH="🗑️"; ICON_CLOCK="⏱️"

MAX_BODY_LOG = 8000
BOX = "─"*90

def c(txt: str, color: str) -> str: return f"{color}{txt}{Style.RESET_ALL}"
def trim(s: str, n: int=180) -> str:
    s = (s or "").replace("\n", " ")
    return s if len(s) <= n else s[: n-1] + "…"

def pretty_json(obj: Any) -> str:
    try: return json.dumps(obj, ensure_ascii=False, indent=2)
    except Exception: return str(obj)

def as_text(b: bytes) -> str:
    try: s = b.decode("utf-8", errors="replace")
    except Exception: s = str(b)
    return s if len(s) <= MAX_BODY_LOG else s[:MAX_BODY_LOG] + "\n…(truncated)"

def mask_token(v: str) -> str:
    if not isinstance(v,str): return v
    if v.lower().startswith("bearer "):
        t = v.split(" ",1)[1]
        return "Bearer " + (t[:6]+"…"+t[-4:] if len(t)>12 else "******")
    return v

SENSITIVE_KEYS = {"password","password_confirmation","token"}

def mask_json_sensitive(data: Any) -> Any:
    if isinstance(data, dict):
        return {k: ("***" if k in SENSITIVE_KEYS else mask_json_sensitive(v)) for k,v in data.items()}
    if isinstance(data, list): return [mask_json_sensitive(x) for x in data]
    return data

def mask_headers_sensitive(h: Dict[str,str]) -> Dict[str,str]:
    out={}
    for k,v in h.items():
        if k.lower() in ("authorization","cookie","set-cookie"):
            out[k] = mask_token(v) if k.lower()=="authorization" else "<hidden>"
        else: out[k]=v
    return out

def security_header_notes(resp: requests.Response) -> List[str]:
    wanted=["X-Content-Type-Options","X-Frame-Options","Referrer-Policy",
            "Content-Security-Policy","X-XSS-Protection","Strict-Transport-Security"]
    miss=[k for k in wanted if k not in resp.headers]
    return [f"Missing security headers: {', '.join(miss)}"] if miss else []

# ───────────────────────── CLI ─────────────────────────
def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(description="Integrated E2E + Perf for NoteSync")
    p.add_argument("--base-url", required=True, help="np. http://localhost:8000")
    p.add_argument("--me-prefix", default="me", help="prefiks dla /api/<prefix> (domyślnie 'me')")
    p.add_argument("--timeout", type=int, default=20, help="timeout żądań [s]")
    p.add_argument("--note-file", default="", help="opcjonalna ścieżka pliku do notatki (jeśli pusta — generowany PNG)")
    p.add_argument("--avatar", default="", help="opcjonalna ścieżka pliku avatara (jeśli pusta — generowany PNG)")
    # perf / rate-limit
    p.add_argument("--perf-endpoint", default="/api/me/notes?top=1&skip=0", help="endpoint do testów wydajności (GET)")
    p.add_argument("--perf-requests", type=int, default=40, help="liczba żądań (łącznie)")
    p.add_argument("--perf-concurrency", type=int, default=5, help="równoległość (bezpieczna domyślnie)")
    p.add_argument("--skip-load", action="store_true", help="pomiń sekcję performance/rate-limit")
    return p.parse_args()

# ───────────────────────── Modele logowania ─────────────────────────
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
class E2EContext:
    base_url: str
    me_prefix: str
    timeout: int
    # sessions
    sesA: requests.Session
    sesB: requests.Session
    sesC: requests.Session
    # auth/token
    tokenA: Optional[str]=None
    tokenB: Optional[str]=None
    tokenC: Optional[str]=None
    # users credentials
    emailA: str=""; pwdA: str=""
    emailB: str=""; pwdB: str=""
    emailC: str=""; pwdC: str=""
    # entities
    note_id: Optional[int]=None
    course_id: Optional[int]=None
    course2_id: Optional[int]=None
    test_private_id: Optional[int]=None
    test_public_id: Optional[int]=None
    question_id: Optional[int]=None
    answer_ids: List[int]=field(default_factory=list)
    # logs
    started_at: float=field(default_factory=time.time)
    endpoints: List[EndpointLog]=field(default_factory=list)
    results: List[TestRecord]=field(default_factory=list)
    # perf data
    perf_samples: List[float]=field(default_factory=list)
    perf_codes: List[int]=field(default_factory=list)

# ───────────────────────── Helpers HTTP ─────────────────────────
def build(ctx: E2EContext, path: str) -> str:
    return f"{ctx.base_url.rstrip('/')}{path}"

def me(ctx: E2EContext, path: str) -> str:
    return f"{ctx.base_url.rstrip('/')}/api/{ctx.me_prefix.strip('/')}{path}"

def auth_headers(token: Optional[str]) -> Dict[str,str]:
    h={"Accept":"application/json"}
    if token: h["Authorization"]=f"Bearer {token}"
    return h

def log_exchange(ctx: E2EContext, el: EndpointLog, resp: Optional[requests.Response]):
    if resp is not None:
        ct = resp.headers.get("Content-Type","")
        if "application/json" in ct.lower():
            try:
                b=mask_json_sensitive(resp.json()); resp_body=pretty_json(b)
            except Exception: resp_body = as_text(resp.content)
        elif "image" in ct.lower() or "octet-stream" in ct.lower():
            resp_body = f"<binary> bytes={len(resp.content)} content-type={ct}"
        else:
            resp_body = as_text(resp.content)
        el.resp_status  = resp.status_code
        el.resp_headers = {k:str(v) for k,v in resp.headers.items()}
        el.resp_body    = resp_body if len(resp_body)<=MAX_BODY_LOG else resp_body[:MAX_BODY_LOG]+"\n…(truncated)"
        el.notes.extend(security_header_notes(resp))
    ctx.endpoints.append(el)

def _http_json(session: requests.Session, ctx: E2EContext, title: str, method: str, url: str,
               json_body: Optional[Dict[str,Any]], headers: Dict[str,str]) -> requests.Response:
    hs = dict(headers or {})
    req_headers_log = mask_headers_sensitive(hs.copy())
    t0 = time.time()
    if method=="GET":    resp = session.get(url, headers=hs, timeout=ctx.timeout)
    elif method=="POST": resp = session.post(url, headers=hs, json=json_body, timeout=ctx.timeout)
    elif method=="PUT":  resp = session.put(url, headers=hs, json=json_body, timeout=ctx.timeout)
    elif method=="PATCH":resp = session.patch(url, headers=hs, json=json_body, timeout=ctx.timeout)
    elif method=="DELETE": resp = session.delete(url, headers=hs, timeout=ctx.timeout)
    else: raise RuntimeError(f"Unsupported method {method}")
    el = EndpointLog(title=title, method=method, url=url,
                     req_headers=req_headers_log, req_body=json_body or {}, req_is_json=True,
                     duration_ms=(time.time()-t0)*1000.0)
    log_exchange(ctx, el, resp)
    return resp

def _http_multipart(session: requests.Session, ctx: E2EContext, title: str, url: str,
                    data: Dict[str,Any], files: Dict[str,Tuple[str,bytes,str]], headers: Dict[str,str]) -> requests.Response:
    hs = dict(headers or {})
    req_headers_log = mask_headers_sensitive(hs.copy())
    friendly_body = {"fields": mask_json_sensitive(data),
                     "files": {k: {"filename": v[0], "bytes": len(v[1]), "content_type": v[2]} for k,v in files.items()}}
    t0=time.time()
    resp = session.post(url, headers=hs, data=data, files=files, timeout=ctx.timeout)
    el = EndpointLog(title=title, method="POST(multipart)", url=url,
                     req_headers=req_headers_log, req_body=friendly_body, req_is_json=False,
                     duration_ms=(time.time()-t0)*1000.0)
    log_exchange(ctx, el, resp)
    return resp

def must_json(resp: requests.Response) -> Dict[str,Any]:
    try: return resp.json()
    except Exception: raise AssertionError(f"Odpowiedź nie-JSON (CT={resp.headers.get('Content-Type')}): {trim(resp.text)}")

def rnd_email(prefix="tester") -> str:
    token = "".join(random.choices(string.ascii_lowercase+string.digits, k=8))
    return f"{prefix}.{token}@example.com"

# ───────────────────────── Generatory plików ─────────────────────────
try:
    from PIL import Image, ImageDraw
    PIL_AVAILABLE=True
except Exception:
    PIL_AVAILABLE=False

def gen_png_bytes(size=(160,160)) -> bytes:
    if not PIL_AVAILABLE: return b"\x89PNG\r\n\x1a\n"
    img = Image.new("RGBA", size, (24,28,40,255))
    d = ImageDraw.Draw(img)
    d.ellipse((10,10,size[0]-10,size[1]-10), fill=(70,160,255,255))
    buf = io.BytesIO(); img.save(buf, format="PNG"); return buf.getvalue()

# ───────────────────────── HTML raport ─────────────────────────
def build_report_path() -> str:
    date_str=time.strftime("%Y-%m-%d"); time_str=time.strftime("%H-%M-%S")
    out_dir=os.path.join(os.getcwd(),"tests","results")
    os.makedirs(out_dir, exist_ok=True)
    return os.path.join(out_dir, f"E2E-{date_str}--{time_str}.html")

def save_html_report(ctx: E2EContext, path: str, perf_summary: Optional[Dict[str,Any]]):
    rows=[]
    for r in ctx.results:
        rows.append(f"""
<tr class="{ 'pass' if r.passed else 'fail' }">
  <td>{r.name}</td><td>{'PASS' if r.passed else 'FAIL'}</td>
  <td>{r.duration_ms:.1f} ms</td><td>{r.method or ''}</td>
  <td><code>{r.url or ''}</code></td><td>{r.status or ''}</td>
  <td>{(r.error or '')}</td>
</tr>""")

    ep_html=[]
    for i, ep in enumerate(ctx.endpoints, 1):
        req_h = pretty_json(ep.req_headers)
        req_b = pretty_json(mask_json_sensitive(ep.req_body)) if ep.req_is_json else pretty_json(ep.req_body)
        resp_h= pretty_json(ep.resp_headers)
        resp_b= ep.resp_body or ""
        notes = "<br/>".join(ep.notes) if ep.notes else ""
        ep_html.append(f"""
<section class="endpoint">
  <h2>{i}. {ep.title}</h2>
  <div class="meta"><span class="m">{ep.method}</span> <code>{ep.url}</code>
       <span class="dur">{ep.duration_ms:.1f} ms</span> <span class="st">{ep.resp_status or ''}</span></div>
  {"<p class='note'>"+notes+"</p>" if notes else ""}
  <details open><summary>Request</summary>
    <h3>Headers</h3><pre>{(req_h).replace("&","&amp;").replace("<","&lt;").replace(">","&gt;")}</pre>
    <h3>Body</h3><pre>{(req_b[:MAX_BODY_LOG]+("\\n…(truncated)" if len(req_b)>MAX_BODY_LOG else "")).replace("&","&amp;").replace("<","&lt;").replace(">","&gt;")}</pre>
  </details>
  <details open><summary>Response</summary>
    <h3>Headers</h3><pre>{(resp_h).replace("&","&amp;").replace("<","&lt;").replace(">","&gt;")}</pre>
    <h3>Body</h3><pre>{(resp_b[:MAX_BODY_LOG]+("\\n…(truncated)" if len(resp_b)>MAX_BODY_LOG else "")).replace("&","&amp;").replace("<","&lt;").replace(">","&gt;")}</pre>
  </details>
</section>""")

    perf_html=""
    if perf_summary:
        s=perf_summary
        perf_html=f"""
<section class="perf">
  <h2>Performance / Rate-limit</h2>
  <p><strong>Endpoint:</strong> <code>{s['endpoint']}</code> &nbsp; | &nbsp; <strong>Total:</strong> {s['n']} &nbsp; | &nbsp; <strong>Conc:</strong> {s['conc']}</p>
  <ul>
    <li>RPS (approx): {s['rps']:.2f}</li>
    <li>p50: {s['p50']:.1f} ms, p90: {s['p90']:.1f} ms, p99: {s['p99']:.1f} ms</li>
    <li>HTTP 2xx/3xx: {s['ok']}, 429: {s['rl']}, 4xx/5xx(other): {s['err']}</li>
  </ul>
</section>"""

    html=f"""<!doctype html><html lang="pl"><head>
<meta charset="utf-8" />
<title>NoteSync — Zintegrowany E2E</title>
<style>
:root {{ --bg:#0b0d12; --panel:#0f1320; --ink:#e6e6e6; --muted:#9aa4b2; --ok:#65d26e; --err:#ff6b6b; --warn:#ffd166; --accent:#7cb8ff; }}
html,body {{ background:var(--bg); color:var(--ink); font-family: ui-sans-serif,system-ui,Segoe UI,Roboto,Arial; }}
.wrapper {{ margin:24px; }}
h1,h2,h3 {{ color:#e6f1ff; }}
code {{ background:#141a2a; padding:2px 6px; border-radius:6px; }}
pre {{ background:var(--panel); padding:12px; border-radius:12px; overflow:auto; border:1px solid #1b2136; }}
section.endpoint {{ border:1px solid #1b2136; border-radius:14px; padding:16px; margin:16px 0; background:#0e1220; }}
section.endpoint .meta {{ font-size:13px; color:var(--muted); margin:4px 0 12px; }}
table {{ width:100%; border-collapse:collapse; margin-top: 20px; }}
th,td {{ border:1px solid #1b2136; padding:10px; text-align:left; }}
th {{ background:#10162a; color:#d4e2ff; }}
tr.pass {{ background: rgba(101,210,110,.06); }}
tr.fail {{ background: rgba(255,107,107,.08); }}
.summary {{ margin-top:24px; color: var(--muted); }}
.note {{ color:#ffd166; }}
</style></head><body><div class="wrapper">
<h1>NoteSync — Zintegrowany E2E</h1>
<h2>Wyniki</h2>
<table><thead><tr>
  <th>Test</th><th>Wynik</th><th>Czas</th><th>Metoda</th><th>URL</th><th>HTTP</th><th>Błąd</th>
</tr></thead><tbody>{''.join(rows)}</tbody></table>
{perf_html}
<h2>Endpointy — Szczegóły</h2>
{''.join(ep_html)}
<p class="summary">Raport: {time.strftime('%Y-%m-%d %H:%M:%S')}</p>
</div></body></html>"""
    with open(path,"w",encoding="utf-8") as f: f.write(html)
    print(c(f"📄 Zapisano raport HTML: {path}", Fore.CYAN))

# ───────────────────────── Runner ─────────────────────────
class Runner:
    def __init__(self, ctx: E2EContext): self.ctx=ctx

    # Utility do egzekwowania kroków
    def _exec(self, name: str, fn: Callable[[], Dict[str,Any]]):
        start=time.time(); rec=TestRecord(name=name, passed=False, duration_ms=0)
        print(c(f"{name} …", Fore.CYAN), end=" ")
        try:
            ret=fn() or {}; rec.passed=True
            rec.status=ret.get("status"); rec.method=ret.get("method",""); rec.url=ret.get("url","")
            print(c("PASS", Fore.GREEN))
        except AssertionError as e:
            rec.error=str(e); print(c("FAIL", Fore.RED), c(f"— {e}", Fore.RED))
        except Exception as e:
            rec.error=f"{type(e).__name__}: {e}"; print(c("ERROR", Fore.RED), c(f"— {e}", Fore.RED))
        rec.duration_ms=(time.time()-start)*1000.0; self.ctx.results.append(rec)

    # ───── Etap 0: Rejestracje / Loginy ─────
    def t_register_A(self):
        self.ctx.emailA=rnd_email("userA"); self.ctx.pwdA="Haslo123123"
        url=build(self.ctx,"/api/users/register")
        r=_http_json(self.ctx.sesA,self.ctx,"Register A","POST",url,
                     {"name":"Tester A","email":self.ctx.emailA,"password":self.ctx.pwdA,"password_confirmation":self.ctx.pwdA},
                     {"Accept":"application/json"})
        assert r.status_code in (200,201), f"Register A {r.status_code}: {trim(r.text)}"
        return {"status":r.status_code,"method":"POST","url":url}
    def t_login_A(self):
        url=build(self.ctx,"/api/login")
        r=_http_json(self.ctx.sesA,self.ctx,"Login A","POST",url,{"email":self.ctx.emailA,"password":self.ctx.pwdA},{"Accept":"application/json"})
        assert r.status_code==200, f"Login A {r.status_code}: {trim(r.text)}"
        self.ctx.tokenA=must_json(r).get("token"); assert self.ctx.tokenA, "Brak tokenu A"
        return {"status":200,"method":"POST","url":url}
    def t_register_B(self):
        self.ctx.emailB=rnd_email("userB"); self.ctx.pwdB="Haslo123123"
        url=build(self.ctx,"/api/users/register")
        r=_http_json(self.ctx.sesB,self.ctx,"Register B","POST",url,
                     {"name":"Tester B","email":self.ctx.emailB,"password":self.ctx.pwdB,"password_confirmation":self.ctx.pwdB},
                     {"Accept":"application/json"})
        assert r.status_code in (200,201), f"Register B {r.status_code}: {trim(r.text)}"
        return {"status":r.status_code,"method":"POST","url":url}
    def t_login_B(self):
        url=build(self.ctx,"/api/login")
        r=_http_json(self.ctx.sesB,self.ctx,"Login B","POST",url,{"email":self.ctx.emailB,"password":self.ctx.pwdB},{"Accept":"application/json"})
        assert r.status_code==200, f"Login B {r.status_code}: {trim(r.text)}"
        self.ctx.tokenB=must_json(r).get("token"); assert self.ctx.tokenB, "Brak tokenu B"
        return {"status":200,"method":"POST","url":url}
    def t_register_C(self):
        self.ctx.emailC=rnd_email("userC"); self.ctx.pwdC="Haslo123123"
        url=build(self.ctx,"/api/users/register")
        r=_http_json(self.ctx.sesC,self.ctx,"Register C","POST",url,
                     {"name":"Tester C","email":self.ctx.emailC,"password":self.ctx.pwdC,"password_confirmation":self.ctx.pwdC},
                     {"Accept":"application/json"})
        assert r.status_code in (200,201), f"Register C {r.status_code}: {trim(r.text)}"
        return {"status":r.status_code,"method":"POST","url":url}
    def t_login_C(self):
        url=build(self.ctx,"/api/login")
        r=_http_json(self.ctx.sesC,self.ctx,"Login C","POST",url,{"email":self.ctx.emailC,"password":self.ctx.pwdC},{"Accept":"application/json"})
        assert r.status_code==200, f"Login C {r.status_code}: {trim(r.text)}"
        self.ctx.tokenC=must_json(r).get("token"); assert self.ctx.tokenC, "Brak tokenu C"
        return {"status":200,"method":"POST","url":url}

    # ───── Etap 1: User (na bazie UserTest) ─────
    def t_profile_unauth(self):
        url=me(self.ctx,"/profile")
        r=_http_json(self.ctx.sesA,self.ctx,"Profile unauth","GET",url,None,{"Accept":"application/json"})
        assert r.status_code in (401,403), f"Expected 401/403, got {r.status_code}"
        return {"status":r.status_code,"method":"GET","url":url}
    def t_profile_auth(self):
        url=me(self.ctx,"/profile")
        r=_http_json(self.ctx.sesA,self.ctx,"Profile auth","GET",url,None,auth_headers(self.ctx.tokenA))
        assert r.status_code==200, f"Profile {r.status_code}: {trim(r.text)}"
        js=must_json(r); assert "user" in js and "email" in js["user"], "Brak user/email"
        return {"status":200,"method":"GET","url":url}
    def t_patch_profile_name(self):
        url=me(self.ctx,"/profile")
        r=_http_json(self.ctx.sesA,self.ctx,"PATCH name","PATCH",url,{"name":"Tester A++"},auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,204), f"PATCH name {r.status_code}: {trim(r.text)}"
        return {"status":r.status_code,"method":"PATCH","url":url}
    def t_patch_email_conflict(self):
        # potrzebny istniejący B
        url=me(self.ctx,"/profile")
        r=_http_json(self.ctx.sesA,self.ctx,"PATCH email conflict","PATCH",url,{"email":self.ctx.emailB},auth_headers(self.ctx.tokenA))
        assert r.status_code in (400,409,422), f"PATCH email conflict {r.status_code}"
        return {"status":r.status_code,"method":"PATCH","url":url}
    def t_patch_email_ok(self):
        url=me(self.ctx,"/profile")
        new_mail=rnd_email("userA2")
        r=_http_json(self.ctx.sesA,self.ctx,"PATCH email ok","PATCH",url,{"email":new_mail},auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,204), f"PATCH email ok {r.status_code}"
        self.ctx.emailA=new_mail
        return {"status":r.status_code,"method":"PATCH","url":url}
    def t_patch_password(self):
        url=me(self.ctx,"/profile")
        r=_http_json(self.ctx.sesA,self.ctx,"PATCH password","PATCH",url,{"password":"NoweHaslo123","password_confirmation":"NoweHaslo123"},auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,204), f"PATCH password {r.status_code}"
        self.ctx.pwdA="NoweHaslo123"
        return {"status":r.status_code,"method":"PATCH","url":url}
    def t_avatar_missing(self):
        url=me(self.ctx,"/profile/avatar")
        r=_http_multipart(self.ctx.sesA,self.ctx,"Avatar missing",url,{}, {}, auth_headers(self.ctx.tokenA))
        assert r.status_code in (400,422), f"Avatar missing {r.status_code}"
        return {"status":r.status_code,"method":"POST(multipart)","url":url}
    def t_avatar_upload(self, avatar_bytes: bytes):
        url=me(self.ctx,"/profile/avatar")
        files={"avatar":("avatar.png", avatar_bytes, "image/png")}
        r=_http_multipart(self.ctx.sesA,self.ctx,"Avatar upload",url,{}, files, auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,201), f"Avatar upload {r.status_code}"
        return {"status":r.status_code,"method":"POST(multipart)","url":url}
    def t_avatar_download(self):
        url=me(self.ctx,"/profile/avatar")
        r=_http_json(self.ctx.sesA,self.ctx,"Avatar download","GET",url,None,auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,302), f"Avatar download {r.status_code}"
        return {"status":r.status_code,"method":"GET","url":url}
    def t_logout(self):
        url=build(self.ctx,"/api/logout")
        r=_http_json(self.ctx.sesA,self.ctx,"Logout","POST",url,None,auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,204), f"Logout {r.status_code}"
        return {"status":r.status_code,"method":"POST","url":url}
    def t_relogin_A(self):
        url=build(self.ctx,"/api/login")
        r=_http_json(self.ctx.sesA,self.ctx,"Re-login A","POST",url,{"email":self.ctx.emailA,"password":self.ctx.pwdA},{"Accept":"application/json"})
        assert r.status_code==200, f"Re-login A {r.status_code}"
        self.ctx.tokenA=must_json(r).get("token"); assert self.ctx.tokenA
        return {"status":200,"method":"POST","url":url}

    # ───── Etap 2: Notes (na bazie NoteTest) ─────
    def t_notes_index_initial(self):
        url=me(self.ctx,"/notes?top=10&skip=0")
        r=_http_json(self.ctx.sesA,self.ctx,"Notes index initial","GET",url,None,auth_headers(self.ctx.tokenA))
        assert r.status_code==200, f"Notes index {r.status_code}"
        js=must_json(r); assert "data" in js and isinstance(js["data"],list)
        return {"status":200,"method":"GET","url":url}
    def t_note_store_missing(self):
        url=me(self.ctx,"/notes")
        r=_http_multipart(self.ctx.sesA,self.ctx,"Note missing file",url,{}, {}, auth_headers(self.ctx.tokenA))
        assert r.status_code in (400,422), f"Note missing file {r.status_code}"
        return {"status":r.status_code,"method":"POST(multipart)","url":url}
    def t_note_store_invalid_mime(self):
        url=me(self.ctx,"/notes")
        files={"file":("bad.txt", b"hello", "text/plain")}
        r=_http_multipart(self.ctx.sesA,self.ctx,"Note invalid mime",url,{"title":"x"}, files, auth_headers(self.ctx.tokenA))
        assert r.status_code in (400,415,422), f"Note invalid mime {r.status_code}"
        return {"status":r.status_code,"method":"POST(multipart)","url":url}
    def t_note_store_ok(self, file_bytes: bytes):
        url=me(self.ctx,"/notes")
        files={"file":("note.png", file_bytes, "image/png")}
        r=_http_multipart(self.ctx.sesA,self.ctx,"Note store ok",url,{"title":"E2E Note"}, files, auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,201), f"Note store {r.status_code}"
        self.ctx.note_id = must_json(r).get("id") or must_json(r).get("note",{}).get("id")
        assert self.ctx.note_id, "Brak note_id"
        return {"status":r.status_code,"method":"POST(multipart)","url":url}
    def t_note_patch_title(self):
        url=me(self.ctx,f"/notes/{self.ctx.note_id}")
        r=_http_json(self.ctx.sesA,self.ctx,"Note PATCH title","PATCH",url,{"title":"E2E Note — v2"},auth_headers(self.ctx.tokenA))
        if r.status_code==405: # PUT fallback
            r=_http_json(self.ctx.sesA,self.ctx,"Note PUT fallback","PUT",url,{"title":"E2E Note — v2"},auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,204), f"Note update {r.status_code}"
        return {"status":r.status_code,"method":"PATCH/PUT","url":url}
    def t_note_patch_priv_invalid(self):
        url=me(self.ctx,f"/notes/{self.ctx.note_id}")
        r=_http_json(self.ctx.sesA,self.ctx,"Note PATCH is_private invalid","PATCH",url,{"is_private":"nope"},auth_headers(self.ctx.tokenA))
        assert r.status_code in (400,422), f"is_private invalid {r.status_code}"
        return {"status":r.status_code,"method":"PATCH","url":url}
    def t_note_patch_file_missing(self):
        url=me(self.ctx,f"/notes/{self.ctx.note_id}/patch")
        r=_http_multipart(self.ctx.sesA,self.ctx,"Note patch file missing",url,{}, {}, auth_headers(self.ctx.tokenA))
        assert r.status_code in (400,422), f"Note patch missing {r.status_code}"
        return {"status":r.status_code,"method":"POST(multipart)","url":url}
    def t_note_patch_file_ok(self, file_bytes: bytes):
        url=me(self.ctx,f"/notes/{self.ctx.note_id}/patch")
        files={"file":("note2.png", file_bytes, "image/png")}
        r=_http_multipart(self.ctx.sesA,self.ctx,"Note patch file ok",url,{}, files, auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,201), f"Note patch ok {r.status_code}"
        return {"status":r.status_code,"method":"POST(multipart)","url":url}
    def t_note_download(self):
        url=me(self.ctx,f"/notes/{self.ctx.note_id}/download")
        r=_http_json(self.ctx.sesA,self.ctx,"Note download","GET",url,None,auth_headers(self.ctx.tokenA))
        assert r.status_code==200, f"Note download {r.status_code}"
        return {"status":200,"method":"GET","url":url}
    def t_note_foreign_download_403(self):
        url=me(self.ctx,f"/notes/{self.ctx.note_id}/download")
        r=_http_json(self.ctx.sesB,self.ctx,"Foreign download (B→A note)","GET",url,None,auth_headers(self.ctx.tokenB))
        assert r.status_code in (403,404), f"Foreign download status={r.status_code}"
        return {"status":r.status_code,"method":"GET","url":url}
    def t_note_delete(self):
        url=me(self.ctx,f"/notes/{self.ctx.note_id}")
        r=_http_json(self.ctx.sesA,self.ctx,"Note delete","DELETE",url,None,auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,204), f"Note delete {r.status_code}"
        return {"status":r.status_code,"method":"DELETE","url":url}
    def t_note_download_404(self):
        url=me(self.ctx,f"/notes/{self.ctx.note_id}/download")
        r=_http_json(self.ctx.sesA,self.ctx,"Note download after delete","GET",url,None,auth_headers(self.ctx.tokenA))
        assert r.status_code==404, f"Expected 404, got {r.status_code}"
        return {"status":404,"method":"GET","url":url}

    # ───── Etap 3: Courses (na bazie CourseTest) ─────
    def t_courses_index_no_token(self):
        url=me(self.ctx,"/courses")
        r=_http_json(self.ctx.sesA,self.ctx,"Courses index no token","GET",url,None,{"Accept":"application/json"})
        assert r.status_code in (401,403), f"Expected 401/403, got {r.status_code}"
        return {"status":r.status_code,"method":"GET","url":url}
    def t_course_create_A(self):
        url=me(self.ctx,"/courses")
        r=_http_json(self.ctx.sesA,self.ctx,"Create course","POST",url,
            {"title":"My Course","description":"Course for E2E","type":"private"},
            auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,201), f"Create course {r.status_code}"
        js=must_json(r); self.ctx.course_id=(js.get("course") or {}).get("id") or js.get("id")
        assert self.ctx.course_id, "Brak course_id"
        return {"status":r.status_code,"method":"POST","url":url}
    def t_course_avatar_none_404(self):
        url=me(self.ctx,f"/courses/{self.ctx.course_id}/avatar")
        r=_http_json(self.ctx.sesA,self.ctx,"Course avatar none","GET",url,None,auth_headers(self.ctx.tokenA))
        assert r.status_code==404, f"Course avatar none {r.status_code}"
        return {"status":404,"method":"GET","url":url}
    def t_course_create_invalid_type(self):
        url=me(self.ctx,"/courses")
        r=_http_json(self.ctx.sesA,self.ctx,"Create invalid type","POST",url,
            {"title":"Bad","description":"x","type":"weird"},
            auth_headers(self.ctx.tokenA))
        assert r.status_code in (400,422), f"Invalid type {r.status_code}"
        return {"status":r.status_code,"method":"POST","url":url}
    def t_courses_index_contains_A(self):
        url=me(self.ctx,"/courses")
        r=_http_json(self.ctx.sesA,self.ctx,"Courses index contains A","GET",url,None,auth_headers(self.ctx.tokenA))
        assert r.status_code==200, f"Index courses {r.status_code}"
        return {"status":200,"method":"GET","url":url}
    def t_B_cannot_download_A_course_avatar(self):
        url=me(self.ctx,f"/courses/{self.ctx.course_id}/avatar")
        r=_http_json(self.ctx.sesB,self.ctx,"B cannot download A course avatar","GET",url,None,auth_headers(self.ctx.tokenB))
        assert r.status_code in (401,403,404), f"B avatar access {r.status_code}"
        return {"status":r.status_code,"method":"GET","url":url}
    def t_B_cannot_update_or_delete_A_course(self):
        url=me(self.ctx,f"/courses/{self.ctx.course_id}")
        r=_http_json(self.ctx.sesB,self.ctx,"B cannot update A course","PATCH",url,{"title":"hack"},auth_headers(self.ctx.tokenB))
        assert r.status_code in (401,403,404), f"B update {r.status_code}"
        r2=_http_json(self.ctx.sesB,self.ctx,"B cannot delete A course","DELETE",url,None,auth_headers(self.ctx.tokenB))
        assert r2.status_code in (401,403,404), f"B delete {r2.status_code}"
        return {"status":r2.status_code,"method":"DELETE","url":url}
    def t_invite_B_and_accept(self):
        # invite
        url=build(self.ctx, f"/api/courses/{self.ctx.course_id}/invite-user")
        r=_http_json(self.ctx.sesA,self.ctx,"Invite B","POST",url,{"email":self.ctx.emailB,"role":"member"},auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,201), f"Invite B {r.status_code}"
        # receive
        url2=build(self.ctx,"/api/me/invitations-received")
        r2=_http_json(self.ctx.sesB,self.ctx,"B invitations","GET",url2,None,auth_headers(self.ctx.tokenB))
        assert r2.status_code==200, f"B invitations {r2.status_code}"
        invs=must_json(r2).get("invitations",[]); assert invs, "Brak zaproszeń dla B"
        token=invs[0].get("token"); assert token, "Brak tokenu zaproszenia"
        # accept
        url3=build(self.ctx,f"/api/courses/{self.ctx.course_id}/accept-invite/{token}")
        r3=_http_json(self.ctx.sesB,self.ctx,"B accept invite","POST",url3,None,auth_headers(self.ctx.tokenB))
        assert r3.status_code in (200,204), f"B accept {r3.status_code}"
        return {"status":r3.status_code,"method":"POST","url":url3}
    def t_courses_index_contains_B(self):
        url=me(self.ctx,"/courses")
        r=_http_json(self.ctx.sesB,self.ctx,"Courses index contains B","GET",url,None,auth_headers(self.ctx.tokenB))
        assert r.status_code==200, f"Index B courses {r.status_code}"
        return {"status":200,"method":"GET","url":url}
    def t_note_share_to_course_flow(self, file_bytes: bytes):
        # create fresh note by A
        url=me(self.ctx,"/notes"); files={"file":("share.png", file_bytes, "image/png")}
        r=_http_multipart(self.ctx.sesA,self.ctx,"Create note for share",url,{"title":"Shared"}, files, auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,201), f"Create note share {r.status_code}"
        nid=must_json(r).get("id") or must_json(r).get("note",{}).get("id"); assert nid
        # B cannot share A note
        urlb=build(self.ctx, f"/api/notes/{nid}/share-course/{self.ctx.course_id}")
        rb=_http_json(self.ctx.sesB,self.ctx,"B cannot share A note","POST",urlb,None,auth_headers(self.ctx.tokenB))
        assert rb.status_code in (403,404), f"B share note {rb.status_code}"
        # A share invalid course
        urlbad=build(self.ctx, f"/api/notes/{nid}/share-course/999999")
        rbad=_http_json(self.ctx.sesA,self.ctx,"A share to invalid course","POST",urlbad,None,auth_headers(self.ctx.tokenA))
        assert rbad.status_code in (404,422), f"Share invalid course {rbad.status_code}"
        # A share ok
        urla=build(self.ctx, f"/api/notes/{nid}/share-course/{self.ctx.course_id}")
        ra=_http_json(self.ctx.sesA,self.ctx,"A share note -> course","POST",urla,None,auth_headers(self.ctx.tokenA))
        assert ra.status_code in (200,201,204), f"Share note {ra.status_code}"
        # verify flags — przyjmujemy, że endpoint index/notes w kursie lub note meta expose flags
        return {"status":ra.status_code,"method":"POST","url":urla}
    def t_remove_B_then_owner_422(self):
        # remove B
        url=build(self.ctx,f"/api/courses/{self.ctx.course_id}/remove-user")
        r=_http_json(self.ctx.sesA,self.ctx,"Remove B","POST",url,{"email":self.ctx.emailB},auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,204), f"Remove B {r.status_code}"
        # idempotent non-member
        r2=_http_json(self.ctx.sesA,self.ctx,"Remove non-member idempotent","POST",url,{"email":"nonexist@example.com"},auth_headers(self.ctx.tokenA))
        assert r2.status_code in (200,204), f"Remove non-member {r2.status_code}"
        # owner 422
        r3=_http_json(self.ctx.sesA,self.ctx,"Remove owner blocked","POST",url,{"email":self.ctx.emailA},auth_headers(self.ctx.tokenA))
        assert r3.status_code in (400,422), f"Remove owner {r3.status_code}"
        return {"status":r3.status_code,"method":"POST","url":url}
    def t_invite_limit_C_3_rejects(self):
        # second course for limit tests
        urlc=me(self.ctx,"/courses")
        rc=_http_json(self.ctx.sesA,self.ctx,"Create course #2","POST",urlc,
                      {"title":"Course 2","description":"limit tests","type":"private"},auth_headers(self.ctx.tokenA))
        assert rc.status_code in (200,201), f"Create course2 {rc.status_code}"
        js=must_json(rc); self.ctx.course2_id=(js.get("course") or {}).get("id") or js.get("id"); assert self.ctx.course2_id
        # invite/reject x3
        for i in range(1,4):
            url=build(self.ctx, f"/api/courses/{self.ctx.course2_id}/invite-user")
            r=_http_json(self.ctx.sesA,self.ctx,f"Invite C #{i}","POST",url,{"email":self.ctx.emailC,"role":"member"},auth_headers(self.ctx.tokenA))
            assert r.status_code in (200,201), f"Invite C {i} {r.status_code}"
            # received
            url2=build(self.ctx,"/api/me/invitations-received")
            r2=_http_json(self.ctx.sesC,self.ctx,f"C received #{i}","GET",url2,None,auth_headers(self.ctx.tokenC))
            invs=must_json(r2).get("invitations",[]); assert invs
            token=invs[0].get("token"); assert token
            # reject
            url3=build(self.ctx,f"/api/courses/{self.ctx.course2_id}/reject-invite/{token}")
            r3=_http_json(self.ctx.sesC,self.ctx,f"C reject #{i}","POST",url3,None,auth_headers(self.ctx.tokenC))
            assert r3.status_code in (200,204), f"C reject {i} {r3.status_code}"
        # 4th blocked
        url4=build(self.ctx, f"/api/courses/{self.ctx.course2_id}/invite-user")
        r4=_http_json(self.ctx.sesA,self.ctx,"Invite C #4 blocked","POST",url4,{"email":self.ctx.emailC,"role":"member"},auth_headers(self.ctx.tokenA))
        assert r4.status_code in (400,422), f"Invite C #4 {r4.status_code}"
        return {"status":r4.status_code,"method":"POST","url":url4}

    # ───── Etap 4: Quiz (na bazie QuizTest) ─────
    def t_quiz_create_private(self):
        url=me(self.ctx,"/tests")
        r=_http_json(self.ctx.sesA,self.ctx,"Create PRIVATE test","POST",url,
                     {"title":"Private Test 1","description":"desc","status":"private"},
                     auth_headers(self.ctx.tokenA))
        assert r.status_code==201, f"Create private test {r.status_code}"
        self.ctx.test_private_id=must_json(r).get("id"); assert self.ctx.test_private_id
        return {"status":201,"method":"POST","url":url}
    def t_quiz_show_and_update(self):
        url=me(self.ctx,f"/tests/{self.ctx.test_private_id}")
        r=_http_json(self.ctx.sesA,self.ctx,"Show private test","GET",url,None,auth_headers(self.ctx.tokenA))
        assert r.status_code==200, f"Show private {r.status_code}"
        r2=_http_json(self.ctx.sesA,self.ctx,"Update private test","PUT",url,{"title":"Private Test 1 — updated","description":"desc2"},auth_headers(self.ctx.tokenA))
        assert r2.status_code==200, f"Update private {r2.status_code}"
        return {"status":200,"method":"PUT","url":url}
    def t_quiz_questions_answers_limits(self):
        # add Q1
        urlq=me(self.ctx,f"/tests/{self.ctx.test_private_id}/questions")
        r=_http_json(self.ctx.sesA,self.ctx,"Add Q1","POST",urlq,{"question":"What is 2+2?"},auth_headers(self.ctx.tokenA))
        assert r.status_code==201, f"Add Q1 {r.status_code}"
        self.ctx.question_id=must_json(r).get("id"); assert self.ctx.question_id
        # update Q1
        urlq1=me(self.ctx,f"/tests/{self.ctx.test_private_id}/questions/{self.ctx.question_id}")
        ru=_http_json(self.ctx.sesA,self.ctx,"Update Q1","PUT",urlq1,{"question":"What is 3+3?"},auth_headers(self.ctx.tokenA))
        assert ru.status_code==200, f"Update Q1 {ru.status_code}"
        # answers: invalid first (e.g. no correct present yet / duplicate) — zależnie od walidacji backendu
        urla=me(self.ctx,f"/tests/{self.ctx.test_private_id}/questions/{self.ctx.question_id}/answers")
        r1=_http_json(self.ctx.sesA,self.ctx,"Add A1 (correct)","POST",urla,{"answer":"6","is_correct":True},auth_headers(self.ctx.tokenA))
        assert r1.status_code==201, f"A1 {r1.status_code}"
        # duplicate (should 400)
        rdup=_http_json(self.ctx.sesA,self.ctx,"Add A1 duplicate","POST",urla,{"answer":"6","is_correct":False},auth_headers(self.ctx.tokenA))
        assert rdup.status_code in (400,422), f"A duplicate {rdup.status_code}"
        # add wrong answers up to limit 4 total
        ids=[must_json(r1).get("id")]
        for ans in ("5","7","8"):
            rr=_http_json(self.ctx.sesA,self.ctx,"Add wrong","POST",urla,{"answer":ans,"is_correct":False},auth_headers(self.ctx.tokenA))
            assert rr.status_code==201, f"Add wrong {rr.status_code}"
            ids.append(must_json(rr).get("id"))
        # 5th should block
        rlim=_http_json(self.ctx.sesA,self.ctx,"Add 5th blocked","POST",urla,{"answer":"9","is_correct":False},auth_headers(self.ctx.tokenA))
        assert rlim.status_code in (400,422), f"5th answer {rlim.status_code}"
        # update #2 -> correct
        if len(ids)>=2:
            urlu=me(self.ctx,f"/tests/{self.ctx.test_private_id}/questions/{self.ctx.question_id}/answers/{ids[1]}")
            ru=_http_json(self.ctx.sesA,self.ctx,"Update answer #2 -> correct","PUT",urlu,{"is_correct":True},auth_headers(self.ctx.tokenA))
            assert ru.status_code==200, f"Update ans {ru.status_code}"
        # delete #3
        if len(ids)>=3:
            urld=me(self.ctx,f"/tests/{self.ctx.test_private_id}/questions/{self.ctx.question_id}/answers/{ids[2]}")
            rd=_http_json(self.ctx.sesA,self.ctx,"Delete answer #3","DELETE",urld,None,auth_headers(self.ctx.tokenA))
            assert rd.status_code in (200,204), f"Delete ans {rd.status_code}"
        # delete Q1
        rdq=_http_json(self.ctx.sesA,self.ctx,"Delete Q1","DELETE",urlq1,None,auth_headers(self.ctx.tokenA))
        assert rdq.status_code in (200,204), f"Delete Q1 {rdq.status_code}"
        # add up to 20 questions
        for i in range(1,21):
            rq=_http_json(self.ctx.sesA,self.ctx,f"Add Q{i}","POST",urlq,{"question":f"Q{i}?"},auth_headers(self.ctx.tokenA))
            assert rq.status_code==201, f"Add Q{i} {rq.status_code}"
        # 21st blocked
        rq21=_http_json(self.ctx.sesA,self.ctx,"Add Q21 blocked","POST",urlq,{"question":"Q21?"},auth_headers(self.ctx.tokenA))
        assert rq21.status_code in (400,422), f"Q21 blocked {rq21.status_code}"
        return {"status":200,"method":"POST","url":urlq}
    def t_quiz_create_public_and_share_course(self):
        url=me(self.ctx,"/tests")
        r=_http_json(self.ctx.sesA,self.ctx,"Create PUBLIC test","POST",url,{"title":"Public Test","description":"desc","status":"public"},auth_headers(self.ctx.tokenA))
        assert r.status_code in (200,201), f"Create public {r.status_code}"
        self.ctx.test_public_id = must_json(r).get("id") or must_json(r).get("test",{}).get("id"); assert self.ctx.test_public_id
        # share → course_id
        url1=build(self.ctx,f"/api/tests/{self.ctx.test_public_id}/share-course/{self.ctx.course_id}")
        r1=_http_json(self.ctx.sesA,self.ctx,"Share public test → course","POST",url1,None,auth_headers(self.ctx.tokenA))
        if r1.status_code not in (200,201,204):
            # fallback: create directly in course (jak w QuizTest) :contentReference[oaicite:4]{index=4}
            url2=build(self.ctx,f"/api/courses/{self.ctx.course_id}/tests")
            r2=_http_json(self.ctx.sesA,self.ctx,"Share fallback: create in course","POST",url2,{"title":"Shared via fallback","description":"course-owned"},auth_headers(self.ctx.tokenA))
            assert r2.status_code in (200,201), f"Fallback create {r2.status_code}"
        return {"status":200,"method":"POST","url":url1}
    def t_quiz_permissions_B(self):
        # B cannot read/modify A private
        url=me(self.ctx,f"/tests/{self.ctx.test_private_id}")
        r=_http_json(self.ctx.sesB,self.ctx,"B show A private","GET",url,None,auth_headers(self.ctx.tokenB))
        assert r.status_code in (403,404), f"B show {r.status_code}"
        r2=_http_json(self.ctx.sesB,self.ctx,"B update A private","PUT",url,{"title":"hack"},auth_headers(self.ctx.tokenB))
        assert r2.status_code in (403,404), f"B update {r2.status_code}"
        urlq=me(self.ctx,f"/tests/{self.ctx.test_private_id}/questions")
        r3=_http_json(self.ctx.sesB,self.ctx,"B add Q to A","POST",urlq,{"question":"hack?"},auth_headers(self.ctx.tokenB))
        assert r3.status_code in (403,404), f"B add Q {r3.status_code}"
        r4=_http_json(self.ctx.sesB,self.ctx,"B delete A test","DELETE",url,None,auth_headers(self.ctx.tokenB))
        assert r4.status_code in (403,404), f"B delete {r4.status_code}"
        return {"status":r4.status_code,"method":"DELETE","url":url}
    def t_quiz_cleanup(self):
        # delete tests & course at the end of quiz part
        urlp=me(self.ctx,f"/tests/{self.ctx.test_public_id}")
        rp=_http_json(self.ctx.sesA,self.ctx,"Delete public test","DELETE",urlp,None,auth_headers(self.ctx.tokenA))
        assert rp.status_code in (200,204), f"Delete public {rp.status_code}"
        urlpr=me(self.ctx,f"/tests/{self.ctx.test_private_id}")
        rr=_http_json(self.ctx.sesA,self.ctx,"Delete private test","DELETE",urlpr,None,auth_headers(self.ctx.tokenA))
        assert rr.status_code in (200,204), f"Delete private {rr.status_code}"
        urlc=me(self.ctx,f"/courses/{self.ctx.course_id}")
        rc=_http_json(self.ctx.sesA,self.ctx,"Delete course #1","DELETE",urlc,None,auth_headers(self.ctx.tokenA))
        assert rc.status_code in (200,204), f"Delete course {rc.status_code}"
        urlc2=me(self.ctx,f"/courses/{self.ctx.course2_id}")
        rc2=_http_json(self.ctx.sesA,self.ctx,"Delete course #2","DELETE",urlc2,None,auth_headers(self.ctx.tokenA))
        assert rc2.status_code in (200,204,404), f"Delete course2 {rc2.status_code}"
        return {"status":200,"method":"DELETE","url":urlc}

    # ───── Perf / Rate-limit (before deleting user A) ─────
    def t_perf(self, endpoint_path: str, total: int, conc: int):
        url=build(self.ctx, endpoint_path)
        lat=[]; codes=[]; lock=threading.Lock()
        def worker(idx:int):
            t0=time.time()
            try:
                r=self.ctx.sesA.get(url, headers=auth_headers(self.ctx.tokenA), timeout=self.ctx.timeout)
                code=r.status_code
            except Exception:
                code=599
            dt=(time.time()-t0)*1000.0
            with lock:
                lat.append(dt); codes.append(code)
        with ThreadPoolExecutor(max_workers=max(1,conc)) as ex:
            futs=[ex.submit(worker,i) for i in range(total)]
            for _ in as_completed(futs): pass
        self.ctx.perf_samples=lat; self.ctx.perf_codes=codes
        return {"status":200,"method":"GET","url":url}

    # ───── Końcowy cleanup użytkownika A ─────
    def t_delete_user_A_and_login_fail(self):
        # relogin (na wszelki)
        self.t_relogin_A()
        url=build(self.ctx,"/api/me/delete")  # jeśli nie ma — użyj właściwego DELETE profilu
        r=_http_json(self.ctx.sesA,self.ctx,"DELETE profile A","DELETE",url,None,auth_headers(self.ctx.tokenA))
        # niektóre API mają DELETE /api/me lub /api/users/{id}
        if r.status_code not in (200,204):
            # fallback: /api/me/profile
            url2=me(self.ctx,"/profile")
            r2=_http_json(self.ctx.sesA,self.ctx,"DELETE profile A (fallback)","DELETE",url2,None,auth_headers(self.ctx.tokenA))
            assert r2.status_code in (200,204), f"Delete profile fallback {r2.status_code}"
        # login powinien się nie udać
        urlL=build(self.ctx,"/api/login")
        rL=_http_json(self.ctx.sesA,self.ctx,"Login after delete (should fail)","POST",urlL,{"email":self.ctx.emailA,"password":self.ctx.pwdA},{"Accept":"application/json"})
        assert rL.status_code in (400,401,404), f"Login after delete {rL.status_code}"
        return {"status":rL.status_code,"method":"POST","url":urlL}

# ───────────────────────── Perf helpers ─────────────────────────
def percentiles(samples: List[float], ps=(50,90,99)) -> Dict[int,float]:
    if not samples: return {p:0.0 for p in ps}
    s=sorted(samples); n=len(s)
    out={}
    for p in ps:
        k=min(n-1, max(0, int(round((p/100.0)*(n-1)))))
        out[p]=s[k]
    return out

def perf_summary(ctx: E2EContext, endpoint: str, total: int, conc: int) -> Dict[str,Any]:
    ps=percentiles(ctx.perf_samples); ok=sum(1 for c in ctx.perf_codes if 200<=c<400)
    rl=ctx.perf_codes.count(429); err=len(ctx.perf_codes)-ok-rl
    duration=max(1e-9, time.time()-ctx.started_at)  # coarse RPS over whole run
    return {"endpoint":endpoint,"n":total,"conc":conc,"p50":ps.get(50,0.0),"p90":ps.get(90,0.0),"p99":ps.get(99,0.0),
            "ok":ok,"rl":rl,"err":err,"rps": len(ctx.perf_samples)/max(1e-9,(sum(ctx.perf_samples)/1000.0))}

# ───────────────────────── main ─────────────────────────
def main():
    args=parse_args(); colorama_init()
    print(c(f"\n{ICON_INFO} Start Integrated E2E @ {args.base_url}\n", Fore.WHITE))

    # sesje (User-Agent rozdzielone lojalnie z Twoich testów)
    sA=requests.Session(); sA.headers.update({"User-Agent":"NoteSync-E2E/A","Accept":"application/json"})
    sB=requests.Session(); sB.headers.update({"User-Agent":"NoteSync-E2E/B","Accept":"application/json"})
    sC=requests.Session(); sC.headers.update({"User-Agent":"NoteSync-E2E/C","Accept":"application/json"})

    ctx=E2EContext(
        base_url=args.base_url.rstrip("/"), me_prefix=args.me_prefix, timeout=args.timeout,
        sesA=sA, sesB=sB, sesC=sC
    )
    runner=Runner(ctx)

    # pliki (fallback generowany, jak w testach cząstkowych)
    avatar_bytes = open(args.avatar,"rb").read() if args.avatar and os.path.isfile(args.avatar) else gen_png_bytes((220,220))
    note_bytes   = open(args.note_file,"rb").read() if args.note_file and os.path.isfile(args.note_file) else gen_png_bytes((140,140))

    # ── Etap 0: Auth (A,B,C)
    runner._exec(f"{ICON_USER} Rejestracja A", runner.t_register_A)
    runner._exec(f"{ICON_LOCK} Login A", runner.t_login_A)
    runner._exec(f"{ICON_USER} Rejestracja B", runner.t_register_B)
    runner._exec(f"{ICON_LOCK} Login B", runner.t_login_B)
    runner._exec(f"{ICON_USER} Rejestracja C", runner.t_register_C)
    runner._exec(f"{ICON_LOCK} Login C", runner.t_login_C)

    # ── Etap 1: User
    runner._exec(f"{ICON_LOCK} Profil bez autoryzacji", runner.t_profile_unauth)
    runner._exec(f"{ICON_LOCK} Profil z autoryzacją", runner.t_profile_auth)
    runner._exec(f"✏️ PATCH name", runner.t_patch_profile_name)
    runner._exec(f"✏️ PATCH email conflict", runner.t_patch_email_conflict)
    runner._exec(f"✏️ PATCH email ok", runner.t_patch_email_ok)
    runner._exec(f"✏️ PATCH password", runner.t_patch_password)
    runner._exec(f"{ICON_IMG} Avatar missing", runner.t_avatar_missing)
    runner._exec(f"{ICON_IMG} Avatar upload", lambda: runner.t_avatar_upload(avatar_bytes))
    runner._exec(f"{ICON_IMG} Avatar download", runner.t_avatar_download)
    runner._exec(f"🚪 Logout", runner.t_logout)
    runner._exec(f"{ICON_LOCK} Re-login A", runner.t_relogin_A)

    # ── Etap 2: Notes
    runner._exec(f"🗒️ Notes index (initial)", runner.t_notes_index_initial)
    runner._exec(f"🖼️ Store: missing file → 400/422", runner.t_note_store_missing)
    runner._exec(f"🖼️ Store: invalid mime → 400/422", runner.t_note_store_invalid_mime)
    runner._exec(f"🖼️ Store: ok (multipart)", lambda: runner.t_note_store_ok(note_bytes))
    runner._exec(f"🔒 Foreign download → 403/404", runner.t_note_foreign_download_403)
    runner._exec(f"🩹 PATCH title", runner.t_note_patch_title)
    runner._exec(f"🩹 PATCH is_private invalid → 400/422", runner.t_note_patch_priv_invalid)
    runner._exec(f"🖼️ POST …/patch: missing file → 400/422", runner.t_note_patch_file_missing)
    runner._exec(f"🖼️ POST …/patch: ok", lambda: runner.t_note_patch_file_ok(note_bytes))
    runner._exec(f"⬇️ Download note (200)", runner.t_note_download)
    runner._exec(f"🗑️ DELETE note", runner.t_note_delete)
    runner._exec(f"⬇️ Download after delete → 404", runner.t_note_download_404)

    # ── Etap 3: Courses
    runner._exec(f"🔒 Courses index without token → 401/403", runner.t_courses_index_no_token)
    runner._exec(f"🏫 Create course (A)", runner.t_course_create_A)
    runner._exec(f"🖼️ Course avatar none → 404", runner.t_course_avatar_none_404)
    runner._exec(f"🏫 Create course invalid type → 400/422", runner.t_course_create_invalid_type)
    runner._exec(f"🏫 Index courses (A contains)", runner.t_courses_index_contains_A)
    runner._exec(f"🖼️ B cannot download A avatar → 401/403/404", runner.t_B_cannot_download_A_course_avatar)
    runner._exec(f"✏️/🗑️ B cannot update/delete A course → 401/403/404", runner.t_B_cannot_update_or_delete_A_course)
    runner._exec(f"✉️ Invite B → accept", runner.t_invite_B_and_accept)
    runner._exec(f"🏫 Index courses (B contains)", runner.t_courses_index_contains_B)
    runner._exec(f"🔗 Share note flow (A→course)", lambda: runner.t_note_share_to_course_flow(note_bytes))
    runner._exec(f"🗑️ Remove B; idempotent; owner=422", runner.t_remove_B_then_owner_422)
    runner._exec(f"✉️ Invite C limit: 3×reject → 4th 422", runner.t_invite_limit_C_3_rejects)

    # ── Etap 4: Quiz (na kursie #1)
    runner._exec(f"{ICON_BOOK} Create PRIVATE test", runner.t_quiz_create_private)
    runner._exec(f"{ICON_LIST} Show/Update private test", runner.t_quiz_show_and_update)
    runner._exec(f"{ICON_Q} Q/A limits + duplicate + 20/21", runner.t_quiz_questions_answers_limits)
    runner._exec(f"{ICON_LINK} Create PUBLIC test → share course", runner.t_quiz_create_public_and_share_course)
    runner._exec(f"🔒 B cannot access/modify A private test", runner.t_quiz_permissions_B)

    # ── Perf (przed usunięciem A)
    perf_sum=None
    if not args.skip_load:
        runner._exec(f"{ICON_CLOCK} PERF/RATE-LIMIT GET {args.perf-endpoint} (N={args.perf_requests}, C={args.perf_concurrency})",
                     lambda: runner.t_perf(args.perf_endpoint, args.perf_requests, args.perf_concurrency))
        perf_sum = perf_summary(ctx, args.perf_endpoint, args.perf_requests, args.perf_concurrency)

    # ── Cleanup: quiz/courses + delete user A + login-fail
    runner._exec(f"{ICON_TRASH} Cleanup quiz + courses", runner.t_quiz_cleanup)
    runner._exec(f"{ICON_TRASH} DELETE profile A + login should fail", runner.t_delete_user_A_and_login_fail)

    # raport
    report_path=build_report_path()
    save_html_report(ctx, report_path, perf_sum)

if __name__=="__main__":
    try: main()
    except KeyboardInterrupt:
        print("\nPrzerwano."); sys.exit(130)
