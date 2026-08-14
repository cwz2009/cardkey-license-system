#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Binary AndroidManifest.xml editor (no aapt / no resource recompilation).

The sample target APK is AndResGuard-obfuscated, so aapt/aapt2 cannot
recompile its resources.arsc. Instead we keep resources.arsc byte-identical
and edit only the *binary* AndroidManifest.xml here, then reassemble the APK.

What this script does to the manifest:
  1. Demotes the original LAUNCHER activity (android.intent.category.LAUNCHER
     -> ...category.DEFAULT) so it no longer launches on its own.
  2. Adds a new LAUNCHER activity com.cardkey.gate.GateActivity (WebView gate)
     as a child of <application>, with android:exported="true".
  3. Adds android:usesCleartextTraffic="true" to <application> (server is HTTP,
     required on API 28+).
  4. Ensures android.permission.INTERNET is declared.

Pure standard library. Works on CPython 3.x.

Usage:
  python3 edit_manifest.py analyze  <AndroidManifest.xml>
  python3 edit_manifest.py edit     <in.xml> <out.xml> [--pkg PKG] [--orig ORIG]
"""
import sys, struct, argparse

# ---- constants -------------------------------------------------------------
RES_XML_TYPE            = 0x0003
RES_STRING_POOL_TYPE    = 0x0001
RES_XML_RESOURCE_MAP_TYPE = 0x0180
RES_XML_START_NAMESPACE = 0x0100
RES_XML_START_ELEMENT   = 0x0102
RES_XML_END_ELEMENT     = 0x0103

FLAG_UTF8   = 0x100

# framework attr resource ids (package 0x01, type 0x01 attr)
A_NAME        = 0x01010003
A_EXPORTED    = 0x01010010
A_CLEARTEXT   = 0x01010464

TYPE_STRING    = 0x03
TYPE_INT_BOOLEAN = 0x12

NS_NONE = 0xFFFFFFFF

ATTR_EXT_SIZE = 12          # attrStart,attrSize,attrCount,idIndex,classIndex,styleIndex
NODE_BODY = 16              # lineNumber + comment (after 8-byte common header)
NS_NAME = 8                 # ns(4) + name(4) after NODE_BODY
ATTR_BASE = NODE_BODY + NS_NAME + ATTR_EXT_SIZE   # = 36 : where attrs begin
ATTR_SIZE = 20


def u16(b, o): return struct.unpack_from('<H', b, o)[0]
def u32(b, o): return struct.unpack_from('<I', b, o)[0]


# ---- string pool -----------------------------------------------------------
def decode_string_pool(data, chunk_start):
    off = chunk_start
    stringCount = u32(data, off + 8)
    styleCount  = u32(data, off + 12)
    flags       = u32(data, off + 16)
    stringsStart= u32(data, off + 20)
    base = chunk_start + stringsStart
    offsets = [u32(data, off + 28 + i * 4) for i in range(stringCount)]
    strings = []
    utf8 = bool(flags & FLAG_UTF8)
    for o in offsets:
        strings.append(decode_one_string(data, base + o, utf8))
    return strings, flags


def decode_one_string(data, p, utf8):
    if utf8:
        if (data[p] & 0x80):
            cc = ((data[p] & 0x7f) << 8) | data[p + 1]; p += 2
        else:
            cc = data[p]; p += 1
        if (data[p] & 0x80):
            bc = ((data[p] & 0x7f) << 8) | data[p + 1]; p += 2
        else:
            bc = data[p]; p += 1
        return data[p:p + bc].decode('utf-8', 'replace')
    v = u16(data, p); p += 2
    if (v & 0x8000):
        v = ((v & 0x7fff) << 16) | u16(data, p); p += 2
    units = [u16(data, p + 2 * i) for i in range(v)]; p += 2 * v
    return ''.join(chr(c) for c in units)


def encode_string_pool(strings, flags):
    headerSize = 28
    data_blob = bytearray()
    offsets = []
    utf8 = bool(flags & FLAG_UTF8)
    for s in strings:
        offsets.append(len(data_blob))
        if utf8:
            sb = s.encode('utf-8'); cc = len(s); bl = len(sb)
            data_blob += bytes([0x80 | (cc >> 8), cc & 0xff]) if cc >= 0x80 else bytes([cc])
            data_blob += bytes([0x80 | (bl >> 8), bl & 0xff]) if bl >= 0x80 else bytes([bl])
            data_blob += sb + b'\x00'
        else:
            units = [ord(c) for c in s]; n = len(units)
            if n >= 0x8000:
                data_blob += struct.pack('<HH', 0x8000 | (n >> 16), n & 0xffff)
            else:
                data_blob += struct.pack('<H', n)
            for c in units:
                data_blob += struct.pack('<H', c)
            data_blob += struct.pack('<H', 0)
    stringCount = len(strings)
    stringsStart = headerSize + stringCount * 4
    # 补齐到 4 字节边界：XML 要求每个 chunk 的 size 是 4 的整数倍，
    # 否则 aapt2 报 "XML size ... not on an integer boundary"（apksigner 宽容但 aapt2 严格）
    if len(data_blob) % 4:
        data_blob += b'\x00' * (4 - len(data_blob) % 4)
    stylesStart = stringsStart + len(data_blob)
    size = stylesStart
    chunk = bytearray()
    chunk += struct.pack('<HHI', RES_STRING_POOL_TYPE, headerSize, size)
    chunk += struct.pack('<IIIII', stringCount, 0, flags, stringsStart, stylesStart)
    for o in offsets:
        chunk += struct.pack('<I', o)
    chunk += data_blob
    return bytes(chunk)


# ---- resource map ----------------------------------------------------------
def parse_resource_map(data, chunk_start):
    off = chunk_start
    size = u32(data, off + 4)
    headerSize = u16(data, off + 2)
    count = (size - headerSize) // 4
    return [u32(data, off + headerSize + i * 4) for i in range(count)]


def encode_resource_map(ids):
    headerSize = 8
    size = headerSize + len(ids) * 4
    chunk = bytearray()
    chunk += struct.pack('<HHI', RES_XML_RESOURCE_MAP_TYPE, headerSize, size)
    for i in ids:
        chunk += struct.pack('<I', i)
    return bytes(chunk)


# ---- element / attribute parsing ------------------------------------------
class Attr:
    __slots__ = ('ns', 'name', 'rawValue', 'vsize', 'vtype', 'vdata')
    def __init__(self, ns, name, rawValue, vsize, vtype, vdata):
        self.ns = ns; self.name = name; self.rawValue = rawValue
        self.vsize = vsize; self.vtype = vtype; self.vdata = vdata


def parse_start_element(data, chunk_start):
    size = u32(data, chunk_start + 4)
    lineNumber = u32(data, chunk_start + 8)
    comment = u32(data, chunk_start + 12)
    ns = u32(data, chunk_start + 16)
    name = u32(data, chunk_start + 20)
    attrCount = u16(data, chunk_start + 28)
    attr_base = chunk_start + ATTR_BASE
    attrs = []
    for i in range(attrCount):
        ao = attr_base + i * ATTR_SIZE
        ans = u32(data, ao)
        an = u32(data, ao + 4)
        araw = u32(data, ao + 8)
        avsize = u16(data, ao + 12)
        avtype = data[ao + 15]
        avdata = u32(data, ao + 16)
        attrs.append(Attr(ans, an, araw, avsize, avtype, avdata))
    return dict(size=size, lineNumber=lineNumber, comment=comment, ns=ns, name=name,
                attrCount=attrCount, attrs=attrs, start=chunk_start, end=chunk_start + size)


def parse_end_element(data, chunk_start):
    size = u32(data, chunk_start + 4)
    lineNumber = u32(data, chunk_start + 8)
    comment = u32(data, chunk_start + 12)
    ns = u32(data, chunk_start + 16)
    name = u32(data, chunk_start + 20)
    return dict(size=size, lineNumber=lineNumber, comment=comment, ns=ns, name=name,
                start=chunk_start, end=chunk_start + size)


def parse_namespace(data, chunk_start):
    # uri is at +20 (ns string idx at +16, uri string idx at +20)
    return u32(data, chunk_start + 20)


# ---- builders --------------------------------------------------------------
def build_start_element(name_idx, ns_idx, attrs):
    attrCount = len(attrs)
    headerSize = 16
    size = ATTR_BASE + attrCount * ATTR_SIZE   # 36 + n*20
    # attributeStart：老 aapt1 生成的 manifest 用「相对 ext 起点」的 20；
    # aapt2 用「相对 node 起点」的 36。为兼容 aapt1 风格文件（apksigner 按文件自身
    # 风格解析），统一写 20（相对 ext 起点，即 node+16+20=node+36 处即 attrs 开始，
    # 与 ATTR_BASE 计算一致）。两种风格在 Android 解析器里都能正确找到 attrs。
    attrStart = 20
    body = bytearray()
    body += struct.pack('<II', 0, NS_NONE)              # lineNumber, comment
    body += struct.pack('<II', ns_idx, name_idx)        # ns, name
    body += struct.pack('<HHHHHH', attrStart, ATTR_SIZE, attrCount, 0xFFFF, 0xFFFF, 0xFFFF)
    for a in attrs:
        body += struct.pack('<II', a.ns, a.name)
        body += struct.pack('<I', a.rawValue)
        body += struct.pack('<H', a.vsize)
        body += struct.pack('<B', 0)                    # res0
        body += struct.pack('<B', a.vtype)
        body += struct.pack('<I', a.vdata)
    chunk = bytearray()
    chunk += struct.pack('<HHI', RES_XML_START_ELEMENT, headerSize, size)
    chunk += body
    return bytes(chunk)


def build_end_element(name_idx, ns_idx):
    headerSize = 16
    size = 24
    chunk = bytearray()
    chunk += struct.pack('<HHI', RES_XML_END_ELEMENT, headerSize, size)
    chunk += struct.pack('<II', 0, NS_NONE)             # lineNumber, comment
    chunk += struct.pack('<II', ns_idx, name_idx)
    return bytes(chunk)


def make_str_attr(ns, name_ridx, string_idx):
    return Attr(ns=ns, name=name_ridx, rawValue=string_idx,
                vsize=8, vtype=TYPE_STRING, vdata=string_idx)


def make_bool_attr(ns, name_ridx):
    return Attr(ns=ns, name=name_ridx, rawValue=NS_NONE,
                vsize=8, vtype=TYPE_INT_BOOLEAN, vdata=0xFFFFFFFF)


# ---- top-level parse -------------------------------------------------------
def parse_manifest(data):
    assert u16(data, 0) == RES_XML_TYPE, "not a binary XML"
    chunks = []
    pos = 8
    sp = rm = android_ns = None
    while pos < len(data):
        ctype = u16(data, pos)
        csize = u32(data, pos + 4)
        if csize == 0:
            break
        chunks.append((ctype, pos, csize))
        if ctype == RES_STRING_POOL_TYPE and sp is None:
            sp = decode_string_pool(data, pos)
        elif ctype == RES_XML_RESOURCE_MAP_TYPE and rm is None:
            rm = parse_resource_map(data, pos)
        elif ctype == RES_XML_START_NAMESPACE and android_ns is None:
            android_ns = parse_namespace(data, pos)
        pos += csize
    return dict(chunks=chunks, sp=sp, rm=rm, android_ns=android_ns, data=data)


def analyze(data):
    m = parse_manifest(data)
    sp, rm = m['sp'], m['rm']
    strings = sp[0]; flags = sp[1]
    print("string pool: %d strings, flags=0x%04x (%s)" % (
        len(strings), flags, 'UTF8' if (flags & FLAG_UTF8) else 'UTF16'))
    print("resource map: %d ids" % len(rm))
    for label, aid in [('name', A_NAME), ('exported', A_EXPORTED), ('cleartext', A_CLEARTEXT)]:
        idx = rm.index(aid) if aid in rm else -1
        print("  %-10s 0x%08x -> resource-map index %d" % (label, aid, idx))
    print("android namespace uri string index:", m['android_ns'], "->", strings[m['android_ns']])
    print("\n--- elements ---")
    stack = []
    for (ctype, pos, cs) in m['chunks']:
        if ctype == RES_XML_START_ELEMENT:
            e = parse_start_element(data, pos)
            nm = strings[e['name']]
            stack.append(nm)
            aname = None
            for a in e['attrs']:
                if a.name == rm.index(A_NAME):
                    aname = strings[a.vdata]
            print("  %-28s name=%-45s parent=%s" % (nm, aname or '', stack[-2] if len(stack) > 1 else '<root>'))
        elif ctype == RES_XML_END_ELEMENT:
            if stack: stack.pop()
    print("\n--- launcher / main categories ---")
    for (ctype, pos, cs) in m['chunks']:
        if ctype == RES_XML_START_ELEMENT:
            e = parse_start_element(data, pos)
            if strings[e['name']] == 'category':
                for a in e['attrs']:
                    if a.name == rm.index(A_NAME) and ('LAUNCHER' in strings[a.vdata] or 'MAIN' in strings[a.vdata]):
                        print("  category android:name=%s" % strings[a.vdata])


# ---- edit ------------------------------------------------------------------
def detect(data):
    """自动检测包名(pkg)与原 LAUNCHER activity 全名(orig)。返回 (pkg, orig)。"""
    m = parse_manifest(data)
    strings = m['sp'][0]; rm = m['rm']; android_ns = m['android_ns']
    r_name = rm.index(A_NAME) if A_NAME in rm else -1
    idx_pkg = strings.index('package') if 'package' in strings else -1
    pkg = None; launcher = None
    stack = []; act_stack = []
    for (ctype, pos, cs) in m['chunks']:
        if ctype == RES_XML_START_ELEMENT:
            e = parse_start_element(data, pos)
            nm = strings[e['name']]
            aname = None
            for a in e['attrs']:
                if r_name >= 0 and a.name == r_name:
                    aname = strings[a.vdata]
            if nm == 'manifest' and idx_pkg >= 0:
                for a in e['attrs']:
                    if a.name == idx_pkg and a.ns == NS_NONE:
                        pkg = strings[a.vdata]
            elif nm == 'activity':
                act_stack.append(aname)
            elif nm == 'activity-alias':
                # alias 入口：优先取 android:targetActivity(0x01010202) 作为真实拉起类
                alias_tgt = None
                ridx_tgt = rm.index(0x01010202) if 0x01010202 in rm else -1
                for a in e['attrs']:
                    if a.name == ridx_tgt and a.vtype == TYPE_STRING and a.vdata < len(strings):
                        alias_tgt = strings[a.vdata]
                        break
                act_stack.append(alias_tgt or aname)
            elif nm == 'category' and act_stack and launcher is None:
                if aname == 'android.intent.category.LAUNCHER':
                    launcher = act_stack[-1]
            stack.append(nm)
        elif ctype == RES_XML_END_ELEMENT:
            nm = strings[parse_end_element(data, pos)['name']]
            if nm in ('activity', 'activity-alias') and act_stack:
                act_stack.pop()
            if stack:
                stack.pop()
    if launcher and launcher.startswith('.'):
        launcher = (pkg or '') + launcher
    return pkg, launcher


def edit(data, pkg, orig):
    m = parse_manifest(data)
    strings = list(m['sp'][0]); flags = m['sp'][1]; rm = list(m['rm'])
    android_ns = m['android_ns']

    # 自动检测包名与原 launcher（二进制 manifest 无法用文本正则提取）
    if not pkg or not orig:
        dp, do = detect(data)
        if not pkg: pkg = dp or 'com.cardkey.demo'
        if not orig: orig = do or (dp + '.MainActivity' if dp else 'com.cardkey.demo.MainActivity')

    def sidx(s):
        if s in strings:
            return strings.index(s)
        strings.append(s)
        return len(strings) - 1

    GATE = 'com.cardkey.gate.GateActivity'
    idx_gate = sidx(GATE)
    idx_default = sidx('android.intent.category.DEFAULT')
    idx_main = sidx('android.intent.action.MAIN')
    idx_launcher = sidx('android.intent.category.LAUNCHER')
    idx_internet = sidx('android.permission.INTERNET')

    def ridx(aid):
        if aid in rm:
            return rm.index(aid)
        rm.append(aid)
        return len(rm) - 1
    r_name = ridx(A_NAME)
    r_exported = ridx(A_EXPORTED)
    r_cleartext = ridx(A_CLEARTEXT)

    # Build new inner XML content, applying edits + insertions.
    new_chunks = []
    stack = []
    already_internet = False
    seen_orig = False

    def rebuild_application(e):
        attrs = list(e['attrs'])
        for a in attrs:
            if a.name == r_cleartext:
                return None
        attrs.append(make_bool_attr(android_ns, r_cleartext))
        return build_start_element(e['name'], e['ns'], attrs)

    def flip_launcher_in_activity(e):
        # only flip within the original launch activity's filter
        return None

    def flip_category_if_launcher(e):
        changed = False
        attrs = []
        for a in e['attrs']:
            if a.name == r_name and strings[a.vdata] == 'android.intent.category.LAUNCHER':
                a = Attr(a.ns, a.name, idx_default, a.vsize, a.vtype, idx_default)
                changed = True
            attrs.append(a)
        return build_start_element(e['name'], e['ns'], attrs) if changed else None

    gate_attrs = [make_str_attr(android_ns, r_name, idx_gate),
                  make_bool_attr(android_ns, r_exported)]
    gate_subtree = bytearray()
    gate_subtree += build_start_element(sidx('activity'), NS_NONE, gate_attrs)
    gate_subtree += build_start_element(sidx('intent-filter'), NS_NONE, [])
    gate_subtree += build_start_element(sidx('action'), NS_NONE,
                                        [make_str_attr(android_ns, r_name, idx_main)])
    gate_subtree += build_end_element(sidx('action'), NS_NONE)
    gate_subtree += build_start_element(sidx('category'), NS_NONE,
                                        [make_str_attr(android_ns, r_name, idx_launcher)])
    gate_subtree += build_end_element(sidx('category'), NS_NONE)
    gate_subtree += build_end_element(sidx('intent-filter'), NS_NONE)
    gate_subtree += build_end_element(sidx('activity'), NS_NONE)

    perm_subtree = bytearray()
    perm_subtree += build_start_element(sidx('uses-permission'), NS_NONE,
                                        [make_str_attr(android_ns, r_name, idx_internet)])
    perm_subtree += build_end_element(sidx('uses-permission'), NS_NONE)

    for (ctype, pos, cs) in m['chunks']:
        if ctype == RES_STRING_POOL_TYPE or ctype == RES_XML_RESOURCE_MAP_TYPE:
            continue
        if ctype == RES_XML_START_ELEMENT:
            e = parse_start_element(data, pos)
            ename = strings[e['name']]
            stack.append(ename)
            out = None
            if ename == 'application':
                out = rebuild_application(e)
            elif ename == 'category':
                out = flip_category_if_launcher(e)
            elif ename == 'uses-permission':
                for a in e['attrs']:
                    if a.name == r_name and strings[a.vdata] == 'android.permission.INTERNET':
                        already_internet = True
            elif ename == 'activity':
                for a in e['attrs']:
                    if a.name == r_name and strings[a.vdata] == orig:
                        seen_orig = True
            if out is None:
                new_chunks.append(data[pos:pos + cs])
            else:
                new_chunks.append(out)
        elif ctype == RES_XML_END_ELEMENT:
            ename = strings[parse_end_element(data, pos)['name']]
            if ename == 'application':
                new_chunks.append(bytes(gate_subtree))
            if ename == 'manifest' and not already_internet:
                new_chunks.append(bytes(perm_subtree))
            new_chunks.append(data[pos:pos + cs])
            if stack: stack.pop()
        else:
            new_chunks.append(data[pos:pos + cs])

    if not seen_orig:
        print("WARNING: original launch activity %s not found; no launcher demotion performed" % orig)

    sp_new = encode_string_pool(strings, flags)
    rm_new = encode_resource_map(rm)
    inner = b''.join(new_chunks)
    total = 8 + len(sp_new) + len(rm_new) + len(inner)
    outer = struct.pack('<HHI', RES_XML_TYPE, 8, total)
    return outer + sp_new + rm_new + inner


def verify(data, orig):
    """Re-parse edited manifest; assert gate present + launcher flipped."""
    m = parse_manifest(data)
    strings = m['sp'][0]; rm = m['rm']
    r_name = rm.index(A_NAME)
    has_gate = False
    gate_launcher = False
    orig_launcher = False
    stack = []          # list of (element_name_string, is_activity_bool)
    for (ctype, pos, cs) in m['chunks']:
        if ctype == RES_XML_START_ELEMENT:
            e = parse_start_element(data, pos)
            nm = strings[e['name']]
            is_act = (nm == 'activity')
            aname = None
            for a in e['attrs']:
                if a.name == r_name:
                    aname = strings[a.vdata]
            if is_act and aname == 'com.cardkey.gate.GateActivity':
                has_gate = True
            # find enclosing activity for category checks
            if nm == 'category':
                cv = None
                for a in e['attrs']:
                    if a.name == r_name:
                        cv = strings[a.vdata]
                enc_act_class = None
                for (en, ea, ean) in reversed(stack):
                    if ea:
                        enc_act_class = ean; break
                if cv == 'android.intent.category.LAUNCHER' and enc_act_class == 'com.cardkey.gate.GateActivity':
                    gate_launcher = True
                if cv == 'android.intent.category.LAUNCHER' and enc_act_class == orig:
                    orig_launcher = True
            stack.append((nm, is_act, aname))
        elif ctype == RES_XML_END_ELEMENT:
            if stack: stack.pop()
    # structural integrity: outer size must equal file length
    outer_size = u32(data, 4)
    struct_ok = (outer_size == len(data))
    print("VERIFY: outer size == file length:", struct_ok, "(%d vs %d)" % (outer_size, len(data)))
    print("VERIFY: gate activity present =", has_gate)
    print("VERIFY: gate is LAUNCHER =", gate_launcher)
    print("VERIFY: original activity still LAUNCHER =", orig_launcher, "(should be False)")
    ok = has_gate and gate_launcher and not orig_launcher and struct_ok
    print("VERIFY: overall =", ok)
    return ok


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('mode', choices=['analyze', 'edit'])
    ap.add_argument('infile')
    ap.add_argument('outfile', nargs='?')
    ap.add_argument('--pkg', default='', help='包名（留空自动从 manifest 检测）')
    ap.add_argument('--orig', default='', help='原 LAUNCHER activity 全名（留空自动检测）')
    args = ap.parse_args()

    data = open(args.infile, 'rb').read()
    if args.mode == 'analyze':
        analyze(data)
        return
    if not args.outfile:
        print("edit mode requires outfile"); sys.exit(1)
    out = edit(data, args.pkg, args.orig)
    open(args.outfile, 'wb').write(out)
    ok = verify(out, args.orig)
    # 输出检测结果供 inject.py 使用（壳 dex 编译需要 pkg/orig）
    if not args.pkg or not args.orig:
        dp, do = detect(data)
        print("DETECT: pkg=%s orig=%s" % (dp or args.pkg, do or args.orig))
    print("wrote %s (%d bytes, was %d) ; verify=%s" % (args.outfile, len(out), len(data), ok))


if __name__ == '__main__':
    main()
