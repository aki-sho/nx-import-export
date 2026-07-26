<?php

namespace NexaPressImportExport\Admin;

use app\Core\Csrf;
use app\Core\Permission;
use NexaPressImportExport\Import\ImportService;
use NexaPressImportExport\Validation\ImportValidator;
use Throwable;

class ImportController
{
    /*
     * インポートを実行
     */
    public function import(): void
    {
        /*
         * 管理者権限を確認
         */
        Permission::require(
            'extensions.manage'
        );

        /*
         * CSRFトークンを確認
         */
        Csrf::requireValid(
            $_POST['_csrf_token'] ?? null
        );

        try {
            /*
             * 選択された処理モードを確認
             */
            $mode = ImportValidator::mode(
                $_POST['mode'] ?? ''
            );

            /*
             * 制限値を取得
             */
            $limitsFile =
                NX_IMPORT_EXPORT_PATH
                . '/config/limits.php';

            $limits = is_file($limitsFile)
                ? require $limitsFile
                : [];

            if (!is_array($limits)) {
                $limits = [];
            }

            /*
             * アップロードファイルを確認
             */
            $upload = ImportValidator::upload(
                $_FILES['import_file'] ?? null,
                $limits
            );

            /*
             * インポートを実行
             */
            $service = new ImportService();

            $result = $service->import(
                $upload['tmp_name'],
                $mode
            );

            /*
             * 処理件数を合計
             */
            $processedCount = 0;

            foreach ($result as $count) {
                if (is_numeric($count)) {
                    $processedCount +=
                        (int)$count;
                }
            }

            Notice::success(
                'インポートが完了しました。'
                . '処理件数：'
                . $processedCount
                . '件'
            );
        } catch (Throwable $exception) {
            Notice::error(
                $exception->getMessage()
            );
        }

        $this->redirect();
    }

    /*
     * 拡張機能の管理画面へ戻る
     */
    private function redirect(): void
    {
        redirect_to(
            'admin/extensions/'
            . NX_IMPORT_EXPORT_ID
            . '/dashboard'
        );
    }
}