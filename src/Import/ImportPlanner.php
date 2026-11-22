<?php

namespace NexaPressImportExport\Import;

use NexaPressImportExport\Repository\ImportRepository;
use NexaPressImportExport\Repository\SettingsRepository;
use NexaPressImportExport\Validation\ExportValidator;
use RuntimeException;

class ImportPlanner
{
    public function prepare(string $directory, string $mode, int $userId, array $limits, array $options): array
    {
        $modes = ExportValidator::modes();
        if (!isset($modes[$mode])) { throw new RuntimeException('選択された処理内容が正しくありません。'); }
        $options = ImportOptions::normalize($options);
        $plan = ['mode' => $mode, 'user_id' => $userId, 'options' => $options, 'content' => ['categories' => [], 'posts' => [], 'pages' => []], 'media' => [], 'settings' => []];
        $paths = [];
        if (!empty($modes[$mode]['media'])) {
            $plan['media'] = (new MediaImporter())->prepare($directory, $userId, $limits, $options);
            foreach ($plan['media'] as $item) {
                if ($item['source_key'] !== $item['key']) {
                    $paths[$item['source_key']] = $item['key'];
                    $encodedSource = implode('/', array_map('rawurlencode', explode('/', $item['source_key'])));
                    $encodedTarget = implode('/', array_map('rawurlencode', explode('/', $item['key'])));
                    $paths[$encodedSource] = $encodedTarget;
                }
            }
        }
        $rewriter = new UrlRewriter($options, $paths);
        if (!empty($modes[$mode]['content'])) {
            $plan['content'] = (new ContentImporter())->prepare($directory, $userId, $limits, $options, $rewriter);
        }
        if (!empty($modes[$mode]['settings'])) {
            $plan['settings'] = (new SettingsImporter())->prepare($directory, $limits, $options['import_debug'], $rewriter);
        }
        $plan['urls'] = $rewriter->result();
        return $plan;
    }

    public function targets(array $plan): array
    {
        $targets = ['categories' => [], 'posts' => [], 'pages' => [], 'media' => [], 'files' => [], 'settings' => []];
        foreach ($plan['content'] + ['media' => $plan['media']] as $type => $entries) {
            foreach ($entries as $entry) {
                if ($entry['action'] !== 'skip') {
                    $targets[$type][] = $entry['key'];
                    if ($type === 'media') {
                        $targets['files'][] = 'public/' . $entry['key'];
                        if (isset($entry['data']['folder_name'])) {
                            $targets['media_folders'][] = $entry['data']['folder_name'];
                        }
                    }
                }
            }
        }
        foreach (array_keys($plan['settings']) as $section) { $targets['settings'][] = 'config/' . $section . '.php'; }
        foreach ($targets as &$keys) {
            $keys = array_values(array_unique($keys));
            sort($keys, SORT_STRING);
        }
        return $targets;
    }

    public function summary(array $plan): array
    {
        $summary = ['counts' => [], 'items' => [], 'settings' => [], 'urls' => $plan['urls'], 'omitted' => 0];
        $lookup = new ImportRepository();
        foreach ($plan['content'] + ['media' => $plan['media']] as $type => $entries) {
            $summary['counts'][$type] = ['add' => 0, 'update' => 0, 'skip' => 0];
            foreach ($entries as $entry) {
                $summary['counts'][$type][$entry['action']]++;
                if (count($summary['items']) < 200) {
                    $changed = [];
                    $before = $lookup->find($type, $entry['key']);
                    if ($before !== null && $entry['action'] === 'update') {
                        foreach ($entry['data'] as $field => $value) {
                            if ($field !== 'created_at' && array_key_exists($field, $before) && (string)$before[$field] !== (string)$value) { $changed[] = $field; }
                        }
                        if (isset($before['user_id']) && (int)$before['user_id'] !== $plan['user_id']) { $changed[] = 'owner'; }
                    }
                    $summary['items'][] = [
                        'type' => $type, 'source' => $entry['source_key'], 'target' => $entry['key'],
                        'title' => $entry['data']['title'] ?? $entry['data']['name'] ?? basename($entry['key']),
                        'action' => $entry['action'], 'conflict' => $entry['conflict'], 'changed' => $changed,
                    ];
                } else { $summary['omitted']++; }
            }
        }
        if ($plan['settings'] !== []) {
            $current = (new SettingsRepository())->export();
            foreach ($plan['settings'] as $section => $values) {
                foreach ($values as $name => $value) {
                    $before = $current[$section][$name] ?? null;
                    if ($before !== $value) { $summary['settings'][] = ['name' => $section . '.' . $name, 'before' => $before, 'after' => $value]; }
                }
            }
        }
        return $summary;
    }
}
