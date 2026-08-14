# 崩溃日志器（2026-08-06 新增）：
#  - 全局捕获未捕获异常/Error（UncaughtExceptionHandler），写本地日志文件（外部+内部双写）
#  - 提供 getLog()/clearLog()，由验证页(gate.html)下次打开时自动读取并 POST 上报服务器
#  - 以后任何闪退/进不去都能远程拿到日志，终端用户零操作
.class public Lcom/cardkey/gate/CrashLog;
.super Ljava/lang/Object;
.source "CrashLog.java"

# interfaces
.implements Ljava/lang/Thread$UncaughtExceptionHandler;

# instance fields
.field public activity:Landroid/app/Activity;

.field public orig:Ljava/lang/Thread$UncaughtExceptionHandler;

# static fields（供 getLog/clearLog 静态方法拿 Context）
.field public static sCtx:Landroid/content/Context;

# direct methods
.method public constructor <init>(Landroid/app/Activity;)V
    .registers 3

    invoke-direct {p0}, Ljava/lang/Object;-><init>()V

    iput-object p1, p0, Lcom/cardkey/gate/CrashLog;->activity:Landroid/app/Activity;

    sput-object p1, Lcom/cardkey/gate/CrashLog;->sCtx:Landroid/content/Context;

    invoke-static {}, Ljava/lang/Thread;->getDefaultUncaughtExceptionHandler()Ljava/lang/Thread$UncaughtExceptionHandler;

    move-result-object v0

    iput-object v0, p0, Lcom/cardkey/gate/CrashLog;->orig:Ljava/lang/Thread$UncaughtExceptionHandler;

    return-void
.end method

# 追加写一行到文件（FileWriter 追加模式）
.method public static appendFile(Ljava/io/File;Ljava/lang/String;)V
    .registers 5

    :try_start
    new-instance v0, Ljava/io/FileWriter;

    const/4 v1, 0x1

    invoke-direct {v0, p0, v1}, Ljava/io/FileWriter;-><init>(Ljava/io/File;Z)V

    invoke-virtual {v0, p1}, Ljava/io/FileWriter;->write(Ljava/lang/String;)V

    invoke-virtual {v0}, Ljava/io/FileWriter;->flush()V

    invoke-virtual {v0}, Ljava/io/FileWriter;->close()V
    :try_end
    .catch Ljava/lang/Throwable; {:try_start .. :try_end} :ign

    :ign
    return-void
.end method

# 事件日志：时间戳 + 消息 追加到 external + internal 两个「崩溃日志.txt」（中文名好找）
.method public static log(Landroid/content/Context;Ljava/lang/String;)V
    .registers 9

    :try_start
    new-instance v0, Ljava/lang/StringBuilder;

    invoke-direct {v0}, Ljava/lang/StringBuilder;-><init>()V

    invoke-static {}, Ljava/lang/System;->currentTimeMillis()J

    move-result-wide v1

    invoke-virtual {v0, v1, v2}, Ljava/lang/StringBuilder;->append(J)Ljava/lang/StringBuilder;

    const-string v3, " "

    invoke-virtual {v0, v3}, Ljava/lang/StringBuilder;->append(Ljava/lang/String;)Ljava/lang/StringBuilder;

    invoke-virtual {v0, p1}, Ljava/lang/StringBuilder;->append(Ljava/lang/String;)Ljava/lang/StringBuilder;

    const-string v3, "\n"

    invoke-virtual {v0, v3}, Ljava/lang/StringBuilder;->append(Ljava/lang/String;)Ljava/lang/StringBuilder;

    invoke-virtual {v0}, Ljava/lang/StringBuilder;->toString()Ljava/lang/String;

    move-result-object v3

    # external 文件
    const/4 v4, 0x0

    invoke-virtual {p0, v4}, Landroid/content/Context;->getExternalFilesDir(Ljava/lang/String;)Ljava/io/File;

    move-result-object v4

    if-eqz v4, :skip_ext

    new-instance v5, Ljava/io/File;

    const-string v6, "崩溃日志.txt"

    invoke-direct {v5, v4, v6}, Ljava/io/File;-><init>(Ljava/io/File;Ljava/lang/String;)V

    invoke-static {v5, v3}, Lcom/cardkey/gate/CrashLog;->appendFile(Ljava/io/File;Ljava/lang/String;)V

    :skip_ext
    # internal 文件
    invoke-virtual {p0}, Landroid/content/Context;->getFilesDir()Ljava/io/File;

    move-result-object v4

    new-instance v5, Ljava/io/File;

    const-string v6, "崩溃日志.txt"

    invoke-direct {v5, v4, v6}, Ljava/io/File;-><init>(Ljava/io/File;Ljava/lang/String;)V

    invoke-static {v5, v3}, Lcom/cardkey/gate/CrashLog;->appendFile(Ljava/io/File;Ljava/lang/String;)V
    :try_end
    .catch Ljava/lang/Throwable; {:try_start .. :try_end} :ign2

    :ign2
    return-void
.end method

# 读取一个日志文件内容追加到 StringBuilder
.method public static readAppend(Ljava/io/File;Ljava/lang/StringBuilder;)V
    .registers 8

    :try_start
    invoke-virtual {p0}, Ljava/io/File;->exists()Z

    move-result v0

    if-eqz v0, :done

    new-instance v1, Ljava/io/BufferedReader;

    new-instance v2, Ljava/io/FileReader;

    invoke-direct {v2, p0}, Ljava/io/FileReader;-><init>(Ljava/io/File;)V

    invoke-direct {v1, v2}, Ljava/io/BufferedReader;-><init>(Ljava/io/Reader;)V

    :loop
    invoke-virtual {v1}, Ljava/io/BufferedReader;->readLine()Ljava/lang/String;

    move-result-object v3

    if-eqz v3, :eof

    invoke-virtual {p1, v3}, Ljava/lang/StringBuilder;->append(Ljava/lang/String;)Ljava/lang/StringBuilder;

    const-string v4, "\n"

    invoke-virtual {p1, v4}, Ljava/lang/StringBuilder;->append(Ljava/lang/String;)Ljava/lang/StringBuilder;

    goto :loop

    :eof
    invoke-virtual {v1}, Ljava/io/BufferedReader;->close()V

    :done
    :try_end
    .catch Ljava/lang/Throwable; {:try_start .. :try_end} :ign

    :ign
    return-void
.end method

# 读取全部日志（external + internal），供验证页 JS 上报
.method public static getLog()Ljava/lang/String;
    .registers 7

    const-string v0, ""

    :try_start
    sget-object v1, Lcom/cardkey/gate/CrashLog;->sCtx:Landroid/content/Context;

    if-eqz v1, :ret

    new-instance v2, Ljava/lang/StringBuilder;

    invoke-direct {v2}, Ljava/lang/StringBuilder;-><init>()V

    const/4 v3, 0x0

    invoke-virtual {v1, v3}, Landroid/content/Context;->getExternalFilesDir(Ljava/lang/String;)Ljava/io/File;

    move-result-object v3

    if-eqz v3, :skip_ext

    new-instance v4, Ljava/io/File;

    const-string v5, "崩溃日志.txt"

    invoke-direct {v4, v3, v5}, Ljava/io/File;-><init>(Ljava/io/File;Ljava/lang/String;)V

    invoke-static {v4, v2}, Lcom/cardkey/gate/CrashLog;->readAppend(Ljava/io/File;Ljava/lang/StringBuilder;)V

    :skip_ext
    invoke-virtual {v1}, Landroid/content/Context;->getFilesDir()Ljava/io/File;

    move-result-object v3

    new-instance v4, Ljava/io/File;

    const-string v5, "崩溃日志.txt"

    invoke-direct {v4, v3, v5}, Ljava/io/File;-><init>(Ljava/io/File;Ljava/lang/String;)V

    invoke-static {v4, v2}, Lcom/cardkey/gate/CrashLog;->readAppend(Ljava/io/File;Ljava/lang/StringBuilder;)V

    invoke-virtual {v2}, Ljava/lang/StringBuilder;->toString()Ljava/lang/String;

    move-result-object v0

    :ret
    :try_end
    .catch Ljava/lang/Throwable; {:try_start .. :try_end} :ign

    :ign
    return-object v0
.end method

# 清空日志（上报成功后调用）
.method public static clearLog()V
    .registers 5

    :try_start
    sget-object v0, Lcom/cardkey/gate/CrashLog;->sCtx:Landroid/content/Context;

    if-eqz v0, :done

    const/4 v1, 0x0

    invoke-virtual {v0, v1}, Landroid/content/Context;->getExternalFilesDir(Ljava/lang/String;)Ljava/io/File;

    move-result-object v1

    if-eqz v1, :skip_ext

    new-instance v2, Ljava/io/File;

    const-string v3, "崩溃日志.txt"

    invoke-direct {v2, v1, v3}, Ljava/io/File;-><init>(Ljava/io/File;Ljava/lang/String;)V

    invoke-virtual {v2}, Ljava/io/File;->delete()Z

    :skip_ext
    invoke-virtual {v0}, Landroid/content/Context;->getFilesDir()Ljava/io/File;

    move-result-object v1

    new-instance v2, Ljava/io/File;

    const-string v3, "崩溃日志.txt"

    invoke-direct {v2, v1, v3}, Ljava/io/File;-><init>(Ljava/io/File;Ljava/lang/String;)V

    invoke-virtual {v2}, Ljava/io/File;->delete()Z

    :done
    :try_end
    .catch Ljava/lang/Throwable; {:try_start .. :try_end} :ign

    :ign
    return-void
.end method

# 崩溃入口：组装堆栈 → 写日志 → 转交原 handler（或杀进程）
.method public uncaughtException(Ljava/lang/Thread;Ljava/lang/Throwable;)V
    .registers 9

    :try_start
    new-instance v0, Ljava/io/StringWriter;

    invoke-direct {v0}, Ljava/io/StringWriter;-><init>()V

    new-instance v1, Ljava/io/PrintWriter;

    invoke-direct {v1, v0}, Ljava/io/PrintWriter;-><init>(Ljava/io/Writer;)V

    invoke-virtual {v1, p2}, Ljava/io/PrintWriter;->printStackTrace(Ljava/lang/Throwable;)V

    invoke-virtual {v1}, Ljava/io/PrintWriter;->flush()V

    invoke-virtual {v0}, Ljava/io/StringWriter;->toString()Ljava/lang/String;

    move-result-object v2

    new-instance v3, Ljava/lang/StringBuilder;

    invoke-direct {v3}, Ljava/lang/StringBuilder;-><init>()V

    const-string v4, "==== CARDKEY CRASH "

    invoke-virtual {v3, v4}, Ljava/lang/StringBuilder;->append(Ljava/lang/String;)Ljava/lang/StringBuilder;

    invoke-static {}, Ljava/lang/System;->currentTimeMillis()J

    # 2026-08-07 审计修复：.registers 9 下参数 p0=this=v6，原 move-result-wide v5 会占用 v5+v6 把 p0(this) 覆盖，
    # 导致之后 iget-object p0.activity 取到垃圾引用 → 崩溃日志永远写不进去，且 orig handler 判断/回调也读垃圾引用，
    # 存在二次崩溃风险。改用局部寄存器对 v4/v5（与 p0/p1/p2 及正在使用的 v3 均不冲突）。
    move-result-wide v4

    invoke-virtual {v3, v4, v5}, Ljava/lang/StringBuilder;->append(J)Ljava/lang/StringBuilder;

    const-string v4, " thread="

    invoke-virtual {v3, v4}, Ljava/lang/StringBuilder;->append(Ljava/lang/String;)Ljava/lang/StringBuilder;

    invoke-virtual {p1}, Ljava/lang/Thread;->getName()Ljava/lang/String;

    move-result-object v4

    invoke-virtual {v3, v4}, Ljava/lang/StringBuilder;->append(Ljava/lang/String;)Ljava/lang/StringBuilder;

    const-string v4, "\n"

    invoke-virtual {v3, v4}, Ljava/lang/StringBuilder;->append(Ljava/lang/String;)Ljava/lang/StringBuilder;

    invoke-virtual {v3, v2}, Ljava/lang/StringBuilder;->append(Ljava/lang/String;)Ljava/lang/StringBuilder;

    invoke-virtual {v3}, Ljava/lang/StringBuilder;->toString()Ljava/lang/String;

    move-result-object v2

    iget-object v3, p0, Lcom/cardkey/gate/CrashLog;->activity:Landroid/app/Activity;

    invoke-static {v3, v2}, Lcom/cardkey/gate/CrashLog;->log(Landroid/content/Context;Ljava/lang/String;)V
    :try_end
    .catch Ljava/lang/Throwable; {:try_start .. :try_end} :ign

    :ign
    # 转交原 handler，保持系统默认行为
    iget-object v0, p0, Lcom/cardkey/gate/CrashLog;->orig:Ljava/lang/Thread$UncaughtExceptionHandler;

    if-eqz v0, :kill

    # 2026-08-06 修复：Thread$UncaughtExceptionHandler 是接口，必须 invoke-interface！
    # 原 invoke-virtual 触发 ART VerifyError → 日志器类加载即崩 → App 启动闪退（1786025185 直接闪退根因）
    invoke-interface {v0, p1, p2}, Ljava/lang/Thread$UncaughtExceptionHandler;->uncaughtException(Ljava/lang/Thread;Ljava/lang/Throwable;)V

    return-void

    :kill
    invoke-static {}, Landroid/os/Process;->myPid()I

    move-result v1

    invoke-static {v1}, Landroid/os/Process;->killProcess(I)V

    return-void
.end method
