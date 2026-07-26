<?php

use app\Core\Csrf;

/*
 * 拡張機能以外から直接実行された場合は終了
 */
if (!defined('NX_IMPORT_EXPORT_PATH')) {
    return;
}

/*
 * 最大アップロード容量を表示用に変換
 */
$maxPackageSize =
    (int)(
        $limits['max_package_size']
        ?? 104857600
    );

$maxPackageSizeMb =
    max(
        1,
        (int)ceil(
            $maxPackageSize
            / 1024
            / 1024
        )
    );

$fieldName = 'mode';
$selectedMode = 'content';
$inputIdPrefix = 'nx-import-mode';
?>

<div class="nx-import-export-card">
    <div class="nx-import-export-card-header">
        <h2>インポート</h2>

        <p>
            NX Import Exportで作成した
            ZIPファイルを取り込みます。
        </p>
    </div>

    <form
        method="post"
        enctype="multipart/form-data"
        action="<?= e(
            url(
                'admin/extensions/'
                . NX_IMPORT_EXPORT_ID
                . '/import'
            )
        ) ?>"
        class="nx-import-export-form"
    >
        <input
            type="hidden"
            name="_csrf_token"
            value="<?= e(Csrf::token()) ?>"
        >

        <div class="nx-import-export-field">
            <label for="nx-import-file">
                インポートファイル
            </label>

            <input
                id="nx-import-file"
                type="file"
                name="import_file"
                accept=".zip,application/zip"
                required
            >

            <p class="nx-import-export-help">
                ZIP形式・最大
                <?= e((string)$maxPackageSizeMb) ?>MB
            </p>
        </div>

        <fieldset>
            <legend>
                インポートする内容
            </legend>

            <?php
            $modeOptionsFile =
                NX_IMPORT_EXPORT_PATH
                . '/admin/partials/mode-options.php';

            if (is_file($modeOptionsFile)) {
                require $modeOptionsFile;
            }
            ?>
        </fieldset>

        <div class="nx-import-export-warning">
            <strong>注意</strong>

            <p>
                既存データと重複する内容が含まれる場合があります。
                本番環境では、事前にバックアップを取得してください。
            </p>
        </div>

        <div class="nx-import-export-actions">
            <button
                type="submit"
                class="button button-primary"
            >
                インポートを実行
            </button>
        </div>
    </form>
</div>