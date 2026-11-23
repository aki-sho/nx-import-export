<?php

namespace NexaPressImportExport\Support;

use app\Core\ArticleContent;
use app\Core\RequestSize;
use NexaPressImportExport\Repository\ContentRepository;
use RuntimeException;
use Throwable;

final class CsvContent
{
    public const MODE = 'csv';
    private const REQUIRED = ['type', 'title', 'slug', 'content'];
    private const HEADERS = ['type', 'title', 'slug', 'content', 'excerpt', 'category_slug', 'category_name', 'nx_format'];
    private const FORMAT_VERSION = '1';
    private const TEXT_PREFIX = '~';

    public static function uploadLimit(array $limits): int
    {
        $limit = self::byteLimit($limits);
        $upload = RequestSize::phpLimit('upload_max_filesize');
        $post = RequestSize::phpLimit('post_max_size');
        if ($upload !== null) {
            $limit = min($limit, $upload);
        }
        if ($post !== null) {
            $limit = min($limit, max(0, $post - 16384));
        }
        return $limit;
    }

    public static function read(string $path, array $limits): array
    {
        $columns = [];
        $documents = ['categories' => [], 'posts' => [], 'pages' => []];
        $categories = [];
        foreach (CsvReader::rows($path, self::byteLimit($limits), self::rowLimit($limits) + 1, count(self::HEADERS)) as $number => $row) {
            if ($number === 1) {
                if (array_diff(self::REQUIRED, $row) !== [] || array_diff($row, self::HEADERS) !== []
                    || count(array_unique($row)) !== count($row)) {
                    throw new RuntimeException('CSVの見出しが正しくありません。type、title、slug、contentが必要です。');
                }
                $columns = $row;
                continue;
            }
            if (count($row) !== count($columns)) {
                throw new RuntimeException("CSVの{$number}行目の列数が見出しと一致しません。");
            }
            $item = array_combine($columns, $row);
            $encoded = isset($item['nx_format']);
            if ($encoded && $item['nx_format'] !== self::FORMAT_VERSION) {
                throw new RuntimeException("CSVの{$number}行目のNexaPress形式番号を確認してください。");
            }
            unset($item['nx_format']);
            foreach ($item as &$cell) {
                if (preg_match('//u', $cell) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $cell) === 1) {
                    throw new RuntimeException("CSVの{$number}行目をUTF-8の文字列で入力してください。");
                }
                if ($encoded && str_starts_with($cell, self::TEXT_PREFIX)) {
                    $original = substr($cell, 1);
                    if (self::encodeCell($original) === $cell) {
                        $cell = $original;
                    }
                }
            }
            unset($cell);

            $type = match ($item['type']) {
                'post' => 'posts',
                'page' => 'pages',
                default => throw new RuntimeException("CSVの{$number}行目のtypeにはpostまたはpageを指定してください。"),
            };
            if (strlen($item['content']) > ArticleContent::MAX_BYTES) {
                throw new RuntimeException("CSVの{$number}行目の本文は6,553,500バイト以内です。");
            }
            $item['slug'] = self::slug($item['slug'], $number);

            $categorySlug = trim($item['category_slug'] ?? '');
            $categoryName = trim($item['category_name'] ?? '');
            if ($type === 'pages' && ($categorySlug !== '' || $categoryName !== '' || ($item['excerpt'] ?? '') !== '')) {
                throw new RuntimeException("CSVの{$number}行目のカテゴリと抜粋は投稿で指定してください。");
            }
            if ($categorySlug === '' && $categoryName !== '') {
                throw new RuntimeException("CSVの{$number}行目にカテゴリのスラッグがありません。");
            }
            if ($categorySlug !== '') {
                $categorySlug = self::slug($categorySlug, $number);
                $categoryName = $categoryName !== '' ? $categoryName : $categorySlug;
                $key = strtr($categorySlug, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
                if (!isset($categories[$key])) {
                    $categories[$key] = ['id' => count($categories) + 1, 'slug' => $categorySlug, 'name' => $categoryName];
                } elseif ($categories[$key]['name'] !== $categoryName) {
                    throw new RuntimeException("CSVの{$number}行目のカテゴリ名が同じスラッグの他の行と異なります。");
                }
                $item['category_id'] = $categories[$key]['id'];
            }
            $item['csv_row'] = $number;
            $documents[$type][] = $item;
        }
        if ($documents['posts'] === [] && $documents['pages'] === []) {
            throw new RuntimeException('CSVに取り込む投稿・固定ページがありません。');
        }
        $documents['categories'] = array_values($categories);
        return $documents;
    }

    public static function export(string $scope, array $limits): array
    {
        $types = match ($scope) {
            'all' => ['post', 'page'],
            'post', 'page' => [$scope],
            default => throw new RuntimeException('CSVの出力対象を選択してください。'),
        };

        TempDirectory::cleanup((int)($limits['temporary_file_lifetime'] ?? 3600));
        $directory = TempDirectory::create();
        $name = 'nexapress-content-' . gmdate('Ymd-His') . '.csv';
        $path = $directory . '/' . $name;

        try {
            $handle = fopen($path, 'xb');
            if ($handle === false) {
                throw new RuntimeException('CSVファイルを作成できませんでした。');
            }
            try {
                if (fwrite($handle, "\xEF\xBB\xBF") !== 3 || fputcsv($handle, self::HEADERS, ',', '"', '') === false) {
                    throw new RuntimeException('CSVの見出しを保存できませんでした。');
                }
                $repository = new ContentRepository();
                $bytes = (int)ftell($handle);
                $count = 0;
                foreach ($types as $type) {
                    foreach ($repository->csvRows($type) as $row) {
                        $written = fputcsv($handle, array_map(static fn(string $cell): string => self::encodeCell($cell), [
                            $type, (string)$row['title'], (string)$row['slug'], (string)$row['content'],
                            (string)($row['excerpt'] ?? ''), (string)($row['category_slug'] ?? ''),
                            (string)($row['category_name'] ?? ''), self::FORMAT_VERSION,
                        ]), ',', '"', '');
                        if ($written === false) {
                            throw new RuntimeException('CSVの書き出しに失敗しました。');
                        }
                        $bytes += $written;
                        if (++$count > self::rowLimit($limits) || $bytes > self::byteLimit($limits)) {
                            throw new RuntimeException('CSVの出力上限を超えています。出力対象を絞るか、ZIP形式を使用してください。');
                        }
                    }
                }
                if (!fflush($handle)) {
                    throw new RuntimeException('CSVの保存を完了できませんでした。');
                }
            } finally {
                fclose($handle);
            }
            return ['path' => $path, 'name' => $name];
        } catch (Throwable $exception) {
            TempDirectory::remove($directory);
            throw $exception;
        }
    }

    private static function slug(string $value, int $number): string
    {
        $value = trim($value);
        if (preg_match('/\A[\p{L}\p{N}\p{M}_-]{1,255}\z/u', $value) !== 1) {
            throw new RuntimeException("CSVの{$number}行目のスラッグには文字・数字・ハイフン・アンダースコアを使用してください。");
        }
        return $value;
    }

    private static function encodeCell(string $value): string
    {
        // A visible, ordinary character survives spreadsheet CSV export. Doubling
        // the prefix preserves originals that already start with it.
        if (str_starts_with($value, self::TEXT_PREFIX)
            || preg_match('/\A(?:[\p{Cc}\p{Cf}]|[\s\p{Z}\p{Cc}\p{Cf}]*[=+\-@\x{FF1D}\x{FF0B}\x{FF0D}\x{FF20}\x{2212}\x{0027}])/u', $value) === 1) {
            return self::TEXT_PREFIX . $value;
        }
        return $value;
    }

    private static function byteLimit(array $limits): int
    {
        return (int)($limits['max_csv_size'] ?? 20971520);
    }

    private static function rowLimit(array $limits): int
    {
        return (int)($limits['max_csv_rows'] ?? 1000);
    }
}
