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

    // 全文検索の索引に「中身を複製しない」フォルダ（索引は一時フォルダに実体コピーを持つため、
    // 認証情報などを置いているフォルダは必ずここに書く）
    'ftsExcludeFolders' => [],

    // URLを直接指定されても配信しないファイル名（鍵・認証情報など）
    'denyFiles' => [],

    // 任意：ツリーの「まとまり順」ソート。フォルダ→まとまり対応表（Markdownの表）のパスを書くと、
    // 画面に並び替えボタンが出る。書かなければ従来どおり名前順のみ。
    // 'mapFile' => 'C:/path/to/map.md',
    // 'mapGroupOrder' => [1, 2, 3],   // まとまりの表示順（省略時は番号順）
];
