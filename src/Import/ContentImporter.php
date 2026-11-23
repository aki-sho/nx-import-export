<?php

namespace NexaPressImportExport\Import;

use app\Core\ArticleContent;
use DateTimeImmutable;
use InvalidArgumentException;
use NexaPressImportExport\Support\CsvContent;
use NexaPressImportExport\Repository\ContentRepository;
use NexaPressImportExport\Repository\ImportRepository;
use NexaPressImportExport\Support\Json;
use NexaPressImportExport\Validation\MediaValidator;
use RuntimeException;

class ContentImporter
{
    /*
     * コンテンツを取り込む
     */
    private const MAX_EXCERPT_BYTES = 65535;
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
        $types = ['categories' => 'カテゴリー', 'posts' => '投稿', 'pages' => '固定ページ'];
        $documents = [];
        foreach ($types as $type => $label) {
            $documents[$type] = $this->readItems($packageDirectory, $type, $label, $limits);
        }
        return $this->prepareDocuments($documents, $userId, $limits, $options, $rewriter, false);
    }

    public function prepareCsv(
        string $path,
        int $userId,
        array $limits,
        array $options,
        UrlRewriter $rewriter
    ): array {
        return $this->prepareDocuments(CsvContent::read($path, $limits), $userId, $limits, $options, $rewriter, true);
    }

    public function prepareCategories(
        string $source,
        int $userId,
        array $limits,
        array $options,
        bool $fromCsv
    ): array {
        $categories = $fromCsv ? CsvContent::readCategories($source, $limits)
            : $this->readItems($source, 'categories', 'カテゴリー', $limits);
        return $this->prepareDocuments(
            ['categories' => $categories, 'posts' => [], 'pages' => []], $userId, $limits, $options, null, false
        );
    }

    private function readItems(string $directory, string $type, string $label, array $limits): array
    {
        $document = Json::read(rtrim($directory, '/\\') . '/data/' . $type . '.json', (int)($limits['max_json_size'] ?? 20971520));
        return $this->items($document, $label);
    }

    private function prepareDocuments(
        array $documents,
        int $userId,
        array $limits,
        array $options,
        ?UrlRewriter $rewriter,
        bool $csv
    ): array {
        if ($userId <= 0) { throw new RuntimeException('投稿の所有者を確認できません。'); }
        $options = ImportOptions::normalize($options);
        $this->importedAt = $options['imported_at'];
        $rewriter = $rewriter ?? new UrlRewriter($options);
        $types = ['categories' => 'カテゴリー', 'posts' => '投稿', 'pages' => '固定ページ'];
        $maxItems = (int)($limits['max_content_items'] ?? 10000);
        if ($maxItems <= 0 || array_sum(array_map('count', $documents)) > $maxItems) {
            throw new RuntimeException('コンテンツの合計件数が制限を超えています。');
        }
        $resolver = new ConflictResolver();
        $lookup = new ImportRepository();
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
        $existingCategoryIds = [];
        foreach ($documents['categories'] as $category) {
            $oldId = (int)($category['id'] ?? 0);
            if ($oldId <= 0 || isset($categoryIds[$oldId])) { throw new RuntimeException('カテゴリーIDが正しくないか重複しています。'); }
            $categoryIds[$oldId] = true;
            $slug = $this->requiredString($category['slug'], 'カテゴリースラッグ');
            $entry = $resolver->resolve('categories', $slug, $csv ? 'skip' : $options['conflict']);
            $created = $this->date($category['created_at'] ?? null);
            if ($csv && $entry['action'] === 'skip') {
                $entry['existing_id'] = (int)($lookup->find('categories', $entry['key'])['id'] ?? 0);
                $existingCategoryIds[$oldId] = $entry['existing_id'];
            }
            $entry['source_id'] = $oldId;
            $entry['data'] = [
                'name' => $this->requiredString($category['name'] ?? '', 'カテゴリー名', 100),
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
                $reserved = ['admin', 'install', 'public', 'posts', 'search', 'category'];
                if ($type === 'pages') {
                    $reserved = array_merge($reserved, ['page', 'post']);
                }
                if ($csv && $entry['action'] === 'add' && in_array(strtolower($entry['key']), $reserved, true)) {
                    throw new RuntimeException('CSVの' . $item['csv_row'] . '行目のスラッグはシステムで使用されています。');
                }
                $existing = $csv && $entry['action'] === 'update' ? $lookup->find($type, $entry['key']) : null;
                if ($existing !== null) {
                    $entry['replaces_published'] = $existing['status'] === 'published' && empty($existing['deleted_at']);
                    if ((int)($existing['user_id'] ?? 0) > 0) {
                        $entry['owner_id'] = (int)$existing['user_id'];
                    }
                }
                $created = $this->date($existing['created_at'] ?? $item['created_at'] ?? null);
                $status = $this->status($existing['status'] ?? $item['status'] ?? 'draft');
                $content = $item['content'] ?? '';
                $label = isset($item['csv_row']) && $csv ? 'CSVの' . (int)$item['csv_row'] . '行目。' : '';
                try {
                    ArticleContent::validate($content);
                    if ($entry['action'] !== 'skip') {
                        $content = ArticleContent::sanitize($rewriter->rewrite($content, $types[$type] . '：' . $title));
                    }
                } catch (InvalidArgumentException $exception) {
                    throw new RuntimeException($label . $exception->getMessage(), 0, $exception);
                }
                $entry['data'] = [
                    'title' => $title, 'slug' => $entry['key'], 'content' => $content,
                    'status' => $status, 'created_at' => $created,
                    'updated_at' => $this->date($item['updated_at'] ?? null, $csv ? $this->importedAt : $created),
                    'published_at' => $status === 'published'
                        ? $this->date($existing['published_at'] ?? $item['published_at'] ?? null, $created) : null,
                ];
                if ($type === 'posts') {
                    $categoryId = (int)($item['category_id'] ?? 0);
                    if ($categoryId > 0 && !isset($categoryIds[$categoryId])) {
                        throw new RuntimeException('投稿から参照されたカテゴリーがパッケージにありません。');
                    }
                    $entry['category_id'] = $categoryId > 0 ? $categoryId : null;
                    if ($existing !== null) {
                        $existingCategory = (int)($existing['category_id'] ?? 0);
                        if (array_key_exists('category_slug', $item)) {
                            $entry['category_changed'] = $categoryId > 0
                                ? ($existingCategoryIds[$categoryId] ?? -1) !== $existingCategory
                                : $existingCategory !== 0;
                        } elseif ($existingCategory > 0) {
                            $entry['retain_category_id'] = $existingCategory;
                        }
                    }
                    if (array_key_exists('excerpt', $item)) {
                        $excerpt = $item['excerpt'] ?? '';
                        if (!is_string($excerpt) || strlen($excerpt) > self::MAX_EXCERPT_BYTES) {
                            throw new RuntimeException('投稿の抜粋の形式または長さが正しくありません。');
                        }
                        $entry['data']['excerpt'] = $excerpt;
                    }
                    if (array_key_exists('featured_media_path', $item)) {
                        $path = $item['featured_media_path'];
                        if ($path !== null && !is_string($path)) {
                            throw new RuntimeException('アイキャッチ画像の参照が正しくありません。');
                        }
                        $entry['data']['featured_media_path'] = $path === null ? null : $rewriter->mediaPath(MediaValidator::path($path));
                    }
                }
                $plan[$type][] = $entry;
            }
        }
        if ($csv) {
            $used = [];
            foreach ($plan['posts'] as $post) {
                if ($post['action'] !== 'skip' && $post['category_id'] !== null) {
                    $used[$post['category_id']] = true;
                }
            }
            $plan['categories'] = array_values(array_filter(
                $plan['categories'],
                static fn(array $entry): bool => isset($used[$entry['source_id']])
            ));
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
                if ($existing === null || (isset($entry['existing_id']) && (int)$existing['id'] !== $entry['existing_id'])) {
                    throw new RuntimeException('既存のカテゴリーが変更されています。');
                }
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
                $ownerId = $entry['owner_id'] ?? $userId;
                if ($type === 'posts') {
                    $categoryId = $entry['category_id'] === null
                        ? ($entry['retain_category_id'] ?? null)
                        : $categoryIdMap[$entry['category_id']];
                    $data = $entry['data'];
                    if (array_key_exists('featured_media_path', $data)) {
                        $path = $data['featured_media_path'];
                        if ($path === null) {
                            $data['featured_media_id'] = null;
                        } else {
                            $media = $lookup->find('media', $path);
                            if (($media['file_type'] ?? '') === 'image') {
                                $data['featured_media_id'] = (int)$media['id'];
                            }
                        }
                    }
                    $repository->savePost($data, $ownerId, $categoryId, $entry['action'] === 'update');
                } else { $repository->savePage($entry['data'], $ownerId, $entry['action'] === 'update'); }
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
        string $label,
        int $limit = 255
    ): string {
        if (!is_string($value)) { throw new RuntimeException($label . 'は文字列で指定してください。'); }
        $value = trim($value);

        if ($value === '') {
            throw new RuntimeException(
                $label
                . 'が入力されていません。'
            );
        }

        if (!preg_match('/\A.{1,' . $limit . '}\z/us', $value)) {
            throw new RuntimeException($label . 'は' . $limit . '文字以内で指定してください。');
        }

        return $value;
    }

    /*
     * 公開状態を確認
     */
    private function status(
        mixed $value
    ): string {
        $status = is_string($value) ? trim($value) : '';

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
        if ($value !== null && !is_string($value)) {
            throw new RuntimeException('コンテンツの日時が正しくありません。');
        }
        $value = trim($value ?? '');

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
