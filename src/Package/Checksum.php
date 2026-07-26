<?php

namespace NexaPressImportExport\Package;

use NexaPressImportExport\Support\Zip;
use RuntimeException;

class Checksum
{
    /*
     * 指定ファイルのチェックサム一覧を作成
     */
    public static function createMap(
        string $baseDirectory,
        array $relativePaths
    ): array {
        $basePath = realpath(
            $baseDirectory
        );

        if ($basePath === false) {
            throw new RuntimeException(
                'チェックサム対象フォルダが見つかりません。'
            );
        }

        $checksums = [];

        foreach ($relativePaths as $relativePath) {
            $relativePath =
                Zip::normalizePath(
                    (string)$relativePath
                );

            $filePath =
                $basePath
                . DIRECTORY_SEPARATOR
                . str_replace(
                    '/',
                    DIRECTORY_SEPARATOR,
                    $relativePath
                );

            $realFilePath = realpath(
                $filePath
            );

            if (
                $realFilePath === false ||
                !is_file($realFilePath) ||
                !str_starts_with(
                    $realFilePath,
                    $basePath
                    . DIRECTORY_SEPARATOR
                )
            ) {
                throw new RuntimeException(
                    'チェックサム対象ファイルが見つかりません。'
                );
            }

            $checksum = hash_file(
                'sha256',
                $realFilePath
            );

            if ($checksum === false) {
                throw new RuntimeException(
                    'チェックサムを作成できませんでした。'
                );
            }

            $checksums[$relativePath] =
                $checksum;
        }

        ksort($checksums);

        return $checksums;
    }

    /*
     * チェックサム一覧を検証
     */
    public static function verifyMap(
        string $baseDirectory,
        array $checksums
    ): void {
        $basePath = realpath(
            $baseDirectory
        );

        if ($basePath === false) {
            throw new RuntimeException(
                'チェックサム確認先が見つかりません。'
            );
        }

        foreach (
            $checksums as
            $relativePath => $expectedChecksum
        ) {
            $relativePath =
                Zip::normalizePath(
                    (string)$relativePath
                );

            $expectedChecksum = strtolower(
                trim(
                    (string)$expectedChecksum
                )
            );

            if (
                !preg_match(
                    '/^[a-f0-9]{64}$/',
                    $expectedChecksum
                )
            ) {
                throw new RuntimeException(
                    'チェックサムの形式が正しくありません。'
                );
            }

            $filePath =
                $basePath
                . DIRECTORY_SEPARATOR
                . str_replace(
                    '/',
                    DIRECTORY_SEPARATOR,
                    $relativePath
                );

            $realFilePath = realpath(
                $filePath
            );

            if (
                $realFilePath === false ||
                !is_file($realFilePath) ||
                !str_starts_with(
                    $realFilePath,
                    $basePath
                    . DIRECTORY_SEPARATOR
                )
            ) {
                throw new RuntimeException(
                    '検証対象ファイルが見つかりません。'
                );
            }

            $actualChecksum = hash_file(
                'sha256',
                $realFilePath
            );

            if (
                $actualChecksum === false ||
                !hash_equals(
                    $expectedChecksum,
                    strtolower(
                        $actualChecksum
                    )
                )
            ) {
                throw new RuntimeException(
                    'インポートファイルが破損または変更されています。'
                );
            }
        }
    }
}