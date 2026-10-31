<?php

namespace NexaPressImportExport\Support;

use RuntimeException;

class SafePath
{
    /*
     * 信頼できる基点から1階層ずつ確認する。
     * 親ディレクトリのリンクやWindowsのジャンクションも拒否する。
     */
    public static function directory(
        string $baseDirectory,
        string $relativePath = '',
        bool $create = true
    ): string {
        $basePath = realpath($baseDirectory);

        if ($basePath === false || !is_dir($basePath)) {
            throw new RuntimeException('保存先の基点を確認できません。');
        }

        $currentPath = $basePath;
        $parts = $relativePath === ''
            ? []
            : explode('/', Zip::normalizePath($relativePath));

        foreach ($parts as $part) {
            $candidate = $currentPath . DIRECTORY_SEPARATOR . $part;
            clearstatcache(true, $candidate);

            if (is_link($candidate)) {
                throw new RuntimeException('保存先のフォルダにリンクがあります。');
            }

            if (!$create && !file_exists($candidate)) {
                // 未作成の親は検査だけ行い、プレビューからは作成しない。
                if (@lstat($candidate) !== false) {
                    throw new RuntimeException('保存先に解決できないリンクがあります。');
                }
                $currentPath = $candidate;
                continue;
            }

            if (
                !file_exists($candidate) &&
                !mkdir($candidate, 0755) &&
                !is_dir($candidate)
            ) {
                throw new RuntimeException('保存先のフォルダを作成できませんでした。');
            }

            clearstatcache(true, $candidate);
            $resolved = realpath($candidate);

            if (
                is_link($candidate) ||
                !is_dir($candidate) ||
                $resolved === false ||
                !self::same($candidate, $resolved)
            ) {
                throw new RuntimeException('保存先のフォルダが許可された範囲外です。');
            }

            $currentPath = $resolved;
        }

        return $currentPath;
    }

    public static function file(
        string $baseDirectory,
        string $relativePath,
        bool $createParents = true
    ): string {
        $relativePath = Zip::normalizePath($relativePath);
        $parent = dirname($relativePath);
        $directory = self::directory(
            $baseDirectory,
            $parent === '.' ? '' : $parent,
            $createParents
        );
        $target = $directory . DIRECTORY_SEPARATOR . basename($relativePath);
        clearstatcache(true, $target);

        if (
            is_link($target) ||
            (file_exists($target) && (
                !is_file($target) ||
                !self::same($target, (string)realpath($target))
            ))
        ) {
            throw new RuntimeException('保存先のファイルにリンクまたは不正なパスがあります。');
        }

        return $target;
    }

    private static function same(string $left, string $right): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return strcasecmp($left, $right) === 0;
        }

        return $left === $right;
    }
}
