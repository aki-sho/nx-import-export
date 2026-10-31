<?php

namespace NexaPressImportExport\Import;

use DateTimeImmutable;
use RuntimeException;

class ImportOptions
{
    public static function normalize(array $input): array
    {
        $conflict = $input['conflict'] ?? 'overwrite';
        if (!in_array($conflict, ['overwrite', 'skip', 'rename'], true)) {
            throw new RuntimeException('重複時の処理が正しくありません。');
        }
        $from = self::baseUrl($input['url_from'] ?? '');
        $to = self::baseUrl($input['url_to'] ?? '');
        if (($from === '') !== ($to === '')) {
            throw new RuntimeException('URL置換を行う場合は、移行元と移行先の両方を指定してください。');
        }
        $now = $input['imported_at'] ?? date('Y-m-d H:i:s');
        $date = is_string($now) ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $now) : false;
        if (!$date || $date->format('Y-m-d H:i:s') !== $now) {
            throw new RuntimeException('取り込み日時が正しくありません。');
        }
        return [
            'conflict' => $conflict,
            'url_from' => $from,
            'url_to' => $to,
            'import_debug' => ($input['import_debug'] ?? false) === true,
            'imported_at' => $now,
        ];
    }

    private static function baseUrl(mixed $value): string
    {
        if (!is_string($value)) {
            throw new RuntimeException('置換するURLが正しくありません。');
        }
        $value = rtrim(trim($value), '/');
        if ($value === '') { return ''; }
        $parts = parse_url($value);
        if (
            strlen($value) > 2048 || !filter_var($value, FILTER_VALIDATE_URL) ||
            !is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) ||
            preg_match('/[\x00-\x20<>"\'\\\\]/', $value)
        ) {
            throw new RuntimeException('置換URLには認証情報・クエリ・フラグメントを含まないhttp/httpsのURLを指定してください。');
        }
        return $value;
    }
}
