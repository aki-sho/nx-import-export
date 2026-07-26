<?php

namespace NexaPressImportExport\Support;

use RuntimeException;
use ZipArchive;

class Zip
{
    /*
     * ZipArchiveが使用できるか確認
     */
    public static function requireAvailable(): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException(
                'PHPのZipArchive拡張機能が必要です。'
            );
        }
    }

    /*
     * 新しいZIPファイルを作成
     */
    public static function create(
        string $filePath
    ): ZipArchive {
        self::requireAvailable();

        $directory = dirname(
            $filePath
        );

        if (
            !is_dir($directory) &&
            !mkdir(
                $directory,
                0755,
                true
            ) &&
            !is_dir($directory)
        ) {
            throw new RuntimeException(
                'ZIP保存フォルダを作成できませんでした。'
            );
        }

        $zip = new ZipArchive();

        $result = $zip->open(
            $filePath,
            ZipArchive::CREATE
            | ZipArchive::OVERWRITE
        );

        if ($result !== true) {
            throw new RuntimeException(
                'ZIPファイルを作成できませんでした。'
            );
        }

        return $zip;
    }

    /*
     * 既存のZIPファイルを開く
     */
    public static function open(
        string $filePath
    ): ZipArchive {
        self::requireAvailable();

        if (!is_file($filePath)) {
            throw new RuntimeException(
                'ZIPファイルが見つかりません。'
            );
        }

        $zip = new ZipArchive();

        $result = $zip->open(
            $filePath
        );

        if ($result !== true) {
            throw new RuntimeException(
                'ZIPファイルを開けませんでした。'
            );
        }

        return $zip;
    }

    /*
     * 文字列をZIPへ追加
     */
    public static function addString(
        ZipArchive $zip,
        string $archivePath,
        string $contents
    ): void {
        $archivePath = self::normalizePath(
            $archivePath
        );

        if (
            !$zip->addFromString(
                $archivePath,
                $contents
            )
        ) {
            throw new RuntimeException(
                'ZIPへデータを追加できませんでした。'
            );
        }
    }

    /*
     * ファイルをZIPへ追加
     */
    public static function addFile(
        ZipArchive $zip,
        string $sourcePath,
        string $archivePath
    ): void {
        if (!is_file($sourcePath)) {
            throw new RuntimeException(
                'ZIPへ追加するファイルが見つかりません。'
            );
        }

        $archivePath = self::normalizePath(
            $archivePath
        );

        if (
            !$zip->addFile(
                $sourcePath,
                $archivePath
            )
        ) {
            throw new RuntimeException(
                'ZIPへファイルを追加できませんでした。'
            );
        }
    }

    /*
     * ZIPを安全に展開
     */
    public static function extractSafe(
        string $zipPath,
        string $destination,
        int $maxEntries = 5000,
        int $maxTotalSize = 524288000
    ): array {
        $zip = self::open(
            $zipPath
        );

        try {
            if ($zip->numFiles > $maxEntries) {
                throw new RuntimeException(
                    'ZIP内のファイル数が制限を超えています。'
                );
            }

            if (
                !is_dir($destination) &&
                !mkdir(
                    $destination,
                    0755,
                    true
                ) &&
                !is_dir($destination)
            ) {
                throw new RuntimeException(
                    'ZIP展開フォルダを作成できませんでした。'
                );
            }

            $basePath = realpath(
                $destination
            );

            if ($basePath === false) {
                throw new RuntimeException(
                    'ZIP展開フォルダを確認できません。'
                );
            }

            $entries = [];
            $totalSize = 0;

            for (
                $index = 0;
                $index < $zip->numFiles;
                $index++
            ) {
                $stat = $zip->statIndex(
                    $index
                );

                if (!is_array($stat)) {
                    throw new RuntimeException(
                        'ZIP内のファイル情報を取得できません。'
                    );
                }

                $entryName = self::normalizePath(
                    (string)($stat['name'] ?? '')
                );

                $entrySize = (int)(
                    $stat['size'] ?? 0
                );

                $totalSize += $entrySize;

                if ($totalSize > $maxTotalSize) {
                    throw new RuntimeException(
                        'ZIP展開後の容量が制限を超えています。'
                    );
                }

                /*
                 * シンボリックリンクを拒否
                 */
                $operatingSystem = 0;
                $attributes = 0;

                if (
                    $zip->getExternalAttributesIndex(
                        $index,
                        $operatingSystem,
                        $attributes
                    )
                ) {
                    $fileType =
                        ($attributes >> 16)
                        & 0170000;

                    if ($fileType === 0120000) {
                        throw new RuntimeException(
                            'ZIP内に使用できないリンクが含まれています。'
                        );
                    }
                }

                $targetPath =
                    $basePath
                    . DIRECTORY_SEPARATOR
                    . str_replace(
                        '/',
                        DIRECTORY_SEPARATOR,
                        $entryName
                    );

                /*
                 * フォルダを作成
                 */
                if (
                    str_ends_with(
                        (string)($stat['name'] ?? ''),
                        '/'
                    )
                ) {
                    if (
                        !is_dir($targetPath) &&
                        !mkdir(
                            $targetPath,
                            0755,
                            true
                        ) &&
                        !is_dir($targetPath)
                    ) {
                        throw new RuntimeException(
                            'ZIP内のフォルダを作成できませんでした。'
                        );
                    }

                    continue;
                }

                $parentDirectory = dirname(
                    $targetPath
                );

                if (
                    !is_dir($parentDirectory) &&
                    !mkdir(
                        $parentDirectory,
                        0755,
                        true
                    ) &&
                    !is_dir($parentDirectory)
                ) {
                    throw new RuntimeException(
                        'ZIP展開先のフォルダを作成できませんでした。'
                    );
                }

                $input = $zip->getStream(
                    (string)($stat['name'] ?? '')
                );

                if ($input === false) {
                    throw new RuntimeException(
                        'ZIP内のファイルを開けませんでした。'
                    );
                }

                $output = fopen(
                    $targetPath,
                    'wb'
                );

                if ($output === false) {
                    fclose($input);

                    throw new RuntimeException(
                        'ZIP内のファイルを保存できませんでした。'
                    );
                }

                $copied = stream_copy_to_stream(
                    $input,
                    $output
                );

                fclose($input);
                fclose($output);

                if ($copied === false) {
                    throw new RuntimeException(
                        'ZIP内のファイルを展開できませんでした。'
                    );
                }

                $entries[] = $entryName;
            }

            return $entries;
        } finally {
            $zip->close();
        }
    }

    /*
     * ZIP内部のパスを検証
     */
    public static function normalizePath(
        string $path
    ): string {
        $path = str_replace(
            '\\',
            '/',
            trim($path)
        );

        if (
            $path === '' ||
            str_contains($path, "\0") ||
            str_starts_with($path, '/') ||
            preg_match('/^[A-Za-z]:\//', $path)
        ) {
            throw new RuntimeException(
                'ZIP内のファイルパスが正しくありません。'
            );
        }

        $parts = explode(
            '/',
            trim($path, '/')
        );

        foreach ($parts as $part) {
            if (
                $part === '' ||
                $part === '.' ||
                $part === '..'
            ) {
                throw new RuntimeException(
                    'ZIP内のファイルパスが正しくありません。'
                );
            }
        }

        return implode(
            '/',
            $parts
        );
    }
}