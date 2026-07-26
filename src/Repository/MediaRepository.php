<?php

namespace NexaPressImportExport\Repository;

use app\Core\Database;
use RuntimeException;

class MediaRepository
{
    /*
     * メディア情報を取得
     */
    public function all(): array
    {
        $pdo = Database::connect();

        $table =
            Database::table(
                'media'
            );

        $statement = $pdo->query("
            SELECT *
            FROM {$table}
            ORDER BY id ASC
        ");

        if ($statement === false) {
            throw new RuntimeException(
                'メディア情報を取得できませんでした。'
            );
        }

        return $statement->fetchAll();
    }

    /*
     * 公開フォルダ内の元ファイルを取得
     */
    public function sourceFile(
        string $filePath
    ): ?string {
        $publicPath = realpath(
            BASE_PATH . '/public'
        );

        if ($publicPath === false) {
            return null;
        }

        $relativePath =
            $this->normalizeMediaPath(
                $filePath
            );

        $candidate =
            $publicPath
            . DIRECTORY_SEPARATOR
            . str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $relativePath
            );

        $realFilePath = realpath(
            $candidate
        );

        if (
            $realFilePath === false ||
            !is_file($realFilePath) ||
            !str_starts_with(
                $realFilePath,
                $publicPath
                . DIRECTORY_SEPARATOR
            )
        ) {
            return null;
        }

        return $realFilePath;
    }

    /*
     * インポート先の絶対パスを取得
     */
    public function targetFile(
        string $filePath
    ): string {
        $relativePath =
            $this->normalizeMediaPath(
                $filePath
            );

        return BASE_PATH
            . '/public/'
            . $relativePath;
    }

    /*
     * メディア情報を追加または更新
     */
    public function save(
        array $data,
        int $userId
    ): int {
        $pdo = Database::connect();

        $table =
            Database::table(
                'media'
            );

        $filePath =
            $this->normalizeMediaPath(
                (string)$data['file_path']
            );

        $existingId =
            $this->findIdByFilePath(
                $filePath
            );

        if ($existingId !== null) {
            $statement = $pdo->prepare("
                UPDATE {$table}
                SET title = :title,
                    description = :description,
                    original_name = :original_name,
                    file_name = :file_name,
                    mime_type = :mime_type,
                    file_size = :file_size,
                    file_type = :file_type,
                    user_id = :user_id,
                    updated_at = :updated_at
                WHERE id = :id
            ");

            $statement->execute([
                ':title' =>
                    $data['title'],

                ':description' =>
                    $data['description'],

                ':original_name' =>
                    $data['original_name'],

                ':file_name' =>
                    $data['file_name'],

                ':mime_type' =>
                    $data['mime_type'],

                ':file_size' =>
                    $data['file_size'],

                ':file_type' =>
                    $data['file_type'],

                ':user_id' =>
                    $userId,

                ':updated_at' =>
                    $data['updated_at'],

                ':id' =>
                    $existingId,
            ]);

            return $existingId;
        }

        $statement = $pdo->prepare("
            INSERT INTO {$table} (
                title,
                description,
                original_name,
                file_name,
                file_path,
                mime_type,
                file_size,
                file_type,
                user_id,
                created_at,
                updated_at
            )
            VALUES (
                :title,
                :description,
                :original_name,
                :file_name,
                :file_path,
                :mime_type,
                :file_size,
                :file_type,
                :user_id,
                :created_at,
                :updated_at
            )
        ");

        $statement->execute([
            ':title' =>
                $data['title'],

            ':description' =>
                $data['description'],

            ':original_name' =>
                $data['original_name'],

            ':file_name' =>
                $data['file_name'],

            ':file_path' =>
                $filePath,

            ':mime_type' =>
                $data['mime_type'],

            ':file_size' =>
                $data['file_size'],

            ':file_type' =>
                $data['file_type'],

            ':user_id' =>
                $userId,

            ':created_at' =>
                $data['created_at'],

            ':updated_at' =>
                $data['updated_at'],
        ]);

        return (int)$pdo->lastInsertId();
    }

    /*
     * メディアパスからIDを取得
     */
    private function findIdByFilePath(
        string $filePath
    ): ?int {
        $pdo = Database::connect();

        $table =
            Database::table(
                'media'
            );

        $statement = $pdo->prepare("
            SELECT id
            FROM {$table}
            WHERE file_path = :file_path
            LIMIT 1
        ");

        $statement->execute([
            ':file_path' =>
                $filePath,
        ]);

        $id = $statement->fetchColumn();

        return $id !== false
            ? (int)$id
            : null;
    }

    /*
     * メディアファイルのパスを検証
     */
    private function normalizeMediaPath(
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

        if (
            $filePath === '' ||
            str_contains(
                $filePath,
                "\0"
            ) ||
            !str_starts_with(
                $filePath,
                'uploads/media/'
            )
        ) {
            throw new RuntimeException(
                'メディアファイルのパスが正しくありません。'
            );
        }

        $parts = explode(
            '/',
            $filePath
        );

        foreach ($parts as $part) {
            if (
                $part === '' ||
                $part === '.' ||
                $part === '..'
            ) {
                throw new RuntimeException(
                    'メディアファイルのパスが正しくありません。'
                );
            }
        }

        return implode(
            '/',
            $parts
        );
    }
}