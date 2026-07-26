<?php

namespace NexaPressImportExport\Repository;

use DateTimeZone;
use RuntimeException;

class SettingsRepository
{
    /*
     * 対応する設定を取得
     */
    public function export(): array
    {
        return [
            'general' =>
                $this->load(
                    'general',
                    $this->generalDefaults()
                ),

            'url' =>
                $this->load(
                    'url',
                    $this->urlDefaults()
                ),

            'debug' =>
                $this->load(
                    'debug',
                    $this->debugDefaults()
                ),
        ];
    }

    /*
     * 設定を保存
     */
    public function save(
        array $settings
    ): int {
        $savedCount = 0;

        if (
            isset($settings['general']) &&
            is_array(
                $settings['general']
            )
        ) {
            $this->write(
                'general',
                $this->sanitizeGeneral(
                    $settings['general']
                )
            );

            $savedCount++;
        }

        if (
            isset($settings['url']) &&
            is_array(
                $settings['url']
            )
        ) {
            $this->write(
                'url',
                $this->sanitizeUrl(
                    $settings['url']
                )
            );

            $savedCount++;
        }

        if (
            isset($settings['debug']) &&
            is_array(
                $settings['debug']
            )
        ) {
            $this->write(
                'debug',
                $this->sanitizeDebug(
                    $settings['debug']
                )
            );

            $savedCount++;
        }

        if ($savedCount === 0) {
            throw new RuntimeException(
                '保存できる設定がありません。'
            );
        }

        return $savedCount;
    }

    /*
     * 設定ファイルを読み込む
     */
    private function load(
        string $name,
        array $defaults
    ): array {
        $filePath =
            BASE_PATH
            . '/config/'
            . $name
            . '.php';

        if (!is_file($filePath)) {
            return $defaults;
        }

        $settings = require $filePath;

        if (!is_array($settings)) {
            return $defaults;
        }

        return array_merge(
            $defaults,
            $settings
        );
    }

    /*
     * 一般設定を検証
     */
    private function sanitizeGeneral(
        array $settings
    ): array {
        $siteTitle = trim(
            (string)(
                $settings['site_title']
                ?? 'My CMS'
            )
        );

        if ($siteTitle === '') {
            $siteTitle = 'My CMS';
        }

        $timezone = trim(
            (string)(
                $settings['timezone']
                ?? 'Asia/Tokyo'
            )
        );

        if (
            !in_array(
                $timezone,
                DateTimeZone::listIdentifiers(),
                true
            )
        ) {
            $timezone = 'Asia/Tokyo';
        }

        $siteIcon = trim(
            (string)(
                $settings['site_icon']
                ?? ''
            )
        );

        return [
            'site_title' =>
                $siteTitle,

            'timezone' =>
                $timezone,

            'site_icon' =>
                $siteIcon,

            'discourage_search_engines' =>
                (bool)(
                    $settings[
                        'discourage_search_engines'
                    ]
                    ?? false
                ),
        ];
    }

    /*
     * URL設定を検証
     */
    private function sanitizeUrl(
        array $settings
    ): array {
        $siteUrlMode =
            (string)(
                $settings['site_url_mode']
                ?? 'public'
            );

        if (
            !in_array(
                $siteUrlMode,
                [
                    'public',
                    'root',
                ],
                true
            )
        ) {
            $siteUrlMode = 'public';
        }

        $postUrlType =
            (string)(
                $settings['post_url_type']
                ?? 'post_slug'
            );

        if (
            !in_array(
                $postUrlType,
                [
                    'post_slug',
                    'slug',
                    'category_slug',
                ],
                true
            )
        ) {
            $postUrlType = 'post_slug';
        }

        $pageUrlType =
            (string)(
                $settings['page_url_type']
                ?? 'page_slug'
            );

        if (
            !in_array(
                $pageUrlType,
                [
                    'page_slug',
                    'slug',
                ],
                true
            )
        ) {
            $pageUrlType = 'page_slug';
        }

        return [
            'site_url_mode' =>
                $siteUrlMode,

            'post_url_type' =>
                $postUrlType,

            'page_url_type' =>
                $pageUrlType,
        ];
    }

    /*
     * デバッグ設定を検証
     */
    private function sanitizeDebug(
        array $settings
    ): array {
        return [
            'enabled' =>
                (bool)(
                    $settings['enabled']
                    ?? false
                ),
        ];
    }

    /*
     * 設定ファイルを安全に保存
     */
    private function write(
        string $name,
        array $settings
    ): void {
        $configDirectory =
            BASE_PATH . '/config';

        if (
            !is_dir($configDirectory) ||
            !is_writable(
                $configDirectory
            )
        ) {
            throw new RuntimeException(
                '設定フォルダへ書き込めません。'
            );
        }

        $filePath =
            $configDirectory
            . '/'
            . $name
            . '.php';

        $temporaryPath =
            $filePath
            . '.tmp-'
            . bin2hex(
                random_bytes(6)
            );

        $contents =
            "<?php\n\nreturn "
            . var_export(
                $settings,
                true
            )
            . ";\n";

        $result = file_put_contents(
            $temporaryPath,
            $contents,
            LOCK_EX
        );

        if ($result === false) {
            throw new RuntimeException(
                '設定ファイルを作成できませんでした。'
            );
        }

        if (
            !rename(
                $temporaryPath,
                $filePath
            )
        ) {
            unlink(
                $temporaryPath
            );

            throw new RuntimeException(
                '設定ファイルを保存できませんでした。'
            );
        }
    }

    /*
     * 一般設定の初期値
     */
    private function generalDefaults(): array
    {
        return [
            'site_title' => 'My CMS',
            'timezone' => 'Asia/Tokyo',
            'site_icon' => '',
            'discourage_search_engines' =>
                false,
        ];
    }

    /*
     * URL設定の初期値
     */
    private function urlDefaults(): array
    {
        return [
            'site_url_mode' => 'public',
            'post_url_type' =>
                'post_slug',

            'page_url_type' =>
                'page_slug',
        ];
    }

    /*
     * デバッグ設定の初期値
     */
    private function debugDefaults(): array
    {
        return [
            'enabled' => false,
        ];
    }
}