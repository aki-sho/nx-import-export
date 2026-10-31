<?php

namespace NexaPressImportExport\Support;

use RuntimeException;

class OperationLock
{
    /** @var resource|null */
    private $handle;

    private function __construct($handle)
    {
        $this->handle = $handle;
    }

    public static function acquire(): self
    {
        $path = SafePath::file(BASE_PATH, 'storage/nx-import-export.lock');
        $handle = fopen($path, 'c+b');

        if ($handle === false) {
            throw new RuntimeException('処理用ロックを作成できませんでした。');
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('別のインポート・エクスポート処理が実行中です。完了後に再実行してください。');
        }

        return new self($handle);
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
