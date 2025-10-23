#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
QuizTest.py — E2E test sekcji Testów/Quizów (Laravel + JWT)
- Konsola: tylko progres PASS/FAIL + na końcu jedna tabela zbiorcza
- HTML: pełne szczegóły każdego żądania (nagłówki, body, odpowiedź)
- Wyniki: tests/E2E/result/Quiz/ResultE2E--YYYYMMDD--HHMMSS/index.html
"""

from __future__ import annotations

import argparse
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

# ───────────────────────── UI ─────────────────────────
ICON_OK    = "✅"
ICON_FAIL  = "❌"
ICON_INFO  = "ℹ️"
ICON_LOCK  = "🔒"
ICON_USER  = "👤"
ICON_BOOK  = "📘"
ICON_Q     = "❓"
ICON_A     = "🅰️"
ICON_LINK  = "🔗"
ICON_TRASH = "🗑️"
ICON_EDIT  = "✏️"
ICON_LIST  = "📋"
ICON_CLOCK = "⏱️"
BOX = "─" * 84

MAX_BODY_LOG = 8000

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

def mask_headers(h: Dict[str, str]) -> Dict[str, str]:
    out = {}
    for k, v in h.items():
        kl = k.lower()
        if kl in ("authorization", "cookie", "set-cookie"):
            if kl == "authorization" and isinstance(v, str) and v.lower().startswith("bearer "):
                tok = v.split(" ", 1)[1]
                v = "Bearer " + (tok[:6] + "…" + tok[-4:] if len(tok) > 12 else "******")
            else:
                v = "<hidden>"
        out[k] = v
    return out

def mask_json_sensitive(data: Any) -> Any:
    if isinstance(data, dict):
        red = {}
        for k, v in data.items():
            if k in ("password","password_confirmation","token"):
                red[k] = "***"
            else:
                red[k] = mask_json_sensitive(v)
        return red
    if isinstance(data, list):
        return [mask_json_sensitive(x) for x in data]
    return data

# ───────────────────────── CLI ─────────────────────────
def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(description="NoteSync Quiz API E2E")
    p.add_argument("--base-url", required=True, help="np. http://localhost:8000")
    p.add_argument("--me-prefix", default="me", help="prefiks dla /api/<prefix> (domyślnie 'me')")
    p.add_argument("--timeout", type=int, default=20, help="timeout żądań w sekundach")
    p.add_argument("--html-report", action="store_true", help="generuj raport HTML")
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
    timeout: int = 20
    # auth
    token: Optional[str] = None
    userA: Tuple[str,str] = ("","")
    userB: Tuple[str,str] = ("","")
    # entities
    course_id: Optional[int] = None
    test_private_id: Optional[int] = None
    test_public_id: Optional[int] = None
    question_id: Optional[int] = None
    answer_ids: List[int] = field(default_factory=list)
    # logging
    started_at: float = field(default_factory=time.time)
    endpoints: List[EndpointLog] = field(default_factory=list)
    # report dir
    out_dir: str = ""

# ───────────────────────── Helpers ─────────────────────────
def rnd_email() -> str:
    token = "".join(random.choices(string.ascii_lowercase + string.digits, k=8))
    return f"quiz.{token}@example.com"

def build(ctx: TestContext, path: str) -> str:
    return f"{ctx.base_url.rstrip('/')}{path}"

def me(ctx: TestContext, path: str) -> str:
    return f"{ctx.base_url.rstrip('/')}/api/{ctx.me_prefix.strip('/')}{path}"

def auth_headers(ctx: TestContext) -> Dict[str, str]:
    h = {"Accept": "application/json"}
    if ctx.token:
        h["Authorization"] = f"Bearer {ctx.token}"
    return h

def must_json(resp: requests.Response) -> Dict[str, Any]:
    try:
        return resp.json()
    except Exception:
        raise AssertionError(f"Odpowiedź nie-JSON (CT={resp.headers.get('Content-Type')}): {trim(resp.text)}")

def http_json(ctx: TestContext, title: str, method: str, url: str,
              json_body: Optional[Dict[str, Any]], headers: Dict[str,str]) -> requests.Response:
    hs = dict(headers or {})
    req_headers_log = mask_headers(hs.copy())
    t0 = time.time()
    if method == "GET":
        resp = ctx.ses.get(url, headers=hs, timeout=ctx.timeout)
    elif method == "POST":
        resp = ctx.ses.post(url, headers=hs, json=json_body, timeout=ctx.timeout)
    elif method == "PUT":
        resp = ctx.ses.put(url, headers=hs, json=json_body, timeout=ctx.timeout)
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
        req_body=mask_json_sensitive(json_body) if json_body else {},
        req_is_json=True,
        duration_ms=(time.time() - t0) * 1000.0
    )
    # response logging
    el.resp_status = resp.status_code
    el.resp_headers = {k: str(v) for k, v in resp.headers.items()}
    try:
        if "application/json" in resp.headers.get("Content-Type","").lower():
            el.resp_body = pretty_json(mask_json_sensitive(resp.json()))
        else:
            el.resp_body = trim(resp.text, MAX_BODY_LOG)
    except Exception:
        el.resp_body = trim(resp.text, MAX_BODY_LOG)
    ctx.endpoints.append(el)
    return resp

# ───────────────────────── Runner ─────────────────────────
class ApiTester:
    def __init__(self, ctx: TestContext):
        self.ctx = ctx
        self.results: List[TestRecord] = []

    def run(self):
        steps: List[Tuple[str, Callable[[], Dict[str, Any]]]] = [
            (f"{ICON_USER} Rejestracja A", self.t_register_A),
            (f"{ICON_LOCK} Login A", self.t_login_A),
            (f"{ICON_BOOK} Create course", self.t_create_course),

            (f"{ICON_BOOK} Index my tests (empty ok)", self.t_index_user_tests_initial),

            (f"{ICON_BOOK} Create PRIVATE test", self.t_create_private_test),
            (f"{ICON_LIST} List my tests includes private", self.t_index_user_tests_contains_private),
            (f"{ICON_BOOK} Show private test", self.t_show_private_test),
            (f"{ICON_EDIT} Update private test (PUT)", self.t_update_private_test),

            (f"{ICON_Q} Add question #1", self.t_add_question),
            (f"{ICON_LIST} List questions has #1", self.t_list_questions_contains_q1),
            (f"{ICON_EDIT} Update question #1", self.t_update_question),

            (f"{ICON_A} Add answer invalid (no correct yet)", self.t_add_answer_invalid_first),
            (f"{ICON_A} Add answer #1 (correct)", self.t_add_answer_correct_first),
            (f"{ICON_A} Add answer duplicate → 400", self.t_add_answer_duplicate),
            (f"{ICON_A} Add answer #2 (wrong)", self.t_add_answer_wrong_2),
            (f"{ICON_A} Add answer #3 (wrong)", self.t_add_answer_wrong_3),
            (f"{ICON_A} Add answer #4 (wrong)", self.t_add_answer_wrong_4),
            (f"{ICON_A} Add answer #5 blocked (limit 4)", self.t_add_answer_limit),

            (f"{ICON_LIST} Get answers list", self.t_get_answers_list),
            (f"{ICON_EDIT} Update answer #2 -> correct", self.t_update_answer),
            (f"{ICON_TRASH} Delete answer #3", self.t_delete_answer),

            (f"{ICON_TRASH} Delete question #1", self.t_delete_question),

            (f"{ICON_Q} Add up to 20 questions", self.t_add_questions_to_20),
            (f"{ICON_Q} 21st question blocked", self.t_add_21st_question_block),

            (f"{ICON_BOOK} Create PUBLIC test", self.t_create_public_test),
            (f"{ICON_LINK} Share PUBLIC test → course", self.t_share_public_test_to_course),
            (f"{ICON_LIST} Course tests include shared", self.t_course_tests_include_shared),

            (f"{ICON_USER} Rejestracja B", self.t_register_B),
            (f"{ICON_LOCK} Login B", self.t_login_B),
            (f"{ICON_LOCK} B cannot see A private test", self.t_b_cannot_show_a_test),
            (f"{ICON_LOCK} B cannot modify A test", self.t_b_cannot_modify_a_test),
            (f"{ICON_LOCK} B cannot add question to A test", self.t_b_cannot_add_q_to_a_test),
            (f"{ICON_LOCK} B cannot delete A test", self.t_b_cannot_delete_a_test),

            (f"{ICON_TRASH} Cleanup A: delete public test", self.t_cleanup_delete_public),
            (f"{ICON_TRASH} Cleanup A: delete private test", self.t_cleanup_delete_private),
            (f"{ICON_TRASH} Cleanup A: delete course", self.t_cleanup_delete_course),
        ]

        total = len(steps)
        for idx, (name, fn) in enumerate(steps, 1):
            self._exec(idx, total, name, fn)
        self._summary()

    def _exec(self, idx: int, total: int, name: str, fn: Callable[[], Dict[str, Any]]):
        start = time.time()
        ret = {} # Zapewnienie istnienia 'ret'
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
            rec.status = ret.get("status") if 'ret' in locals() else None
            print(c("FAIL", Fore.RED), c(f"— {e}", Fore.RED))
        except Exception as e:
            rec.error = f"{type(e).__name__}: {e}"
            print(c("ERROR", Fore.RED), c(f"— {e}", Fore.RED))
        rec.duration_ms = (time.time() - start) * 1000.0
        self.results.append(rec)

    # ───────────────────────── Testy ─────────────────────────
    # Rejestracja / logowanie
    def t_register_A(self):
        email = rnd_email(); pwd = "Haslo123123"
        url = build(self.ctx, "/api/users/register")
        r = http_json(self.ctx, "Register A", "POST", url, {
            "name":"Tester A","email":email,
            "password":pwd,"password_confirmation":pwd
        }, {"Accept":"application/json"})
        assert r.status_code in (200,201), f"Register A {r.status_code}: {trim(r.text)}"
        self.ctx.userA = (email, pwd)
        return {"status": r.status_code, "method":"POST", "url":url}

    def t_login_A(self):
        url = build(self.ctx, "/api/login")
        r = http_json(self.ctx, "Login A", "POST", url, {
            "email": self.ctx.userA[0], "password": self.ctx.userA[1]
        }, {"Accept":"application/json"})
        assert r.status_code == 200, f"Login A {r.status_code}: {trim(r.text)}"
        self.ctx.token = must_json(r).get("token")
        assert self.ctx.token, "Brak tokenu"
        return {"status": 200, "method":"POST", "url":url}

    # Kurs
    def t_create_course(self):
        url = me(self.ctx, "/courses")
        r = http_json(self.ctx, "Create course", "POST", url, {
            "title": "QuizCourse",
            "description": "Course for quiz E2E",
            "type": "private"
        }, auth_headers(self.ctx))
        assert r.status_code in (200,201), f"Create course {r.status_code}: {trim(r.text)}"
        self.ctx.course_id = must_json(r).get("course",{}).get("id") or must_json(r).get("id")
        assert self.ctx.course_id, "Brak course_id"
        return {"status": r.status_code, "method":"POST", "url":url}

    # Lista testów użytkownika (pusta)
    def t_index_user_tests_initial(self):
        url = me(self.ctx, "/tests")
        r = http_json(self.ctx, "Index user tests initial", "GET", url, None, auth_headers(self.ctx))
        assert r.status_code == 200, f"Index tests {r.status_code}: {trim(r.text)}"
        return {"status": 200, "method":"GET", "url":url}

    # Tworzenie prywatnego testu
    def t_create_private_test(self):
        url = me(self.ctx, "/tests")
        r = http_json(self.ctx, "Create private test", "POST", url, {
            "title":"Private Test 1", "description":"desc", "status":"private"
        }, auth_headers(self.ctx))
        assert r.status_code == 201, f"Create private test {r.status_code}: {trim(r.text)}"
        self.ctx.test_private_id = must_json(r).get("id")
        assert self.ctx.test_private_id, "Brak test_private_id"
        return {"status": 201, "method":"POST", "url":url}

    def t_index_user_tests_contains_private(self):
        url = me(self.ctx, "/tests")
        r = http_json(self.ctx, "Index contains private", "GET", url, None, auth_headers(self.ctx))
        assert r.status_code == 200, f"Index {r.status_code}"
        js = must_json(r)
        assert any(t.get("id")==self.ctx.test_private_id for t in js), "Lista nie zawiera prywatnego testu"
        return {"status": 200, "method":"GET", "url":url}

    def t_show_private_test(self):
        url = me(self.ctx, f"/tests/{self.ctx.test_private_id}")
        r = http_json(self.ctx, "Show private test", "GET", url, None, auth_headers(self.ctx))
        assert r.status_code == 200, f"Show {r.status_code}: {trim(r.text)}"
        return {"status": 200, "method":"GET", "url":url}

    def t_update_private_test(self):
        url = me(self.ctx, f"/tests/{self.ctx.test_private_id}")
        r = http_json(self.ctx, "Update private test", "PUT", url, {
            "title":"Private Test 1 — updated", "description":"desc2"
        }, auth_headers(self.ctx))
        assert r.status_code == 200, f"Update test {r.status_code}: {trim(r.text)}"
        return {"status": 200, "method":"PUT", "url":url}

    # Pytania
    def t_add_question(self):
        url = me(self.ctx, f"/tests/{self.ctx.test_private_id}/questions")
        r = http_json(self.ctx, "Add Q1", "POST", url, {"question":"What is 2+2?"}, auth_headers(self.ctx))
        assert r.status_code == 201, f"Add Q1 {r.status_code}: {trim(r.text)}"
        self.ctx.question_id = must_json(r).get("id")
        assert self.ctx.question_id, "Brak question_id"
        return {"status": 201, "method":"POST", "url":url}

    def t_list_questions_contains_q1(self):
        url = me(self.ctx, f"/tests/{self.ctx.test_private_id}/questions")
        r = http_json(self.ctx, "List questions", "GET", url, None, auth_headers(self.ctx))
        assert r.status_code == 200, f"List Q {r.status_code}"
        js = must_json(r)
        arr = js.get("questions",[])
        assert any(q.get("id")==self.ctx.question_id for q in arr), "Lista nie zawiera Q1"
        return {"status": 200, "method":"GET", "url":url}

    def t_update_question(self):
        url = me(self.ctx, f"/tests/{self.ctx.test_private_id}/questions/{self.ctx.question_id}")
        r = http_json(self.ctx, "Update Q1", "PUT", url, {"question":"What is 3+3?"}, auth_headers(self.ctx))
        assert r.status_code == 200, f"Update Q1 {r.status_code}"
        return {"status": 200, "method":"PUT", "url":url}

    # Odpowiedzi: walidacje
    def t_add_answer_invalid_first(self):
        url = me(self.ctx, f"/tests/{self.ctx.test_private_id}/questions/{self.ctx.question_id}/answers")
        r = http_json(self.ctx, "Add A1 invalid first", "POST", url, {
            "answer":"4", "is_correct": False
        }, auth_headers(self.ctx))
        assert r.status_code in (400,422), f"Pierwsza odpowiedź niepoprawna powinna być zablokowana, jest {r.status_code}"
        return {"status": r.status_code, "method":"POST", "url":url}

    def t_add_answer_correct_first(self):
        url = me(self.ctx, f"/tests/{self.ctx.test_private_id}/questions/{self.ctx.question_id}/answers")
        r = http_json(self.ctx, "Add A1 correct", "POST", url, {
            "answer":"6", "is_correct": True
        }, auth_headers(self.ctx))
        assert r.status_code == 201, f"Add A1 {r.status_code}: {trim(r.text)}"
        a_id = must_json(r).get("answer",{}).get("id") or must_json(r).get("id")
        assert a_id, "Brak id utworzonej odpowiedzi"
        self.ctx.answer_ids.append(a_id)
        return {"status": 201, "method":"POST", "url":url}

    def t_add_answer_duplicate(self):
        url = me(self.ctx, f"/tests/{self.ctx.test_private_id}/questions/{self.ctx.question_id}/answers")
        r = http_json(self.ctx, "Add duplicate", "POST", url, {
            "answer":"6", "is_correct": False
        }, auth_headers(self.ctx))
        assert r.status_code in (400,422), f"Duplikat powinien dać 400/422, jest {r.status_code}"
        return {"status": r.status_code, "method":"POST", "url":url}

    def t_add_answer_wrong_2(self):
        url = me(self.ctx, f"/tests/{self.ctx.test_private_id}/questions/{self.ctx.question_id}/answers")
        r = http_json(self.ctx, "Add A2 wrong", "POST", url, {
            "answer":"7", "is_correct": False
        }, auth_headers(self.ctx))
        assert r.status_code == 201, f"Add A2 {r.status_code}"
        self.ctx.answer_ids.append(must_json(r).get("answer",{}).get("id") or must_json(r).get("id"))
        return {"status": 201, "method":"POST", "url":url}

    def t_add_answer_wrong_3(self):
        url = me(self.ctx, f"/tests/{self.ctx.test_private_id}/questions/{self.ctx.question_id}/answers")
        r = http_json(self.ctx, "Add A3 wrong", "POST", url, {
            "answer":"8", "is_correct": False
        }, auth_headers(self.ctx))
        assert r.status_code == 201, f"Add A3 {r.status_code}"
        self.ctx.answer_ids.append(must_json(r).get("answer",{}).get("id") or must_json(r).get("id"))
        return {"status": 201, "method":"POST", "url":url}

    def t_add_answer_wrong_4(self):
        url = me(self.ctx, f"/tests/{self.ctx.test_private_id}/questions/{self.ctx.question_id}/answers")
        r = http_json(self.ctx, "Add A4 wrong", "POST", url, {
            "answer":"9", "is_correct": False
        }, auth_headers(self.ctx))
        assert r.status_code == 201, f"Add A4 {r.status_code}"
        self.ctx.answer_ids.append(must_json(r).get("answer",{}).get("id") or must_json(r).get("id"))
        return {"status": 201, "method":"POST", "url":url}

    def t_add_answer_limit(self):
        url = me(self.ctx, f"/tests/{self.ctx.test_private_id}/questions/{self.ctx.question_id}/answers")
        r = http_json(self.ctx, "Add A5 blocked", "POST", url, {
            "answer":"10", "is_correct": False
        }, auth_headers(self.ctx))
        assert r.status_code in (400,422), f"Limit 4 odp. powinien zablokować, jest {r.status_code}"
        return {"status": r.status_code, "method":"POST", "url":url}

    def t_get_answers_list(self):
        url = me(self.ctx, f"/tests/{self.ctx.test_private_id}/questions/{self.ctx.question_id}/answers")
        r = http_json(self.ctx, "Get answers", "GET", url, None, auth_headers(self.ctx))
        assert r.status_code == 200, f"Get answers {r.status_code}"
        return {"status": 200, "method":"GET", "url":url}

    def t_update_answer(self):
        if len(self.ctx.answer_ids) < 2:
            raise AssertionError("Za mało odpowiedzi do aktualizacji")
        target = self.ctx.answer_ids[1]  # druga odpowiedź (była wrong)
        url = me(self.ctx, f"/tests/{self.ctx.test_private_id}/questions/{self.ctx.question_id}/answers/{target}")
        r = http_json(self.ctx, "Update answer #2", "PUT", url, {
            "answer":"7 (upd)", "is_correct": True
        }, auth_headers(self.ctx))
        assert r.status_code == 200, f"Update answer {r.status_code}"
        return {"status": 200, "method":"PUT", "url":url}

    def t_delete_answer(self):
        if len(self.ctx.answer_ids) < 3:
            raise AssertionError("Za mało odpowiedzi do kasowania")
        target = self.ctx.answer_ids[2]  # trzecia odpowiedź
        url = me(self.ctx, f"/tests/{self.ctx.test_private_id}/questions/{self.ctx.question_id}/answers/{target}")
        r = http_json(self.ctx, "Delete answer #3", "DELETE", url, None, auth_headers(self.ctx))
        assert r.status_code == 200, f"Delete answer {r.status_code}"
        return {"status": 200, "method":"DELETE", "url":url}

    def t_delete_question(self):
        url = me(self.ctx, f"/tests/{self.ctx.test_private_id}/questions/{self.ctx.question_id}")
        r = http_json(self.ctx, "Delete Q1", "DELETE", url, None, auth_headers(self.ctx))
        assert r.status_code == 200, f"Delete Q {r.status_code}"
        self.ctx.question_id = None
        self.ctx.answer_ids.clear()
        return {"status": 200, "method":"DELETE", "url":url}

    def t_add_questions_to_20(self):
        url = me(self.ctx, f"/tests/{self.ctx.test_private_id}/questions")
        # dodamy 20 pytań
        for i in range(1, 21):
            r = http_json(self.ctx, f"Add Q{i}", "POST", url, {"question": f"Q{i}?"}, auth_headers(self.ctx))
            assert r.status_code == 201, f"Q{i} {r.status_code}: {trim(r.text)}"
        return {"status": 201, "method":"POST", "url":url}

    def t_add_21st_question_block(self):
        url = me(self.ctx, f"/tests/{self.ctx.test_private_id}/questions")
        r = http_json(self.ctx, "Add Q21 blocked", "POST", url, {"question":"Q21?"}, auth_headers(self.ctx))
        assert r.status_code in (400,422), f"Q21 powinno być zablokowane (limit 20), jest {r.status_code}"
        return {"status": r.status_code, "method":"POST", "url":url}

    # Publiczny test + udostępnianie
    def t_create_public_test(self):
        url = me(self.ctx, "/tests")
        r = http_json(self.ctx, "Create PUBLIC test", "POST", url, {
            "title":"Public Test 1", "description":"share me", "status":"public"
        }, auth_headers(self.ctx))
        assert r.status_code == 201, f"Create public test {r.status_code}: {trim(r.text)}"
        self.ctx.test_public_id = must_json(r).get("id")
        assert self.ctx.test_public_id, "Brak test_public_id"
        return {"status": 201, "method":"POST", "url":url}

    def t_share_public_test_to_course(self):
        # ─── POPRAWIONA LOGIKA (HIPOTEZA) ───
        # Zakładamy, że endpoint `POST /api/courses/{id}/tests`
        # potrafi zarówno TWORZYĆ nowy test (gdy dostanie 'title'),
        # jak i PRZYPISYWAĆ istniejący (gdy dostanie 'test_id').
        # Bazujemy na migracjach (1:N), które wymagają ustawienia 'course_id' w tabeli 'tests'.

        # 1. Próbujemy użyć 'N:N /share' (jak w oryginale), na wypadek gdyby
        #    kontroler implementował 1:N pod tym endpointem
        url1 = me(self.ctx, f"/tests/{self.ctx.test_public_id}/share")
        r1 = http_json(self.ctx, "Share public test (POST /share)", "POST", url1, {
            "course_id": self.ctx.course_id
        }, auth_headers(self.ctx))

        if r1.status_code == 200:
            # Sukces! Ten endpoint działał.
            return {"status": 200, "method":"POST", "url":url1}

        # 2. Skoro /share zawiódł, próbujemy `PUT /me/tests/{id}` (jak w mojej poprzedniej próbie)
        #    Może jednak kontroler został naprawiony lub zadziała.
        url2 = me(self.ctx, f"/tests/{self.ctx.test_public_id}")
        payload2 = {
            "title": "Public Test 1", "description": "share me", "status": "public",
            "course_id": self.ctx.course_id
        }
        r2 = http_json(self.ctx, "Share public test (PUT /me/tests)", "PUT", url2, payload2, auth_headers(self.ctx))

        if r2.status_code == 200:
            # Sukces! Ten endpoint działał.
            return {"status": 200, "method":"PUT", "url":url2}

        # 3. Oba powyższe zawiodły. Próbujemy hipotezy nr 3:
        #    `POST /courses/{id}/tests` z `test_id`
        url3 = build(self.ctx, f"/api/courses/{self.ctx.course_id}/tests")
        payload3 = {
            "test_id": self.ctx.test_public_id
        }
        r3 = http_json(self.ctx, "Share public test (POST /courses/.../tests)", "POST", url3, payload3, auth_headers(self.ctx))

        # Oczekujemy 200 (OK) lub 201 (Created)
        assert r3.status_code in (200, 201), \
            f"Wszystkie metody udostępniania zawiodły. Ostatnia próba (POST /courses) zwróciła {r3.status_code}: {trim(r3.text)}"

        return {"status": r3.status_code, "method":"POST", "url": url3}

    def t_course_tests_include_shared(self):
        url = build(self.ctx, f"/api/courses/{self.ctx.course_id}/tests")
        r = http_json(self.ctx, "Course tests", "GET", url, None, auth_headers(self.ctx))
        assert r.status_code == 200, f"Course tests {r.status_code}"
        js = must_json(r)
        assert isinstance(js, list), "Odpowiedź nie jest listą"

        # ─── WZMOCNIONA ASERCJA ───
        # Sprawdzamy, czy test o ID, który właśnie "udostępniliśmy",
        # faktycznie znajduje się na liście testów kursu.
        assert any(t.get("id") == self.ctx.test_public_id for t in js), \
            f"Lista testów kursu nie zawiera udostępnionego testu ID: {self.ctx.test_public_id}. Znaleziono: {js}"

        return {"status": 200, "method":"GET", "url":url}

    # Użytkownik B (nieautoryzowany do zasobów A)
    def t_register_B(self):
        email = rnd_email(); pwd = "Haslo123123"
        url = build(self.ctx, "/api/users/register")
        r = http_json(self.ctx, "Register B", "POST", url, {
            "name":"Tester B","email":email,
            "password":pwd,"password_confirmation":pwd
        }, {"Accept":"application/json"})
        assert r.status_code in (200,201), f"Register B {r.status_code}: {trim(r.text)}"
        self.ctx.userB = (email, pwd)
        return {"status": r.status_code, "method":"POST", "url":url}

    def t_login_B(self):
        url = build(self.ctx, "/api/login")
        r = http_json(self.ctx, "Login B", "POST", url, {
            "email": self.ctx.userB[0], "password": self.ctx.userB[1]
        }, {"Accept":"application/json"})
        assert r.status_code == 200, f"Login B {r.status_code}: {trim(r.text)}"
        self.ctx.token = must_json(r).get("token")
        assert self.ctx.token, "Brak tokenu B"
        return {"status": 200, "method":"POST", "url":url}

    def t_b_cannot_show_a_test(self):
        # Ten test jest POPRAWNY. Oczekuje 403 lub 404.
        url = me(self.ctx, f"/tests/{self.ctx.test_private_id}")
        r = http_json(self.ctx, "B show A test", "GET", url, None, auth_headers(self.ctx))
        assert r.status_code in (403,404), f"B powinien dostać 403/404, jest {r.status_code}"
        return {"status": r.status_code, "method":"GET", "url":url}

    def t_b_cannot_modify_a_test(self):
        # Ten test jest POPRAWNY. Oczekuje 403 lub 404.
        url = me(self.ctx, f"/tests/{self.ctx.test_private_id}")
        r = http_json(self.ctx, "B update A test", "PUT", url, {
            "title":"hack", "description":"hack"
        }, auth_headers(self.ctx))
        assert r.status_code in (403,404), f"B update powinien 403/404, jest {r.status_code}"
        return {"status": r.status_code, "method":"PUT", "url":url}

    def t_b_cannot_add_q_to_a_test(self):
        # Ten test jest POPRAWNY. Oczekuje 403 lub 404.
        url = me(self.ctx, f"/tests/{self.ctx.test_private_id}/questions")
        r = http_json(self.ctx, "B add Q to A", "POST", url, {"question":"hack?"}, auth_headers(self.ctx))
        assert r.status_code in (403,404), f"B add Q powinien 403/404, jest {r.status_code}"
        return {"status": r.status_code, "method":"POST", "url":url}

    def t_b_cannot_delete_a_test(self):
        # Ten test jest POPRAWNY. Oczekuje 403 lub 404.
        url = me(self.ctx, f"/tests/{self.ctx.test_private_id}")
        r = http_json(self.ctx, "B delete A test", "DELETE", url, None, auth_headers(self.ctx))
        assert r.status_code in (403,404), f"B delete powinien 403/404, jest {r.status_code}"
        return {"status": r.status_code, "method":"DELETE", "url":url}

    # Sprzątanie (A)
    def t_cleanup_delete_public(self):
        # zaloguj z powrotem A
        url_login = build(self.ctx, "/api/login")
        rlog = http_json(self.ctx, "Re-login A for cleanup", "POST", url_login, {
            "email": self.ctx.userA[0], "password": self.ctx.userA[1]
        }, {"Accept":"application/json"})
        assert rlog.status_code == 200, f"Re-login A {rlog.status_code}"
        self.ctx.token = must_json(rlog).get("token")

        if not self.ctx.test_public_id:
            return {"status": 200, "method":"DELETE", "url":"(skip public)"}
        url = me(self.ctx, f"/tests/{self.ctx.test_public_id}")
        r = http_json(self.ctx, "Cleanup delete public", "DELETE", url, None, auth_headers(self.ctx))
        assert r.status_code == 200, f"Cleanup public {r.status_code}"
        return {"status": 200, "method":"DELETE", "url":url}

    def t_cleanup_delete_private(self):
        if not self.ctx.test_private_id:
            return {"status": 200, "method":"DELETE", "url":"(skip private)"}
        url = me(self.ctx, f"/tests/{self.ctx.test_private_id}")
        r = http_json(self.ctx, "Cleanup delete private", "DELETE", url, None, auth_headers(self.ctx))
        assert r.status_code == 200, f"Cleanup private {r.status_code}"
        return {"status": 200, "method":"DELETE", "url":url}

    def t_cleanup_delete_course(self):
        if not self.ctx.course_id:
            return {"status": 200, "method":"DELETE", "url":"(skip course)"}
        url = me(self.ctx, f"/courses/{self.ctx.course_id}")
        r = http_json(self.ctx, "Cleanup delete course", "DELETE", url, None, auth_headers(self.ctx))
        assert r.status_code == 200, f"Cleanup course {r.status_code}"
        return {"status": 200, "method":"DELETE", "url":url}

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
            rows.append([
                r.name,
                c("PASS", Fore.GREEN) if r.passed else c("FAIL", Fore.RED),
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

        write_html_report(self.ctx, self.results)

# ───────────────────────── Raport HTML ─────────────────────────
def write_html_report(ctx: TestContext, results: List[TestRecord]):
    os.makedirs(ctx.out_dir, exist_ok=True)
    path = os.path.join(ctx.out_dir, "index.html")

    # Endpointy (szczegóły)
    ep_html = []
    for i, ep in enumerate(ctx.endpoints, 1):
        req_h = pretty_json(ep.req_headers)
        req_b = pretty_json(ep.req_body) if ep.req_is_json else str(ep.req_body)
        resp_h = pretty_json(ep.resp_headers)
        resp_b = ep.resp_body or ""

        ep_html.append(f"""
<section class="endpoint">
  <h2>{i}. {ep.title}</h2>
  <div class="meta"><span class="m">{ep.method}</span> <code>{ep.url}</code> <span class="dur">{ep.duration_ms:.1f} ms</span> <span class="st">{ep.resp_status if ep.resp_status is not None else ''}</span></div>
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

    # Tabela wyników
    rows = []
    for r in results:
        rows.append(f"""
<tr class="{ 'pass' if r.passed else 'fail' }">
  <td>{r.name}</td>
  <td>{'PASS' if r.passed else 'FAIL'}</td>
  <td>{r.duration_ms:.1f} ms</td>
  <td>{r.method or ''}</td>
  <td><code>{r.url or ''}</code></td>
  <td>{r.status or ''}</td>
</tr>
""")

    html = f"""<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8" />
<title>NoteSync — Quiz API Test Report</title>
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
<h1>NoteSync — Quiz API Test</h1>

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
    ses.headers.update({"User-Agent": "NoteSync-QuizTest/1.0", "Accept":"application/json"})

    # katalog wyników
    ts_date = time.strftime("%Y%m%d")
    ts_time = time.strftime("%H%M%S")
    out_dir = os.path.join(os.getcwd(), "tests", "E2E", "result", "Quiz", f"ResultE2E--{ts_date}--{ts_time}")

    ctx = TestContext(
        base_url=args.base_url.rstrip("/"),
        me_prefix=args.me_prefix,
        ses=ses,
        timeout=args.timeout,
        out_dir=out_dir,
    )

    print(c(f"\n{ICON_INFO} Start Quiz API tests @ {ctx.base_url}\n", Fore.WHITE))

    ApiTester(ctx).run()

if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("\nPrzerwano.")
        sys.exit(130)
