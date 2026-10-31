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
        int $maxTotalSize = 524288000,
        int $maxFileSize = 52428800,
        int $maxJsonSize = 20971520
    ): array {
        if (min($maxEntries, $maxTotalSize, $maxFileSize, $maxJsonSize) <= 0) {
            throw new RuntimeException('ZIPの容量・件数制限が正しくありません。');
        }

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
            $files = [];
            $seen = [];

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

                $isDirectory = str_ends_with((string)$stat['name'], '/');
                $entryLimit = str_ends_with(strtolower($entryName), '.json')
                    ? $maxJsonSize
                    : $maxFileSize;
                $key = strtolower($entryName);

                if (isset($seen[$key])) {
                    throw new RuntimeException('ZIP内に重複したパスがあります。');
                }

                $seen[$key] = true;

                if (
                    $entrySize < 0 ||
                    $entrySize > $entryLimit ||
                    $entrySize > $maxTotalSize - $totalSize ||
                    ($isDirectory && $entrySize !== 0)
                ) {
                    throw new RuntimeException(
                        'ZIP展開後の容量が制限を超えています。'
                    );
                }

                $totalSize += $entrySize;

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

                $files[] = [
                    'name' => (string)$stat['name'],
                    'path' => $entryName,
                    'size' => $entrySize,
                    'limit' => $entryLimit,
                    'directory' => $isDirectory,
                ];
            }

            /*
             * 全エントリーの検査後に展開し、実際に読み出した量も制限する。
             */
            $actualTotalSize = 0;

            foreach ($files as $file) {
                $entryName = $file['path'];

                /*
                 * フォルダを作成
                 */
                if ($file['directory']) {
                    SafePath::directory($basePath, $entryName);
                    continue;
                }

                $targetPath = SafePath::file($basePath, $entryName);

                $input = $zip->getStream(
                    $file['name']
                );

                if ($input === false) {
                    throw new RuntimeException(
                        'ZIP内のファイルを開けませんでした。'
                    );
                }

                $output = fopen(
                    $targetPath,
                    'xb'
                );

                if ($output === false) {
                    fclose($input);

                    throw new RuntimeException(
                        'ZIP内のファイルを保存できませんでした。'
                    );
                }

                try {
                    $copied = self::copyBounded(
                        $input,
                        $output,
                        min($file['size'], $file['limit'], $maxTotalSize - $actualTotalSize)
                    );

                    if ($copied !== $file['size']) {
                        throw new RuntimeException('ZIP内の申告容量と実際の容量が一致しません。');
                    }
                } catch (\Throwable $exception) {
                    fclose($input);
                    fclose($output);
                    unlink($targetPath);
                    throw $exception;
                }

                fclose($input);
                fclose($output);
                $actualTotalSize += $copied;
                $entries[] = $entryName;
            }

            return $entries;
        } finally {
            $zip->close();
        }
    }

    /*
     * 上限を超えるデータは保存しない。申告容量が偽装されたZIPも拒否する。
     */
    private static function copyBounded($input, $output, int $limit): int
    {
        $copied = 0;

        while (!feof($input)) {
            $chunk = fread($input, min(65536, $limit - $copied + 1));

            if ($chunk === false || ($chunk === '' && !feof($input))) {
                throw new RuntimeException('ZIP内のファイルを読み込めませんでした。');
            }

            $length = strlen($chunk);

            if ($length > $limit - $copied) {
                throw new RuntimeException('ZIP展開後の実測容量が制限を超えています。');
            }

            $offset = 0;

            while ($offset < $length) {
                $written = fwrite($output, substr($chunk, $offset));

                if ($written === false || $written === 0) {
                    throw new RuntimeException('ZIP内のファイルを保存できませんでした。');
                }

                $offset += $written;
            }

            $copied += $length;
        }

        return $copied;
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
                $part === '..' ||
                preg_match('/[<>:"|?*\x00-\x1F\x7F]/', $part) ||
                preg_match('/[. ]$/', $part) ||
                preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $part)
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
