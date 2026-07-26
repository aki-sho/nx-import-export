<?php

namespace NexaPressImportExport\Export;

use NexaPressImportExport\Repository\SettingsRepository;
use NexaPressImportExport\Support\Json;
use RuntimeException;

class SettingsExporter
{
    /*
     * NexaPressの設定をJSONへ出力
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
                '設定保存フォルダを作成できませんでした。'
            );
        }

        $repository =
            new SettingsRepository();

        $settings =
            $repository->export();

        Json::write(
            $dataDirectory
            . '/settings.json',
            [
                'settings' =>
                    $settings,

                'sections' =>
                    array_keys(
                        $settings
                    ),
            ]
        );

        return [
            'settings' =>
                count($settings),
        ];
    }
}