<?php
// ファイル保存スクリプト（全体ビューア all.html の編集機能用）
// POST: p=フルパス, body=新しい内容
// 安全: メインの作業フォルダ（api/config.php の root）配下のみ書き込み許可。テキスト系拡張子のみ。
header('Content-Type: text/plain; charset=utf-8');

$configFile = __DIR__ . '/api/config.php';
if (!is_file($configFile)) { http_response_code(500); echo 'NG: config not found (copy api/config.example.php to api/config.php)'; exit; }
$config = require $configFile;
// realpath は日本語パスで false を返すことがあるため、リテラル文字列で字句判定する（serve.php と同方針）
$rootN = strtolower(str_replace('\\', '/', $config['root']));

$p = isset($_POST['p']) ? $_POST['p'] : '';
if ($p === '') { http_response_code(400); echo 'NG: no path'; exit; }

// 要求パスがルート配下か（字句チェック。リンク先=00_ハーネス等も p がルート配下表記なら許可）
$pN = rtrim(strtolower(str_replace('\\', '/', $p)), '/');
if (strpos($pN, $rootN . '/') !== 0) { http_response_code(403); echo 'NG: outside root'; exit; }

// テキスト系のみ書き込み許可
$ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
$ok = ['md','txt','json','py','php','js','css','html','htm','xml','csv','yml','yaml','sql','sh','bat','puml','ini','env','gitignore'];
if (!in_array($ext, $ok, true)) { http_response_code(415); echo 'NG: not editable type (.' . $ext . ')'; exit; }

// 既存ファイルのみ（新規作成はしない＝誤爆防止）
if (!is_file($p)) { http_response_code(404); echo 'NG: file not found'; exit; }

$body = isset($_POST['body']) ? $_POST['body'] : '';
// 改行をそのまま保存（PHPは受け取った文字列をそのまま書く）
$bytes = file_put_contents($p, $body);
if ($bytes === false) { http_response_code(500); echo 'NG: write failed'; exit; }

echo 'OK: ' . $bytes . ' bytes saved';
