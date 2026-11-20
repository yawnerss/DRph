<?php
/**
 * PHP Email Sender - Send emails from any website
 * Uses the website's built-in mail function
 */

// ============================================================
// CONFIGURATION
// ============================================================
$DEFAULT_FROM = "admin@" . $_SERVER['HTTP_HOST'];
$DEFAULT_FROM_NAME = "Website Admin";

// ============================================================
// FUNCTIONS
// ============================================================

function send_email($to, $subject, $message, $from = null, $from_name = null, $reply_to = null) {
    global $DEFAULT_FROM, $DEFAULT_FROM_NAME;
    
    // Set defaults
    $from = $from ?: $DEFAULT_FROM;
    $from_name = $from_name ?: $DEFAULT_FROM_NAME;
    $reply_to = $reply_to ?: $from;
    
    // Headers
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: $from_name <$from>\r\n";
    $headers .= "Reply-To: $reply_to\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    $headers .= "X-Priority: 3\r\n";
    
    // Build HTML message
    $html_message = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>$subject</title>
        <style>
            body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 20px; }
            .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            .header { border-bottom: 2px solid #4CAF50; padding-bottom: 15px; margin-bottom: 20px; }
            .header h1 { color: #333; margin: 0; }
            .content { color: #555; line-height: 1.6; }
            .footer { margin-top: 20px; padding-top: 15px; border-top: 1px solid #ddd; font-size: 12px; color: #999; text-align: center; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>$subject</h1>
            </div>
            <div class='content'>
                " . nl2br(htmlspecialchars($message)) . "
            </div>
            <div class='footer'>
                Sent from " . htmlspecialchars($_SERVER['HTTP_HOST']) . "
            </div>
        </div>
    </body>
    </html>
    ";
    
    // Try to send
    if (mail($to, $subject, $html_message, $headers)) {
        return ['success' => true, 'message' => 'Email sent successfully!'];
    } else {
        return ['success' => false, 'message' => 'Failed to send email. Check server configuration.'];
    }
}

function test_smtp_connection($host = null, $port = null, $username = null, $password = null) {
    // Test SMTP connection if configured
    if ($host && $port) {
        $fp = @fsockopen($host, $port, $errno, $errstr, 5);
        if ($fp) {
            fclose($fp);
            return ['success' => true, 'message' => "SMTP server $host:$port is reachable"];
        } else {
            return ['success' => false, 'message' => "Cannot reach SMTP server $host:$port: $errstr"];
        }
    }
    return ['success' => true, 'message' => 'Using PHP mail() function'];
}

function get_email_headers($email) {
    // Validate email format
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function get_domain() {
    return $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
}

// ============================================================
// HANDLE REQUESTS
// ============================================================

$action = isset($_GET['action']) ? $_GET['action'] : '';
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send'])) {
    $to = isset($_POST['to']) ? trim($_POST['to']) : '';
    $subject = isset($_POST['subject']) ? trim($_POST['subject']) : 'Test Email';
    $message = isset($_POST['message']) ? trim($_POST['message']) : '';
    $from = isset($_POST['from']) ? trim($_POST['from']) : null;
    $from_name = isset($_POST['from_name']) ? trim($_POST['from_name']) : null;
    
    if ($to && $message) {
        $result = send_email($to, $subject, $message, $from, $from_name);
    } else {
        $result = ['success' => false, 'message' => 'Please fill in recipient and message'];
    }
}

// ============================================================
// WEB INTERFACE
// ============================================================
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Email Sender</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: #0a0a0a;
            color: #00ff00;
            font-family: 'Courier New', monospace;
            padding: 20px;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .container {
            max-width: 600px;
            width: 100%;
            background: #0f141f;
            border: 1px solid #1e2a3a;
            border-radius: 10px;
            padding: 30px;
        }
        .header {
            text-align: center;
            border-bottom: 1px solid #1e2a3a;
            padding-bottom: 20px;
            margin-bottom: 25px;
        }
        .header h1 {
            color: #00ff00;
            font-size: 24px;
            font-weight: normal;
        }
        .header .sub {
            color: #4a627a;
            font-size: 12px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            color: #4a627a;
            font-size: 12px;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .form-group input, .form-group textarea {
            width: 100%;
            background: #0a0e17;
            border: 1px solid #1e2a3a;
            color: #00ff00;
            padding: 10px 15px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            border-radius: 5px;
        }
        .form-group input:focus, .form-group textarea:focus {
            outline: none;
            border-color: #00ff00;
        }
        .form-group textarea {
            min-height: 120px;
            resize: vertical;
        }
        .form-row {
            display: flex;
            gap: 15px;
        }
        .form-row .form-group {
            flex: 1;
        }
        button {
            width: 100%;
            background: #00ff00;
            color: #0a0e17;
            border: none;
            padding: 12px;
            font-family: 'Courier New', monospace;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            border-radius: 5px;
            transition: 0.3s;
        }
        button:hover {
            background: #00cc00;
        }
        .result {
            margin-top: 15px;
            padding: 10px 15px;
            border-radius: 5px;
            font-size: 14px;
        }
        .result.success {
            background: rgba(0, 255, 0, 0.1);
            border: 1px solid #00ff00;
            color: #00ff00;
        }
        .result.error {
            background: rgba(255, 51, 51, 0.1);
            border: 1px solid #ff3333;
            color: #ff3333;
        }
        .warning {
            background: rgba(255, 170, 0, 0.1);
            border: 1px solid #ffaa00;
            color: #ffaa00;
            padding: 10px;
            border-radius: 5px;
            font-size: 12px;
            margin-top: 15px;
            text-align: center;
        }
        .domain-info {
            background: #0a0e17;
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 12px;
            color: #4a627a;
            border: 1px solid #1e2a3a;
        }
        .domain-info strong {
            color: #00ff00;
        }
        .quick-presets {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 15px;
        }
        .quick-presets button {
            width: auto;
            font-size: 11px;
            padding: 5px 12px;
            background: #1e2a3a;
            color: #4a627a;
        }
        .quick-presets button:hover {
            color: #00ff00;
            background: #2a3a4a;
        }
        @media (max-width: 768px) {
            .form-row { flex-direction: column; }
            .container { padding: 20px; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>📧 Email Sender</h1>
        <div class="sub">Send emails using this website's mail server</div>
    </div>

    <div class="domain-info">
        🌐 Domain: <strong><?= htmlspecialchars(get_domain()) ?></strong>
        <br>📤 Default From: <strong><?= htmlspecialchars($DEFAULT_FROM) ?></strong>
    </div>

    <div class="quick-presets">
        <button onclick="fillPreset('admin@example.com', 'Test Email', 'This is a test email from the server.')">Test</button>
        <button onclick="fillPreset('user@example.com', 'Welcome!', 'Welcome to our website!\n\nWe hope you enjoy your visit.')">Welcome</button>
        <button onclick="fillPreset('contact@example.com', 'Contact Form', 'You have received a new message from your contact form.')">Contact</button>
    </div>

    <?php if ($result): ?>
        <div class="result <?= $result['success'] ? 'success' : 'error' ?>">
            <?= htmlspecialchars($result['message']) ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-row">
            <div class="form-group">
                <label>From Email</label>
                <input type="email" name="from" value="<?= htmlspecialchars($DEFAULT_FROM) ?>">
            </div>
            <div class="form-group">
                <label>From Name</label>
                <input type="text" name="from_name" value="Website Admin">
            </div>
        </div>

        <div class="form-group">
            <label>To Email *</label>
            <input type="email" name="to" placeholder="recipient@example.com" required>
        </div>

        <div class="form-group">
            <label>Subject</label>
            <input type="text" name="subject" placeholder="Email subject" value="Test Email">
        </div>

        <div class="form-group">
            <label>Message *</label>
            <textarea name="message" placeholder="Your message here...">This is a test email sent from the server.</textarea>
        </div>

        <button type="submit" name="send">📨 Send Email</button>
    </form>

    <div class="warning">
        ⚠️ For authorized testing only. Use responsibly.
        <br>Emails may be rate-limited by your hosting provider.
    </div>
</div>

<script>
function fillPreset(to, subject, message) {
    document.querySelector('input[name="to"]').value = to;
    document.querySelector('input[name="subject"]').value = subject;
    document.querySelector('textarea[name="message"]').value = message;
}
</script>
</body>
</html>