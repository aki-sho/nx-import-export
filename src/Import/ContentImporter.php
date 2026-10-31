<?php

namespace NexaPressImportExport\Import;

use DateTimeImmutable;
use NexaPressImportExport\Repository\ContentRepository;
use NexaPressImportExport\Repository\ImportRepository;
use NexaPressImportExport\Support\Json;
use RuntimeException;

class ContentImporter
{
    /*
     * コンテンツを取り込む
     */
    private string $importedAt = '';

    public function import(
        string $packageDirectory,
        int $userId,
        array $limits,
        array $options = []
    ): array {
        return $this->apply($this->prepare($packageDirectory, $userId, $limits, $options), $userId);
    }

    /*
     * 検証と実行計画の作成だけを行う。DB・公開ファイルには書き込まない。
     */
    public function prepare(
        string $packageDirectory,
        int $userId,
        array $limits,
        array $options = [],
        ?UrlRewriter $rewriter = null
    ): array {
        if ($userId <= 0) { throw new RuntimeException('投稿の所有者を確認できません。'); }
        $options = ImportOptions::normalize($options);
        $this->importedAt = $options['imported_at'];
        $rewriter = $rewriter ?? new UrlRewriter($options);
        $types = ['categories' => 'カテゴリー', 'posts' => '投稿', 'pages' => '固定ページ'];
        $documents = [];
        foreach ($types as $type => $label) {
            $document = Json::read(rtrim($packageDirectory, '/\\') . '/data/' . $type . '.json', (int)($limits['max_json_size'] ?? 20971520));
            $documents[$type] = $this->items($document, $label);
        }
        $maxItems = (int)($limits['max_content_items'] ?? 10000);
        if ($maxItems <= 0 || array_sum(array_map('count', $documents)) > $maxItems) {
            throw new RuntimeException('コンテンツの合計件数が制限を超えています。');
        }
        $resolver = new ConflictResolver();
        foreach ($types as $type => $label) {
            $slugs = [];
            foreach ($documents[$type] as $item) {
                $slug = $this->requiredString($item['slug'] ?? '', $label . 'スラッグ');
                $folded = strtolower($slug);
                if (isset($slugs[$folded])) { throw new RuntimeException('重複した' . $label . 'スラッグがあります。'); }
                $slugs[$folded] = $slug;
            }
            $resolver->reserveInput($type, array_values($slugs));
        }
        $plan = ['categories' => [], 'posts' => [], 'pages' => []];
        $categoryIds = [];
        foreach ($documents['categories'] as $category) {
            $oldId = (int)($category['id'] ?? 0);
            if ($oldId <= 0 || isset($categoryIds[$oldId])) { throw new RuntimeException('カテゴリーIDが正しくないか重複しています。'); }
            $categoryIds[$oldId] = true;
            $slug = $this->requiredString($category['slug'], 'カテゴリースラッグ');
            $entry = $resolver->resolve('categories', $slug, $options['conflict']);
            $created = $this->date($category['created_at'] ?? null);
            $entry['source_id'] = $oldId;
            $entry['data'] = [
                'name' => $this->requiredString($category['name'] ?? '', 'カテゴリー名'),
                'slug' => $entry['key'],
                'created_at' => $created,
                'updated_at' => $this->date($category['updated_at'] ?? null, $created),
            ];
            $plan['categories'][] = $entry;
        }
        foreach (['posts', 'pages'] as $type) {
            foreach ($documents[$type] as $item) {
                $title = $this->requiredString($item['title'] ?? '', $types[$type] . 'タイトル');
                $slug = $this->requiredString($item['slug'], $types[$type] . 'スラッグ');
                $entry = $resolver->resolve($type, $slug, $options['conflict']);
                $created = $this->date($item['created_at'] ?? null);
                $status = $this->status($item['status'] ?? 'draft');
                $content = $item['content'] ?? '';
                if (!is_string($content)) { throw new RuntimeException('本文は文字列で指定してください。'); }
                if ($entry['action'] !== 'skip') { $content = $rewriter->rewrite($content, $types[$type] . '：' . $title); }
                $entry['data'] = [
                    'title' => $title, 'slug' => $entry['key'], 'content' => $content,
                    'status' => $status, 'created_at' => $created,
                    'updated_at' => $this->date($item['updated_at'] ?? null, $created),
                    'published_at' => $status === 'published' ? $this->date($item['published_at'] ?? null, $created) : null,
                ];
                if ($type === 'posts') {
                    $categoryId = (int)($item['category_id'] ?? 0);
                    if ($categoryId > 0 && !isset($categoryIds[$categoryId])) {
                        throw new RuntimeException('投稿から参照されたカテゴリーがパッケージにありません。');
                    }
                    $entry['category_id'] = $categoryId > 0 ? $categoryId : null;
                }
                $plan[$type][] = $entry;
            }
        }
        return $plan;
    }

    public function apply(array $plan, int $userId): array
    {
        $repository = new ContentRepository();
        $lookup = new ImportRepository();
        $result = ['categories' => 0, 'posts' => 0, 'pages' => 0];
        $categoryIdMap = [];
        foreach ($plan['categories'] as $entry) {
            if ($entry['action'] === 'skip') {
                $existing = $lookup->find('categories', $entry['key']);
                if ($existing === null) { throw new RuntimeException('既存のカテゴリーが変更されています。'); }
                $id = (int)$existing['id'];
            } else {
                $id = $repository->saveCategory($entry['data'], $entry['action'] === 'update');
                $result['categories']++;
            }
            $categoryIdMap[$entry['source_id']] = $id;
        }
        foreach (['posts', 'pages'] as $type) {
            foreach ($plan[$type] as $entry) {
                if ($entry['action'] === 'skip') { continue; }
                if ($type === 'posts') {
                    $categoryId = $entry['category_id'] === null ? null : $categoryIdMap[$entry['category_id']];
                    $repository->savePost($entry['data'], $userId, $categoryId, $entry['action'] === 'update');
                } else { $repository->savePage($entry['data'], $userId, $entry['action'] === 'update'); }
                $result[$type]++;
            }
        }
        return $result;
    }

    /*
     * JSON内のitemsを取得
     */
    private function items(
        array $document,
        string $label
    ): array {
        $items =
            $document['items']
            ?? null;

        $count =
            $document['count']
            ?? null;

        if (
            !is_array($items) ||
            !is_numeric($count)
        ) {
            throw new RuntimeException(
                $label
                . 'データの形式が正しくありません。'
            );
        }

        if (
            (int)$count
            !== count($items)
        ) {
            throw new RuntimeException(
                $label
                . 'データの件数が一致しません。'
            );
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new RuntimeException(
                    $label
                    . 'データの内容が正しくありません。'
                );
            }
        }

        return $items;
    }

    /*
     * 必須文字列を確認
     */
    private function requiredString(
        mixed $value,
        string $label
    ): string {
        if (!is_string($value)) { throw new RuntimeException($label . 'は文字列で指定してください。'); }
        $value = trim($value);

        if ($value === '') {
            throw new RuntimeException(
                $label
                . 'が入力されていません。'
            );
        }

        return $value;
    }

    /*
     * 公開状態を確認
     */
    private function status(
        mixed $value
    ): string {
        $status = trim(
            (string)$value
        );

        if (
            !in_array(
                $status,
                [
                    'draft',
                    'published',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'コンテンツの公開状態が正しくありません。'
            );
        }

        return $status;
    }

    /*
     * DB保存用日時へ変換
     */
    private function date(
        mixed $value,
        ?string $fallback = null
    ): string {
        $value = trim(
            (string)$value
        );

        if ($value === '') {
            return $fallback
                ?? ($this->importedAt !== '' ? $this->importedAt : date('Y-m-d H:i:s'));
        }

        $date =
            DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                $value
            );

        $errors =
            DateTimeImmutable::getLastErrors();

        if (
            $date === false ||
            (
                is_array($errors) &&
                (
                    $errors['warning_count'] > 0 ||
                    $errors['error_count'] > 0
                )
            ) ||
            $date->format(
                'Y-m-d H:i:s'
            ) !== $value
        ) {
            throw new RuntimeException(
                'コンテンツの日時形式が正しくありません。'
            );
        }

        return $value;
    }
}
