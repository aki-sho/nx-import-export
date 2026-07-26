<?php

use app\Core\Router;

/*
 * NexaPress以外から直接実行された場合は終了
 */
if (
    !defined('BASE_PATH') ||
    !isset($router) ||
    !$router instanceof Router
) {
    return;
}

/*
 * 拡張機能の基本情報
 */
if (!defined('NX_IMPORT_EXPORT_PATH')) {
    define(
        'NX_IMPORT_EXPORT_PATH',
        __DIR__
    );
}

if (!defined('NX_IMPORT_EXPORT_ID')) {
    define(
        'NX_IMPORT_EXPORT_ID',
        'nx-import-export'
    );
}

/*
 * クラスの自動読み込み
 */
require_once __DIR__ . '/autoload.php';

/*
 * 拡張機能用ルートの読み込み
 */
$routeFiles = glob(
    __DIR__ . '/routes/*.php'
);

if (is_array($routeFiles)) {
    sort($routeFiles);

    foreach ($routeFiles as $routeFile) {
        require $routeFile;
    }
}