<?php

namespace NexaPressImportExport\Admin;

use app\Core\Csrf;
use app\Core\Permission;
use NexaPressImportExport\Export\ExportService;
use NexaPressImportExport\Support\TempDirectory;
use NexaPressImportExport\Validation\ExportValidator;
use RuntimeException;
use Throwable;

class ExportController
{
    /*
     * エクスポートを実行
     */
    public function export(): void
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

        $temporaryDirectory = null;

        try {
            /*
             * 選択された処理モードを確認
             */
            $mode = ExportValidator::mode(
                $_POST['mode'] ?? ''
            );

            /*
             * エクスポートZIPを作成
             */
            $service = new ExportService();

            $package = $service->create(
                $mode
            );

            $packagePath = (string)(
                $package['path'] ?? ''
            );

            $downloadName = basename(
                (string)(
                    $package['name'] ?? ''
                )
            );

            if (
                $packagePath === '' ||
                !is_file($packagePath)
            ) {
                throw new RuntimeException(
                    'エクスポートファイルを作成できませんでした。'
                );
            }

            if (
                $downloadName === '' ||
                !preg_match(
                    '/^[A-Za-z0-9._-]+$/',
                    $downloadName
                )
            ) {
                throw new RuntimeException(
                    'ダウンロードファイル名が正しくありません。'
                );
            }

            $temporaryDirectory =
                dirname($packagePath);

            $fileSize = filesize(
                $packagePath
            );

            if ($fileSize === false) {
                throw new RuntimeException(
                    'エクスポートファイルを読み込めませんでした。'
                );
            }

            $handle = fopen(
                $packagePath,
                'rb'
            );

            if ($handle === false) {
                throw new RuntimeException(
                    'エクスポートファイルを開けませんでした。'
                );
            }

            /*
             * 既存の出力バッファを削除
             */
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            /*
             * ZIPファイルとしてダウンロード
             */
            header(
                'Content-Type: application/zip'
            );

            header(
                'Content-Disposition: attachment; filename="'
                . $downloadName
                . '"'
            );

            header(
                'Content-Length: '
                . $fileSize
            );

            header(
                'X-Content-Type-Options: nosniff'
            );

            fpassthru($handle);
            fclose($handle);

            /*
             * 一時ファイルを削除
             */
            TempDirectory::remove(
                $temporaryDirectory
            );

            exit;
        } catch (Throwable $exception) {
            if (
                $temporaryDirectory !== null
            ) {
                TempDirectory::remove(
                    $temporaryDirectory
                );
            }

            Notice::error(
                $exception->getMessage()
            );

            $this->redirect();
        }
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