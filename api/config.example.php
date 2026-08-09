<?php
// ローカル環境設定の「見本」。
// 使い方：このファイルを config.php という名前でコピーし、自分の環境に合わせて書き換える。
// config.php は環境ごとのローカル設定なので git 管理外（.gitignore 済み）。

return [
    // メインの作業フォルダ（ツリー表示のルート。編集・保存もここだけ許可される）
    // 区切りは / で書く
    'root' => 'C:/Users/YOURNAME/Documents/workspace',

    // ツリー走査で除外するフォルダ名（機械生成物・巨大フォルダ）
    'excludeFolders' => [
        '.git', 'node_modules', '__pycache__', '.venv', 'venv', 'vendor',
        '.next', 'dist', 'build', '.cache', '.pytest_cache', '.idea',
    ],

    // ツリー走査の深さ上限（深くしすぎるとスキャンが遅くなる）
    'maxDepth' => 6,

    // URLを直接指定されても配信しないファイル名（鍵・認証情報など）
    'denyFiles' => [],
];
