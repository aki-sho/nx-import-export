<?php

namespace NexaPressImportExport\Support;

use DateTimeImmutable;
use DateTimeInterface;

class FileName
{
    /*
     * エクスポートZIPのファイル名を作成
     */
    public static function exportPackage(
        ?DateTimeInterface $date = null
    ): string {
        $date = $date
            ?? new DateTimeImmutable();

        return
            'nexapress-export-'
            . $date->format(
                'Ymd-His'
            )
            . '.zip';
    }

    /*
     * ダウンロード用ファイル名を整える
     */
    public static function download(
        string $fileName,
        string $fallback =
            'nexapress-export.zip'
    ): string {
        $fileName = basename(
            trim($fileName)
        );

        $fileName = preg_replace(
            '/[^A-Za-z0-9._-]/',
            '-',
            $fileName
        );

        if (!is_string($fileName)) {
            return $fallback;
        }

        $fileName = trim(
            $fileName,
            '.-_'
        );

        if ($fileName === '') {
            return $fallback;
        }

        return $fileName;
    }

    /*
     * ファイル名の一部分として使える文字へ変換
     */
    public static function segment(
        string $value,
        string $fallback = 'file'
    ): string {
        $value = preg_replace(
            '/[^A-Za-z0-9_-]/',
            '-',
            trim($value)
        );

        if (!is_string($value)) {
            return $fallback;
        }

        $value = trim(
            $value,
            '-_'
        );

        return $value !== ''
            ? $value
            : $fallback;
    }
}