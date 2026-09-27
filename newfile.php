<?php
// ファイル作成スクリプト（全体ビューア all.html の右クリックメニュー用・2026-09-27 追加）
// POST: p=親フォルダの絶対パス, name=作るファイルの名前
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
if ($p === '' || $name === '') { http_response_code(400); echo 'NG: 親フォルダと名前が要ります'; exit; }

// 名前の検査。区切り文字や上位への移動を弾く
$bad = array(chr(92), '/', ':', '*', '?', '"', '<', '>', '|');
foreach ($bad as $ch) {
    if (strpos($name, $ch) !== false) { http_response_code(400); echo 'NG: 名前に使えない文字が入っています'; exit; }
}
if ($name === '.' || $name === '..' || strlen($name) > 200) {
    http_response_code(400); echo 'NG: その名前は使えません'; exit;
}

// 親が作業フォルダ配下か（字句で判定。realpath は日本語パスで false を返すことがあるため save.php と同方針）
$pN = rtrim(strtolower(str_replace(chr(92), '/', $p)), '/');
if ($pN !== $rootN && strpos($pN, $rootN . '/') !== 0) {
    http_response_code(403); echo 'NG: 作業フォルダの外には作れません'; exit;
}
if (!is_dir($p)) { http_response_code(404); echo 'NG: 親フォルダがありません'; exit; }

// 実体でももう一度検査する（リンクや .. で外へ抜ける手口を塞ぐ）
$real = realpath($p);
if ($real !== false) {
    $realN = rtrim(strtolower(str_replace(chr(92), '/', $real)), '/');
    if ($realN !== $rootN && strpos($realN, $rootN . '/') !== 0) {
        http_response_code(403); echo 'NG: 作業フォルダの外には作れません（実体）'; exit;
    }
}

$target = rtrim($p, chr(92) . '/') . DIRECTORY_SEPARATOR . $name;
if (file_exists($target)) { http_response_code(409); echo 'NG: 同じ名前のものが既にあります'; exit; }
if (@file_put_contents($target, '') === false) {
    http_response_code(500); echo 'NG: 作成に失敗しました（権限・名前を確認してください）'; exit;
}

echo 'OK: ファイルを作りました: ' . $name;
