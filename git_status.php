<?php
// gitステータス一覧（全体ビューア all.html のツリー色つけ用・読み取り専用）
// 許可フォルダ内の全gitリポジトリを見つけ、未コミットの変更ファイル一覧をJSONで返す。
// 実行するのは git status --porcelain のみ。書き込みは一切しない。パラメータも受け取らない。

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// リポジトリ80個超のスキャンは重い(実測27秒)ため、結果を90秒キャッシュする。
// スキャン中の多重実行は、ロックのmtimeで検知して旧キャッシュ(無ければ空)を返す。
$cacheFile = sys_get_temp_dir() . '/mydocs_gitstatus_cache.json';
$lockFile  = sys_get_temp_dir() . '/mydocs_gitstatus_lock';
if (is_file($cacheFile) && (time() - @filemtime($cacheFile) < 90)) {
    header('X-Cache: HIT');
    readfile($cacheFile);
    exit;
}
if (is_file($lockFile) && (time() - @filemtime($lockFile) < 60)) {
    header('X-Cache: BUSY');
    if (is_file($cacheFile)) { readfile($cacheFile); } else { echo json_encode(['repos' => 0, 'files' => new stdClass()]); }
    exit;
}
@touch($lockFile);

$configFile = __DIR__ . '/api/config.php';
if (!is_file($configFile)) { echo json_encode(['error' => 'config not found']); exit; }
$config = require $configFile;
$exclude = isset($config['excludeFolders']) ? $config['excludeFolders'] : [];

$bases = [str_replace('\\', '/', $config['root'])];
$rootsFile = __DIR__ . '/api/roots.php';
if (is_file($rootsFile)) {
    $extra = include $rootsFile;
    if (is_array($extra)) {
        foreach ($extra as $er) {
            if (isset($er['path']) && is_dir($er['path'])) $bases[] = str_replace('\\', '/', $er['path']);
        }
    }
}

// .git を持つフォルダを探す（リポジトリの中へはそれ以上潜らない）
function findRepos($dir, $exclude, $depth) {
    $repos = [];
    if ($depth < 0) return $repos;
    if (is_dir($dir . '/.git')) { $repos[] = $dir; return $repos; }
    $items = @scandir($dir);
    if ($items === false) return $repos;
    foreach ($items as $it) {
        if ($it === '.' || $it === '..') continue;
        if (in_array($it, $exclude, true)) continue;
        $full = $dir . '/' . $it;
        if (is_dir($full)) {
            $repos = array_merge($repos, findRepos($full, $exclude, $depth - 1));
        }
    }
    return $repos;
}

$repoList = [];
foreach ($bases as $b) {
    $repoList = array_merge($repoList, findRepos(rtrim($b, '/'), $exclude, 4));
}

$files = [];
foreach ($repoList as $repo) {
    $out = shell_exec('git -c core.quotepath=false -C ' . escapeshellarg($repo) . ' status --porcelain -uall 2>&1');
    if (!$out || stripos($out, 'fatal') === 0) continue;
    foreach (explode("\n", $out) as $line) {
        if (strlen($line) < 4) continue;
        $xy = substr($line, 0, 2);
        $path = trim(substr($line, 3));
        $arrow = strpos($path, ' -> ');           // リネームは新パスを使う
        if ($arrow !== false) $path = substr($path, $arrow + 4);
        $path = trim($path, '"');
        $abs = str_replace('/', '\\', $repo . '/' . $path);
        // 新規（未追跡・追加）= new、それ以外の変更 = mod
        $files[$abs] = (strpos($xy, '?') !== false || strpos($xy, 'A') !== false) ? 'new' : 'mod';
    }
}

$json = json_encode(['repos' => count($repoList), 'files' => $files], JSON_UNESCAPED_UNICODE);
@file_put_contents($cacheFile, $json);
header('X-Cache: MISS');
echo $json;
