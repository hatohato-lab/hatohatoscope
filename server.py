# -*- coding: utf-8 -*-
"""hatohatoscope の Flask 版サーバー。

PHP 版（api/*.php・serve.php 等）と同じ仕様の窓口を、Python + Flask だけで提供する。
画面（all.html）は同じものをそのまま使う。どちらのサーバーでも動く。

使い方:
    1) api/config.example.json を api/config.json にコピーして root を書き換える
       （閲覧フォルダを足すなら api/roots.example.json → api/roots.json も）
    2) pip install flask
    3) python server.py
    4) ブラウザで http://localhost:8765/all.html

セキュリティ上の前提は README のとおり: 認証は無く、127.0.0.1 限定の待受だけが守り。
"""
import json
import os
import re
import subprocess
import sys
import tempfile
import time

from flask import Flask, Response, request, send_from_directory

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
app = Flask(__name__)

# ---------------------------------------------------------------- 設定の読み込み

def load_config():
    path = os.path.join(BASE_DIR, 'api', 'config.json')
    if not os.path.isfile(path):
        return None
    with open(path, encoding='utf-8') as f:
        return json.load(f)


def load_roots():
    path = os.path.join(BASE_DIR, 'api', 'roots.json')
    if not os.path.isfile(path):
        return []
    with open(path, encoding='utf-8') as f:
        data = json.load(f)
    return data if isinstance(data, list) else []


def norm(p):
    """比較用の正規化（区切りを / に・小文字化）。PHP版と同じ字句比較方針。"""
    return p.replace('\\', '/').rstrip('/').lower()


def allowed_roots(config):
    roots = []
    real = os.path.realpath(config['root'])
    roots.append(norm(real))
    for er in load_roots():
        if 'path' in er and os.path.isdir(er['path']):
            roots.append(norm(os.path.realpath(er['path'])))
    return roots


def under_allowed(path_n, roots):
    return any(path_n == ar or path_n.startswith(ar + '/') for ar in roots)


UUID_RE = re.compile(r'^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$', re.I)

# ---------------------------------------------------------------- 画面と静的ファイル

@app.route('/')
@app.route('/all.html')
def page():
    return send_from_directory(BASE_DIR, 'all.html')


@app.route('/img/<path:name>')
def img(name):
    return send_from_directory(os.path.join(BASE_DIR, 'img'), name)

# ---------------------------------------------------------------- ツリー一覧

TREE_CACHE = os.path.join(tempfile.gettempdir(), 'hhscope_py_alltree_cache.json')
# 増分スキャン用の台帳：フォルダごとの一覧（名前・種別・サイズ）を「フォルダの更新時刻」付きで覚える。
# 直下の増減・改名は更新時刻で検知できる。sizeは画面未使用のため古くてもよい（2026-09-05 追加）
DIR_CACHE_FILE = os.path.join(tempfile.gettempdir(), 'hhscope_py_dircache.json')


def scan_directory(path, exclude, max_depth, depth=0, exclude_files=(), dcache=None, seen=None, dirty=None):
    result = []
    if dcache is None:
        dcache, seen, dirty = {}, set(), [False]
    if max_depth > 0 and depth >= max_depth:
        return result
    try:
        mt = os.path.getmtime(path)
    except OSError:
        return result
    ent = dcache.get(path)
    if ent and ent[0] == mt:
        listing = ent[1]
    else:
        # 台帳が古い時だけ実際に読み直す。os.scandir は一覧と同時に種別・サイズが取れて速い
        listing = []
        try:
            with os.scandir(path) as it:
                for e in it:
                    try:
                        if e.is_dir(follow_symlinks=True):
                            listing.append([e.name, 1, 0])
                        else:
                            try:
                                sz = e.stat().st_size
                            except OSError:
                                sz = 0
                            listing.append([e.name, 0, sz])
                    except OSError:
                        continue
        except OSError:
            return result
        listing.sort(key=lambda x: x[0])
        dcache[path] = [mt, listing]
        dirty[0] = True
    seen.add(path)
    folders, files, size_of = [], [], {}
    for name, is_dir_flag, sz in listing:
        if name in exclude:
            continue
        if is_dir_flag:
            if UUID_RE.match(name):
                continue
            folders.append(name)
        else:
            if name.endswith('.jsonl') or name in exclude_files:
                continue
            files.append(name)
            size_of[name] = sz
    for item in folders:
        full = path + '/' + item
        # 価値ある深い枝(projects/memory)だけ深く辿る（PHP版と同じ特例）
        child_max = depth + 6 if (max_depth > 0 and item in ('projects', 'memory')) else max_depth
        result.append({
            'name': item,
            'path': full.replace('/', '\\'),
            'children': scan_directory(full, exclude, child_max, depth + 1, exclude_files, dcache, seen, dirty),
        })
    for item in files:
        full = path + '/' + item
        result.append({'name': item, 'path': full.replace('/', '\\'), 'size': size_of.get(item, 0)})
    return result


@app.route('/api/file_tree.php')
def file_tree():
    config = load_config()
    if config is None:
        return Response(json.dumps({'error': 'api/config.json がありません。api/config.example.json をコピーして作成してください'},
                                   ensure_ascii=False), mimetype='application/json')
    if request.args.get('root') != '1':
        return Response(json.dumps({'error': 'use root=1'}), mimetype='application/json')
    fresh = request.args.get('fresh') == '1'
    if not fresh and os.path.isfile(TREE_CACHE) and time.time() - os.path.getmtime(TREE_CACHE) < 300:
        with open(TREE_CACHE, encoding='utf-8') as f:
            body = f.read()
        return Response(body, mimetype='application/json',
                        headers={'X-Cache': 'HIT', 'Cache-Control': 'no-store'})
    root = config['root']
    exclude = config.get('excludeFolders', ['.git', 'node_modules'])
    max_depth = int(config.get('maxDepth', 6))
    dcache, seen, dirty = {}, set(), [False]
    if os.path.isfile(DIR_CACHE_FILE):
        try:
            with open(DIR_CACHE_FILE, encoding='utf-8') as f:
                loaded = json.load(f)
            if isinstance(loaded, dict):
                dcache = loaded
        except (OSError, ValueError):
            pass
    tree = {
        'name': os.path.basename(root.rstrip('/')),
        'path': root.replace('/', '\\'),
        'children': scan_directory(root.rstrip('/'), exclude, max_depth, 0, (), dcache, seen, dirty),
    }
    for er in load_roots():
        if 'path' not in er or not os.path.isdir(er['path']):
            continue
        tree['children'].append({
            'name': er.get('name', os.path.basename(er['path'])),
            'path': er['path'].replace('/', '\\'),
            'children': scan_directory(er['path'].rstrip('/'), er.get('exclude', []),
                                       int(er.get('maxDepth', 4)), 0,
                                       er.get('excludeFiles', []), dcache, seen, dirty),
        })
    # 台帳の保存（今回見に行かなかったフォルダ＝消えた分は落とす）
    if dirty[0] or len(dcache) != len(seen):
        try:
            with open(DIR_CACHE_FILE, 'w', encoding='utf-8') as f:
                json.dump({k: v for k, v in dcache.items() if k in seen}, f)
        except OSError:
            pass
    # まとまり順ソート用：地図ファイルの場所を画面へ伝える（ローカル設定がある環境だけ）
    map_file = config.get('mapFile')
    if map_file and os.path.isfile(map_file):
        tree['mapFile'] = map_file.replace('/', '\\')
        if isinstance(config.get('mapGroupOrder'), list):
            tree['mapGroupOrder'] = config['mapGroupOrder']
    body = json.dumps(tree, ensure_ascii=False)
    try:
        with open(TREE_CACHE, 'w', encoding='utf-8') as f:
            f.write(body)
    except OSError:
        pass
    return Response(body, mimetype='application/json',
                    headers={'X-Cache': 'MISS', 'Cache-Control': 'no-store'})

# ---------------------------------------------------------------- ファイル配信

CONTENT_TYPES = {
    'html': 'text/html; charset=utf-8', 'htm': 'text/html; charset=utf-8',
    'css': 'text/css; charset=utf-8', 'js': 'text/javascript; charset=utf-8',
    'json': 'application/json; charset=utf-8', 'svg': 'image/svg+xml',
    'png': 'image/png', 'jpg': 'image/jpeg', 'jpeg': 'image/jpeg',
    'gif': 'image/gif', 'webp': 'image/webp', 'pdf': 'application/pdf',
}


@app.route('/serve.php')
def serve_file():
    config = load_config()
    if config is None:
        return Response('config not found (copy api/config.example.json to api/config.json)', status=500)
    roots = allowed_roots(config)
    p = request.args.get('p', '')
    if not p:
        return Response('no path', status=400)
    if not under_allowed(norm(p), roots):
        return Response('forbidden (outside allowed roots)', status=403)
    real = os.path.realpath(p)
    if not os.path.exists(real):
        return Response('not found', status=404)
    if not os.path.isfile(real):
        return Response('not a file', status=404)
    real_n = norm(real)
    if not any(real_n.startswith(ar + '/') for ar in roots):
        return Response('forbidden (resolved outside allowed roots)', status=403)
    base = real_n.rsplit('/', 1)[-1]
    if base in config.get('denyFiles', []):
        return Response('forbidden (protected file)', status=403)
    ext = os.path.splitext(real)[1].lstrip('.').lower()
    ct = CONTENT_TYPES.get(ext, 'text/plain; charset=utf-8')
    with open(real, 'rb') as f:
        body = f.read()
    return Response(body, mimetype=ct.split(';')[0],
                    headers={'Content-Type': ct, 'Cache-Control': 'no-store',
                             'X-Content-Type-Options': 'nosniff'})

# ---------------------------------------------------------------- 保存

SAVE_EXTS = {'md', 'txt', 'json', 'py', 'php', 'js', 'css', 'html', 'htm', 'xml', 'csv',
             'yml', 'yaml', 'sql', 'sh', 'bat', 'puml', 'ini', 'env', 'gitignore'}


@app.route('/save.php', methods=['POST'])
def save_file():
    config = load_config()
    if config is None:
        return Response('NG: config not found', status=500, mimetype='text/plain')
    root_n = norm(config['root'])
    p = request.form.get('p', '')
    if not p:
        return Response('NG: no path', status=400, mimetype='text/plain')
    if not norm(p).startswith(root_n + '/'):
        return Response('NG: outside root', status=403, mimetype='text/plain')
    ext = os.path.splitext(p)[1].lstrip('.').lower()
    if ext not in SAVE_EXTS:
        return Response('NG: not editable type (.' + ext + ')', status=415, mimetype='text/plain')
    if not os.path.isfile(p):
        return Response('NG: file not found', status=404, mimetype='text/plain')
    body = request.form.get('body', '').encode('utf-8')
    try:
        with open(p, 'wb') as f:
            f.write(body)
    except OSError:
        return Response('NG: write failed', status=500, mimetype='text/plain')
    return Response('OK: %d bytes saved' % len(body), mimetype='text/plain')

# ---------------------------------------------------------------- git 差分（読み取り専用）

def run_git(args, cwd=None):
    try:
        out = subprocess.run(['git', '-c', 'core.quotepath=false'] + args, cwd=cwd,
                             capture_output=True, timeout=60)
        return (out.stdout + out.stderr).decode('utf-8', 'replace')
    except Exception as e:  # git不在等
        return 'fatal: ' + str(e)


@app.route('/git_diff.php')
def git_diff():
    config = load_config()
    if config is None:
        return Response('config not found', status=500, mimetype='text/plain')
    roots = allowed_roots(config)
    p = request.args.get('p', '')
    if not p:
        return Response('no path', status=400, mimetype='text/plain')
    is_file = os.path.isfile(p)
    if not is_file and not os.path.isdir(p):
        return Response('not found', status=404, mimetype='text/plain')
    real = os.path.realpath(p)
    if not under_allowed(norm(p), roots) or not under_allowed(norm(real), roots):
        return Response('forbidden (outside allowed roots)', status=403, mimetype='text/plain')
    repo_dir = os.path.dirname(real) if is_file else real
    top = run_git(['-C', repo_dir, 'rev-parse', '--show-toplevel']).strip()
    if not top or 'fatal' in top.lower() or 'not a git' in top.lower():
        return Response('not a git repository: ' + p, status=404, mimetype='text/plain')
    top_n = norm(top)
    if not under_allowed(top_n, roots):
        return Response('forbidden (repo root outside allowed roots)', status=403, mimetype='text/plain')
    real_fwd = real.replace('\\', '/')
    pathspec, rel_label = [], ''
    if (real_fwd.lower() + '/').startswith(top_n + '/') and real_fwd.lower() != top_n:
        rel_label = real_fwd[len(top_n) + 1:]
        pathspec = ['--', rel_label]
    status = run_git(['-C', top, 'status', '--short'] + pathspec)
    unstaged = run_git(['-C', top, 'diff'] + pathspec)
    staged = run_git(['-C', top, 'diff', '--cached'] + pathspec)
    if is_file and not unstaged.strip() and not staged.strip() and status.lstrip().startswith('??'):
        ni = run_git(['diff', '--no-index', '--', os.devnull, real])
        if ni.strip():
            unstaged = '# 未追跡の新規ファイル（全行が追加扱い）\n' + ni
        else:
            unstaged = '# 未追跡の新規ファイルです（内容はファイルを開いて確認）\n'
    out = '# リポジトリ: %s\n' % top
    if rel_label:
        out += '# 対象を絞り込み: %s\n' % rel_label
    out += '# 取得時刻: %s\n' % time.strftime('%Y-%m-%d %H:%M:%S')
    out += '#\n# ==== 変更ファイル一覧 (git status --short) ====\n'
    out += status if status else '# （変更なし・作業ツリーはクリーン）\n'
    out += '\n# ==== 未ステージの差分 (git diff) ====\n'
    out += unstaged if unstaged else '# （なし）\n'
    out += '\n# ==== ステージ済みの差分 (git diff --cached) ====\n'
    out += staged if staged else '# （なし）\n'
    return Response(out, mimetype='text/plain', headers={'Cache-Control': 'no-store'})

# ---------------------------------------------------------------- git ステータス（ツリー色つけ）

GS_CACHE = os.path.join(tempfile.gettempdir(), 'hhscope_py_gitstatus_cache.json')
GS_LOCK = os.path.join(tempfile.gettempdir(), 'hhscope_py_gitstatus_lock')


def find_repos(d, exclude, depth):
    if depth < 0:
        return []
    if os.path.isdir(d + '/.git'):
        return [d]
    repos = []
    try:
        items = os.listdir(d)
    except OSError:
        return repos
    for it in items:
        if it in ('.', '..') or it in exclude:
            continue
        full = d + '/' + it
        if os.path.isdir(full):
            repos.extend(find_repos(full, exclude, depth - 1))
    return repos


@app.route('/git_status.php')
def git_status():
    if os.path.isfile(GS_CACHE) and time.time() - os.path.getmtime(GS_CACHE) < 90:
        with open(GS_CACHE, encoding='utf-8') as f:
            return Response(f.read(), mimetype='application/json',
                            headers={'X-Cache': 'HIT', 'Cache-Control': 'no-store'})
    if os.path.isfile(GS_LOCK) and time.time() - os.path.getmtime(GS_LOCK) < 60:
        body = '{"repos": 0, "files": {}}'
        if os.path.isfile(GS_CACHE):
            with open(GS_CACHE, encoding='utf-8') as f:
                body = f.read()
        return Response(body, mimetype='application/json', headers={'X-Cache': 'BUSY'})
    try:
        with open(GS_LOCK, 'w') as f:
            f.write('1')
    except OSError:
        pass
    config = load_config()
    if config is None:
        return Response('{"error": "config not found"}', mimetype='application/json')
    exclude = config.get('excludeFolders', [])
    bases = [config['root'].replace('\\', '/')]
    for er in load_roots():
        if 'path' in er and os.path.isdir(er['path']):
            bases.append(er['path'].replace('\\', '/'))
    repos = []
    for b in bases:
        repos.extend(find_repos(b.rstrip('/'), exclude, 4))
    files = {}
    for repo in repos:
        out = run_git(['-C', repo, 'status', '--porcelain', '-uall'])
        if not out or out.lower().startswith('fatal'):
            continue
        for line in out.split('\n'):
            if len(line) < 4:
                continue
            xy, path = line[:2], line[3:].strip()
            if ' -> ' in path:
                path = path.split(' -> ', 1)[1]
            path = path.strip('"')
            abs_p = (repo + '/' + path).replace('/', '\\')
            files[abs_p] = 'new' if ('?' in xy or 'A' in xy) else 'mod'
    body = json.dumps({'repos': len(repos), 'files': files}, ensure_ascii=False)
    try:
        with open(GS_CACHE, 'w', encoding='utf-8') as f:
            f.write(body)
    except OSError:
        pass
    return Response(body, mimetype='application/json',
                    headers={'X-Cache': 'MISS', 'Cache-Control': 'no-store'})

# ---------------------------------------------------------------- 全文検索（索引方式）

FTS_EXTS = {'md', 'html', 'htm'}
FTS_MAX_SIZE = 2 * 1024 * 1024
FTS_PACK = os.path.join(tempfile.gettempdir(), 'hhscope_py_fts.pack')
FTS_MANIFEST = os.path.join(tempfile.gettempdir(), 'hhscope_py_fts_manifest.json')


def fts_bases_exclude(config):
    exclude = list(config.get('excludeFolders', []))
    exclude += config.get('ftsExcludeFolders', [])  # 機密フォルダは索引に絶対入れない
    bases = [config['root'].replace('\\', '/')]
    for er in load_roots():
        if 'path' in er and os.path.isdir(er['path']):
            bases.append(er['path'].replace('\\', '/'))
    return bases, exclude, config.get('denyFiles', [])


def fts_walk(bases, exclude, deny_files):
    cur = {}
    stack = [b.rstrip('/') for b in bases]
    while stack:
        d = stack.pop()
        try:
            items = os.listdir(d)
        except OSError:
            continue
        for it in items:
            if it in exclude:
                continue
            full = d + '/' + it
            if os.path.isdir(full):
                if not UUID_RE.match(it):
                    stack.append(full)
                continue
            if it in deny_files:
                continue
            ext = it.rsplit('.', 1)[-1].lower() if '.' in it else ''
            if ext not in FTS_EXTS:
                continue
            try:
                st = os.stat(full)
            except OSError:
                continue
            if st.st_size > FTS_MAX_SIZE or st.st_size == 0:
                continue
            cur[full] = [int(st.st_mtime), st.st_size]
    return cur


def fts_load_manifest():
    if not os.path.isfile(FTS_MANIFEST):
        return {'entries': {}, 'garbage': 0, 'pending': {}}
    try:
        with open(FTS_MANIFEST, encoding='utf-8') as f:
            m = json.load(f)
    except (OSError, ValueError):
        return {'entries': {}, 'garbage': 0, 'pending': {}}
    if not isinstance(m, dict) or 'entries' not in m:
        return {'entries': {}, 'garbage': 0, 'pending': {}}
    m.setdefault('pending', {})
    return m


def fts_save_manifest(m):
    with open(FTS_MANIFEST, 'w', encoding='utf-8') as f:
        json.dump(m, f)


@app.route('/api/fts.php')
def fts():
    config = load_config()
    if config is None:
        return Response('{"error": "config not found"}', mimetype='application/json')
    op = request.args.get('op', '')
    if op == 'build':
        man = fts_load_manifest()
        if not man['pending']:
            bases, exclude, deny = fts_bases_exclude(config)
            cur = fts_walk(bases, exclude, deny)
            for path, ms in cur.items():
                e = man['entries'].get(path)
                if e is None or e['mtime'] != ms[0] or e['size'] != ms[1]:
                    man['pending'][path] = ms
            for path in list(man['entries']):
                if path not in cur:
                    man['garbage'] += man['entries'][path]['len']
                    del man['entries'][path]
        deadline = time.time() + 12.0
        done = 0
        with open(FTS_PACK, 'ab') as fh:
            fh.seek(0, os.SEEK_END)
            for path in list(man['pending']):
                if time.time() > deadline:
                    break
                ms = man['pending'].pop(path)
                done += 1
                try:
                    with open(path, 'rb') as src:
                        body = src.read()
                except OSError:
                    continue
                if path in man['entries']:
                    man['garbage'] += man['entries'][path]['len']
                off = fh.tell()
                fh.write(body)
                man['entries'][path] = {'off': off, 'len': len(body), 'mtime': ms[0], 'size': ms[1]}
        remaining = len(man['pending'])
        if remaining == 0 and man['garbage'] > 0:
            pack_size = os.path.getsize(FTS_PACK) if os.path.isfile(FTS_PACK) else 0
            if man['garbage'] > pack_size / 2:
                tmp = FTS_PACK + '.new'
                with open(FTS_PACK, 'rb') as src, open(tmp, 'wb') as dst:
                    for path, e in man['entries'].items():
                        src.seek(e['off'])
                        body = src.read(max(e['len'], 1))
                        e['off'] = dst.tell()
                        dst.write(body)
                os.replace(tmp, FTS_PACK)  # 索引ファイル自身の入れ替え（ワークスペースのファイルではない）
                man['garbage'] = 0
        fts_save_manifest(man)
        return Response(json.dumps({'op': 'build', 'processed': done, 'remaining': remaining,
                                    'indexed': len(man['entries'])}), mimetype='application/json')
    if op == 'search':
        q = request.args.get('q', '').strip()
        if len(q) < 2:
            return Response(json.dumps({'error': '検索語は2文字以上'}, ensure_ascii=False),
                            mimetype='application/json')
        terms = [t.encode('utf-8') for t in q.split()]
        man = fts_load_manifest()
        if not man['entries'] or not os.path.isfile(FTS_PACK):
            return Response(json.dumps({'need_build': True, 'reason': '索引が未作成'}, ensure_ascii=False),
                            mimetype='application/json')
        changed = list(man['pending'])
        if len(changed) > 800:
            return Response(json.dumps({'need_build': True,
                                        'reason': '未反映の変更が%d件' % len(changed)}, ensure_ascii=False),
                            mimetype='application/json')
        changed_set = set(changed)
        hits, truncated, max_files = [], False, 200

        def probe(body, path):
            low = body.lower()
            for t in terms:
                if t.lower() not in low:
                    return
            pos = low.index(terms[0].lower())
            line_no = body.count(b'\n', 0, pos) + 1
            ls = body.rfind(b'\n', 0, pos) + 1
            le = body.find(b'\n', pos)
            if le < 0:
                le = len(body)
            line = body[ls:le].decode('utf-8', 'replace').strip()
            if len(line) > 160:
                line = line[:160] + '…'
            hits.append({'path': path.replace('/', '\\'), 'line': line_no, 'text': line})

        with open(FTS_PACK, 'rb') as fh:
            for path, e in man['entries'].items():
                if len(hits) >= max_files:
                    truncated = True
                    break
                if path in changed_set:
                    continue
                fh.seek(e['off'])
                probe(fh.read(e['len']) if e['len'] > 0 else b'', path)
        for path in changed:
            if len(hits) >= max_files:
                truncated = True
                break
            try:
                with open(path, 'rb') as f:
                    probe(f.read(), path)
            except OSError:
                pass
        hits.sort(key=lambda h: h['path'])
        return Response(json.dumps({'q': q, 'hits': hits, 'indexed': len(man['entries']),
                                    'live': len(changed), 'truncated': truncated}, ensure_ascii=False),
                        mimetype='application/json')
    return Response(json.dumps({'error': 'op は build か search'}, ensure_ascii=False),
                    mimetype='application/json')

# ---------------------------------------------------------------- PlantUML その場描画

PUML_CACHE_DIR = os.path.join(tempfile.gettempdir(), 'hhscope_py_puml')


@app.route('/api/puml.php')
def puml():
    config = load_config()
    if config is None:
        return Response('config not found', status=500, mimetype='text/plain')
    jar = os.path.join(BASE_DIR, 'api', 'bin', 'plantuml.jar')
    java = config.get('javaPath', 'java')
    if not os.path.isfile(jar):
        return Response('plantuml.jar not installed', status=501, mimetype='text/plain')
    roots = allowed_roots(config)
    p = request.args.get('p', '')
    if not p or not p.lower().endswith('.puml'):
        return Response('need .puml path', status=400, mimetype='text/plain')
    real = os.path.realpath(p)
    if not os.path.isfile(real):
        return Response('not found', status=404, mimetype='text/plain')
    real_n = norm(real)
    if not any(real_n.startswith(ar + '/') for ar in roots):
        return Response('forbidden', status=403, mimetype='text/plain')
    os.makedirs(PUML_CACHE_DIR, exist_ok=True)
    import hashlib
    key = hashlib.md5(('%s|%d' % (real_n, int(os.path.getmtime(real)))).encode()).hexdigest()
    cache_file = os.path.join(PUML_CACHE_DIR, key + '.svg')
    if os.path.isfile(cache_file):
        with open(cache_file, 'rb') as f:
            return Response(f.read(), mimetype='image/svg+xml', headers={'X-Puml': 'cache'})
    with open(real, 'rb') as f:
        src = f.read()
    try:
        proc = subprocess.run([java, '-Djava.awt.headless=true', '-jar', jar,
                               '-tsvg', '-charset', 'UTF-8', '-pipe'],
                              input=src, capture_output=True, timeout=120)
    except Exception as e:
        return Response('java start failed: %s' % e, status=500, mimetype='text/plain')
    svg = proc.stdout
    if b'<svg' not in svg:
        return Response(b'render failed\n' + proc.stderr[:500], status=500, mimetype='text/plain')
    try:
        with open(cache_file, 'wb') as f:
            f.write(svg)
    except OSError:
        pass
    return Response(svg, mimetype='image/svg+xml', headers={'X-Puml': 'fresh'})


# ---------------------------------------------------------------- 起動

if __name__ == '__main__':
    config = load_config()
    port = int(config.get('port', 8765)) if config else 8765
    print('hatohatoscope (Flask) : http://localhost:%d/all.html' % port)
    # 認証なしのため 127.0.0.1 限定は絶対に変えない（README のセキュリティ前提）
    app.run(host='127.0.0.1', port=port, threaded=True)
