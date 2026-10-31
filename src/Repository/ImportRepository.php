<?php

namespace NexaPressImportExport\Repository;

use app\Core\Database;
use PDO;
use RuntimeException;

class ImportRepository
{
    public const KEYS = ['categories' => 'slug', 'posts' => 'slug', 'pages' => 'slug', 'media' => 'file_path'];

    public function find(string $type, string $value): ?array
    {
        $table = $this->table($type);
        $key = self::KEYS[$type];
        $query = Database::connect()->prepare("SELECT * FROM {$table} WHERE {$key} = :value LIMIT 1");
        $query->execute([':value' => $value]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function lockTargets(array $targets): void
    {
        $pdo = Database::connect();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') { return; }
        foreach (self::KEYS as $type => $column) {
            $keys = array_values(array_unique($targets[$type] ?? []));
            sort($keys, SORT_STRING);
            foreach (array_chunk($keys, 500) as $chunk) {
                $table = $this->table($type);
                $query = $pdo->prepare("SELECT id FROM {$table} WHERE {$column} IN (" . implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY id FOR UPDATE');
                $query->execute($chunk);
                $query->fetchAll(PDO::FETCH_COLUMN);
            }
        }
    }

    public function restore(string $type, string $key, ?array $before): void
    {
        $table = $this->table($type);
        $current = $this->find($type, $key);
        if ($before === null) {
            if ($current !== null) {
                $query = Database::connect()->prepare("DELETE FROM {$table} WHERE id = :id");
                $query->execute([':id' => $current['id']]);
            }
            return;
        }
        if ($current !== null && (int)$current['id'] !== (int)$before['id']) {
            throw new RuntimeException('復元対象のIDが変更されています。');
        }
        if ($current === null) {
            $columns = [];
            $parameters = [];
            foreach ($before as $column => $value) {
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) { throw new RuntimeException('バックアップの列名が正しくありません。'); }
                $columns[] = '`' . $column . '`';
                $parameters[':' . $column] = $value;
            }
            $query = Database::connect()->prepare("INSERT INTO {$table} (" . implode(',', $columns) . ') VALUES (' . implode(',', array_keys($parameters)) . ')');
            $query->execute($parameters);
            return;
        }
        $assignments = [];
        $parameters = [':id' => $before['id']];
        foreach ($before as $column => $value) {
            if ($column === 'id') { continue; }
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
                throw new RuntimeException('バックアップの列名が正しくありません。');
            }
            $assignments[] = '`' . $column . '` = :' . $column;
            $parameters[':' . $column] = $value;
        }
        $query = Database::connect()->prepare("UPDATE {$table} SET " . implode(', ', $assignments) . ' WHERE id = :id');
        $query->execute($parameters);
    }

    public function assertCategoryReferences(array $before): void
    {
        $trackedIds = [];
        foreach (array_keys($before['posts'] ?? []) as $slug) {
            $post = $this->find('posts', $slug);
            if ($post !== null) { $trackedIds[] = (int)$post['id']; }
        }
        $posts = $this->table('posts');
        foreach ($before['categories'] ?? [] as $slug => $category) {
            if ($category !== null) { continue; }
            $current = $this->find('categories', $slug);
            if ($current === null) { continue; }
            $sql = "SELECT COUNT(*) FROM {$posts} WHERE category_id = :id";
            if ($trackedIds !== []) { $sql .= ' AND id NOT IN (' . implode(',', $trackedIds) . ')'; }
            $query = Database::connect()->prepare($sql);
            $query->execute([':id' => $current['id']]);
            if ((int)$query->fetchColumn() > 0) {
                throw new RuntimeException('追加されたカテゴリーが別の投稿から参照されているため復元できません。');
            }
        }
    }

    private function table(string $type): string
    {
        if (!isset(self::KEYS[$type])) { throw new RuntimeException('取り込み対象のテーブルが正しくありません。'); }
        return Database::table($type);
    }
}
