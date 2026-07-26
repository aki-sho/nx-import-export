<?php

namespace NexaPressImportExport\Validation;

use RuntimeException;

class ImportValidator
{
    /*
     * 処理モードを確認
     */
    public static function mode(
        mixed $value
    ): string {
        return ExportValidator::mode(
            $value
        );
    }

    /*
     * アップロードされたZIPを確認
     */
    public static function upload(
        mixed $file,
        array $limits
    ): array {
        if (!is_array($file)) {
            throw new RuntimeException(
                'インポートファイルを選択してください。'
            );
        }

        $error = (int)(
            $file['error']
            ?? UPLOAD_ERR_NO_FILE
        );

        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(
                self::uploadErrorMessage(
                    $error
                )
            );
        }

        $originalName = basename(
            (string)(
                $file['name'] ?? ''
            )
        );

        $temporaryName = (string)(
            $file['tmp_name'] ?? ''
        );

        $fileSize = (int)(
            $file['size'] ?? 0
        );

        if (
            $originalName === '' ||
            $temporaryName === '' ||
            !is_file($temporaryName)
        ) {
            throw new RuntimeException(
                'アップロードファイルを確認できません。'
            );
        }

        /*
         * ファイル拡張子を確認
         */
        $extension = strtolower(
            pathinfo(
                $originalName,
                PATHINFO_EXTENSION
            )
        );

        if ($extension !== 'zip') {
            throw new RuntimeException(
                'ZIP形式のファイルを選択してください。'
            );
        }

        /*
         * ファイル容量を確認
         */
        $maxPackageSize = (int)(
            $limits['max_package_size']
            ?? 104857600
        );

        if (
            $fileSize <= 0 ||
            $fileSize > $maxPackageSize
        ) {
            throw new RuntimeException(
                'インポートファイルの容量が制限を超えています。'
            );
        }

        /*
         * ZIPファイルの先頭データを確認
         */
        $handle = fopen(
            $temporaryName,
            'rb'
        );

        if ($handle === false) {
            throw new RuntimeException(
                'インポートファイルを開けませんでした。'
            );
        }

        $signature = fread(
            $handle,
            4
        );

        fclose($handle);

        $validSignatures = [
            "PK\x03\x04",
            "PK\x05\x06",
            "PK\x07\x08",
        ];

        if (
            !in_array(
                $signature,
                $validSignatures,
                true
            )
        ) {
            throw new RuntimeException(
                '有効なZIPファイルではありません。'
            );
        }

        return [
            'name' => $originalName,
            'tmp_name' => $temporaryName,
            'size' => $fileSize,
        ];
    }

    /*
     * PHPのアップロードエラーを
     * 日本語メッセージへ変換
     */
    private static function uploadErrorMessage(
        int $error
    ): string {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE =>
                'インポートファイルの容量が大きすぎます。',

            UPLOAD_ERR_PARTIAL =>
                'ファイルのアップロードが完了しませんでした。',

            UPLOAD_ERR_NO_FILE =>
                'インポートファイルを選択してください。',

            UPLOAD_ERR_NO_TMP_DIR =>
                '一時保存フォルダが見つかりません。',

            UPLOAD_ERR_CANT_WRITE =>
                'アップロードファイルを保存できませんでした。',

            UPLOAD_ERR_EXTENSION =>
                'PHP拡張機能によってアップロードが停止されました。',

            default =>
                'ファイルのアップロードに失敗しました。',
        };
    }
}