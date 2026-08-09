<?php
// ファイル配信スクリプト（全体ビューア all.html 用）
// PHPがディスクから直接読むので、Apache DocumentRoot/Alias の制約を受けない。
// 安全のため、許可フォルダ（api/config.php の root ＋ api/roots.php の一覧）の外は配信しない。
// さらに、シンボリックリンク/ジャンクションの実体が許可フォルダ外を指す場合も拒否する（認証情報の保護）。

$configFile = __DIR__ . '/api/config.php';
if (!is_file($configFile)) { http_response_code(500); echo 'config not found (copy api/config.example.php to api/config.php)'; exit; }
$config = require $configFile;
$root = $config['root'];
$rootReal = realpath($root);
if ($rootReal === false) { http_response_code(500); echo 'root not found'; exit; }
$rootRealN = strtolower(str_replace('\\', '/', $rootReal));

$allowedRoots = [$rootRealN];

// 外部の許可フォルダ（api/roots.php）。ツリーと同じ一覧を読み取り専用で配信する。
// 書き込み(save.php)は同期フォルダ限定のままなので、ここに足しても編集はできない。
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

// 要求パス p が、許可ルート（同期フォルダ or .claude）配下として字句的に始まるか。
$pN = rtrim(strtolower(str_replace('\\', '/', $p)), '/');
$reqOk = false;
foreach ($allowedRoots as $ar) {
    if ($pN === $ar || strpos($pN, $ar . '/') === 0) { $reqOk = true; break; }
}
if (!$reqOk) { http_response_code(403); echo 'forbidden (outside allowed roots)'; exit; }

$real = realpath($p);
if ($real === false) { http_response_code(404); echo 'not found'; exit; }
if (!is_file($real)) { http_response_code(404); echo 'not a file'; exit; }

// ハードニング：実体解決後(realpath)も許可ルート配下であること。
// これで「.claude\..\.ssh\id_rsa」等の .. によるルート外脱出を塞ぐ。
$realN = strtolower(str_replace('\\', '/', $real));
$realOk = false;
foreach ($allowedRoots as $ar) {
    if (strpos($realN, $ar . '/') === 0) { $realOk = true; break; }
}
if (!$realOk) { http_response_code(403); echo 'forbidden (resolved outside allowed roots)'; exit; }

// 秘密ファイル（api/config.php の denyFiles）は URL を直接叩かれても配信しない（ツリー非表示に加えた二重の防御）
$baseN = substr($realN, strrpos($realN, '/') + 1);
$denyFiles = isset($config['denyFiles']) ? $config['denyFiles'] : [];
if (in_array($baseN, $denyFiles, true)) { http_response_code(403); echo 'forbidden (protected file)'; exit; }

$ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
$types = [
    'html' => 'text/html; charset=utf-8',
    'htm'  => 'text/html; charset=utf-8',
    'css'  => 'text/css; charset=utf-8',
    'js'   => 'text/javascript; charset=utf-8',
    'json' => 'application/json; charset=utf-8',
    'svg'  => 'image/svg+xml',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'pdf'  => 'application/pdf',
    // md / txt / py / php(ソースとして) / その他は text/plain で生表示
];
$ct = isset($types[$ext]) ? $types[$ext] : 'text/plain; charset=utf-8';

header('Content-Type: ' . $ct);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($real);
