#!/usr/bin/env python3
# 卡密验证注入脚本：把验证壳(GateActivity+CardKeyBridge)注入到任意第三方 APK
# 用法: python3 inject.py <目标.apk> <输出.apk>
#
# 构建策略（修复 apktool 2.5.0-dirty 自带 aapt2 损坏、系统 aapt2 又无法解析
# Material 私有属性 state_dragged/state_liftable 等，导致 build code=1 失败）：
#   采用 apktool 正常解码（拿到可读 XML 的 manifest + smali），改 LAUNCHER、加壳，
#   然后用 `apktool b --no-res` 打包。--no-res 会【直接复用原始 APK 的二进制
#   res/ 与 resources.arsc】，不再重编译资源，因此 Material 私有属性永远不会被 aapt
#   解析，注入对任意带 Material 设计的 APK 都能成功。--no-res 仅重编译 manifest
#   （manifest 里只有 icon/label/theme 等普通 @ 引用，可正常解析）。
#
# 自愈机制（2026-08-05 新增）：
#   注入过程任何一步失败，不再直接报错，而是自动换方案重试：
#     正常模式失败 → 自动切轻量模式（不重编译资源，绕开加固/资源问题）
#     轻量模式签名失败 → 自动换签名策略重试（v1+v2 → v1 → v2）
#     轻量模式打包/结构异常 → 自动重新打包一次
#   全部尝试完仍失败，输出 [FINAL_ERR] <中文原因>（含已尝试方案），供注入服务直接返回给用户。
#   自愈过程通过 [HEAL] 标记行汇报，前端实时展示"自动修复"过程。
#
# 说明：
#  - 由注入服务(inject_server.py)调用，故所有工作目录都放在 /var/www/inject 下。
#  - 二进制(apktool/zipalign/apksigner/java)由调用方的 shell 通过 PATH 提供。
import sys, os, re, shutil, subprocess

APKTOOL = 'apktool'
WORK = '/var/www/inject/work'
TPL = '/var/www/inject/tpl'
KS = '/opt/android-tools/release.keystore'
KS_PASS = 'cardkey123'
ALIAS = 'cardkey'
GATE_CLS = 'com.cardkey.gate.GateActivity'
SHELL_ACTIVITY = 'com.cardkey.demo.MainActivity'   # 模板里硬编码的原入口
SHELL_PKG = 'com.cardkey.demo'                      # 模板里硬编码的 app 包名
# 壳加载的验证页地址（GateActivity 模板里用 SERVER_PLACEHOLDER 占位，注入时替换）。
# 可用环境变量 GATE_URL 覆盖，便于以后换服务器/域名。
# 2026-08-08：主站统一为 YOUR_SERVER_IP，默认值必须指向 158，避免 CLI 直跑注入出坏包。
GATE_URL = os.environ.get('GATE_URL', 'http://YOUR_SERVER_IP:8080/gate.html')

# ===== 本地验证页（2026-08-11 离线功能）=====
# 壳不再联网加载验证页，改加载打进 APK 的本地 assets/gate.html —— 没网时验证界面也能打开显示
# （"看着没跑路"），但验证/激活必须联网；卡密与密码一律不落本地。
LOCAL_GATE_URL = 'file:///android_asset/gate.html'
GATE_HTML = os.environ.get('GATE_HTML', '/var/www/cardkey8080/gate.html')   # 打包进 APK 的验证页源文件
GATE_PARAMS_RAW = os.environ.get('GATE_PARAMS', '')   # 注入参数 JSON（api/owner/code/ver），由注入服务传入

def gate_params_smali(pkg):
    """构建注入参数 JSON（含 app 包名），并转成 smali const-string 可用的转义形式。"""
    import json as _json
    from urllib.parse import urlparse, parse_qs
    params = {}
    if GATE_PARAMS_RAW:
        try:
            params = _json.loads(GATE_PARAMS_RAW) or {}
        except Exception:
            params = {}
    # 兼容旧 GATE_URL 带参（owner/code/ver）兜底解析
    try:
        u = urlparse(GATE_URL)
        q = parse_qs(u.query)
        params.setdefault('api', '%s://%s' % (u.scheme, u.netloc))
        params.setdefault('owner', (q.get('owner') or [''])[0])
        params.setdefault('code', (q.get('code') or [''])[0])
        params.setdefault('ver', (q.get('ver') or [''])[0])
    except Exception:
        pass
    params['app'] = pkg
    return _json.dumps(params, ensure_ascii=False).replace('"', '\\"')

# 注入时间戳版本（壳 URL 带 &ver=，gate.html 据此比对 gate_min_ver：自身=门槛则不强制更新）。
# 由注入时通过命令行第 3 参数或环境变量 GATE_VER 传入；缺失则壳不带 ver（旧行为→强制更新死循环）。
GATE_VER = os.environ.get('GATE_VER', '')

TAG_RE = r'<(?:activity|activity-alias)\b[^>]*>.*?</(?:activity|activity-alias)>'


class InjectFail(Exception):
    """注入失败（携带原因，由自愈链决定是否换方案重试）。"""
    def __init__(self, msg, log=''):
        super().__init__(msg)
        self.msg = msg
        self.log = log


def run(cmd, timeout=None):
    print('[RUN]', cmd, flush=True)
    try:
        r = subprocess.run(cmd, shell=True, timeout=timeout)
    except subprocess.TimeoutExpired:
        raise InjectFail('命令超时: %s' % cmd)
    if r.returncode != 0:
        raise InjectFail('命令失败(rc=%d): %s' % (r.returncode, cmd))


def prog(pct, msg):
    """向调用方(注入服务)汇报进度，格式 [PROGRESS] pct|msg"""
    print('[PROGRESS] %d|%s' % (pct, msg), flush=True)


def heal(msg):
    """汇报自愈动作，格式 [HEAL] 说明（注入服务收集后随进度返回给前端展示）"""
    print('[HEAL] ' + msg, flush=True)


def classify_error(text):
    """错误分类：返回 (category, 中文说明)。自愈链根据 category 决策换什么方案。"""
    t = (text or '').lower()
    if 'no space left' in t or 'not enough space' in t or 'disk full' in t:
        return 'disk', '服务器磁盘空间不足'
    if 'permission denied' in t or 'operation not permitted' in t:
        return 'perm', '服务器文件权限异常'
    if 'timed out' in t or 'timeout' in t:
        return 'timeout', '注入执行超时'
    if 'command not found' in t or 'no such file' in t or 'cannot execute' in t:
        return 'env', '服务器缺少必要工具或文件'
    if 'signature' in t or 'meta-inf' in t or 'v2/v3' in t or 'sigblock' in t or 'signture' in t or 'apksigner' in t:
        return 'sign', 'APK 签名环节异常'
    if 'bad zip' in t or 'corrupt' in t or 'not a zip' in t or 'zipfile' in t \
            or 'unzip' in t or 'zip -' in t or 'zipalign' in t:
        return 'zip', 'APK 打包/解包结构异常'
    if 'apktool' in t or 'brut.androlib' in t or 'cannot decode' in t or 'unsupported' in t or 'failed to decode' in t:
        return 'shell', '原 APK 无法正常反编译（疑似加固/资源异常）'
    if 'attribute' in t or 'boundary' in t or 'integer' in t or 'manifest' in t or 'not found' in t:
        return 'manifest', 'APK 清单(manifest)/资源结构异常'
    return 'unknown', '未知错误'


CAT_CN = {
    'disk': '服务器磁盘空间不足，无法继续注入',
    'perm': '服务器文件权限异常，注入进程无写入权限',
    'timeout': '注入执行超时（大包或服务器繁忙）',
    'env': '服务器缺少必要工具或文件',
    'sign': 'APK 签名环节异常（原包可能已被签名/加固锁定）',
    'shell': '原 APK 疑似加固/混淆，无法正常反编译',
    'manifest': 'APK 清单(manifest)结构异常，无法正确注入',
    'zip': 'APK 打包结构异常',
    'unknown': '未知错误',
}


def detect_protection(d):
    """扫描解包目录，识别常见加固 SDK 特征（用于提示预期，不阻断注入）。"""
    hits = []
    lib = os.path.join(d, 'lib')
    if os.path.isdir(lib):
        for root, _, files in os.walk(lib):
            for fn in files:
                fnl = fn.lower()
                if any(k in fnl for k in (
                        'jiagu', 'protect', 'dexhelper', 'nesec', 'shell', 'egis',
                        'sgmain', 'secexe', 'nisec', 'apkprotect', 'tup', 'aliyun', 'secneo')):
                    hits.append(fn)
    cdex = os.path.join(d, 'classes.dex')
    if os.path.isfile(cdex) and os.path.getsize(cdex) < 200 * 1024:
        hits.append('classes.dex 仅 %dKB（疑似加固壳）' % (os.path.getsize(cdex) // 1024))
    return hits


def human_error(attempts, last_msg):
    """把自愈过程汇总成一句中文原因，随 [FINAL_ERR] 输出。"""
    tried = ' → '.join(
        ('%s%s' % (a['mode'], ('/' + a['sign']) if a['mode'] == 'light' and a.get('sign') else ''))
        for a in attempts) or '无'
    cat = attempts[-1]['cat'] if attempts else 'unknown'
    cn = CAT_CN.get(cat, '未知错误')
    tail = ((last_msg or '').strip().splitlines() or [''])[-1]
    tail = tail[:160]
    return '%s。已自动尝试：%s，仍未成功。%s' % (cn, tried, ('原始信息：' + tail) if tail else '')


def remove_launcher_filter(block):
    # 仅移除含 MAIN+LAUNCHER 的 intent-filter（顺序无关），保留其它如 VIEW
    if 'android.intent.action.MAIN' in block and 'android.intent.category.LAUNCHER' in block:
        return re.sub(
            r'<intent-filter>.*?(?:android.intent.action.MAIN.*?android.intent.category.LAUNCHER'
            r'|android.intent.category.LAUNCHER.*?android.intent.action.MAIN).*?</intent-filter>',
            '', block, flags=re.S)
    return block


def sign_cmd(al, out, sign_mode):
    """生成 apksigner 签名命令。sign_mode: v1v2（默认最兼容）/ v1 / v2（逐级兜底）。"""
    base = 'apksigner sign --ks %s --ks-pass pass:%s --ks-key-alias %s --key-pass pass:%s' % (KS, KS_PASS, ALIAS, KS_PASS)
    if sign_mode == 'v1':
        base += ' --v1-signing-enabled true --v2-signing-enabled false'
    elif sign_mode == 'v2':
        base += ' --v1-signing-enabled false --v2-signing-enabled true'
    return base + ' --out %s %s' % (out, al)


def light_inject(src, out, sign_mode='v1v2', packer='zipfile'):
    """加固包轻量注入：完全不碰原 dex / 资源（加固再强也不影响）。
    1) unzip 原样解包（二进制 manifest + 加密 dex + 资源原样保留）
    2) edit_manifest.py 二进制编辑 manifest（自动检测 pkg/orig：
       demote 原 LAUNCHER → 加 GateActivity LAUNCHER + usesCleartextTraffic + INTERNET）
    3) 壳 smali 模板占位符替换（GATE_URL/pkg/orig）→ 最小 apktool 工程编译出 classes2.dex
    4) 塞入 → zip 重打包 → zipalign → apksigner 签名 → 校验 → 清理
    """
    import zipfile as _zip
    src_size = os.path.getsize(src)
    prog(15, '检测到加固包，切换轻量模式（原 dex 原样保留）…')
    d = os.path.join(WORK, 'dec_' + str(os.getpid()))
    if os.path.exists(d):
        shutil.rmtree(d)
    os.makedirs(d, exist_ok=True)
    run('cd %s && unzip -q -o %s' % (d, src))

    # 1.5 加固特征检测：命中时提示预期（壳可注入，但加固自身签名/完整性校验无法破解）
    prots = detect_protection(d)
    if prots:
        print('[INFO] 检测到加固特征: %s' % ', '.join(prots[:6]))
        heal('检测到加固保护（%s），已用轻量模式原样保留原壳 dex；若注入后原 App 闪退，'
             '多为加固自身签名/完整性校验导致，注入壳本身不受影响' % ', '.join(prots[:3]))
    else:
        print('[INFO] 未检测到常见加固特征')

    # 2. 二进制改 manifest（自动检测 pkg/orig）
    prog(45, '反编译完成，正在分析入口…')
    em = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'edit_manifest.py')
    manifest = os.path.join(d, 'AndroidManifest.xml')
    r = subprocess.run('python3 %s edit %s %s' % (em, manifest, manifest),
                       shell=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True)
    if r.returncode != 0 or not os.path.isfile(manifest):
        raise InjectFail('二进制 manifest 编辑失败:\n' + ((r.stdout or '')[-400:]) + ((r.stderr or '')[-400:]))
    pkg = orig = ''
    for line in r.stdout.splitlines():
        if line.startswith('DETECT:'):
            for kv in line[7:].split():
                if '=' in kv:
                    k, v = kv.split('=', 1)
                    if k == 'pkg': pkg = v
                    if k == 'orig': orig = v
    print('[INFO] light detect pkg=%s orig=%s' % (pkg, orig))
    if not pkg or not orig:
        raise InjectFail('未能从二进制 manifest 检测到包名/入口')

    # 3. 编译壳 classes2.dex（最小 apktool 工程，占位符注入 GATE_URL/pkg/orig）
    prog(55, '正在注入验证壳…')
    sp = os.path.join(WORK, 'shell_' + str(os.getpid()))
    if os.path.exists(sp):
        shutil.rmtree(sp)
    gate_dir = os.path.join(sp, 'smali', 'com', 'cardkey', 'gate')
    os.makedirs(gate_dir)
    for fn in ('CardKeyBridge.smali', 'CardKeyBridge$1.smali', 'CardKeyBridge$2.smali', 'GateActivity.smali', 'GateActivity$1.smali', 'GateClient.smali', 'CrashLog.smali'):
        p = os.path.join(TPL, fn)
        if os.path.isfile(p):
            shutil.copy(p, gate_dir)
    ga = os.path.join(gate_dir, 'GateActivity.smali')
    s = open(ga, encoding='utf-8').read()
    s = s.replace('SERVER_PLACEHOLDER', GATE_URL)
    s = s.replace('LOCAL_PLACEHOLDER', LOCAL_GATE_URL)
    s = s.replace('PKG_PLACEHOLDER', pkg)
    s = s.replace('ORIG_PLACEHOLDER', orig)
    s = s.replace('VER_PLACEHOLDER', GATE_VER)
    open(ga, 'w', encoding='utf-8').write(s)
    cb = os.path.join(gate_dir, 'CardKeyBridge.smali')
    s = open(cb, encoding='utf-8').read()
    s = s.replace('L' + SHELL_ACTIVITY.replace('.', '/') + ';', 'L' + orig.replace('.', '/') + ';')
    s = s.replace('GATE_PARAMS_PLACEHOLDER', gate_params_smali(pkg))
    open(cb, 'w', encoding='utf-8').write(s)
    open(os.path.join(sp, 'apktool.yml'), 'w', encoding='utf-8').write(
        '!!brut.androlib.meta.MetaInfo\napkFileName: shell.apk\nisFrameworkApk: false\n'
        "packageInfo:\n  forcedPackageId: '127'\n  renameManifestPackage: null\n"
        "sdkInfo:\n  minSdkVersion: '21'\n  targetSdkVersion: '33'\nunknownFiles: {}\n")
    open(os.path.join(sp, 'AndroidManifest.xml'), 'w', encoding='utf-8').write(
        '<?xml version="1.0" encoding="utf-8"?>\n'
        '<manifest xmlns:android="http://schemas.android.com/apk/res/android" package="' + pkg + '">\n'
        '  <uses-sdk android:minSdkVersion="21" android:targetSdkVersion="33"/>\n'
        '  <application android:label="Shell">\n'
        '    <activity android:name="com.cardkey.gate.GateActivity" android:exported="true">\n'
        '      <intent-filter><action android:name="android.intent.action.MAIN"/>'
        '<category android:name="android.intent.category.LAUNCHER"/></intent-filter>\n'
        '    </activity>\n  </application>\n</manifest>\n')
    shx = os.path.join(WORK, 'shell_%d.apk' % os.getpid())
    run('cd %s && apktool b --use-aapt2 -o %s .' % (sp, shx))
    # 壳 dex 用不冲突的序号：原 APK 可能已有多 dex（classes2/3/...），
    # 固定命名 classes2.dex 会覆盖游戏原 dex → 启动缺类闪退（600MB 大包闪退根因）。
    dex_nums = []
    for _n in os.listdir(d):
        _m = re.match(r'classes(\d*)\.dex$', _n)
        if _m:
            dex_nums.append(int(_m.group(1) or 1))
    _maxn = max(dex_nums) if dex_nums else 1
    shell_dex = 'classes.dex' if _maxn == 1 else 'classes%d.dex' % (_maxn + 1)
    print('[INFO] shell dex -> %s (原包已有 dex 最大序号 %d)' % (shell_dex, _maxn))
    run('cd %s && rm -rf dexdir && unzip -q -o %s classes.dex -d dexdir && mv dexdir/classes.dex %s/%s' % (sp, shx, d, shell_dex))

    # 4. 组装 + 对齐 + 签名
    prog(70, '验证壳注入完成，正在打包…')
    # 打包本地验证页 assets/gate.html：没网时验证界面也能打开显示（离线功能）
    try:
        os.makedirs(os.path.join(d, 'assets'), exist_ok=True)
        if os.path.isfile(GATE_HTML):
            shutil.copy(GATE_HTML, os.path.join(d, 'assets', 'gate.html'))
        else:
            print('[WARN] GATE_HTML 不存在，未打包本地验证页:', GATE_HTML)
    except OSError as e:
        print('[WARN] 打包本地验证页失败:', e)
    uns = d + '.unsigned.apk'
    if packer == 'zipfile':
        # Python zipfile 组装：保留目录结构、剔除旧签名残留(META-INF/*.SF/*.RSA/*.MF)，
        # 错误可精确定位到具体 entry，比 shell zip 更稳（特殊文件名/权限不丢）。
        import zipfile as _z
        try:
            with _z.ZipFile(uns, 'w', allowZip64=True) as zf:
                for root, _, files in os.walk(d):
                    for fn in files:
                        full = os.path.join(root, fn)
                        rel = os.path.relpath(full, d).replace(os.sep, '/')
                        rl = rel.lower()
                        if rl.startswith('meta-inf/') and (
                                rl.endswith('.sf') or rl.endswith('.rsa') or rl.endswith('.ec')
                                or rl.endswith('.dsa') or rl.endswith('manifest.mf')):
                            continue
                        zf.write(full, rel, compress_type=_z.ZIP_STORED)
        except Exception as e:
            raise InjectFail('APK 重打包失败(zipfile): %s' % e)
    else:
        # 兜底：shell zip -0 存储模式（速度快，不重新压缩），并剔除旧签名残留
        run('cd %s && zip -0 -q -r %s . -x "*.DS_Store" "*META-INF/*.SF" "*META-INF/*.RSA" "*META-INF/*.MF" "*META-INF/*.EC"' % (d, uns))
    prog(86, '打包完成，正在对齐优化…')
    al = d + '.aligned.apk'
    run('zipalign -p 4 %s %s' % (uns, al))
    # 对齐自检：so 需页对齐，Android 7+ 安装/加载严格检查，对齐异常立即暴露
    run('zipalign -c -p 4 %s' % al)
    # manifest 可解析性验证：aapt2 解析失败 = 结构被改坏，立即报 manifest 类错误换方案
    try:
        rv = subprocess.run(['aapt2', 'dump', 'xmltree', al, '--file', 'AndroidManifest.xml'],
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=120)
        if rv.returncode != 0:
            raise InjectFail('注入后 manifest 无法解析（结构异常）:\n' + ((rv.stderr or rv.stdout or b'').decode('utf-8', 'replace')[-300:]))
    except subprocess.TimeoutExpired:
        pass
    prog(92, '正在签名（%s）…' % sign_mode)
    # 统一 v1+v2 签名（实测 600MB 仅约 18 秒）：v1 兼容 Android 5.0+，
    # 若用 v2-only（Android 7+），老设备安装会报"解析包时出现问题/与操作系统不兼容"。
    # 失败时由自愈链逐级降级 v1 / v2。
    run(sign_cmd(al, out, sign_mode))

    # 5. 校验
    prog(96, '正在校验产物…')
    valid = False
    try:
        with _zip.ZipFile(out) as zf:
            names = zf.namelist()
            valid = any(n.endswith('.dex') for n in names) and 'AndroidManifest.xml' in names and shell_dex in names
    except Exception:
        valid = False
    if not valid or not os.path.exists(out) or os.path.getsize(out) < 1024:
        raise InjectFail('轻量注入产物不是有效 APK')
    print('[OK] injected APK (light, sign=%s) ->' % sign_mode, out)
    prog(100, '注入完成')
    for tmp in (uns, al, shx):
        try:
            os.remove(tmp)
        except OSError:
            pass
    for tdir in (d, sp):
        shutil.rmtree(tdir, ignore_errors=True)


def normal_inject(src, out):
    """正常注入：apktool 全量反编译 → 改 LAUNCHER/加壳 → apktool b --no-res → 对齐 → 签名 → 校验。
    任何一步失败抛 InjectFail，由 main 自愈链决定是否切轻量模式重试。"""
    d = os.path.join(WORK, 'dec_' + str(os.getpid()))
    if os.path.exists(d):
        shutil.rmtree(d)

    # 解码：正常解码拿到可读 XML 的 AndroidManifest.xml（便于改 LAUNCHER / 加壳）+ smali 源码
    prog(15, '正在反编译 APK…（大文件约 1-2 分钟）')
    run('%s d -f -o %s %s' % (APKTOOL, d, src))
    prog(45, '反编译完成，正在分析入口…')

    # 主 smali 目录（支持多 dex：smali / smali_classes2 ...）
    if os.path.isdir(os.path.join(d, 'smali')):
        smali = os.path.join(d, 'smali')
    else:
        ds = sorted(x for x in os.listdir(d) if x.startswith('smali'))
        smali = os.path.join(d, ds[0]) if ds else None
    if not smali:
        raise InjectFail('no smali dir')

    manifest = os.path.join(d, 'AndroidManifest.xml')
    mtxt = open(manifest, encoding='utf-8').read()
    pkgm = re.search(r'\bpackage="([^"]+)"', mtxt)
    pkg = pkgm.group(1) if pkgm else ''

    # 找原 LAUNCHER activity（兼容 activity 与 activity-alias）
    # 若是 activity-alias，取 android:targetActivity 指向的真实 activity，避免拉起别名类失败
    orig_cls = None
    for blk in re.findall(TAG_RE, mtxt, re.S):
        if 'android.intent.action.MAIN' in blk and 'android.intent.category.LAUNCHER' in blk:
            tgt = re.search(r'android:targetActivity="([^"]+)"', blk)
            nm = re.search(r'android:name="([^"]+)"', blk)
            if tgt:
                orig_cls = tgt.group(1)
            elif nm:
                orig_cls = nm.group(1)
            break
    if not orig_cls:
        raise InjectFail('no launcher activity found')
    # 原入口全名 + smali 类型描述符
    if pkg and orig_cls.startswith('.'):
        orig_full = pkg + orig_cls
    else:
        orig_full = orig_cls
    if orig_full.startswith('.'):
        orig_full = pkg + orig_full
    smali_cls = 'L' + orig_full.replace('.', '/') + ';'
    print('[INFO] orig launcher =', orig_full, '->', smali_cls)

    # 注入壳组件：目标已有壳也强制用最新模板覆盖（修复/升级壳代码必须生效，否则重复注入老 bug 保留）
    gate = os.path.join(smali, 'com', 'cardkey', 'gate')
    has_shell = os.path.isfile(os.path.join(gate, 'GateActivity.smali'))
    os.makedirs(gate, exist_ok=True)
    shutil.copy(os.path.join(TPL, 'CardKeyBridge.smali'), gate)
    shutil.copy(os.path.join(TPL, 'CardKeyBridge$1.smali'), gate)
    shutil.copy(os.path.join(TPL, 'CardKeyBridge$2.smali'), gate)
    shutil.copy(os.path.join(TPL, 'GateActivity.smali'), gate)
    shutil.copy(os.path.join(TPL, 'GateActivity$1.smali'), gate)
    shutil.copy(os.path.join(TPL, 'GateClient.smali'), gate)
    shutil.copy(os.path.join(TPL, 'CrashLog.smali'), gate)
    if has_shell:
        print('[INFO] target has shell, refreshed from template (shell upgrade)')
    else:
        print('[INFO] copied shell classes')

    # 改 GateActivity：把模板里的三个占位符替换成真实值
    ga = os.path.join(gate, 'GateActivity.smali')
    if os.path.isfile(ga):
        s = open(ga, encoding='utf-8').read()
        s = s.replace('SERVER_PLACEHOLDER', GATE_URL)
        s = s.replace('LOCAL_PLACEHOLDER', LOCAL_GATE_URL)
        s = s.replace('PKG_PLACEHOLDER', pkg)
        s = s.replace('ORIG_PLACEHOLDER', orig_full)
        s = s.replace('VER_PLACEHOLDER', GATE_VER)
        open(ga, 'w', encoding='utf-8').write(s)
        print('[INFO] GateActivity patched -> url=%s local=%s pkg=%s orig=%s' % (GATE_URL, LOCAL_GATE_URL, pkg, orig_full))

    # 改 CardKeyBridge.launchOriginal 硬编码类
    cb = os.path.join(gate, 'CardKeyBridge.smali')
    if os.path.isfile(cb):
        s = open(cb, encoding='utf-8').read()
        s = s.replace('L' + SHELL_ACTIVITY.replace('.', '/') + ';', smali_cls)
        s = s.replace('GATE_PARAMS_PLACEHOLDER', gate_params_smali(pkg))
        open(cb, 'w', encoding='utf-8').write(s)
        print('[INFO] CardKeyBridge patched ->', smali_cls)

    # 改 manifest：移除原 LAUNCHER 入口 + 加 GateActivity + 加联网权限 + cleartext
    newm = mtxt
    prog(55, '正在注入验证壳…')
    newm = re.sub(TAG_RE, lambda b: remove_launcher_filter(b.group(0)), newm, flags=re.S)
    if GATE_CLS not in newm:
        gate_act = ('<activity android:name="' + GATE_CLS + '" android:exported="true">'
                    '<intent-filter><action android:name="android.intent.action.MAIN"/>'
                    '<category android:name="android.intent.category.LAUNCHER"/></intent-filter></activity>')
        newm = re.sub(r'(<application\b[^>]*>)', r'\1' + '\n  ' + gate_act, newm, count=1)
        print('[INFO] added GateActivity launcher')
    if 'android.permission.INTERNET' not in newm:
        newm = newm.replace('</manifest>', '<uses-permission android:name="android.permission.INTERNET"/>\n</manifest>')
    if 'usesCleartextTraffic' not in newm:
        newm = re.sub(r'(<application\b[^>]*?)>', r'\1 android:usesCleartextTraffic="true">', newm, count=1)
    open(manifest, 'w', encoding='utf-8').write(newm)

    # 通用修复：apktool 把库私有属性(如 state_dragged/layout_constraint*/alpha 等)
    # 误归到 app 包，aapt2 link 时报 `attribute xxx not found`。这里扫描解码 res 中
    # 所有 `pkg:attr` 引用，自动补声明到 res/values/shell_attrs_fix.xml（全能 format），
    # 让 link 通过。app 公开资源 id 由 apktool 生成的 public.xml 固定，不受影响，app 运行不崩。
    missing = set()
    attr_re = re.compile(r'\b([A-Za-z_]\w*):([A-Za-z_]\w*)\s*=')
    for root, _, files in os.walk(os.path.join(d, 'res')):
        for fn in files:
            if not fn.endswith('.xml'):
                continue
            try:
                txt = open(os.path.join(root, fn), encoding='utf-8', errors='ignore').read()
            except Exception:
                continue
            for pfx, name in attr_re.findall(txt):
                if pfx in ('android', 'xmlns'):
                    continue
                missing.add(name)
    declared = set()
    vals_dir = os.path.join(d, 'res', 'values')
    if os.path.isdir(vals_dir):
        for fn in os.listdir(vals_dir):
            if fn.endswith('.xml'):
                t = open(os.path.join(vals_dir, fn), encoding='utf-8', errors='ignore').read()
                for m in re.findall(r'<attr\s+name="([^"]+)"', t):
                    declared.add(m)
    need = sorted(missing - declared)
    if need:
        os.makedirs(vals_dir, exist_ok=True)
        ALLFMT = 'reference|boolean|color|dimension|float|integer|string|fraction|enum|flags'
        buf = '<?xml version="1.0" encoding="utf-8"?>\n<resources>\n'
        for n in need:
            buf += '  <attr name="%s" format="%s"/>\n' % (n, ALLFMT)
        buf += '</resources>\n'
        open(os.path.join(vals_dir, 'shell_attrs_fix.xml'), 'w', encoding='utf-8').write(buf)
        print('[INFO] auto-declared %d missing attrs: %s' % (len(need), ', '.join(need[:12]) + ('...' if len(need) > 12 else '')))

    # 构建 + 签名：--use-aapt2 用新版 aapt2（老 aapt 对老 APK 资源表直接崩溃）；
    # --no-res 不复用重编译，配合上面的属性补全，注入对任意 APK 都稳定成功。
    prog(70, '验证壳注入完成，正在打包重编译…')
    # 打包本地验证页 assets/gate.html：没网时验证界面也能打开显示（离线功能）
    try:
        os.makedirs(os.path.join(d, 'assets'), exist_ok=True)
        if os.path.isfile(GATE_HTML):
            shutil.copy(GATE_HTML, os.path.join(d, 'assets', 'gate.html'))
        else:
            print('[WARN] GATE_HTML 不存在，未打包本地验证页:', GATE_HTML)
    except OSError as e:
        print('[WARN] 打包本地验证页失败:', e)
    uns = d + '.unsigned.apk'
    run('%s b --use-aapt2 --no-res -o %s %s' % (APKTOOL, uns, d))
    prog(86, '打包完成，正在对齐优化…')
    al = d + '.aligned.apk'
    run('zipalign -p 4 %s %s' % (uns, al))
    prog(92, '正在签名…')
    run(sign_cmd(al, out, 'v1v2'))

    # 产物合法性校验：必须是含 classes.dex / AndroidManifest.xml 的有效 APK，
    # 否则大概率是原 APK 加固/混淆导致解码或打包失败，抛异常交给自愈链切轻量模式。
    import zipfile
    prog(96, '正在校验产物…')
    valid = False
    try:
        with zipfile.ZipFile(out) as zf:
            names = zf.namelist()
            valid = any(n.endswith('.dex') for n in names) or 'AndroidManifest.xml' in names
    except Exception:
        valid = False
    if not valid or not os.path.exists(out) or os.path.getsize(out) < 1024:
        raise InjectFail('注入产物不是有效 APK（常见于加固/混淆/多 dex 的 APK 解码失败）')
    print('[OK] injected APK ->', out)
    prog(100, '注入完成')

    # 清理中间文件与解码目录，避免 work 目录无限堆积
    for tmp in (uns, al):
        try:
            os.remove(tmp)
        except OSError:
            pass
    shutil.rmtree(d, ignore_errors=True)


def main():
    if len(sys.argv) < 3:
        print('usage: inject.py <src.apk> <out.apk>')
        sys.exit(1)
    src, out = sys.argv[1], sys.argv[2]
    global GATE_VER
    GATE_VER = sys.argv[3] if len(sys.argv) > 3 else os.environ.get('GATE_VER', '')
    if not os.path.exists(src):
        print('src not found', src)
        sys.exit(1)
    os.makedirs(WORK, exist_ok=True)

    # —— 自愈链：按优先级尝试方案，失败自动降级，全部失败输出中文原因 ——
    src_size = os.path.getsize(src)
    # 超大 APK（>200MB）：资源/so 巨大，apktool 全量解码极慢且吃内存（500MB 包可能 OOM），
    # 直接走轻量模式（只改二进制 manifest + 独立壳 classes2.dex，完全不碰资源）最稳最快。
    mode = 'light' if src_size > 200 * 1024 * 1024 else 'normal'
    sign_mode = 'v1v2'
    packer = 'zipfile'
    repacked = False
    attempts = []
    last_msg = ''
    for _round in range(8):
        try:
            if mode == 'normal':
                normal_inject(src, out)
            else:
                light_inject(src, out, sign_mode=sign_mode, packer=packer)
            prog(100, '注入完成')
            return
        except InjectFail as e:
            cat, _cn = classify_error(e.msg)
            attempts.append({'mode': mode, 'sign': sign_mode, 'cat': cat})
            last_msg = e.msg
            print('[ERR] 第 %d 次尝试失败（%s/%s）: %s' % (_round + 1, mode, sign_mode, e.msg[-300:]), flush=True)
            # —— 自愈决策 ——
            if mode == 'normal':
                # 环境/磁盘/权限/超时类问题换模式也没用，直接放弃
                if cat in ('disk', 'perm', 'timeout', 'env'):
                    break
                heal('正常模式失败（%s），自动切换轻量模式重试（不重编译资源，可绕开加固/资源问题）…' % cat)
                mode = 'light'
                continue
            # 轻量模式失败：
            if cat == 'sign' and sign_mode != 'v2':
                nxt = 'v1' if sign_mode == 'v1v2' else 'v2'
                heal('签名失败（%s 策略），自动切换 %s 签名策略重试…' % (sign_mode, nxt))
                sign_mode = nxt
                continue
            if cat in ('zip', 'manifest', 'unknown') and not repacked:
                repacked = True
                packer = 'zip' if packer == 'zipfile' else 'zipfile'
                heal('打包/结构环节异常（%s），切换打包方式重新打包重试…' % cat)
                continue
            break
    # 全部方案尝试完仍失败 → 输出中文原因（注入服务直接返回给前端）
    cn = human_error(attempts, last_msg)
    print('[FINAL_ERR] ' + cn, flush=True)
    print('[ERR] 注入失败（详见上文日志）', flush=True)
    sys.exit(1)


if __name__ == '__main__':
    main()
