<?php
header('Content-Type: application/json; charset=utf-8');
// ディレクトリ一覧は動的なのでHTTPキャッシュを無効化（リネーム後の旧パス残留を防ぐ）
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// ローカル環境設定（作業フォルダのパス・除外リスト等）。無ければ見本のコピーを促す
$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    echo json_encode(['error' => 'api/config.php がありません。api/config.example.php をコピーして作成してください'], JSON_UNESCAPED_UNICODE);
    exit;
}
$config = require $configFile;
$root = $config['root'];

// パラメータ取得
// root=1 → 作業フォルダ全体を表示（all.html 用）
$rootMode = (isset($_GET['root']) && $_GET['root'] === '1');
$excludeFiles = [];

if ($rootMode) {
    // 全体モード：ルートを丸ごとスキャン
    $basePath = $root;
    $excludeFolders = isset($config['excludeFolders']) ? $config['excludeFolders'] : ['.git', 'node_modules'];
    $guardLinks = false; // ルート配下のリンク先も辿る（除外は excludeFolders で制御）
    $maxDepth = isset($config['maxDepth']) ? (int)$config['maxDepth'] : 6;
    $rootReal = strtolower(str_replace('\\', '/', (realpath($root) ?: $root)));
} else {
    // 旧カテゴリモード（index.html 用）は 2026-08-08 に廃止。全体モードのみ対応
    echo json_encode(['error' => 'use root=1'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 全体モード(all.html)はスキャンが重い(約9秒)。5分キャッシュで2回目以降を即時化する。
// ?fresh=1 で強制再生成。カテゴリモード(index.html)はキャッシュしない。
$cacheFile = sys_get_temp_dir() . '/mydocs_alltree_cache.json';
$wantFresh = (isset($_GET['fresh']) && $_GET['fresh'] === '1');
if ($rootMode && !$wantFresh && is_file($cacheFile) && (time() - @filemtime($cacheFile) < 300)) {
    header('X-Cache: HIT');
    readfile($cacheFile);
    exit;
}

function scanDirectory($path, $excludeFolders, $guardLinks, $rootReal, $maxDepth = 0, $depth = 0, $withSize = true, $excludeFiles = []) {
    $result = [];

    if (!is_dir($path)) {
        return $result;
    }
    // 深さ上限（0=無制限）。全体モードのタイムアウト防止。
    if ($maxDepth > 0 && $depth >= $maxDepth) {
        return $result;
    }

    $items = scandir($path);

    $folders = [];
    $files = [];

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        if (in_array($item, $excludeFolders)) continue;

        $fullPath = $path . '/' . $item;

        if (is_dir($fullPath)) {
            // セッションUUIDフォルダ（会話ログの入れ物）はノイズなので隠す
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $item)) continue;
            $folders[] = $item;
        } else {
            // 会話ログ(.jsonl)は巨大＆ノイズなので隠す（memoryの.md等を見やすく）
            if (substr($item, -6) === '.jsonl') continue;
            // 指定の秘密ファイル等は隠す（.claude カテゴリ用。既定は空で従来通り）
            if (in_array($item, $excludeFiles, true)) continue;
            $files[] = $item;
        }
    }

    foreach ($folders as $item) {
        $fullPath = $path . '/' . $item;

        // 全体モード：リンク（00_ハーネス→.claude 等）は「名前だけ出して中は辿らない」。
        // 中を辿ると .claude の巨大ログでタイムアウトするため。フォルダ一覧には残す＝全部表示。
        $isLink = false;
        if ($guardLinks) {
            $real = realpath($fullPath);
            if ($real !== false) {
                $realN = strtolower(str_replace('\\', '/', $real));
                $lexN  = strtolower(str_replace('\\', '/', $fullPath));
                if ($realN !== $lexN) $isLink = true;                 // リンク
                if (strpos($realN, $rootReal) !== 0) $isLink = true;  // ルート外を指す
            }
        }

        // 価値ある深いフォルダ(memory/projects/skills)だけ、この枝を深く辿る（全体は浅いまま=高速）
        $childMax = $maxDepth;
        if ($maxDepth > 0 && in_array($item, ['projects', 'memory'])) {
            $childMax = $depth + 6;
        }

        $result[] = [
            'name' => $item,
            'path' => str_replace('/', '\\', $fullPath),
            'children' => $isLink ? [] : scanDirectory($fullPath, $excludeFolders, $guardLinks, $rootReal, $childMax, $depth + 1, $withSize, $excludeFiles)
        ];
    }

    foreach ($files as $item) {
        $fullPath = $path . '/' . $item;
        $entry = [
            'name' => $item,
            'path' => str_replace('/', '\\', $fullPath)
        ];
        if ($withSize) {
            $sz = @filesize($fullPath); // リンク切れ等で失敗しても警告を出さない（JSON汚染防止）
            $entry['size'] = ($sz === false ? 0 : $sz);
        }
        $result[] = $entry;
    }

    return $result;
}

$depthCap = isset($maxDepth) ? $maxDepth : 0;
$withSize = $rootMode;  // index（カテゴリ）はサイズ未使用なので付与しない。all.html（全体）は従来どおり付与
$tree = [
    'name' => basename($basePath),
    'path' => str_replace('/', '\\', $basePath),
    'children' => scanDirectory($basePath, $excludeFolders, $guardLinks, $rootReal, $depthCap, 0, $withSize, $excludeFiles)
];

// 外部の許可フォルダ（api/roots.php に列挙）もツリーの枝として足す。
// serve.php も同じ一覧を読んで配信を許可する。書き込み(save.php)は同期フォルダ限定のまま＝外部は読み取り専用。
if ($rootMode) {
    $rootsFile = __DIR__ . '/roots.php';
    if (is_file($rootsFile)) {
        $extraRoots = include $rootsFile;
        if (is_array($extraRoots)) {
            foreach ($extraRoots as $er) {
                if (!isset($er['path']) || !is_dir($er['path'])) continue;
                $tree['children'][] = [
                    'name' => isset($er['name']) ? $er['name'] : basename($er['path']),
                    'path' => str_replace('/', '\\', $er['path']),
                    'children' => scanDirectory(
                        $er['path'],
                        isset($er['exclude']) ? $er['exclude'] : [],
                        false, '',
                        isset($er['maxDepth']) ? (int)$er['maxDepth'] : 4,
                        0, $withSize,
                        isset($er['excludeFiles']) ? $er['excludeFiles'] : []
                    )
                ];
            }
        }
    }
}

$json = json_encode($tree, JSON_UNESCAPED_UNICODE);
if ($rootMode) { @file_put_contents($cacheFile, $json); header('X-Cache: MISS'); }  // 全体モードのみキャッシュ更新
echo $json;
