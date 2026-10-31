<?php

namespace NexaPressImportExport\Support;

class Limits
{
    public static function all(): array
    {
        $path = NX_IMPORT_EXPORT_PATH . '/config/limits.php';
        $limits = is_file($path) ? require $path : [];
        return is_array($limits) ? $limits : [];
    }
}
