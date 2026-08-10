<?php
// git差分スクリプト（全体ビューア all.html 用・読み取り専用）
// GET p=フォルダかファイルの絶対パス → そのパスが属するgitリポジトリの未コミット差分をテキストで返す。
// 実行するのは読み取り系gitコマンドのみ（rev-parse / status / diff）。書き込みは一切しない。
// serve.php と同じ許可フォルダ制限（api/config.php の root ＋ api/roots.php）。

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$configFile = __DIR__ . '/api/config.php';
if (!is_file($configFile)) { http_response_code(500); echo 'config not found (copy api/config.example.php to api/config.php)'; exit; }
$config = require $configFile;
$root = $config['root'];
$rootReal = realpath($root);
if ($rootReal === false) { http_response_code(500); echo 'root not found'; exit; }

$allowedRoots = [strtolower(str_replace('\\', '/', $rootReal))];
$rootsFile = __DIR__ . '/api/roots.php';
if (is_file($rootsFile)) {
    $extraRoots = include $rootsFile;
    if (is_array($extraRoots)) {
        foreach ($extraRoots as $er) {
            if (!isset($er['path'])) continue;
            $erReal = realpath($er['path']);
            if ($erReal !== false) { $allowedRoots[] = strtolower(str_replace('\\', '/', $erReal)); }
        }
    }
}

$p = isset($_GET['p']) ? $_GET['p'] : '';
if ($p === '') { http_response_code(400); echo 'no path'; exit; }

// ファイルが指定されたら親フォルダを対象にする
if (is_file($p)) { $p = dirname($p); }
if (!is_dir($p)) { http_response_code(404); echo 'not found'; exit; }

// 許可フォルダ配下か（字句＋実体の二段検査。serve.php と同方針）
$pN = rtrim(strtolower(str_replace('\\', '/', $p)), '/');
$reqOk = false;
foreach ($allowedRoots as $ar) {
    if ($pN === $ar || strpos($pN, $ar . '/') === 0) { $reqOk = true; break; }
}
$real = realpath($p);
$realN = $real === false ? '' : strtolower(str_replace('\\', '/', $real));
$realOk = false;
foreach ($allowedRoots as $ar) {
    if ($realN === $ar || strpos($realN, $ar . '/') === 0) { $realOk = true; break; }
}
if (!$reqOk || !$realOk) { http_response_code(403); echo 'forbidden (outside allowed roots)'; exit; }

// リポジトリ判定（読み取りのみ）
function run_git($args) {
    return shell_exec('git -c core.quotepath=false ' . $args . ' 2>&1');
}
$dirArg = escapeshellarg($real);
$top = trim((string)run_git("-C $dirArg rev-parse --show-toplevel"));
if ($top === '' || stripos($top, 'fatal') !== false || stripos($top, 'not a git') !== false) {
    http_response_code(404); echo 'not a git repository: ' . $p; exit;
}

// リポジトリの最上位も許可フォルダ配下であること（許可外へ辿らせない）
$topN = strtolower(str_replace('\\', '/', $top));
$topOk = false;
foreach ($allowedRoots as $ar) {
    if ($topN === $ar || strpos($topN, $ar . '/') === 0) { $topOk = true; break; }
}
if (!$topOk) { http_response_code(403); echo 'forbidden (repo root outside allowed roots)'; exit; }

$topArg = escapeshellarg($top);
$status = (string)run_git("-C $topArg status --short");
$unstaged = (string)run_git("-C $topArg diff");
$staged = (string)run_git("-C $topArg diff --cached");

$out  = "# リポジトリ: $top\n";
$out .= "# 取得時刻: " . date('Y-m-d H:i:s') . "\n";
$out .= "#\n# ==== 変更ファイル一覧 (git status --short) ====\n";
$out .= ($status === '' ? "# （変更なし・作業ツリーはクリーン）\n" : $status);
$out .= "\n# ==== 未ステージの差分 (git diff) ====\n";
$out .= ($unstaged === '' ? "# （なし）\n" : $unstaged);
$out .= "\n# ==== ステージ済みの差分 (git diff --cached) ====\n";
$out .= ($staged === '' ? "# （なし）\n" : $staged);
echo $out;
