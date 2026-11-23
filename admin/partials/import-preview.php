<?php
use app\Core\Csrf;
if (!defined('NX_IMPORT_EXPORT_PATH') || !is_array($preview ?? null)) { return; }
$summary = $preview['summary'];
$categoriesOnly = !empty($modes[$preview['mode']]['categories_only']);
$types = $categoriesOnly
    ? ['categories' => 'カテゴリ']
    : ['categories' => 'カテゴリー', 'posts' => '投稿', 'pages' => '固定ページ', 'media' => 'メディア'];
$actions = ['add' => '追加', 'update' => '上書き', 'skip' => 'スキップ'];
$policies = ['overwrite' => '上書き', 'skip' => 'スキップ', 'rename' => '別名で追加'];
$fields = ['name' => '名前', 'title' => 'タイトル', 'content' => '本文', 'status' => '公開状態', 'slug' => 'スラッグ', 'published_at' => '公開日時', 'created_at' => '作成日時', 'updated_at' => '更新日時', 'description' => '説明', 'original_name' => '元ファイル名', 'file_name' => '保存名', 'file_path' => '保存先', 'mime_type' => '形式', 'file_size' => '容量', 'file_type' => '種類'];
$settingsLabels = ['general.site_title' => 'サイト名', 'general.timezone' => 'タイムゾーン', 'general.site_icon' => 'サイトアイコン', 'general.discourage_search_engines' => '検索エンジンへの非表示設定', 'url.site_url_mode' => '公開URL', 'url.post_url_type' => '投稿URL形式', 'url.page_url_type' => '固定ページURL形式', 'debug.enabled' => 'デバッグ'];
$displayValue = static fn($value) => is_bool($value) ? ($value ? '有効' : '無効') : ($value === null ? '未設定' : (string)$value);
$fields['owner'] = '所有者';
$fields['category_id'] = 'カテゴリ';
$expires = (new DateTimeImmutable('@' . $preview['expires_at']))->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('H:i');
?>
<section class="nx-import-export-card nx-import-export-review" aria-labelledby="nx-preview-heading">
    <div class="nx-import-export-card-header">
        <h2 id="nx-preview-heading">取り込み前の確認</h2>
        <p>まだデータは変更していません。重複時は「<?= e($policies[$preview['options']['conflict']]) ?>」で処理します。有効期限：<?= e($expires) ?>（JST）</p>
    </div>
    <div class="nx-import-export-review-body">
        <div class="nx-import-export-summary">
            <?php foreach ($types as $type => $label): $counts = $summary['counts'][$type]; ?>
                <div><strong><?= e($label) ?></strong><span>追加 <?= e((string)$counts['add']) ?> ／ 上書き <?= e((string)$counts['update']) ?> ／ スキップ <?= e((string)$counts['skip']) ?></span></div>
            <?php endforeach; ?>
        </div>
        <?php if ($preview['mode'] === 'csv'): ?>
            <p>新規に追加する投稿と固定ページは下書きになります。「公開中」と表示された項目の上書きは、公開サイトにも反映されます。</p>
        <?php endif; ?>
        <?php if ($preview['mode'] === 'settings'): ?>
            <p>設定は差分を確認したうえで上書きします。デバッグ設定：<?= $preview['options']['import_debug'] ? '取り込む' : '取り込まない' ?>。</p>
        <?php endif; ?>
        <?php if ($summary['items'] !== []): ?>
            <details open>
                <summary>対象データと重複の確認</summary>
                <div class="nx-import-export-scroll">
                    <table class="nx-import-export-table"><thead><tr><th>対象</th><th>処理</th><th>元の識別子 → 取り込み先</th><th>変更する項目</th></tr></thead><tbody>
                    <?php foreach ($summary['items'] as $item): ?>
                        <tr><td><?= e($types[$item['type']]) ?><br><?= e($item['title']) ?></td><td><?= e($actions[$item['action']]) ?><?= $item['conflict'] ? '（重複あり）' : '' ?><?= !empty($item['replaces_published']) ? '（公開中）' : '' ?></td>
                            <td><code><?= e($item['source']) ?></code><?php if ($item['source'] !== $item['target']): ?><br>→ <code><?= e($item['target']) ?></code><?php endif; ?></td>
                            <td><?= e(implode('・', array_map(static fn($field) => $fields[$field] ?? $field, $item['changed']))) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table>
                </div>
                <?php if ($summary['omitted'] > 0): ?><p>一覧は先頭200件です。ほか<?= e((string)$summary['omitted']) ?>件は上の件数に含まれます。</p><?php endif; ?>
            </details>
        <?php endif; ?>
        <?php if ($preview['mode'] === 'settings'): ?>
            <h3>設定の変更</h3>
            <?php if ($summary['settings'] === []): ?><p>値の変更はありません。選択した設定ファイルを保存します。</p><?php else: ?>
                <div class="nx-import-export-scroll"><table class="nx-import-export-table"><thead><tr><th>設定</th><th>現在</th><th>取り込み後</th></tr></thead><tbody>
                <?php foreach ($summary['settings'] as $setting): ?><tr><td><?= e($settingsLabels[$setting['name']] ?? $setting['name']) ?></td><td><?= e($displayValue($setting['before'])) ?></td><td><?= e($displayValue($setting['after'])) ?></td></tr><?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (!$categoriesOnly): ?>
            <h3>URL・メディア参照の置換</h3>
            <p><?= e((string)$summary['urls']['count']) ?>箇所を置換します。スキップするデータは変更しません。</p>
            <?php if ($preview['options']['url_from'] !== ''): ?><p><code><?= e($preview['options']['url_from']) ?></code> → <code><?= e($preview['options']['url_to']) ?></code></p><?php endif; ?>
            <?php if ($summary['urls']['samples'] !== []): ?>
                <details><summary>置換例を確認（最大50項目）</summary>
                    <?php foreach ($summary['urls']['samples'] as $sample): ?><div class="nx-import-export-url-example"><strong><?= e($sample['label']) ?></strong><pre><?= e($sample['before']) ?></pre><span>↓</span><pre><?= e($sample['after']) ?></pre></div><?php endforeach; ?>
                </details>
            <?php endif; ?>
        <?php endif; ?>
        <p class="nx-import-export-help">実行直前に変更前のデータを自動バックアップします。確認後に対象データが変わった場合は処理を中止します。</p>
    </div>
    <div class="nx-import-export-review-actions">
        <form method="post" action="<?= e(url('admin/extensions/' . NX_IMPORT_EXPORT_ID . '/import')) ?>" data-confirm="確認した内容で取り込みを実行します。よろしいですか？">
            <input type="hidden" name="_csrf_token" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="preview_token" value="<?= e($preview['id']) ?>">
            <button type="submit" class="button button-primary">確認した内容で実行</button>
        </form>
        <form method="post" action="<?= e(url('admin/extensions/' . NX_IMPORT_EXPORT_ID . '/cancel-preview')) ?>">
            <input type="hidden" name="_csrf_token" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="preview_token" value="<?= e($preview['id']) ?>">
            <button type="submit" class="button">プレビューを破棄</button>
        </form>
    </div>
</section>
