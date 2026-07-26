<?php

namespace NexaPressImportExport\Validation;

use RuntimeException;

class ExportValidator
{
    /*
     * 処理モードを確認
     */
    public static function mode(
        mixed $value
    ): string {
        $mode = trim(
            (string)$value
        );

        if ($mode === '') {
            throw new RuntimeException(
                '処理内容を選択してください。'
            );
        }

        $modes = self::modes();

        if (
            !array_key_exists(
                $mode,
                $modes
            ) ||
            !is_array($modes[$mode])
        ) {
            throw new RuntimeException(
                '選択された処理内容が正しくありません。'
            );
        }

        return $mode;
    }

    /*
     * 使用可能な処理モードを取得
     */
    public static function modes(): array
    {
        if (
            !defined(
                'NX_IMPORT_EXPORT_PATH'
            )
        ) {
            throw new RuntimeException(
                '拡張機能の設定を読み込めません。'
            );
        }

        $modesFile =
            NX_IMPORT_EXPORT_PATH
            . '/config/export-modes.php';

        if (!is_file($modesFile)) {
            throw new RuntimeException(
                '処理モードの設定が見つかりません。'
            );
        }

        $modes = require $modesFile;

        if (
            !is_array($modes) ||
            $modes === []
        ) {
            throw new RuntimeException(
                '処理モードの設定が正しくありません。'
            );
        }

        return $modes;
    }
}