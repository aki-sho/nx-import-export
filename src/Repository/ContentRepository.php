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
        $posts = Database::table('posts');
        $media = Database::table('media');
        $statement = Database::connect()->query("
            SELECT p.*, m.file_path AS featured_media_path
            FROM {$posts} p
            LEFT JOIN {$media} m ON m.id = p.featured_media_id
            WHERE p.deleted_at IS NULL
            ORDER BY p.id ASC
        ");
        if ($statement === false) {
            throw new RuntimeException('投稿を取得できませんでした。');
        }
        return $statement->fetchAll();
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

    public function csvRows(string $type): \Generator
    {
        $pdo = Database::connect();
        $batchSize = $type === 'category' ? 100 : 4;
        if ($type === 'post') {
            $posts = Database::table('posts');
            $categories = Database::table('categories');
            $statement = $pdo->prepare("SELECT p.id, p.title, p.slug, p.content, p.excerpt,
                    c.slug AS category_slug, c.name AS category_name
                FROM {$posts} p LEFT JOIN {$categories} c ON p.category_id = c.id
                WHERE p.deleted_at IS NULL AND p.id > ? ORDER BY p.id LIMIT {$batchSize}");
        } elseif ($type === 'category') {
            $categories = Database::table('categories');
            $statement = $pdo->prepare("SELECT id, name, slug FROM {$categories}
                WHERE id > ? ORDER BY id LIMIT {$batchSize}");
        } else {
            $pages = Database::table('pages');
            $statement = $pdo->prepare("SELECT id, title, slug, content FROM {$pages}
                WHERE deleted_at IS NULL AND id > ? ORDER BY id LIMIT {$batchSize}");
        }
        $lastId = 0;
        do {
            $statement->execute([$lastId]);
            $count = 0;
            while (($row = $statement->fetch()) !== false) {
                $lastId = (int)$row['id'];
                $count++;
                yield $row;
            }
            $statement->closeCursor();
        } while ($count === $batchSize);
    }

    /*
     * カテゴリーを追加または更新
     */
    public function saveCategory(
        array $data,
        bool $allowUpdate = true
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

        if ($existingId !== null && !$allowUpdate) { throw new RuntimeException('取り込み中にカテゴリースラッグが重複しました。再度プレビューしてください。'); }
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
        ?int $categoryId,
        bool $allowUpdate = true
    ): int {
        $pdo = Database::connect();
        $table = Database::table('posts');
        $existingId = $this->findIdBySlug('posts', $data['slug']);
        $values = [
            'title' => $data['title'],
            'content' => $data['content'],
            'status' => $data['status'],
            'user_id' => $userId,
            'category_id' => $categoryId,
            'published_at' => $data['published_at'],
            'updated_at' => $data['updated_at'],
        ] + array_intersect_key($data, ['excerpt' => true, 'featured_media_id' => true]);

        if ($existingId !== null) {
            if (!$allowUpdate) {
                throw new RuntimeException('取り込み中に投稿スラッグが重複しました。再度プレビューしてください。');
            }
            $assignments = array_map(static fn(string $column): string => "$column = :$column", array_keys($values));
            $statement = $pdo->prepare("UPDATE {$table} SET " . implode(', ', $assignments) . ' WHERE id = :id');
            $statement->execute($values + ['id' => $existingId]);
            return $existingId;
        }

        $values += ['slug' => $data['slug'], 'created_at' => $data['created_at']];
        $columns = array_keys($values);
        $statement = $pdo->prepare("INSERT INTO {$table} (" . implode(', ', $columns)
            . ') VALUES (:' . implode(', :', $columns) . ')');
        $statement->execute($values);
        return (int)$pdo->lastInsertId();
    }

    /*
     * 固定ページを追加または更新
     */
    public function savePage(
        array $data,
        int $userId,
        bool $allowUpdate = true
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

        if ($existingId !== null && !$allowUpdate) { throw new RuntimeException('取り込み中に固定ページスラッグが重複しました。再度プレビューしてください。'); }
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
