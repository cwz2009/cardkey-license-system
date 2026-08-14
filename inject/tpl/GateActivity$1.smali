# WebView 下载监听：用户点击 APK 下载链接时自动用系统 DownloadManager 下载到公共下载目录
# （修复：无 DownloadListener 时 WebView 点 APK 链接无反应，"立即更新"点了没动静）
.class public Lcom/cardkey/gate/GateActivity$1;
.super Ljava/lang/Object;
.source "GateActivity.java"

# interfaces
.implements Landroid/webkit/DownloadListener;

# annotations
.annotation system Ldalvik/annotation/EnclosingClass;
    value = Lcom/cardkey/gate/GateActivity;
.end annotation

.annotation system Ldalvik/annotation/InnerClass;
    accessFlags = 0x0
    name = null
.end annotation

# instance fields
.field final synthetic this$0:Lcom/cardkey/gate/GateActivity;

# direct methods
.method constructor <init>(Lcom/cardkey/gate/GateActivity;)V
    .registers 2

    iput-object p1, p0, Lcom/cardkey/gate/GateActivity$1;->this$0:Lcom/cardkey/gate/GateActivity;

    invoke-direct {p0}, Ljava/lang/Object;-><init>()V

    return-void
.end method

# virtual methods
.method public onDownloadStart(Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;J)V
    .registers 12

    :try_start
    iget-object v0, p0, Lcom/cardkey/gate/GateActivity$1;->this$0:Lcom/cardkey/gate/GateActivity;

    const-string v1, "download"

    invoke-virtual {v0, v1}, Landroid/app/Activity;->getSystemService(Ljava/lang/String;)Ljava/lang/Object;

    move-result-object v0

    check-cast v0, Landroid/app/DownloadManager;

    move-object v7, v0

    invoke-static {p1}, Landroid/net/Uri;->parse(Ljava/lang/String;)Landroid/net/Uri;

    move-result-object v0

    new-instance v8, Landroid/app/DownloadManager$Request;

    invoke-direct {v8, v0}, Landroid/app/DownloadManager$Request;-><init>(Landroid/net/Uri;)V

    if-eqz p4, :setmime

    invoke-virtual {v8, p4}, Landroid/app/DownloadManager$Request;->setMimeType(Ljava/lang/String;)Landroid/app/DownloadManager$Request;
    move-result-object v8

    goto :setdest

    :setmime
    const-string v0, "application/vnd.android.package-archive"

    invoke-virtual {v8, v0}, Landroid/app/DownloadManager$Request;->setMimeType(Ljava/lang/String;)Landroid/app/DownloadManager$Request;
    move-result-object v8

    :setdest
    const/4 v0, 0x1

    invoke-virtual {v8, v0}, Landroid/app/DownloadManager$Request;->setNotificationVisibility(I)Landroid/app/DownloadManager$Request;
    move-result-object v8

    const-string v0, "download"

    const-string v1, "cardkey_update.apk"

    invoke-virtual {v8, v0, v1}, Landroid/app/DownloadManager$Request;->setDestinationInExternalPublicDir(Ljava/lang/String;Ljava/lang/String;)Landroid/app/DownloadManager$Request;
    move-result-object v8

    invoke-virtual {v7, v8}, Landroid/app/DownloadManager;->enqueue(Landroid/app/DownloadManager$Request;)J
    :try_end
    goto :done

    :catch
    move-exception v9

    const-string v0, "CardKey"

    const-string v1, "download start failed"

    invoke-static {v0, v1, v9}, Landroid/util/Log;->e(Ljava/lang/String;Ljava/lang/String;Ljava/lang/Throwable;)I

    :done
    return-void

    .catch Ljava/lang/Exception; {:try_start .. :try_end} :catch
.end method
