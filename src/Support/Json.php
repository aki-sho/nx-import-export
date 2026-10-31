<?php

namespace NexaPressImportExport\Support;

use JsonException;
use RuntimeException;

class Json
{
    /*
     * 配列をJSONへ変換
     */
    public static function encode(
        mixed $data
    ): string {
        try {
            return json_encode(
                $data,
                JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'JSONデータを作成できませんでした。',
                0,
                $exception
            );
        }
    }

    /*
     * JSON文字列を配列へ変換
     */
    public static function decode(
        string $json
    ): array {
        self::validateComplexity($json);

        try {
            $data = json_decode(
                $json,
                true,
                64,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'JSONデータの形式が正しくありません。',
                0,
                $exception
            );
        }

        if (!is_array($data)) {
            throw new RuntimeException(
                'JSONデータの内容が正しくありません。'
            );
        }

        return $data;
    }

    /*
     * 小さいJSONでも大量の配列・要素を生成してメモリを消費しないよう、
     * デコード前に構造要素数を制限する。文字列内の記号は数えない。
     */
    private static function validateComplexity(string $json): void
    {
        $length = strlen($json);
        $nodes = 0;

        for ($index = 0; $index < $length; $index++) {
            $character = $json[$index];

            if ($character === '"') {
                while (++$index < $length) {
                    $index += strcspn($json, '"\\', $index);

                    if ($index >= $length || $json[$index] === '"') {
                        break;
                    }

                    // エスケープされた次の1文字も読み飛ばす。
                    $index++;
                }
            } elseif (
                $character === '{' ||
                $character === '[' ||
                $character === ','
            ) {
                if (++$nodes > 200000) {
                    throw new RuntimeException('JSONの構造要素数が制限を超えています。');
                }
            }
        }
    }

    /*
     * JSONファイルを読み込む
     */
    public static function read(
        string $filePath,
        int $maxSize = 20971520
    ): array {
        if (!is_file($filePath)) {
            throw new RuntimeException(
                'JSONファイルが見つかりません。'
            );
        }

        $fileSize = filesize(
            $filePath
        );

        if ($fileSize === false) {
            throw new RuntimeException(
                'JSONファイルの容量を確認できません。'
            );
        }

        if (
            $fileSize <= 0 ||
            $fileSize > $maxSize
        ) {
            throw new RuntimeException(
                'JSONファイルの容量が正しくありません。'
            );
        }

        $json = file_get_contents(
            $filePath
        );

        if ($json === false) {
            throw new RuntimeException(
                'JSONファイルを読み込めませんでした。'
            );
        }

        return self::decode(
            $json
        );
    }

    /*
     * 配列をJSONファイルへ保存
     */
    public static function write(
        string $filePath,
        mixed $data
    ): void {
        $directory = dirname(
            $filePath
        );

        if (
            !is_dir($directory) &&
            !mkdir(
                $directory,
                0755,
                true
            ) &&
            !is_dir($directory)
        ) {
            throw new RuntimeException(
                'JSON保存フォルダを作成できませんでした。'
            );
        }

        $json = self::encode(
            $data
        );

        $result = file_put_contents(
            $filePath,
            $json . PHP_EOL,
            LOCK_EX
        );

        if ($result === false) {
            throw new RuntimeException(
                'JSONファイルを保存できませんでした。'
            );
        }
    }
}
