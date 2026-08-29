<?php
// PlantUMLのその場描画（全体ビューア all.html 用）
// GET p=.pumlファイルの絶対パス → SVGを返す。ローカルの plantuml.jar で描画し、外部には送らない。
// 結果はPHP一時フォルダにキャッシュ（ファイルの更新時刻が変わるまで再利用）。
// jar か Java が無い環境では 501 を返し、ビューア側は従来表示（_svg→ソース）に切り替わる。

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) { http_response_code(500); echo 'config not found'; exit; }
$config = require $configFile;

$JAR = __DIR__ . '/bin/plantuml.jar';
$JAVA = isset($config['javaPath']) ? $config['javaPath'] : 'java';
if (!is_file($JAR)) { http_response_code(501); echo 'plantuml.jar not installed'; exit; }

// 許可フォルダ検査（serve.php と同方針）
$root = $config['root'];
$rootReal = realpath($root);
if ($rootReal === false) { http_response_code(500); echo 'root not found'; exit; }
$allowedRoots = [strtolower(str_replace('\\', '/', $rootReal))];
$rootsFile = __DIR__ . '/roots.php';
if (is_file($rootsFile)) {
    $extra = include $rootsFile;
    if (is_array($extra)) {
        foreach ($extra as $er) {
            if (!isset($er['path'])) continue;
            $r = realpath($er['path']);
            if ($r !== false) $allowedRoots[] = strtolower(str_replace('\\', '/', $r));
        }
    }
}

$p = isset($_GET['p']) ? $_GET['p'] : '';
if ($p === '' || strtolower(substr($p, -5)) !== '.puml') { http_response_code(400); echo 'need .puml path'; exit; }
$real = realpath($p);
if ($real === false || !is_file($real)) { http_response_code(404); echo 'not found'; exit; }
$realN = strtolower(str_replace('\\', '/', $real));
$ok = false;
foreach ($allowedRoots as $ar) {
    if (strpos($realN, $ar . '/') === 0) { $ok = true; break; }
}
if (!$ok) { http_response_code(403); echo 'forbidden'; exit; }

// キャッシュ（パス＋更新時刻）
$cacheDir = sys_get_temp_dir() . '/hhscope_puml';
if (!is_dir($cacheDir)) @mkdir($cacheDir);
$key = md5($realN . '|' . filemtime($real));
$cacheFile = $cacheDir . '/' . $key . '.svg';
if (is_file($cacheFile)) {
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('X-Puml: cache');
    readfile($cacheFile);
    exit;
}

// 描画（標準入力から渡し、標準出力のSVGを受ける。ファイルは書かせない）
$cmd = escapeshellarg($JAVA) . ' -Djava.awt.headless=true -jar ' . escapeshellarg($JAR)
     . ' -tsvg -charset UTF-8 -pipe';
$desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open($cmd, $desc, $pipes);
if (!is_resource($proc)) { http_response_code(500); echo 'java start failed'; exit; }
fwrite($pipes[0], file_get_contents($real));
fclose($pipes[0]);
$svg = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
proc_close($proc);

if ($svg === false || strpos($svg, '<svg') === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "render failed\n" . substr((string)$err, 0, 500);
    exit;
}
@file_put_contents($cacheFile, $svg);
header('Content-Type: image/svg+xml; charset=utf-8');
header('X-Puml: fresh');
echo $svg;
