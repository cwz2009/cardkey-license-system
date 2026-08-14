# 下载完成广播接收器：DOWNLOAD_COMPLETE 后自动弹出安装（修复：原文件误为 CardKeyBridge 主体拷贝，
# 导致 receiver 类错乱 → 下载完永远不弹安装。改用 DownloadManager.getUriForDownloadedFile 取系统授权 URI，
# ACTION_VIEW 直接安装，兼容 Android 7+，无需 FileProvider / 无需 Uri.fromFile）
.class Lcom/cardkey/gate/CardKeyBridge$1;
.super Landroid/content/BroadcastReceiver;
.source "CardKeyBridge.java"

# annotations
.annotation system Ldalvik/annotation/EnclosingClass;
    value = Lcom/cardkey/gate/CardKeyBridge;
.end annotation

.annotation system Ldalvik/annotation/InnerClass;
    accessFlags = 0x0
    name = null
.end annotation

# instance fields
.field final synthetic val$activity:Landroid/app/Activity;

.field final synthetic val$id:J

# direct methods
.method constructor <init>(Landroid/app/Activity;J)V
    .registers 5

    iput-object p1, p0, Lcom/cardkey/gate/CardKeyBridge$1;->val$activity:Landroid/app/Activity;

    iput-wide p2, p0, Lcom/cardkey/gate/CardKeyBridge$1;->val$id:J

    invoke-direct {p0}, Landroid/content/BroadcastReceiver;-><init>()V

    return-void
.end method

# virtual methods
.method public onReceive(Landroid/content/Context;Landroid/content/Intent;)V
    .registers 12

    # 1) 校验下载 id 是否匹配本次更新
    iget-wide v2, p0, Lcom/cardkey/gate/CardKeyBridge$1;->val$id:J

    const-string v0, "extra_download_id"

    const-wide/16 v4, -1

    invoke-virtual {p2, v0, v4, v5}, Landroid/content/Intent;->getLongExtra(Ljava/lang/String;J)J

    move-result-wide v0

    cmp-long v6, v0, v2

    # 审计修复(2026-08-06)：cmp-long 相等(0)表示广播 id==本次下载 id，此时应继续弹安装，
    # 原 if-eqz 反了（匹配时走 :ignore 直接返回 → 下载完永远不弹安装）
    if-nez v6, :ignore

    # 2) 通过 DownloadManager 拿系统授权 content URI
    iget-object v0, p0, Lcom/cardkey/gate/CardKeyBridge$1;->val$activity:Landroid/app/Activity;

    const-string v1, "download"

    invoke-virtual {v0, v1}, Landroid/app/Activity;->getSystemService(Ljava/lang/String;)Ljava/lang/Object;

    move-result-object v0

    check-cast v0, Landroid/app/DownloadManager;

    move-object v6, v0

    iget-wide v2, p0, Lcom/cardkey/gate/CardKeyBridge$1;->val$id:J

    invoke-virtual {v6, v2, v3}, Landroid/app/DownloadManager;->getUriForDownloadedFile(J)Landroid/net/Uri;

    move-result-object v7

    if-eqz v7, :fail

    # 3) ACTION_VIEW 打开安装界面（FLAG_GRANT_READ_URI_PERMISSION + NEW_TASK）
    new-instance v8, Landroid/content/Intent;

    invoke-direct {v8}, Landroid/content/Intent;-><init>()V

    const-string v0, "android.intent.action.VIEW"

    invoke-virtual {v8, v0}, Landroid/content/Intent;->setAction(Ljava/lang/String;)Landroid/content/Intent;
    move-result-object v8

    const-string v0, "application/vnd.android.package-archive"

    invoke-virtual {v8, v7, v0}, Landroid/content/Intent;->setDataAndType(Landroid/net/Uri;Ljava/lang/String;)Landroid/content/Intent;
    move-result-object v8

    const/4 v0, 0x1

    invoke-virtual {v8, v0}, Landroid/content/Intent;->addFlags(I)Landroid/content/Intent;
    move-result-object v8

    const/high16 v0, 0x10000000

    invoke-virtual {v8, v0}, Landroid/content/Intent;->addFlags(I)Landroid/content/Intent;
    move-result-object v8

    :try_start
    iget-object v0, p0, Lcom/cardkey/gate/CardKeyBridge$1;->val$activity:Landroid/app/Activity;

    invoke-virtual {v0, v8}, Landroid/app/Activity;->startActivity(Landroid/content/Intent;)V
    :try_end
    goto :done

    :fail
    const-string v0, "CardKey"

    const-string v1, "update download not found"

    invoke-static {v0, v1}, Landroid/util/Log;->e(Ljava/lang/String;Ljava/lang/String;)I

    :done
    return-void

    :ignore
    return-void

    .catch Ljava/lang/Exception; {:try_start .. :try_end} :fail
.end method
