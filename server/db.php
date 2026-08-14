<?php
// 卡密验证系统 - 数据库（SQLite，单文件，省内存）
require_once __DIR__ . '/config.php';

function getDB() {
    static $db = null;
    if ($db === null) {
        $dir = dirname(DB_PATH);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $db = new SQLite3(DB_PATH);
                $db->exec('PRAGMA busy_timeout=15000;');
        $db->exec('PRAGMA synchronous=NORMAL;');
        if (($db->querySingle('PRAGMA journal_mode') ?: 'wal') !== 'wal') { @$db->exec('PRAGMA journal_mode=WAL;'); }
        $db->exec('CREATE TABLE IF NOT EXISTS cards (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            card_key TEXT UNIQUE NOT NULL,
            status TEXT NOT NULL DEFAULT \'unused\',
            validity_days INTEGER NOT NULL DEFAULT 30,
            created_at INTEGER NOT NULL,
            activated_at INTEGER,
            expires_at INTEGER,
            validity_seconds INTEGER NOT NULL DEFAULT 0
        )');
        // 旧库兼容迁移：仅在列缺失时执行一次（避免每次请求 UPDATE 全表制造写锁）
        try {
        $has = $db->querySingle("SELECT COUNT(*) FROM pragma_table_info('cards') WHERE name='validity_seconds'");
        if (!$has) {
            $db->exec('ALTER TABLE cards ADD COLUMN validity_seconds INTEGER NOT NULL DEFAULT 0');
            $db->exec('UPDATE cards SET validity_seconds = validity_days * 86400 WHERE validity_seconds = 0 AND validity_days > 0');
        }
        } catch (\Throwable $e) { /* 并发迁移冲突忽略，下次请求再补 */ }
        $db->exec('CREATE TABLE IF NOT EXISTS login_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip TEXT NOT NULL,
            action TEXT NOT NULL,
            detail TEXT,
            ua TEXT,
            device TEXT,
            location TEXT,
            at INTEGER NOT NULL
        )');
        $has = $db->querySingle("SELECT COUNT(*) FROM pragma_table_info('login_logs') WHERE name='ua'");
        if (!$has) {
            $db->exec('ALTER TABLE login_logs ADD COLUMN ua TEXT');
            $db->exec('ALTER TABLE login_logs ADD COLUMN device TEXT');
            $db->exec('ALTER TABLE login_logs ADD COLUMN location TEXT');
        }
        $db->exec('CREATE TABLE IF NOT EXISTS online (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip TEXT NOT NULL,
            device TEXT,
            at INTEGER NOT NULL
        )');
        $db->exec('CREATE TABLE IF NOT EXISTS meta (
            k TEXT PRIMARY KEY,
            v TEXT
        )');
    }
    return $db;
}

function genKey($len = 16) {
    $len = max(8, min(40, (int) $len));
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    $n = 0;
    while ($n < $len) {
        $s .= $chars[random_int(0, strlen($chars) - 1)];
        $n++;
    }
    return $s;
}
