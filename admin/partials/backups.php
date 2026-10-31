<?php
use app\Core\Csrf;
if (!defined('NX_IMPORT_EXPORT_PATH')) { return; }
$statuses = ['ready' => '完了', 'pending' => '処理途中・要確認', 'failed' => '処理失敗', 'restored' => '復元済み'];
?>
<section class="nx-import-export-card nx-import-export-backups" aria-labelledby="nx-backups-heading">
    <div class="nx-import-export-card-header"><h2 id="nx-backups-heading">バックアップと復元</h2><p>変更対象だけを取り込み直前の状態へ戻します。対象に後から変更がある場合は、編集を守るため復元を止めます。</p></div>
    <div class="nx-import-export-review-body">
        <p>サイト全体で直近<?= e((string)($limits['max_backups'] ?? 5)) ?>件、合計<?= e((string)round(($limits['max_backup_storage_size'] ?? 1073741824) / 1024 / 1024)) ?>MBまで保存します。自分が実行した処理の履歴を表示します。</p>
        <?php if (($backups ?? []) === []): ?><p>まだバックアップはありません。</p><?php else: ?>
            <div class="nx-import-export-scroll"><table class="nx-import-export-table"><thead><tr><th>保存日時（JST）</th><th>内容</th><th>対象件数</th><th>状態</th><th>操作</th></tr></thead><tbody>
            <?php foreach ($backups as $backup):
                $created = (new DateTimeImmutable($backup['created_at']))->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y-m-d H:i:s');
                $count = 0; foreach (['categories', 'posts', 'pages', 'media', 'settings'] as $type) { $count += count($backup['targets'][$type]); }
            ?>
                <tr><td><?= e($created) ?></td><td><?= e($modes[$backup['mode']]['label'] ?? $backup['mode']) ?><br><?= ($backup['kind'] ?? '') === 'restore' ? '復元直前' : '取り込み直前' ?></td><td><?= e((string)$count) ?></td><td><?= e($statuses[$backup['status']] ?? '要確認') ?></td><td>
                    <?php if ($backup['status'] === 'ready'): ?>
                        <form method="post" action="<?= e(url('admin/extensions/' . NX_IMPORT_EXPORT_ID . '/restore')) ?>" data-confirm="このバックアップから復元します。対象の変更を元に戻し、追加されたデータも削除します。実行しますか？">
                            <input type="hidden" name="_csrf_token" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="backup_id" value="<?= e($backup['id']) ?>"><button type="submit" class="button">復元する</button>
                        </form>
                    <?php endif; ?>
                </td></tr>
            <?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </div>
</section>
