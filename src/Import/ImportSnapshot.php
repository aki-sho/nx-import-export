<?php

namespace NexaPressImportExport\Import;

use NexaPressImportExport\Repository\ImportRepository;
use NexaPressImportExport\Support\AtomicFile;
use NexaPressImportExport\Support\Json;
use NexaPressImportExport\Support\SafePath;
use RuntimeException;

class ImportSnapshot
{
    public function capture(array $targets): array
    {
        $state = [];
        $lookup = new ImportRepository();
        foreach (array_keys(ImportRepository::KEYS) as $type) {
            if (!array_key_exists($type, $targets)) {
                continue;
            }
            $state[$type] = [];
            foreach ($targets[$type] as $key) { $state[$type][$key] = $lookup->find($type, $key); }
        }
        foreach (['files', 'settings'] as $type) {
            $state[$type] = [];
            foreach ($targets[$type] as $relative) {
                $path = SafePath::file(BASE_PATH, $relative, false);
                if (!is_file($path)) { $state[$type][$relative] = null; continue; }
                $size = filesize($path);
                $hash = hash_file('sha256', $path);
                if ($size === false || $hash === false) { throw new RuntimeException('変更対象のファイルを確認できません。'); }
                $state[$type][$relative] = ['sha256' => $hash, 'size' => $size, 'mode' => fileperms($path) & 0777];
            }
        }
        return $state;
    }

    public static function digest(array $state): string { return hash('sha256', Json::encode($state)); }

    public function restoreFiles(array $state, string $backupDirectory): void
    {
        foreach (['files', 'settings'] as $type) {
            foreach ($state[$type] as $relative => $metadata) {
                $target = SafePath::file(BASE_PATH, $relative, false);
                if ($metadata === null) {
                    if (is_file($target) && !unlink($target)) { throw new RuntimeException('追加ファイルを削除できませんでした。'); }
                } else {
                    $source = SafePath::file($backupDirectory, 'saved/' . $relative, false);
                    if (!is_file($source) || !hash_equals($metadata['sha256'], (string)hash_file('sha256', $source))) {
                        throw new RuntimeException('バックアップファイルが破損しています。');
                    }
                    AtomicFile::copy($source, BASE_PATH, $relative, $metadata['sha256']);
                    if (DIRECTORY_SEPARATOR !== '\\') { chmod(SafePath::file(BASE_PATH, $relative, false), $metadata['mode']); }
                }
            }
        }
    }

    public function restoreRows(array $state): void
    {
        $lookup = new ImportRepository();
        $lookup->assertReferences($state);
        // Restore referenced rows first, then remove additions after their dependents.
        $references = ['categories', 'media_folders', 'media'];
        foreach ($references as $type) {
            foreach ($state[$type] ?? [] as $key => $row) {
                if ($row !== null) {
                    $lookup->restore($type, (string)$key, $row);
                }
            }
        }
        foreach (['posts', 'pages'] as $type) {
            foreach ($state[$type] as $key => $row) {
                $lookup->restore($type, (string)$key, $row);
            }
        }
        foreach (array_reverse($references) as $type) {
            foreach ($state[$type] ?? [] as $key => $row) {
                if ($row === null) {
                    $lookup->restore($type, (string)$key, null);
                }
            }
        }
    }
}
