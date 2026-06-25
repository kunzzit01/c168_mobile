<?php
/**
 * 重置密码 - 发送 TAC 验证码
 * POST: company_id (string), email
 * 根据公司 ID 与邮箱查找用户，生成 6 位 TAC，入库并发送邮件
 * 若 config 中配置了 SMTP（如 Gmail），则走 SMTP 发信，否则用 mail()
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/email_validation.php';

/**
 * 通过 SMTP (SSL 465) 发送邮件，支持 Gmail
 * @return bool
 */
function sendMailSmtp($to, $subject, $bodyText, $fromEmail, $fromName, $host, $port, $user, $pass) {
    $fromEmail = trim($fromEmail);
    $fromName = trim($fromName);
    $msg = "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
         . "From: " . (strlen($fromName) ? "\"{$fromName}\" <{$fromEmail}>" : $fromEmail) . "\r\n"
         . "To: {$to}\r\n"
         . "Content-Type: text/plain; charset=UTF-8\r\n"
         . "Content-Transfer-Encoding: base64\r\n\r\n"
         . chunk_split(base64_encode($bodyText));
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client(
        "ssl://{$host}:{$port}",
        $errno,
        $errstr,
        15,
        STREAM_CLIENT_CONNECT,
        stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]])
    );
    if (!$fp) {
        error_log("sendMailSmtp: connect failed {$host}:{$port} - {$errstr}");
        return false;
    }
    $read = function() use ($fp) {
        $line = @fgets($fp, 512);
        return $line !== false ? trim($line) : '';
    };
    $send = function($cmd) use ($fp) { @fwrite($fp, $cmd . "\r\n"); };
    $read();
    $send("EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    while ($line = $read()) { if (preg_match('/^\d{3}\s/', $line) && substr($line, 3, 1) === ' ') break; }
    $send("AUTH LOGIN");
    $read();
    $send(base64_encode($user));
    $read();
    $send(base64_encode($pass));
    if (strpos($read(), '235') !== 0) { fclose($fp); return false; }
    $send("MAIL FROM:<" . $fromEmail . ">");
    $read();
    $send("RCPT TO:<" . $to . ">");
    $read();
    $send("DATA");
    $read();
    $send($msg);
    $send(".");
    $read();
    $send("QUIT");
    fclose($fp);
    return true;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $company_id_raw = trim($input['company_id'] ?? $_POST['company_id'] ?? '');
    $emailValidation = validate_email($input['email'] ?? $_POST['email'] ?? '');

    if (!$company_id_raw || !$emailValidation['normalized']) {
        echo json_encode(['success' => false, 'message' => 'Company ID and email are required']);
        exit;
    }
    if (!$emailValidation['ok']) {
        echo json_encode(['success' => false, 'message' => 'Invalid email format']);
        exit;
    }

    $company_id_upper = strtoupper($company_id_raw);
    $email = $emailValidation['normalized'];
    $email_lower = $email;

    // 1) 检查是否为普通用户（支持 Company ID 或 Group ID）
    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.email, c.id AS company_numeric_id
        FROM user u
        INNER JOIN user_company_map ucm ON u.id = ucm.user_id
        INNER JOIN company c ON ucm.company_id = c.id
        WHERE u.email = ? AND (UPPER(c.company_id) = ? OR UPPER(c.group_id) = ?) AND u.status = 'active'
        ORDER BY c.id ASC
        LIMIT 1
    ");
    $stmt->execute([$email, $company_id_upper, $company_id_upper]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $is_owner_reset = false;
    $owner_id = null;
    $company_numeric_id = null;

    if ($user) {
        $company_numeric_id = (int) $user['company_numeric_id'];
    } else {
        // 2) 如果不是普通用户，将输入视为 Owner Code，按 owner_code + email 查 owner
        $stmt = $pdo->prepare("
            SELECT id, name, email FROM owner
            WHERE UPPER(owner_code) = ? AND LOWER(TRIM(email)) = ?
            LIMIT 1
        ");
        $stmt->execute([$company_id_upper, $email_lower]);
        $owner = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$owner) {
            echo json_encode(['success' => false, 'message' => 'No active user found for this email in the selected company/group. For owner reset, enter your Owner Code in the Company ID field.']);
            exit;
        }
        $is_owner_reset = true;
        $owner_id = (int) $owner['id'];
    }

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expires_at = date('Y-m-d H:i:s', time() + 900); // 15 分钟

    if ($is_owner_reset) {
        // Owner TAC 表（若不存在则创建）
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS password_reset_tac_owner (
                email VARCHAR(255) NOT NULL,
                owner_id INT UNSIGNED NOT NULL,
                code VARCHAR(10) NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (email, owner_id)
            )
        ");
        $stmt = $pdo->prepare("
            INSERT INTO password_reset_tac_owner (email, owner_id, code, expires_at, created_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE code = ?, expires_at = ?, created_at = NOW()
        ");
        $stmt->execute([$email_lower, $owner_id, $code, $expires_at, $code, $expires_at]);
    } else {
        // User TAC 表（若不存在则创建）
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS password_reset_tac (
                email VARCHAR(255) NOT NULL,
                company_id INT NOT NULL,
                code VARCHAR(10) NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (email, company_id)
            )
        ");
        $stmt = $pdo->prepare("
            INSERT INTO password_reset_tac (email, company_id, code, expires_at, created_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE code = ?, expires_at = ?, created_at = NOW()
        ");
        $stmt->execute([$email, $company_numeric_id, $code, $expires_at, $code, $expires_at]);
    }

    // 发送邮件：优先 SMTP（Gmail 等），未配置则 mail()
    $subject = 'EazyCount - Password Reset TAC';
    $body = "Your verification code is: {$code}\n\nValid for 15 minutes.\n\nIf you did not request this, please ignore this email.";
    $sent = false;
    if (!empty($smtp_host) && !empty($smtp_user)) {
        $smtp_from = !empty($smtp_from_email) ? $smtp_from_email : $smtp_user;
        $sent = sendMailSmtp($email, $subject, $body, $smtp_from, $smtp_from_name ?? '', $smtp_host, (int)($smtp_port ?? 465), $smtp_user, $smtp_pass ?? '');
        if (!$sent) {
            error_log("send_reset_tac_api: SMTP send failed for {$email}");
        }
    }
    if (!$sent) {
        $headers = "From: noreply@eazycount.com\r\nReply-To: noreply@eazycount.com\r\nContent-Type: text/plain; charset=UTF-8";
        $sent = @mail($email, $subject, $body, $headers);
    }

    $out = ['success' => true, 'message' => 'TAC has been sent to your email. Please check your inbox (and spam folder).'];
    if (!$sent) {
        error_log("send_reset_tac_api: mail/SMTP failed for {$email}, code was saved.");
        $out['message'] = 'Email may not have been delivered (server mail not configured). Your verification code is below. '
            . 'To enable sending to Gmail: in config.php set $smtp_host=\'smtp.gmail.com\', $smtp_port=465, $smtp_user and $smtp_pass (use Gmail App Password).';
        $out['tac'] = $code;
    }
    echo json_encode($out);
} catch (Exception $e) {
    error_log("send_reset_tac_api: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to send TAC. Please try again or contact support.']);
}
