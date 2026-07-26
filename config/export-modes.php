<?php

/*
 * エクスポート・インポートの処理モード
 */
return [
    /*
     * 投稿・固定ページ・カテゴリー
     */
    'content' => [
        'label' => 'コンテンツのみ',
        'description' =>
            '投稿、固定ページ、カテゴリーを対象にします。',

        'content' => true,
        'media' => false,
        'settings' => false,
    ],

    /*
     * コンテンツとアップロード済みメディア
     */
    'content_media' => [
        'label' => 'コンテンツ＋メディア',
        'description' =>
            '投稿、固定ページ、カテゴリー、画像やファイルを対象にします。',

        'content' => true,
        'media' => true,
        'settings' => false,
    ],

    /*
     * NexaPressの設定
     */
    'settings' => [
        'label' => '設定のみ',
        'description' =>
            'サイト設定など、対応している設定項目だけを対象にします。',

        'content' => false,
        'media' => false,
        'settings' => true,
    ],
];