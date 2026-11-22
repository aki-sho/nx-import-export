<?php

/*
 * php tests/security.php
 * NexaPress本体を変更せず、一時フォルダとSQLiteで回帰を検証する。
 */
namespace app\Core {
    class Auth
    {
        public static function user(): array { return ['id' => 1]; }
    }

    // End-to-end tests use the CMS implementation. These tests isolate package and database logic.
    class HtmlSanitizer
    {
        public static function sanitize(?string $html): string { return trim((string)$html); }
    }

    class Database
    {
        private static ?\PDO $connection = null;

        public static function connect(): \PDO
        {
            if (self::$connection === null) {
                self::$connection = new \PDO('sqlite::memory:');
                self::$connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                self::$connection->exec('CREATE TABLE media (id INTEGER PRIMARY KEY, title TEXT, description TEXT, original_name TEXT, file_name TEXT, file_path TEXT, mime_type TEXT, file_size INTEGER, file_type TEXT, user_id INTEGER, created_at TEXT, updated_at TEXT)');
                self::$connection->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY, name TEXT, slug TEXT, created_at TEXT, updated_at TEXT)');
                foreach (['posts', 'pages'] as $table) {
                    self::$connection->exec("CREATE TABLE $table (id INTEGER PRIMARY KEY, title TEXT, slug TEXT, content TEXT, status TEXT, user_id INTEGER, category_id INTEGER, published_at TEXT, created_at TEXT, updated_at TEXT, excerpt TEXT, featured_media_id INTEGER, deleted_at TEXT)");
                }
            }

            return self::$connection;
        }

        public static function table(string $name): string { return $name; }
    }
}

namespace {
    use NexaPressImportExport\Export\ExportService;
    use NexaPressImportExport\Import\ContentImporter;
    use NexaPressImportExport\Import\ImportService;
    use NexaPressImportExport\Import\MediaImporter;
    use NexaPressImportExport\Import\SettingsImporter;
    use NexaPressImportExport\Package\PackageBuilder;
    use NexaPressImportExport\Repository\SettingsRepository;
    use NexaPressImportExport\Support\Json;
    use NexaPressImportExport\Support\OperationLock;
    use NexaPressImportExport\Support\Zip;

    require dirname(__DIR__) . '/autoload.php';

    if (($argv[1] ?? '') === '--probe-lock') {
        $probeRoot = realpath($argv[2] ?? '');
        $probeTemp = realpath(sys_get_temp_dir());
        if (
            $probeRoot === false || $probeTemp === false ||
            !str_starts_with($probeRoot, $probeTemp . DIRECTORY_SEPARATOR) ||
            !str_starts_with(basename($probeRoot), 'nx-ie-security-')
        ) { exit(3); }
        define('BASE_PATH', $probeRoot);
        try {
            $probeLock = OperationLock::acquire();
            $probeLock->release();
            exit(0);
        } catch (RuntimeException $exception) {
            echo $exception->getMessage();
            exit(2);
        }
    }

    $root = sys_get_temp_dir() . '/nx-ie-security-' . bin2hex(random_bytes(8));
    mkdir($root, 0755, true);
    $root = realpath($root);
    $tempBase = realpath(sys_get_temp_dir());

    if (
        $root === false || $tempBase === false ||
        !str_starts_with($root, $tempBase . DIRECTORY_SEPARATOR) ||
        !str_starts_with(basename($root), 'nx-ie-security-')
    ) {
        throw new RuntimeException('Unsafe fixture directory');
    }

    define('BASE_PATH', $root);
    define('NX_IMPORT_EXPORT_PATH', dirname(__DIR__));
    mkdir($root . '/config');
    mkdir($root . '/public/uploads/media', 0755, true);
    $passed = 0;
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) { return false; }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    function check(bool $condition, string $message): void
    {
        if (!$condition) { throw new RuntimeException($message); }
    }

    function rejects(callable $action, string $messagePart): void
    {
        try { $action(); }
        catch (RuntimeException $exception) {
            check(str_contains($exception->getMessage(), $messagePart), 'Unexpected error: ' . $exception->getMessage());
            return;
        }
        throw new RuntimeException('Expected rejection: ' . $messagePart);
    }

    function test(string $name, callable $action): void
    {
        global $passed;
        $action();
        $passed++;
        echo "PASS: $name\n";
    }

    function mediaFixture(array $files): string
    {
        global $root;
        $directory = $root . '/package-' . bin2hex(random_bytes(4));
        mkdir($directory . '/data', 0755, true);
        foreach (['categories', 'posts', 'pages'] as $type) {
            Json::write($directory . '/data/' . $type . '.json', ['items' => [], 'count' => 0]);
        }
        $items = [];
        foreach ($files as $name => $contents) {
            $path = 'uploads/media/' . $name;
            $source = $directory . '/media/' . $path;
            if (!is_dir(dirname($source))) { mkdir(dirname($source), 0755, true); }
            file_put_contents($source, $contents);
            $items[] = ['file_path' => $path, 'archive_path' => 'media/' . $path, 'file_size' => strlen($contents)];
        }
        Json::write($directory . '/data/media.json', ['items' => $items, 'count' => count($items)]);
        return $directory;
    }

    function archive(array $files): string
    {
        global $root;
        $path = $root . '/archive-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = Zip::create($path);
        foreach ($files as $name => $contents) { $zip->addFromString($name, $contents); }
        check($zip->close(), 'Archive close failed');
        return $path;
    }

    function makeDirectoryLink(string $link, string $target): void
    {
        if (@symlink($target, $link)) { return; }
        if (PHP_OS_FAMILY !== 'Windows') { throw new RuntimeException('Directory symlink unavailable'); }

        $environment = getenv();
        $environment['NX_IE_TEST_LINK'] = $link;
        $environment['NX_IE_TEST_TARGET'] = $target;
        $process = proc_open(
            ['powershell.exe', '-NoProfile', '-NonInteractive', '-Command',
                'New-Item -ItemType Junction -Path $env:NX_IE_TEST_LINK -Target $env:NX_IE_TEST_TARGET -ErrorAction Stop | Out-Null'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, null, $environment, ['bypass_shell' => true]
        );
        check(is_resource($process), 'Cannot create junction process');
        foreach ($pipes as $pipe) { fclose($pipe); }
        check(proc_close($process) === 0 && is_dir($link), 'Cannot create test junction');
    }

    function removeFixture(string $path): void
    {
        global $root;
        check($path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR), 'Cleanup outside fixture');
        clearstatcache(true, $path);
        $resolved = realpath($path);
        $same = $resolved !== false && (PHP_OS_FAMILY === 'Windows'
            ? strcasecmp($resolved, $path) === 0 : $resolved === $path);

        if (is_link($path) || !$same) {
            // Windowsのリンク先を先に削除した場合もジャンクション自体を除去する。
            check(@rmdir($path) || @unlink($path), 'Could not remove fixture link');
        } elseif (is_dir($path)) {
            foreach (scandir($path) as $item) {
                if ($item !== '.' && $item !== '..') { removeFixture($path . DIRECTORY_SEPARATOR . $item); }
            }
            check(rmdir($path), 'Could not remove fixture directory');
        } else { check(unlink($path), 'Could not remove fixture file'); }
    }

    try {
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');

        test('valid media package round trip', function () use ($gif, $root) {
            $directory = mediaFixture(['normal.gif' => $gif]);
            $package = (new PackageBuilder())->build($directory, $root . '/output', 'content_media');
            $result = (new ImportService())->import($package['path'], 'content_media');
            check($result['media'] === 1, 'Missing media result');
            check(file_get_contents($root . '/public/uploads/media/normal.gif') === $gif, 'Media changed');
        });

        test('GIF with PHP or dangerous filenames rejected', function () use ($gif, $root) {
            foreach (['payload.php', 'payload.PHP', 'payload.php.gif', 'payload.phtml', '.htaccess', '.user.ini', 'payload.html', 'payload.svg'] as $name) {
                $directory = mediaFixture([$name => $gif . '<?php /* inert marker */ ?>']);
                rejects(fn() => (new MediaImporter())->import($directory, 1, []), str_starts_with($name, '.') || str_contains(strtolower($name), '.php') || str_ends_with($name, '.phtml') || str_ends_with($name, '.html') ? '実行可能' : '拡張子');
                check(!file_exists($root . '/public/uploads/media/' . $name), 'Unsafe file saved');
            }
        });

        test('MIME and extension mismatch rejected', function () use ($gif) {
            $directory = mediaFixture(['wrong.jpg' => $gif]);
            rejects(fn() => (new MediaImporter())->import($directory, 1, []), '一致しません');
        });

        test('safe text and CSV MIME fallback accepted', function () {
            $directory = mediaFixture(['notes.txt' => "plain text\n", 'table.csv' => "name,value\na,1\n"]);
            $importer = new MediaImporter();
            $result = $importer->import($directory, 1, []);
            check($result['count'] === 2, 'Text import failed');
            $importer->commit($result['state']);
        });

        test('parent symlink or junction cannot escape media root', function () use ($gif, $root) {
            mkdir($root . '/outside');
            $link = $root . '/public/uploads/media/linked';
            makeDirectoryLink($link, $root . '/outside');
            $directory = mediaFixture(['linked/escaped.gif' => $gif]);
            try { (new MediaImporter())->import($directory, 1, []); }
            catch (RuntimeException $exception) {
                check(!file_exists($root . '/outside/escaped.gif'), 'Wrote outside media root');
                check(str_contains($exception->getMessage(), 'リンク') || str_contains($exception->getMessage(), '範囲外'), 'Unexpected link rejection');
                return;
            }
            throw new RuntimeException('Parent link accepted');
        });

        test('failed media import restores replaced file and database', function () use ($gif, $root) {
            $old = $gif . 'old';
            file_put_contents($root . '/public/uploads/media/rollback.gif', $old);
            $directory = mediaFixture(['rollback.gif' => $gif . 'new', 'rejected.php' => $gif]);
            Json::write($directory . '/data/categories.json', ['items' => [['id' => 99, 'name' => 'rollback', 'slug' => 'rollback']], 'count' => 1]);
            $package = (new PackageBuilder())->build($directory, $root . '/rollback-output', 'content_media');
            rejects(fn() => (new ImportService())->import($package['path'], 'content_media'), '実行可能');
            check(file_get_contents($root . '/public/uploads/media/rollback.gif') === $old, 'Old file not restored');
            check((int)\app\Core\Database::connect()->query("SELECT COUNT(*) FROM categories WHERE slug = 'rollback'")->fetchColumn() === 0, 'Database not rolled back');
        });

        test('ZIP total size, JSON size, entry count and duplicates rejected before writes', function () use ($root) {
            $cases = [
                [archive(['a.txt' => '123456', 'b.txt' => '123456']), [10, 10, 100, 100], '容量'],
                [archive(['data.json' => str_repeat(' ', 11)]), [10, 100, 100, 10], '容量'],
                [archive(['a.txt' => 'a', 'b.txt' => 'b']), [1, 100, 100, 100], 'ファイル数'],
                [archive(['a.txt' => 'a', 'A.txt' => 'b']), [10, 100, 100, 100], '重複'],
            ];
            foreach ($cases as [$path, $limits, $message]) {
                $destination = $root . '/extract-' . bin2hex(random_bytes(3));
                rejects(fn() => Zip::extractSafe($path, $destination, ...$limits), $message);
                check(!is_dir($destination) || count(scandir($destination)) === 2, 'Wrote archive before preflight finished');
            }
        });

        test('stream exceeding declared byte budget is rejected without writing excess', function () {
            $input = fopen('php://temp', 'w+b');
            $output = fopen('php://temp', 'w+b');
            fwrite($input, str_repeat('x', 65537));
            rewind($input);
            $method = new ReflectionMethod(Zip::class, 'copyBounded');
            $method->setAccessible(true);
            try {
                rejects(fn() => $method->invoke(null, $input, $output, 65536), '実測容量');
                check(fstat($output)['size'] <= 65536, 'Wrote beyond byte budget');
            } finally { fclose($input); fclose($output); }
        });

        test('ZIP exact byte limit and empty file are accepted', function () use ($root) {
            $path = archive(['exact.txt' => '1234567890', 'empty.txt' => '']);
            $destination = $root . '/exact-extract';
            $entries = Zip::extractSafe($path, $destination, 2, 10, 10, 10);
            check(count($entries) === 2, 'Boundary archive rejected');
            check(file_get_contents($destination . '/exact.txt') === '1234567890', 'Boundary file changed');
            check(filesize($destination . '/empty.txt') === 0, 'Empty file changed');
        });

        test('ZIP traversal and Windows alternate paths rejected', function () use ($root) {
            foreach (['../escaped.txt', 'C:/escaped.txt', 'uploads/a.gif:stream', 'folder./a.txt', 'NUL.txt'] as $name) {
                $path = archive([$name => 'unsafe']);
                rejects(fn() => Zip::extractSafe($path, $root . '/unsafe-extract'), 'パス');
            }
        });

        test('content count limit rejects before database writes', function () {
            $directory = mediaFixture([]);
            Json::write($directory . '/data/categories.json', ['items' => [
                ['id' => 1, 'name' => 'one', 'slug' => 'one'],
                ['id' => 2, 'name' => 'two', 'slug' => 'two'],
            ], 'count' => 2]);
            rejects(fn() => (new ContentImporter())->import($directory, 1, ['max_content_items' => 1]), '合計件数');
            check((int)\app\Core\Database::connect()->query('SELECT COUNT(*) FROM categories')->fetchColumn() === 0, 'Content written over limit');
        });

        test('JSON structural and nesting limits; punctuation in strings remains valid', function () {
            rejects(fn() => Json::decode('[' . str_repeat('0,', 200000) . '0]'), '構造要素');
            rejects(fn() => Json::decode(str_repeat('[', 70) . '0' . str_repeat(']', 70)), '形式');
            $value = str_repeat('{[,\\"', 100000);
            check(Json::decode(Json::encode(['value' => $value]))['value'] === $value, 'String scanner changed valid JSON');
        });

        test('concurrent import and export rejected; failed request releases lock', function () use ($root) {
            $lock = OperationLock::acquire();
            try {
                rejects(fn() => (new ImportService())->import('missing.zip', 'content'), '実行中');
                rejects(fn() => (new ExportService())->create('content'), '実行中');
                $process = proc_open(
                    [PHP_BINARY, '-n', __FILE__, '--probe-lock', $root],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes, null, null, ['bypass_shell' => true]
                );
                check(is_resource($process), 'Cannot start lock probe');
                fclose($pipes[0]);
                $message = stream_get_contents($pipes[1]);
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                check(proc_close($process) === 2 && str_contains($message, '実行中'), 'Cross-process lock failed: ' . $errors);
            } finally { $lock->release(); }
            rejects(fn() => (new ImportService())->import('missing.zip', 'content'), '見つかりません');
            $package = (new ExportService())->create('content');
            check(is_file($package['path']), 'Lock was not released after failure');
        });

        test('debug string false and other non-booleans rejected without partial changes', function () use ($root) {
            $repository = new SettingsRepository();
            $repository->save(['general' => ['site_title' => 'original'], 'debug' => ['enabled' => false]]);
            foreach (['false', 'true', 0, 1, null, []] as $value) {
                rejects(fn() => $repository->save(['general' => ['site_title' => 'changed'], 'debug' => ['enabled' => $value]]), 'trueまたはfalse');
                check((require $root . '/config/general.php')['site_title'] === 'original', 'Partial config write');
                check((require $root . '/config/debug.php')['enabled'] === false, 'Debug changed on invalid input');
            }
        });

        test('debug import requires opt-in and accepts actual JSON booleans', function () use ($root) {
            $directory = mediaFixture([]);
            Json::write($directory . '/data/settings.json', ['sections' => ['general', 'debug'], 'settings' => [
                'general' => ['site_title' => 'imported'], 'debug' => ['enabled' => true],
            ]]);
            $importer = new SettingsImporter();
            check($importer->import($directory, []) === 1, 'Debug imported by default');
            check((require $root . '/config/debug.php')['enabled'] === false, 'Debug enabled without consent');
            $package = (new PackageBuilder())->build(settingsOnly($directory), $root . '/settings-output', 'settings');
            (new ImportService())->import($package['path'], 'settings', true);
            check((require $root . '/config/debug.php')['enabled'] === true, 'Explicit debug import failed');
            (new SettingsRepository())->save(['debug' => ['enabled' => false]]);
            check((require $root . '/config/debug.php')['enabled'] === false, 'Boolean false did not remain false');

            Json::write($directory . '/data/settings.json', ['sections' => ['debug'], 'settings' => ['debug' => ['enabled' => true]]]);
            rejects(fn() => $importer->import($directory, []), '専用のチェック');
            check((require $root . '/config/debug.php')['enabled'] === false, 'Debug-only package bypassed opt-in');
        });

        if (defined('NX_IE_RUN_FEATURES')) { require __DIR__ . '/feature-cases.php'; }
        echo "$passed regression tests passed.\n";
    } finally {
        removeFixture($root);
    }

    function settingsOnly(string $directory): string
    {
        global $root;
        $target = $root . '/settings-package';
        mkdir($target . '/data', 0755, true);
        copy($directory . '/data/settings.json', $target . '/data/settings.json');
        return $target;
    }
}
