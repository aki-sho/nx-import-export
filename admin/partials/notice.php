<?php

/*
 * 通知がない場合は表示しない
 */
if (
    !isset($notice) ||
    !is_array($notice)
) {
    return;
}

$type =
    ($notice['type'] ?? '') === 'success'
        ? 'success'
        : 'error';

$message = trim(
    (string)($notice['message'] ?? '')
);

if ($message === '') {
    return;
}
?>

<div
    class="nx-import-export-notice is-<?= e($type) ?>"
    role="alert"
>
    <?= e($message) ?>
</div>