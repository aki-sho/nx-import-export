<?php

namespace NexaPressImportExport\Import;

use DateTimeImmutable;
use NexaPressImportExport\Repository\MediaRepository;
use NexaPressImportExport\Support\Json;
use NexaPressImportExport\Support\AtomicFile;
use NexaPressImportExport\Support\Zip;
use NexaPressImportExport\Validation\MediaValidator;
use RuntimeException;

class MediaImporter
{
    /*
     * 使用できるメディア形式
     */
    private array $allowedTypes = [
        'image/jpeg' => 'image',
        'image/png' => 'image',
        'image/gif' => 'image',
        'image/webp' => 'image',

        'audio/mpeg' => 'audio',
        'audio/wav' => 'audio',
        'audio/x-wav' => 'audio',
        'audio/mp4' => 'audio',
        'audio/ogg' => 'audio',

        'video/mp4' => 'video',
        'video/webm' => 'video',
        'video/quicktime' => 'video',

        'application/pdf' => 'document',
        'text/plain' => 'document',
        'text/csv' => 'document',

        'application/msword' =>
            'document',

        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' =>
            'document',

        'application/vnd.ms-excel' =>
            'document',

        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' =>
            'document',

        'application/vnd.ms-powerpoint' =>
            'document',

        'application/vnd.openxmlformats-officedocument.presentationml.presentation' =>
            'document',
    ];

    /*
     * メディアを取り込む
     */
    private string $importedAt = '';

    public function import(string $packageDirectory, int $userId, array $limits, array $options = []): array
    {
        return $this->apply($packageDirectory, $this->prepare($packageDirectory, $userId, $limits, $options), $userId);
    }

    public function prepare(string $packageDirectory, int $userId, array $limits, array $options = []): array
    {
        if ($userId <= 0) { throw new RuntimeException('メディアの所有者を確認できません。'); }
        $options = ImportOptions::normalize($options);
        $this->importedAt = $options['imported_at'];
        $document = Json::read(rtrim($packageDirectory, '/\\') . '/data/media.json', (int)($limits['max_json_size'] ?? 20971520));
        $items = $document['items'] ?? null;
        if (!is_array($items) || !is_numeric($document['count'] ?? null) || (int)$document['count'] !== count($items)) {
            throw new RuntimeException('メディアデータの形式が正しくありません。');
        }
        if (count($items) > (int)($limits['max_media_files'] ?? 2000)) { throw new RuntimeException('メディア件数が制限を超えています。'); }
        $base = realpath($packageDirectory);
        if ($base === false) { throw new RuntimeException('メディアパッケージを確認できません。'); }
        $paths = [];
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['file_path'] ?? null)) { throw new RuntimeException('メディア情報が正しくありません。'); }
            $path = $this->filePath($item['file_path']);
            if (isset($paths[strtolower($path)])) { throw new RuntimeException('重複したメディアファイルがあります。'); }
            $paths[strtolower($path)] = $path;
        }
        $resolver = new ConflictResolver();
        $resolver->reserveInput('media', array_values($paths));
        $plan = [];
        foreach ($items as $item) {
            $filePath = $this->filePath($item['file_path']);
            if (!is_string($item['archive_path'] ?? null)) { throw new RuntimeException('メディアの保存先情報が正しくありません。'); }
            $archivePath = Zip::normalizePath($item['archive_path']);
            if ($archivePath !== 'media/' . $filePath) { throw new RuntimeException('メディアの保存先情報が一致しません。'); }
            $source = realpath($base . '/' . $archivePath);
            if ($source === false || !is_file($source) || !str_starts_with($source, $base . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('メディアファイルが見つかりません。');
            }
            $size = filesize($source);
            if ($size === false || $size > (int)($limits['max_media_file_size'] ?? 52428800)) {
                throw new RuntimeException('メディアファイルの容量が正しくありません。');
            }
            if (isset($item['file_size']) && (int)$item['file_size'] !== $size) { throw new RuntimeException('メディアファイルの容量が一致しません。'); }
            $mime = mime_content_type($source);
            if (!is_string($mime) || !isset($this->allowedTypes[$mime])) { throw new RuntimeException('使用できないメディア形式が含まれています。'); }
            MediaValidator::type($filePath, $mime);
            $entry = $resolver->resolve('media', $filePath, $options['conflict']);
            $entry['source'] = $archivePath;
            $entry['sha256'] = hash_file('sha256', $source);
            $created = $this->date($item['created_at'] ?? null);
            foreach (['title', 'description', 'original_name'] as $field) {
                if (isset($item[$field]) && !is_string($item[$field])) { throw new RuntimeException('メディアの説明・名前は文字列で指定してください。'); }
            }
            $original = trim($item['original_name'] ?? basename($filePath));
            $entry['data'] = [
                'title' => trim($item['title'] ?? '') ?: pathinfo($original ?: basename($filePath), PATHINFO_FILENAME),
                'description' => $item['description'] ?? '',
                'original_name' => $original ?: basename($filePath),
                'file_name' => basename($entry['key']), 'file_path' => $entry['key'],
                'mime_type' => $mime, 'file_size' => $size, 'file_type' => $this->allowedTypes[$mime],
                'created_at' => $created, 'updated_at' => $this->date($item['updated_at'] ?? null, $created),
            ];
            if (array_key_exists('folder_name', $item)) {
                $folder = $item['folder_name'];
                if ($folder !== null && (!is_string($folder) || trim($folder) !== $folder ||
                    !preg_match('/\A[^\p{Cc}]{1,100}\z/u', $folder))) {
                    throw new RuntimeException('メディアのフォルダ名が正しくありません。');
                }
                if (MediaRepository::supportsFolders()) {
                    $entry['data']['folder_name'] = $folder;
                }
            }
            $plan[] = $entry;
        }
        return $plan;
    }

    public function apply(string $packageDirectory, array $plan, int $userId): array
    {
        $base = realpath($packageDirectory);
        if ($base === false) { throw new RuntimeException('メディアパッケージを確認できません。'); }
        $backupDirectory = $base . '/.media-backup-' . bin2hex(random_bytes(6));
        if (!mkdir($backupDirectory, 0700)) { throw new RuntimeException('メディアバックアップを作成できませんでした。'); }
        $state = ['backup_directory' => $backupDirectory, 'created' => [], 'replaced' => []];
        $count = 0;
        $repository = new MediaRepository();
        try {
            foreach ($plan as $entry) {
                if ($entry['action'] === 'skip') { continue; }
                $source = realpath($base . '/' . $entry['source']);
                if ($source === false || !str_starts_with($source, $base . DIRECTORY_SEPARATOR)) { throw new RuntimeException('メディアのコピー元が正しくありません。'); }
                $target = $repository->targetFile($entry['key']);
                if (is_file($target)) {
                    if ($entry['action'] === 'add') {
                        throw new RuntimeException('取り込み中にメディア保存先が重複しました。再度プレビューしてください。');
                    }
                    $backupName = hash('sha256', $entry['key']) . '.bak';
                    AtomicFile::copy($target, $backupDirectory, $backupName);
                    $state['replaced'][] = ['target' => $target, 'backup' => $backupDirectory . DIRECTORY_SEPARATOR . $backupName];
                } else { $state['created'][] = $target; }
                AtomicFile::copy($source, BASE_PATH, 'public/' . $entry['key'], $entry['sha256']);
                // 公開メディアは通常のWebサーバーから読み出せる権限へ戻す。
                if (DIRECTORY_SEPARATOR !== '\\') { chmod($target, 0644); }
                $repository->save($entry['data'], $userId, $entry['action'] === 'update');
                $count++;
            }
            return ['count' => $count, 'state' => $state];
        } catch (\Throwable $exception) {
            $this->rollback($state);
            throw $exception;
        }
    }

    /*
     * インポート成功後に
     * バックアップを削除
     */
    public function commit(
        array $state
    ): void {
        $backupDirectory =
            (string)(
                $state[
                    'backup_directory'
                ]
                ?? ''
            );

        $this->removeDirectory(
            $backupDirectory
        );
    }

    /*
     * コピーしたファイルを元に戻す
     */
    public function rollback(
        array $state
    ): void {
        $created =
            $state['created']
            ?? [];

        if (is_array($created)) {
            foreach (
                array_reverse($created)
                as $targetFile
            ) {
                if (
                    is_string($targetFile) &&
                    is_file($targetFile)
                ) {
                    @unlink(
                        $targetFile
                    );
                }
            }
        }

        $replaced =
            $state['replaced']
            ?? [];

        if (is_array($replaced)) {
            foreach (
                array_reverse($replaced)
                as $file
            ) {
                if (!is_array($file)) {
                    continue;
                }

                $target =
                    (string)(
                        $file['target']
                        ?? ''
                    );

                $backup =
                    (string)(
                        $file['backup']
                        ?? ''
                    );

                if (
                    $target !== '' &&
                    is_file($target)
                ) {
                    @unlink(
                        $target
                    );
                }

                if (
                    $backup !== '' &&
                    is_file($backup)
                ) {
                    if (
                        !@rename(
                            $backup,
                            $target
                        )
                    ) {
                        @copy(
                            $backup,
                            $target
                        );
                    }
                }
            }
        }

        $this->commit(
            $state
        );
    }

    /*
     * メディアパスを確認
     */
    private function filePath(
        mixed $value
    ): string {
        $filePath = str_replace(
            '\\',
            '/',
            trim(
                (string)$value
            )
        );

        $filePath = ltrim(
            $filePath,
            '/'
        );

        $filePath =
            Zip::normalizePath(
                $filePath
            );

        if (
            !str_starts_with(
                $filePath,
                'uploads/media/'
            )
        ) {
            throw new RuntimeException(
                'メディアファイルのパスが正しくありません。'
            );
        }

        return MediaValidator::path($filePath);
    }

    /*
     * DB保存用日時へ変換
     */
    private function date(
        mixed $value,
        ?string $fallback = null
    ): string {
        $value = trim(
            (string)$value
        );

        if ($value === '') {
            return $fallback
                ?? ($this->importedAt !== '' ? $this->importedAt : date('Y-m-d H:i:s'));
        }

        $date =
            DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                $value
            );

        $errors =
            DateTimeImmutable::getLastErrors();

        if (
            $date === false ||
            (
                is_array($errors) &&
                (
                    $errors['warning_count'] > 0 ||
                    $errors['error_count'] > 0
                )
            ) ||
            $date->format(
                'Y-m-d H:i:s'
            ) !== $value
        ) {
            throw new RuntimeException(
                'メディアの日時形式が正しくありません。'
            );
        }

        return $value;
    }

    /*
     * バックアップフォルダを削除
     */
    private function removeDirectory(
        string $directory
    ): void {
        if (
            $directory === '' ||
            !is_dir($directory)
        ) {
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
                $this->removeDirectory(
                    $path
                );
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
