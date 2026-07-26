<?php

namespace NexaPressImportExport\Export;

use NexaPressImportExport\Repository\ContentRepository;
use NexaPressImportExport\Support\Json;
use RuntimeException;

class ContentExporter
{
    /*
     * コンテンツをJSONへ出力
     */
    public function export(
        string $packageDirectory
    ): array {
        $dataDirectory =
            rtrim(
                $packageDirectory,
                '/\\'
            )
            . DIRECTORY_SEPARATOR
            . 'data';

        if (
            !is_dir($dataDirectory) &&
            !mkdir(
                $dataDirectory,
                0755,
                true
            ) &&
            !is_dir($dataDirectory)
        ) {
            throw new RuntimeException(
                'コンテンツ保存フォルダを作成できませんでした。'
            );
        }

        $repository =
            new ContentRepository();

        $categories =
            $this->categories(
                $repository->categories()
            );

        $posts =
            $this->posts(
                $repository->posts()
            );

        $pages =
            $this->pages(
                $repository->pages()
            );

        /*
         * カテゴリーを書き出す
         */
        Json::write(
            $dataDirectory
            . '/categories.json',
            [
                'items' => $categories,
                'count' => count(
                    $categories
                ),
            ]
        );

        /*
         * 投稿を書き出す
         */
        Json::write(
            $dataDirectory
            . '/posts.json',
            [
                'items' => $posts,
                'count' => count(
                    $posts
                ),
            ]
        );

        /*
         * 固定ページを書き出す
         */
        Json::write(
            $dataDirectory
            . '/pages.json',
            [
                'items' => $pages,
                'count' => count(
                    $pages
                ),
            ]
        );

        return [
            'categories' =>
                count($categories),

            'posts' =>
                count($posts),

            'pages' =>
                count($pages),
        ];
    }

    /*
     * カテゴリーデータを整形
     */
    private function categories(
        array $rows
    ): array {
        $items = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = (int)(
                $row['id']
                ?? 0
            );

            $name = trim(
                (string)(
                    $row['name']
                    ?? ''
                )
            );

            $slug = trim(
                (string)(
                    $row['slug']
                    ?? ''
                )
            );

            if (
                $id <= 0 ||
                $name === '' ||
                $slug === ''
            ) {
                continue;
            }

            $items[] = [
                'id' => $id,
                'name' => $name,
                'slug' => $slug,

                'created_at' =>
                    $this->nullableString(
                        $row['created_at']
                        ?? null
                    ),

                'updated_at' =>
                    $this->nullableString(
                        $row['updated_at']
                        ?? null
                    ),
            ];
        }

        return $items;
    }

    /*
     * 投稿データを整形
     */
    private function posts(
        array $rows
    ): array {
        $items = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = (int)(
                $row['id']
                ?? 0
            );

            $title = trim(
                (string)(
                    $row['title']
                    ?? ''
                )
            );

            $slug = trim(
                (string)(
                    $row['slug']
                    ?? ''
                )
            );

            if (
                $id <= 0 ||
                $title === '' ||
                $slug === ''
            ) {
                continue;
            }

            $categoryId =
                $row['category_id']
                ?? null;

            $items[] = [
                'id' => $id,
                'title' => $title,
                'slug' => $slug,

                'content' =>
                    (string)(
                        $row['content']
                        ?? ''
                    ),

                'status' =>
                    (string)(
                        $row['status']
                        ?? 'draft'
                    ),

                /*
                 * ユーザー情報は出力しない
                 */
                'category_id' =>
                    $categoryId !== null
                        ? (int)$categoryId
                        : null,

                'published_at' =>
                    $this->nullableString(
                        $row['published_at']
                        ?? null
                    ),

                'created_at' =>
                    $this->nullableString(
                        $row['created_at']
                        ?? null
                    ),

                'updated_at' =>
                    $this->nullableString(
                        $row['updated_at']
                        ?? null
                    ),
            ];
        }

        return $items;
    }

    /*
     * 固定ページデータを整形
     */
    private function pages(
        array $rows
    ): array {
        $items = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = (int)(
                $row['id']
                ?? 0
            );

            $title = trim(
                (string)(
                    $row['title']
                    ?? ''
                )
            );

            $slug = trim(
                (string)(
                    $row['slug']
                    ?? ''
                )
            );

            if (
                $id <= 0 ||
                $title === '' ||
                $slug === ''
            ) {
                continue;
            }

            $items[] = [
                'id' => $id,
                'title' => $title,
                'slug' => $slug,

                'content' =>
                    (string)(
                        $row['content']
                        ?? ''
                    ),

                'status' =>
                    (string)(
                        $row['status']
                        ?? 'draft'
                    ),

                /*
                 * ユーザー情報は出力しない
                 */
                'published_at' =>
                    $this->nullableString(
                        $row['published_at']
                        ?? null
                    ),

                'created_at' =>
                    $this->nullableString(
                        $row['created_at']
                        ?? null
                    ),

                'updated_at' =>
                    $this->nullableString(
                        $row['updated_at']
                        ?? null
                    ),
            ];
        }

        return $items;
    }

    /*
     * NULLを許可する文字列へ変換
     */
    private function nullableString(
        mixed $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim(
            (string)$value
        );

        return $value !== ''
            ? $value
            : null;
    }
}