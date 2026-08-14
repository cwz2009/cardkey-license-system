# 卡密验证系统 · 云后台（阶段 1）

阶段 1 交付：**PHP + SQLite 后端 + 网页版管理后台/用户端**，管理端每 1.5s 轮询拉状态，
用户端激活即写服务端 —— 满足"用户一激活，管理端 ≤2s 显示激活中"（R028 < 3 秒）。

> 最终交付物是 APK（R008）；本阶段 HTML 仅作可运行 demo。阶段 2（打包 APK 时）把管理端轮询换 SSE 推送，即为毫秒级绝对同步。

## 文件结构
```
cardkey-server/
├── index.php      # API 入口（?action=...）
├── config.php     # 配置：管理员 IP / token / 有效期
├── db.php         # SQLite 初始化 + 生成卡密
├── admin.html     # 管理云后台（轮询）
├── user.html      # 用户激活端
├── index.html     # 入口
└── data/          # SQLite 数据库（运行时生成，需可写）
```

## API（标准 REST，JSON）
| action | 说明 | 鉴权 |
|---|---|---|
| ping | 探活 + 版本 | 公开 |
| generate | 批量生成卡密 | token |
| list | 全部卡密 | token |
| status | 摘要 + 激活中列表（管理端轮询用） | token |
| activate | 激活卡密（用户端） | 公开 |
| verify | 查询卡密状态 | 公开 |
| delete | 删除卡密 | token |
| loginlog / loginlist | 登录记录 | token |
| announce | 公告（GET 读 / POST 写） | 写需 token |
| change_token | 改管理 token | token |
| version / download | 版本 / APK 下载地址 | 公开 |

## 部署到服务器（YOUR_SERVER_IP）
1. 把 `cardkey-server/` 整目录上传到 web 目录，例如 `/var/www/html/cardkey/`。
2. 服务器需 **PHP 7.4+** 且开启 **sqlite3** 扩展（`php -m | grep sqlite3`）。
3. 赋写权限：`chmod 755 /var/www/html/cardkey/data`。
4. Nginx/PHP-FPM 已跑在 8080（你现有环境），确认 `index.php` 可被访问：
   `curl http://YOUR_SERVER_IP:8080/cardkey/index.php?action=ping`
5. 前端访问：
   - 用户端：`http://YOUR_SERVER_IP:8080/cardkey/user.html`
   - 管理端：`http://YOUR_SERVER_IP:8080/cardkey/admin.html?token=cardkey-admin-2026`

## 注意（阶段 1 简化点，待你确认）
- **清登录记录功能已移除**：按用户要求删除（此功能可有可无），相关的管理员 IP 判定逻辑一并去掉。
- **token**：默认 `cardkey-admin-2026`，请用 `change_token` 改成强 token。
- **到期销毁**：当前把过期卡密标记 `expired`；真正"销毁"可加定时任务清理，阶段 1 未做。
- **缓存**：已发 `Cache-Control: no-store` + `no-store`，保证实时；前端轮询 1.5s。
- **CORS**：demo 开了 `*`；同机部署可删掉 index.php 里那行。
