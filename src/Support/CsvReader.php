<?php

namespace NexaPressImportExport\Support;

use Generator;
use RuntimeException;

final class CsvReader
{
    public static function rows(string $path, int $maxBytes, int $maxRows, int $maxColumns): Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('CSVファイルを開けませんでした。');
        }

        try {
            $size = fstat($handle)['size'] ?? 0;
            if ($size <= 0 || $size > $maxBytes) {
                throw new RuntimeException('CSVファイルは' . number_format($maxBytes) . 'バイト以内です。');
            }

            $bytesRead = 3;
            if (fread($handle, 3) !== "\xEF\xBB\xBF") {
                rewind($handle);
                $bytesRead = 0;
            }

            $row = [];
            $field = '';
            $state = 'plain';
            $skipLineFeed = false;
            $rowNumber = 1;
            while (!feof($handle)) {
                $chunk = fread($handle, 65536);
                if ($chunk === false) {
                    throw new RuntimeException('CSVファイルを読み込めませんでした。');
                }
                $length = strlen($chunk);
                $bytesRead += $length;
                if ($bytesRead > $maxBytes) {
                    throw new RuntimeException('CSVファイルは' . number_format($maxBytes) . 'バイト以内です。');
                }

                for ($index = 0; $index < $length;) {
                    $char = $chunk[$index];
                    if ($skipLineFeed) {
                        $skipLineFeed = false;
                        if ($char === "\n") {
                            $index++;
                            continue;
                        }
                    }
                    if ($state === 'quoted') {
                        $span = strcspn($chunk, '"', $index);
                        $field .= substr($chunk, $index, $span);
                        $index += $span;
                        if ($index < $length) {
                            $state = 'closed';
                            $index++;
                        }
                        continue;
                    }
                    if ($char === '"') {
                        if ($state === 'closed') {
                            $field .= '"';
                        } elseif ($field !== '') {
                            throw new RuntimeException("CSVの{$rowNumber}行目の引用符が正しくありません。");
                        }
                        $state = 'quoted';
                        $index++;
                        continue;
                    }
                    if ($char === ',' || $char === "\r" || $char === "\n") {
                        $row[] = $field;
                        $field = '';
                        $state = 'plain';
                        $index++;
                        if ($char === ',') {
                            if (count($row) >= $maxColumns) {
                                throw new RuntimeException("CSVの{$rowNumber}行目の列数が上限を超えています。");
                            }
                            continue;
                        }
                        if ($rowNumber > $maxRows) {
                            throw new RuntimeException('CSVの行数が上限を超えています。');
                        }
                        yield $rowNumber++ => $row;
                        $row = [];
                        $skipLineFeed = $char === "\r";
                        continue;
                    }
                    if ($state === 'closed') {
                        throw new RuntimeException("CSVの{$rowNumber}行目の引用符の後に余分な文字があります。");
                    }
                    $span = strcspn($chunk, ",\r\n\"", $index);
                    $field .= substr($chunk, $index, $span);
                    $index += $span;
                }
            }
            if ($state === 'quoted') {
                throw new RuntimeException("CSVの{$rowNumber}行目で引用符が閉じられていません。");
            }
            if ($row !== [] || $field !== '' || $state === 'closed') {
                if ($rowNumber > $maxRows) {
                    throw new RuntimeException('CSVの行数が上限を超えています。');
                }
                $row[] = $field;
                yield $rowNumber => $row;
            }
        } finally {
            fclose($handle);
        }
    }
}
