<?php

namespace NexaPressImportExport\Import;

use app\Core\Auth;
use app\Core\Database;
use NexaPressImportExport\Package\PackageReader;
use NexaPressImportExport\Repository\SettingsRepository;
use NexaPressImportExport\Repository\ImportRepository;
use NexaPressImportExport\Support\Json;
use NexaPressImportExport\Support\Limits;
use NexaPressImportExport\Support\OperationLock;
use NexaPressImportExport\Support\PrivateStorage;
use RuntimeException;
use Throwable;

class ImportService
{
    public function preview(string $zipPath, string $mode, array $options = []): array
    {
        $lock = OperationLock::acquire();
        $reader = new PackageReader();
        $package = null;
        try {
            $userId = $this->userId();
            $options = ImportOptions::normalize($options);
            $package = $reader->read($zipPath, $mode);
            $planner = new ImportPlanner();
            $plan = $planner->prepare($package['directory'], $mode, $userId, Limits::all(), $options);
            $signature = $this->signature($plan);
            return (new PreviewStore())->save(
                $zipPath, $mode, $options, $planner->summary($plan), $signature, $userId
            );
        } finally {
            try { if ($package !== null) { $reader->release($package); } }
            finally { $lock->release(); }
        }
    }

    public function confirm(string $token): array
    {
        $lock = OperationLock::acquire();
        $reader = new PackageReader();
        $package = null;
        try {
            $userId = $this->userId();
            $store = new PreviewStore();
            $request = $store->get($token, $userId);
            $package = $reader->read($store->packagePath($request), $request['mode']);
            $plan = (new ImportPlanner())->prepare($package['directory'], $request['mode'], $userId, Limits::all(), $request['options']);
            if (!hash_equals($request['signature'], $this->signature($plan))) {
                throw new RuntimeException('プレビュー後に変更対象のデータまたは重複状態が変わっています。再度プレビューしてください。');
            }
            $result = $this->execute($package['directory'], $plan, $userId, $request['signature']);
            try { PrivateStorage::remove('previews', $token); }
            catch (Throwable $exception) { $result['warning'] = '取り込みは完了しましたが、プレビューの一時ファイルを削除できませんでした。'; }
            return $result;
        } finally {
            try { if ($package !== null) { $reader->release($package); } }
            finally { $lock->release(); }
        }
    }

    /*
     * PHPから直接実行する場合も同じ検証・バックアップを必ず通す。
     * 管理画面の実行ルートではconfirmを使用する。
     */
    public function import(string $zipPath, string $mode, bool $importDebug = false, array $options = []): array
    {
        $lock = OperationLock::acquire();
        $reader = new PackageReader();
        $package = null;
        try {
            $userId = $this->userId();
            $options['import_debug'] = $importDebug;
            $package = $reader->read($zipPath, $mode);
            $plan = (new ImportPlanner())->prepare($package['directory'], $mode, $userId, Limits::all(), ImportOptions::normalize($options));
            return $this->execute($package['directory'], $plan, $userId);
        } finally {
            try { if ($package !== null) { $reader->release($package); } }
            finally { $lock->release(); }
        }
    }

    private function execute(string $directory, array $plan, int $userId, ?string $expectedSignature = null): array
    {
        $planner = new ImportPlanner();
        $targets = $planner->targets($plan);
        $result = ['categories' => 0, 'posts' => 0, 'pages' => 0, 'media' => 0, 'settings' => 0, 'skipped' => 0, 'backup_id' => null, 'warning' => ''];
        foreach ($plan['content'] + ['media' => $plan['media']] as $entries) {
            foreach ($entries as $entry) { if ($entry['action'] === 'skip') { $result['skipped']++; } }
        }
        if (array_sum(array_map('count', $targets)) === 0) { return $result; }
        $expectedSignature = $expectedSignature ?? $this->signature($plan);
        $backups = new BackupService();
        $id = $backups->create($targets, $plan['mode'], $userId);
        $result['backup_id'] = $id;
        $before = $backups->state($id, 'before');
        $snapshot = new ImportSnapshot();
        $pdo = null;
        $started = false;
        $filesTouched = false;
        $mediaImporter = new MediaImporter();
        $mediaState = null;
        try {
            $pdo = $plan['mode'] === 'settings' ? null : Database::connect();
            if ($pdo !== null) {
                if ($pdo->inTransaction() || !$pdo->beginTransaction()) { throw new RuntimeException('インポート処理を開始できませんでした。'); }
                $started = true;
                $lockedTargets = $targets;
                foreach ($plan['content'] as $type => $entries) {
                    foreach ($entries as $entry) { $lockedTargets[$type][] = $entry['key']; }
                }
                (new ImportRepository())->lockTargets($lockedTargets);
            }
            $current = $snapshot->capture($targets);
            if (
                !hash_equals(ImportSnapshot::digest($before), ImportSnapshot::digest($current)) ||
                !hash_equals($expectedSignature, hash('sha256', Json::encode($plan) . ImportSnapshot::digest($current)))
            ) {
                throw new RuntimeException('プレビュー後に変更対象が変わっています。再度プレビューしてください。');
            }
            foreach ($plan['content'] as $type => $entries) {
                foreach ($entries as $entry) {
                    if (($entry['action'] === 'add' && $current[$type][$entry['key']] !== null) || ($entry['action'] === 'update' && $current[$type][$entry['key']] === null)) {
                        throw new RuntimeException('重複状態が変更されています。再度プレビューしてください。');
                    }
                }
            }
            $content = (new ContentImporter())->apply($plan['content'], $userId);
            foreach ($content as $type => $count) { $result[$type] = $count; }
            if ($plan['media'] !== []) {
                $filesTouched = true;
                $media = $mediaImporter->apply($directory, $plan['media'], $userId);
                $result['media'] = $media['count'];
                $mediaState = $media['state'];
            }
            if ($plan['settings'] !== []) { $filesTouched = true; $result['settings'] = (new SettingsRepository())->save($plan['settings']); }
            // 復元に必要な取り込み後の状態も、DBの確定前に保存する。
            $backups->seal($id);
            if ($started && !$pdo->commit()) { throw new RuntimeException('インポート内容を確定できませんでした。'); }
            $started = false;
        } catch (Throwable $exception) {
            $errors = [];
            if ($started && $pdo->inTransaction()) {
                try { $pdo->rollBack(); } catch (Throwable $rollback) { $errors[] = $rollback->getMessage(); }
            }
            // メディアだけでなく、途中まで変更された設定も元のバイト列へ戻す。
            try { if ($filesTouched) { $snapshot->restoreFiles($before, PrivateStorage::directory('backups', $id, false)); } }
            catch (Throwable $rollback) { $errors[] = $rollback->getMessage(); }
            try { $backups->mark($id, $errors === [] ? 'failed' : 'pending'); }
            catch (Throwable $ignored) { /* 元データのバックアップは保持する */ }
            if ($errors !== []) { throw new RuntimeException('取り込みに失敗し、自動復旧も完了できませんでした。復旧用バックアップID：' . $id, 0, $exception); }
            throw $exception;
        }
        if ($mediaState !== null) {
            try { $mediaImporter->commit($mediaState); }
            catch (Throwable $exception) { $result['warning'] = '取り込みは完了しましたが、一時バックアップの削除に失敗しました。'; }
        }
        return $result;
    }

    private function signature(array $plan): string
    {
        $targets = (new ImportPlanner())->targets($plan);
        $current = (new ImportSnapshot())->capture($targets);
        return hash('sha256', Json::encode($plan) . ImportSnapshot::digest($current));
    }

    private function userId(): int
    {
        $userId = (int)(Auth::user()['id'] ?? 0);
        if ($userId <= 0) { throw new RuntimeException('ログインユーザーを確認できません。'); }
        return $userId;
    }
}
