<?php

use NexaPressImportExport\Admin\Notice;

/*
 * 拡張機能以外から直接実行された場合は終了
 */
if (!defined('NX_IMPORT_EXPORT_PATH')) {
    return;
}

/*
 * 処理モードを取得
 */
$modesFile =
    NX_IMPORT_EXPORT_PATH
    . '/config/export-modes.php';

$modes = is_file($modesFile)
    ? require $modesFile
    : [];

if (!is_array($modes)) {
    $modes = [];
}

/*
 * 制限値を取得
 */
$limitsFile =
    NX_IMPORT_EXPORT_PATH
    . '/config/limits.php';

$limits = is_file($limitsFile)
    ? require $limitsFile
    : [];

if (!is_array($limits)) {
    $limits = [];
}

/*
 * 処理結果メッセージを取得
 */
$notice = Notice::pull();

/*
 * 管理画面用CSS
 */
$cssFile =
    NX_IMPORT_EXPORT_PATH
    . '/assets/css/import-export.css';

if (is_file($cssFile)) {
    echo '<style>';
    readfile($cssFile);
    echo '</style>';
}
?>

<div
    class="nx-import-export"
    data-nx-import-export
>
    <div class="nx-import-export-header">
        <div>
            <h1>
                インポート・エクスポート
            </h1>

            <p>
                コンテンツ、メディア、設定の
                書き出しと取り込みを行います。
            </p>
        </div>

        <span class="nx-import-export-version">
            Version
            <?= e(
                (string)(
                    $extensionInfo['version']
                    ?? '1.0.0'
                )
            ) ?>
        </span>
    </div>

    <?php
    $noticeFile =
        NX_IMPORT_EXPORT_PATH
        . '/admin/partials/notice.php';

    if (is_file($noticeFile)) {
        require $noticeFile;
    }
    ?>

    <nav
        class="nx-import-export-tabs"
        aria-label="インポート・エクスポート"
    >
        <button
            type="button"
            class="nx-import-export-tab is-active"
            data-nx-import-export-tab="export"
        >
            エクスポート
        </button>

        <button
            type="button"
            class="nx-import-export-tab"
            data-nx-import-export-tab="import"
        >
            インポート
        </button>
    </nav>

    <div class="nx-import-export-panels">
        <section
            class="nx-import-export-panel is-active"
            data-nx-import-export-panel="export"
        >
            <?php
            $exportSection =
                NX_IMPORT_EXPORT_PATH
                . '/admin/sections/export.php';

            if (is_file($exportSection)) {
                require $exportSection;
            } else {
                echo '<p>エクスポート画面を読み込めません。</p>';
            }
            ?>
        </section>

        <section
            class="nx-import-export-panel"
            data-nx-import-export-panel="import"
        >
            <?php
            $importSection =
                NX_IMPORT_EXPORT_PATH
                . '/admin/sections/import.php';

            if (is_file($importSection)) {
                require $importSection;
            } else {
                echo '<p>インポート画面を読み込めません。</p>';
            }
            ?>
        </section>
    </div>
</div>

<?php

/*
 * 管理画面用JavaScript
 */
$jsFile =
    NX_IMPORT_EXPORT_PATH
    . '/assets/js/import-export.js';

if (is_file($jsFile)) {
    echo '<script>';
    readfile($jsFile);
    echo '</script>';
}
?>