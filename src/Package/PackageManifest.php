<?php

namespace NexaPressImportExport\Package;

use RuntimeException;

class PackageManifest
{
    /*
     * エクスポート形式のバージョン
     */
    public const FORMAT_VERSION = '1.0';

    /*
     * エクスポート情報を作成
     */
    public static function create(
        string $mode,
        array $files
    ): array {
        $files = array_values(
            array_unique(
                array_map(
                    static fn(mixed $file): string =>
                        str_replace(
                            '\\',
                            '/',
                            trim((string)$file)
                        ),
                    $files
                )
            )
        );

        sort($files);

        return [
            'package' => 'nx-import-export',
            'format_version' =>
                self::FORMAT_VERSION,

            'extension_version' =>
                self::extensionVersion(),

            'nexapress_version' =>
                self::nexaPressVersion(),

            'created_at' => gmdate('c'),
            'mode' => $mode,
            'files' => $files,
        ];
    }

    /*
     * 拡張機能のバージョンを取得
     */
    private static function extensionVersion(): string
    {
        if (
            !defined(
                'NX_IMPORT_EXPORT_PATH'
            )
        ) {
            return '1.0.0';
        }

        $manifestFile =
            NX_IMPORT_EXPORT_PATH
            . '/manifest.json';

        if (!is_file($manifestFile)) {
            return '1.0.0';
        }

        $json = file_get_contents(
            $manifestFile
        );

        if ($json === false) {
            return '1.0.0';
        }

        $manifest = json_decode(
            $json,
            true
        );

        if (!is_array($manifest)) {
            return '1.0.0';
        }

        $version = trim(
            (string)(
                $manifest['version']
                ?? ''
            )
        );

        return $version !== ''
            ? $version
            : '1.0.0';
    }

    /*
     * NexaPress本体のバージョンを取得
     */
    private static function nexaPressVersion(): string
    {
        if (!defined('BASE_PATH')) {
            return 'unknown';
        }

        $versionFile =
            BASE_PATH
            . '/config/version.php';

        if (!is_file($versionFile)) {
            return 'unknown';
        }

        $version = require $versionFile;

        if (is_array($version)) {
            $version =
                $version['version']
                ?? '';
        }

        if (
            !is_string($version) &&
            !is_numeric($version)
        ) {
            throw new RuntimeException(
                'NexaPressのバージョン情報が正しくありません。'
            );
        }

        $version = trim(
            (string)$version
        );

        return $version !== ''
            ? $version
            : 'unknown';
    }
}