<?php

namespace NexaPressImportExport\Repository;

use DateTimeZone;
use NexaPressImportExport\Support\PrivateStorage;
use NexaPressImportExport\Support\SafePath;
use RuntimeException;

class SettingsRepository
{
    /*
     * 対応する設定を取得
     */
    public function export(): array
    {
        $sections = [
            'general' => $this->generalDefaults(),
            'url' => $this->urlDefaults(),
            'debug' => $this->debugDefaults(),
        ];
        foreach ($sections as $name => $defaults) {
            $sections[$name] = array_intersect_key($this->load($name, $defaults), $defaults);
        }
        return $sections;
    }

    /*
     * 設定を保存
     */
    public function save(
        array $settings
    ): int {
        $validated = $this->validate($settings);
        foreach ($validated as $section => $values) {
            $this->write($section, array_replace($this->load($section, []), $values));
        }
        return count($validated);
    }

    public function validate(array $settings): array
    {
        $validated = [];

        if (
            isset($settings['general']) &&
            is_array(
                $settings['general']
            )
        ) {
            $validated['general'] = $this->sanitizeGeneral(
                $settings['general']
            );
        }

        if (
            isset($settings['url']) &&
            is_array(
                $settings['url']
            )
        ) {
            $validated['url'] = $this->sanitizeUrl(
                $settings['url']
            );
        }

        if (
            isset($settings['debug']) &&
            is_array(
                $settings['debug']
            )
        ) {
            $validated['debug'] = $this->sanitizeDebug(
                $settings['debug']
            );
        }

        if ($validated === []) {
            throw new RuntimeException(
                '保存できる設定がありません。'
            );
        }

        return $validated;
    }

    /*
     * 設定ファイルを読み込む
     */
    private function load(
        string $name,
        array $defaults
    ): array {
        $filePath = SafePath::file(BASE_PATH, 'config/' . $name . '.php', false);

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
        if (
            !array_key_exists('enabled', $settings) ||
            !is_bool($settings['enabled'])
        ) {
            throw new RuntimeException('デバッグ設定のenabledにはtrueまたはfalseを指定してください。');
        }

        return [
            'enabled' =>
                $settings['enabled'],
        ];
    }

    /*
     * 設定ファイルを安全に保存
     */
    private function write(string $name, array $settings): void
    {
        $directory = SafePath::directory(BASE_PATH, 'config', false);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('設定フォルダへ書き込めません。');
        }
        $file = $name . '.php';
        $target = SafePath::file($directory, $file, false);
        $mode = is_file($target) ? fileperms($target) & 0777 : 0644;
        PrivateStorage::atomic($directory, $file, "<?php\n\nreturn " . var_export($settings, true) . ";\n");
        if (DIRECTORY_SEPARATOR !== '\\') {
            chmod($target, $mode);
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
