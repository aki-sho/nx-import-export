<?php

namespace NexaPressImportExport\Support;

use RuntimeException;

class TempDirectory
{
    /*
     * 一時フォルダを作成
     */
    public static function create(): string
    {
        $baseDirectory =
            self::baseDirectory();

        if (
            !is_dir($baseDirectory) &&
            !mkdir(
                $baseDirectory,
                0755,
                true
            ) &&
            !is_dir($baseDirectory)
        ) {
            throw new RuntimeException(
                '一時保存フォルダを作成できませんでした。'
            );
        }

        for (
            $attempt = 0;
            $attempt < 10;
            $attempt++
        ) {
            $directory =
                $baseDirectory
                . DIRECTORY_SEPARATOR
                . 'nx-import-export-'
                . bin2hex(
                    random_bytes(8)
                );

            if (
                mkdir(
                    $directory,
                    0755
                )
            ) {
                return $directory;
            }
        }

        throw new RuntimeException(
            '作業用フォルダを作成できませんでした。'
        );
    }

    /*
     * 一時フォルダを削除
     */
    public static function remove(
        string $directory
    ): void {
        if (
            $directory === '' ||
            !file_exists($directory)
        ) {
            return;
        }

        $basePath = realpath(
            self::baseDirectory()
        );

        $targetPath = realpath(
            $directory
        );

        if (
            $basePath === false ||
            $targetPath === false ||
            $targetPath === $basePath ||
            !str_starts_with(
                $targetPath,
                $basePath
                . DIRECTORY_SEPARATOR
            )
        ) {
            return;
        }

        self::removeItems(
            $targetPath
        );
    }

    /*
     * 保存期限を過ぎた一時フォルダを削除
     */
    public static function cleanup(
        int $lifetime
    ): int {
        $baseDirectory =
            self::baseDirectory();

        if (!is_dir($baseDirectory)) {
            return 0;
        }

        $items = scandir(
            $baseDirectory
        );

        if ($items === false) {
            return 0;
        }

        $deletedCount = 0;
        $expireTime =
            time() - max(0, $lifetime);

        foreach ($items as $item) {
            if (
                $item === '.' ||
                $item === '..' ||
                !str_starts_with(
                    $item,
                    'nx-import-export-'
                )
            ) {
                continue;
            }

            $path =
                $baseDirectory
                . DIRECTORY_SEPARATOR
                . $item;

            $modifiedTime = filemtime(
                $path
            );

            if (
                $modifiedTime !== false &&
                $modifiedTime < $expireTime
            ) {
                self::remove(
                    $path
                );

                $deletedCount++;
            }
        }

        return $deletedCount;
    }

    /*
     * 一時保存先を取得
     */
    private static function baseDirectory(): string
    {
        if (!defined('BASE_PATH')) {
            throw new RuntimeException(
                'NexaPressの保存先を確認できません。'
            );
        }

        return BASE_PATH
            . '/storage/cache';
    }

    /*
     * フォルダ内を再帰的に削除
     */
    private static function removeItems(
        string $directory
    ): void {
        if (is_link($directory)) {
            unlink($directory);

            return;
        }

        if (!is_dir($directory)) {
            if (is_file($directory)) {
                unlink($directory);
            }

            return;
        }

        $items = scandir(
            $directory
        );

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if (
                $item === '.' ||
                $item === '..'
            ) {
                continue;
            }

            $path =
                $directory
                . DIRECTORY_SEPARATOR
                . $item;

            if (
                is_dir($path) &&
                !is_link($path)
            ) {
                self::removeItems(
                    $path
                );
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}