<?php
// 外部閲覧の許可フォルダ一覧の「見本」。
// 使い方：このファイルを roots.php という名前でコピーし、見たいフォルダを書き足す。
// roots.php は各環境のローカル設定なので git 管理外（.gitignore 済み）。
//
// - 対象は「読み取り専用」。編集・保存（save.php）はメインの作業フォルダ内だけ。
// - name        : ツリーの枝として出す表示名
// - path        : フォルダの絶対パス（区切りは / で書く）
// - maxDepth    : 辿る深さの上限（大きくしすぎるとスキャンが遅くなる）
// - exclude     : 除外するフォルダ名のリスト
// - excludeFiles: ツリーに出さないファイル名のリスト（任意。鍵ファイル等）

return [
    [
        'name' => 'tmp（一時フォルダ）',
        'path' => 'C:/tmp',
        'maxDepth' => 4,
        'exclude' => ['node_modules', '.git', '__pycache__', '.venv', 'venv'],
    ],
    // ['name' => 'Documents', 'path' => 'C:/Users/YOURNAME/Documents', 'maxDepth' => 4,
    //  'exclude' => ['.git', 'node_modules'], 'excludeFiles' => ['secret-key.json']],
];
