<?php

namespace NexaPressImportExport\Import;

use NexaPressImportExport\Repository\ImportRepository;
use NexaPressImportExport\Repository\MediaRepository;
use RuntimeException;

class ConflictResolver
{
    private ImportRepository $repository;
    private array $reserved = [];
    private array $inputKeys = [];

    public function __construct() { $this->repository = new ImportRepository(); }

    public function reserveInput(string $type, array $keys): void
    {
        foreach ($keys as $key) { $this->inputKeys[$type][strtolower($key)] = true; }
    }

    public function resolve(string $type, string $key, string $policy): array
    {
        $exists = $this->exists($type, $key);
        $action = $exists ? ($policy === 'skip' ? 'skip' : 'update') : 'add';
        $target = $key;
        $record = $this->repository->find($type, $key);
        if ($record !== null && $policy !== 'rename') {
            $target = (string)$record[ImportRepository::KEYS[$type]];
        }
        if ($exists && $policy === 'rename') {
            for ($index = 2; $index <= 10002; $index++) {
                if ($type === 'media') {
                    $base = pathinfo($key, PATHINFO_FILENAME);
                    $extension = '.' . pathinfo($key, PATHINFO_EXTENSION);
                } else { $base = $key; $extension = ''; }
                preg_match('/^.{1,180}/us', $base, $match);
                $name = ($match[0] ?? $base) . '-import-' . $index . $extension;
                $target = $type === 'media' ? dirname($key) . '/' . $name : $name;
                if (!$this->exists($type, $target) && !isset($this->inputKeys[$type][strtolower($target)])) { break; }
            }
            if ($index > 10002) { throw new RuntimeException('別名の候補を確保できませんでした。'); }
            $action = 'add';
        }
        if (isset($this->reserved[$type][$target])) {
            throw new RuntimeException('取り込み先の識別子が重複しています。');
        }
        $this->reserved[$type][$target] = true;
        return ['source_key' => $key, 'key' => $target, 'action' => $action, 'conflict' => $exists];
    }

    private function exists(string $type, string $key): bool
    {
        if (isset($this->reserved[$type][$key]) || $this->repository->find($type, $key) !== null) { return true; }
        if ($type === 'media') {
            $path = (new MediaRepository())->targetFile($key, false);
            return is_file($path);
        }
        return false;
    }
}
