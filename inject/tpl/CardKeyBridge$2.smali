# 主线程执行拉起原应用的 Runnable（修复：addJavascriptInterface 的 JS 调用跑在 JavaBridge 非主线程，
# 直接在非主线程 startActivity/finish 可能抛 CalledFromWrongThreadException 导致"验证通过但进不去"）
.class public Lcom/cardkey/gate/CardKeyBridge$2;
.super Ljava/lang/Object;
.source "CardKeyBridge.java"

# interfaces
.implements Ljava/lang/Runnable;

# annotations
.annotation system Ldalvik/annotation/EnclosingClass;
    value = Lcom/cardkey/gate/CardKeyBridge;
.end annotation

.annotation system Ldalvik/annotation/InnerClass;
    accessFlags = 0x0
    name = null
.end annotation

# instance fields
.field final synthetic this$0:Lcom/cardkey/gate/CardKeyBridge;

# direct methods
.method constructor <init>(Lcom/cardkey/gate/CardKeyBridge;)V
    .registers 2

    iput-object p1, p0, Lcom/cardkey/gate/CardKeyBridge$2;->this$0:Lcom/cardkey/gate/CardKeyBridge;

    invoke-direct {p0}, Ljava/lang/Object;-><init>()V

    return-void
.end method

# virtual methods
.method public run()V
    .registers 8

    # 组装显式 Intent：setClassName(包名, 原入口类) + NEW_TASK
    new-instance v0, Landroid/content/Intent;

    invoke-direct {v0}, Landroid/content/Intent;-><init>()V

    const/high16 v1, 0x10000000

    invoke-virtual {v0, v1}, Landroid/content/Intent;->addFlags(I)Landroid/content/Intent;
    move-result-object v0

    iget-object v1, p0, Lcom/cardkey/gate/CardKeyBridge$2;->this$0:Lcom/cardkey/gate/CardKeyBridge;

    iget-object v2, v1, Lcom/cardkey/gate/CardKeyBridge;->mActivity:Landroid/app/Activity;

    invoke-virtual {v2}, Landroid/app/Activity;->getPackageName()Ljava/lang/String;

    move-result-object v3

    iget-object v4, v1, Lcom/cardkey/gate/CardKeyBridge;->mOrigClass:Ljava/lang/String;

    invoke-virtual {v0, v3, v4}, Landroid/content/Intent;->setClassName(Ljava/lang/String;Ljava/lang/String;)Landroid/content/Intent;
    move-result-object v0

    :try_start
    iget-object v2, v1, Lcom/cardkey/gate/CardKeyBridge;->mActivity:Landroid/app/Activity;

    invoke-virtual {v2, v0}, Landroid/app/Activity;->startActivity(Landroid/content/Intent;)V
    :try_end
    goto :done

    :catch
    move-exception v5

    # 2026-08-06 升级：catch Exception → catch Throwable。
    # 手写 smali 的 Error（NoSuchMethodError/VerifyError 等）是 Error 不是 Exception，
    # 之前 catch(Exception) 兜不住 → 壳侧错误直接闪退；Throwable 全兜 → 至少弹提示不闪退。
    # 记录失败原因，供 WebView 内 getLastError() 读取显示
    invoke-virtual {v5}, Ljava/lang/Throwable;->toString()Ljava/lang/String;

    move-result-object v6

    invoke-virtual {v1, v6}, Lcom/cardkey/gate/CardKeyBridge;->setLastError(Ljava/lang/String;)V

    const-string v6, "CardKey"

    const-string v7, "launchOriginal failed"

    invoke-static {v6, v7, v5}, Landroid/util/Log;->e(Ljava/lang/String;Ljava/lang/String;Ljava/lang/Throwable;)I

    iget-object v6, v1, Lcom/cardkey/gate/CardKeyBridge;->mActivity:Landroid/app/Activity;

    const-string v7, "启动原应用失败，请手动打开"

    const/4 v2, 0x1

    invoke-static {v6, v7, v2}, Landroid/widget/Toast;->makeText(Landroid/content/Context;Ljava/lang/CharSequence;I)Landroid/widget/Toast;

    move-result-object v6

    invoke-virtual {v6}, Landroid/widget/Toast;->show()V

    :done
    iget-object v6, v1, Lcom/cardkey/gate/CardKeyBridge;->mActivity:Landroid/app/Activity;

    invoke-virtual {v6}, Landroid/app/Activity;->finish()V

    return-void

    .catch Ljava/lang/Throwable; {:try_start .. :try_end} :catch
.end method
