<?php

use NexaPressImportExport\Admin\Notice;
use app\Core\Auth;
use app\Core\Permission;
use NexaPressImportExport\Import\BackupService;
use NexaPressImportExport\Import\PreviewStore;

/*
 * 拡張機能以外から直接実行された場合は終了
 */
if (!defined('NX_IMPORT_EXPORT_PATH')) {
    return;
}

Permission::require('extensions.manage');
$preview = null;
$backups = [];
$managementError = '';
$userId = (int)(Auth::user()['id'] ?? 0);
$activeTab = ($_SESSION['nx_import_export_active_tab'] ?? '') === 'import' ? 'import' : 'export';
unset($_SESSION['nx_import_export_active_tab']);
if (isset($_SESSION['nx_import_export_preview'])) {
    try {
        $preview = (new PreviewStore())->get((string)$_SESSION['nx_import_export_preview'], $userId);
        $activeTab = 'import';
    } catch (Throwable $exception) {
        unset($_SESSION['nx_import_export_preview']);
        $managementError = '確認用データを読み込めません。再度アップロードしてプレビューしてください。';
    }
}
try { $backups = (new BackupService())->listForUser($userId); }
catch (Throwable $exception) { $managementError = 'バックアップ履歴を読み込めません。保存先の権限を確認してください。'; }

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
                    ?? '1.1.0'
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
            class="nx-import-export-tab<?= $activeTab === 'export' ? ' is-active' : '' ?>"
            data-nx-import-export-tab="export"
        >
            エクスポート
        </button>

        <button
            type="button"
            class="nx-import-export-tab<?= $activeTab === 'import' ? ' is-active' : '' ?>"
            data-nx-import-export-tab="import"
        >
            インポート
        </button>
    </nav>

    <div class="nx-import-export-panels">
        <section
            class="nx-import-export-panel<?= $activeTab === 'export' ? ' is-active' : '' ?>"
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
            class="nx-import-export-panel<?= $activeTab === 'import' ? ' is-active' : '' ?>"
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
