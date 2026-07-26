<?php

/*
 * NX Import Exportのクラスを自動読み込み
 */
spl_autoload_register(
    static function (string $className): void {
        $namespace = 'NexaPressImportExport\\';

        if (!str_starts_with($className, $namespace)) {
            return;
        }

        /*
         * 名前空間部分を除去
         */
        $relativeClass = substr(
            $className,
            strlen($namespace)
        );

        /*
         * 名前空間をファイルパスへ変換
         */
        $relativePath = str_replace(
            '\\',
            DIRECTORY_SEPARATOR,
            $relativeClass
        );

        $filePath = __DIR__
            . '/src/'
            . $relativePath
            . '.php';

        if (is_file($filePath)) {
            require_once $filePath;
        }
    }
);