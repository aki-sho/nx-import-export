<?php

namespace NexaPressImportExport\Export;

use NexaPressImportExport\Repository\MediaRepository;
use NexaPressImportExport\Support\Json;
use NexaPressImportExport\Support\Zip;
use RuntimeException;

class MediaExporter
{
    /*
     * メディア情報と実ファイルを出力
     */
    public function export(
        string $packageDirectory,
        array $limits
    ): array {
        $repository =
            new MediaRepository();

        $mediaItems =
            $repository->all();

        $maxMediaFiles = (int)(
            $limits['max_media_files']
            ?? 2000
        );

        if (
            count($mediaItems)
            > $maxMediaFiles
        ) {
            throw new RuntimeException(
                'メディア件数が制限を超えています。'
            );
        }

        $maxFileSize = (int)(
            $limits[
                'max_media_file_size'
            ]
            ?? 52428800
        );

        $dataDirectory =
            rtrim(
                $packageDirectory,
                '/\\'
            )
            . DIRECTORY_SEPARATOR
            . 'data';

        if (
            !is_dir($dataDirectory) &&
            !mkdir(
                $dataDirectory,
                0755,
                true
            ) &&
            !is_dir($dataDirectory)
        ) {
            throw new RuntimeException(
                'メディア情報保存フォルダを作成できませんでした。'
            );
        }

        $exportedItems = [];
        $archivePaths = [];

        foreach ($mediaItems as $row) {
            if (!is_array($row)) {
                continue;
            }

            $filePath =
                $this->normalizeFilePath(
                    (string)(
                        $row['file_path']
                        ?? ''
                    )
                );

            $sourceFile =
                $repository->sourceFile(
                    $filePath
                );

            if ($sourceFile === null) {
                throw new RuntimeException(
                    'メディアファイルが見つかりません：'
                    . $filePath
                );
            }

            $fileSize = filesize(
                $sourceFile
            );

            if (
                $fileSize === false ||
                $fileSize < 0
            ) {
                throw new RuntimeException(
                    'メディアファイルの容量を確認できません。'
                );
            }

            if ($fileSize > $maxFileSize) {
                throw new RuntimeException(
                    'メディアファイルの容量が制限を超えています：'
                    . $filePath
                );
            }

            /*
             * ZIP内ではmedia以下へ保存
             *
             * 元のfile_path:
             * uploads/media/example.jpg
             *
             * ZIP内:
             * media/uploads/media/example.jpg
             */
            $archivePath =
                Zip::normalizePath(
                    'media/' . $filePath
                );

            if (
                in_array(
                    $archivePath,
                    $archivePaths,
                    true
                )
            ) {
                throw new RuntimeException(
                    '重複したメディアファイルがあります。'
                );
            }

            $archivePaths[] =
                $archivePath;

            $targetFile =
                rtrim(
                    $packageDirectory,
                    '/\\'
                )
                . DIRECTORY_SEPARATOR
                . str_replace(
                    '/',
                    DIRECTORY_SEPARATOR,
                    $archivePath
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

            if (
                !copy(
                    $sourceFile,
                    $targetFile
                )
            ) {
                throw new RuntimeException(
                    'メディアファイルをコピーできませんでした。'
                );
            }

            $exportedItems[] = [
                'id' =>
                    (int)(
                        $row['id']
                        ?? 0
                    ),

                'title' =>
                    (string)(
                        $row['title']
                        ?? ''
                    ),

                'description' =>
                    (string)(
                        $row['description']
                        ?? ''
                    ),

                'original_name' =>
                    (string)(
                        $row['original_name']
                        ?? ''
                    ),

                'file_name' =>
                    (string)(
                        $row['file_name']
                        ?? basename($filePath)
                    ),

                'file_path' =>
                    $filePath,

                'archive_path' =>
                    $archivePath,

                'mime_type' =>
                    (string)(
                        $row['mime_type']
                        ?? 'application/octet-stream'
                    ),

                'file_size' =>
                    (int)$fileSize,

                'file_type' =>
                    (string)(
                        $row['file_type']
                        ?? 'document'
                    ),

                /*
                 * ユーザー情報は出力しない
                 */
                'created_at' =>
                    $this->nullableString(
                        $row['created_at']
                        ?? null
                    ),

                'updated_at' =>
                    $this->nullableString(
                        $row['updated_at']
                        ?? null
                    ),
            ];
        }

        Json::write(
            $dataDirectory
            . '/media.json',
            [
                'items' =>
                    $exportedItems,

                'count' =>
                    count(
                        $exportedItems
                    ),
            ]
        );

        return [
            'media' =>
                count(
                    $exportedItems
                ),

            'files' =>
                count(
                    $archivePaths
                ),
        ];
    }

    /*
     * メディアファイルのパスを検証
     */
    private function normalizeFilePath(
        string $filePath
    ): string {
        $filePath = str_replace(
            '\\',
            '/',
            trim($filePath)
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
     * NULLを許可する文字列へ変換
     */
    private function nullableString(
        mixed $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim(
            (string)$value
        );

        return $value !== ''
            ? $value
            : null;
    }
}