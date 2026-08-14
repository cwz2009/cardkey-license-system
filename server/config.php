<?php
// 卡密验证系统 - 配置（阶段1：轮询 + SQLite）
// 部署后改这里：管理员 token、有效期等。

define('ADMIN_IP', 'YOUR_SERVER_IP');        // 服务器 IP（用于 download 等地址拼接）
define('DB_PATH', __DIR__ . '/data/cardkey.db');
define('ADMIN_TOKEN', 'CHANGE_ME_ADMIN_TOKEN');   // 2026-08-07 与 meta.admin_token 对齐  // 默认管理 token（内部会话令牌，登录后返回）
define('USER_TOKEN_DEFAULT', 'CHANGE_ME_USER_TOKEN'); // 普通用户默认会话令牌

// 登录密码：仅管理员/普通用户两类。用户无法修改；以后你想改，直接改这两个常量即可。
define('ADMIN_PASS', 'CHANGE_ME_PASSWORD');            // 管理员登录密码
define('USER_PASS',  'CHANGE_ME_PASSWORD');   // 2026-08-06               // 普通用户登录密码

define('DEFAULT_VALIDITY_DAYS', 30);          // 卡密默认有效期（天）
define('API_VERSION', 'v12');
define('APP_VERSION_CODE', 5);             // 当前 APK 版本号（整数，用于强制更新比对）

define('APP_SECRET', 'CHANGE_ME_APP_SECRET');
