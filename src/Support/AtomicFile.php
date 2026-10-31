<?php

namespace NexaPressImportExport\Support;

use RuntimeException;

class AtomicFile
{
    public static function copy(string $source, string $base, string $relative, ?string $expectedHash = null, int $maxBytes = 524288000): void
    {
        $target = SafePath::file($base, $relative);
        $temporary = $target . '.nx-copy-' . bin2hex(random_bytes(8));
        $input = fopen($source, 'rb');
        if ($input === false) { throw new RuntimeException('コピー元のファイルを開けませんでした。'); }
        $output = fopen($temporary, 'xb');
        if ($output === false) { fclose($input); throw new RuntimeException('コピー先の一時ファイルを作成できませんでした。'); }
        $size = 0;
        try {
            while (!feof($input)) {
                $chunk = fread($input, 65536);
                if ($chunk === false || ($chunk === '' && !feof($input))) { throw new RuntimeException('ファイルを読み込めませんでした。'); }
                if (strlen($chunk) > $maxBytes - $size) { throw new RuntimeException('コピーするファイルの容量が制限を超えています。'); }
                $offset = 0;
                while ($offset < strlen($chunk)) {
                    $written = fwrite($output, substr($chunk, $offset));
                    if ($written === false || $written === 0) { throw new RuntimeException('ファイルを書き込めませんでした。'); }
                    $offset += $written;
                }
                $size += strlen($chunk);
            }
            if (!fflush($output)) { throw new RuntimeException('ファイルの書き込みを完了できませんでした。'); }
        } catch (\Throwable $exception) {
            fclose($input); fclose($output); @unlink($temporary);
            throw $exception;
        }
        fclose($input); fclose($output);
        if ($expectedHash !== null && !hash_equals($expectedHash, (string)hash_file('sha256', $temporary))) {
            @unlink($temporary);
            throw new RuntimeException('コピー中にファイルが変更されました。');
        }
        if (DIRECTORY_SEPARATOR !== '\\') { chmod($temporary, 0600); }
        if (!rename($temporary, $target)) { @unlink($temporary); throw new RuntimeException('ファイルを配置できませんでした。'); }
    }
}
