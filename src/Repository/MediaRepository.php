<?php

namespace NexaPressImportExport\Repository;

use app\Core\Database;
use app\Models\Media;
use NexaPressImportExport\Support\SafePath;
use RuntimeException;

class MediaRepository
{
    /*
     * メディア情報を取得
     */
    public function all(): array
    {
        $table = Database::table('media');
        $sql = "SELECT * FROM {$table} ORDER BY id ASC";
        if (self::supportsFolders()) {
            $folders = Database::table('media_folders');
            $sql = "SELECT m.*, f.name AS folder_name FROM {$table} m
                LEFT JOIN {$folders} f ON f.id = m.folder_id ORDER BY m.id ASC";
        }
        $statement = Database::connect()->query($sql);
        if ($statement === false) {
            throw new RuntimeException('メディア情報を取得できませんでした。');
        }
        return $statement->fetchAll();
    }

    public static function supportsFolders(): bool
    {
        return method_exists(Media::class, 'createFolder');
    }

    /*
     * 公開フォルダ内の元ファイルを取得
     */
    public function sourceFile(string $filePath): ?string
    {
        $path = $this->targetFile($filePath, false);
        return is_file($path) ? $path : null;
    }

    /*
     * インポート先の絶対パスを取得
     */
    public function targetFile(
        string $filePath,
        bool $createParents = true
    ): string {
        $relativePath =
            $this->normalizeMediaPath(
                $filePath
            );

        return SafePath::file(
            BASE_PATH,
            'public/' . $relativePath,
            $createParents
        );
    }

    /*
     * メディア情報を追加または更新
     */
    public function save(array $data, int $userId, bool $allowUpdate = true): int
    {
        $pdo = Database::connect();
        $table = Database::table('media');
        $filePath = $this->normalizeMediaPath($data['file_path']);
        $existingId = $this->findIdByFilePath($filePath);
        if ($existingId !== null && !$allowUpdate) {
            throw new RuntimeException('取り込み中にメディア保存先が重複しました。再度プレビューしてください。');
        }

        $values = [
            'title' => $data['title'],
            'description' => $data['description'],
            'original_name' => $data['original_name'],
            'file_name' => $data['file_name'],
            'mime_type' => $data['mime_type'],
            'file_size' => $data['file_size'],
            'file_type' => $data['file_type'],
            'user_id' => $userId,
            'updated_at' => $data['updated_at'],
        ];
        if (array_key_exists('folder_name', $data) && self::supportsFolders()) {
            $name = $data['folder_name'];
            $values['folder_id'] = $name === null ? null : Media::createFolder($name, true);
        }

        if ($existingId !== null) {
            $assignments = array_map(static fn(string $column): string => "$column = :$column", array_keys($values));
            $statement = $pdo->prepare("UPDATE {$table} SET " . implode(', ', $assignments) . ' WHERE id = :id');
            $statement->execute($values + ['id' => $existingId]);
            return $existingId;
        }

        $values += ['file_path' => $filePath, 'created_at' => $data['created_at']];
        $columns = array_keys($values);
        $statement = $pdo->prepare("INSERT INTO {$table} (" . implode(', ', $columns)
            . ') VALUES (:' . implode(', :', $columns) . ')');
        $statement->execute($values);
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
