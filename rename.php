<?php
// 名前の変更スクリプト（全体ビューア all.html の右クリックメニュー用・2026-09-27 追加）
// POST: p=いまの絶対パス（ファイルでもフォルダでもよい）, name=新しい名前
//
// 安全の決まり（mkdir.php と同じ考え方）:
//   - メインの作業フォルダ（api/config.php の root）の中だけ。外には触らせない
//   - 既にある名前は断る（上書き・合流をさせない）
//   - 削除は一切しない（このプロジェクトの禁止事項）
header('Content-Type: text/plain; charset=utf-8');

$configFile = __DIR__ . '/api/config.php';
if (!is_file($configFile)) { http_response_code(500); echo 'NG: config not found (copy api/config.example.php to api/config.php)'; exit; }
$config = require $configFile;
$rootN = rtrim(strtolower(str_replace(chr(92), '/', $config['root'])), '/');

$p = isset($_POST['p']) ? $_POST['p'] : '';
$name = isset($_POST['name']) ? trim($_POST['name']) : '';
if ($p === '' || $name === '') { http_response_code(400); echo 'NG: 対象と新しい名前が要ります'; exit; }

// 名前の検査。区切り文字や上位への移動を弾く
$bad = array(chr(92), '/', ':', '*', '?', '"', '<', '>', '|');
foreach ($bad as $ch) {
    if (strpos($name, $ch) !== false) { http_response_code(400); echo 'NG: 名前に使えない文字が入っています'; exit; }
}
if ($name === '.' || $name === '..' || strlen($name) > 200) {
    http_response_code(400); echo 'NG: その名前は使えません'; exit;
}

// 対象が作業フォルダ配下か（字句で判定）
$pN = rtrim(strtolower(str_replace(chr(92), '/', $p)), '/');
if ($pN === $rootN) { http_response_code(403); echo 'NG: 作業フォルダ自体の名前は変えられません'; exit; }
if (strpos($pN, $rootN . '/') !== 0) {
    http_response_code(403); echo 'NG: 作業フォルダの外のものは変えられません'; exit;
}
if (!file_exists($p)) { http_response_code(404); echo 'NG: そのファイル・フォルダがありません'; exit; }

// 実体でももう一度検査する
$real = realpath($p);
if ($real !== false) {
    $realN = rtrim(strtolower(str_replace(chr(92), '/', $real)), '/');
    if (strpos($realN, $rootN . '/') !== 0) {
        http_response_code(403); echo 'NG: 作業フォルダの外のものは変えられません（実体）'; exit;
    }
}

$parent = dirname($p);
$target = rtrim($parent, chr(92) . '/') . DIRECTORY_SEPARATOR . $name;
if (strtolower($target) !== strtolower($p) && file_exists($target)) {
    http_response_code(409); echo 'NG: 同じ名前のものが既にあります'; exit;
}
if (!@rename($p, $target)) {
    http_response_code(500); echo 'NG: 名前を変えられませんでした（開いていないか・権限を確認してください）'; exit;
}

echo 'OK: 名前を変えました: ' . $name;
