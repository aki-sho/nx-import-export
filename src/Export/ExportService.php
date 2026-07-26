<?php

namespace NexaPressImportExport\Export;

use NexaPressImportExport\Package\PackageBuilder;
use NexaPressImportExport\Support\TempDirectory;
use NexaPressImportExport\Validation\ExportValidator;
use RuntimeException;
use Throwable;

class ExportService
{
    /*
     * エクスポートパッケージを作成
     */
    public function create(
        string $mode
    ): array {
        $modes =
            ExportValidator::modes();

        if (
            !isset($modes[$mode]) ||
            !is_array($modes[$mode])
        ) {
            throw new RuntimeException(
                '選択された処理内容が正しくありません。'
            );
        }

        $modeSettings =
            $modes[$mode];

        $limits =
            $this->limits();

        /*
         * 保存期限を過ぎた一時フォルダを削除
         */
        TempDirectory::cleanup(
            (int)(
                $limits[
                    'temporary_file_lifetime'
                ]
                ?? 3600
            )
        );

        /*
         * 今回の作業用フォルダを作成
         */
        $workingDirectory =
            TempDirectory::create();

        $packageDirectory =
            $workingDirectory
            . DIRECTORY_SEPARATOR
            . 'package';

        if (
            !mkdir(
                $packageDirectory,
                0755,
                true
            ) &&
            !is_dir($packageDirectory)
        ) {
            TempDirectory::remove(
                $workingDirectory
            );

            throw new RuntimeException(
                'エクスポート作業フォルダを作成できませんでした。'
            );
        }

        try {
            /*
             * コンテンツを出力
             */
            if (
                !empty(
                    $modeSettings['content']
                )
            ) {
                $exporter =
                    new ContentExporter();

                $exporter->export(
                    $packageDirectory
                );
            }

            /*
             * メディアを出力
             */
            if (
                !empty(
                    $modeSettings['media']
                )
            ) {
                $exporter =
                    new MediaExporter();

                $exporter->export(
                    $packageDirectory,
                    $limits
                );
            }

            /*
             * 設定を出力
             */
            if (
                !empty(
                    $modeSettings['settings']
                )
            ) {
                $exporter =
                    new SettingsExporter();

                $exporter->export(
                    $packageDirectory
                );
            }

            /*
             * ZIPパッケージを作成
             */
            $builder =
                new PackageBuilder();

            return $builder->build(
                $packageDirectory,
                $workingDirectory,
                $mode
            );
        } catch (Throwable $exception) {
            TempDirectory::remove(
                $workingDirectory
            );

            throw $exception;
        }
    }

    /*
     * 制限値を取得
     */
    private function limits(): array
    {
        if (
            !defined(
                'NX_IMPORT_EXPORT_PATH'
            )
        ) {
            return [];
        }

        $limitsFile =
            NX_IMPORT_EXPORT_PATH
            . '/config/limits.php';

        if (!is_file($limitsFile)) {
            return [];
        }

        $limits = require $limitsFile;

        return is_array($limits)
            ? $limits
            : [];
    }
}