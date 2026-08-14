<?php
// mail.php - 邮件发送纯函数库（index.php / auth.php 共用；无 mbstring 依赖）
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/* 2026-08-07 修复：mail.php 独立使用时缺 metaGet（原定义在 index.php），补内联实现 */
if (!function_exists('metaGet')) {
    function metaGet($db, $k) {
        $r = $db->querySingle("SELECT v FROM meta WHERE k='" . SQLite3::escapeString($k) . "'");
        return ($r === false || $r === null) ? '' : $r;
    }
}

/* ---------- SMTP 发送（纯 fsockopen，支持 ssl/tls） ---------- */
function smtp_mail($to, $subject, $body) {
    $db = getDB();
    $host = trim(metaGet($db, 'smtp_host') . '');
    $port = (int)(metaGet($db, 'smtp_port') ?: 465);
    $user = trim(metaGet($db, 'smtp_user') . '');
    $pass = metaGet($db, 'smtp_pass') . '';
    $from = trim(metaGet($db, 'smtp_from') . '');
    $secure = trim(metaGet($db, 'smtp_secure') . '');
    if ($host === '' || $user === '' || $pass === '') return ['ok' => false, 'error' => 'SMTP 未配置'];
    $fromAddr = $from !== '' ? $from : $user;
    $prefix = ($secure === 'ssl') ? 'ssl://' : '';
    $sock = @fsockopen($prefix . $host, $port, $errno, $errstr, 15);
    if (!$sock) return ['ok' => false, 'error' => "连接 SMTP 失败 ($errno $errstr)"];
    $srv = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $sl = function ($s) use ($sock) {
        fwrite($sock, $s . "\r\n");
        $r = '';
        while (($l = fgets($sock, 512)) !== false) { $r .= $l; if (isset($l[3]) && $l[3] === ' ') break; }
        return $r;
    };
    fgets($sock, 512);
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

/* ---------- 发信路由：按 mail_method 选 smtp / resend ---------- */
function send_mail_router($to, $subject, $body) {
    $db = getDB();
    $method = trim(metaGet($db, 'mail_method') . '') ?: 'smtp';
    if ($method === 'resend') {
        // resend 简化：复用 smtp 配置不存在时提示
        return ['ok' => false, 'error' => 'resend 模式未启用'];
    }
    return smtp_mail($to, $subject, $body);
}
