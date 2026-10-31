<?php

namespace NexaPressImportExport\Support;

use RuntimeException;

class PrivateStorage
{
    public static function directory(string $area, string $id = '', bool $create = true): string
    {
        if (!in_array($area, ['previews', 'backups'], true) || ($id !== '' && !preg_match('/^[a-f0-9]{32}$/', $id))) {
            throw new RuntimeException('保存データの識別子が正しくありません。');
        }
        $base = SafePath::directory(BASE_PATH, 'storage/nx-import-export', $create);
        if (!$create && !is_dir($base)) {
            return $base . DIRECTORY_SEPARATOR . $area . ($id === '' ? '' : DIRECTORY_SEPARATOR . $id);
        }
        $path = SafePath::directory($base, $area . ($id === '' ? '' : '/' . $id), $create);
        if ($create && DIRECTORY_SEPARATOR !== '\\') {
            chmod($base, 0700);
            chmod(dirname($path), 0700);
            chmod($path, 0700);
        }
        return $path;
    }

    public static function write(string $area, string $id, string $name, array $data): void
    {
        $directory = self::directory($area, $id);
        $contents = Json::encode($data);
        if (strlen($contents) > (int)(Limits::all()['max_json_size'] ?? 20971520)) {
            throw new RuntimeException('保存する管理データがJSON容量制限を超えています。');
        }
        Json::decode($contents);
        self::atomic($directory, $name, $contents);
    }

    public static function read(string $area, string $id, string $name): array
    {
        $path = SafePath::file(self::directory($area, $id, false), $name, false);
        return Json::read($path, (int)(Limits::all()['max_json_size'] ?? 20971520));
    }

    public static function atomic(string $base, string $relative, string $contents): void
    {
        $target = SafePath::file($base, $relative);
        $temporary = $target . '.tmp-' . bin2hex(random_bytes(8));
        $handle = fopen($temporary, 'xb');
        if ($handle === false) { throw new RuntimeException('保存用ファイルを作成できませんでした。'); }
        try {
            $offset = 0;
            while ($offset < strlen($contents)) {
                $written = fwrite($handle, substr($contents, $offset, 65536));
                if ($written === false || $written === 0) { throw new RuntimeException('データの保存に失敗しました。'); }
                $offset += $written;
            }
            if (!fflush($handle)) { throw new RuntimeException('データの書き込みを完了できませんでした。'); }
        } catch (\Throwable $exception) {
            fclose($handle);
            @unlink($temporary);
            throw $exception;
        }
        fclose($handle);
        if (DIRECTORY_SEPARATOR !== '\\') { chmod($temporary, 0600); }
        if (!rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('保存ファイルを配置できませんでした。');
        }
    }

    public static function ids(string $area): array
    {
        $base = self::directory($area, '', false);
        if (!is_dir($base)) { return []; }
        return array_values(array_filter(scandir($base), static fn($id) => preg_match('/^[a-f0-9]{32}$/', $id) === 1));
    }

    public static function remove(string $area, string $id): void
    {
        $base = self::directory($area, '', false);
        $path = self::directory($area, $id, false);
        if (!str_starts_with($path, $base . DIRECTORY_SEPARATOR) || $path === $base) {
            throw new RuntimeException('削除対象が保存範囲外です。');
        }
        if (is_dir($path)) { self::removeTree($path, $path); }
    }

    private static function removeTree(string $path, string $root): void
    {
        if ($path !== $root && !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('削除対象が作業範囲外です。');
        }
        clearstatcache(true, $path);
        $resolved = realpath($path);
        $same = $resolved !== false && (DIRECTORY_SEPARATOR === '\\' ? strcasecmp($path, $resolved) === 0 : $path === $resolved);
        if (is_link($path) || !$same) {
            if (!@rmdir($path) && !@unlink($path)) { throw new RuntimeException('保存先のリンクを削除できません。'); }
        } elseif (is_dir($path)) {
            foreach (scandir($path) as $name) {
                if ($name !== '.' && $name !== '..') { self::removeTree($path . DIRECTORY_SEPARATOR . $name, $root); }
            }
            if (!rmdir($path)) { throw new RuntimeException('作業フォルダを削除できません。'); }
        } elseif (!unlink($path)) { throw new RuntimeException('作業ファイルを削除できません。'); }
    }
}
