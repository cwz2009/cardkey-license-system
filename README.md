# CardKey 卡密验证系统

给任意 Android APK 加一层"卡密（激活密码）验证壳"：**未激活无法进入，验证通过才拉起原 App**。含 PHP + SQLite 服务端、smali 注入壳、Python 注入服务、离线兜底验证页、后台一键修复。

## 功能特性

- **卡密管理**：在线/批量生成，有效期灵活（永久 / 按天 / 按小时），全生命周期可视化管理
- **APK 注入保护**：任意 Android 应用一键注入验证壳，未激活无法进入
- **一卡一设备**：卡密激活后永久绑定首个设备，无法在其他设备激活，防多人共用
- **OTA 强制更新**：版本门槛一键抬升，旧包打开自动提示更新，坏包后台远程一键修复
- **邮件提醒**：卡被激活 / 到期前 3 天 / 到期后，自动通知卡主（生成者）邮箱
- **数据看板**：实时统计卡密总数、激活中、已使用
- **多角色协作**：管理员 / 普通用户分权管理，各自主理自己的卡密与注入产物
- **离线兜底**：壳内远程优先加载验证页，离线自动切本地兜底页

## 目录结构

```
├── server/                 # 服务端（PHP + SQLite）
│   ├── index.php           # 主接口（激活/验证/卡密管理/统计/OTA）
│   ├── admin.html          # 管理后台
│   ├── gate.html           # 验证页（壳内远程加载）
│   ├── gate_preview.html   # 验证页预览端（与注入后效果一致）
│   ├── download.html       # 下载页
│   ├── register.html       # 注册页
│   ├── user.html           # 用户中心
│   ├── auth.php / db.php / mail.php / config.php
├── inject/                 # 注入工具链（Python + smali 模板）
│   ├── inject.py           # 注入脚本（apktool 全量 + light 降级）
│   ├── inject_server.py    # 注入 HTTP 服务（上传/进度/修复）
│   ├── edit_manifest.py    # 二进制 AXML 编辑
│   └── tpl/                # 壳模板（GateActivity / CardKeyBridge / CrashLog）
```

## 快速开始

1. 将 `server/` 部署到 PHP 8 + SQLite 服务器（改 `config.php` 里的密钥/令牌占位符）
2. 访问 `admin.html` 登录后台，生成卡密
3. 用 `inject/` 工具链对目标 APK 注入验证壳：
   ```
   python3 inject.py --apk 你的App.apk --output 输出目录
   ```
4. 用户安装注入后的 APK，输入卡密验证通过后自动拉起原 App

## 安全说明

- 仓库所有密钥/令牌/IP 均为占位符（`YOUR_SERVER_IP`、`CHANGE_ME_*`），部署前必须替换
- 卡密验证需联网同步（激活/复核均在服务器完成）
- 验证页支持 4 套主题（曜石黑 / 暮光蓝 / 商务金 / 雾白）

## License

MIT
