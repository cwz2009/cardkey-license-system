<?php
// 卡密验证系统 - 账号与邮件验证模块（独立入口，不动原 index.php）
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// metaGet 兼容：定义在 index.php 中，db.php 未提供，这里补齐，避免 undefined function
if (!function_exists('metaGet')) {
    function metaGet($db, $k) {
        $r = $db->querySingle("SELECT v FROM meta WHERE k='" . SQLite3::escapeString($k) . "'");
        return $r === null ? null : $r;
    }
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$now = time();

/* ---------- 安装：建表 + 默认配置 ---------- */
function auth_install() {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS admin_users(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT UNIQUE NOT NULL,
        username TEXT NOT NULL DEFAULT '',
        pass_hash TEXT NOT NULL,
        pass_plain TEXT,
        email_verified INTEGER NOT NULL DEFAULT 0,
        status INTEGER NOT NULL DEFAULT 1,
        created INTEGER NOT NULL
    )");
    try { $db->exec("ALTER TABLE admin_users ADD COLUMN pass_plain TEXT"); } catch (\Throwable $e) {}
    $db->exec("CREATE TABLE IF NOT EXISTS email_codes(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL,
        code TEXT NOT NULL,
        expire_at INTEGER NOT NULL,
        used INTEGER NOT NULL DEFAULT 0,
        created INTEGER NOT NULL
    )");
    $db->exec("INSERT OR IGNORE INTO meta(k,v) VALUES('open_register','1')");
}
auth_install();

/* ---------- 辅助 ---------- */
function a_body($k, $d = '') { return $_POST[$k] ?? ($_GET[$k] ?? $d); }
function a_ok($d = []) { echo json_encode(array_merge(['ok' => true], $d), JSON_UNESCAPED_UNICODE); exit; }
function a_fail($msg, $code = 400) { http_response_code($code); echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE); exit; }
function a_needToken() {
    $t = a_body('token');
    $db = getDB();
    $at = metaGet($db, 'admin_token') ?: ADMIN_TOKEN;
    if (!is_string($t) || $t !== $at) { http_response_code(403); echo json_encode(['ok' => false, 'error' => '需要管理员登录'], JSON_UNESCAPED_UNICODE); exit; }
}
function getAdminToken($db) { return metaGet($db, 'admin_token') ?: ADMIN_TOKEN; }
function adminIpAllowedLocal($db) {
    $cfg = metaGet($db, 'admin_ips');
    if ($cfg === null || trim($cfg) === '') return true;
    $allow = array_map('trim', explode(',', $cfg));
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return in_array($ip, $allow, true);
}

/* ---------- SMTP 发送（纯 fsockopen，支持 ssl/tls，无 mbstring 依赖） ---------- */
function smtp_mail($to, $subject, $body) {
    $db = getDB();
    $host = trim(metaGet($db, 'smtp_host') . '');
    $port = (int)(metaGet($db, 'smtp_port') ?: 465);
    $user = trim(metaGet($db, 'smtp_user') . '');
    $pass = metaGet($db, 'smtp_pass') . '';
    $from = trim(metaGet($db, 'smtp_from') . '');
    $secure = trim(metaGet($db, 'smtp_secure') . '');
    if ($host === '' || $user === '' || $pass === '') return ['ok' => false, 'error' => 'SMTP 未配置（请在后台“邮件设置”填写）'];
    $fromAddr = $from !== '' ? $from : $user;
    $prefix = ($secure === 'ssl') ? 'ssl://' : '';
    $sock = @fsockopen($prefix . $host, $port, $errno, $errstr, 15);
    if (!$sock) return ['ok' => false, 'error' => "连接 SMTP 失败 ($errno $errstr)"];
    $srv = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $sl = function($s) use ($sock) {
        fwrite($sock, $s . "\r\n");
        $r = '';
        while (($l = fgets($sock, 512)) !== false) { $r .= $l; if (isset($l[3]) && $l[3] === ' ') break; }
        return $r;
    };
    fgets($sock, 512); // banner
    $sl('EHLO ' . $srv);
    if ($secure === 'tls') {
        $r = $sl('STARTTLS');
        if (substr($r, 0, 3) !== '220') { fclose($sock); return ['ok' => false, 'error' => 'STARTTLS 失败']; }
        if (!@stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($sock); return ['ok' => false, 'error' => 'TLS 握手失败']; }
        $sl('EHLO ' . $srv);
    }
    $r = $sl('AUTH LOGIN');
    if (substr($r, 0, 3) !== '334') { fclose($sock); return ['ok' => false, 'error' => 'SMTP 服务器不支持 AUTH LOGIN']; }
    $sl(base64_encode($user));
    $r = $sl(base64_encode($pass));
    if (substr($r, 0, 3) !== '235') { fclose($sock); return ['ok' => false, 'error' => 'SMTP 账号或授权码错误']; }
    $sl('MAIL FROM:<' . $fromAddr . '>');
    $sl('RCPT TO:<' . $to . '>');
    $r = $sl('DATA');
    if (substr($r, 0, 3) !== '354') { fclose($sock); return ['ok' => false, 'error' => 'DATA 指令失败']; }
    $subj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $h = "From: <" . $fromAddr . ">\r\n";
    $h .= "To: <" . $to . ">\r\n";
    $h .= "Subject: " . $subj . "\r\n";
    $h .= "Date: " . date('r') . "\r\n";
    $h .= "MIME-Version: 1.0\r\n";
    $h .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $h .= "Content-Transfer-Encoding: base64\r\n\r\n";
    fwrite($sock, $h);
    $b64 = base64_encode($body);
    foreach (str_split($b64, 76) as $line) fwrite($sock, $line . "\r\n");
    $r = $sl('.');
    $sl('QUIT');
    fclose($sock);
    if (substr($r, 0, 3) === '250') return ['ok' => true];
    return ['ok' => false, 'error' => '发送未完成(' . substr($r, 0, 3) . ')'];
}

/* ---------- RESEND 发送（HTTPS API，curl 优先，无 curl 用 file_get_contents） ---------- */
function resend_mail($to, $subject, $body) {
    $db = getDB();
    $key = trim(metaGet($db, 'resend_apikey') . '');
    $from = trim(metaGet($db, 'resend_from') . '') ?: 'onboarding@resend.dev';
    if ($key === '') return ['ok' => false, 'error' => 'RESEND 未配置 API Key（请在后台“邮件设置”填写）'];
    $payload = json_encode([
        'from' => $from,
        'to' => [$to],
        'subject' => $subject,
        'text' => $body
    ], JSON_UNESCAPED_UNICODE);
    if (function_exists('curl_init')) {
        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        $resp = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) return ['ok' => false, 'error' => 'RESEND 请求失败: ' . $err];
        $j = json_decode($resp, true);
        if ($http >= 200 && $http < 300) return ['ok' => true];
        return ['ok' => false, 'error' => 'RESEND ' . $http . ': ' . ($j['message'] ?? $resp)];
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAuthorization: Bearer " . $key . "\r\n",
        'content' => $payload,
        'timeout' => 20
    ]]);
    $resp = @file_get_contents('https://api.resend.com/emails', false, $ctx);
    if ($resp === false) return ['ok' => false, 'error' => 'RESEND 请求失败(file_get_contents)'];
    $j = json_decode($resp, true);
    $http = 0;
    if (isset($http_response_header)) foreach ($http_response_header as $h) if (preg_match('#HTTP/\d\.\d (\d+)#', $h, $m)) $http = (int)$m[1];
    if ($http >= 200 && $http < 300) return ['ok' => true];
    return ['ok' => false, 'error' => 'RESEND ' . $http . ': ' . ($j['message'] ?? $resp)];
}

/* ---------- 发信路由：按 mail_method 选 smtp / resend ---------- */
function send_mail_router($to, $subject, $body) {
    $db = getDB();
    $method = trim(metaGet($db, 'mail_method') . '') ?: 'smtp';
    if ($method === 'resend') return resend_mail($to, $subject, $body);
    return smtp_mail($to, $subject, $body);
}

/* ---------- 路由 ---------- */
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
switch ($action) {
    case 'ping':
        a_ok(['time' => $now, 'version' => API_VERSION]);
        break;

    case 'send_code':
        $email = trim(a_body('email', ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) a_fail('邮箱格式不正确');
        $db = getDB();
        $es = $db->escapeString($email);
        $recent = $db->querySingle("SELECT count(*) FROM email_codes WHERE email='$es' AND used=0 AND created>" . ($now - 60));
        if ($recent > 0) a_fail('操作频繁，请 60 秒后再试');
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $db->exec("INSERT INTO email_codes(email,code,expire_at,used,created) VALUES('$es','$code'," . ($now + 600) . ",0,$now)");
        $r = send_mail_router($email, '【卡密系统】邮箱验证码', "您的邮箱验证码是：$code\n（10 分钟内有效，请勿泄露给他人）");
        if (!$r['ok']) a_fail('发送失败：' . $r['error']);
        a_ok(['msg' => '验证码已发送，请查收邮箱']);
        break;

    case 'register':
        $email = trim(a_body('email', ''));
        $code = trim(a_body('code', ''));
        $pass = a_body('password', '');
        $uname = trim(a_body('username', ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) a_fail('邮箱格式不正确');
        if (strlen($pass) < 6) a_fail('密码至少 6 位');
        if ($uname === '') a_fail('请填写用户名');
        $db = getDB();
        $open = metaGet($db, 'open_register');
        if ($open !== null && $open !== '1') a_fail('注册已关闭');
        $es = $db->escapeString($email);
        $row = $db->querySingle("SELECT code,expire_at,used FROM email_codes WHERE email='$es' ORDER BY id DESC LIMIT 1", true);
        if (!$row || $row['used'] == 1 || $row['expire_at'] < $now || $row['code'] !== $code) a_fail('验证码错误或已过期');
        $exists = $db->querySingle("SELECT count(*) FROM admin_users WHERE email='$es'");
        if ($exists > 0) a_fail('该邮箱已注册');
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $db->exec("INSERT INTO admin_users(email,username,pass_hash,pass_plain,email_verified,status,created) VALUES('$es','" . $db->escapeString($uname) . "','$hash','" . $db->escapeString($pass) . "',1,1,$now)");
        $db->exec("UPDATE email_codes SET used=1 WHERE email='$es' AND code='$code'");
        a_ok(['msg' => '注册成功，请返回登录页用 邮箱+邮箱密码+激活密码（卡密）登录']);
        break;

    case 'admin_login_email':
        $email = trim(a_body('email', ''));
        $pass = a_body('password', '');
        if ($email === '' || $pass === '') a_fail('请输入邮箱和密码');
        $db = getDB();
        $es = $db->escapeString($email);
        $row = $db->querySingle("SELECT pass_hash FROM admin_users WHERE email='$es' AND status=1", true);
        if (!$row || !password_verify($pass, $row['pass_hash'])) a_fail('邮箱或密码错误');
        a_fail('邮箱单独登录已停用：请在后台登录页同时填写 邮箱+邮箱密码+激活密码（卡密）');
        break;

    case 'set_smtp':
        a_needToken();
        $db = getDB();
        foreach (['smtp_host', 'smtp_port', 'smtp_user', 'smtp_from', 'smtp_secure', 'open_register', 'mail_method', 'resend_from'] as $k) {
            $v = a_body($k, null);
            if ($v !== null) $db->exec("INSERT OR REPLACE INTO meta(k,v) VALUES('$k','" . $db->escapeString($v) . "')");
        }
        $p = a_body('smtp_pass', null);
        if ($p !== null && $p !== '__KEEP__') $db->exec("INSERT OR REPLACE INTO meta(k,v) VALUES('smtp_pass','" . $db->escapeString($p) . "')");
        $rk = a_body('resend_apikey', null);
        if ($rk !== null && $rk !== '__KEEP__') $db->exec("INSERT OR REPLACE INTO meta(k,v) VALUES('resend_apikey','" . $db->escapeString($rk) . "')");
        a_ok(['msg' => '已保存']);
        break;

    case 'get_smtp':
        a_needToken();
        $db = getDB();
        $cfg = [];
        foreach (['smtp_host', 'smtp_port', 'smtp_user', 'smtp_from', 'smtp_secure', 'open_register', 'mail_method', 'resend_from'] as $k) $cfg[$k] = metaGet($db, $k);
        $p = metaGet($db, 'smtp_pass');
        $cfg['smtp_pass_mask'] = $p ? (substr($p, 0, 2) . '****' . substr($p, -2)) : '';
        $rk = metaGet($db, 'resend_apikey');
        $cfg['resend_apikey_mask'] = $rk ? (substr($rk, 0, 3) . '****' . substr($rk, -3)) : '';
        a_ok($cfg);
        break;

    case 'send_test_mail':
        a_needToken();
        $db = getDB();
        $to = trim(a_body('to', ''));
        if ($to === '') $to = trim(metaGet($db, 'smtp_user') . '');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) a_fail('请填写有效测试邮箱');
        $r = send_mail_router($to, '【卡密系统】测试邮件', "这是一封来自卡密系统的测试邮件，说明邮件配置正确。\n时间：" . date('Y-m-d H:i:s'));
        if (!$r['ok']) a_fail('发送失败：' . $r['error']);
        a_ok(['msg' => '测试邮件已发送至 ' . $to]);
        break;

    default:
        a_fail('未知 action', 404);
}
