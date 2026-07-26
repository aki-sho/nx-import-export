<?php

namespace NexaPressImportExport\Admin;

class Notice
{
    /*
     * セッションに保存するキー
     */
    private const SESSION_KEY =
        'nx_import_export_notice';

    /*
     * 成功メッセージを保存
     */
    public static function success(
        string $message
    ): void {
        self::set(
            'success',
            $message
        );
    }

    /*
     * エラーメッセージを保存
     */
    public static function error(
        string $message
    ): void {
        self::set(
            'error',
            $message
        );
    }

    /*
     * メッセージを取得して削除
     */
    public static function pull(): ?array
    {
        $notice =
            $_SESSION[self::SESSION_KEY]
            ?? null;

        unset(
            $_SESSION[self::SESSION_KEY]
        );

        if (
            !is_array($notice) ||
            empty($notice['type']) ||
            empty($notice['message'])
        ) {
            return null;
        }

        return [
            'type' =>
                (string)$notice['type'],

            'message' =>
                (string)$notice['message'],
        ];
    }

    /*
     * メッセージをセッションへ保存
     */
    private static function set(
        string $type,
        string $message
    ): void {
        $_SESSION[self::SESSION_KEY] = [
            'type' => $type,
            'message' => $message,
        ];
    }
}