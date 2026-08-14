<?php

// 卡密验证系统 - API 入口（阶段1：轮询）

require_once __DIR__ . '/config.php';

require_once __DIR__ . '/db.php';

require_once __DIR__ . '/mail.php';



header('Content-Type: application/json; charset=utf-8');

header('Access-Control-Allow-Origin: *');   // demo 跨域；同机部署可删

header('Cache-Control: no-store');           // 禁缓存，保证实时

header('X-Content-Type-Options: nosniff');



$action = $_GET['action'] ?? ($_POST['action'] ?? '');

$now = time();



// 启动时确保表结构齐全（先查列是否存在再 ALTER，避免 SQLite3::exec 失败刷 Warning/性能损耗）
function ensureColumn($db, $table, $col, $ddl) {
    $cols = [];
    try {
        $r = $db->query("PRAGMA table_info($table)");
        if ($r) { while ($x = $r->fetchArray(SQLITE3_ASSOC)) $cols[] = $x['name']; }
    } catch (\Throwable $e) { return; }
    if (!in_array($col, $cols, true)) {
        try { $db->exec($ddl); } catch (\Throwable $e) {}
    }
}
try {

    $db0 = getDB();

    // 先建 sessions/oplog 表（ensureColumn 依赖表存在）
    try { $db0->exec('CREATE TABLE IF NOT EXISTS sessions (token TEXT PRIMARY KEY, card_id INTEGER NOT NULL, email TEXT NOT NULL DEFAULT \'\', created_at INTEGER NOT NULL)'); } catch (\Throwable $e) {}
    try { $db0->exec('CREATE TABLE IF NOT EXISTS oplog (id INTEGER PRIMARY KEY AUTOINCREMENT, ts INTEGER NOT NULL, who TEXT, action TEXT, detail TEXT)'); } catch (\Throwable $e) {}

    // 确保各表列齐全（先查列再 ALTER）
    ensureColumn($db0, 'cards', 'sess', 'ALTER TABLE cards ADD COLUMN sess TEXT');
    ensureColumn($db0, 'cards', 'owner', 'ALTER TABLE cards ADD COLUMN owner TEXT');
    ensureColumn($db0, 'cards', 'sess_email', 'ALTER TABLE cards ADD COLUMN sess_email TEXT');
    ensureColumn($db0, 'admin_users', 'pass_plain', 'ALTER TABLE admin_users ADD COLUMN pass_plain TEXT');
    ensureColumn($db0, 'admin_users', 'card_key', 'ALTER TABLE admin_users ADD COLUMN card_key TEXT');
    ensureColumn($db0, 'sessions', 'login_ip', 'ALTER TABLE sessions ADD COLUMN login_ip TEXT');
    ensureColumn($db0, 'sessions', 'last_active', 'ALTER TABLE sessions ADD COLUMN last_active INTEGER DEFAULT 0');
    ensureColumn($db0, 'cards', 'max_uses', 'ALTER TABLE cards ADD COLUMN max_uses INTEGER NOT NULL DEFAULT 1');
    ensureColumn($db0, 'cards', 'used_list', "ALTER TABLE cards ADD COLUMN used_list TEXT NOT NULL DEFAULT ''");
    ensureColumn($db0, 'cards', 'ctype', "ALTER TABLE cards ADD COLUMN ctype TEXT NOT NULL DEFAULT 'card'");
    ensureColumn($db0, 'cards', 'expiry_notified', 'ALTER TABLE cards ADD COLUMN expiry_notified INTEGER NOT NULL DEFAULT 0');

    // 历史数据归位：管理员名下（owner 为空）的一律视为激活密码
    try { $db0->exec("UPDATE cards SET ctype='act' WHERE owner IS NULL OR owner=''"); } catch (\Throwable $e) {}

    // 旧单槽会话平滑迁移到多会话表（token 唯一，重复执行无碍）
    try { $db0->exec("INSERT OR IGNORE INTO sessions(token, card_id, email, created_at) SELECT sess, id, COALESCE(sess_email,''), " . time() . " FROM cards WHERE sess IS NOT NULL AND sess<>'' "); } catch (\Throwable $e) {}

} catch (\Throwable $e) {}



/* ---------- 辅助 ---------- */

function tokenVal($db, $k, $fallback) {

    // 2026-08-07 审计修复：$k 可能含用户输入（如 gate_code 的 app 参数），未转义直接拼 SQL 可注入
    // meta 表（读取 admin_token/user_token 等敏感值）。统一在此处对 key 做转义，堵住所有调用点。
    $r = $db->querySingle("SELECT v FROM meta WHERE k='" . SQLite3::escapeString($k) . "'");

    // 2026-08-07 审计修复：SQLite3::querySingle 无行时返回 false 而非 null。
    // 原判断 `$r === null` 导致 meta 无值时返回 false 而非 $fallback → getUserToken() 在 meta 无 user_token
    // 时返回 false，共享账号（USER_PASS 登录）拿到 token=false 后所有接口 401，实际无法使用；
    // 同理 notify_activate/latest_version 等默认值也全被误判为 0。统一按 false/null 兜底。
    return ($r === false || $r === null) ? $fallback : $r;

}

function getToken($db = null) {

    if (!$db) $db = getDB();

    return tokenVal($db, 'admin_token', ADMIN_TOKEN);

}

function getUserToken($db = null) {

    if (!$db) $db = getDB();

    return tokenVal($db, 'user_token', USER_TOKEN_DEFAULT);

}

// 根据会话令牌解析角色：admin / user / null（未登录）

function roleOf($db, $tok) {

    if (is_string($tok) && $tok !== '' && $tok === getToken($db)) return 'admin';

    if (is_string($tok) && $tok !== '' && $tok === getUserToken($db)) return 'user';

    // 卡密绑定的会话令牌（客户用激活密码登录后持有）；账号被删/停用 → 会话视为无效。记住账号即永久有效（2026-08-06 取消 72h/IP 失效）
    if (is_string($tok) && $tok !== '') {
        $srow = $db->querySingle("SELECT 1 FROM sessions s JOIN admin_users a ON a.email = s.email AND a.status = 1 WHERE s.token='" . SQLite3::escapeString($tok) . "'", true);

        if ($srow) return 'user';

    }

    return null;

}

function reqToken() {

    return $_POST['token'] ?? ($_GET['token'] ?? '');

}

// 数据归属过滤片段（delete/batch_delete 用）：返回附加到 WHERE 的 SQL 片段
// 管理员 -> " AND (owner IS NULL OR owner='')"（管理员生成的激活密码）
// 普通用户 -> " AND owner='邮箱'"; 共享 user_token（无邮箱）-> 返回 null（调用方 fail 403）
function scopeWhere($db, $tok) {

    $role = roleOf($db, $tok);

    // 2026-08-07 审计修复：管理员 generate 的卡已统一写入 owner='__shared__'（2026-08-06 合并），
    // 原 scopeWhere 管理员分支只匹配 (owner IS NULL OR owner='')，导致管理员删不掉自己生成的卡
    // （delete/batch_delete 一律 403「卡密不存在或无权操作」）。改为兼容三类历史/当前 owner。
    if ($role === 'admin') return " AND (owner='__shared__' OR owner IS NULL OR owner='')";

    $srow = $db->querySingle("SELECT email FROM sessions WHERE token='" . SQLite3::escapeString($tok) . "'", true);

    if ($srow && !empty($srow['email'])) return " AND owner='" . $db->escapeString($srow['email']) . "'";

    return " AND owner='__shared__'";   // 共享 user_token：可删共享空间的卡

}

/* 验证端归属校验（activate/verify/verify_card 共用）：
   验证端带注入者 owner，激活时必须用该注入者自己生成的卡。
   - owner 空（旧版注入/直接打开 gate.html）→ 不限（兼容）
   - owner='admin'（管理员注入的端）→ 不限（管理员端可用任何卡，方便全局测试/分发）
   - owner='__shared__'（共享端）→ 只能用共享空间的卡
   - owner=<邮箱>（普通用户端）→ 只能用该用户自己生成的卡（owner=邮箱）
   返回 SQL 片段（含前导 AND）或空串。 */
function ownerScopeSql($owner) {

    $owner = trim((string)$owner);

    if ($owner === '' || $owner === 'admin') return '';

    if ($owner === '__shared__') return " AND owner='__shared__'";

    return " AND owner='" . SQLite3::escapeString($owner) . "'";

}

function needToken() {

    $t = reqToken();

    if (!is_string($t) || $t !== getToken()) {

        http_response_code(403);

        echo json_encode(['ok' => false, 'error' => '需要管理员登录'], JSON_UNESCAPED_UNICODE);

        exit;

    }

}

// 已登录（管理员或普通用户）方可调用；未登录拒绝

function requireLogin() {

    $t = reqToken();

    if (!is_string($t) || $t === '' || roleOf(getDB(), $t) === null) {

        http_response_code(401);

        echo json_encode(['ok' => false, 'error' => '请先登录'], JSON_UNESCAPED_UNICODE);

        exit;

    }

}

// 管理员登录 IP 白名单：仅允许名单内的 IP 以管理员身份登录（密码对也不行）

// meta.admin_ips 为空时视为“未配置”→ 不限制（fail-open，便于首次配置）；配置后严格校验。

function adminIpAllowed($db) {

    $cfg = metaGet($db, 'admin_ips');

    if ($cfg === null || trim($cfg) === '') return true;

    $allow = array_map('trim', explode(',', $cfg));

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    return in_array($ip, $allow, true);

}

function metaGet($db, $k) {

    $row = $db->querySingle("SELECT v FROM meta WHERE k='" . SQLite3::escapeString($k) . "'", true);

    return $row ? $row['v'] : null;

}

function metaSet($db, $k, $v) {

    $db->exec("INSERT INTO meta(k,v) VALUES('" . SQLite3::escapeString($k) . "','" . SQLite3::escapeString($v) . "') ON CONFLICT(k) DO UPDATE SET v='" . SQLite3::escapeString($v) . "'");

}

// 把有效期秒数格式化为人类可读标签（0 表示永久）

function validityLabel($secs) {

    if ($secs == 0) return '永久';

    if ($secs % 86400 === 0) return ((int)($secs / 86400)) . ' 天';

    if ($secs % 3600 === 0)  return ((int)($secs / 3600)) . ' 小时';

    if ($secs % 60 === 0)    return ((int)($secs / 60)) . ' 分钟';

    return $secs . ' 秒';

}

function clientLocation($ip) {

    if ($ip === '127.0.0.1' || $ip === '::1' || preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $ip)) return '内网/本地';

    $url = 'http://ip-api.com/json/' . urlencode($ip) . '?fields=country,regionName,city&lang=zh-CN';

    $ctx = stream_context_create(['http' => ['timeout' => 1.2, 'ignore_errors' => true]]);

    try { $j = @file_get_contents($url, false, $ctx); } catch (\Throwable $e) { $j = false; }

    if ($j) {

        $d = json_decode($j, true);

        if (is_array($d) && isset($d['city'])) return trim(($d['country'] ?? '') . ' ' . ($d['regionName'] ?? '') . ' ' . ($d['city'] ?? ''));

    }

    return '未知地区';

}

function logLogin($action, $detail = '', $device = '') {

    $db = getDB();

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $location = clientLocation($ip);

    $st = $db->prepare('INSERT INTO login_logs (ip, action, detail, ua, device, location, at) VALUES (?, ?, ?, ?, ?, ?, ?)');

    $st->bindValue(1, $ip);

    $st->bindValue(2, $action);

    $st->bindValue(3, $detail);

    $st->bindValue(4, $ua);

    $st->bindValue(5, $device);

    $st->bindValue(6, $location);

    $st->bindValue(7, time());

    $st->execute();

}

function ok($data = []) {

    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE);

    exit;

}

function fail($msg, $code = 400) {

    http_response_code($code);

    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);

    exit;

}

function getBody($k, $default = '') {

    // 优先 POST，回退 GET（兼容 version_check 等以 GET 传参的接口）

    return $_POST[$k] ?? ($_GET[$k] ?? $default);

}

// 惰性过期：把已激活且已超过 expires_at 的卡转为 expired 并销毁卡密字符串（R019 到期自动上锁+销毁）

function expireDueCards($db, $now) {

    $c = $db->querySingle("SELECT COUNT(*) FROM cards WHERE status='active' AND expires_at>0 AND expires_at<$now");

    if ($c > 0) {

        $db->exec("UPDATE cards SET status='expired', card_key='[已销毁]' WHERE status='active' AND expires_at>0 AND expires_at<$now");

    }

}

/* ---------- 管理员操作日志（oplog 表，保留最近 500 条） ---------- */

function oplog($db, $action, $detail = '') {

    try {

        $who = ($_SERVER['REMOTE_ADDR'] ?? '') . ' ';

        $t = reqToken();

        $role = roleOf($db, $t);

        $who .= ($role === 'admin') ? 'admin' : (($role === 'user') ? 'user' : '-');

        $st = $db->prepare('INSERT INTO oplog (ts, who, action, detail) VALUES (?,?,?,?)');

        $st->bindValue(1, time());

        $st->bindValue(2, $who);

        $st->bindValue(3, $action);

        $st->bindValue(4, $detail);

        $st->execute();

        $db->exec("DELETE FROM oplog WHERE id NOT IN (SELECT id FROM oplog ORDER BY id DESC LIMIT 500)");

    } catch (\Throwable $e) {}

}

/* ---------- 激活邮件通知（发到管理员绑定邮箱；60 秒节流防刷屏） ---------- */

function notifyActivate($db, $key, $count = 1, $who = '', $owner = '', $expires = 0) {

    try {

        // 按卡密主人（owner）隔离通知：普通用户生成的卡被激活 → 发到该用户自己的邮箱；
        // owner 为空（管理员卡）或共享卡 → 走全局设置（管理员配置的收件箱）。
        $perOwner = ($owner !== '' && strpos($owner, '@') !== false);

        $pfx = $perOwner ? '_' . $owner : '';

        $on = (int)tokenVal($db, 'notify_activate' . $pfx, 1);   // 默认开启（管理员与普通用户一致）

        if ($on !== 1) return;

        $to = trim(tokenVal($db, 'notify_email' . $pfx, ''));

        if ($to === '') $to = $perOwner ? $owner : trim(tokenVal($db, 'smtp_from', ''));

        if ($to === '') return;

        $lastKey = 'notify_last_ts' . $pfx;

        $last = (int)tokenVal($db, $lastKey, 0);

        if (time() - $last < 60) return;

        metaSet($db, $lastKey, (string)time());

        // 有效期文案：expires=0 永久；>0 显示剩余时长与到期日
        $expTxt = '';
        if ($count <= 1 && $expires !== 0) {
            if ($expires > 0) {
                $days = (int)ceil(($expires - time()) / 86400);
                $expTxt = '有效期：' . ($days > 0 ? $days . ' 天' : '今日到期') . '（至 ' . date('Y-m-d', $expires) . '）' . "\n";
            }
        }

        $body = '【卡密激活提醒】' . "\n\n" .

            ($count > 1
                ? ('📢 您的 ' . $count . ' 张卡密刚刚被激活' . "\n")
                : ('📢 您的卡密 ' . $key . ' 刚刚被激活' . "\n")) .

            '激活时间：' . date('Y-m-d H:i:s') . "\n" .

            $expTxt .

            ($who !== '' ? '激活 IP：' . $who . "\n" : '') .

            "\n如非您本人操作，请留意。" . "\n";

        @send_mail_router($to, '【卡密激活提醒】' . ($count > 1 ? $count . ' 张' : $key), $body);

    } catch (\Throwable $e) {}

}

/* ---------- 到期提醒：到期前 3 天给卡主(owner)+买家发提醒（24h 惰性检查一次，发过不再发） ---------- */

function expiryCheck($db) {

    try {

        $lc = (int)tokenVal($db, 'expiry_last_check', 0);

        if (time() - $lc < 86400) return;

        metaSet($db, 'expiry_last_check', (string)time());

        $limit = time() + 3 * 86400;

        $q = $db->query("SELECT id, card_key, expires_at, owner FROM cards WHERE status='active' AND expires_at>0 AND expires_at<$limit AND expiry_notified=0");

        if ($q) while ($row = $q->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;

        foreach ($rows as $row) {

            $days = (int)ceil(($row['expires_at'] - time()) / 86400);

            $txt = "您好：\n\n您使用的卡密 " . $row['card_key'] . " 将于 " . $days . " 天后到期（" . date('Y-m-d H:i', $row['expires_at']) . "）。\n请及时续费，以免影响使用。\n\n——卡密验证系统";

            // 发给买家（使用者的登录邮箱）
            $em = $db->querySingle("SELECT email FROM sessions WHERE card_id=" . (int)$row['id'] . " ORDER BY created_at DESC LIMIT 1");

            if ($em && filter_var($em, FILTER_VALIDATE_EMAIL)) {

                @send_mail_router($em, '【卡密到期提醒】您的卡密即将到期', $txt);

            }

            // 2026-08-14 需求：同时发给卡主（生成卡密的人），让他知道有卡快到期、可跟进续费
            $own = trim((string)$row['owner']);

            if ($own !== '' && strpos($own, '@') !== false) {

                @send_mail_router($own, '【卡密到期提醒】您生成的卡密即将到期', "您好：\n\n您生成的卡密 " . $row['card_key'] . " 将于 " . $days . " 天后到期（" . date('Y-m-d H:i', $row['expires_at']) . "）。\n如需续费跟进请及时联系用户。\n\n——卡密验证系统");

            }

            $db->exec("UPDATE cards SET expiry_notified=1 WHERE id=" . (int)$row['id']);

        }

    } catch (\Throwable $e) {}

}

/* ---------- 到期后提醒：卡已过期时通知卡主（owner）；expiry_notified2 防重复（24h 惰性检查） ---------- */

function expiredNotify($db) {

    try {

        // 幂等补列（旧库可能没有该字段）
        $cols = [];

        $pq = $db->query('PRAGMA table_info(cards)');

        if ($pq) while ($pc = $pq->fetchArray(SQLITE3_ASSOC)) $cols[] = $pc['name'];

        if (!in_array('expiry_notified2', $cols, true)) {

            $db->exec('ALTER TABLE cards ADD COLUMN expiry_notified2 INTEGER DEFAULT 0');

        }

        $lc2 = (int)tokenVal($db, 'expiry_last_check2', 0);

        if (time() - $lc2 < 86400) return;

        metaSet($db, 'expiry_last_check2', (string)time());

        $q = $db->query("SELECT id, card_key, expires_at, owner FROM cards WHERE status='active' AND expires_at>0 AND expires_at<" . time() . " AND expiry_notified2=0");

        if ($q) while ($row = $q->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;

        foreach ($rows as $row) {

            $own = trim((string)$row['owner']);

            if ($own !== '' && strpos($own, '@') !== false) {

                @send_mail_router($own, '【卡密到期通知】您生成的卡密已到期', "您好：\n\n您生成的卡密 " . $row['card_key'] . " 已于 " . date('Y-m-d H:i', $row['expires_at']) . " 到期。\n如用户需要续费，请为其生成新卡或延期。\n\n——卡密验证系统");

            }

            $db->exec("UPDATE cards SET expiry_notified2=1 WHERE id=" . (int)$row['id']);

        }

    } catch (\Throwable $e) {}

}



/* ---------- 路由 ---------- */

// 到期邮件提醒惰性检查（24h 一次，任意请求触发，轻量）
try { expiryCheck(getDB()); } catch (\Throwable $e) {}
// 到期后通知卡主（24h 一次）
try { expiredNotify(getDB()); } catch (\Throwable $e) {}

switch ($action) {

    case 'ping':

        ok(['version' => API_VERSION, 'time' => $now]);

        break;



    case 'login':

        // 统一登录：管理员在“激活密码”框输入管理员密码即可（其余留空）；普通用户需 邮箱+邮箱密码+激活密码 三者同时。

        $pass = trim(getBody('pass', ''));          // 激活密码（卡密）框
        $email = trim(getBody('email', ''));        // 注册邮箱
        $emailPwd = getBody('email_pwd', '');       // 注册密码

        // 服务端"IP 记住账号"（2026-08-06）：勾选记录账号 → 后端按访问 IP 记录登录态，
        // 之后该 IP 再来直接放行（绕开浏览器 localStorage/cookie 被禁导致"每次都要重输"的问题）
        $remember = (int)getBody('remember', 0);
        $loginIp0 = $_SERVER['REMOTE_ADDR'] ?? '';

        // 服务端"设备 Cookie 记住"（2026-08-06 增强）：登录时下发长期 Cookie（30 天），
        // 与 IP 无关——换网络/IP 变化也不影响，只要浏览器 Cookie 在就免登
        $devToken = null;
        if ($remember) { $devToken = bin2hex(random_bytes(16)); }

        if ($pass === '') fail('请输入激活密码（卡密）', 400);

        $db = getDB();

        // 仅当邮箱/邮箱密码都留空时，才允许"纯密码"通道（管理员密码 / 共享账号密码 123123）
        if ($email === '' && $emailPwd === '') {

            if ($pass === ADMIN_PASS) {

                if (!adminIpAllowed($db)) { logLogin('login_blocked', 'role=admin ip=' . ($_SERVER['REMOTE_ADDR'] ?? '')); http_response_code(403); echo json_encode(['ok' => false, 'error' => '该 IP 无管理员登录权限'], JSON_UNESCAPED_UNICODE); exit; }

                if ($remember && $loginIp0 !== '') $db->exec("INSERT OR REPLACE INTO meta (k,v) VALUES ('remember_ip_" . $db->escapeString($loginIp0) . "', '" . SQLite3::escapeString(getToken($db)) . "')");

                if ($devToken) { setcookie('ck_dev', $devToken, time() + 2592000, '/'); $db->exec("INSERT OR REPLACE INTO meta (k,v) VALUES ('dev_" . $db->escapeString($devToken) . "', '" . SQLite3::escapeString(getToken($db)) . "')"); }

                logLogin('login', 'role=admin'); ok(['ok' => true, 'role' => 'admin', 'token' => getToken($db)]);

            }

            if ($pass === USER_PASS)  { if ($remember && $loginIp0 !== '') $db->exec("INSERT OR REPLACE INTO meta (k,v) VALUES ('remember_ip_" . $db->escapeString($loginIp0) . "', '" . SQLite3::escapeString(getUserToken($db)) . "')"); if ($devToken) { setcookie('ck_dev', $devToken, time() + 2592000, '/'); $db->exec("INSERT OR REPLACE INTO meta (k,v) VALUES ('dev_" . $db->escapeString($devToken) . "', '" . SQLite3::escapeString(getUserToken($db)) . "')"); } logLogin('login', 'role=user');  ok(['ok' => true, 'role' => 'user',  'token' => getUserToken($db)]); }

            fail('请输入邮箱、邮箱密码与激活密码（三者缺一不可）', 400);

        }

        // —— 账号路径：只要填了邮箱，就必须"账号已注册 + 邮箱密码正确 + 激活密码有效"三者同时满足；
        //    任何密码（包括管理员密码 / 123123）都不能绕过账号校验 —— 账号被删除/未注册 → 登录失败（安全加固）
        if ($email === '' || $emailPwd === '') {
            fail('请输入邮箱、邮箱密码与激活密码（三者缺一不可）', 400);
        }

        // 1) 校验注册账号（邮箱 + 密码）；表不存在时按账号不存在处理（避免 500）
        $es = $db->escapeString($email);
        $u = null;
        try { $u = $db->querySingle("SELECT pass_hash, username FROM admin_users WHERE email='$es' AND status=1", true); } catch (\Throwable $e) { $u = null; }
        if (!$u || !password_verify($emailPwd, $u['pass_hash'])) fail('邮箱或邮箱密码错误（账号不存在或密码不正确）', 401);

        // 2) 校验激活密码（卡密）是否有效
        $ek = strtoupper($pass);

        $st = $db->prepare("SELECT id, status, max_uses, used_list, validity_seconds FROM cards WHERE card_key=? AND status IN ('unused','active')");

        $st->bindValue(1, $ek);

        $cr = $st->execute()->fetchArray(SQLITE3_ASSOC);

        if (!$cr) fail('激活密码(卡密)无效或已被禁用', 401);

        // 3) 人数上限判定（按邮箱去重；max_uses=0 表示不限人数）
        $maxUses = (int)($cr['max_uses'] ?? 1);
        $usedArr = array_filter(array_map('trim', explode(',', (string)($cr['used_list'] ?? ''))));
        $already = in_array($email, $usedArr, true);
        if (!$already) {
            if ($maxUses > 0 && count($usedArr) >= $maxUses) {
                fail('该激活密码已达使用人数上限', 403);
            }
            $usedArr[] = $email;
            $db->exec("UPDATE cards SET used_list='" . $db->escapeString(implode(',', $usedArr)) . "' WHERE id=" . (int)$cr['id']);
        }

        // 3.2) 激活密码首次被使用 → 状态从未使用改为已使用（列表不再永远显示"未激活"）
        // 2026-08-07 审计修复：原只改 status 不设 activated_at/expires_at，导致账号登录激活的卡
        // expires_at=NULL → activate 接口「已激活复用」分支永远判不过期（有效期失效，30 天卡变永久卡）。
        if (($cr['status'] ?? '') === 'unused') {
            $exp2 = ((int)($cr['validity_seconds'] ?? 0) > 0) ? $now + (int)$cr['validity_seconds'] : 0;
            $st2 = $db->prepare("UPDATE cards SET status='active', activated_at=?, expires_at=? WHERE id=? AND status='unused'");
            $st2->bindValue(1, $now);
            $st2->bindValue(2, $exp2);
            $st2->bindValue(3, (int)$cr['id']);
            $st2->execute();
        }

        // 3.5) 记录该用户本次登录使用的激活密码（用户管理可查）
        try { $db->exec("UPDATE admin_users SET card_key='" . $db->escapeString($ek) . "' WHERE email='$es'"); } catch (\Throwable $e) {}

        // 4) 生成独立会话令牌并写入 sessions 表（多会话，互不覆盖；记录登录 IP 用于"记住登录：72 小时 + IP 变动失效"）
        $sess = bin2hex(random_bytes(16));
        $loginIp = $_SERVER['REMOTE_ADDR'] ?? '';
        $db->exec("INSERT OR REPLACE INTO sessions(token, card_id, email, created_at, login_ip) VALUES ('" . SQLite3::escapeString($sess) . "', " . (int)$cr['id'] . ", '" . $db->escapeString($email) . "', " . $now . ", '" . $db->escapeString($loginIp) . "')");

        logLogin('login', 'role=user via account+card email=' . $es . ' key=' . $ek);

        if ($remember && $loginIp0 !== '') $db->exec("INSERT OR REPLACE INTO meta (k,v) VALUES ('remember_ip_" . $db->escapeString($loginIp0) . "', '" . SQLite3::escapeString($sess) . "')");

        if ($devToken) { setcookie('ck_dev', $devToken, time() + 2592000, '/'); $db->exec("INSERT OR REPLACE INTO meta (k,v) VALUES ('dev_" . $db->escapeString($devToken) . "', '" . SQLite3::escapeString($sess) . "')"); }

        ok(['ok' => true, 'role' => 'user', 'token' => $sess, 'via' => 'card', 'username' => ($u['username'] ?? '')]);

        break;



    case 'app_login':

        // 注入器登录：邮箱 + 邮箱密码 → 校验后台注册账号(admin_users) → 返回 HMAC 身份令牌
        // 令牌 = base64url(email) + '.' + HMAC-SHA256(secret, base64url(email))，注入服务(9090)用 APP_SECRET 本地验证并解出上传者邮箱

        $email = trim(getBody('email', ''));

        $emailPwd = getBody('email_pwd', '');

        if ($email === '' || $emailPwd === '') fail('请输入邮箱和邮箱密码', 400);

        $db = getDB();

        $es = $db->escapeString($email);

        $u = null;

        try { $u = $db->querySingle("SELECT pass_hash FROM admin_users WHERE email='$es' AND status=1", true); } catch (\Throwable $e) { $u = null; }

        if (!$u || !password_verify($emailPwd, $u['pass_hash'])) fail('邮箱或邮箱密码错误', 401);

        $payload = strtr(base64_encode($email), '+/', '-_');

        $sig = hash_hmac('sha256', $payload, APP_SECRET);

        $token = $payload . '.' . $sig;

        logLogin('app_login', 'email=' . $es);

        ok(['ok' => true, 'token' => $token, 'email' => $email]);

        break;



    case 'app_token':

        // 后台网页注入用：为当前已登录用户生成注入身份令牌（与 app_login 同算法，无需再输密码）
        // 管理员 → owner='admin'；普通用户 → owner=登录邮箱；共享账号 → '__shared__'

        requireLogin();

        $db = getDB();

        $role = roleOf($db, reqToken());

        if ($role === 'admin') {

            $owner = 'admin';

        } else {

            $srow = $db->querySingle("SELECT email FROM sessions WHERE token='" . SQLite3::escapeString(reqToken()) . "'", true);

            $owner = ($srow && !empty($srow['email'])) ? $srow['email'] : '__shared__';

        }

        $payload = strtr(base64_encode($owner), '+/', '-_');

        $sig = hash_hmac('sha256', $payload, APP_SECRET);

        $token = $payload . '.' . $sig;

        ok(['token' => $token, 'owner' => $owner]);

        break;



    case 'verify_session':

        // 会话存活校验：用于“删除卡密即踢出”的实时生效

        $t = reqToken();

        if ($t === getToken() || $t === getUserToken()) ok(['ok' => true, 'role' => 'legacy']);

        $db = getDB();

        // 记住账号即永久有效（用户要求 2026-08-06）：删除"72 小时过期 + IP 变动失效"约束，
        // 登录一次后只要账号仍存在且启用就一直保持登录，不再因换 IP / 超时被要求重填三框。
        // 账号被管理员删除 / 停用 → JOIN admin_users 仍立即踢出。
        $row = $db->querySingle("SELECT 1 FROM sessions s JOIN admin_users a ON a.email = s.email AND a.status = 1 WHERE s.token='" . SQLite3::escapeString($t) . "'", true);

        if ($row) {
            // 心跳：刷新 last_active（仅用于"在线状态"判断）
            try { $db->exec("UPDATE sessions SET last_active=$now WHERE token='" . SQLite3::escapeString($t) . "'"); } catch (\Throwable $e) {}
            ok(['ok' => true, 'role' => 'user', 'token' => $t]);
        }

        // 服务端 IP 免登（2026-08-06）：本地无 token / token 失效都没关系——
        // 只要这个 IP 曾勾选"记录账号"登录过，就按记住的令牌直接放行（不依赖浏览器存储）
        // 2026-08-14 修复：放行前先校验记住的令牌仍然有效（账号被删/停用→删除记录并拒绝，
        // 避免"先进后台又弹登录"的假登录现象）
        $ipR = $_SERVER['REMOTE_ADDR'] ?? '';
        $rmT = ($ipR !== '') ? $db->querySingle("SELECT v FROM meta WHERE k='remember_ip_" . $db->escapeString($ipR) . "'") : null;
        if ($rmT) {
            $rmValid = false; $rmRole = 'user';
            if ($rmT === getToken($db)) { $rmValid = true; $rmRole = 'admin'; }
            elseif ($rmT === getUserToken($db)) { $rmValid = true; $rmRole = 'user'; }
            else {
                $vrow = $db->querySingle("SELECT 1 FROM sessions s JOIN admin_users a ON a.email=s.email AND a.status=1 WHERE s.token='" . SQLite3::escapeString($rmT) . "'", true);
                if ($vrow) $rmValid = true;
            }
            if ($rmValid) {
                ok(['ok' => true, 'role' => $rmRole, 'token' => $rmT, 'via_ip' => true]);
            } else {
                if ($ipR !== '') $db->exec("DELETE FROM meta WHERE k='remember_ip_" . $db->escapeString($ipR) . "'");
            }
        }

        // 服务端"设备 Cookie"免登（2026-08-06）：登录时下发的长期 Cookie（30 天），与 IP 无关——
        // 换网络/IP 变化也不影响，只要浏览器 Cookie 还在就放行
        $devC = $_COOKIE['ck_dev'] ?? '';
        if ($devC !== '') {
            $devT = $db->querySingle("SELECT v FROM meta WHERE k='dev_" . $db->escapeString($devC) . "'");
            if ($devT) {
                $devValid = false; $devRole = 'user';
                if ($devT === getToken($db)) { $devValid = true; $devRole = 'admin'; }
                elseif ($devT === getUserToken($db)) { $devValid = true; $devRole = 'user'; }
                else {
                    $vrow2 = $db->querySingle("SELECT 1 FROM sessions s JOIN admin_users a ON a.email=s.email AND a.status=1 WHERE s.token='" . SQLite3::escapeString($devT) . "'", true);
                    if ($vrow2) $devValid = true;
                }
                if ($devValid) {
                    ok(['ok' => true, 'role' => $devRole, 'token' => $devT, 'via_dev' => true]);
                } else {
                    $db->exec("DELETE FROM meta WHERE k='dev_" . $db->escapeString($devC) . "'");
                }
            }
        }

        fail('会话已失效（激活密码可能已被删除或账号不存在）', 401);

        break;



    case 'forget_me':

        // 退出登录：删除本 IP 的"服务端记住账号"标记 + 设备 Cookie 凭证（之后需重新登录）

        $db = getDB();

        $ipR = $_SERVER['REMOTE_ADDR'] ?? '';

        if ($ipR !== '') $db->exec("DELETE FROM meta WHERE k='remember_ip_" . $db->escapeString($ipR) . "'");

        $devC = $_COOKIE['ck_dev'] ?? '';

        if ($devC !== '') { $db->exec("DELETE FROM meta WHERE k='dev_" . $db->escapeString($devC) . "'"); setcookie('ck_dev', '', time() - 3600, '/'); }

        ok(['ok' => true]);

        break;



    case 'verify_card':

        // 给 sh / 脚本外壳用的纯文本卡密校验：仅需 邮箱 + 卡密（无需账号密码），复用现有卡密与 max_uses 去重。
        // 通过返回 HTTP 200 文本 "OK"，失败返回 403/401/400 文本 "FAIL: 原因"，sh 里直接判 HTTP 状态码即可。
        $email = trim(getBody('email', ''));
        $code  = strtoupper(trim(getBody('code', '')));
        if ($code === '') { http_response_code(400); echo 'FAIL: 请输入卡密'; exit; }

        $db = getDB();
        $owner = trim(getBody('owner', '') ?: ($_GET['owner'] ?? ''));
        $st = $db->prepare("SELECT id, max_uses, used_list FROM cards WHERE UPPER(card_key)=UPPER(?) AND status IN ('unused','active')" . ownerScopeSql($owner));
        $st->bindValue(1, $code);
        $cr = $st->execute()->fetchArray(SQLITE3_ASSOC);
        if (!$cr) { http_response_code(401); echo 'FAIL: 卡密无效或不属于此软件'; exit; }

        $maxUses = (int)($cr['max_uses'] ?? 1);
        $usedArr = array_filter(array_map('trim', explode(',', (string)($cr['used_list'] ?? ''))));
        $already = $email !== '' && in_array($email, $usedArr, true);
        if (!$already) {
            if ($maxUses > 0 && count($usedArr) >= $maxUses) {
                http_response_code(403); echo 'FAIL: 该卡密已达使用人数上限'; exit;
            }
            if ($email !== '') {
                $usedArr[] = $email;
                $db->exec("UPDATE cards SET used_list='" . $db->escapeString(implode(',', $usedArr)) . "' WHERE id=" . (int)$cr['id']);
            }
        }

        logLogin('verify_card', 'email=' . $email . ' key=' . $code);
        http_response_code(200); echo 'OK'; exit;

        break;



    case 'generate':

        requireLogin();

        $count = max(0, min(1000, (int) getBody('count', 0)));

        $len   = max(8, min(40, (int) getBody('len', 16)));

        $value = max(0, (int) getBody('value', 0));

        $unit  = getBody('unit', 'day');

        $maxUses = max(0, min(100000, (int) getBody('max_uses', 1)));   // 0=不限人数

        $ctype = getBody('type', '');
        if ($ctype !== 'act') $ctype = 'card';   // type=act → 激活密码；其余一律卡密

        if ($unit === 'forever') {

            $secs = 0;

        } elseif ($unit === 'hour') {

            $secs = $value * 3600;

        } else {

            $unit = 'day';

            $secs = $value * 86400;

        }

        $db = getDB();

        // 归属：普通用户生成 -> owner 记为自己邮箱；管理员生成 -> owner 留空（待分发）
        $role = roleOf($db, reqToken());
        // 仅管理员（激活密码）可调人数；普通用户生成的卡密固定单人次（max_uses=1）且只能是卡密（不能生成激活密码）
        if ($role === 'user') {
            $maxUses = 1;
            $ctype = 'card';
        }
        $owner = '__shared__';   // 2026-08-06 合并：管理员生成的卡归共享空间
        if ($role === 'user') {
            $srow = $db->querySingle("SELECT email FROM sessions WHERE token='" . SQLite3::escapeString(reqToken()) . "'", true);
            if ($srow && !empty($srow['email'])) $owner = $srow['email'];
            else $owner = '__shared__';   // 共享 user_token（无邮箱身份）：生成的卡归共享空间
        }

        // 卡密生成上限：全库最多 5000 条

        $MAX_CARDS = 5000;

        $total = (int) $db->querySingle('SELECT COUNT(*) FROM cards');

        if ($total >= $MAX_CARDS) {

            fail('卡密总数已达上限 ' . $MAX_CARDS . '，无法再生成', 400);

        }

        if ($count > ($MAX_CARDS - $total)) {

            $count = $MAX_CARDS - $total;   // 自动裁剪到剩余名额，避免超额

        }

        $keys = [];

        if ($count > 0) {

        for ($i = 0; $i < $count; $i++) {

            $k = genKey($len);

            // 永久卡：validity_days=0、validity_seconds=0，避免被旧库迁移逻辑误改成有效期

            $days = ($unit === 'forever') ? 0 : $value;

            $st = $db->prepare('INSERT OR IGNORE INTO cards (card_key, status, validity_days, validity_seconds, created_at, owner, max_uses, used_list, ctype) VALUES (?, \'unused\', ?, ?, ?, ?, ?, \'\', ?)');

            $st->bindValue(1, $k);

            $st->bindValue(2, $days);

            $st->bindValue(3, $secs);

            $st->bindValue(4, $now);
            $st->bindValue(5, $owner);
            $st->bindValue(6, $maxUses);
            $st->bindValue(7, $ctype);

            $st->execute();

            $keys[] = $k;

        }

        }

        logLogin('generate', "count=$count len=$len unit=$unit val=$value");

        ok(['count' => count($keys), 'keys' => $keys, 'unit' => $unit, 'value' => $value, 'max_uses' => $maxUses, 'total' => $total + count($keys), 'limit' => $MAX_CARDS]);

        break;



    case 'list':

        requireLogin();

        $db = getDB();

        expireDueCards($db, $now);

        // 数据隔离：所有人都只看到「自己生成的卡密」
        //  - 普通用户（账号+激活密码登录,sessions 表里的 token）：owner = 自己邮箱
        //  - 管理员（admin_token 共享）：owner IS NULL（管理员作为「管理员」生成的激活密码）
        //  - 共享 user_token 登录：无具体邮箱，安全起见返回空
        //  - 管理员穿透查看任一用户的卡密：用 action=user_detail 接口（前端用户管理弹窗已实现）
        $role = roleOf($db, reqToken());
        $ownerEmail = null;
        $isAdminNull = false;
        if ($role === 'admin') {
            $isAdminNull = true;
        } else {
            $srow = $db->querySingle("SELECT email FROM sessions WHERE token='" . SQLite3::escapeString(reqToken()) . "'", true);
            if ($srow && !empty($srow['email'])) $ownerEmail = $srow['email'];
        }

        // 类型过滤：type=act → 激活密码（仅管理员）；默认/其他 → 卡密
        $ltype = getBody('type', '');
        if ($ltype !== 'act') $ltype = 'card';
        if ($ltype === 'act' && !$isAdminNull) fail('无权查看激活密码', 403);

        $rows = [];

        $sql = 'SELECT id, card_key, status, validity_days, validity_seconds, created_at, activated_at, expires_at, owner, max_uses, used_list, ctype FROM cards';
        if ($isAdminNull) {
            $sql .= " WHERE owner='__shared__' AND ctype='" . $db->escapeString($ltype) . "'";   // 2026-08-06 合并
        } elseif ($ownerEmail !== null) {
            $sql .= " WHERE owner='" . $db->escapeString($ownerEmail) . "' AND ctype='card'";
        } else {
            // 共享 user_token（无邮箱身份）：看共享空间
            $sql .= " WHERE owner='__shared__' AND ctype='card'";
        }
        $sql .= ' ORDER BY id DESC';
        $res = $db->query($sql);

        while ($r = $res->fetchArray(SQLITE3_ASSOC)) {

            $r['validity_label'] = validityLabel($r['validity_seconds']);

            $r['is_permanent'] = ($r['validity_seconds'] == 0);

            $rows[] = $r;

        }

        ok(['cards' => $rows]);

        break;


    case 'list_users':
        needToken();
        $db = getDB();
        // 在线判断：该邮箱最近 120 秒内有登录会话（verify_session 轮询保持活跃）→ 在线
        $res = $db->query("SELECT u.email, u.username, u.created, u.status, u.pass_plain, "
            . "(SELECT 1 FROM sessions s WHERE s.email=u.email AND s.last_active >= " . ($now - 120) . " LIMIT 1) AS online "
            . "FROM admin_users u ORDER BY u.created DESC");
        $users = [];
        while ($r = $res->fetchArray(SQLITE3_ASSOC)) { $users[] = $r; }
        ok(['users' => $users]);
        break;

    case 'user_detail':
        needToken();
        $email = trim(getBody('email', ''));
        if ($email === '') fail('缺少 email', 400);
        $db = getDB();
        $es = $db->escapeString($email);
        // 特殊：email='__shared__' 表示查看"共享空间"（123123 共享账号生成的卡）
        if ($email === '__shared__') {
            $cards = [];
            $res = $db->query("SELECT id, card_key, status, validity_seconds, created_at, expires_at FROM cards WHERE owner='__shared__' ORDER BY id DESC");
            while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
                $r['validity_label'] = validityLabel($r['validity_seconds']);
                $r['is_permanent'] = ($r['validity_seconds'] == 0);
                $cards[] = $r;
            }
            ok(['user' => ['email' => '__shared__', 'username' => '共享账号（123123 登录）', 'created' => 0, 'status' => 1, 'pass_plain' => '', 'online' => 0], 'cards' => $cards]);
            break;
        }
        $u = $db->querySingle("SELECT email, username, created, status, pass_plain, card_key FROM admin_users WHERE email='$es'", true);
        if (!$u) fail('用户不存在', 404);
        $u['online'] = (int)$db->querySingle("SELECT 1 FROM sessions WHERE email='$es' AND last_active >= " . ($now - 120) . " LIMIT 1") ? 1 : 0;
        $cards = [];
        $res = $db->query("SELECT id, card_key, status, validity_seconds, created_at, expires_at FROM cards WHERE owner='$es' ORDER BY id DESC");
        while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
            $r['validity_label'] = validityLabel($r['validity_seconds']);
            $r['is_permanent'] = ($r['validity_seconds'] == 0);
            $cards[] = $r;
        }
        ok(['user' => $u, 'cards' => $cards]);
        break;


    case 'status':

        requireLogin();

        $db = getDB();

        expireDueCards($db, $now);

        // 数据隔离：所有人都只看到「自己生成的卡密」的统计
        //  - 普通用户（账号+激活密码登录）：owner = 自己邮箱
        //  - 管理员：admin_token → owner IS NULL（管理员生成的激活密码）
        //  - 共享 user_token：无具体邮箱，全 0
        $role = roleOf($db, reqToken());
        $ownerEmail = null;
        $isAdminNull = false;
        if ($role === 'admin') {
            $isAdminNull = true;
        } else {
            $srow = $db->querySingle("SELECT email FROM sessions WHERE token='" . SQLite3::escapeString(reqToken()) . "'", true);
            if ($srow && !empty($srow['email'])) $ownerEmail = $srow['email'];
        }

        $whereOwner = '';
        // 类型过滤：type=act → 激活密码（仅管理员）；默认/其他 → 卡密
        $ltype = getBody('type', '');
        if ($ltype !== 'act') $ltype = 'card';
        if ($ltype === 'act' && !$isAdminNull) fail('无权查看激活密码', 403);
        // 2026-08-07 审计修复：与 list 保持一致。管理员生成卡 owner='__shared__'，
        // 原 status 用 (owner IS NULL OR owner='') 统计 → 合并后管理员统计永远为 0。
        if ($isAdminNull) {
            $whereOwner = " AND (owner='__shared__' OR owner IS NULL OR owner='') AND ctype='" . $db->escapeString($ltype) . "'";
        } elseif ($ownerEmail !== null) {
            $whereOwner = " AND owner='" . $db->escapeString($ownerEmail) . "' AND ctype='card'";
        } else {
            // 共享 user_token（无邮箱身份）：看共享空间
            $whereOwner = " AND owner='__shared__' AND ctype='card'";
        }

        $total  = $db->querySingle('SELECT COUNT(*) FROM cards WHERE 1=1' . $whereOwner);

        $active = $db->querySingle("SELECT COUNT(*) FROM cards WHERE status='active' AND (expires_at=0 OR expires_at IS NULL OR expires_at>=$now)" . $whereOwner);

        $used   = $db->querySingle("SELECT COUNT(*) FROM cards WHERE status!='unused'" . $whereOwner);

        $res = $db->query("SELECT id, card_key, status, activated_at, expires_at, validity_seconds FROM cards WHERE status='active'" . $whereOwner . " ORDER BY activated_at DESC LIMIT 50");

        $ac = [];

        while ($r = $res->fetchArray(SQLITE3_ASSOC)) {

            $r['validity_label'] = validityLabel($r['validity_seconds']);

            $r['is_permanent'] = ($r['validity_seconds'] == 0);

            $ac[] = $r;

        }

        ok([

            'total'        => (int) $total,

            'active'       => (int) $active,

            'used'         => (int) $used,

            'active_cards' => $ac,

            'server_time'  => $now,

        ]);

        break;



    case 'activate':

        // 激活：卡密即凭证，免登录（任何持卡人可激活）；
        // 验证端带 owner（注入者）时校验卡密归属——只能激活本软件注入者自己生成的卡

        $db = getDB();

        $key = strtoupper(trim(getBody('key', '') ?: ($_GET['key'] ?? '')));

        if ($key === '') fail('卡密为空');

        $owner = trim(getBody('owner', '') ?: ($_GET['owner'] ?? ''));

        expireDueCards($db, $now);

        $st = $db->prepare('SELECT * FROM cards WHERE card_key=?' . ownerScopeSql($owner));

        $st->bindValue(1, $key);

        $r = $st->execute()->fetchArray(SQLITE3_ASSOC);

        if (!$r) fail('卡密无效或不属于此软件（请使用本软件注入者提供的卡密）', 404);

        if ($r['status'] === 'active') {
            // 2026-08-14 需求：卡密激活后永久绑定首个设备，不能换设备
            if ($r['expires_at'] > 0 && $r['expires_at'] < $now) fail('卡密已过期', 410);
            $dev = trim(getBody('device', ''));
            $sess = $r['sess'];
            if ($sess !== null && $sess !== '') {
                // 已绑定设备：仅同设备可复用；其他设备（含网页无设备）一律拒绝，不可顶替
                if ($dev === '' || $dev !== $sess) {
                    fail('该卡密已绑定设备，无法在其他设备激活（如需更换设备请联系客服解绑）', 403);
                }
                logLogin('activate', "key=$key(复用)", $dev);
                ok(['status' => 'active', 'activated_at' => $r['activated_at'], 'expires_at' => $r['expires_at'], 'permanent' => ($r['expires_at'] === 0), 'reused' => true]);
                exit;
            }
            // 已激活但未绑定设备（历史卡/网页激活的卡）：绑定当前设备
            if ($dev !== '') {
                $db->exec("UPDATE cards SET sess='" . $db->escapeString($dev) . "' WHERE id=" . (int)$r['id']);
            }
            logLogin('activate', "key=$key(复用)", $dev);
            ok(['status' => 'active', 'activated_at' => $r['activated_at'], 'expires_at' => $r['expires_at'], 'permanent' => ($r['expires_at'] === 0), 'reused' => true]);
            exit;
        }

        if ($r['status'] === 'revoked') fail('卡密已作废', 409);

        if ($r['status'] === 'expired' || ($r['expires_at'] && $r['expires_at'] < $now)) fail('卡密已过期', 410);

        $expires = ($r['validity_seconds'] > 0) ? $now + $r['validity_seconds'] : 0;

        // 首次激活：绑定当前设备（一卡一设备；网页无设备号则存空，后续壳内首次使用自动绑定）
        $dev0 = trim(getBody('device', ''));
        $st = $db->prepare('UPDATE cards SET status=\'active\', activated_at=?, expires_at=?, sess=? WHERE id=?');

        $st->bindValue(1, $now);

        $st->bindValue(2, $expires);

        $st->bindValue(3, $dev0 === '' ? null : $dev0);

        $st->bindValue(4, $r['id']);

        $st->execute();

        logLogin('activate', "key=$key", getBody('device', ''));

        notifyActivate($db, $key, 1, $_SERVER['REMOTE_ADDR'] ?? '', $r['owner'] ?? '', $expires);

        ok(['status' => 'active', 'activated_at' => $now, 'expires_at' => $expires, 'permanent' => $expires === 0]);

        break;



    case 'batch_activate':

        // 多选卡密 → 批量激活（所有登录用户均可，仅限自己空间内的卡）。接收 keys[] 数组

        requireLogin();

        $db = getDB();

        $delScope = scopeWhere($db, reqToken());

        if ($delScope === null) fail('无权操作（未关联账号）', 403);

        $keys = $_POST['keys'] ?? [];

        if (!is_array($keys)) $keys = [$keys];

        expireDueCards($db, $now);

        $results = [];

        foreach ($keys as $key) {

            $key = strtoupper(trim($key));

            if ($key === '') continue;

            $st = $db->prepare('SELECT * FROM cards WHERE card_key=? AND (1=1' . $delScope . ')');

            $st->bindValue(1, $key);

            $r = $st->execute()->fetchArray(SQLITE3_ASSOC);

            if (!$r) { $results[] = ['key' => $key, 'ok' => false, 'error' => '卡密不存在或无权操作']; continue; }

            if ($r['status'] === 'active') { $results[] = ['key' => $key, 'ok' => false, 'error' => '已激活']; continue; }

            if ($r['status'] === 'revoked') { $results[] = ['key' => $key, 'ok' => false, 'error' => '已作废']; continue; }

            if ($r['status'] === 'expired' || ($r['expires_at'] && $r['expires_at'] < $now)) { $results[] = ['key' => $key, 'ok' => false, 'error' => '已过期']; continue; }

            $expires = ($r['validity_seconds'] > 0) ? $now + $r['validity_seconds'] : 0;

            $st = $db->prepare('UPDATE cards SET status=\'active\', activated_at=?, expires_at=? WHERE id=?');

            $st->bindValue(1, $now);

            $st->bindValue(2, $expires);

            $st->bindValue(3, $r['id']);

            $st->execute();

            logLogin('activate', "key=$key (batch)");

            $results[] = ['key' => $key, 'ok' => true, 'expires_at' => $expires, 'permanent' => $expires === 0, 'owner' => $r['owner'] ?? ''];

        }

        $okCnt = 0; $ownerCnt = [];

        foreach ($results as $rr) {

            if (!empty($rr['ok'])) {

                $okCnt++;

                if (!empty($rr['owner'])) $ownerCnt[$rr['owner']] = ($ownerCnt[$rr['owner']] ?? 0) + 1;

            }

        }

        if ($okCnt > 0) {

            $who = $_SERVER['REMOTE_ADDR'] ?? '';

            if ($ownerCnt) {

                foreach ($ownerCnt as $o => $n) notifyActivate($db, '', $n, $who, $o);

            } else {

                notifyActivate($db, '', $okCnt, $who);

            }

        }

        ok(['count' => count($results), 'results' => $results]);

        break;



    case 'batch_delete':

        // 多选卡密 → 批量删除（所有登录用户均可，仅限自己的）。接收 keys[] 数组

        requireLogin();

        $db = getDB();
        // 归属校验：管理员可删 owner 为空(管理员生成)的卡；普通用户只能删自己邮箱的卡
        $delScope = scopeWhere($db, reqToken());
        if ($delScope === null) fail('无权删除（未关联账号）', 403);

        $keys = $_POST['keys'] ?? [];

        if (!is_array($keys)) $keys = [$keys];

        $n = 0;

        foreach ($keys as $k) {

            $k = trim($k);

            if ($k === '') continue;

            $st = $db->prepare('DELETE FROM cards WHERE card_key=? AND (1=1' . $delScope . ')');

            $st->bindValue(1, $k);

            $st->execute();

            $n++;

        }

        logLogin('batch_delete', "count=$n");

        ok(['deleted' => $n]);

        break;



    case 'user_card_delete':

        // 管理员从"用户管理"里删除某用户的某张卡（跨 owner 特权删除，普通用户不可用）
        // 支持 email='__shared__' 删除共享空间的卡

        needToken();

        $db = getDB();

        if (roleOf($db, reqToken()) !== 'admin') fail('仅管理员可操作', 403);

        $email = trim(getBody('email', ''));

        $key = trim(getBody('key', ''));

        if ($email === '' || $key === '') fail('缺少 email 或 key', 400);

        $st = $db->prepare('DELETE FROM cards WHERE owner=? AND card_key=?');

        $st->bindValue(1, $email);

        $st->bindValue(2, $key);

        $st->execute();

        if ($db->changes() <= 0) fail('未找到该卡密', 404);

        logLogin('user_card_delete', "email=$email key=$key");

        try { oplog($db, 'user_card_delete', "email=$email key=$key"); } catch (\Throwable $e) {}

        ok(['ok' => true, 'deleted' => 1]);

        break;



    case 'verify':

        // 校验：卡密即凭证，免登录；验证端带 owner 时校验归属

        $db = getDB();

        $key = strtoupper(trim($_GET['key'] ?? ''));

        if ($key === '') fail('卡密为空');

        $owner = trim($_GET['owner'] ?? '');

        expireDueCards($db, $now);

        $st = $db->prepare('SELECT * FROM cards WHERE card_key=?' . ownerScopeSql($owner));

        $st->bindValue(1, $key);

        $r = $st->execute()->fetchArray(SQLITE3_ASSOC);

        if (!$r) fail('卡密无效或不属于此软件', 404);

        // 一卡一设备（2026-08-14）：壳内验证（shell=1）且卡已绑定设备时，仅绑定设备可继续使用。
        // 新设备激活会自动顶替旧设备；旧设备下次验证被拒并提示重新激活。
        $shell = (int)($_GET['shell'] ?? 0);
        $dev = trim($_GET['device'] ?? '');
        if ($shell === 1 && $r['status'] === 'active' && $dev !== '' && $r['sess'] !== null && $r['sess'] !== '' && $r['sess'] !== $dev) {
            fail('该卡密已绑定设备，无法在其他设备使用（如需更换设备请联系客服解绑）', 409);
        }

        ok(['status' => $r['status'], 'activated_at' => $r['activated_at'], 'expires_at' => $r['expires_at'], 'permanent' => ($r['expires_at'] == 0)]);

        break;



    case 'delete':

        requireLogin();

        $key = trim(getBody('key', ''));

        if ($key === '') fail('卡密为空');

        $db = getDB();
        // 归属校验：管理员可删 owner 为空(管理员生成)的卡；普通用户只能删自己邮箱的卡
        $delScope = scopeWhere($db, reqToken());
        if ($delScope === null) fail('无权删除（未关联账号）', 403);

        $cr = $db->querySingle("SELECT id FROM cards WHERE card_key='" . $db->escapeString($key) . "' AND (1=1" . $delScope . ")", true);
        $cid = $cr ? (int)$cr['id'] : 0;

        $st = $db->prepare('DELETE FROM cards WHERE card_key=? AND (1=1' . $delScope . ')');

        $st->bindValue(1, $key);

        $st->execute();

        if ($db->changes() == 0) fail('卡密不存在或无权删除', 403);

        // 清掉该卡的所有会话（踢出所有用这张卡登录的人）
        if ($cid) { $db->exec("DELETE FROM sessions WHERE card_id=" . $cid); }

        logLogin('delete', "key=$key");

        oplog($db, 'delete', "key=$key");

        ok(['deleted' => true]);

        break;



    case 'loginlog':

    case 'loginlist':

        needToken();

        $db = getDB();

        $rows = [];

        $res = $db->query('SELECT id, ip, action, detail, ua, device, location, at FROM login_logs ORDER BY id DESC LIMIT 300');

        while ($r = $res->fetchArray(SQLITE3_ASSOC)) {

            $r['time'] = $r['at'];

            $rows[] = $r;

        }

        ok(['logs' => $rows]);

        break;



    case 'clear_logs':

        // 清空操作日志（管理员）：mode=all 清空全部；mode=keep 保留最近 keep 条

        needToken();

        $db = getDB();

        $mode = getBody('mode', 'all');

        if ($mode === 'keep') {

            $keep = max(0, (int) getBody('keep', 100));

            if ($keep > 0) {

                $db->exec("DELETE FROM login_logs WHERE id NOT IN (SELECT id FROM login_logs ORDER BY id DESC LIMIT " . intval($keep) . ")");

            }

        } else {

            $db->exec("DELETE FROM login_logs");

        }

        $rest = $db->querySingle("SELECT COUNT(*) FROM login_logs");

        ok(['cleared' => true, 'rest' => (int) $rest]);

        break;



    case 'announce':

        // 后台管理公告：仅管理员可发布；任何人可读
        // 注意：needToken 只校验 token 等于 admin_token，已被前端 hack 绕过（普通用户拿到 admin_token 字符串就能调）。
        // 这里双重校验 roleOf === 'admin'，并拒绝空字符串 token/共享 user_token 的请求。

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {

            $tok = reqToken();
            if (!is_string($tok) || $tok === '' || roleOf(getDB(), $tok) !== 'admin') {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => '仅管理员可发布后台公告'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $msg = trim(getBody('msg', ''));

            $db = getDB();

            $db->exec("INSERT OR REPLACE INTO meta (k, v) VALUES ('announce', '" . SQLite3::escapeString($msg) . "')");

            ok(['announce' => $msg]);

        }

        $db = getDB();

        $a = $db->querySingle("SELECT v FROM meta WHERE k='announce'");

        ok(['announce' => $a ?? '']);

        break;



    case 'announce_verify':

        // 验证公告（按用户空间隔离）：普通用户可发、存自己名下；管理员不发验证公告。
        // 读取：?owner=<email> → 返回该用户（注入者）的验证公告；无 owner → 返回全局旧值（兼容）

        $db = getDB();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {

            requireLogin();

            $role = roleOf($db, reqToken());

            if ($role === 'admin') fail('管理员不发布验证公告（验证端只显示各注入者的公告）', 403);

            $srow = $db->querySingle("SELECT email FROM sessions WHERE token='" . SQLite3::escapeString(reqToken()) . "'", true);

            if (!$srow || empty($srow['email'])) fail('未关联账号，无法发布', 403);

            $msg = trim(getBody('msg', ''));

            metaSet($db, 'announce_verify_' . $srow['email'], $msg);

            ok(['announce_verify' => $msg, 'owner' => $srow['email']]);

        }

        $owner = trim($_GET['owner'] ?? '');

        if ($owner !== '') {

            $a = metaGet($db, 'announce_verify_' . $owner);

            ok(['announce_verify' => $a ?? '', 'owner' => $owner]);

        }

        // 无 owner 一律返回空：验证公告按用户空间隔离，后台/管理员不读取（避免泄漏与误弹"用户端公告预览"）
        ok(['announce_verify' => '', 'owner' => '']);

        break;



    case 'module':

        // 验证端自定义模块（按用户空间隔离）：每个普通用户一个；管理员无模块。
        // POST: 保存自己的模块内容；GET ?owner=<email>: 读取该用户的模块（验证页用）

        $db = getDB();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {

            requireLogin();

            $role = roleOf($db, reqToken());

            if ($role === 'admin') fail('管理员无验证端模块', 403);

            $srow = $db->querySingle("SELECT email FROM sessions WHERE token='" . SQLite3::escapeString(reqToken()) . "'", true);

            if (!$srow || empty($srow['email'])) fail('未关联账号，无法保存', 403);

            $content = trim(getBody('content', ''));

            metaSet($db, 'module_' . $srow['email'], $content);

            oplog($db, 'module_save', $srow['email']);

            ok(['ok' => true]);

        }

        $owner = trim($_GET['owner'] ?? '');

        $m = ($owner !== '') ? metaGet($db, 'module_' . $owner) : '';

        ok(['module' => $m ?? '']);

        break;



    case 'heartbeat':

        // 客户端心跳：记录在线状态（免登录），用于统计"正在使用人数"

        $db = getDB();

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        $device = trim(getBody('device', ''));

        $st = $db->prepare('INSERT INTO online (ip, device, at) VALUES (?, ?, ?)');

        $st->bindValue(1, $ip);

        $st->bindValue(2, $device);

        $st->bindValue(3, $now);

        $st->execute();

        $db->exec('DELETE FROM online WHERE at < ' . ($now - 600));

        ok(['ok' => true]);

        break;



    case 'online_count':

        // 在线人数（管理员）：统计最近 5 分钟有心跳的去重设备/IP

        needToken();

        $db = getDB();

        $n = $db->querySingle("SELECT COUNT(DISTINCT COALESCE(NULLIF(device,''), ip)) FROM online WHERE at >= " . ($now - 300));

        ok(['online' => (int) $n]);

        break;



    case 'crash_report':

        // 免登录：接收壳自动上报的崩溃日志（POST log=），存 crash_logs/ 供远程排障（2026-08-06 新增）

        $log = (string) getBody('log', '');

        $log = substr($log, 0, 60000);

        if ($log === '') fail('empty', 400);

        @mkdir(__DIR__ . '/crash_logs', 0777, true);

        $fn = __DIR__ . '/crash_logs/crash_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 6) . '.txt';

        @file_put_contents($fn, $log . "
", FILE_APPEND);

        ok(['saved' => true]);

        break;

    case 'version_check':

        // 版本检查（免登录）：客户端传本地 versionCode，返回是否需更新

        $v = (int) getBody('v', 0);

        $db = getDB();

        $latest = (int) tokenVal($db, 'latest_version', APP_VERSION_CODE);

        $url = tokenVal($db, 'update_url', 'http://' . ADMIN_IP . '/cardkey_demo_v' . APP_VERSION_CODE . '.apk');

        $force = (int) tokenVal($db, 'force_update', 0);

        // gate_min_ver：验证端"最低版本门槛"（注入时间戳）。注入壳把 URL 里的 ver 传进来，
        // ver < gate_min_ver → 该验证端被停用，必须强制更新（用于停用所有旧注入包）。
        // 旧包无 ver(=0)，设 gate_min_ver=1 即全量停用；新注入包 ver=时间戳自动放行。
        $gateMinVer = (int) tokenVal($db, 'gate_min_ver', 0);

        // web_ver：网页资源版本号（取 gate.html / admin.html 最新修改时间），前端据此自动刷新到最新

        $webVer = 0;

        foreach (['gate.html', 'admin.html'] as $wf) {

            $p = __DIR__ . '/' . $wf;

            if (file_exists($p)) $webVer = max($webVer, (int) filemtime($p));

        }

        ok(['latest' => $latest, 'url' => $url, 'force' => $force, 'current' => $v, 'need_update' => ($v < $latest), 'gate_min_ver' => $gateMinVer, 'admin_version' => API_VERSION, 'app_version_code' => APP_VERSION_CODE, 'web_ver' => $webVer]);

        break;



    case 'stream':

        // SSE 实时推送：公告 / 网页资源版本 / APK 版本 变更，客户端无需手动刷新即收到

        header('Content-Type: text/event-stream; charset=utf-8');

        header('Cache-Control: no-cache');

        header('Connection: keep-alive');

        header('X-Accel-Buffering: no');   // 关闭 nginx 缓冲，保证实时下发

        @ob_end_clean();

        set_time_limit(0);

        $db = getDB();

        if (!function_exists('sse_webver')) {

            function sse_webver($dir){

                $m = 0;

                foreach (['gate.html','admin.html','user.html'] as $f){

                    $p = $dir.'/'.$f;

                    if (file_exists($p)) $m = max($m, (int) filemtime($p));

                }

                return $m;

            }

        }

        $last = '';

        $st = [

            'web_ver'         => sse_webver(__DIR__),

            'announce'        => metaGet($db, 'announce') ?? '',

            'announce_verify' => metaGet($db, 'announce_verify') ?? '',

            'latest'          => (int) tokenVal($db, 'latest_version', APP_VERSION_CODE),

            'update_url'      => tokenVal($db, 'update_url', ''),

            'force'           => (int) tokenVal($db, 'force_update', 0),

            'now'             => time(),

        ];

        $last = md5(json_encode($st));

        echo "retry: 3000\n";

        echo "data: " . json_encode($st, JSON_UNESCAPED_UNICODE) . "\n\n";

        @ob_flush(); flush();

        $deadline = time() + 25;   // 单连接最多 25s，之后由客户端 EventSource 自动重连

        while (time() < $deadline) {

            usleep(1500000);       // 1.5s 检测一次，变更即时下发

            $st = [

                'web_ver'         => sse_webver(__DIR__),

                'announce'        => metaGet($db, 'announce') ?? '',

                'announce_verify' => metaGet($db, 'announce_verify') ?? '',

                'latest'          => (int) tokenVal($db, 'latest_version', APP_VERSION_CODE),

                'update_url'      => tokenVal($db, 'update_url', ''),

                'force'           => (int) tokenVal($db, 'force_update', 0),

                'now'             => time(),

            ];

            $sig = md5(json_encode($st));

            if ($sig !== $last) {

                $last = $sig;

                echo "data: " . json_encode($st, JSON_UNESCAPED_UNICODE) . "\n\n";

                @ob_flush(); flush();

            }

        }

        exit;



    case 'set_update':

        // 发布更新（管理员）：设定最新版本号 / 下载地址 / 是否强制

        needToken();

        $db = getDB();

        $v = (int) getBody('version', APP_VERSION_CODE);

        $url = trim(getBody('url', ''));

        $force = (int) getBody('force', 0);

        $db->exec("INSERT OR REPLACE INTO meta (k, v) VALUES ('latest_version', '" . intval($v) . "')");

        if ($url !== '') $db->exec("INSERT OR REPLACE INTO meta (k, v) VALUES ('update_url', '" . SQLite3::escapeString($url) . "')");

        $db->exec("INSERT OR REPLACE INTO meta (k, v) VALUES ('force_update', '" . intval($force) . "')");

        oplog($db, 'set_update', "v=$v force=$force");

        ok(['latest' => $v, 'url' => tokenVal($db, 'update_url', ''), 'force' => $force]);

        break;



    case 'set_gate_ver':

        // 设置验证端最低版本门槛（仅管理员）：停用所有 ver < 门槛 的注入包（打开即强制更新）。
        // 旧包 ver=0：设 1 即全量停用；新注入包 ver=注入时间戳 自动放行。设 0 关闭停用。
        needToken();

        $db = getDB();

        $gv = (int) getBody('gate_min_ver', 0);

        if ($gv < 0) $gv = 0;

        $db->exec("INSERT OR REPLACE INTO meta (k, v) VALUES ('gate_min_ver', '" . intval($gv) . "')");

        oplog($db, 'set_gate_ver', "gate_min_ver=$gv");

        ok(['gate_min_ver' => $gv]);

        break;



    case 'set_server':

        // 更改服务器连接地址（管理员）

        needToken();

        $db = getDB();

        $base = trim(getBody('api_base', ''));

        if ($base === '') fail('地址为空');

        if (!preg_match('#^https?://#', $base)) fail('地址格式错误（需以 http:// 或 https:// 开头）');

        $db->exec("INSERT OR REPLACE INTO meta (k, v) VALUES ('api_base', '" . SQLite3::escapeString($base) . "')");

        ok(['api_base' => $base]);

        break;



    case 'set_admin_ips':

        // 设置管理员登录 IP 白名单（管理员）。逗号分隔；留空表示不限制。

        needToken();

        $db = getDB();

        $ips = trim(getBody('ips', ''));

        // 校验每个条目都是合法 IPv4

        if ($ips !== '') {

            foreach (explode(',', $ips) as $ip) {

                $ip = trim($ip);

                if (!filter_var($ip, FILTER_VALIDATE_IP)) fail('非法 IP：' . $ip, 400);

            }

        }

        metaSet($db, 'admin_ips', $ips);

        ok(['admin_ips' => $ips === '' ? '' : $ips]);

        break;



    case 'get_register':

        // 读取公开注册开关（管理员）

        needToken();

        $db = getDB();

        $open = tokenVal($db, 'open_register', '1');

        ok(['open' => $open === '1' ? 1 : 0]);

        break;



    case 'set_register':

        // 设置公开注册开关（管理员）。open=1 开放；open=0 关闭

        needToken();

        $db = getDB();

        $open = getBody('open', '1');

        $open = ($open === '1' || $open === 1 || $open === 'true' || $open === true) ? '1' : '0';

        metaSet($db, 'open_register', $open);

        oplog($db, 'set_register', 'open=' . $open);

        ok(['open' => (int)$open]);

        break;



    case 'delete_user':

        // 删除账户（彻底清除）：删注册信息 + 他生成的卡密 + 他所有会话（踢下线）。仅管理员，需二次确认。
        needToken();

        $email = trim(getBody('email', ''));
        $confirm = trim(getBody('confirm', ''));
        if ($email === '') fail('缺少邮箱', 400);
        if ($confirm !== '1') fail('请先确认删除', 400);

        $db = getDB();
        $es = $db->escapeString($email);

        // 删他自己生成的卡密
        $db->exec("DELETE FROM cards WHERE owner='" . $es . "'");
        // 踢掉他所有登录会话
        $db->exec("DELETE FROM sessions WHERE email='" . $es . "'");
        // 删注册账户
        $db->exec("DELETE FROM admin_users WHERE email='" . $es . "'");

        logLogin('delete_user', "email=$email");

        oplog($db, 'delete_user', "email=$email");
        ok(['deleted' => true]);

        break;



    case 'get_config':

        // 前端动态获取服务器连接地址（免登录）

        $db = getDB();

        ok(['api_base' => tokenVal($db, 'api_base', '')]);

        break;



    case 'my_ip':

        // 返回当前访问 IP（免登录）：白名单校验用的就是服务器看到的这个 IP

        ok(['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);

        break;



    case 'owner_info':

        // 验证端署名：返回注入者（owner 邮箱）注册时填写的用户名，让终端用户知道"受谁控制"

        $owner = trim($_GET['owner'] ?? '');

        $username = '';

        if ($owner !== '') {

            $db = getDB();

            if ($owner === 'admin') {

                $username = '管理员';

            } else {

                $es = $db->escapeString($owner);

                try { $username = (string)($db->querySingle("SELECT username FROM admin_users WHERE email='$es' AND status=1") ?: ''); } catch (\Throwable $e) { $username = ''; }

            }

        }

        ok(['username' => $username, 'owner' => $owner]);

        break;



    case 'upload_update':

        // 上传新 APK（管理员）：保存为 latest.apk 并设定更新地址 + 最新版本号

        needToken();

        $db = getDB();

        $ver = (int) getBody('version', APP_VERSION_CODE);

        $force = (int) getBody('force', 0);

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) fail('未收到文件或上传失败');

        $tmp = $_FILES['file']['tmp_name'];

        $name = $_FILES['file']['name'] ?? '';

        if (strtolower(substr($name, -4)) !== '.apk') fail('仅支持 .apk 文件');

        $dest = __DIR__ . '/latest.apk';

        if (!is_uploaded_file($tmp) || !move_uploaded_file($tmp, $dest)) fail('保存文件失败');

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

        $host = $_SERVER['HTTP_HOST'] ?? ADMIN_IP;

        $url = $scheme . '://' . $host . '/latest.apk';

        $db->exec("INSERT OR REPLACE INTO meta (k, v) VALUES ('update_url', '" . SQLite3::escapeString($url) . "')");

        $db->exec("INSERT OR REPLACE INTO meta (k, v) VALUES ('latest_version', '" . intval($ver) . "')");

        $db->exec("INSERT OR REPLACE INTO meta (k, v) VALUES ('force_update', '" . intval($force) . "')");

        ok(['url' => $url, 'latest' => $ver, 'force' => $force]);

        break;



    case 'version':

        ok(['version' => API_VERSION]);

        break;



    case 'update':

        // APK 更新检查：返回当前版本与下载地址（阶段2 打包 APK 后由客户端调用）

        ok(['version' => API_VERSION, 'url' => 'http://' . ADMIN_IP . '/cardkey_admin.apk', 'check' => $now]);

        break;



    case 'change_token':

        needToken();

        $new = trim(getBody('new_token', ''));

        if (strlen($new) < 6) fail('token 至少 6 位');

        $db = getDB();

        $db->exec("INSERT OR REPLACE INTO meta (k, v) VALUES ('admin_token', '" . SQLite3::escapeString($new) . "')");

        oplog($db, 'change_token', 'new_len=' . strlen($new));

        ok(['changed' => true]);

        break;



    case 'download':

        ok(['url' => 'http://' . ADMIN_IP . '/cardkey_admin.apk']);

        break;




    case 'injected_put':

        // 注入服务(NEW)把产物归属记录推送到本机 data/injected.json（管理员令牌调用），保证双机一致
        requireLogin();

        $db = getDB();

        if (roleOf($db, reqToken()) !== 'admin') fail('仅管理员', 403);

        $rec = json_decode(getBody('rec', ''), true);

        if (!is_array($rec) || empty($rec['name'])) fail('参数错误', 400);

        // 2026-08-11 「重新修复」：replace_name 非空时先删同名旧记录再追加（每软件只留一条）
        $replaceName = (string)getBody('replace_name', '');

        $jf = __DIR__ . '/data/injected.json';

        $arr = [];

        if (is_file($jf)) { $t = json_decode((string)file_get_contents($jf), true); if (is_array($t)) $arr = $t; }

        if ($replaceName !== '') {

            $arr = array_values(array_filter($arr, function ($x) use ($replaceName) {

                return (string)($x['name'] ?? '') !== $replaceName;

            }));

        }

        $arr[] = $rec;

        if (count($arr) > 500) $arr = array_slice($arr, -500);

        file_put_contents($jf, json_encode($arr, JSON_UNESCAPED_UNICODE));

        @chmod($jf, 0644);

        ok(['ok' => true]);

        break;



    case 'list_injected':

        // 查看注入产物（按用户空间隔离）：读 data/injected.json（注入服务写入）
        // 管理员：全部；普通用户：只看到自己（owner=自己邮箱）的

        requireLogin();

        $db = getDB();

        $role = roleOf($db, reqToken());

        $ownerEmail = null;

        if ($role === 'user') {

            $srow = $db->querySingle("SELECT email FROM sessions WHERE token='" . SQLite3::escapeString(reqToken()) . "'", true);

            if ($srow && !empty($srow['email'])) $ownerEmail = $srow['email'];

        }

        $records = [];

        $jf = __DIR__ . '/data/injected.json';

        if (is_file($jf)) {

            $arr = json_decode((string)file_get_contents($jf), true);

            if (is_array($arr)) {

                foreach ($arr as $r) {

                    $o = (string)($r['owner'] ?? '');

                    // 2026-08-07 审计修复：共享账号（user_token，无邮箱身份，ownerEmail=null）注入的产物
                    // owner='__shared__'，原条件看不到任何记录。共享空间即共享账号共用，放行 __shared__ 记录。
                    if ($role === 'admin' || ($ownerEmail !== null && $o === $ownerEmail) || ($ownerEmail === null && $o === '__shared__')) {

                        $records[] = $r;

                    }

                }

            }

        }

        usort($records, function ($a, $b) { return (int)($b['ts'] ?? 0) - (int)($a['ts'] ?? 0); });

        ok(['items' => $records]);

        break;



    case 'gate_code':

        // 免登录：验证页无配对码时按 app 拉取（2026-08-06 CLI 直跑注入的包无码，服务器补发）

        $db = getDB();

        $app = trim(getBody('app', '') ?: ($_GET['app'] ?? ''));

        if ($app === '') fail('缺少 app', 400);

        $cd = tokenVal($db, 'gate_code_' . $app, '');

        if ($cd === '') fail('该应用未配置配对码', 404);

        ok(['code' => $cd]);

        break;

    case 'bind_status':

        // 验证端配对码状态（免登录，gate.html 调用）：?code=xxxx
        // 返回 {bound, owner, username}：bound=是否已被绑定；已绑定给出归属用户（供显示"管理端：xxx"）
        $db = getDB();

        $code = strtoupper(trim($_GET['code'] ?? ''));

        if ($code === '') fail('缺少配对码', 400);

        $bound = $db->querySingle("SELECT v FROM meta WHERE k='bind_" . SQLite3::escapeString($code) . "'");

        if ($bound === null) {

            ok(['bound' => false, 'owner' => '', 'username' => '']);

            break;

        }

        if ($bound === 'admin') {

            ok(['bound' => true, 'owner' => 'admin', 'username' => '管理员']);

            break;

        }

        $un = '';

        $row = $db->querySingle("SELECT username FROM admin_users WHERE email='" . SQLite3::escapeString($bound) . "' LIMIT 1", true);

        if ($row) $un = (string)($row['username'] ?? '');

        ok(['bound' => true, 'owner' => $bound, 'username' => $un]);

        break;



    case 'bind_code':

        // 绑定验证端（登录用户）：POST code=xxxx → 该验证端归当前用户
        // 先到先得、永久绑定、不可解绑；一个码只能绑一次，重复绑拒绝
        requireLogin();

        $db = getDB();

        $code = strtoupper(trim(getBody('code', '')));

        if ($code === '') fail('缺少配对码', 400);

        if (!preg_match('/^[A-Z0-9]{6,16}$/', $code)) fail('配对码格式不正确', 400);

        // 校验配对码存在：注入产物记录（data/injected.json）里有该 code 才算有效
        $jf = __DIR__ . '/data/injected.json';

        $valid = false;

        if (is_file($jf)) {

            $arr = json_decode((string)file_get_contents($jf), true);

            if (is_array($arr)) {

                foreach ($arr as $r) {

                    if (strtoupper((string)($r['code'] ?? '')) === $code) { $valid = true; break; }

                }

            }

        }

        if (!$valid) fail('配对码不存在或已失效', 404);

        $bound = $db->querySingle("SELECT v FROM meta WHERE k='bind_" . SQLite3::escapeString($code) . "'");

        if ($bound !== null) fail('该配对码已被绑定，无法重复绑定', 403);

        // 当前登录用户（普通用户取 sessions 邮箱；管理员绑定记为 admin）
        $srow = $db->querySingle("SELECT email FROM sessions WHERE token='" . SQLite3::escapeString(reqToken()) . "'", true);

        $me = ($srow && !empty($srow['email'])) ? $srow['email'] : 'admin';

        $db->exec("INSERT OR REPLACE INTO meta (k,v) VALUES ('bind_" . SQLite3::escapeString($code) . "', '" . SQLite3::escapeString($me) . "')");

        oplog($db, 'bind_code', "code=$code owner=$me");

        ok(['bound' => true, 'owner' => $me]);

        break;



    case 'inject_status':

        // 后台实时监控（仅管理员）：当前是否有人在使用注入（转发 INJ:9090 /active）

        needToken();

        $NEW = 'YOUR_SERVER_IP';   // 注入机（腾讯云 2G，专跑注入服务）

        $hc = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);

        $hj = @file_get_contents("http://{$NEW}:9090/active?token=" . urlencode(getToken()), false, $hc);

        $d = ($hj !== false) ? json_decode($hj, true) : null;

        if (is_array($d) && !empty($d['ok'])) {

            ok(['active' => (int)$d['active'], 'jobs' => $d['jobs'] ?? [], 'ts' => $now]);

        } else {

            ok(['active' => -1, 'jobs' => [], 'ts' => $now, 'note' => '注入服务不可达（NEW:9090）']);

        }

        break;



    case 'self_check':

        // 后台一键体检（所有登录用户可用）：数据库 / 磁盘 / PHP 环境 / 注入服务(INJ:9090) / 下载服务 / 产物目录

        requireLogin();

        $items = [];

        $NEW = 'YOUR_SERVER_IP';   // 注入机（腾讯云 2G，专跑注入服务）

        $hc = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);

        // 1. 数据库

        try {

            $db = getDB();

            $c = $db->querySingle("SELECT COUNT(*) FROM meta");

            $items[] = ['name' => '数据库', 'ok' => ($c !== false), 'detail' => ($c !== false) ? "连接正常（meta {$c} 条）" : '打开/查询失败'];

        } catch (\Throwable $e) {

            $items[] = ['name' => '数据库', 'ok' => false, 'detail' => '异常: ' . $e->getMessage()];

        }

        // 2. 磁盘空间（主站分区）

        $free = @disk_free_space(__DIR__);

        $total = @disk_total_space(__DIR__);

        if ($free !== false && $total !== false) {

            $freeGb = round($free / 1073741824, 2);

            $totalGb = round($total / 1073741824, 2);

            $items[] = ['name' => '磁盘空间（主站）', 'ok' => ($free > 2147483648), 'detail' => "可用 {$freeGb} GB / 共 {$totalGb} GB" . ($free <= 2147483648 ? '（偏低）' : '')];

        } else {

            $items[] = ['name' => '磁盘空间（主站）', 'ok' => false, 'detail' => '无法读取'];

        }

        // 3. PHP 环境

        $exts = [];

        $phpOk = true;

        foreach (['sqlite3' => 'SQLite3', 'openssl' => 'OpenSSL'] as $ext => $label) {

            $has = extension_loaded($ext);

            $phpOk = $phpOk && $has;

            $exts[] = $label . ($has ? ' ✓' : ' ✗');

        }

        $items[] = ['name' => 'PHP 环境', 'ok' => $phpOk, 'detail' => 'PHP ' . PHP_VERSION . ' · ' . implode(' · ', $exts) . ' · 上传限制 ' . ini_get('upload_max_filesize')];

        // 4. 注入服务（NEW:9090）

        $hj = @file_get_contents("http://{$NEW}:9090/health", false, $hc);

        $health = ($hj !== false) ? json_decode($hj, true) : null;

        if (is_array($health) && !empty($health['ok'])) {

            $sub = [];

            foreach (['java' => 'Java', 'apktool' => 'apktool', 'apksigner' => '签名', 'zipalign' => '对齐', 'aapt2' => 'aapt2'] as $tk => $tl) {

                $sub[] = $tl . (!empty($health['tools'][$tk]) ? ' ✓' : ' ✗');

            }

            $diskS = (!empty($health['disk_free']) && $health['disk_free'] > 0) ? ' · 注入机磁盘 ' . round($health['disk_free'] / 1073741824, 2) . ' GB' : '';

            $items[] = ['name' => '注入服务（' . $NEW . ':9090）', 'ok' => true, 'detail' => '在线 · 并发 ' . (int)($health['max_jobs'] ?? 1) . ' · ' . implode(' · ', $sub) . $diskS];

        } else {

            $items[] = ['name' => '注入服务（' . $NEW . ':9090）', 'ok' => false, 'detail' => '无法连接，请检查注入服务是否运行'];

        }

        // 5. 主站下载服务（8080）：注入机不跑 8080，这里固定指主站 158

        $MAIN = 'YOUR_SERVER_IP';

        $h2 = @file_get_contents("http://{$MAIN}:9090/health", false, $hc);

        $items[] = ['name' => '下载服务（' . $MAIN . ':8080）', 'ok' => ($h2 !== false), 'detail' => ($h2 !== false) ? '正常' : '无法访问'];

        // 6. 注入产物目录可写

        $injDir = __DIR__ . '/injected';

        $w = is_dir($injDir) && is_writable($injDir);

        $items[] = ['name' => '产物目录', 'ok' => $w, 'detail' => $w ? '可写' : '不可写/不存在'];

        $bad = 0;

        foreach ($items as $it) { if (empty($it['ok'])) $bad++; }

        ok(['items' => $items, 'ok_all' => ($bad === 0), 'bad' => $bad, 'ts' => $now]);

        break;



    case 'stats':

        // 销售统计（管理员）：近 days 天每日 生成/激活/到期 数量 + 汇总

        needToken();

        $db = getDB();

        $days = max(7, min(90, (int) getBody('days', 30)));

        $out = [];

        for ($i = $days - 1; $i >= 0; $i--) {

            $d0 = $now - $i * 86400;

            $d1 = $d0 + 86400;

            $gen = (int)$db->querySingle("SELECT COUNT(*) FROM cards WHERE created_at>=$d0 AND created_at<$d1");

            $act = (int)$db->querySingle("SELECT COUNT(*) FROM cards WHERE activated_at>=$d0 AND activated_at<$d1 AND activated_at>0");

            $exp = (int)$db->querySingle("SELECT COUNT(*) FROM cards WHERE expires_at>=$d0 AND expires_at<$d1 AND expires_at>0 AND status='expired'");

            $out[] = ['day' => date('m-d', $d0), 'gen' => $gen, 'act' => $act, 'exp' => $exp];

        }

        $tot = $db->querySingle("SELECT COUNT(*) FROM cards");

        $actTot = $db->querySingle("SELECT COUNT(*) FROM cards WHERE status='active'");

        $unused = $db->querySingle("SELECT COUNT(*) FROM cards WHERE status='unused'");

        ok(['days' => $out, 'total' => (int)$tot, 'active' => (int)$actTot, 'unused' => (int)$unused]);

        break;



    case 'cleanup_expired':

        // 一键清理（管理员）：删除已到期/已销毁/已作废的卡记录（二次确认 confirm=1）

        needToken();

        $db = getDB();

        $confirm = trim(getBody('confirm', ''));

        if ($confirm !== '1') fail('请先确认清理', 400);

        $n = $db->exec("DELETE FROM cards WHERE status IN ('expired','destroyed','revoked')");

        $db->exec("DELETE FROM sessions WHERE card_id NOT IN (SELECT id FROM cards)");

        oplog($db, 'cleanup_expired', "cards=$n");

        ok(['deleted' => $n]);

        break;



    case 'oplog_list':

        // 操作日志（管理员）：最近 200 条

        needToken();

        $db = getDB();

        $rows = [];

        $q = $db->query('SELECT ts, who, action, detail FROM oplog ORDER BY id DESC LIMIT 200');

        if ($q) while ($row = $q->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;

        ok(['items' => $rows]);

        break;



    case 'injected_delete':

        // 删除注入产物（管理员可删任意；普通用户只能删自己的）。name=文件名

        requireLogin();

        $db = getDB();

        $name = trim(getBody('name', ''));

        if ($name === '' || strpos($name, '/') !== false || strpos($name, '..') !== false) fail('非法文件名', 400);

        $role = roleOf($db, reqToken());

        $ownerEmail = null;

        if ($role === 'user') {

            $srow = $db->querySingle("SELECT email FROM sessions WHERE token='" . SQLite3::escapeString(reqToken()) . "'", true);

            if ($srow && !empty($srow['email'])) $ownerEmail = $srow['email'];

        }

        $jf = __DIR__ . '/data/injected.json';

        $removed = false;

        if (is_file($jf)) {

            $arr = json_decode((string)file_get_contents($jf), true);

            if (is_array($arr)) {

                $arr2 = [];

                foreach ($arr as $r) {

                    $o = (string)($r['owner'] ?? '');

                    // 2026-08-07 审计修复：共享账号同样可删除 owner='__shared__' 的产物（与 list_injected 可见性一致）
                    if ((string)($r['name'] ?? '') === $name && ($role === 'admin' || ($ownerEmail !== null && $o === $ownerEmail) || ($ownerEmail === null && $o === '__shared__'))) { $removed = true; continue; }

                    $arr2[] = $r;

                }

                file_put_contents($jf, json_encode($arr2, JSON_UNESCAPED_UNICODE));

            }

        }

        if (!$removed) fail('未找到该产物或无权删除', 404);

        @unlink(__DIR__ . '/injected/' . $name);

        oplog($db, 'injected_delete', $name);

        ok(['deleted' => true]);

        break;



    case 'shell_cfg':

        // 验证端远程配置（免注入推送）：GET 按 owner 返回（owner 覆盖全局）；POST 保存（登录用户存自己的，管理员可存全局）
        // 任意一项配置失败/缺失都自动用默认值，不影响验证端使用
        $db = getDB();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {

            requireLogin();

            $role = roleOf($db, reqToken());

            $global = trim(getBody('global', ''));

            if ($global === '1' && $role === 'admin') {

                $key = 'shell_cfg';

            } else {

                $srow = $db->querySingle("SELECT email FROM sessions WHERE token='" . SQLite3::escapeString(reqToken()) . "'", true);

                if (!$srow || empty($srow['email'])) fail('未关联账号，无法保存', 403);

                $key = 'shell_cfg_' . $srow['email'];

            }

            $cfg = [

                'title' => substr(trim(getBody('title', '')), 0, 30),

                'theme' => in_array(getBody('theme', ''), ['dark', 'blue', 'light', 'gold']) ? getBody('theme', '') : '',

                'qq' => substr(trim(getBody('qq', '')), 0, 200),

                'footer' => substr(trim(getBody('footer', '')), 0, 100),

            ];

            metaSet($db, $key, json_encode($cfg, JSON_UNESCAPED_UNICODE));

            oplog($db, 'shell_cfg_save', $key);

            ok(['ok' => true]);

            break;

        }

        $owner = trim($_GET['owner'] ?? '');

        $cfg = ['title' => '卡密验证', 'theme' => '', 'qq' => '', 'footer' => ''];

        $g = metaGet($db, 'shell_cfg');

        if ($g) { $gc = json_decode($g, true); if (is_array($gc)) $cfg = array_merge($cfg, $gc); }

        if ($owner !== '') {

            $m = metaGet($db, 'shell_cfg_' . $owner);

            if ($m) { $mc = json_decode($m, true); if (is_array($mc)) $cfg = array_merge($cfg, $mc); }

        }

        ok(['cfg' => $cfg]);

        break;



    case 'get_notify':

        requireLogin();

        $db = getDB();

        // 管理员/共享账号 → 全局配置；普通用户 → 个人配置（按邮箱隔离，卡被激活发到自己邮箱）
        $my = '';
        if (roleOf($db, reqToken()) !== 'admin') {
            $srow = $db->querySingle("SELECT email FROM sessions WHERE token='" . SQLite3::escapeString(reqToken()) . "'", true);
            if ($srow && !empty($srow['email'])) $my = $srow['email'];
        }
        $pfx = ($my !== '') ? '_' . $my : '';

        // 默认开启 + 默认收件箱：普通用户=自己注册邮箱；管理员/全局=SMTP 发件箱（管理员邮箱）
        $defaultEmail = $my !== '' ? $my : trim((string)tokenVal($db, 'smtp_from', ''));

        ok([
            'notify_activate' => (int)tokenVal($db, 'notify_activate' . $pfx, 1),
            'notify_email' => (string)tokenVal($db, 'notify_email' . $pfx, $defaultEmail),
            'scope' => $my !== '' ? '个人' : '全局',
            'owner' => $my,
        ]);

        break;



    case 'set_notify':

        // 通知设置：管理员配全局；普通用户配个人（卡被激活 → 发到自己邮箱）

        requireLogin();

        $db = getDB();

        $my = '';
        if (roleOf($db, reqToken()) !== 'admin') {
            $srow = $db->querySingle("SELECT email FROM sessions WHERE token='" . SQLite3::escapeString(reqToken()) . "'", true);
            if ($srow && !empty($srow['email'])) $my = $srow['email'];
        }
        $pfx = ($my !== '') ? '_' . $my : '';

        $on = getBody('on', '');

        $email = trim(getBody('email', ''));

        if ($on !== '') {
            $onv = ($on === '1' || $on === 1 || $on === 'true') ? '1' : '0';
            metaSet($db, 'notify_activate' . $pfx, $onv);
        }

        if ($email !== '') {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('邮箱格式不正确', 400);
            metaSet($db, 'notify_email' . $pfx, $email);
        }

        oplog($db, 'set_notify', ($my !== '' ? 'owner=' . $my . ' ' : 'global ') . 'on=' . $on . ' email=' . $email);

        ok([
            'notify_activate' => (int)tokenVal($db, 'notify_activate' . $pfx, $my !== '' ? 1 : 0),
            'notify_email' => (string)tokenVal($db, 'notify_email' . $pfx, $my !== '' ? $my : ''),
            'scope' => $my !== '' ? '个人' : '全局',
        ]);

        break;
    default:

        fail('未知 action', 404);

}

