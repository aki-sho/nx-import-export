<?php

use app\Core\Csrf;
use NexaPressImportExport\Support\CsvContent;

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
$selectedMode = $preview['mode'] ?? 'content';
$inputIdPrefix = 'nx-import-mode';
$importOptions = $preview['options'] ?? ['conflict' => '', 'url_from' => '', 'url_to' => '', 'import_debug' => false];
?>

<?php if (($managementError ?? '') !== ''): ?><div class="nx-import-export-notice is-error" role="alert"><?= e($managementError) ?></div><?php endif; ?>
<?php require NX_IMPORT_EXPORT_PATH . '/admin/partials/import-preview.php'; ?>

<div class="nx-import-export-card">
    <div class="nx-import-export-card-header">
        <h2>インポート</h2>

        <p>
            CSVまたはNX Import ExportのZIPを読み込み、変更内容をプレビューします。
        </p>
    </div>

    <form
        method="post"
        enctype="multipart/form-data"
        action="<?= e(
            url(
                'admin/extensions/'
                . NX_IMPORT_EXPORT_ID
                . '/preview'
            )
        ) ?>"
        class="nx-import-export-form"
    >
        <input
            type="hidden"
            name="_csrf_token"
            value="<?= e(Csrf::token()) ?>"
        >

        <?php if ($preview !== null): ?><input type="hidden" name="preview_token" value="<?= e($preview['id']) ?>"><?php endif; ?>

        <div class="nx-import-export-field">
            <label for="nx-import-file">
                インポートファイル
            </label>

            <input
                id="nx-import-file"
                type="file"
                name="import_file"
                accept=".zip,.csv,application/zip,text/csv"
                <?= $preview === null ? 'required' : '' ?>
            >

            <p class="nx-import-export-help">
                ZIPの設定上限 <?= e((string)$maxPackageSizeMb) ?> MB ／
                CSVの受信上限 <?= e(number_format(CsvContent::uploadLimit($limits))) ?>バイト・最大<?= e((string)$limits['max_csv_rows']) ?>件
                <?= $preview !== null ? '。ファイルを選択しなければ現在のファイルで再確認します。' : '' ?>
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

        <details>
            <summary>CSVの書式</summary>
            <p>文字コードはUTF-8です。見出しに <code>type</code>、<code>title</code>、<code>slug</code>、<code>content</code> が必要です。</p>
            <p><code>type</code> に投稿は <code>post</code>、固定ページは <code>page</code> を指定します。新規は下書きです。</p>
            <p>エクスポートしたCSVをひな形に使えます。表計算ソフトで編集する際はセルの文字数制限を確認してください。</p>
        </details>

        <fieldset data-nx-content-options>
            <legend>同じスラッグ・メディア保存先がある場合</legend>
            <label for="nx-import-conflict">重複時の処理</label>
            <select id="nx-import-conflict" name="conflict">
                <option value="" <?= $importOptions['conflict'] === '' ? 'selected' : '' ?>>形式の初期設定を使用（CSVはスキップ、ZIPは上書き）</option>
                <?php foreach (['overwrite' => '既存データを上書き', 'skip' => '既存データを残してスキップ', 'rename' => '別名で追加して両方を残す'] as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $importOptions['conflict'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="nx-import-export-help">別名で追加したカテゴリーの関連付けや、本文内のメディア参照も更新します。設定はプレビューを確認してから上書きします。</p>
        </fieldset>

        <fieldset>
            <legend>移行時のURL置換（任意）</legend>
            <div class="nx-import-export-url-fields">
                <div class="nx-import-export-field"><label for="nx-url-from">移行元のURL</label><input id="nx-url-from" name="url_from" type="url" placeholder="https://old.example.com" maxlength="2048" value="<?= e($importOptions['url_from']) ?>"></div>
                <div class="nx-import-export-field"><label for="nx-url-to">移行先のURL</label><input id="nx-url-to" name="url_to" type="url" placeholder="https://new.example.com" maxlength="2048" value="<?= e($importOptions['url_to']) ?>"></div>
            </div>
            <p class="nx-import-export-help">本文内の絶対URLとサイトアイコンを置換します。サブディレクトリまで指定できます。両方空欄ならURLは変更しません。</p>
        </fieldset>

        <div class="nx-import-export-field" data-nx-debug-option>
            <label>
                <input type="checkbox" name="import_debug" value="1" <?= $importOptions['import_debug'] ? 'checked' : '' ?>>
                デバッグ設定も取り込む
            </label>
            <p class="nx-import-export-help">
                「設定のみ」で有効です。デバッグが有効になると詳細なエラー情報が表示される場合があります。
            </p>
        </div>

        <div class="nx-import-export-warning">
            <strong>注意</strong>

            <p>
                この段階では取り込み先を変更しません。プレビューの確認後に実行すると、変更前のデータを自動バックアップします。
            </p>
        </div>

        <div class="nx-import-export-actions">
            <button
                type="submit"
                class="button button-primary"
            >
                <?= $preview === null ? '変更内容をプレビュー' : '条件を変更して再プレビュー' ?>
            </button>
        </div>
    </form>
</div>

<?php require NX_IMPORT_EXPORT_PATH . '/admin/partials/backups.php'; ?>
