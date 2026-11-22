<?php

namespace NexaPressImportExport\Repository;

use app\Core\Database;
use PDO;
use RuntimeException;

class ImportRepository
{
    public const KEYS = [
        'categories' => 'slug', 'posts' => 'slug', 'pages' => 'slug', 'media' => 'file_path', 'media_folders' => 'name',
    ];

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

    public function assertReferences(array $before): void
    {
        $this->assertNoExternalReferences($before, 'categories', 'posts', 'category_id',
            '追加されたカテゴリーが別の投稿から参照されているため復元できません。');
        $this->assertNoExternalReferences($before, 'media', 'posts', 'featured_media_id',
            '追加されたメディアが別の投稿のアイキャッチに使われているため復元できません。');
        $this->assertNoExternalReferences($before, 'media_folders', 'media', 'folder_id',
            '追加されたフォルダが別のメディアに使われているため復元できません。');
    }

    private function assertNoExternalReferences(
        array $before,
        string $type,
        string $dependentType,
        string $reference,
        string $message
    ): void {
        $added = array_keys(array_filter($before[$type] ?? [], static fn($row): bool => $row === null));
        if ($added === []) {
            return;
        }
        $trackedIds = [];
        foreach (array_keys($before[$dependentType]) as $key) {
            $record = $this->find($dependentType, (string)$key);
            if ($record !== null) {
                $trackedIds[] = (int)$record['id'];
            }
        }
        $table = $this->table($dependentType);
        $sql = "SELECT 1 FROM {$table} WHERE {$reference} = :id";
        if ($trackedIds !== []) {
            $sql .= ' AND id NOT IN (' . implode(',', $trackedIds) . ')';
        }
        $query = Database::connect()->prepare($sql . ' LIMIT 1');
        foreach ($added as $key) {
            $current = $this->find($type, (string)$key);
            if ($current === null) {
                continue;
            }
            $query->execute([':id' => $current['id']]);
            if ($query->fetchColumn() !== false) {
                throw new RuntimeException($message);
            }
        }
    }

    private function table(string $type): string
    {
        if (!isset(self::KEYS[$type])) { throw new RuntimeException('取り込み対象のテーブルが正しくありません。'); }
        return Database::table($type);
    }
}
