<?php

namespace NexaPressImportExport\Validation;

use NexaPressImportExport\Package\PackageManifest;
use NexaPressImportExport\Support\Zip;
use RuntimeException;

class PackageValidator
{
    /*
     * インポートパッケージ全体を検証
     */
    public static function validate(
        array $manifest,
        array $checksumDocument,
        array $entries,
        ?string $requestedMode = null
    ): array {
        self::validateManifest(
            $manifest
        );

        $mode = (string)$manifest['mode'];

        self::validateRequestedMode(
            $mode,
            $requestedMode
        );

        $manifestFiles =
            self::normalizeManifestFiles(
                $manifest['files']
            );

        self::validateModeFiles(
            $mode,
            $manifestFiles
        );

        $checksums =
            self::normalizeChecksums(
                $checksumDocument
            );

        self::validateChecksumTargets(
            $manifestFiles,
            $checksums
        );

        self::validateArchiveEntries(
            $entries,
            $checksums
        );

        return $checksums;
    }

    /*
     * マニフェストの基本情報を検証
     */
    private static function validateManifest(
        array $manifest
    ): void {
        if (
            ($manifest['package'] ?? '')
            !== 'nx-import-export'
        ) {
            throw new RuntimeException(
                'NX Import Exportのファイルではありません。'
            );
        }

        if (
            ($manifest['format_version'] ?? '')
            !== PackageManifest::FORMAT_VERSION
        ) {
            throw new RuntimeException(
                '対応していないエクスポート形式です。'
            );
        }

        $mode = trim(
            (string)(
                $manifest['mode']
                ?? ''
            )
        );

        if (
            !in_array(
                $mode,
                [
                    'content',
                    'content_media',
                    'settings',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'エクスポート内容が正しくありません。'
            );
        }

        if (
            !isset($manifest['files']) ||
            !is_array($manifest['files'])
        ) {
            throw new RuntimeException(
                'エクスポートファイル一覧がありません。'
            );
        }
    }

    /*
     * 選択したインポート内容と
     * ZIPの内容が一致するか検証
     */
    private static function validateRequestedMode(
        string $packageMode,
        ?string $requestedMode
    ): void {
        if ($requestedMode === null) {
            return;
        }

        $requestedMode = trim(
            $requestedMode
        );

        $allowedModes = [
            'content' => [
                'content',
            ],

            'content_media' => [
                'content',
                'content_media',
            ],

            'settings' => [
                'settings',
            ],
        ];

        if (
            !isset(
                $allowedModes[$packageMode]
            ) ||
            !in_array(
                $requestedMode,
                $allowedModes[$packageMode],
                true
            )
        ) {
            throw new RuntimeException(
                '選択したインポート内容と'
                . 'ZIPファイルの内容が一致しません。'
            );
        }
    }

    /*
     * マニフェスト内のファイル一覧を正規化
     */
    private static function normalizeManifestFiles(
        array $files
    ): array {
        $normalizedFiles = [];

        foreach ($files as $file) {
            $path = Zip::normalizePath(
                (string)$file
            );

            if (
                $path ===
                    'export-manifest.json' ||
                $path ===
                    'checksums.json'
            ) {
                throw new RuntimeException(
                    'エクスポートファイル一覧が正しくありません。'
                );
            }

            if (
                in_array(
                    $path,
                    $normalizedFiles,
                    true
                )
            ) {
                throw new RuntimeException(
                    'エクスポートファイル一覧に重複があります。'
                );
            }

            $normalizedFiles[] = $path;
        }

        sort($normalizedFiles);

        return $normalizedFiles;
    }

    /*
     * 処理モードごとの必須ファイルを検証
     */
    private static function validateModeFiles(
        string $mode,
        array $files
    ): void {
        $contentFiles = [
            'data/categories.json',
            'data/pages.json',
            'data/posts.json',
        ];

        if ($mode === 'content') {
            $expected = $contentFiles;

            sort($expected);

            if ($files !== $expected) {
                throw new RuntimeException(
                    'コンテンツパッケージの構成が正しくありません。'
                );
            }

            return;
        }

        if ($mode === 'settings') {
            if (
                $files !== [
                    'data/settings.json',
                ]
            ) {
                throw new RuntimeException(
                    '設定パッケージの構成が正しくありません。'
                );
            }

            return;
        }

        $requiredFiles = array_merge(
            $contentFiles,
            [
                'data/media.json',
            ]
        );

        foreach ($requiredFiles as $requiredFile) {
            if (
                !in_array(
                    $requiredFile,
                    $files,
                    true
                )
            ) {
                throw new RuntimeException(
                    'コンテンツ＋メディアパッケージに'
                    . '必要なファイルが不足しています。'
                );
            }
        }

        foreach ($files as $file) {
            if (
                in_array(
                    $file,
                    $requiredFiles,
                    true
                )
            ) {
                continue;
            }

            if (
                !str_starts_with(
                    $file,
                    'media/uploads/'
                )
            ) {
                throw new RuntimeException(
                    'メディアパッケージに'
                    . '使用できないファイルが含まれています。'
                );
            }
        }
    }

    /*
     * チェックサム一覧を正規化
     */
    private static function normalizeChecksums(
        array $checksumDocument
    ): array {
        if (
            ($checksumDocument['algorithm'] ?? '')
            !== 'sha256'
        ) {
            throw new RuntimeException(
                '対応していないチェックサム形式です。'
            );
        }

        $files =
            $checksumDocument['files']
            ?? null;

        if (
            !is_array($files) ||
            $files === []
        ) {
            throw new RuntimeException(
                'チェックサム一覧がありません。'
            );
        }

        $checksums = [];

        foreach (
            $files as
            $relativePath => $checksum
        ) {
            $relativePath =
                Zip::normalizePath(
                    (string)$relativePath
                );

            $checksum = strtolower(
                trim(
                    (string)$checksum
                )
            );

            if (
                isset(
                    $checksums[$relativePath]
                )
            ) {
                throw new RuntimeException(
                    'チェックサム一覧に重複があります。'
                );
            }

            if (
                !preg_match(
                    '/^[a-f0-9]{64}$/',
                    $checksum
                )
            ) {
                throw new RuntimeException(
                    'チェックサムの形式が正しくありません。'
                );
            }

            $checksums[$relativePath] =
                $checksum;
        }

        ksort($checksums);

        return $checksums;
    }

    /*
     * マニフェストとチェックサムの
     * 対象ファイルが一致するか確認
     */
    private static function validateChecksumTargets(
        array $manifestFiles,
        array $checksums
    ): void {
        $expectedTargets =
            $manifestFiles;

        $expectedTargets[] =
            'export-manifest.json';

        sort($expectedTargets);

        $checksumTargets =
            array_keys($checksums);

        sort($checksumTargets);

        if (
            $expectedTargets
            !== $checksumTargets
        ) {
            throw new RuntimeException(
                'チェックサム対象ファイルが一致しません。'
            );
        }
    }

    /*
     * ZIP内のファイル一覧を確認
     */
    private static function validateArchiveEntries(
        array $entries,
        array $checksums
    ): void {
        $archiveFiles = [];

        foreach ($entries as $entry) {
            $path = Zip::normalizePath(
                (string)$entry
            );

            if (
                in_array(
                    $path,
                    $archiveFiles,
                    true
                )
            ) {
                throw new RuntimeException(
                    'ZIP内に重複したファイルがあります。'
                );
            }

            $archiveFiles[] = $path;
        }

        sort($archiveFiles);

        $expectedFiles =
            array_keys($checksums);

        $expectedFiles[] =
            'checksums.json';

        sort($expectedFiles);

        if (
            $archiveFiles
            !== $expectedFiles
        ) {
            throw new RuntimeException(
                'ZIP内のファイル構成が正しくありません。'
            );
        }
    }
}