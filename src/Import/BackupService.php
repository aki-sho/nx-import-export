<?php

namespace NexaPressImportExport\Import;

use app\Core\Database;
use NexaPressImportExport\Repository\ImportRepository;
use NexaPressImportExport\Support\AtomicFile;
use NexaPressImportExport\Support\Json;
use NexaPressImportExport\Support\Limits;
use NexaPressImportExport\Support\OperationLock;
use NexaPressImportExport\Support\PrivateStorage;
use NexaPressImportExport\Support\SafePath;
use RuntimeException;
use Throwable;

class BackupService
{
    private const TYPES = ['categories', 'posts', 'pages', 'media', 'files', 'settings'];

    public function create(array $targets, string $mode, int $userId, string $kind = 'import', array $protect = []): string
    {
        $snapshot = new ImportSnapshot();
        $before = $snapshot->capture($targets);
        $required = 0;
        foreach (['files', 'settings'] as $type) {
            foreach ($before[$type] as $metadata) { $required += $metadata['size'] ?? 0; }
        }
        foreach ($before as $rows) { $required += strlen(Json::encode($rows)); }
        if ($required > (int)(Limits::all()['max_backup_size'] ?? 524288000)) {
            throw new RuntimeException('変更前のバックアップが容量制限を超えています。取り込みは開始していません。');
        }
        $this->makeRoom($required, $protect, true);
        $id = bin2hex(random_bytes(16));
        $directory = PrivateStorage::directory('backups', $id);
        try {
            $checksums = $this->writeState($id, 'before', $before);
            foreach (['files', 'settings'] as $type) {
                foreach ($before[$type] as $relative => $metadata) {
                    if ($metadata !== null) {
                        AtomicFile::copy(SafePath::file(BASE_PATH, $relative, false), $directory, 'saved/' . $relative, $metadata['sha256']);
                    }
                }
            }
            PrivateStorage::write('backups', $id, 'manifest.json', [
                'id' => $id, 'owner' => $userId, 'mode' => $mode, 'kind' => $kind,
                'created_at' => gmdate('c'), 'sequence' => microtime(true), 'status' => 'pending', 'targets' => $targets,
                'before_hash' => ImportSnapshot::digest($before), 'before_checksums' => $checksums, 'protect' => $protect,
            ]);
            return $id;
        } catch (Throwable $exception) { PrivateStorage::remove('backups', $id); throw $exception; }
    }

    public function seal(string $id): void
    {
        $metadata = PrivateStorage::read('backups', $id, 'manifest.json');
        $after = (new ImportSnapshot())->capture($metadata['targets']);
        $additional = 0;
        foreach ($after as $rows) { $additional += strlen(Json::encode($rows)); }
        $directory = PrivateStorage::directory('backups', $id, false);
        if ($this->size($directory) + $additional > (int)(Limits::all()['max_backup_size'] ?? 524288000)) {
            throw new RuntimeException('バックアップの完了情報が容量制限を超えています。');
        }
        $this->makeRoom($additional, array_merge([$id], $metadata['protect'] ?? []));
        $metadata['after_checksums'] = $this->writeState($id, 'after', $after);
        $metadata['after_hash'] = ImportSnapshot::digest($after);
        $metadata['status'] = 'ready';
        PrivateStorage::write('backups', $id, 'manifest.json', $metadata);
        if ($this->size($directory) > (int)(Limits::all()['max_backup_size'] ?? 524288000)) {
            throw new RuntimeException('バックアップの合計容量が制限を超えています。');
        }
        $this->makeRoom(0, array_merge([$id], $metadata['protect'] ?? []));
    }

    public function state(string $id, string $phase): array
    {
        if (!in_array($phase, ['before', 'after'], true)) { throw new RuntimeException('バックアップの状態が正しくありません。'); }
        $metadata = PrivateStorage::read('backups', $id, 'manifest.json');
        $state = [];
        foreach (self::TYPES as $type) {
            $name = $phase . '-' . $type . '.json';
            $path = SafePath::file(PrivateStorage::directory('backups', $id, false), $name, false);
            $expected = $metadata[$phase . '_checksums'][$type] ?? '';
            if (!is_file($path) || !hash_equals($expected, (string)hash_file('sha256', $path))) {
                throw new RuntimeException('バックアップの管理データが破損しています。');
            }
            $state[$type] = PrivateStorage::read('backups', $id, $name);
        }
        return $state;
    }

    public function mark(string $id, string $status): void
    {
        $metadata = PrivateStorage::read('backups', $id, 'manifest.json');
        $metadata['status'] = $status;
        PrivateStorage::write('backups', $id, 'manifest.json', $metadata);
    }

    public function listForUser(int $userId): array
    {
        $items = [];
        foreach (PrivateStorage::ids('backups') as $id) {
            try { $metadata = PrivateStorage::read('backups', $id, 'manifest.json'); }
            catch (RuntimeException $exception) { continue; }
            if (($metadata['owner'] ?? 0) === $userId) {
                $items[] = array_intersect_key($metadata, array_flip(['id', 'created_at', 'sequence', 'status', 'mode', 'kind', 'targets']));
            }
        }
        usort($items, static fn($a, $b) => ($b['sequence'] ?? strtotime($b['created_at'])) <=> ($a['sequence'] ?? strtotime($a['created_at'])));
        return $items;
    }

    public function restore(string $id, int $userId): array
    {
        $lock = OperationLock::acquire();
        try { return $this->restoreLocked($id, $userId); }
        finally { $lock->release(); }
    }

    private function restoreLocked(string $id, int $userId): array
    {
        $metadata = PrivateStorage::read('backups', $id, 'manifest.json');
        if (($metadata['owner'] ?? 0) !== $userId || ($metadata['status'] ?? '') !== 'ready') {
            throw new RuntimeException('このバックアップを復元できません。実行者と保存状態を確認してください。');
        }
        $before = $this->state($id, 'before');
        $this->state($id, 'after');
        $snapshot = new ImportSnapshot();
        $current = $snapshot->capture($metadata['targets']);
        if (!hash_equals($metadata['after_hash'], ImportSnapshot::digest($current))) {
            throw new RuntimeException('取り込み後に対象データが変更されています。後から行った編集を守るため復元を中止しました。');
        }
        (new ImportRepository())->assertCategoryReferences($before);
        // 復元処理そのものも取り消せるよう、現在の状態を先に保存する。
        $guard = $this->create($metadata['targets'], $metadata['mode'], $userId, 'restore', [$id]);
        $usesDatabase = false;
        foreach (array_keys(ImportRepository::KEYS) as $type) { $usesDatabase = $usesDatabase || $metadata['targets'][$type] !== []; }
        $pdo = null;
        $started = false;
        $filesTouched = false;
        try {
            $pdo = $usesDatabase ? Database::connect() : null;
            if ($pdo !== null) {
                if ($pdo->inTransaction() || !$pdo->beginTransaction()) { throw new RuntimeException('復元処理を開始できませんでした。'); }
                $started = true;
                (new ImportRepository())->lockTargets($metadata['targets']);
            }
            if (!hash_equals($metadata['after_hash'], ImportSnapshot::digest($snapshot->capture($metadata['targets'])))) {
                throw new RuntimeException('復元開始前に対象データが変更されています。復元を中止しました。');
            }
            $snapshot->restoreRows($before);
            $filesTouched = true;
            $snapshot->restoreFiles($before, PrivateStorage::directory('backups', $id, false));
            $this->seal($guard);
            if ($started && !$pdo->commit()) { throw new RuntimeException('復元内容を確定できませんでした。'); }
            $started = false;
        } catch (Throwable $exception) {
            $errors = [];
            if ($started && $pdo->inTransaction()) {
                try { $pdo->rollBack(); } catch (Throwable $rollback) { $errors[] = $rollback->getMessage(); }
            }
            try { if ($filesTouched) { $snapshot->restoreFiles($current, PrivateStorage::directory('backups', $guard, false)); } }
            catch (Throwable $rollback) { $errors[] = $rollback->getMessage(); }
            try { $this->mark($guard, $errors === [] ? 'failed' : 'pending'); } catch (Throwable $ignored) { /* 復旧データを保持 */ }
            if ($errors !== []) { throw new RuntimeException('復元に失敗し、自動復旧も完了できませんでした。復旧用バックアップID：' . $guard, 0, $exception); }
            throw $exception;
        }
        $warning = '';
        try { $this->mark($id, 'restored'); }
        catch (Throwable $exception) { $warning = '復元は完了しましたが、履歴の状態を更新できませんでした。'; }
        return ['backup_id' => $guard, 'warning' => $warning];
    }

    private function writeState(string $id, string $phase, array $state): array
    {
        $checksums = [];
        foreach (self::TYPES as $type) {
            $name = $phase . '-' . $type . '.json';
            PrivateStorage::write('backups', $id, $name, $state[$type]);
            $checksums[$type] = hash_file('sha256', SafePath::file(PrivateStorage::directory('backups', $id, false), $name, false));
        }
        return $checksums;
    }

    private function makeRoom(int $additional, array $protect, bool $newBackup = false): void
    {
        $limits = Limits::all();
        $maximum = (int)($limits['max_backups'] ?? 5);
        if ($maximum < 2) { throw new RuntimeException('復元前の保護のため、バックアップ保持件数は2件以上に設定してください。'); }
        $quota = (int)($limits['max_backup_storage_size'] ?? 1073741824);
        $entries = [];
        $total = 0;
        foreach (PrivateStorage::ids('backups') as $id) {
            $directory = PrivateStorage::directory('backups', $id, false);
            $size = $this->size($directory);
            $total += $size;
            try { $metadata = PrivateStorage::read('backups', $id, 'manifest.json'); }
            catch (RuntimeException $exception) { continue; }
            $entries[$id] = ['size' => $size, 'metadata' => $metadata];
        }
        uasort($entries, static fn($a, $b) => ($a['metadata']['sequence'] ?? strtotime($a['metadata']['created_at'])) <=> ($b['metadata']['sequence'] ?? strtotime($b['metadata']['created_at'])));
        $count = count(PrivateStorage::ids('backups'));
        foreach ($entries as $id => $entry) {
            if ($total + $additional <= $quota && $count + ($newBackup ? 1 : 0) <= $maximum) { break; }
            if (in_array($id, $protect, true) || !in_array($entry['metadata']['status'], ['ready', 'restored', 'failed'], true)) { continue; }
            PrivateStorage::remove('backups', $id);
            $total -= $entry['size'];
            $count--;
        }
        if ($total + $additional > $quota || $count + ($newBackup ? 1 : 0) > $maximum) {
            throw new RuntimeException('バックアップの保存容量または件数が上限に達しています。');
        }
    }

    private function size(string $directory): int
    {
        $size = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink()) { throw new RuntimeException('バックアップ内にリンクがあります。'); }
            if ($file->isFile()) { $size += $file->getSize(); }
        }
        return $size;
    }
}
