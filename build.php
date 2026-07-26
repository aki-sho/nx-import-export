<?php

/*
 * NX Import Exportの配布用ZIPを作成
 */

$projectDirectory = __DIR__;

$manifestFile =
    $projectDirectory
    . '/manifest.json';

/*
 * 必須ファイルを確認
 */
if (!is_file($manifestFile)) {
    exit(
        "manifest.jsonが見つかりません。\n"
    );
}

if (!class_exists(ZipArchive::class)) {
    exit(
        "ZipArchiveが使用できません。\n"
    );
}

/*
 * manifest.jsonを読み込む
 */
$manifestJson = file_get_contents(
    $manifestFile
);

if ($manifestJson === false) {
    exit(
        "manifest.jsonを読み込めませんでした。\n"
    );
}

try {
    $manifest = json_decode(
        $manifestJson,
        true,
        512,
        JSON_THROW_ON_ERROR
    );
} catch (JsonException) {
    exit(
        "manifest.jsonの形式が正しくありません。\n"
    );
}

if (!is_array($manifest)) {
    exit(
        "manifest.jsonの内容が正しくありません。\n"
    );
}

/*
 * 拡張機能IDを確認
 */
$extensionId = trim(
    (string)(
        $manifest['id']
        ?? ''
    )
);

if (
    !preg_match(
        '/^[A-Za-z0-9_-]+$/',
        $extensionId
    )
) {
    exit(
        "拡張機能IDが正しくありません。\n"
    );
}

/*
 * バージョンを確認
 */
$version = trim(
    (string)(
        $manifest['version']
        ?? ''
    )
);

if (
    !preg_match(
        '/^\d+\.\d+\.\d+$/',
        $version
    )
) {
    exit(
        "バージョンが正しくありません。\n"
    );
}

/*
 * ZIPファイル名を取得
 */
$assetPattern = trim(
    (string)(
        $manifest['update']['asset']
        ?? $extensionId
            . '-{version}.zip'
    )
);

$outputName = str_replace(
    '{version}',
    $version,
    $assetPattern
);

if (
    $outputName === '' ||
    basename($outputName)
        !== $outputName ||
    strtolower(
        pathinfo(
            $outputName,
            PATHINFO_EXTENSION
        )
    ) !== 'zip'
) {
    exit(
        "ZIPファイル名が正しくありません。\n"
    );
}

/*
 * 配布ZIPへ必ず含めるファイル
 */
$requiredFiles = [
    'manifest.json',
    'bootstrap.php',
    'autoload.php',
    'README.md',
    'config/export-modes.php',
    'config/limits.php',
    'routes/admin.php',
    'admin/dashboard.php',
];

foreach ($requiredFiles as $requiredFile) {
    $requiredPath =
        $projectDirectory
        . DIRECTORY_SEPARATOR
        . str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $requiredFile
        );

    if (!is_file($requiredPath)) {
        exit(
            "必須ファイルが見つかりません："
            . $requiredFile
            . "\n"
        );
    }
}

/*
 * 出力先を準備
 */
$outputDirectory =
    $projectDirectory
    . '/dist';

if (
    !is_dir($outputDirectory) &&
    !mkdir(
        $outputDirectory,
        0755,
        true
    ) &&
    !is_dir($outputDirectory)
) {
    exit(
        "distフォルダを作成できません。\n"
    );
}

$outputFile =
    $outputDirectory
    . '/'
    . $outputName;

if (
    is_file($outputFile) &&
    !unlink($outputFile)
) {
    exit(
        "以前のZIPファイルを削除できません。\n"
    );
}

/*
 * ZIPを作成
 */
$zip = new ZipArchive();

$result = $zip->open(
    $outputFile,
    ZipArchive::CREATE
    | ZipArchive::OVERWRITE
);

if ($result !== true) {
    exit(
        "ZIPファイルを作成できませんでした。\n"
    );
}

/*
 * ZIPへ含めない項目
 */
$excludedPaths = [
    '.git',
    '.github',
    '.idea',
    '.vscode',
    'dist',
    'build.php',
    '.gitignore',
];

/*
 * プロジェクト内のファイルを追加
 */
$iterator =
    new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $projectDirectory,
            FilesystemIterator::SKIP_DOTS
        ),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

$fileCount = 0;

foreach ($iterator as $fileInfo) {
    if (
        !$fileInfo instanceof SplFileInfo
    ) {
        continue;
    }

    if ($fileInfo->isLink()) {
        $zip->close();

        if (is_file($outputFile)) {
            unlink($outputFile);
        }

        exit(
            "リンクファイルは使用できません："
            . $fileInfo->getPathname()
            . "\n"
        );
    }

    if (!$fileInfo->isFile()) {
        continue;
    }

    $relativePath = substr(
        $fileInfo->getPathname(),
        strlen($projectDirectory) + 1
    );

    if ($relativePath === false) {
        continue;
    }

    $relativePath = str_replace(
        DIRECTORY_SEPARATOR,
        '/',
        $relativePath
    );

    $topDirectory = explode(
        '/',
        $relativePath
    )[0];

    if (
        in_array(
            $topDirectory,
            $excludedPaths,
            true
        ) ||
        in_array(
            $relativePath,
            $excludedPaths,
            true
        )
    ) {
        continue;
    }

    $zipPath =
        $extensionId
        . '/'
        . $relativePath;

    if (
        !$zip->addFile(
            $fileInfo->getPathname(),
            $zipPath
        )
    ) {
        $zip->close();

        if (is_file($outputFile)) {
            unlink($outputFile);
        }

        exit(
            "ZIPへの追加に失敗しました："
            . $relativePath
            . "\n"
        );
    }

    $fileCount++;
}

if (!$zip->close()) {
    if (is_file($outputFile)) {
        unlink($outputFile);
    }

    exit(
        "ZIPファイルを保存できませんでした。\n"
    );
}

if (
    !is_file($outputFile) ||
    filesize($outputFile) === false
) {
    exit(
        "ZIPファイルを確認できませんでした。\n"
    );
}

echo "ZIPを作成しました。\n";
echo "ファイル数："
    . $fileCount
    . "\n";

echo "出力先："
    . $outputFile
    . "\n";