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
     * 一時ファイルの保存時間：1時間
     */
    'temporary_file_lifetime' => 3600,
];