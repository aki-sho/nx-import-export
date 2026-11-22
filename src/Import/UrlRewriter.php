<?php

namespace NexaPressImportExport\Import;

use NexaPressImportExport\Support\Limits;
use RuntimeException;

class UrlRewriter
{
    private array $options;
    private array $mediaPaths;
    private int $count = 0;
    private int $growth = 0;
    private int $maximum;
    private array $samples = [];

    public function __construct(array $options, array $mediaPaths = [])
    {
        $this->options = $options;
        $this->mediaPaths = $mediaPaths;
        $this->maximum = (int)(Limits::all()['max_json_size'] ?? 20971520);
        uksort($this->mediaPaths, static fn($a, $b) => strlen($b) <=> strlen($a));
    }

    public function rewrite(string $value, string $label): string
    {
        $before = $value;
        $size = strlen($value);
        $from = $this->options['url_from'];
        if ($from !== '' && $from !== $this->options['url_to']) {
            $pattern = '~(?<![A-Za-z0-9+._-])' . preg_quote($from, '~') . '(?=$|[/?#\s"\'<>\)\],;])~';
            $value = preg_replace_callback($pattern, function ($match) use (&$size) {
                return $this->replacement($match[0], $this->options['url_to'], $size);
            }, $value);
        }
        if ($this->mediaPaths !== []) {
            // 長いパスが多数あってもPCREのパターン上限を超えないよう分割する。
            // 中間値を置換対象から外し、別バッチでの連鎖的な再置換も防ぐ。
            do { $prefix = "\x1fNX" . bin2hex(random_bytes(8)) . ':'; } while (str_contains($value, $prefix));
            $markers = [];
            $batch = [];
            $length = 0;
            $batches = [];
            foreach (array_keys($this->mediaPaths) as $path) {
                $quoted = preg_quote($path, '~');
                if ($batch !== [] && $length + strlen($quoted) > 6000) { $batches[] = $batch; $batch = []; $length = 0; }
                $batch[] = $quoted;
                $length += strlen($quoted) + 1;
            }
            if ($batch !== []) { $batches[] = $batch; }
            foreach ($batches as $batch) {
                $pattern = '~(?<![A-Za-z0-9_%-])(?:' . implode('|', $batch) . ')(?=$|[?#\s"\'<>\)\],;])~';
                $value = preg_replace_callback($pattern, function ($match) use ($prefix, &$markers, &$size) {
                    $marker = $prefix . count($markers) . "\x1f";
                    $markers[$marker] = $this->replacement($match[0], $this->mediaPaths[$match[0]], $size);
                    return $marker;
                }, $value);
                if ($value === null) { throw new RuntimeException('メディア参照の置換を完了できませんでした。'); }
            }
            $value = strtr($value, $markers);
        }
        if ($value !== $before && count($this->samples) < 50) {
            $offset = 0;
            $common = min(strlen($value), strlen($before));
            while ($offset < $common && $value[$offset] === $before[$offset]) { $offset++; }
            $start = max(0, $offset - 80);
            while ($start > 0 && (ord($before[$start]) & 0xC0) === 0x80) { $start--; }
            $this->samples[] = ['label' => $label, 'before' => self::excerpt(substr($before, $start)), 'after' => self::excerpt(substr($value, $start))];
        }
        return $value;
    }

    public function mediaPath(string $path): string
    {
        return $this->mediaPaths[$path] ?? $path;
    }

    public function result(): array { return ['count' => $this->count, 'samples' => $this->samples]; }

    private function replacement(string $from, string $to, int &$size): string
    {
        $delta = strlen($to) - strlen($from);
        if ($delta > 0 && ($size + $delta > $this->maximum || $this->growth + $delta > $this->maximum)) {
            throw new RuntimeException('URL置換後の容量が制限を超えています。置換URLまたは取り込み範囲を見直してください。');
        }
        $size += $delta;
        $this->growth += max(0, $delta);
        $this->count++;
        return $to;
    }

    private static function excerpt(string $value): string
    {
        preg_match('/^.{0,240}/us', $value, $match);
        return $match[0] ?? '';
    }
}
