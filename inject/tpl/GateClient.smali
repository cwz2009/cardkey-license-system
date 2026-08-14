.class public Lcom/cardkey/gate/GateClient;
.super Landroid/webkit/WebViewClient;
.source "GateActivity.java"

# 远程优先 + 本地兜底验证页（2026-08-11 修复版）：
# 联网加载服务器最新验证页失败（离线/断网/5xx）时，自动切到内置 assets/gate.html，
# 保证没网也能打开验证界面（"看着没跑路"）；已兜底过则忽略，避免死循环。
# ⚠️ 原"GateActivity$2"作为非静态内部类在 d8 编译时会被注入合成 this$0 字段，
# 与手写 smali 不兼容导致 NPE 闪退（2026-08-11 雷电测试捕获）。
# 修复：改为独立类 GateClient，不带 $ 命名，构造方法 2 参数，彻底无 inner 关系。

.field public mWebView:Landroid/webkit/WebView;

.field public mFallback:Ljava/lang/String;

.field public mFallen:Z


.method public constructor <init>(Landroid/webkit/WebView;Ljava/lang/String;)V
    .registers 3

    invoke-direct {p0}, Landroid/webkit/WebViewClient;-><init>()V

    iput-object p1, p0, Lcom/cardkey/gate/GateClient;->mWebView:Landroid/webkit/WebView;

    iput-object p2, p0, Lcom/cardkey/gate/GateClient;->mFallback:Ljava/lang/String;

    # mFallen 默认 false（Z 字段初值），不再显式初始化。
    # ⚠️ 之前用 const/4 v0 + iput-boolean v0 时，d8 会把 v0 优化重映射成 p0，
    # 导致 iput-boolean p0, p0 变成"把 null 写进 this"闪退（2026-08-11 雷电测试捕获）。

    return-void
.end method


# 兼容低版本：主页面加载失败 → 切内置页
.method public onReceivedError(Landroid/webkit/WebView;ILjava/lang/String;Ljava/lang/String;)V
    .registers 7

    iget-boolean v0, p0, Lcom/cardkey/gate/GateClient;->mFallen:Z

    if-nez v0, :done

    const/4 v0, 0x1

    iput-boolean v0, p0, Lcom/cardkey/gate/GateClient;->mFallen:Z

    iget-object v0, p0, Lcom/cardkey/gate/GateClient;->mWebView:Landroid/webkit/WebView;

    iget-object v1, p0, Lcom/cardkey/gate/GateClient;->mFallback:Ljava/lang/String;

    invoke-virtual {v0, v1}, Landroid/webkit/WebView;->loadUrl(Ljava/lang/String;)V

    :done
    return-void
.end method


# API 23+：只对主框架错误做离线兜底
.method public onReceivedError(Landroid/webkit/WebView;Landroid/webkit/WebResourceRequest;Landroid/webkit/WebResourceError;)V
    .registers 5

    if-eqz p2, :done

    invoke-virtual {p2}, Landroid/webkit/WebResourceRequest;->isForMainFrame()Z

    move-result v0

    if-nez v0, :done

    iget-boolean v0, p0, Lcom/cardkey/gate/GateClient;->mFallen:Z

    if-nez v0, :done

    const/4 v0, 0x1

    iput-boolean v0, p0, Lcom/cardkey/gate/GateClient;->mFallen:Z

    iget-object v0, p0, Lcom/cardkey/gate/GateClient;->mWebView:Landroid/webkit/WebView;

    iget-object v1, p0, Lcom/cardkey/gate/GateClient;->mFallback:Ljava/lang/String;

    invoke-virtual {v0, v1}, Landroid/webkit/WebView;->loadUrl(Ljava/lang/String;)V

    :done
    return-void
.end method


# API 23+：服务器返回错误(404/5xx)也兜底到内置页
.method public onReceivedHttpError(Landroid/webkit/WebView;Landroid/webkit/WebResourceRequest;Landroid/webkit/WebResourceResponse;)V
    .registers 5

    if-eqz p2, :done

    invoke-virtual {p2}, Landroid/webkit/WebResourceRequest;->isForMainFrame()Z

    move-result v0

    if-nez v0, :done

    iget-boolean v0, p0, Lcom/cardkey/gate/GateClient;->mFallen:Z

    if-nez v0, :done

    const/4 v0, 0x1

    iput-boolean v0, p0, Lcom/cardkey/gate/GateClient;->mFallen:Z

    iget-object v0, p0, Lcom/cardkey/gate/GateClient;->mWebView:Landroid/webkit/WebView;

    iget-object v1, p0, Lcom/cardkey/gate/GateClient;->mFallback:Ljava/lang/String;

    invoke-virtual {v0, v1}, Landroid/webkit/WebView;->loadUrl(Ljava/lang/String;)V

    :done
    return-void
.end method