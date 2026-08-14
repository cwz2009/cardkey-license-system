.class public Lcom/cardkey/gate/GateActivity;
.super Landroid/app/Activity;
.source "GateActivity.java"


.method public constructor <init>()V
    .registers 1

    invoke-direct {p0}, Landroid/app/Activity;-><init>()V

    return-void
.end method


.method protected onCreate(Landroid/os/Bundle;)V
    .registers 8

    invoke-super {p0, p1}, Landroid/app/Activity;->onCreate(Landroid/os/Bundle;)V

    new-instance v0, Landroid/webkit/WebView;

    invoke-direct {v0, p0}, Landroid/webkit/WebView;-><init>(Landroid/content/Context;)V

    invoke-virtual {v0}, Landroid/webkit/WebView;->getSettings()Landroid/webkit/WebSettings;

    move-result-object v1

    const/4 v2, 0x1

    invoke-virtual {v1, v2}, Landroid/webkit/WebSettings;->setJavaScriptEnabled(Z)V

    invoke-virtual {v1, v2}, Landroid/webkit/WebSettings;->setDomStorageEnabled(Z)V

    const/4 v2, 0x0

    invoke-virtual {v1, v2}, Landroid/webkit/WebSettings;->setSupportMultipleWindows(Z)V

    # 本地验证页(file:///android_asset/gate.html)需要跨源请求服务器 API：
    # 必须允许 file 页面发起网络请求（否则验证/版本检查全部失败，页面永远"转圈"）
    const/4 v2, 0x1

    invoke-virtual {v1, v2}, Landroid/webkit/WebSettings;->setAllowUniversalAccessFromFileURLs(Z)V

    # 保险：允许加载 file:// 本地页（assets 兜底必需）
    invoke-virtual {v1, v2}, Landroid/webkit/WebSettings;->setAllowFileAccess(Z)V

    # 远程优先 + 本地兜底（2026-08-11 免注入更新 + 离线功能）：
    # 先联网加载服务器最新验证页（改验证页只改服务器文件即可，所有已装 APK 自动生效，免重新注入）；
    # 网络失败(离线/断网)时由 GateActivity$2 自动切到内置 assets/gate.html（离线也能打开验证界面）。
    new-instance v4, Lcom/cardkey/gate/GateClient;

    const-string v5, "LOCAL_PLACEHOLDER"

    invoke-direct {v4, v0, v5}, Lcom/cardkey/gate/GateClient;-><init>(Landroid/webkit/WebView;Ljava/lang/String;)V

    invoke-virtual {v0, v4}, Landroid/webkit/WebView;->setWebViewClient(Landroid/webkit/WebViewClient;)V

    # 下载监听：点 APK 下载链接 → DownloadManager 自动下载（否则 WebView 点下载无反应）
    new-instance v4, Lcom/cardkey/gate/GateActivity$1;

    invoke-direct {v4, p0}, Lcom/cardkey/gate/GateActivity$1;-><init>(Lcom/cardkey/gate/GateActivity;)V

    invoke-virtual {v0, v4}, Landroid/webkit/WebView;->setDownloadListener(Landroid/webkit/DownloadListener;)V

    new-instance v1, Lcom/cardkey/gate/CardKeyBridge;

    const-string v2, "ORIG_PLACEHOLDER"

    invoke-direct {v1, p0, v2}, Lcom/cardkey/gate/CardKeyBridge;-><init>(Landroid/app/Activity;Ljava/lang/String;)V

    const-string v2, "CardKeyBridge"

    invoke-virtual {v0, v1, v2}, Landroid/webkit/WebView;->addJavascriptInterface(Ljava/lang/Object;Ljava/lang/String;)V

    new-instance v1, Ljava/lang/StringBuilder;

    invoke-direct {v1}, Ljava/lang/StringBuilder;-><init>()V

    const-string v2, "SERVER_PLACEHOLDER"

    invoke-virtual {v1, v2}, Ljava/lang/StringBuilder;->append(Ljava/lang/String;)Ljava/lang/StringBuilder;

    const-string v3, "?"

    invoke-virtual {v2, v3}, Ljava/lang/String;->indexOf(Ljava/lang/String;)I

    move-result v5

    # 修复（2026-08-06）：分隔符选择逻辑反了。
    # 原代码：URL 无 "?" 时用 "&"、有 "?" 时用 "?" → 拼出 gate.html&app=xxx → nginx 404。
    # 正确：URL 无 "?" 时用 "?"（首个参数），有 "?" 时用 "&"（追加参数）。
    const-string v4, "?"

    if-ltz v5, :cond_noq

    const-string v4, "&"

    :cond_noq

    invoke-virtual {v1, v4}, Ljava/lang/StringBuilder;->append(Ljava/lang/String;)Ljava/lang/StringBuilder;

    const-string v3, "app="

    invoke-virtual {v1, v3}, Ljava/lang/StringBuilder;->append(Ljava/lang/String;)Ljava/lang/StringBuilder;

    const-string v3, "PKG_PLACEHOLDER"

    invoke-virtual {v1, v3}, Ljava/lang/StringBuilder;->append(Ljava/lang/String;)Ljava/lang/StringBuilder;

    # 注入时间戳版本：gate.html 据此比对 gate_min_ver，自身版本=门槛则不强制更新（避免新版包也死循环更新）
    const-string v3, "&ver="

    invoke-virtual {v1, v3}, Ljava/lang/StringBuilder;->append(Ljava/lang/String;)Ljava/lang/StringBuilder;

    const-string v3, "VER_PLACEHOLDER"

    invoke-virtual {v1, v3}, Ljava/lang/StringBuilder;->append(Ljava/lang/String;)Ljava/lang/StringBuilder;

    invoke-virtual {v1}, Ljava/lang/StringBuilder;->toString()Ljava/lang/String;

    move-result-object v1

    invoke-virtual {v0, v1}, Landroid/webkit/WebView;->loadUrl(Ljava/lang/String;)V

    # 注册全局崩溃日志器（2026-08-06 ★修复寄存器冲突：onCreate 里 p0=this 占 v6，之前误用 v6 存 CrashLog 把 this 覆盖，
    # 导致 setContentView 在 CrashLog 上调用 → 启动必闪退。改用 v5（URL 拼接后不再使用的局部寄存器））
    :try_log_start
    new-instance v5, Lcom/cardkey/gate/CrashLog;

    invoke-direct {v5, p0}, Lcom/cardkey/gate/CrashLog;-><init>(Landroid/app/Activity;)V

    invoke-static {v5}, Ljava/lang/Thread;->setDefaultUncaughtExceptionHandler(Ljava/lang/Thread$UncaughtExceptionHandler;)V
    :try_log_end
    goto :log_done

    :log_catch
    move-exception v5

    :log_done

    invoke-virtual {p0, v0}, Landroid/app/Activity;->setContentView(Landroid/view/View;)V

    return-void

    .catch Ljava/lang/Throwable; {:try_log_start .. :try_log_end} :log_catch
.end method
