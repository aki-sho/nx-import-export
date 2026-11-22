<?php

namespace NexaPressImportExport\Support {
    // 設定の2区分目の保存失敗を再現し、1区分目も復旧することを確認する。
    function rename(string $from, string $to): bool
    {
        if (($GLOBALS['nxFeatureFailConfig'] ?? false) && str_ends_with(str_replace('\\', '/', $to), '/config/url.php')) {
            $GLOBALS['nxFeatureFailConfig'] = false;
            throw new \RuntimeException('fixture config write failure');
        }
        return \rename($from, $to);
    }
}

namespace app\Core {
    class Permission
    {
        public static bool $allowed = true;
        public static function require(string $permission): void
        {
            if (!self::$allowed) { throw new \RuntimeException('fixture permission denied'); }
        }
    }
    class Csrf
    {
        public static function token(): string { return 'fixture-csrf'; }
        public static function requireValid(mixed $value): void
        {
            if ($value !== self::token()) { throw new \RuntimeException('fixture csrf denied'); }
        }
    }
}
