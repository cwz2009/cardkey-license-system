#!/usr/bin/env python3
# 卡密注入独立服务：把重活(apktool 构建)从主 PHP 服务器隔离出来，单独进程运行。
# 用法:
#   python3 inject_server.py                 # 监听 0.0.0.0:9090
#   python3 inject_server.py 1.2.3.4 9090    # 指定监听地址/端口
#
# 接口:
#   GET  /health                 -> {"ok":true}
#   GET  /progress?job=<id>      -> {"ok":true,"pct":N,"msg":"...","done":false}  实时进度
#   POST /inject?job=<id>        -> 请求体为原始 APK 字节(Content-Type: application/octet-stream)
#                        成功返回 {"ok":true,"url":"http://.../injected/xxx.apk","size":N}
#                        失败返回 {"ok":false,"error":"..."}
#   GET  /                       -> 简易网页上传表单(可直接在浏览器里用)
#
# 说明:
#   - 构建调用 /var/www/inject/inject.py（绝对路径，无需关心 cwd）
#   - inject.py 通过 [PROGRESS] pct|msg 汇报进度，本服务记录到内存，供 /progress 轮询
#   - 成品写入 /var/www/cardkey/injected/，返回主服务器可访问的下载地址
#   - 跨域(CORS)已开启，管理端网页可直接 fetch 调用
import sys, os, re, shutil, subprocess, json, time, uuid, threading, urllib.parse, hmac, hashlib, base64, socket
from http.server import BaseHTTPRequestHandler, HTTPServer
from socketserver import ThreadingMixIn


class ThreadingHTTPServer(ThreadingMixIn, HTTPServer):
    """兼容 Python 3.6（3.7+ 才有内置 ThreadingHTTPServer）"""
    daemon_threads = True

INJECT_SCRIPT = '/var/www/inject/inject.py'
WORK_DIR      = '/var/www/inject/work'
OUT_DIR       = '/var/www/cardkey/injected'
CARDKEY_BASE  = 'http://127.0.0.1:8080'   # 主站地址（记录推送用）
INJECTED_JSON = '/var/www/inject/data/injected.json'   # 本地备份记录（主库记录靠推送 injected_put）
# 产物下载基地址：本机(159)直连下载，注入完成即可拿到链接，无需再同步到主站
# （2026-08-08 用户要求：上传完注入完直接下载，不要跨机传来传去）
DOWNLOAD_BASE = os.environ.get('DOWNLOAD_BASE', 'http://YOUR_SERVER_IP:9090')
COS_CONFIG = '/var/www/inject/cos_config.json'   # 可选：配置存在则产物自动传腾讯云 COS，下载走 COS（快 + 不耗 CVM 流量）
# 源包保留目录（2026-08-11 新增「重新修复」机制）：注入成功后源 APK 按 sha 命名保留，
# 供后台产物页【重新修复】按钮复用（拿源包重注入一版，替换旧产物）。全局只留最近 KEEP_SOURCE 个。
SOURCE_BACKUP_DIR = '/var/www/inject/source_backup'
KEEP_SOURCE = 3

def cos_upload(path, key):
    """产物上传腾讯云 COS（可选）。
    ⚠️ COS 默认域名禁止分发 .apk/.ipa（DownloadForbidden），.zip 放行 —— 2026-08-11 实测。
    方案：**裸 APK 字节直接上传、对象名 = <name>.apk.zip**（实际就是安装包本体）。
    用户下载后只需把后缀 .zip 改成 .apk 即可安装（无需解压）。
    有配置则上传并返回 COS 公网下载 URL；失败/无配置返回 None（回退本地 9090）。
    对象 ACL 设为 public-read，桶本身可保持私有，仅本工具产物可公网下载。"""
    if not os.path.isfile(COS_CONFIG):
        return None
    try:
        cfg = json.load(open(COS_CONFIG, encoding='utf-8'))
        from qcloud_cos import CosConfig, CosS3Client
        client = CosS3Client(CosConfig(Region=cfg['region'], SecretId=cfg['secret_id'], SecretKey=cfg['secret_key']))
        zkey = key + '.zip'
        client.upload_file(Bucket=cfg['bucket'], Key=zkey, LocalFilePath=path, MAXThread=10, PartSize=10,
                           ACL='public-read', ContentType='application/zip')
        return 'https://%s.cos.%s.myqcloud.com/%s' % (cfg['bucket'], cfg['region'], zkey)
    except Exception as e:
        print('[WARN] COS upload failed, fallback to local URL:', e)
        return None
BUILD_TIMEOUT = 1800                             # 单次构建超时(秒)：500MB 大包解包/重打包/签名较慢，放宽到 30 分钟
JOB_TTL       = 3600                              # job 进度记录保留秒数（大包任务时间长，同步放宽）
# 并发上限：同时最多跑 MAX_JOBS 个注入任务（apktool 吃内存，防止多任务并发把机器挤爆）。
# 用环境变量覆盖：2G 小机用 1，大机可提到 2。systemd unit 里配 Environment=MAX_JOBS=N
MAX_JOBS = int(os.environ.get('MAX_JOBS', '2') or '2')
if MAX_JOBS < 1:
    MAX_JOBS = 1
INJECT_SEM = threading.Semaphore(MAX_JOBS)
# 与 /var/www/cardkey/config.php 的 APP_SECRET 保持一致（注入器 app_login 签发 HMAC 令牌，这里本地验证）
APP_SECRET = 'CHANGE_ME_APP_SECRET'
GATE_BASE = 'http://YOUR_SERVER_IP:8080'          # 验证页基地址（注入产物 GATE_URL 前缀，指向新主站 cardkey）

HOST = sys.argv[1] if len(sys.argv) > 1 else '0.0.0.0'
PORT = int(sys.argv[2]) if len(sys.argv) > 2 else 9090

os.makedirs(WORK_DIR, exist_ok=True)
os.makedirs(OUT_DIR, exist_ok=True)
os.makedirs(os.path.join(os.path.dirname(INJECTED_JSON)), exist_ok=True)

_BOOT_TS = int(time.time())


def _probe_tool(name):
    """检查构建/签名工具是否可用（PATH + 已知安装路径兜底）。"""
    if shutil.which(name):
        return True
    for p in ('/usr/bin/%s' % name, '/usr/local/bin/%s' % name,
              '/opt/android-tools/bt33/android-13/%s' % name,
              '/opt/android-tools/build-tools/%s' % name):
        if os.path.isfile(p):
            return True
    return False


def cleanup_work():
    """服务启动时清空工作目录（历史失败/中断任务的 dec_* 残留），防止磁盘堆积。"""
    try:
        if os.path.isdir(WORK_DIR):
            for name in os.listdir(WORK_DIR):
                p = os.path.join(WORK_DIR, name)
                try:
                    if name.startswith('.'):
                        continue
                    if os.path.isdir(p):
                        shutil.rmtree(p, ignore_errors=True)
                    else:
                        os.remove(p)
                except Exception:
                    pass
    except Exception:
        pass


cleanup_work()

# 进度存储: job_id -> {pct, stage, msg, done, ts}
JOBS = {}
JOBS_LOCK = threading.Lock()
UPLOAD_FINAL_LOCK = threading.Lock()   # 分块拼接互斥：防止最后几块并发到达时多个线程同时拼接


def set_job(job, **kw):
    if not job:
        return
    now = time.time()
    with JOBS_LOCK:
        j = JOBS.setdefault(job, {'pct': 0, 'stage': 'received', 'msg': '已接收文件', 'done': False,
                                  'ts': now, 'heal_log': [], 'final_err': '', 'src': None, 'owner': ''})
        # 自愈日志/最终原因单独累积，不覆盖
        hl = kw.pop('heal_log', None)
        if hl:
            j['heal_log'] = (j.get('heal_log') or []) + hl
        j.update(kw)
        j['ts'] = now
        # 惰性清理过期 job，防止内存无限增长
        stale = [k for k, v in JOBS.items() if now - v['ts'] > JOB_TTL]
        for k in stale:
            del JOBS[k]


def verify_app_token(token, owner):
    """验证注入器身份令牌：token = base64url(email) + '.' + HMAC-SHA256(secret, base64url(email))
    返回 True 且令牌内邮箱 == owner 时通过。"""
    try:
        payload, sig = token.rsplit('.', 1)
        expect = hmac.new(APP_SECRET.encode(), payload.encode(), hashlib.sha256).hexdigest()
        if not hmac.compare_digest(sig, expect):
            return False
        email = base64.urlsafe_b64decode(payload + '=' * (-len(payload) % 4)).decode('utf-8')
        return email == owner
    except Exception:
        return False


def read_admin_token():
    """读管理员令牌：优先环境变量 ADMIN_TOKEN（systemd 配置，主库 meta.admin_token 一致），
    兜底读主库 meta.admin_token（本机若部署了主站代码）。"""
    env = os.environ.get('ADMIN_TOKEN', '')
    if env:
        return env
    try:
        import sqlite3
        con = sqlite3.connect('/var/www/cardkey/data/cardkey.db', timeout=5)
        row = con.execute("SELECT v FROM meta WHERE k='admin_token'").fetchone()
        con.close()
        return row[0] if row else ''
    except Exception:
        return ''


def sha256_file(path, chunk=1048576):
    """流式计算文件 SHA-256（大 APK 不占内存）。"""
    h = hashlib.sha256()
    with open(path, 'rb') as f:
        while True:
            b = f.read(chunk)
            if not b:
                break
            h.update(b)
    return h.hexdigest()


def record_injected(owner, name, url, size, extra=None, replace_name=None):
    """把注入产物归属追加到 injected.json（PHP list_injected 按 owner 过滤读取）。
    双机一致性：本地(NEW)写一份 + 推送到主服务器 OLD（list_injected 主要跑在 OLD:8080）。
    replace_name：同软件旧产物名，替换语义——本地先删旧记录再追加；推送带 replace_name 让主站同步删旧。"""
    rec = {'ts': int(time.time()), 'owner': owner, 'name': name, 'url': url, 'size': size}
    if extra:
        rec.update(extra)
    try:
        arr = []
        if os.path.isfile(INJECTED_JSON):
            with open(INJECTED_JSON, 'r', encoding='utf-8') as f:
                try:
                    arr = json.load(f)
                except Exception:
                    arr = []
        if not isinstance(arr, list):
            arr = []
        if replace_name:
            arr = [x for x in arr if x.get('name') != replace_name]
        arr.append(rec)
        if len(arr) > 500:   # 只保留最近 500 条，防无限增长
            arr = arr[-500:]
        os.makedirs(os.path.dirname(INJECTED_JSON), exist_ok=True)
        with open(INJECTED_JSON, 'w', encoding='utf-8') as f:
            json.dump(arr, f, ensure_ascii=False)
    except Exception as e:
        print('[WARN] record_injected local failed:', e)
    # 推送到主服务器 OLD（用管理员令牌调 injected_put）
    try:
        import urllib.request, urllib.parse
        adm = read_admin_token()
        if not adm:
            print('[WARN] no admin_token for push')
            return
        body = urllib.parse.urlencode({'token': adm, 'rec': json.dumps(rec, ensure_ascii=False),
                                       'replace_name': replace_name or ''}).encode('utf-8')
        req = urllib.request.Request(
            CARDKEY_BASE + '/index.php?action=injected_put&token=' + urllib.parse.quote(adm),
            data=body, headers={'Content-Type': 'application/x-www-form-urlencoded'})
        with urllib.request.urlopen(req, timeout=10) as resp:
            resp.read()
    except Exception as e:
        print('[WARN] push injected record to OLD failed:', e)


def verify_output(out_path):
    """产物自动验证：结构 + 签名 + manifest 可解析。返回 (ok, issues[])。
    解决用户反馈的"安装包解析失败 / 与操作系统不兼容 / 安装包损坏"——下发前先自检。"""
    import zipfile as _z
    issues = []
    ok = True
    # 1) zip 结构：必须含 dex 与 manifest
    try:
        with _z.ZipFile(out_path) as zf:
            names = zf.namelist()
            if not any(n.endswith('.dex') for n in names):
                ok = False; issues.append('缺少 classes.dex（包结构异常）')
            if 'AndroidManifest.xml' not in names:
                ok = False; issues.append('缺少 AndroidManifest.xml（包结构异常）')
    except Exception as e:
        ok = False; issues.append('ZIP 结构损坏: %s' % e)
    # 2) 签名验证：大包(v1+v2 已统一)默认全查；小包默认
    try:
        r = subprocess.run(['apksigner', 'verify', out_path],
                           stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True, timeout=120)
        if r.returncode != 0:
            ok = False
            issues.append('签名验证失败: %s' % ((r.stdout or r.stderr or '').strip()[:120]))
        else:
            issues.append('签名有效')
    except Exception as e:
        ok = False; issues.append('签名验证异常: %s' % e)
    # 3) manifest 解析：aapt2 xmltree 单独解析 AndroidManifest.xml（不碰 dex——
    #    加固包 dex 加密不影响 manifest 验证）；badging 会因 dex 加密误报，不用它判失败
    try:
        r2 = subprocess.run(['aapt2', 'dump', 'xmltree', out_path, '--file', 'AndroidManifest.xml'],
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True, timeout=120)
        if r2.returncode != 0:
            ok = False; issues.append('manifest 无法解析（包结构异常）')
        else:
            issues.append('manifest 有效')
    except Exception as e:
        ok = False; issues.append('manifest 解析异常: %s' % e)
    # 4) minSdk 尽力提取（badging 对加密 dex 可能失败，失败不致命）
    try:
        r3 = subprocess.run(['aapt2', 'dump', 'badging', out_path],
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True, timeout=120)
        m = re.search(r"sdkVersion:['\"](\d+)['\"]", r3.stdout or '')
        if m:
            minSdk = int(m.group(1))
            if minSdk > 24:
                ok = False; issues.append('最低系统要求 Android %d+（老设备可能无法安装）' % minSdk)
            else:
                issues.append('兼容 Android %d+' % minSdk)
    except Exception:
        pass
    return ok, issues


def build(src, out, job=None, owner=None, code=None):
    """调用注入脚本并逐行解析 [PROGRESS]/[HEAL]/[FINAL_ERR]。
    返回 (rc, log, heal_log, final_err, code)。owner 非空时 GATE_URL 带 ?owner= 实现验证端归属；
    code 为配对码（每个注入产物唯一）：追加 &code= 让验证端能按码查询归属/被绑定。"""
    log = []
    heal_log = []
    final_err = ''
    code = None
    ver = ''
    env = dict(os.environ)
    if owner:
        if not code:
            code = uuid.uuid4().hex[:8].upper()
        # ver=注入时间戳：新注入包带上版本标识，服务端设 gate_min_ver 门槛可"停用旧版+强制更新"，
        # 旧包(无 ver=0)一律被停用，新包(ver=时间戳)自动放行。
        ver = str(int(time.time()))
        env['GATE_URL'] = GATE_BASE + '/gate.html?owner=' + owner + '&code=' + code + '&ver=' + ver
        # 注入参数 JSON：壳内 CardKeyBridge.getGateParams() 返回，本地验证页读取 api/owner/code/ver
        # （2026-08-11 离线功能：验证页打包进 APK，参数经桥注入，不再依赖 URL 查询串）
        env['GATE_PARAMS'] = json.dumps({'api': GATE_BASE, 'owner': owner, 'code': code, 'ver': ver}, ensure_ascii=False)
    try:
        p = subprocess.Popen(
            ['python3', INJECT_SCRIPT, src, out],
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
            universal_newlines=True, bufsize=1, env=env
        )
    except Exception as e:
        return 1, 'failed to start inject.py: %s' % e, [], '', code, ver
    assert p.stdout is not None
    for line in p.stdout:
        line = line.rstrip('\n')
        log.append(line)
        if job:
            m = re.match(r'\[PROGRESS\]\s*(\d+)\|(.*)', line)
            if m:
                set_job(job, pct=int(m.group(1)), msg=m.group(2), stage='building')
            if line.startswith('[HEAL]'):
                heal_log.append(line[6:].strip())
                set_job(job, heal_log=heal_log)
            if line.startswith('[FINAL_ERR]'):
                final_err = line[len('[FINAL_ERR]'):].strip()
                set_job(job, final_err=final_err)
    try:
        rc = p.wait(timeout=BUILD_TIMEOUT)
    except subprocess.TimeoutExpired:
        p.kill()
        return 124, '\n'.join(log) + '\n[ERR] 构建超时(>%ds)' % BUILD_TIMEOUT, heal_log, final_err, code, ver
    return rc, '\n'.join(log), heal_log, final_err, code, ver


class H(BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'   # 长连接：上传大文件 + 后续轮询更顺
    rbufsize = 262144               # 接收缓冲 256KB：大 APK 上传更快（默认无缓冲逐包读）

    def _cors(self):
        self.send_header('Access-Control-Allow-Origin', '*')
        self.send_header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
        self.send_header('Access-Control-Allow-Headers', 'Content-Type')

    def do_OPTIONS(self):
        self.send_response(204)
        self._cors()
        self.end_headers()

    def _get_param(self, name):
        q = urllib.parse.urlparse(self.path).query
        vals = urllib.parse.parse_qs(q).get(name)
        return vals[0] if vals else ''

    def do_GET(self):
        if self.path.startswith('/injected/'):
            # 产物下载：2026-08-11 COS 默认域名被腾讯策略禁止分发 APK（需自定义域名+CDN），
            # 没有域名时无法走 COS，所以恢复服务器直接出 APK。带 Range 断点续传。
            fname = self.path.split('/injected/', 1)[1].split('?', 1)[0]
            fname = os.path.basename(fname)   # 防目录穿越
            fpath = os.path.join(OUT_DIR, fname)
            if not fname or not os.path.isfile(fpath):
                self._json(404, {'ok': False, 'error': 'file not found'})
                return
            try:
                sz = os.path.getsize(fpath)
            except OSError:
                sz = 0
            start, end = 0, sz - 1
            status = 200
            rng = self.headers.get('Range')
            if rng:
                try:
                    m = re.match(r'bytes=(\d*)-(\d*)', rng.strip())
                    if m:
                        s = int(m.group(1)) if m.group(1) else 0
                        e = int(m.group(2)) if m.group(2) else sz - 1
                        if s < sz and e >= s:
                            start, end = s, min(e, sz - 1)
                            status = 206
                except Exception:
                    pass
            self.send_response(status)
            self.send_header('Content-Type', 'application/vnd.android.package-archive')
            self.send_header('Content-Length', str(end - start + 1))
            self.send_header('Content-Disposition', 'attachment; filename="%s"' % fname)
            if status == 206:
                self.send_header('Content-Range', 'bytes %d-%d/%d' % (start, end, sz))
            self._cors()
            self.end_headers()
            try:
                with open(fpath, 'rb') as f:
                    if start > 0:
                        f.seek(start)
                    left = end - start + 1
                    while left > 0:
                        b = f.read(min(262144, left))
                        if not b:
                            break
                        self.wfile.write(b)
                        left -= len(b)
            except (BrokenPipeError, ConnectionResetError):
                pass
            return
        if self.path.startswith('/health'):
            # 自检面板数据源：磁盘/工作目录/构建工具链（NEW 注入机状态）
            try:
                du = shutil.disk_usage(WORK_DIR)
                disk_free, disk_total = du.free, du.total
            except Exception:
                disk_free = disk_total = -1
            try:
                work_ok = os.access(WORK_DIR, os.W_OK)
            except Exception:
                work_ok = False
            tools = {}
            for t in ('java', 'apktool', 'apksigner', 'zipalign', 'aapt2', 'python3'):
                tools[t] = _probe_tool(t)
            self._json(200, {
                'ok': True, 'host': socket.gethostname(), 'pid': os.getpid(),
                'max_jobs': MAX_JOBS, 'disk_free': disk_free, 'disk_total': disk_total,
                'work_writable': work_ok, 'tools': tools,
                'jobs_active': len(JOBS),
                'uptime': int(time.time() - _BOOT_TS),
            })
            return
        if self.path.startswith('/active'):
            # 后台监控：当前谁在用注入（管理员令牌校验；10 分钟内活跃的任务）
            tok = self._get_param('token')
            if not tok or tok != read_admin_token():
                self._json(403, {'ok': False, 'error': '需要管理员令牌'})
                return
            now = time.time()
            jobs = []
            with JOBS_LOCK:
                for jid, j in JOBS.items():
                    if j.get('done') or (j.get('pct') or 0) >= 100:
                        continue
                    if now - j.get('ts', 0) > 600:
                        continue
                    jobs.append({'job': jid, 'owner': j.get('owner', '') or '',
                                 'pct': j.get('pct', 0), 'stage': j.get('stage', ''),
                                 'msg': j.get('msg', ''), 'since': int(j.get('ts', now))})
            jobs.sort(key=lambda x: x['since'])
            self._json(200, {'ok': True, 'active': len(jobs), 'jobs': jobs})
            return
        if self.path.startswith('/chunks'):
            # 分块上传：查询该 job 已收到的块索引（断点续传用）
            job = self._get_param('job')
            owner, at = self._get_param('owner'), self._get_param('at')
            if not owner or not at or not verify_app_token(at, owner):
                self._json(403, {'ok': False, 'error': '身份验证失败'})
                return
            jdir = os.path.join(WORK_DIR, 'up_' + re.sub(r'[^A-Za-z0-9_\-]', '', job))
            chunks = []
            if os.path.isdir(jdir):
                for fn in os.listdir(jdir):
                    m = re.match(r'p_(\d{6})$', fn)
                    if m:
                        chunks.append(int(m.group(1)))
            self._json(200, {'ok': True, 'chunks': sorted(chunks)})
            return
        if self.path.startswith('/progress'):
            job = self._get_param('job')
            with JOBS_LOCK:
                j = JOBS.get(job)
            if not j:
                self._json(404, {'ok': False, 'error': 'job not found'})
                return
            # 上传超时自动判失败：分块接收阶段超过 20 分钟未更新（ts=最后活动时间）→ 网络已断，
            # 明确返回失败，前端立即提示"上传失败"，不再让用户无限干等
            if j['stage'] == 'received' and not j['done'] and (time.time() - (j.get('ts') or time.time())) > 1200:
                with JOBS_LOCK:
                    jj = JOBS.get(job)
                    if jj and jj['stage'] == 'received' and not jj['done']:
                        jj['done'] = True
                        jj['stage'] = 'error'
                        jj['msg'] = '上传超时：20 分钟未收到完整文件（网络中断），请重新上传'
                        jj['final_err'] = jj['msg']
                    j = jj or j
            self._json(200, {'ok': True, 'pct': j['pct'], 'stage': j['stage'],
                             'msg': j['msg'], 'done': j['done'],
                             'heal_log': j.get('heal_log') or [], 'final_err': j.get('final_err') or '',
                             'url': j.get('url') or '', 'name': j.get('name') or '',
                             'size': j.get('size') or 0, 'verify_ok': j.get('verify_ok'),
                             'verify': j.get('verify') or ''})
            return
        # 简易上传表单
        html = '''<!doctype html><html lang="zh"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>卡密注入 · 独立服务</title>
<style>body{font:15px/1.6 -apple-system,Segoe UI,Roboto,sans-serif;background:#0b0e1a;color:#eaf0ff;
display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}
.box{background:#141828;border:1px solid #2a3150;border-radius:16px;padding:28px;width:min(92vw,420px)}
h2{margin:0 0 6px;font-size:18px}small{color:#8b93b5}input{width:100%;margin:14px 0}
button{width:100%;background:linear-gradient(120deg,#21d4fd,#7c5cff);border:0;color:#0a0b14;
font-weight:600;padding:13px;border-radius:12px;cursor:pointer;font-size:15px}
#r{margin-top:14px;white-space:pre-wrap;font-size:13px;color:#34d399;max-height:40vh;overflow:auto}</style>
</head><body><div class="box"><h2>卡密注入服务</h2><small>独立进程运行，不占用主服务器</small>
<input id="f" type="file" accept=".apk"><button id="b">注入并生成</button><div id="r"></div>
<script>
const r=document.getElementById('r'),b=document.getElementById('b');
b.onclick=async()=>{const f=document.getElementById('f').files[0];if(!f){r.textContent='请选择 APK';return;}
const job='j_'+Date.now();r.textContent='构建中…（apktool 重活，请稍候）';b.disabled=true;
const t=setInterval(async()=>{try{const p=await(await fetch('/progress?job='+job)).json();
if(p.ok){r.textContent='['+p.pct+'%] '+p.msg;}if(p.done){clearInterval(t);}}catch(e){}},1500);
try{const res=await fetch('/inject?job='+job,{method:'POST',headers:{'Content-Type':'application/octet-stream'},body:f});
const j=await res.json();b.disabled=false;clearInterval(t);
if(j.ok){r.textContent='✅ 成功\\n下载地址:\\n'+j.url;}else{r.style.color='#ff5d5d';r.textContent='❌ 失败:\\n'+j.error;}}
catch(e){b.disabled=false;clearInterval(t);r.style.color='#ff5d5d';r.textContent='请求出错: '+e.message;}};
</script></div></body></html>'''
        self.send_response(200)
        self.send_header('Content-Type', 'text/html; charset=utf-8')
        self._cors()
        self.end_headers()
        self.wfile.write(html.encode('utf-8'))

    def do_POST(self):
        if self.path.startswith('/upload'):
            # 分块并发上传：body 为第 idx 块（8MB），全部块齐后自动拼接并开始构建
            job = self._get_param('job')
            owner, at = self._get_param('owner'), self._get_param('at')
            if not owner or not at or not verify_app_token(at, owner):
                self._json(403, {'ok': False, 'error': '身份验证失败'})
                return
            try:
                idx = int(self._get_param('idx'))
                total = int(self._get_param('total'))
                total_bytes = self._get_param('total_bytes')   # 可选：客户端传文件总大小，用于端到端完整性校验
            except Exception:
                self._json(400, {'ok': False, 'error': '块参数错误'})
                return
            if idx < 0 or total <= 0 or idx >= total or total > 400:
                self._json(400, {'ok': False, 'error': '块参数越界'})
                return
            try:
                n = int(self.headers.get('Content-Length', 0))
            except Exception:
                n = 0
            if n <= 0 or n > 8 * 1024 * 1024 + 4096:
                self._json(400, {'ok': False, 'error': '块大小异常'})
                return
            jdir = os.path.join(WORK_DIR, 'up_' + re.sub(r'[^A-Za-z0-9_\-]', '', job))
            try:
                os.makedirs(jdir, exist_ok=True)
            except Exception:
                pass
            part = os.path.join(jdir, 'p_%06d' % idx)
            got = 0
            with open(part, 'wb') as fh:
                while got < n:
                    chunk = self.rfile.read(min(262144, n - got))
                    if not chunk:
                        break
                    fh.write(chunk)
                    got += len(chunk)
            # 完整性校验：实际收到的字节必须等于声明的 Content-Length，否则该分块视为
            # 上传不完整（网络中断/丢包）。删除残缺块并返回错误，前端会自动重传该块。
            # 这是修复「分块丢块 -> 拼接出截断 APK -> unzip rc=12」的关键闸。
            if got != n:
                try:
                    os.remove(part)
                except OSError:
                    pass
                self._json(400, {'ok': False, 'retryable': True,
                                'error': '分块 %d 上传不完整（收到 %d/%d 字节），正在重传…' % (idx, got, n)})
                return
            set_job(job, pct=2, stage='received', msg='接收分块 %d/%d' % (idx + 1, total), owner=owner)
            # 拼接互斥 + 收齐校验（关键）：并发上传时最后一块可能先到、其他块仍在写入，
            # 必须校验块大小 ≥1MB 才算真正收齐（曾出现 586MB 拼成 188MB 截断包）；
            # 加锁防止多个线程同时触发拼接。
            UPLOAD_FINAL_LOCK.acquire()
            try:
                all_here = True
                for i in range(total):
                    pf = os.path.join(jdir, 'p_%06d' % i)
                    try:
                        if not os.path.isfile(pf):
                            all_here = False
                            break
                        sz = os.path.getsize(pf)
                        # 非最后一块必须 ≥1MB（防"文件存在但未写完"竞态拼出截断包）；
                        # 最后一块（含 total=1 整包直传的小文件 <1MB）只要 >0 字节即可
                        need = (1024 * 1024) if i < total - 1 else 1
                        if sz < need:
                            all_here = False
                            break
                    except OSError:
                        all_here = False
                        break
                if not all_here:
                    self._json(200, {'ok': True, 'wait': True, 'got': idx})
                    return
                # 拼接成源 APK
                ts = '%d_%s' % (int(time.time()), uuid.uuid4().hex[:6])
                src = os.path.join(WORK_DIR, 'in_' + ts + '.apk')
                try:
                    with open(src, 'wb') as out_f:
                        for i in range(total):
                            with open(os.path.join(jdir, 'p_%06d' % i), 'rb') as pf:
                                shutil.copyfileobj(pf, out_f, 262144)
                except Exception as e:
                    self._json(500, {'ok': False, 'fatal': True, 'error': '分块拼接失败: %s' % e})
                    return
                shutil.rmtree(jdir, ignore_errors=True)
            finally:
                UPLOAD_FINAL_LOCK.release()
            # 端到端完整性校验：若客户端上传时带了 total_bytes（文件总大小），拼接后的源文件
            # 大小必须与之完全一致；不一致说明有分块丢失/截断，直接拒绝并提示重传，不再浪费
            # 一轮注入（此前就是在这里拼出 28MB 残包还当正常包送去注入，最终 unzip rc=12）。
            if total_bytes:
                try:
                    tb = int(total_bytes)
                    if os.path.getsize(src) != tb:
                        try:
                            os.remove(src)
                        except OSError:
                            pass
                        self._json(400, {'ok': False, 'fatal': True,
                                        'error': '上传文件不完整（期望 %d 字节，实际 %d 字节），请重新选择文件上传' % (tb, os.path.getsize(src))})
                        return
                except ValueError:
                    pass
            if os.path.getsize(src) < 1024:
                set_job(job, done=True, stage='error', msg='文件过小')
                try:
                    os.remove(src)
                except OSError:
                    pass
                self._json(400, {'ok': False, 'error': '文件过小，不是合法 APK'})
                return
            # 关键：最后一块上传完成 → 后台线程跑注入，本请求立即返回。
            # 此前同步 _run 会让这个上传请求挂 10~30 分钟（大包 apktool 反编译/打包慢），
            # 前端最后一块 onload 被阻塞，界面一直停在"上传中 N/N 块"——用户以为卡死。
            # 改为立即返回 started 后，前端马上进入 /progress 轮询，能看到
            # 排队 → 反编译 → 打包 → 签名 → 完成 全过程，任何阶段失败都即时可见。
            def _inject_worker(job_, src_, owner_):
                try:
                    code, resp = self._run(job_, src_, owner_)
                    if code >= 400:
                        resp = dict(resp)
                        resp['ok'] = False
                        resp['inject_failed'] = True
                        # 把失败详情写进 job，前端 /progress 轮询即可显示（msg 只到 200 字）
                        set_job(job_, done=True, stage='error',
                                msg=('注入失败：' + str(resp.get('error', '')))[:200],
                                src=src_, owner=owner_)
                except Exception as e:
                    set_job(job_, done=True, stage='error',
                            msg=('注入异常：%s' % e)[:200], src=src_, owner=owner_)
            threading.Thread(target=_inject_worker, args=(job, src, owner), daemon=True).start()
            self._json(200, {'ok': True, 'started': True})
            return
        if self.path.startswith('/retry'):
            # 失败一键重试：复用上次保留的源 APK（1 小时内），重新跑一遍自愈链
            job = self._get_param('job')
            owner, at = self._get_param('owner'), self._get_param('at')
            if not owner or not at or not verify_app_token(at, owner):
                self._json(403, {'ok': False, 'error': '身份验证失败，请刷新页面后重试'})
                return
            with JOBS_LOCK:
                j = JOBS.get(job)
                jsrc = (j or {}).get('src') if j else None
                jowner = (j or {}).get('owner') if j else None
            if not jsrc or not os.path.isfile(jsrc):
                set_job(job, done=True, stage='error', msg='原文件已过期')
                self._json(410, {'ok': False, 'error': '原 APK 已过期（源文件仅保留 1 小时），请重新上传注入', 'retryable': False})
                return
            if jowner and jowner != owner:
                self._json(403, {'ok': False, 'error': '无权重试该任务'})
                return
            set_job(job, pct=1, stage='received', msg='已复用原文件，重新注入中', final_err='')
            code, resp = self._run(job, jsrc, owner)
            self._json(code, resp)
            return
        if self.path.startswith('/repair'):
            # 【重新修复】(2026-08-11)：产物坏了，后台所有登录账号可对"自己名下的产物"点修复。
            # 拿 source_backup/ 里保留的源包重新注入一版（最新壳模板+自愈链），新产物替换旧产物
            # （磁盘删旧包 + 双份 injected.json 删旧记录），全程进度走 [PROGRESS] 轮询。
            job = self._get_param('job') or ('jr_' + uuid.uuid4().hex[:10])
            owner, at = self._get_param('owner'), self._get_param('at')
            name = self._get_param('name')
            if not owner or not at or not verify_app_token(at, owner):
                self._json(403, {'ok': False, 'error': '身份验证失败，请刷新页面后重试'})
                return
            if not name:
                self._json(400, {'ok': False, 'error': '缺少产物名'})
                return
            # 从记录里找该产物 + 校验 owner 隔离（只能修自己名下的）
            rec = None
            try:
                if os.path.isfile(INJECTED_JSON):
                    arr = json.load(open(INJECTED_JSON, encoding='utf-8'))
                    if isinstance(arr, list):
                        for x in arr:
                            if x.get('name') == name:
                                rec = x
                                break
            except Exception:
                rec = None
            if not rec:
                self._json(404, {'ok': False, 'error': '产物记录不存在，请刷新页面'})
                return
            if rec.get('owner') and rec['owner'] != owner and owner != 'admin':
                self._json(403, {'ok': False, 'error': '只能修复自己名下的产物'})
                return
            src_sha = rec.get('src_sha') or ''
            src = os.path.join(SOURCE_BACKUP_DIR, src_sha + '.apk') if src_sha else ''
            if not src_sha or not os.path.isfile(src):
                set_job(job, done=True, stage='error', msg='源包已清理', owner=owner)
                self._json(410, {'ok': False, 'error': '该产物的源包已清理，请重新上传原包注入', 'retryable': False})
                return
            set_job(job, pct=1, stage='received', msg='已找到源包，正在重新注入修复版…', owner=owner)
            def _repair_worker(job_, src_, owner_, old_name_):
                try:
                    code, resp = self._repair(job_, src_, owner_, old_name_)
                    if code >= 400:
                        resp = dict(resp)
                        resp['ok'] = False
                        resp['inject_failed'] = True
                        set_job(job_, done=True, stage='error',
                                msg=('修复失败：' + str(resp.get('error', '')))[:200], src=src_, owner=owner_)
                except Exception as e:
                    set_job(job_, done=True, stage='error',
                            msg=('修复异常：%s' % e)[:200], src=src_, owner=owner_)
            threading.Thread(target=_repair_worker, args=(job, src, owner, name), daemon=True).start()
            self._json(200, {'ok': True, 'started': True, 'job': job})
            return
        if not self.path.startswith('/inject'):
            self._json(404, {'ok': False, 'error': 'not found'})
            return
        job = self._get_param('job') or ('j_' + uuid.uuid4().hex[:10])
        owner, at = self._get_param('owner'), self._get_param('at')
        # 归属身份验证：at = base64url(email).hmac；owner 必须等于令牌里的邮箱
        if not owner or not at or not verify_app_token(at, owner):
            set_job(job, done=True, stage='error', msg='身份验证失败')
            self._json(403, {'ok': False, 'error': '身份验证失败，请先在注入器里登录账号'})
            return
        set_job(job, pct=1, stage='received', msg='已接收文件，准备注入', owner=owner)
        try:
            n = int(self.headers.get('Content-Length', 0))
        except Exception:
            n = 0
        if n <= 0:
            set_job(job, done=True, stage='error', msg='空请求')
            self._json(400, {'ok': False, 'error': 'empty body'})
            return
        # 边收边写盘（256KB 块）：大 APK 不占内存，接收即落盘
        ts = '%d_%s' % (int(time.time()), uuid.uuid4().hex[:6])
        src = os.path.join(WORK_DIR, 'in_' + ts + '.apk')
        out = os.path.join(WORK_DIR, 'out_' + ts + '.apk')
        got = 0
        with open(src, 'wb') as fh:
            while got < n:
                chunk = self.rfile.read(min(262144, n - got))
                if not chunk:
                    break
                fh.write(chunk)
                got += len(chunk)
        if got < 1024:
            set_job(job, done=True, stage='error', msg='文件过小')
            self._json(400, {'ok': False, 'error': '文件过小，不是合法 APK'})
            return
        set_job(job, pct=3, stage='received', msg='接收完成，正在保存文件')
        code, resp = self._run(job, src, owner)
        self._json(code, resp)

    def _run(self, job, src, owner):
        """执行构建 → 产物推送/记录/验证。返回 (http_code, resp)。
        失败时保留源文件（供 /retry 一键重试，1 小时内有效）；成功时删除源文件。"""
        ts = '%d_%s' % (int(time.time()), uuid.uuid4().hex[:6])
        out = os.path.join(WORK_DIR, 'out_' + ts + '.apk')
        # 并发控制：同时最多 MAX_JOBS 个任务，排队等待（apktool 吃内存，避免挤爆小机）
        set_job(job, pct=3, stage='queue', msg='排队中（同时最多 %d 个任务）' % MAX_JOBS)
        INJECT_SEM.acquire()
        try:
            rc, log, heal_log, final_err, code, ver = build(src, out, job=job, owner=owner)
        finally:
            INJECT_SEM.release()
        if rc == 124:
            set_job(job, done=True, stage='error', msg='构建超时', src=src, owner=owner)
            return 500, {'ok': False, 'error': '构建超时(>%ds)，可稍后点「重新注入」重试' % BUILD_TIMEOUT, 'retryable': True}
        if rc != 0 or not os.path.exists(out):
            # 失败原因优先用 inject.py 输出的中文 [FINAL_ERR]（自愈链已自动尝试多种方案）
            err = final_err or '\n'.join([l for l in log.splitlines() if 'ERR' in l or 'Exception' in l][-8:]) or '构建失败'
            set_job(job, done=True, stage='error', msg='注入失败', src=src, owner=owner)
            return 500, {'ok': False, 'error': err[:800], 'retryable': True}
        name = 'cardkey_shelled_%s.apk' % ts
        dest = os.path.join(OUT_DIR, name)
        shutil.move(out, dest)
        # 产物跨机同步：注入在本机(159)跑、下载 URL 指向主站(158)，
        # 产物必须推送到 158，否则 158:8080 下载 404。
        # PUSH_TO_MAIN=1（systemd 环境变量）时 scp 到 158 并修正属主；
        # 推送失败或未启用时 URL 仍指向 158（尽力而为，主要靠推送成功）。
        # 产物下载地址：直接指向本机(159) 9090 的 /injected/ 静态下载（2026-08-08 用户要求
        # 上传完注入完直接下载，不要跨机同步）。产物本地保留 7 天，供下载/历史源包复用。
        url = DOWNLOAD_BASE + '/injected/' + name
        # 2026-08-13 COS 桶欠费停用：不再传 COS，产物直连本机 9090 下载（吃 CVM 流量，但唯一可用通道）
        # cos_url = cos_upload(dest, name)
        # if cos_url:
        #     url = cos_url
        #     print('[INFO] product uploaded to COS(zip):', url)
        set_job(job, pct=98, stage='building', msg='正在验证产物…')
        vok, viss = verify_output(dest)
        # 保留源包到 source_backup/（2026-08-11「重新修复」机制）：按 sha 唯一命名，
        # 供产物页【重新修复】复用源包重注入。全局只保留最近 KEEP_SOURCE 个不同源包。
        src_sha = ''
        try:
            if os.path.isfile(src):
                src_sha = sha256_file(src)[:16]
                os.makedirs(SOURCE_BACKUP_DIR, exist_ok=True)
                spath = os.path.join(SOURCE_BACKUP_DIR, src_sha + '.apk')
                if os.path.abspath(spath) != os.path.abspath(src):
                    shutil.move(src, spath)
                backups = sorted(
                    [os.path.join(SOURCE_BACKUP_DIR, f) for f in os.listdir(SOURCE_BACKUP_DIR) if f.endswith('.apk')],
                    key=lambda p: os.path.getmtime(p), reverse=True)
                for p in backups[KEEP_SOURCE:]:
                    try:
                        os.remove(p)
                    except OSError:
                        pass
        except Exception as e:
            print('[WARN] save source backup failed:', e)
            src_sha = ''
        record_injected(owner, name, url, os.path.getsize(dest),
                        extra={'code': code, 'ver': ver, 'verify_ok': vok, 'verify': '; '.join(viss),
                               'src_sha': src_sha})
        set_job(job, pct=100, done=True, stage='done', msg=('注入完成（产物验证通过）' if vok else '注入完成（⚠️ 产物验证有警告）'), src=None, owner=owner,
                url=url, name=name, size=os.path.getsize(dest), verify_ok=vok, verify='; '.join(viss))
        return 200, {'ok': True, 'url': url, 'name': name, 'size': os.path.getsize(dest),
                     'verify_ok': vok, 'verify': '; '.join(viss)}

    def _repair(self, job, src, owner, old_name):
        """【重新修复】核心：用保留的源包重注入一版，新产物替换旧产物。
        替换语义 = 磁盘删旧 APK + 双份 injected.json 删旧记录（每软件磁盘上始终只有 1 个产物）。"""
        ts = '%d_%s' % (int(time.time()), uuid.uuid4().hex[:6])
        out = os.path.join(WORK_DIR, 'out_' + ts + '.apk')
        set_job(job, pct=3, stage='queue', msg='排队中（同时最多 %d 个任务）' % MAX_JOBS)
        INJECT_SEM.acquire()
        try:
            rc, log, heal_log, final_err, code, ver = build(src, out, job=job, owner=owner)
        finally:
            INJECT_SEM.release()
        if rc == 124:
            set_job(job, done=True, stage='error', msg='构建超时', src=src, owner=owner)
            return 500, {'ok': False, 'error': '构建超时(>%ds)，可稍后重试修复' % BUILD_TIMEOUT, 'retryable': True}
        if rc != 0 or not os.path.exists(out):
            err = final_err or '\n'.join([l for l in log.splitlines() if 'ERR' in l or 'Exception' in l][-8:]) or '修复失败'
            set_job(job, done=True, stage='error', msg='修复失败', src=src, owner=owner)
            return 500, {'ok': False, 'error': err[:800], 'retryable': True}
        name = 'cardkey_shelled_%s.apk' % ts
        dest = os.path.join(OUT_DIR, name)
        shutil.move(out, dest)
        url = DOWNLOAD_BASE + '/injected/' + name
        # 2026-08-13 COS 桶欠费停用：不再传 COS，产物直连本机 9090 下载
        # cos_url = cos_upload(dest, name)
        # if cos_url:
        #     url = cos_url
        #     print('[INFO] product uploaded to COS(zip):', url)
        set_job(job, pct=98, stage='building', msg='正在验证产物…')
        vok, viss = verify_output(dest)
        # 替换：删旧产物（防目录穿越，只取 basename）
        old_dest = os.path.join(OUT_DIR, os.path.basename(old_name))
        try:
            if os.path.isfile(old_dest) and os.path.abspath(old_dest) != os.path.abspath(dest):
                os.remove(old_dest)
                print('[INFO] repaired: removed old product', old_name)
        except OSError as e:
            print('[WARN] remove old product failed:', e)
        # 源包已在 source_backup（sha 命名），新记录带同一 src_sha，便于再次修复
        src_sha = os.path.splitext(os.path.basename(src))[0]
        record_injected(owner, name, url, os.path.getsize(dest),
                        extra={'code': code, 'ver': ver, 'verify_ok': vok, 'verify': '; '.join(viss),
                               'src_sha': src_sha},
                        replace_name=old_name)
        set_job(job, pct=100, done=True, stage='done', msg=('修复完成（产物验证通过）' if vok else '修复完成（⚠️ 产物验证有警告）'),
                src=None, owner=owner, url=url, name=name, size=os.path.getsize(dest),
                verify_ok=vok, verify='; '.join(viss))
        return 200, {'ok': True, 'url': url, 'name': name, 'size': os.path.getsize(dest),
                     'verify_ok': vok, 'verify': '; '.join(viss)}

    def _json(self, code, obj):
        body = json.dumps(obj, ensure_ascii=False).encode('utf-8')
        self.send_response(code)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Content-Length', str(len(body)))
        self._cors()
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *a):
        pass


if __name__ == '__main__':
    print('inject_server on http://%s:%d' % (HOST, PORT))
    ThreadingHTTPServer((HOST, PORT), H).serve_forever()
