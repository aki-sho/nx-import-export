<?php

use app\Core\Database;
use NexaPressImportExport\Admin\ImportController;
use NexaPressImportExport\Import\BackupService;
use NexaPressImportExport\Import\ImportOptions;
use NexaPressImportExport\Import\ImportService;
use NexaPressImportExport\Import\PreviewStore;
use NexaPressImportExport\Import\UrlRewriter;
use NexaPressImportExport\Package\PackageBuilder;
use NexaPressImportExport\Repository\ContentRepository;
use NexaPressImportExport\Repository\ImportRepository;
use NexaPressImportExport\Repository\SettingsRepository;
use NexaPressImportExport\Support\Json;
use NexaPressImportExport\Support\PrivateStorage;

if (!defined('NX_IE_RUN_FEATURES')) { exit; }

function featurePackage(array $posts = [], array $categories = [], array $media = [], array $pages = []): string
{
    global $root;
    $directory = mediaFixture($media);
    foreach (['posts' => $posts, 'categories' => $categories, 'pages' => $pages] as $type => $items) {
        Json::write($directory . '/data/' . $type . '.json', ['items' => $items, 'count' => count($items)]);
    }
    if ($media === []) { unlink($directory . '/data/media.json'); }
    $package = (new PackageBuilder())->build($directory, $root . '/feature-output-' . bin2hex(random_bytes(5)), $media === [] ? 'content' : 'content_media');
    return $package['path'];
}

function featurePost(string $slug, string $content = '', ?int $categoryId = null): array
{
    return ['title' => 'Imported ' . $slug, 'slug' => $slug, 'content' => $content, 'status' => 'draft', 'category_id' => $categoryId,
        'created_at' => '2020-01-02 03:04:05', 'updated_at' => '2020-01-03 03:04:05', 'published_at' => null];
}

function featureSeed(string $slug): array
{
    $post = featurePost($slug, 'original content');
    $post['title'] = 'Original';
    (new ContentRepository())->savePost($post, 7, null);
    return (new ImportRepository())->find('posts', $slug);
}

function settingsPackage(array $settings): string
{
    global $root;
    $directory = $root . '/settings-' . bin2hex(random_bytes(5));
    Json::write($directory . '/data/settings.json', ['sections' => array_keys($settings), 'settings' => $settings]);
    return (new PackageBuilder())->build($directory, $root . '/settings-out-' . bin2hex(random_bytes(5)), 'settings')['path'];
}

function anyRejection(callable $action): void
{
    try { $action(); } catch (RuntimeException $exception) { return; }
    throw new RuntimeException('Expected rejection');
}

test('preview is read-only; confirm, restore and undo-restore preserve original identities', function () use ($gif, $root) {
    $before = featureSeed('feature-preview');
    $zip = featurePackage([
        featurePost('feature-preview', '<a href="https://old.example.test/blog/post">link</a>'),
        featurePost('feature-added', '<img src="https://old.example.test/uploads/media/preview-folder/new.gif">'),
    ], [], ['preview-folder/new.gif' => $gif]);
    $service = new ImportService();
    $preview = $service->preview($zip, 'content_media', ['url_from' => 'https://old.example.test', 'url_to' => 'https://new.example.test']);
    $lookup = new ImportRepository();
    check($lookup->find('posts', 'feature-preview') === $before, 'Preview changed existing post');
    check($lookup->find('posts', 'feature-added') === null, 'Preview inserted post');
    check(!is_dir($root . '/public/uploads/media/preview-folder'), 'Preview created public media directory');
    check($preview['summary']['counts']['posts'] === ['add' => 1, 'update' => 1, 'skip' => 0], 'Wrong preview counts');
    check($preview['summary']['urls']['count'] === 2, 'Wrong URL preview count');
    $result = $service->confirm($preview['id']);
    check($result['posts'] === 2 && $result['media'] === 1 && is_string($result['backup_id']), 'Confirmation did not import');
    check(str_contains($lookup->find('posts', 'feature-preview')['content'], 'https://new.example.test/blog/post'), 'URL not rewritten');
    $unrelated = featureSeed('feature-unrelated');
    anyRejection(fn() => $service->confirm($preview['id']));
    $restore = (new BackupService())->restore($result['backup_id'], 1);
    check($lookup->find('posts', 'feature-preview') === $before, 'Original ID, owner, dates or content not restored');
    check($lookup->find('posts', 'feature-added') === null, 'Added post not removed');
    check(!is_file($root . '/public/uploads/media/preview-folder/new.gif'), 'Added media not removed');
    check($lookup->find('posts', 'feature-unrelated') === $unrelated, 'Unrelated later content was changed by restore');
    (new BackupService())->restore($restore['backup_id'], 1);
    check($lookup->find('posts', 'feature-added') !== null && is_file($root . '/public/uploads/media/preview-folder/new.gif'), 'Undo of restore failed');
});

test('skip preserves existing records and maps categories for new posts', function () use ($gif, $root) {
    $before = featureSeed('feature-skip');
    $repository = new ContentRepository();
    $categoryId = $repository->saveCategory(['slug' => 'feature-skip-category', 'name' => 'Original category', 'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00']);
    $oldMedia = file_get_contents($root . '/public/uploads/media/normal.gif');
    $zip = featurePackage([
        featurePost('feature-skip', 'https://old.example.test/skipped', 42),
        featurePost('feature-skip-new', 'https://old.example.test/new', 42),
    ], [['id' => 42, 'slug' => 'feature-skip-category', 'name' => 'Imported category']], ['normal.gif' => $gif . 'new']);
    $result = (new ImportService())->import($zip, 'content_media', false, ['conflict' => 'skip', 'url_from' => 'https://old.example.test', 'url_to' => 'https://new.example.test']);
    $lookup = new ImportRepository();
    check($result['skipped'] === 3, 'Skipped counts wrong');
    check($lookup->find('posts', 'feature-skip') === $before, 'Skipped post modified');
    check((int)$lookup->find('posts', 'feature-skip-new')['category_id'] === $categoryId, 'Skipped category ID not mapped');
    check($lookup->find('categories', 'feature-skip-category')['name'] === 'Original category', 'Skipped category overwritten');
    check(file_get_contents($root . '/public/uploads/media/normal.gif') === $oldMedia, 'Skipped media overwritten');
});

test('rename avoids incoming names and rewrites category and media references', function () use ($gif, $root) {
    $before = featureSeed('feature-rename');
    $repository = new ContentRepository();
    $oldCategory = $repository->saveCategory(['slug' => 'feature-rename-category', 'name' => 'Original', 'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00']);
    $oldMedia = file_get_contents($root . '/public/uploads/media/normal.gif');
    $zip = featurePackage([
        featurePost('feature-rename', '<img src="https://old.example.test/uploads/media/normal.gif"> <a href="/uploads/media/normal-import-2.gif">second</a>', 55),
        featurePost('feature-rename-import-2'),
    ], [['id' => 55, 'slug' => 'feature-rename-category', 'name' => 'Imported']], ['normal.gif' => $gif . 'imported', 'normal-import-2.gif' => $gif . 'second']);
    $service = new ImportService();
    $preview = $service->preview($zip, 'content_media', ['conflict' => 'rename', 'url_from' => 'https://old.example.test', 'url_to' => 'https://new.example.test']);
    $result = $service->confirm($preview['id']);
    $lookup = new ImportRepository();
    $renamed = $lookup->find('posts', 'feature-rename-import-3');
    check($lookup->find('posts', 'feature-rename') === $before, 'Original post changed');
    check($lookup->find('posts', 'feature-rename-import-2') !== null && $renamed !== null, 'Incoming slug was stolen');
    check((int)$renamed['category_id'] !== $oldCategory, 'Renamed category not mapped');
    check(str_contains($renamed['content'], 'https://new.example.test/uploads/media/normal-import-3.gif'), 'Renamed media reference not updated');
    check(str_contains($renamed['content'], '/uploads/media/normal-import-2.gif'), 'Distinct reference modified');
    check(file_get_contents($root . '/public/uploads/media/normal.gif') === $oldMedia, 'Original file overwritten in rename mode');
    (new BackupService())->restore($result['backup_id'], 1);
    check($lookup->find('posts', 'feature-rename-import-3') === null, 'Renamed new post not undone');
});

test('URL replacement respects host and base path boundaries', function () {
    $rewriter = new UrlRewriter(ImportOptions::normalize(['url_from' => 'https://old.example.test/blog', 'url_to' => 'https://new.example.test/site']));
    $content = 'https://old.example.test/blog/post https://old.example.test/blog?x=1 https://old.example.test/blogger https://old.example.test.evil/blog //old.example.test/blog';
    $result = $rewriter->rewrite($content, 'sample');
    check(str_contains($result, 'https://new.example.test/site/post'), 'Base path URL not rewritten');
    check(str_contains($result, 'https://new.example.test/site?x=1'), 'Query URL not rewritten');
    check(str_contains($result, 'https://old.example.test/blogger') && str_contains($result, 'https://old.example.test.evil/blog') && str_contains($result, '//old.example.test/blog'), 'Unrelated URL was rewritten');
    check($rewriter->result()['count'] === 2, 'Boundary count wrong');
});

test('invalid URL and conflict options rejected', function () {
    foreach ([['url_from' => 'https://old.test'], ['url_from' => 'javascript:alert(1)', 'url_to' => 'https://new.test'], ['url_from' => 'https://user:password@old.test', 'url_to' => 'https://new.test'], ['url_from' => 'https://old.test/?x=1', 'url_to' => 'https://new.test'], ['url_from' => [], 'url_to' => ''], ['conflict' => 'delete']] as $options) {
        anyRejection(fn() => ImportOptions::normalize($options));
    }
});

test('large media reference maps are replaced once without PCRE overflow', function () {
    $map = [];
    for ($index = 0; $index < 4000; $index++) { $map['uploads/media/large-' . $index . '.gif'] = 'uploads/media/large-' . ($index + 1) . '.gif'; }
    $rewriter = new UrlRewriter(ImportOptions::normalize([]), $map);
    $result = $rewriter->rewrite('/uploads/media/large-0.gif /uploads/media/large-1.gif /uploads/media/large-3999.gif', 'large map');
    check($result === '/uploads/media/large-1.gif /uploads/media/large-2.gif /uploads/media/large-4000.gif', 'Chained or incomplete media replacement');
    check($rewriter->result()['count'] === 3, 'Large map count wrong');
});

test('URL preview excerpts show the changed part of long UTF-8 content', function () {
    $rewriter = new UrlRewriter(ImportOptions::normalize(['url_from' => 'https://old.test', 'url_to' => 'https://new.test']));
    $rewriter->rewrite(str_repeat('長い本文。', 100) . 'https://old.test/post', 'UTF-8 sample');
    $sample = $rewriter->result()['samples'][0];
    check(str_contains($sample['before'], 'https://old.test') && str_contains($sample['after'], 'https://new.test'), 'Excerpt hid the change');
    Json::encode($sample);
});

test('URL replacement expansion is bounded before allocating oversized output', function () {
    $rewriter = new UrlRewriter(ImportOptions::normalize(['url_from' => 'https://old.test', 'url_to' => 'https://new.test/' . str_repeat('a', 1900)]));
    rejects(fn() => $rewriter->rewrite(str_repeat('https://old.test/post ', 12000), 'expansion'), '置換後の容量');
});

test('changes after preview reject confirmation without overwriting later edits', function () {
    featureSeed('feature-stale');
    $service = new ImportService();
    $preview = $service->preview(featurePackage([featurePost('feature-stale')]), 'content');
    Database::connect()->exec("UPDATE posts SET title = 'Edited after preview' WHERE slug = 'feature-stale'");
    rejects(fn() => $service->confirm($preview['id']), 'プレビュー後');
    check((new ImportRepository())->find('posts', 'feature-stale')['title'] === 'Edited after preview', 'Later edit lost');
});

test('preview owner, expiry and ZIP integrity enforced', function () {
    $service = new ImportService();
    $preview = $service->preview(featurePackage([featurePost('feature-token')]), 'content');
    $store = new PreviewStore();
    rejects(fn() => $store->get($preview['id'], 2), '実行者');
    $path = $store->packagePath($preview);
    file_put_contents($path, 'corrupt');
    rejects(fn() => $service->confirm($preview['id']), '変更されています');
    $preview['expires_at'] = time() - 1;
    PrivateStorage::write('previews', $preview['id'], 'request.json', $preview);
    rejects(fn() => $store->get($preview['id'], 1), '有効期限');
    check((new ImportRepository())->find('posts', 'feature-token') === null, 'Invalid preview inserted data');
});

test('same ZIP can be re-previewed while old previews are pruned', function () {
    $service = new ImportService();
    $first = $service->preview(featurePackage([featurePost('feature-repreview')]), 'content');
    $store = new PreviewStore();
    $path = $store->packagePath($first);
    $service->preview(featurePackage([featurePost('feature-other-a')]), 'content');
    $service->preview(featurePackage([featurePost('feature-other-b')]), 'content');
    $next = $service->preview($path, 'content', ['conflict' => 'skip']);
    check($store->get($next['id'], 1)['options']['conflict'] === 'skip', 'Re-preview did not retain changed options');
    check(count(PrivateStorage::ids('previews')) <= 3, 'Preview count exceeds limit');
});

test('backup restore refuses later database edits and other owners', function () {
    $result = (new ImportService())->import(featurePackage([featurePost('feature-restore-edited')]), 'content');
    $backups = new BackupService();
    rejects(fn() => $backups->restore($result['backup_id'], 2), '実行者');
    Database::connect()->exec("UPDATE posts SET title = 'Later edit' WHERE slug = 'feature-restore-edited'");
    rejects(fn() => $backups->restore($result['backup_id'], 1), '取り込み後');
    check((new ImportRepository())->find('posts', 'feature-restore-edited')['title'] === 'Later edit', 'Restore overwrote edit');
});

test('backup restore refuses later changes to media files', function () use ($gif, $root) {
    $result = (new ImportService())->import(featurePackage([], [], ['feature-changed.gif' => $gif]), 'content_media');
    file_put_contents($root . '/public/uploads/media/feature-changed.gif', $gif . 'later');
    rejects(fn() => (new BackupService())->restore($result['backup_id'], 1), '取り込み後');
    check(file_get_contents($root . '/public/uploads/media/feature-changed.gif') === $gif . 'later', 'Later media edit lost');
});

test('backup protects categories referenced by posts created after import', function () {
    $result = (new ImportService())->import(featurePackage([featurePost('feature-category-tracked', '', 73)], [['id' => 73, 'slug' => 'feature-category-new', 'name' => 'New']]), 'content');
    $category = (new ImportRepository())->find('categories', 'feature-category-new');
    (new ContentRepository())->savePost(featurePost('feature-external-reference'), 8, (int)$category['id']);
    rejects(fn() => (new BackupService())->restore($result['backup_id'], 1), '別の投稿');
    check((new ImportRepository())->find('categories', 'feature-category-new') !== null, 'Referenced category removed');
});

test('settings preview and restore preserve raw original configuration and debug opt-in', function () use ($root) {
    $original = "<?php\n// original formatting\nreturn ['site_title' => 'Original config', 'timezone' => 'Asia/Tokyo', 'homepage_mode' => 'page', 'homepage_page_id' => 9];\n";
    file_put_contents($root . '/config/general.php', $original);
    (new SettingsRepository())->save(['debug' => ['enabled' => false]]);
    $zip = settingsPackage(['general' => ['site_title' => 'New config', 'site_icon' => 'https://old.test/icon.png'], 'debug' => ['enabled' => true]]);
    $service = new ImportService();
    $preview = $service->preview($zip, 'settings', ['url_from' => 'https://old.test', 'url_to' => 'https://new.test']);
    check(file_get_contents($root . '/config/general.php') === $original, 'Settings preview wrote config');
    check(count($preview['summary']['settings']) > 0, 'Settings diff absent');
    $result = $service->confirm($preview['id']);
    $general = require $root . '/config/general.php';
    check($general['site_icon'] === 'https://new.test/icon.png', 'Icon URL not rewritten');
    check($general['homepage_mode'] === 'page' && $general['homepage_page_id'] === 9, 'Destination homepage was changed');
    check(!isset((new SettingsRepository())->export()['general']['homepage_page_id']), 'Destination-specific page ID leaked into the settings package');
    check((require $root . '/config/debug.php')['enabled'] === false, 'Debug opt-in bypassed');
    (new BackupService())->restore($result['backup_id'], 1);
    check(file_get_contents($root . '/config/general.php') === $original, 'Original config bytes not restored');
});

test('settings write failure restores earlier sections automatically', function () use ($root) {
    $repository = new SettingsRepository();
    $repository->save(['general' => ['site_title' => 'Before failure'], 'url' => ['site_url_mode' => 'public']]);
    $beforeGeneral = file_get_contents($root . '/config/general.php');
    $beforeUrl = file_get_contents($root . '/config/url.php');
    $zip = settingsPackage(['general' => ['site_title' => 'Changed'], 'url' => ['site_url_mode' => 'root']]);
    $GLOBALS['nxFeatureFailConfig'] = true;
    try { rejects(fn() => (new ImportService())->import($zip, 'settings'), 'fixture config write failure'); }
    finally { $GLOBALS['nxFeatureFailConfig'] = false; }
    check(file_get_contents($root . '/config/general.php') === $beforeGeneral && file_get_contents($root . '/config/url.php') === $beforeUrl, 'Partial settings changes were left behind');
});

test('database failure after media copy rolls back rows and files', function () use ($gif, $root) {
    $pdo = Database::connect();
    $pdo->exec("CREATE TRIGGER feature_fail_media BEFORE INSERT ON media WHEN NEW.file_path = 'uploads/media/feature-fail.gif' BEGIN SELECT RAISE(ABORT, 'fixture media database failure'); END");
    try {
        $zip = featurePackage([featurePost('feature-failed-post')], [], ['feature-fail.gif' => $gif]);
        rejects(fn() => (new ImportService())->import($zip, 'content_media'), 'fixture media database failure');
    } finally { $pdo->exec('DROP TRIGGER feature_fail_media'); }
    check((new ImportRepository())->find('posts', 'feature-failed-post') === null, 'Failed import retained post');
    check(!is_file($root . '/public/uploads/media/feature-fail.gif'), 'Failed import retained media file');
});

test('restore database failure preserves imported state', function () use ($gif, $root) {
    $result = (new ImportService())->import(featurePackage([featurePost('feature-restore-fail')], [], ['feature-restore-fail.gif' => $gif]), 'content_media');
    $before = (new ImportRepository())->find('posts', 'feature-restore-fail');
    $pdo = Database::connect();
    $pdo->exec("CREATE TRIGGER feature_fail_restore BEFORE DELETE ON posts WHEN OLD.slug = 'feature-restore-fail' BEGIN SELECT RAISE(ABORT, 'fixture restore database failure'); END");
    try { rejects(fn() => (new BackupService())->restore($result['backup_id'], 1), 'fixture restore database failure'); }
    finally { $pdo->exec('DROP TRIGGER feature_fail_restore'); }
    check((new ImportRepository())->find('posts', 'feature-restore-fail') === $before, 'Failed restore modified row');
    check(file_get_contents($root . '/public/uploads/media/feature-restore-fail.gif') === $gif, 'Failed restore changed media');
});

test('corrupt backup is rejected without modifying site data', function () {
    $result = (new ImportService())->import(featurePackage([featurePost('feature-corrupt-backup')]), 'content');
    $path = PrivateStorage::directory('backups', $result['backup_id'], false) . '/before-posts.json';
    file_put_contents($path, '{}');
    rejects(fn() => (new BackupService())->restore($result['backup_id'], 1), '破損');
    check((new ImportRepository())->find('posts', 'feature-corrupt-backup') !== null, 'Corrupt backup altered data');
});

test('backup retention stays within configured count', function () {
    check(count(PrivateStorage::ids('backups')) <= 5, 'Backup count limit exceeded');
});

test('create plan cannot silently overwrite a record added before apply', function () {
    $directory = mediaFixture([]);
    Json::write($directory . '/data/posts.json', ['items' => [featurePost('feature-raced-post')], 'count' => 1]);
    $importer = new \NexaPressImportExport\Import\ContentImporter();
    $plan = $importer->prepare($directory, 1, []);
    $later = featureSeed('feature-raced-post');
    rejects(fn() => $importer->apply($plan, 1), '重複');
    check((new ImportRepository())->find('posts', 'feature-raced-post') === $later, 'Create plan overwrote a later record');
});

test('create media plan rejects a new conflicting record and restores its file', function () use ($gif, $root) {
    $directory = mediaFixture(['feature-raced-media.gif' => $gif . 'new']);
    $importer = new \NexaPressImportExport\Import\MediaImporter();
    $plan = $importer->prepare($directory, 1, []);
    (new \NexaPressImportExport\Repository\MediaRepository())->save($plan[0]['data'], 9);
    $path = $root . '/public/uploads/media/feature-raced-media.gif';
    file_put_contents($path, $gif . 'old');
    $later = (new ImportRepository())->find('media', 'uploads/media/feature-raced-media.gif');
    rejects(fn() => $importer->apply($directory, $plan, 1), '重複');
    check((new ImportRepository())->find('media', 'uploads/media/feature-raced-media.gif') === $later, 'New media record overwritten');
    check(file_get_contents($path) === $gif . 'old', 'New conflicting file not restored');
});

function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $value): string { return '/' . $value; }
function redirect_to(string $value): void { $GLOBALS['nxFeatureRedirect'] = $value; }
if (!defined('NX_IMPORT_EXPORT_ID')) { define('NX_IMPORT_EXPORT_ID', 'nx-import-export'); }

test('all management mutations require permission and CSRF before changing data', function () {
    $controller = new ImportController();
    $count = Database::connect()->query('SELECT COUNT(*) FROM posts')->fetchColumn();
    foreach (['preview', 'import', 'restore', 'cancel'] as $method) {
        $_POST = ['_csrf_token' => 'fixture-csrf'];
        \app\Core\Permission::$allowed = false;
        try { rejects(fn() => $controller->$method(), 'permission denied'); }
        finally { \app\Core\Permission::$allowed = true; }
        $_POST = ['_csrf_token' => 'incorrect'];
        rejects(fn() => $controller->$method(), 'csrf denied');
    }
    check(Database::connect()->query('SELECT COUNT(*) FROM posts')->fetchColumn() === $count, 'Guarded endpoint changed rows');
});

test('controller allows option changes and re-preview without a second upload', function () {
    $before = featureSeed('feature-controller');
    $zip = featurePackage([featurePost('feature-controller')]);
    $_SESSION = [];
    $_POST = ['_csrf_token' => 'fixture-csrf', 'mode' => 'content'];
    $_FILES = ['import_file' => ['name' => 'fixture.zip', 'tmp_name' => $zip, 'size' => filesize($zip), 'error' => UPLOAD_ERR_OK]];
    $controller = new ImportController();
    $controller->preview();
    $first = $_SESSION['nx_import_export_preview'];
    $_POST = ['_csrf_token' => 'fixture-csrf', 'preview_token' => $first, 'mode' => 'content', 'conflict' => 'skip'];
    $_FILES = ['import_file' => ['error' => UPLOAD_ERR_NO_FILE]];
    $controller->preview();
    $next = $_SESSION['nx_import_export_preview'];
    check($next !== $first, 'Re-preview token not renewed');
    check((new PreviewStore())->get($next, 1)['summary']['counts']['posts']['skip'] === 1, 'Controller did not change policy');
    $_POST = ['_csrf_token' => 'fixture-csrf', 'preview_token' => $next];
    $controller->import();
    check((new ImportRepository())->find('posts', 'feature-controller') === $before, 'Skipped controller import changed data');
    check(!isset($_SESSION['nx_import_export_preview']), 'Confirmed session preview retained');
});

test('confirmation uses stored options instead of tampered POST options', function () {
    featureSeed('feature-controller-confirm');
    $preview = (new ImportService())->preview(featurePackage([featurePost('feature-controller-confirm', 'https://old.test/post')]), 'content');
    $_POST = ['_csrf_token' => 'fixture-csrf', 'preview_token' => $preview['id'], 'conflict' => 'rename', 'url_from' => 'https://old.test', 'url_to' => 'javascript:alert(1)', 'import_debug' => '1'];
    (new ImportController())->import();
    $lookup = new ImportRepository();
    check($lookup->find('posts', 'feature-controller-confirm')['content'] === 'https://old.test/post', 'POST replaced stored options');
    check($lookup->find('posts', 'feature-controller-confirm-import-2') === null, 'POST changed stored conflict policy');
});

test('dashboard shows preview and backup forms with escaped imported values and CSRF', function () {
    $post = featurePost('feature-ui');
    $post['title'] = '<script>alert("feature-ui")</script>';
    $preview = (new ImportService())->preview(featurePackage([$post]), 'content');
    $_SESSION['nx_import_export_preview'] = $preview['id'];
    $extensionInfo = ['version' => '1.1.0'];
    ob_start();
    require NX_IMPORT_EXPORT_PATH . '/admin/dashboard.php';
    $html = ob_get_clean();
    check(!str_contains($html, $post['title']) && str_contains($html, '&lt;script&gt;'), 'Unescaped preview title');
    check(str_contains($html, '確認した内容で実行') && str_contains($html, '条件を変更して再プレビュー') && str_contains($html, 'バックアップと復元'), 'Missing feature controls');
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    foreach ($xpath->query('//form') as $form) {
        check($xpath->query('.//input[@name="_csrf_token"]', $form)->length === 1, 'Form missing CSRF token');
    }
    check($xpath->query('//form[contains(@action,"/import")]//input[@name="preview_token"]')->length === 1, 'Confirmation missing token');
    check($xpath->query('//form[contains(@action,"/restore")]//input[@name="backup_id"]')->length > 0, 'Restore form missing backup ID');
});
