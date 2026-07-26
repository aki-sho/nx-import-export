<?php

namespace NexaPressImportExport\Import;

use DateTimeImmutable;
use NexaPressImportExport\Repository\MediaRepository;
use NexaPressImportExport\Support\Json;
use NexaPressImportExport\Support\Zip;
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
    public function import(
        string $packageDirectory,
        int $userId,
        array $limits
    ): array {
        if ($userId <= 0) {
            throw new RuntimeException(
                'メディアの所有者を確認できません。'
            );
        }

        $maxJsonSize = (int)(
            $limits['max_json_size']
            ?? 20971520
        );

        $maxMediaFiles = (int)(
            $limits['max_media_files']
            ?? 2000
        );

        $maxFileSize = (int)(
            $limits[
                'max_media_file_size'
            ]
            ?? 52428800
        );

        $document =
            Json::read(
                rtrim(
                    $packageDirectory,
                    '/\\'
                )
                . '/data/media.json',
                $maxJsonSize
            );

        $items =
            $document['items']
            ?? null;

        $count =
            $document['count']
            ?? null;

        if (
            !is_array($items) ||
            !is_numeric($count) ||
            (int)$count
                !== count($items)
        ) {
            throw new RuntimeException(
                'メディアデータの形式が正しくありません。'
            );
        }

        if (
            count($items)
            > $maxMediaFiles
        ) {
            throw new RuntimeException(
                'メディア件数が制限を超えています。'
            );
        }

        $packagePath = realpath(
            $packageDirectory
        );

        if ($packagePath === false) {
            throw new RuntimeException(
                'メディアパッケージを確認できません。'
            );
        }

        $backupDirectory =
            $packagePath
            . DIRECTORY_SEPARATOR
            . '.media-backup-'
            . bin2hex(
                random_bytes(6)
            );

        if (
            !mkdir(
                $backupDirectory,
                0755,
                true
            ) &&
            !is_dir($backupDirectory)
        ) {
            throw new RuntimeException(
                'メディアバックアップを作成できませんでした。'
            );
        }

        $state = [
            'backup_directory' =>
                $backupDirectory,

            'created' => [],
            'replaced' => [],
        ];

        $repository =
            new MediaRepository();

        $processedPaths = [];
        $processedCount = 0;

        try {
            foreach ($items as $item) {
                if (!is_array($item)) {
                    throw new RuntimeException(
                        'メディア情報が正しくありません。'
                    );
                }

                $filePath =
                    $this->filePath(
                        $item['file_path']
                        ?? ''
                    );

                if (
                    isset(
                        $processedPaths[
                            $filePath
                        ]
                    )
                ) {
                    throw new RuntimeException(
                        '重複したメディアファイルがあります。'
                    );
                }

                $processedPaths[
                    $filePath
                ] = true;

                $archivePath =
                    Zip::normalizePath(
                        (string)(
                            $item[
                                'archive_path'
                            ]
                            ?? ''
                        )
                    );

                if (
                    $archivePath
                    !== 'media/' . $filePath
                ) {
                    throw new RuntimeException(
                        'メディアの保存先情報が一致しません。'
                    );
                }

                $sourceFile =
                    $packagePath
                    . DIRECTORY_SEPARATOR
                    . str_replace(
                        '/',
                        DIRECTORY_SEPARATOR,
                        $archivePath
                    );

                $realSourceFile =
                    realpath(
                        $sourceFile
                    );

                if (
                    $realSourceFile === false ||
                    !is_file(
                        $realSourceFile
                    ) ||
                    !str_starts_with(
                        $realSourceFile,
                        $packagePath
                        . DIRECTORY_SEPARATOR
                    )
                ) {
                    throw new RuntimeException(
                        'メディアファイルが見つかりません。'
                    );
                }

                $fileSize = filesize(
                    $realSourceFile
                );

                if (
                    $fileSize === false ||
                    $fileSize < 0 ||
                    $fileSize > $maxFileSize
                ) {
                    throw new RuntimeException(
                        'メディアファイルの容量が正しくありません。'
                    );
                }

                if (
                    isset($item['file_size']) &&
                    (int)$item['file_size']
                        !== (int)$fileSize
                ) {
                    throw new RuntimeException(
                        'メディアファイルの容量が一致しません。'
                    );
                }

                $mimeType =
                    mime_content_type(
                        $realSourceFile
                    );

                if (
                    !is_string($mimeType) ||
                    !isset(
                        $this->allowedTypes[
                            $mimeType
                        ]
                    )
                ) {
                    throw new RuntimeException(
                        '使用できないメディア形式が含まれています。'
                    );
                }

                $targetFile =
                    $repository->targetFile(
                        $filePath
                    );

                $targetDirectory =
                    dirname($targetFile);

                if (
                    !is_dir($targetDirectory) &&
                    !mkdir(
                        $targetDirectory,
                        0755,
                        true
                    ) &&
                    !is_dir($targetDirectory)
                ) {
                    throw new RuntimeException(
                        'メディア保存フォルダを作成できませんでした。'
                    );
                }

                if (is_link($targetFile)) {
                    throw new RuntimeException(
                        'メディア保存先にリンクファイルがあります。'
                    );
                }

                $temporaryTarget =
                    $targetFile
                    . '.nx-import-'
                    . bin2hex(
                        random_bytes(6)
                    );

                if (
                    !copy(
                        $realSourceFile,
                        $temporaryTarget
                    )
                ) {
                    throw new RuntimeException(
                        'メディアファイルを一時保存できませんでした。'
                    );
                }

                /*
                 * 既存ファイルをバックアップ
                 */
                if (is_file($targetFile)) {
                    $backupFile =
                        $backupDirectory
                        . DIRECTORY_SEPARATOR
                        . hash(
                            'sha256',
                            $filePath
                        )
                        . '.bak';

                    if (
                        !copy(
                            $targetFile,
                            $backupFile
                        )
                    ) {
                        unlink(
                            $temporaryTarget
                        );

                        throw new RuntimeException(
                            '既存メディアをバックアップできませんでした。'
                        );
                    }

                    if (!unlink($targetFile)) {
                        unlink(
                            $temporaryTarget
                        );

                        throw new RuntimeException(
                            '既存メディアを更新できませんでした。'
                        );
                    }

                    if (
                        !rename(
                            $temporaryTarget,
                            $targetFile
                        )
                    ) {
                        copy(
                            $backupFile,
                            $targetFile
                        );

                        unlink(
                            $temporaryTarget
                        );

                        throw new RuntimeException(
                            'メディアファイルを配置できませんでした。'
                        );
                    }

                    $state['replaced'][] = [
                        'target' =>
                            $targetFile,

                        'backup' =>
                            $backupFile,
                    ];
                } else {
                    if (
                        !rename(
                            $temporaryTarget,
                            $targetFile
                        )
                    ) {
                        unlink(
                            $temporaryTarget
                        );

                        throw new RuntimeException(
                            'メディアファイルを配置できませんでした。'
                        );
                    }

                    $state['created'][] =
                        $targetFile;
                }

                $createdAt =
                    $this->date(
                        $item['created_at']
                        ?? null
                    );

                $updatedAt =
                    $this->date(
                        $item['updated_at']
                        ?? null,
                        $createdAt
                    );

                $originalName = trim(
                    (string)(
                        $item['original_name']
                        ?? basename($filePath)
                    )
                );

                if ($originalName === '') {
                    $originalName =
                        basename($filePath);
                }

                $title = trim(
                    (string)(
                        $item['title']
                        ?? ''
                    )
                );

                if ($title === '') {
                    $title =
                        pathinfo(
                            $originalName,
                            PATHINFO_FILENAME
                        );
                }

                $repository->save(
                    [
                        'title' => $title,

                        'description' =>
                            (string)(
                                $item[
                                    'description'
                                ]
                                ?? ''
                            ),

                        'original_name' =>
                            $originalName,

                        'file_name' =>
                            basename(
                                $filePath
                            ),

                        'file_path' =>
                            $filePath,

                        'mime_type' =>
                            $mimeType,

                        'file_size' =>
                            (int)$fileSize,

                        'file_type' =>
                            $this->allowedTypes[
                                $mimeType
                            ],

                        'created_at' =>
                            $createdAt,

                        'updated_at' =>
                            $updatedAt,
                    ],
                    $userId
                );

                $processedCount++;
            }

            return [
                'count' =>
                    $processedCount,

                'state' =>
                    $state,
            ];
        } catch (\Throwable $exception) {
            $this->rollback(
                $state
            );

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

        return $filePath;
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
                ?? date(
                    'Y-m-d H:i:s'
                );
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