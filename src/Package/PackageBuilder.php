<?php

namespace NexaPressImportExport\Package;

use FilesystemIterator;
use NexaPressImportExport\Support\FileName;
use NexaPressImportExport\Support\Json;
use NexaPressImportExport\Support\Zip;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

class PackageBuilder
{
    /*
     * エクスポートZIPを作成
     */
    public function build(
        string $sourceDirectory,
        string $outputDirectory,
        string $mode
    ): array {
        $sourcePath = realpath(
            $sourceDirectory
        );

        if (
            $sourcePath === false ||
            !is_dir($sourcePath)
        ) {
            throw new RuntimeException(
                'エクスポート元フォルダが見つかりません。'
            );
        }

        if (
            !is_dir($outputDirectory) &&
            !mkdir(
                $outputDirectory,
                0755,
                true
            ) &&
            !is_dir($outputDirectory)
        ) {
            throw new RuntimeException(
                'エクスポート先フォルダを作成できませんでした。'
            );
        }

        /*
         * 前回の管理ファイルが残っている場合は削除
         */
        $manifestPath =
            $sourcePath
            . '/export-manifest.json';

        $checksumsPath =
            $sourcePath
            . '/checksums.json';

        if (is_file($manifestPath)) {
            unlink($manifestPath);
        }

        if (is_file($checksumsPath)) {
            unlink($checksumsPath);
        }

        /*
         * データファイル一覧を取得
         */
        $dataFiles = $this->files(
            $sourcePath
        );

        if ($dataFiles === []) {
            throw new RuntimeException(
                'エクスポートするデータがありません。'
            );
        }

        /*
         * エクスポート情報を保存
         */
        $manifest =
            PackageManifest::create(
                $mode,
                $dataFiles
            );

        Json::write(
            $manifestPath,
            $manifest
        );

        /*
         * 管理情報を含めてチェックサムを作成
         */
        $checksumTargets =
            $this->files(
                $sourcePath
            );

        $checksums =
            Checksum::createMap(
                $sourcePath,
                $checksumTargets
            );

        Json::write(
            $checksumsPath,
            [
                'algorithm' => 'sha256',
                'files' => $checksums,
            ]
        );

        /*
         * ZIPへ格納する全ファイルを取得
         */
        $packageFiles =
            $this->files(
                $sourcePath
            );

        $packageName =
            FileName::exportPackage();

        $packagePath =
            rtrim(
                $outputDirectory,
                '/\\'
            )
            . DIRECTORY_SEPARATOR
            . $packageName;

        $zip = Zip::create(
            $packagePath
        );

        try {
            foreach (
                $packageFiles as $relativePath
            ) {
                $sourceFile =
                    $sourcePath
                    . DIRECTORY_SEPARATOR
                    . str_replace(
                        '/',
                        DIRECTORY_SEPARATOR,
                        $relativePath
                    );

                Zip::addFile(
                    $zip,
                    $sourceFile,
                    $relativePath
                );
            }
        } catch (Throwable $exception) {
            $zip->close();

            if (is_file($packagePath)) {
                unlink($packagePath);
            }

            throw $exception;
        }

        if (!$zip->close()) {
            if (is_file($packagePath)) {
                unlink($packagePath);
            }

            throw new RuntimeException(
                'エクスポートZIPを保存できませんでした。'
            );
        }

        if (!is_file($packagePath)) {
            throw new RuntimeException(
                'エクスポートZIPを作成できませんでした。'
            );
        }

        return [
            'path' => $packagePath,
            'name' =>
                FileName::download(
                    $packageName
                ),

            'manifest' => $manifest,
        ];
    }

    /*
     * フォルダ内のファイル一覧を取得
     */
    private function files(
        string $baseDirectory
    ): array {
        $files = [];

        $iterator =
            new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $baseDirectory,
                    FilesystemIterator::SKIP_DOTS
                ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo) {
                continue;
            }

            if ($fileInfo->isLink()) {
                throw new RuntimeException(
                    'エクスポート対象にリンクファイルは使用できません。'
                );
            }

            if (!$fileInfo->isFile()) {
                continue;
            }

            $filePath =
                $fileInfo->getPathname();

            $relativePath = substr(
                $filePath,
                strlen($baseDirectory) + 1
            );

            if ($relativePath === false) {
                throw new RuntimeException(
                    'エクスポート対象のパスを取得できませんでした。'
                );
            }

            $relativePath =
                Zip::normalizePath(
                    str_replace(
                        DIRECTORY_SEPARATOR,
                        '/',
                        $relativePath
                    )
                );

            $files[] = $relativePath;
        }

        sort($files);

        return $files;
    }
}