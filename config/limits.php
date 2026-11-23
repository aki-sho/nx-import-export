<?php

/*
 * インポート・エクスポートの制限値
 */
return [
    /*
     * ZIPパッケージ全体の最大容量：100MB
     */
    'max_package_size' =>
        100 * 1024 * 1024,

    /*
     * ZIP展開後の実測合計容量：500MB
     */
    'max_extracted_size' =>
        500 * 1024 * 1024,

    /*
     * JSONファイル1個の最大容量：20MB
     */
    'max_json_size' =>
        20 * 1024 * 1024,

    /*
     * メディアファイル1個の最大容量：50MB
     */
    'max_media_file_size' =>
        50 * 1024 * 1024,

    /*
     * ZIP内の最大ファイル数
     */
    'max_archive_entries' => 5000,

    /*
     * メディアファイルの最大件数
     */
    'max_media_files' => 2000,

    /*
     * 投稿・固定ページ・カテゴリーの合計件数
     */
    'max_content_items' => 10000,

    'max_csv_size' => 20 * 1024 * 1024,
    'max_csv_rows' => 1000,

    /*
     * プレビュー保存時間・ユーザーごとの保存件数
     */
    'preview_lifetime' => 3600,
    'max_pending_previews' => 3,

    /*
     * 自動バックアップ：1件500MB、合計1GB、直近5件
     */
    'max_backup_size' => 500 * 1024 * 1024,
    'max_backup_storage_size' => 1024 * 1024 * 1024,
    'max_backups' => 5,

    /*
     * 一時ファイルの保存時間：1時間
     */
    'temporary_file_lifetime' => 3600,
];
