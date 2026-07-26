<?php

namespace NexaPressImportExport\Import;

use DateTimeImmutable;
use NexaPressImportExport\Repository\ContentRepository;
use NexaPressImportExport\Support\Json;
use RuntimeException;

class ContentImporter
{
    /*
     * コンテンツを取り込む
     */
    public function import(
        string $packageDirectory,
        int $userId,
        array $limits
    ): array {
        if ($userId <= 0) {
            throw new RuntimeException(
                '投稿の所有者を確認できません。'
            );
        }

        $maxJsonSize = (int)(
            $limits['max_json_size']
            ?? 20971520
        );

        $dataDirectory =
            rtrim(
                $packageDirectory,
                '/\\'
            )
            . DIRECTORY_SEPARATOR
            . 'data';

        $categoryDocument =
            Json::read(
                $dataDirectory
                . '/categories.json',
                $maxJsonSize
            );

        $postDocument =
            Json::read(
                $dataDirectory
                . '/posts.json',
                $maxJsonSize
            );

        $pageDocument =
            Json::read(
                $dataDirectory
                . '/pages.json',
                $maxJsonSize
            );

        $categories =
            $this->items(
                $categoryDocument,
                'カテゴリー'
            );

        $posts =
            $this->items(
                $postDocument,
                '投稿'
            );

        $pages =
            $this->items(
                $pageDocument,
                '固定ページ'
            );

        $repository =
            new ContentRepository();

        /*
         * 旧カテゴリーIDと
         * 新カテゴリーIDの対応表
         */
        $categoryIdMap = [];
        $categorySlugs = [];

        foreach ($categories as $category) {
            $oldId = (int)(
                $category['id']
                ?? 0
            );

            $name =
                $this->requiredString(
                    $category['name']
                    ?? '',
                    'カテゴリー名'
                );

            $slug =
                $this->requiredString(
                    $category['slug']
                    ?? '',
                    'カテゴリースラッグ'
                );

            if ($oldId <= 0) {
                throw new RuntimeException(
                    'カテゴリーIDが正しくありません。'
                );
            }

            if (
                isset(
                    $categorySlugs[$slug]
                )
            ) {
                throw new RuntimeException(
                    '重複したカテゴリースラッグがあります。'
                );
            }

            $categorySlugs[$slug] = true;

            $createdAt =
                $this->date(
                    $category['created_at']
                    ?? null
                );

            $updatedAt =
                $this->date(
                    $category['updated_at']
                    ?? null,
                    $createdAt
                );

            $newId =
                $repository->saveCategory([
                    'name' => $name,
                    'slug' => $slug,
                    'created_at' =>
                        $createdAt,

                    'updated_at' =>
                        $updatedAt,
                ]);

            $categoryIdMap[$oldId] =
                $newId;
        }

        /*
         * 投稿を取り込む
         */
        $postCount = 0;
        $postSlugs = [];

        foreach ($posts as $post) {
            $title =
                $this->requiredString(
                    $post['title']
                    ?? '',
                    '投稿タイトル'
                );

            $slug =
                $this->requiredString(
                    $post['slug']
                    ?? '',
                    '投稿スラッグ'
                );

            if (
                isset(
                    $postSlugs[$slug]
                )
            ) {
                throw new RuntimeException(
                    '重複した投稿スラッグがあります。'
                );
            }

            $postSlugs[$slug] = true;

            $status =
                $this->status(
                    $post['status']
                    ?? 'draft'
                );

            $oldCategoryId =
                $post['category_id']
                ?? null;

            $categoryId = null;

            if (
                $oldCategoryId !== null &&
                (int)$oldCategoryId > 0
            ) {
                $categoryId =
                    $categoryIdMap[
                        (int)$oldCategoryId
                    ]
                    ?? null;
            }

            $createdAt =
                $this->date(
                    $post['created_at']
                    ?? null
                );

            $updatedAt =
                $this->date(
                    $post['updated_at']
                    ?? null,
                    $createdAt
                );

            $publishedAt = null;

            if ($status === 'published') {
                $publishedAt =
                    $this->date(
                        $post['published_at']
                        ?? null,
                        $createdAt
                    );
            }

            $repository->savePost(
                [
                    'title' => $title,
                    'slug' => $slug,

                    'content' =>
                        (string)(
                            $post['content']
                            ?? ''
                        ),

                    'status' => $status,

                    'published_at' =>
                        $publishedAt,

                    'created_at' =>
                        $createdAt,

                    'updated_at' =>
                        $updatedAt,
                ],
                $userId,
                $categoryId
            );

            $postCount++;
        }

        /*
         * 固定ページを取り込む
         */
        $pageCount = 0;
        $pageSlugs = [];

        foreach ($pages as $page) {
            $title =
                $this->requiredString(
                    $page['title']
                    ?? '',
                    '固定ページタイトル'
                );

            $slug =
                $this->requiredString(
                    $page['slug']
                    ?? '',
                    '固定ページスラッグ'
                );

            if (
                isset(
                    $pageSlugs[$slug]
                )
            ) {
                throw new RuntimeException(
                    '重複した固定ページスラッグがあります。'
                );
            }

            $pageSlugs[$slug] = true;

            $status =
                $this->status(
                    $page['status']
                    ?? 'draft'
                );

            $createdAt =
                $this->date(
                    $page['created_at']
                    ?? null
                );

            $updatedAt =
                $this->date(
                    $page['updated_at']
                    ?? null,
                    $createdAt
                );

            $publishedAt = null;

            if ($status === 'published') {
                $publishedAt =
                    $this->date(
                        $page['published_at']
                        ?? null,
                        $createdAt
                    );
            }

            $repository->savePage(
                [
                    'title' => $title,
                    'slug' => $slug,

                    'content' =>
                        (string)(
                            $page['content']
                            ?? ''
                        ),

                    'status' => $status,

                    'published_at' =>
                        $publishedAt,

                    'created_at' =>
                        $createdAt,

                    'updated_at' =>
                        $updatedAt,
                ],
                $userId
            );

            $pageCount++;
        }

        return [
            'categories' =>
                count($categories),

            'posts' =>
                $postCount,

            'pages' =>
                $pageCount,
        ];
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
        $value = trim(
            (string)$value
        );

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
                ?? date(
                    'Y-m-d H:i:s'
                );
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