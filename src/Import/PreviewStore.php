<?php

namespace NexaPressImportExport\Import;

use NexaPressImportExport\Support\AtomicFile;
use NexaPressImportExport\Support\Limits;
use NexaPressImportExport\Support\PrivateStorage;
use NexaPressImportExport\Support\SafePath;
use RuntimeException;

class PreviewStore
{
    public function save(string $zipPath, string $mode, array $options, array $summary, string $signature, int $userId): array
    {
        $limits = Limits::all();
        $owned = [];
        foreach (PrivateStorage::ids('previews') as $id) {
            try { $request = PrivateStorage::read('previews', $id, 'request.json'); }
            catch (RuntimeException $exception) { PrivateStorage::remove('previews', $id); continue; }
            if (($request['expires_at'] ?? 0) <= time()) { PrivateStorage::remove('previews', $id); }
            elseif (($request['owner'] ?? 0) === $userId) { $owned[$id] = $request['created_at']; }
        }
        asort($owned);
        $maximum = max(1, (int)($limits['max_pending_previews'] ?? 3));
        $id = bin2hex(random_bytes(16));
        $directory = PrivateStorage::directory('previews', $id);
        try {
            AtomicFile::copy($zipPath, $directory, 'package.zip', null, (int)($limits['max_package_size'] ?? 104857600));
            $request = [
                'id' => $id, 'owner' => $userId, 'mode' => $mode, 'options' => $options,
                'summary' => $summary, 'signature' => $signature,
                'created_at' => microtime(true), 'expires_at' => time() + max(60, (int)($limits['preview_lifetime'] ?? 3600)),
                'zip_hash' => hash_file('sha256', $directory . '/package.zip'),
            ];
            PrivateStorage::write('previews', $id, 'request.json', $request);
            // 再プレビュー元のZIPは、新しいコピーの保存が終わるまで残す。
            while (count($owned) >= $maximum) {
                $oldest = array_key_first($owned);
                PrivateStorage::remove('previews', $oldest);
                unset($owned[$oldest]);
            }
            return $request;
        } catch (\Throwable $exception) { PrivateStorage::remove('previews', $id); throw $exception; }
    }

    public function get(string $id, int $userId): array
    {
        $request = PrivateStorage::read('previews', $id, 'request.json');
        if (($request['owner'] ?? 0) !== $userId || ($request['expires_at'] ?? 0) <= time()) {
            throw new RuntimeException('プレビューの有効期限が切れているか、実行者が異なります。再度アップロードしてください。');
        }
        return $request;
    }

    public function packagePath(array $request): string
    {
        $path = SafePath::file(PrivateStorage::directory('previews', $request['id'], false), 'package.zip', false);
        if (!is_file($path) || !hash_equals($request['zip_hash'], (string)hash_file('sha256', $path))) {
            throw new RuntimeException('確認用のZIPが変更されています。再度アップロードしてください。');
        }
        return $path;
    }

    public function remove(string $id, int $userId): void
    {
        $this->get($id, $userId);
        PrivateStorage::remove('previews', $id);
    }
}
