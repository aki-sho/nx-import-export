<?php

namespace NexaPressImportExport\Admin;

use app\Core\Auth;
use app\Core\Csrf;
use app\Core\Permission;
use NexaPressImportExport\Import\BackupService;
use NexaPressImportExport\Import\ImportOptions;
use NexaPressImportExport\Import\ImportService;
use NexaPressImportExport\Import\PreviewStore;
use NexaPressImportExport\Support\Limits;
use NexaPressImportExport\Support\OperationLock;
use NexaPressImportExport\Validation\ImportValidator;
use RuntimeException;
use Throwable;

class ImportController
{
    public function preview(): void
    {
        $this->handle(function (): void {
            $mode = ImportValidator::mode($_POST['mode'] ?? '');
            if (($_FILES['import_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE && isset($_POST['preview_token'])) {
                $store = new PreviewStore();
                $existing = $store->get($this->identifier('preview_token'), $this->userId());
                $zipPath = $store->packagePath($existing);
            } else {
                $upload = ImportValidator::upload($_FILES['import_file'] ?? null, Limits::all());
                $zipPath = $upload['tmp_name'];
            }
            $options = ImportOptions::normalize([
                'conflict' => $_POST['conflict'] ?? 'overwrite',
                'url_from' => $_POST['url_from'] ?? '',
                'url_to' => $_POST['url_to'] ?? '',
                'import_debug' => ($_POST['import_debug'] ?? '') === '1',
            ]);
            $preview = (new ImportService())->preview($zipPath, $mode, $options);
            $_SESSION['nx_import_export_preview'] = $preview['id'];
            Notice::success('プレビューを作成しました。変更内容を確認してから実行してください。');
        });
    }

    public function import(): void
    {
        $this->handle(function (): void {
            $token = $this->identifier('preview_token');
            $result = (new ImportService())->confirm($token);
            if (($_SESSION['nx_import_export_preview'] ?? '') === $token) { unset($_SESSION['nx_import_export_preview']); }
            $count = 0;
            foreach (['categories', 'posts', 'pages', 'media', 'settings'] as $type) { $count += $result[$type]; }
            $message = 'インポートが完了しました。処理件数：' . $count . '件、スキップ：' . $result['skipped'] . '件。';
            if ($result['backup_id'] !== null) { $message .= '変更前のデータを自動バックアップしました。'; }
            if ($result['warning'] !== '') { $message .= $result['warning']; }
            Notice::success($message);
        });
    }

    public function cancel(): void
    {
        $this->handle(function (): void {
            $token = $this->identifier('preview_token');
            $lock = OperationLock::acquire();
            try { (new PreviewStore())->remove($token, $this->userId()); }
            finally { $lock->release(); }
            if (($_SESSION['nx_import_export_preview'] ?? '') === $token) { unset($_SESSION['nx_import_export_preview']); }
            Notice::success('プレビューを破棄しました。取り込み先のデータは変更していません。');
        });
    }

    public function restore(): void
    {
        $this->handle(function (): void {
            $result = (new BackupService())->restore($this->identifier('backup_id'), $this->userId());
            Notice::success('バックアップから復元しました。復元直前の状態もバックアップに保存しました。' . $result['warning']);
        });
    }

    private function handle(callable $action): void
    {
        Permission::require('extensions.manage');
        Csrf::requireValid($_POST['_csrf_token'] ?? null);
        try { $action(); }
        catch (Throwable $exception) {
            Notice::failure($exception, '取り込みまたは復元を完了できませんでした。サーバーのログを確認してください。');
        }
        $_SESSION['nx_import_export_active_tab'] = 'import';
        redirect_to('admin/extensions/' . NX_IMPORT_EXPORT_ID . '/dashboard');
    }

    private function identifier(string $name): string
    {
        $value = $_POST[$name] ?? '';
        if (!is_string($value) || !preg_match('/^[a-f0-9]{32}$/', $value)) {
            throw new RuntimeException('確認データの識別子が正しくありません。再度プレビューしてください。');
        }
        return $value;
    }

    private function userId(): int
    {
        $id = (int)(Auth::user()['id'] ?? 0);
        if ($id <= 0) { throw new RuntimeException('ログインユーザーを確認できません。'); }
        return $id;
    }
}
