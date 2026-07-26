<?php

namespace NexaPressImportExport\Import;

use NexaPressImportExport\Repository\SettingsRepository;
use NexaPressImportExport\Support\Json;
use RuntimeException;

class SettingsImporter
{
    /*
     * 設定を取り込む
     */
    public function import(
        string $packageDirectory,
        array $limits
    ): int {
        $maxJsonSize = (int)(
            $limits['max_json_size']
            ?? 20971520
        );

        $settingsFile =
            rtrim(
                $packageDirectory,
                '/\\'
            )
            . DIRECTORY_SEPARATOR
            . 'data'
            . DIRECTORY_SEPARATOR
            . 'settings.json';

        $document =
            Json::read(
                $settingsFile,
                $maxJsonSize
            );

        $settings =
            $document['settings']
            ?? null;

        $sections =
            $document['sections']
            ?? null;

        if (
            !is_array($settings) ||
            !is_array($sections)
        ) {
            throw new RuntimeException(
                '設定データの形式が正しくありません。'
            );
        }

        $allowedSections = [
            'general',
            'url',
            'debug',
        ];

        foreach ($sections as $section) {
            $section = trim(
                (string)$section
            );

            if (
                !in_array(
                    $section,
                    $allowedSections,
                    true
                ) ||
                !isset(
                    $settings[$section]
                ) ||
                !is_array(
                    $settings[$section]
                )
            ) {
                throw new RuntimeException(
                    '設定項目が正しくありません。'
                );
            }
        }

        if ($sections === []) {
            throw new RuntimeException(
                'インポートする設定がありません。'
            );
        }

        /*
         * sectionsに記載された設定だけを保存
         */
        $selectedSettings = [];

        foreach ($sections as $section) {
            $section = (string)$section;

            $selectedSettings[$section] =
                $settings[$section];
        }

        $repository =
            new SettingsRepository();

        return $repository->save(
            $selectedSettings
        );
    }
}