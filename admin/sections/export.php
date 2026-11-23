<?php

use app\Core\Csrf;

/*
 * 拡張機能以外から直接実行された場合は終了
 */
if (!defined('NX_IMPORT_EXPORT_PATH')) {
    return;
}

$fieldName = 'mode';
$selectedMode = 'content';
$inputIdPrefix = 'nx-export-mode';
?>

<div class="nx-import-export-card">
    <div class="nx-import-export-card-header">
        <h2>エクスポート</h2>

        <p>
            選択したデータをCSVまたはZIPでダウンロードします。
        </p>
    </div>

    <form
        method="post"
        action="<?= e(
            url(
                'admin/extensions/'
                . NX_IMPORT_EXPORT_ID
                . '/export'
            )
        ) ?>"
        class="nx-import-export-form"
    >
        <input
            type="hidden"
            name="_csrf_token"
            value="<?= e(Csrf::token()) ?>"
        >

        <fieldset>
            <legend>
                エクスポートする内容
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

        <div class="nx-import-export-field" data-nx-csv-scope>
            <label for="nx-csv-scope">CSVの出力対象</label>
            <select id="nx-csv-scope" name="csv_scope">
                <option value="all">投稿と固定ページ</option>
                <option value="post">投稿のみ</option>
                <option value="page">固定ページのみ</option>
            </select>
        </div>

        <div class="nx-import-export-warning">
            <strong>エクスポート対象外</strong>

            <p>
                ユーザー情報、パスワード、
                データベース接続情報、
                ログ、キャッシュは含まれません。
            </p>
        </div>

        <div class="nx-import-export-actions">
            <button
                type="submit"
                class="button button-primary"
            >
                エクスポートを実行
            </button>
        </div>
    </form>
</div>