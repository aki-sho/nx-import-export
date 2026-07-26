<?php

namespace NexaPressImportExport\Package;

use NexaPressImportExport\Support\Json;
use NexaPressImportExport\Support\TempDirectory;
use NexaPressImportExport\Support\Zip;
use NexaPressImportExport\Validation\PackageValidator;
use RuntimeException;
use Throwable;

class PackageReader
{
    /*
     * インポートZIPを展開して読み込む
     */
    public function read(
        string $zipPath,
        ?string $requestedMode = null
    ): array {
        $limits = $this->limits();

        $temporaryDirectory =
            TempDirectory::create();

        try {
            $maxEntries = (int)(
                $limits['max_archive_entries']
                ?? 5000
            );

            $maxPackageSize = (int)(
                $limits['max_package_size']
                ?? 104857600
            );

            /*
             * 圧縮後100MBの場合、
             * 展開後は最大500MBまで許可
             */
            $maxExtractedSize =
                max(
                    $maxPackageSize * 5,
                    524288000
                );

            $entries = Zip::extractSafe(
                $zipPath,
                $temporaryDirectory,
                $maxEntries,
                $maxExtractedSize
            );

            $maxJsonSize = (int)(
                $limits['max_json_size']
                ?? 20971520
            );

            $manifestFile =
                $temporaryDirectory
                . '/export-manifest.json';

            $checksumsFile =
                $temporaryDirectory
                . '/checksums.json';

            if (
                !is_file($manifestFile) ||
                !is_file($checksumsFile)
            ) {
                throw new RuntimeException(
                    'インポートZIPの管理情報が不足しています。'
                );
            }

            $manifest = Json::read(
                $manifestFile,
                $maxJsonSize
            );

            $checksumDocument =
                Json::read(
                    $checksumsFile,
                    $maxJsonSize
                );

            /*
             * ZIP構成と処理モードを検証
             */
            $checksumMap =
                PackageValidator::validate(
                    $manifest,
                    $checksumDocument,
                    $entries,
                    $requestedMode
                );

            /*
             * 各ファイルが変更されていないか確認
             */
            Checksum::verifyMap(
                $temporaryDirectory,
                $checksumMap
            );

            return [
                'directory' =>
                    $temporaryDirectory,

                'manifest' => $manifest,
                'checksums' => $checksumMap,
                'entries' => $entries,
            ];
        } catch (Throwable $exception) {
            TempDirectory::remove(
                $temporaryDirectory
            );

            throw $exception;
        }
    }

    /*
     * 読み込み後の一時フォルダを削除
     */
    public function release(
        array $package
    ): void {
        $directory = (string)(
            $package['directory']
            ?? ''
        );

        if ($directory !== '') {
            TempDirectory::remove(
                $directory
            );
        }
    }

    /*
     * 制限値を取得
     */
    private function limits(): array
    {
        if (
            !defined(
                'NX_IMPORT_EXPORT_PATH'
            )
        ) {
            return [];
        }

        $limitsFile =
            NX_IMPORT_EXPORT_PATH
            . '/config/limits.php';

        if (!is_file($limitsFile)) {
            return [];
        }

        $limits = require $limitsFile;

        return is_array($limits)
            ? $limits
            : [];
    }
}