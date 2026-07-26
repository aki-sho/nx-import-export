<?php

/*
 * 必要なデータがない場合は終了
 */
if (
    !isset($modes) ||
    !is_array($modes)
) {
    return;
}

$fieldName =
    isset($fieldName)
        ? (string)$fieldName
        : 'mode';

$selectedMode =
    isset($selectedMode)
        ? (string)$selectedMode
        : 'content';

$inputIdPrefix =
    isset($inputIdPrefix)
        ? (string)$inputIdPrefix
        : 'nx-mode';
?>

<div class="nx-import-export-modes">
    <?php foreach (
        $modes as $modeKey => $mode
    ): ?>
        <?php
        if (!is_array($mode)) {
            continue;
        }

        $inputId =
            $inputIdPrefix
            . '-'
            . preg_replace(
                '/[^a-zA-Z0-9_-]/',
                '-',
                (string)$modeKey
            );
        ?>

        <label
            class="nx-import-export-mode"
            for="<?= e($inputId) ?>"
        >
            <input
                id="<?= e($inputId) ?>"
                type="radio"
                name="<?= e($fieldName) ?>"
                value="<?= e((string)$modeKey) ?>"
                <?= $selectedMode === (string)$modeKey
                    ? 'checked'
                    : '' ?>
                required
            >

            <span class="nx-import-export-mode-content">
                <strong>
                    <?= e(
                        (string)(
                            $mode['label']
                            ?? $modeKey
                        )
                    ) ?>
                </strong>

                <span>
                    <?= e(
                        (string)(
                            $mode['description']
                            ?? ''
                        )
                    ) ?>
                </span>
            </span>
        </label>
    <?php endforeach; ?>
</div>