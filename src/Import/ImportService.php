<?php

namespace NexaPressImportExport\Import;

use app\Core\Auth;
use app\Core\Database;
use NexaPressImportExport\Package\PackageReader;
use NexaPressImportExport\Validation\ExportValidator;
use RuntimeException;
use Throwable;

class ImportService
{
    /*
     * インポートを実行
     */
    public function import(
        string $zipPath,
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

        /*
         * インポート後の所有者は
         * 現在ログインしているユーザー
         */
        $loginUser = Auth::user();

        $userId = (int)(
            $loginUser['id']
            ?? 0
        );

        if ($userId <= 0) {
            throw new RuntimeException(
                'ログインユーザーを確認できません。'
            );
        }

        $limits =
            $this->limits();

        $reader =
            new PackageReader();

        $package = null;
        $mediaImporter = null;
        $mediaState = null;
        $pdo = null;
        $transactionStarted = false;

        $result = [
            'categories' => 0,
            'posts' => 0,
            'pages' => 0,
            'media' => 0,
            'settings' => 0,
        ];

        try {
            /*
             * ZIPの展開・構成・チェックサム確認
             */
            $package = $reader->read(
                $zipPath,
                $mode
            );

            $packageDirectory =
                (string)(
                    $package['directory']
                    ?? ''
                );

            if (
                $packageDirectory === '' ||
                !is_dir($packageDirectory)
            ) {
                throw new RuntimeException(
                    'インポートデータを読み込めませんでした。'
                );
            }

            $usesDatabase =
                !empty(
                    $modeSettings['content']
                ) ||
                !empty(
                    $modeSettings['media']
                );

            /*
             * コンテンツとメディアは
             * DBトランザクション内で処理
             */
            if ($usesDatabase) {
                $pdo =
                    Database::connect();

                if ($pdo->inTransaction()) {
                    throw new RuntimeException(
                        '別のデータ処理が実行中です。'
                    );
                }

                if (!$pdo->beginTransaction()) {
                    throw new RuntimeException(
                        'インポート処理を開始できませんでした。'
                    );
                }

                $transactionStarted = true;
            }

            /*
             * コンテンツを取り込む
             */
            if (
                !empty(
                    $modeSettings['content']
                )
            ) {
                $contentImporter =
                    new ContentImporter();

                $contentResult =
                    $contentImporter->import(
                        $packageDirectory,
                        $userId,
                        $limits
                    );

                $result['categories'] =
                    (int)(
                        $contentResult[
                            'categories'
                        ]
                        ?? 0
                    );

                $result['posts'] =
                    (int)(
                        $contentResult[
                            'posts'
                        ]
                        ?? 0
                    );

                $result['pages'] =
                    (int)(
                        $contentResult[
                            'pages'
                        ]
                        ?? 0
                    );
            }

            /*
             * メディアを取り込む
             */
            if (
                !empty(
                    $modeSettings['media']
                )
            ) {
                $mediaImporter =
                    new MediaImporter();

                $mediaResult =
                    $mediaImporter->import(
                        $packageDirectory,
                        $userId,
                        $limits
                    );

                $result['media'] =
                    (int)(
                        $mediaResult['count']
                        ?? 0
                    );

                $mediaState =
                    $mediaResult['state']
                    ?? null;
            }

            /*
             * 設定を取り込む
             */
            if (
                !empty(
                    $modeSettings['settings']
                )
            ) {
                $settingsImporter =
                    new SettingsImporter();

                $result['settings'] =
                    $settingsImporter->import(
                        $packageDirectory,
                        $limits
                    );
            }

            /*
             * DB変更を確定
             */
            if (
                $transactionStarted &&
                $pdo !== null
            ) {
                if (!$pdo->commit()) {
                    throw new RuntimeException(
                        'インポート内容を確定できませんでした。'
                    );
                }

                $transactionStarted = false;
            }

            /*
             * メディアのバックアップを削除
             */
            if (
                $mediaImporter !== null &&
                is_array($mediaState)
            ) {
                $mediaImporter->commit(
                    $mediaState
                );
            }

            return $result;
        } catch (Throwable $exception) {
            /*
             * DB変更を元に戻す
             */
            if (
                $transactionStarted &&
                $pdo !== null &&
                $pdo->inTransaction()
            ) {
                $pdo->rollBack();
            }

            /*
             * コピー済みメディアを元に戻す
             */
            if (
                $mediaImporter !== null &&
                is_array($mediaState)
            ) {
                $mediaImporter->rollback(
                    $mediaState
                );
            }

            throw $exception;
        } finally {
            /*
             * 展開した一時ファイルを削除
             */
            if (is_array($package)) {
                $reader->release(
                    $package
                );
            }
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