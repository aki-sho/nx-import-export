<?php

namespace NexaPressImportExport\Repository;

use app\Core\Database;
use RuntimeException;

class ContentRepository
{
    /*
     * カテゴリーを取得
     */
    public function categories(): array
    {
        return $this->allRows(
            'categories'
        );
    }

    /*
     * 投稿を取得
     */
    public function posts(): array
    {
        return $this->allRows(
            'posts'
        );
    }

    /*
     * 固定ページを取得
     */
    public function pages(): array
    {
        return $this->allRows(
            'pages'
        );
    }

    /*
     * カテゴリーを追加または更新
     */
    public function saveCategory(
        array $data
    ): int {
        $pdo = Database::connect();

        $table =
            Database::table(
                'categories'
            );

        $existingId =
            $this->findIdBySlug(
                'categories',
                (string)$data['slug']
            );

        if ($existingId !== null) {
            $statement = $pdo->prepare("
                UPDATE {$table}
                SET name = :name,
                    updated_at = :updated_at
                WHERE id = :id
            ");

            $statement->execute([
                ':name' =>
                    $data['name'],

                ':updated_at' =>
                    $data['updated_at'],

                ':id' =>
                    $existingId,
            ]);

            return $existingId;
        }

        $statement = $pdo->prepare("
            INSERT INTO {$table} (
                name,
                slug,
                created_at,
                updated_at
            )
            VALUES (
                :name,
                :slug,
                :created_at,
                :updated_at
            )
        ");

        $statement->execute([
            ':name' =>
                $data['name'],

            ':slug' =>
                $data['slug'],

            ':created_at' =>
                $data['created_at'],

            ':updated_at' =>
                $data['updated_at'],
        ]);

        return (int)$pdo->lastInsertId();
    }

    /*
     * 投稿を追加または更新
     */
    public function savePost(
        array $data,
        int $userId,
        ?int $categoryId
    ): int {
        $pdo = Database::connect();

        $table =
            Database::table(
                'posts'
            );

        $existingId =
            $this->findIdBySlug(
                'posts',
                (string)$data['slug']
            );

        if ($existingId !== null) {
            $statement = $pdo->prepare("
                UPDATE {$table}
                SET title = :title,
                    content = :content,
                    status = :status,
                    user_id = :user_id,
                    category_id = :category_id,
                    published_at = :published_at,
                    updated_at = :updated_at
                WHERE id = :id
            ");

            $statement->execute([
                ':title' =>
                    $data['title'],

                ':content' =>
                    $data['content'],

                ':status' =>
                    $data['status'],

                ':user_id' =>
                    $userId,

                ':category_id' =>
                    $categoryId,

                ':published_at' =>
                    $data['published_at'],

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
                slug,
                content,
                status,
                user_id,
                category_id,
                published_at,
                created_at,
                updated_at
            )
            VALUES (
                :title,
                :slug,
                :content,
                :status,
                :user_id,
                :category_id,
                :published_at,
                :created_at,
                :updated_at
            )
        ");

        $statement->execute([
            ':title' =>
                $data['title'],

            ':slug' =>
                $data['slug'],

            ':content' =>
                $data['content'],

            ':status' =>
                $data['status'],

            ':user_id' =>
                $userId,

            ':category_id' =>
                $categoryId,

            ':published_at' =>
                $data['published_at'],

            ':created_at' =>
                $data['created_at'],

            ':updated_at' =>
                $data['updated_at'],
        ]);

        return (int)$pdo->lastInsertId();
    }

    /*
     * 固定ページを追加または更新
     */
    public function savePage(
        array $data,
        int $userId
    ): int {
        $pdo = Database::connect();

        $table =
            Database::table(
                'pages'
            );

        $existingId =
            $this->findIdBySlug(
                'pages',
                (string)$data['slug']
            );

        if ($existingId !== null) {
            $statement = $pdo->prepare("
                UPDATE {$table}
                SET title = :title,
                    content = :content,
                    status = :status,
                    user_id = :user_id,
                    published_at = :published_at,
                    updated_at = :updated_at
                WHERE id = :id
            ");

            $statement->execute([
                ':title' =>
                    $data['title'],

                ':content' =>
                    $data['content'],

                ':status' =>
                    $data['status'],

                ':user_id' =>
                    $userId,

                ':published_at' =>
                    $data['published_at'],

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
                slug,
                content,
                status,
                user_id,
                published_at,
                created_at,
                updated_at
            )
            VALUES (
                :title,
                :slug,
                :content,
                :status,
                :user_id,
                :published_at,
                :created_at,
                :updated_at
            )
        ");

        $statement->execute([
            ':title' =>
                $data['title'],

            ':slug' =>
                $data['slug'],

            ':content' =>
                $data['content'],

            ':status' =>
                $data['status'],

            ':user_id' =>
                $userId,

            ':published_at' =>
                $data['published_at'],

            ':created_at' =>
                $data['created_at'],

            ':updated_at' =>
                $data['updated_at'],
        ]);

        return (int)$pdo->lastInsertId();
    }

    /*
     * テーブル内の全データを取得
     */
    private function allRows(
        string $tableName
    ): array {
        $pdo = Database::connect();

        $table =
            Database::table(
                $tableName
            );

        $statement = $pdo->query("
            SELECT *
            FROM {$table}
            ORDER BY id ASC
        ");

        if ($statement === false) {
            throw new RuntimeException(
                'コンテンツを取得できませんでした。'
            );
        }

        return $statement->fetchAll();
    }

    /*
     * スラッグからIDを取得
     */
    private function findIdBySlug(
        string $tableName,
        string $slug
    ): ?int {
        $pdo = Database::connect();

        $table =
            Database::table(
                $tableName
            );

        $statement = $pdo->prepare("
            SELECT id
            FROM {$table}
            WHERE slug = :slug
            LIMIT 1
        ");

        $statement->execute([
            ':slug' => $slug,
        ]);

        $id = $statement->fetchColumn();

        return $id !== false
            ? (int)$id
            : null;
    }
}