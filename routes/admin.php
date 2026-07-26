<?php

use app\Core\Router;
use NexaPressImportExport\Admin\ExportController;
use NexaPressImportExport\Admin\ImportController;

/*
 * Routerが使用できない場合は終了
 */
if (
    !isset($router) ||
    !$router instanceof Router
) {
    return;
}

/*
 * 拡張機能の管理URL
 */
$adminBase =
    '/admin/extensions/nx-import-export';

/*
 * エクスポート実行
 */
$router->post(
    $adminBase . '/export',
    ExportController::class . '@export'
);

/*
 * インポート実行
 */
$router->post(
    $adminBase . '/import',
    ImportController::class . '@import'
);