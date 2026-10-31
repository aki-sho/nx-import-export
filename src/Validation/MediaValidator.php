<?php

namespace NexaPressImportExport\Validation;

use NexaPressImportExport\Support\Zip;
use RuntimeException;

class MediaValidator
{
    private const EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg', 'jpe'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
        'image/webp' => ['webp'],
        'audio/mpeg' => ['mp3'],
        'audio/wav' => ['wav'],
        'audio/x-wav' => ['wav'],
        'audio/mp4' => ['m4a', 'mp4'],
        'audio/ogg' => ['ogg', 'oga'],
        'video/mp4' => ['mp4', 'm4v'],
        'video/webm' => ['webm'],
        'video/quicktime' => ['mov'],
        'application/pdf' => ['pdf'],
        'text/plain' => ['txt', 'csv'],
        'text/csv' => ['csv'],
        'application/msword' => ['doc'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.ms-excel' => ['xls'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'application/vnd.ms-powerpoint' => ['ppt'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
    ];

    public static function path(string $filePath): string
    {
        $filePath = Zip::normalizePath($filePath);

        if (!str_starts_with($filePath, 'uploads/media/')) {
            throw new RuntimeException('メディアファイルのパスが正しくありません。');
        }

        foreach (explode('/', $filePath) as $part) {
            if (
                str_starts_with($part, '.') ||
                preg_match('/\.(?:php\d*|phtml|pht|phar|phps|cgi|pl|py|rb|asp|aspx|jsp|jspx|shtml|shtm|htm|html)(?:\.|$)/i', $part)
            ) {
                throw new RuntimeException('実行可能なファイル名はメディアに使用できません。');
            }
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $allowed = false;

        foreach (self::EXTENSIONS as $extensions) {
            if (in_array($extension, $extensions, true)) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            throw new RuntimeException('使用できないメディアの拡張子です。');
        }

        return $filePath;
    }

    public static function type(string $filePath, string $mimeType): void
    {
        self::path($filePath);
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if (!in_array($extension, self::EXTENSIONS[$mimeType] ?? [], true)) {
            throw new RuntimeException('メディアの拡張子と実際の形式が一致しません。');
        }
    }
}
