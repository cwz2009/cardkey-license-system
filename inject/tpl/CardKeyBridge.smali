.class public Lcom/cardkey/gate/CardKeyBridge;
.super Ljava/lang/Object;
.source "CardKeyBridge.java"


.field public mActivity:Landroid/app/Activity;

.field public mOrigClass:Ljava/lang/String;

.field public lastError:Ljava/lang/String;


.method public constructor <init>(Landroid/app/Activity;Ljava/lang/String;)V
    .registers 3

    invoke-direct {p0}, Ljava/lang/Object;-><init>()V

    iput-object p1, p0, Lcom/cardkey/gate/CardKeyBridge;->mActivity:Landroid/app/Activity;

    iput-object p2, p0, Lcom/cardkey/gate/CardKeyBridge;->mOrigClass:Ljava/lang/String;

    return-void
.end method


.method public launchOriginal()V
    .registers 3
    .annotation runtime Landroid/webkit/JavascriptInterface;
    .end annotation

    # 清空上次错误
    const-string v0, ""

    iput-object v0, p0, Lcom/cardkey/gate/CardKeyBridge;->lastError:Ljava/lang/String;

    # 切到主线程执行拉起（JS 调用在 JavaBridge 非主线程，UI 操作必须回主线程）
    new-instance v0, Lcom/cardkey/gate/CardKeyBridge$2;

    invoke-direct {v0, p0}, Lcom/cardkey/gate/CardKeyBridge$2;-><init>(Lcom/cardkey/gate/CardKeyBridge;)V

    iget-object v1, p0, Lcom/cardkey/gate/CardKeyBridge;->mActivity:Landroid/app/Activity;

    invoke-virtual {v1, v0}, Landroid/app/Activity;->runOnUiThread(Ljava/lang/Runnable;)V

    return-void
.end method


# 记录最近一次拉起失败的原因（供 WebView 内 getLastError 显示）
.method public setLastError(Ljava/lang/String;)V
    .registers 2

    iput-object p1, p0, Lcom/cardkey/gate/CardKeyBridge;->lastError:Ljava/lang/String;

    return-void
.end method


# 返回最近一次拉起失败的原因（空串=成功），验证页据此展示具体错误
# 2026-08-06 修复：原 if-eqz 判断反了（有错误时返回空串、无错误返回 null），改为 if-nez
.method public getLastError()Ljava/lang/String;
    .registers 2
    .annotation runtime Landroid/webkit/JavascriptInterface;
    .end annotation

    iget-object v0, p0, Lcom/cardkey/gate/CardKeyBridge;->lastError:Ljava/lang/String;

    if-nez v0, :ok

    const-string v0, ""

    :ok
    return-object v0
.end method

# 返回当前 App 版本名（如 "5.0"），供 WebView 内网页做强制更新比对
.method public getAppVersion()Ljava/lang/String;
    .registers 5
    .annotation runtime Landroid/webkit/JavascriptInterface;
    .end annotation
    :try_start_0
    iget-object v0, p0, Lcom/cardkey/gate/CardKeyBridge;->mActivity:Landroid/app/Activity;

    invoke-virtual {v0}, Landroid/app/Activity;->getPackageManager()Landroid/content/pm/PackageManager;

    move-result-object v0

    iget-object v1, p0, Lcom/cardkey/gate/CardKeyBridge;->mActivity:Landroid/app/Activity;

    invoke-virtual {v1}, Landroid/app/Activity;->getPackageName()Ljava/lang/String;

    move-result-object v1

    const/4 v2, 0x0

    invoke-virtual {v0, v1, v2}, Landroid/content/pm/PackageManager;->getPackageInfo(Ljava/lang/String;I)Landroid/content/pm/PackageInfo;

    move-result-object v0

    iget-object v0, v0, Landroid/content/pm/PackageInfo;->versionName:Ljava/lang/String;

    move-object v3, v0
    :try_end_0
    .catch Ljava/lang/Exception; {:try_start_0 .. :try_end_0} :catch_0
    return-object v3
    :catch_0
    const-string v3, "0"
    return-object v3
.end method

# 下载并安装更新：用系统 DownloadManager 下载到公开下载目录，完成后自动弹出安装
.method public downloadAndInstall(Ljava/lang/String;)V
    .registers 13
    .annotation runtime Landroid/webkit/JavascriptInterface;
    .end annotation

    # 清空上次错误
    const-string v0, ""

    iput-object v0, p0, Lcom/cardkey/gate/CardKeyBridge;->lastError:Ljava/lang/String;

    :try_start
    const-string v0, "download"

    iget-object v1, p0, Lcom/cardkey/gate/CardKeyBridge;->mActivity:Landroid/app/Activity;

    invoke-virtual {v1, v0}, Landroid/app/Activity;->getSystemService(Ljava/lang/String;)Ljava/lang/Object;

    move-result-object v0

    check-cast v0, Landroid/app/DownloadManager;

    move-object v7, v0

    invoke-static {p1}, Landroid/net/Uri;->parse(Ljava/lang/String;)Landroid/net/Uri;

    move-result-object v0

    new-instance v8, Landroid/app/DownloadManager$Request;

    invoke-direct {v8, v0}, Landroid/app/DownloadManager$Request;-><init>(Landroid/net/Uri;)V

    const-string v0, "application/vnd.android.package-archive"

    invoke-virtual {v8, v0}, Landroid/app/DownloadManager$Request;->setMimeType(Ljava/lang/String;)Landroid/app/DownloadManager$Request;
    move-result-object v8

    const/4 v0, 0x1

    invoke-virtual {v8, v0}, Landroid/app/DownloadManager$Request;->setNotificationVisibility(I)Landroid/app/DownloadManager$Request;
    move-result-object v8

    const-string v0, "download"

    const-string v1, "cardkey_update.apk"

    invoke-virtual {v8, v0, v1}, Landroid/app/DownloadManager$Request;->setDestinationInExternalPublicDir(Ljava/lang/String;Ljava/lang/String;)Landroid/app/DownloadManager$Request;
    move-result-object v8

    invoke-virtual {v7, v8}, Landroid/app/DownloadManager;->enqueue(Landroid/app/DownloadManager$Request;)J

    move-result-wide v0

    move-wide v5, v0

    new-instance v9, Lcom/cardkey/gate/CardKeyBridge$1;

    iget-object v0, p0, Lcom/cardkey/gate/CardKeyBridge;->mActivity:Landroid/app/Activity;

    invoke-direct {v9, v0, v5, v6}, Lcom/cardkey/gate/CardKeyBridge$1;-><init>(Landroid/app/Activity;J)V

    new-instance v10, Landroid/content/IntentFilter;

    const-string v0, "android.intent.action.DOWNLOAD_COMPLETE"

    invoke-direct {v10, v0}, Landroid/content/IntentFilter;-><init>(Ljava/lang/String;)V

    iget-object v0, p0, Lcom/cardkey/gate/CardKeyBridge;->mActivity:Landroid/app/Activity;

    invoke-virtual {v0, v9, v10}, Landroid/app/Activity;->registerReceiver(Landroid/content/BroadcastReceiver;Landroid/content/IntentFilter;)Landroid/content/Intent;
    :try_end
    goto :done

    :catch
    move-exception v5

    # 上报下载失败原因（WebView 内 getLastError 可读）
    invoke-virtual {v5}, Ljava/lang/Throwable;->toString()Ljava/lang/String;

    move-result-object v6

    invoke-virtual {p0, v6}, Lcom/cardkey/gate/CardKeyBridge;->setLastError(Ljava/lang/String;)V

    const-string v0, "CardKey"

    const-string v1, "downloadAndInstall failed"

    invoke-static {v0, v1, v5}, Landroid/util/Log;->e(Ljava/lang/String;Ljava/lang/String;Ljava/lang/Throwable;)I

    :done
    return-void

    .catch Ljava/lang/Exception; {:try_start .. :try_end} :catch
.end method

# 返回本地崩溃日志全文（供验证页 JS 自动上报服务器，终端零操作排障）
.method public getLog()Ljava/lang/String;
    .registers 2
    .annotation runtime Landroid/webkit/JavascriptInterface;
    .end annotation

    invoke-static {}, Lcom/cardkey/gate/CrashLog;->getLog()Ljava/lang/String;

    move-result-object v0

    return-object v0
.end method

# 清空本地崩溃日志（上报成功后调用，避免重复上报）
.method public clearLog()V
    .registers 1
    .annotation runtime Landroid/webkit/JavascriptInterface;
    .end annotation

    invoke-static {}, Lcom/cardkey/gate/CrashLog;->clearLog()V

    return-void
.end method

# 返回注入参数 JSON（api/owner/code/ver/app），供本地验证页(assets/gate.html)读取。
# 本地壳模式：验证页离线也能打开显示，但验证必须联网（安全内容不落本地）。
.method public getGateParams()Ljava/lang/String;
    .registers 2
    .annotation runtime Landroid/webkit/JavascriptInterface;
    .end annotation

    const-string v0, "GATE_PARAMS_PLACEHOLDER"

    return-object v0
.end method

# 返回壳版本号（YYYYMMDD）：排查"装的是哪版壳"用——旧壳无此方法(JS 调用返回 undefined)，
# 新壳返回 20260811（远程优先+离线兜底版）。以后判断某台机器是否需要换新包时一眼可辨。
.method public getShellVersion()Ljava/lang/String;
    .registers 2
    .annotation runtime Landroid/webkit/JavascriptInterface;
    .end annotation

    const-string v0, "20260811"

    return-object v0
.end method
