<?php
// 全文検索（索引方式）。全体ビューア all.html 用・読み取りはワークスペース、書き込みは索引ファイルのみ。
//
// 背景（2026-08-29 実測）：このPCではファイルを開くたびにOS側の検査が入り、1件あたり約8ms。
// 26,000ファイルを毎回開くと数分かかるため、内容を1本のパックファイルに集約しておき、
// 検索時はその1本だけを順に読む（1本なら数秒で読み切れる）。
//
// op=build  … 索引の作成・差分更新。全フォルダ走査（このPCでは約12秒）は「処理待ちが空のとき」だけ行い、
//             見つけた差分を pending（処理待ち一覧）に積む。1回の呼び出しで最大12秒ぶん読み込み、
//             残りがあれば remaining を返す（呼び出し側が remaining=0 になるまで繰り返す）。
// op=search&q=… … 検索。フォルダ走査はせず索引だけを読む（実測0.1秒）。鮮度は build 側に任せる。
//             pending に残っている少数の変更ファイルだけ、その場で読んで補う。
//
// 索引の置き場：PHPの一時フォルダ（ツリーキャッシュと同じ場所。ワークスペースは汚さない）

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
set_time_limit(120);

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) { echo json_encode(['error' => 'config not found']); exit; }
$config = require $configFile;
$exclude = isset($config['excludeFolders']) ? $config['excludeFolders'] : [];
// 索引は「中身の複製」を作るため、機密フォルダは絶対に含めない（config の ftsExcludeFolders で指定）
if (isset($config['ftsExcludeFolders']) && is_array($config['ftsExcludeFolders'])) {
    $exclude = array_merge($exclude, $config['ftsExcludeFolders']);
}
$denyFiles = isset($config['denyFiles']) ? $config['denyFiles'] : [];

// 対象は文書だけに絞る（2026-08-29 本人指定。コードや設定の複製を索引に持たないため安全面でも良い）
$EXTS = ['md', 'html', 'htm'];
$MAX_SIZE = 2 * 1024 * 1024;

$PACK = sys_get_temp_dir() . '/hhscope_fts.pack';
$MANIFEST = sys_get_temp_dir() . '/hhscope_fts_manifest.json';

$bases = [str_replace('\\', '/', $config['root'])];
$rootsFile = __DIR__ . '/roots.php';
if (is_file($rootsFile)) {
    $extra = include $rootsFile;
    if (is_array($extra)) {
        foreach ($extra as $er) {
            if (isset($er['path']) && is_dir($er['path'])) $bases[] = str_replace('\\', '/', $er['path']);
        }
    }
}

// 今あるテキスト系ファイルの一覧（statのみ・数秒で終わる）
function walkCurrent($bases, $exclude, $denyFiles, $EXTS, $MAX_SIZE) {
    $cur = [];
    $uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    $stack = [];
    foreach ($bases as $b) $stack[] = rtrim($b, '/');
    while ($stack) {
        $dir = array_pop($stack);
        $items = @scandir($dir);
        if ($items === false) continue;
        foreach ($items as $it) {
            if ($it === '.' || $it === '..') continue;
            if (in_array($it, $exclude, true)) continue;
            $full = $dir . '/' . $it;
            if (@is_dir($full)) {
                if (preg_match($uuid, $it)) continue;
                $stack[] = $full;
                continue;
            }
            if (in_array($it, $denyFiles, true)) continue;
            $dot = strrpos($it, '.');
            $ext = ($dot === false) ? '' : strtolower(substr($it, $dot + 1));
            if (!in_array($ext, $EXTS, true)) continue;
            $st = @stat($full);
            if ($st === false || $st['size'] > $MAX_SIZE || $st['size'] === 0) continue;
            $cur[$full] = [$st['mtime'], $st['size']];
        }
    }
    return $cur;
}

function loadManifest($MANIFEST) {
    if (!is_file($MANIFEST)) return ['entries' => [], 'garbage' => 0, 'pending' => []];
    $m = json_decode(@file_get_contents($MANIFEST), true);
    if (!is_array($m) || !isset($m['entries'])) return ['entries' => [], 'garbage' => 0, 'pending' => []];
    if (!isset($m['pending'])) $m['pending'] = [];
    return $m;
}

// 索引と実体の差分。changed=読み直しが要る、removed=消えた
function diffIndex($cur, $man) {
    $changed = [];
    foreach ($cur as $path => $ms) {
        $e = isset($man['entries'][$path]) ? $man['entries'][$path] : null;
        if ($e === null || $e['mtime'] !== $ms[0] || $e['size'] !== $ms[1]) $changed[] = $path;
    }
    return $changed;
}

$op = isset($_GET['op']) ? $_GET['op'] : '';

if ($op === 'build') {
    $man = loadManifest($MANIFEST);
    // 処理待ちが空のときだけ全フォルダを走査して差分を積む（走査は重いので毎回はしない）
    if (!$man['pending']) {
        $cur = walkCurrent($bases, $exclude, $denyFiles, $EXTS, $MAX_SIZE);
        $changed = diffIndex($cur, $man);
        foreach ($changed as $p) $man['pending'][$p] = $cur[$p];
        // 消えたファイルは台帳から外す（パック内はゴミとして残し、量が増えたら作り直す）
        foreach (array_keys($man['entries']) as $p) {
            if (!isset($cur[$p])) {
                $man['garbage'] += $man['entries'][$p]['len'];
                unset($man['entries'][$p]);
            }
        }
    }
    $deadline = microtime(true) + 12.0;
    $fh = fopen($PACK, 'ab');
    fseek($fh, 0, SEEK_END);   // 追記モードは開いた直後 ftell が0を返すことがあり、位置記録が全てずれる（2026-08-30 実測）
    $done = 0;
    foreach ($man['pending'] as $path => $ms) {
        if (microtime(true) > $deadline) break;
        unset($man['pending'][$path]);
        $done++;
        $body = @file_get_contents($path);
        if ($body === false) continue;
        if (isset($man['entries'][$path])) $man['garbage'] += $man['entries'][$path]['len'];
        $off = ftell($fh);
        fwrite($fh, $body);
        $man['entries'][$path] = ['off' => $off, 'len' => strlen($body),
            'mtime' => $ms[0], 'size' => $ms[1]];
    }
    fclose($fh);
    // ゴミがパックの半分を超えたら詰め直す（残りが無くなった回だけ）
    $remaining = count($man['pending']);
    if ($remaining === 0 && $man['garbage'] > 0) {
        $packSize = @filesize($PACK) ?: 0;
        if ($man['garbage'] > $packSize / 2) {
            $tmp = $PACK . '.new';
            $src = fopen($PACK, 'rb');
            $dst = fopen($tmp, 'wb');
            foreach ($man['entries'] as $p => $e) {
                fseek($src, $e['off']);
                $body = fread($src, max($e['len'], 1));
                $man['entries'][$p]['off'] = ftell($dst);
                fwrite($dst, $body);
            }
            fclose($src); fclose($dst);
            @rename($tmp, $PACK);   // 索引ファイル自身の入れ替え（ワークスペースのファイルではない）
            $man['garbage'] = 0;
        }
    }
    @file_put_contents($MANIFEST, json_encode($man));
    echo json_encode(['op' => 'build', 'processed' => $done, 'remaining' => $remaining,
        'indexed' => count($man['entries'])]);
    exit;
}

if ($op === 'search') {
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    if (mb_strlen($q) < 2) { echo json_encode(['error' => '検索語は2文字以上']); exit; }
    $terms = preg_split('/\s+/u', $q);

    $man = loadManifest($MANIFEST);
    if (!$man['entries'] || !is_file($PACK)) {
        echo json_encode(['need_build' => true, 'reason' => '索引が未作成']); exit;
    }
    // フォルダ走査はしない（走査だけで十秒超かかるため。鮮度は build に任せる）
    $changed = array_keys($man['pending']);
    if (count($changed) > 800) {
        echo json_encode(['need_build' => true, 'reason' => '未反映の変更が' . count($changed) . '件']); exit;
    }
    $changedSet = array_flip($changed);

    $hits = [];
    $truncated = false;
    $MAX_FILES = 200;

    $probe = function ($body, $path) use (&$hits, $terms) {
        foreach ($terms as $t) {
            if (stripos($body, $t) === false) return;
        }
        $pos = stripos($body, $terms[0]);
        $lineNo = substr_count(substr($body, 0, $pos), "\n") + 1;
        $ls = strrpos(substr($body, 0, $pos), "\n");
        $ls = ($ls === false) ? 0 : $ls + 1;
        $le = strpos($body, "\n", $pos);
        if ($le === false) $le = strlen($body);
        $line = trim(substr($body, $ls, $le - $ls));
        if (mb_strlen($line) > 160) $line = mb_substr($line, 0, 160) . '…';
        $hits[] = ['path' => str_replace('/', '\\', $path), 'line' => $lineNo, 'text' => $line];
    };

    // 1) 索引側：1本のパックを順に読む
    $fh = fopen($PACK, 'rb');
    foreach ($man['entries'] as $path => $e) {
        if (count($hits) >= $MAX_FILES) { $truncated = true; break; }
        if (isset($changedSet[$path])) continue;   // 変わったものは後段でその場読みする
        fseek($fh, $e['off']);
        $body = ($e['len'] > 0) ? fread($fh, $e['len']) : '';
        $probe($body, $path);
    }
    fclose($fh);

    // 2) 変わったばかりのファイルはその場で読む（少数なので許容）
    foreach ($changed as $path) {
        if (count($hits) >= $MAX_FILES) { $truncated = true; break; }
        $body = @file_get_contents($path);
        if ($body !== false) $probe($body, $path);
    }

    usort($hits, function ($a, $b) { return strcmp($a['path'], $b['path']); });
    echo json_encode(['q' => $q, 'hits' => $hits, 'indexed' => count($man['entries']),
        'live' => count($changed), 'truncated' => $truncated], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['error' => 'op は build か search']);
