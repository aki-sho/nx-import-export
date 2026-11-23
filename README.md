# NX Import Export

> 最新バージョン 1.4.0（2026-11-23）

NexaPressのコンテンツ、メディア、設定を
ZIPやCSVファイルでエクスポート・インポートする拡張機能です。

## バージョン

```text
1.4.0
```

## 対応環境

- NexaPress 2.33.0以上
- PHP 8.0以上
- PHP PDO MySQL拡張
- PHP ZipArchive拡張
- PHP Fileinfo拡張

## 主な機能

### カテゴリのみ（ZIP／CSV）

カテゴリ名とスラッグをまとめて書き出し・取り込みできます。「カテゴリのみ（ZIP）」では、
「コンテンツのみ」や「コンテンツ＋メディア」で書き出したZIPからもカテゴリを取り込めます。
CSVの文字コードはUTF-8で、見出しは <code>name</code> と <code>slug</code> です。
エクスポートしたCSVはそのまま取り込めます。

取り込み前に重複を確認できます。同じスラッグは既定でスキップし、上書きや別名での追加も選べます。

### CSV（投稿・固定ページ）

タイトル、スラッグ、本文や投稿のカテゴリを扱えます。新規の投稿・固定ページは下書きで取り込みます。

### コンテンツのみ

対象：

- 投稿
- 固定ページ
- カテゴリー

ユーザー情報やパスワードは含まれません。

### コンテンツ＋メディア

対象：

- 投稿
- 固定ページ
- カテゴリー
- メディア情報
- アップロード済みの画像やファイル

### 設定のみ

対象：

- 一般設定
- URL設定
- デバッグ設定

データベース接続情報や更新設定は含まれません。

## v1.2.0で追加した機能

- 投稿の抜粋とアイキャッチ画像を引き継ぎます。画像も移すときは「コンテンツ＋メディア」を選択します。
- メディアのフォルダ名を対応するNexaPressへ引き継ぎます。画像などが入ったフォルダを再作成し、同名のフォルダがあれば使用します。
- 書き出し対象は公開中と下書きの投稿・固定ページです。取り込み前に本文をNexaPressのHTML検査に通します。
- 設定を取り込む際、移行先のトップページ指定を保持します。以前に書き出したZIPも取り込めます。

## v1.1.0で追加した機能

- 取り込み前に、追加・上書き・スキップ件数と変更する設定をプレビューします。
- 重複時は「上書き」「スキップ」「別名で追加」から選択できます。
- 実行直前のデータを自動バックアップし、管理画面から復元できます。
- 移行元URLを移行先URLへ置換し、置換例を確認できます。

## エクスポート対象外

次の情報はエクスポートされません。

- ユーザー情報
- パスワード
- データベース接続情報
- NexaPress本体ファイル
- 拡張機能本体
- テーマ本体
- ログ
- キャッシュ
- 一時ファイル

## 管理画面

拡張機能を有効にすると、管理画面の拡張機能メニューに
「インポート・エクスポート」が追加されます。

管理画面には次の2つのタブがあります。

```text
インポート・エクスポート
├─ エクスポート
└─ インポート
```

## エクスポートZIPの構成

### コンテンツのみ

```text
nexapress-export-YYYYMMDD-HHMMSS.zip
├─ export-manifest.json
├─ checksums.json
└─ data/
   ├─ categories.json
   ├─ pages.json
   └─ posts.json
```

### コンテンツ＋メディア

```text
nexapress-export-YYYYMMDD-HHMMSS.zip
├─ export-manifest.json
├─ checksums.json
├─ data/
│  ├─ categories.json
│  ├─ pages.json
│  ├─ posts.json
│  └─ media.json
└─ media/
   └─ uploads/
      └─ media/
         └─ メディアファイル
```

### 設定のみ

```text
nexapress-export-YYYYMMDD-HHMMSS.zip
├─ export-manifest.json
├─ checksums.json
└─ data/
   └─ settings.json
```

## インポート時の動作

### コンテンツ

投稿、固定ページ、カテゴリーは、同じスラッグがある場合の処理を選べます。

- 上書き：既存データを更新します（標準）。
- スキップ：既存データと所有者を変更しません。
- 別名で追加：既存データを残し、`-import-2`などの接尾辞を付けて追加します。

別名が他の既存データや取り込みデータと重なる場合は、別の番号を採番します。

同じスラッグが存在しない場合は、
新しいデータとして追加します。

追加・上書きした投稿と固定ページの所有者は、
インポートを実行した管理者になります。

### カテゴリー

エクスポート元とインポート先では
カテゴリーIDが異なる場合があります。

インポート時にカテゴリーIDを変換し、
投稿との関連付けを維持します。

### メディア

メディアは次の場所へ復元されます。

```text
public/uploads/media/
```

同じ保存先のファイルが存在する場合も、重複時の処理選択に従います。
上書き時は既存ファイルをバックアップしてから置き換えます。
別名で追加する場合は、取り込む投稿・固定ページ本文内の参照も新しい保存先へ変更します。
URLエンコードされたファイル名も対象です。

インポートに失敗した場合は、
可能な範囲で元のファイルへ戻します。

### 設定

次の設定ファイルへ保存します。

```text
config/general.php
config/url.php
config/debug.php
```

データベース設定ファイルは変更しません。

デバッグ設定は標準では取り込みません。
「デバッグ設定も取り込む」を選択した場合だけ、`config/debug.php`へ反映します。
デバッグの`enabled`はJSONの真偽値（`true`または`false`）だけを受け付けます。
文字列の`"false"`や数値の`0`などは拒否します。
取り込む設定は、全項目の検証を終えてから保存を開始します。

### プレビューとURL置換

ZIPの構成・チェックサム・各データ・メディア形式を検証してから、
追加・上書き・スキップの件数を表示します。識別子と変更項目は先頭200件、
URLの置換例は変更箇所の前後を最大50項目表示します。

プレビューではDB、公開メディア、設定ファイルを変更しません。
確認用ZIPは非公開の`storage/nx-import-export/previews/`へ一時保存します。
条件を変更して再プレビューする場合は、ZIPを再選択する必要はありません。
実行者・有効期限・ZIPのハッシュ・確認した対象の状態を再検証してから取り込みます。

URL置換は任意です。移行元と移行先の両方にhttp/httpsのベースURLを入力してください。
本文内の絶対URLとサイトアイコンが対象です。サブディレクトリの指定も可能です。
別ドメインや似た名前のディレクトリは置換しません。認証情報・クエリ・フラグメントを
含むベースURLは指定できません。プロトコル相対URLや特殊なエンコード形式は、
このベースURL置換の対象外です。スキップするデータのURLは変更しません。

### 自動バックアップと復元

変更対象がある場合、取り込み開始前のDB行・メディア実体・設定ファイルを
`storage/nx-import-export/backups/`に保存します。
ユーザー情報、DBのスキーマ、テーマ、本体ファイルは対象に含めません。

管理画面の「バックアップと復元」から、自分が実行した取り込みを復元できます。
追加した行・ファイルは削除し、更新した行は元のID・所有者・日時へ、
設定ファイルは元のバイト列へ戻します。復元直前の状態も保存するため、
そのバックアップを復元すれば、復元操作自体を取り消せます。

取り込み後に対象の行・ファイル・設定が変わっている場合は、復元を中止します。
追加したカテゴリーが別の投稿から参照されている場合も中止します。
追加メディアは復元時に削除されるため、取り込み後に別の記事から参照した場合は
復元前に参照先を確認してください。変更対象以外のデータは復元しません。

バックアップはサイト全体で直近5件・合計1GB、1件500MBまで保存します。
上限に達した場合は、古い完了・復元済み・失敗履歴を順に削除します。
処理途中で復旧確認が必要なバックアップは自動削除しません。
必要なバックアップを作成できない場合は、取り込みを開始しません。

## セキュリティ

インポート時は次の検証を行います。

- 管理者権限
- CSRFトークン
- ZIP拡張子
- ZIPファイル署名
- アップロード容量
- ZIP内のファイル数
- ZIP展開後の容量
- 不正な相対パス
- ディレクトリトラバーサル
- シンボリックリンク
- JSON形式
- JSONファイル容量
- パッケージ形式
- 処理モード
- SHA-256チェックサム
- メディア形式
- メディアの拡張子と実際のMIME形式の一致
- 実行可能な拡張子・二重拡張子・隠しファイル名の拒否
- 保存先の親ディレクトリを含むリンク・ジャンクションの拒否
- メディア容量
- ZIP展開中の実測容量（上限を超えるデータは書き込まない）
- 展開前の重複パス・個別ファイル容量の検証
- プレビューの実行者・有効期限・確認後の変更検出
- バックアップの実行者・整合性・復元前の変更検出
- コンテンツの合計件数
- JSONの深さ・構造要素数

インポート・エクスポート処理は、同じNexaPress保存先に対する
ファイルロックで排他制御します。同時に別の処理を開始すると拒否します。
成功・失敗のいずれの場合もロックを解放します。

メディアは許可された拡張子と実際のMIME形式が一致する場合だけ保存します。
画像、音声、動画、PDF、テキスト、CSV、対応するOffice文書が対象です。
PHP、HTML、SVG、`.htaccess`、`.user.ini`などは取り込めません。
CSVは実形式が`text/plain`と判定された場合も受け付けます。

### Webサーバーの実行禁止設定

本番環境では`public/uploads/media/`を静的ファイル専用として設定し、
PHP・CGIなどを実行させないでください。`storage/`も外部から公開しないでください。
Webサーバーの設定は、この拡張機能からは変更しません。
Apacheのハンドラー設定やNginxのPHP用locationに、アップロード先が含まれないよう確認してください。
設定方法は[Apacheのハンドラー](https://httpd.apache.org/docs/2.4/handler.html)と
[Nginxのlocation](https://nginx.org/en/docs/http/ngx_http_core_module.html#location)を参照してください。

## 初期制限値

```text
ZIPファイル             最大100MB
ZIP展開後の合計容量     最大500MB（実測）
JSONファイル1個         最大20MB
メディアファイル1個     最大50MB
ZIP内のファイル数       最大5,000件
メディアファイル数      最大2,000件
コンテンツ合計件数      最大10,000件
プレビュー保存時間      1時間・1ユーザー3件
バックアップ保持件数    サイト全体で5件（設定は2件以上）
バックアップ1件         最大500MB
バックアップ合計        最大1GB
一時ファイル保存時間    1時間
```

制限値は次のファイルで管理します。

```text
config/limits.php
```

`max_extracted_size`で展開後の合計容量、`max_content_items`で
投稿・固定ページ・カテゴリーの合計件数を変更できます。
`preview_lifetime`・`max_pending_previews`でプレビューの保持期間と件数、
`max_backups`・`max_backup_size`・`max_backup_storage_size`でバックアップの
保持件数と容量を変更できます。URL置換による増加量もJSON容量制限以内に抑えます。
JSONの深さは64、構造要素数（文字列外の`{`・`[`・`,`の合計）は200,000までです。
旧バージョンで作成したZIPでも、これらの上限や新しいメディア検証を満たす必要があります。

## インストール方法

1. 配布用ZIPを用意します。
2. NexaPress管理画面へログインします。
3. 「拡張機能」を開きます。
4. ZIPファイルをアップロードします。
5. 「NX Import Export」を有効化します。
6. 拡張機能メニューから「インポート・エクスポート」を開きます。

## エクスポート方法

1. 「エクスポート」タブを開きます。
2. 処理内容を選択します。
3. 「エクスポートを実行」を押します。
4. ZIPファイルを保存します。

## インポート方法

1. 本番環境のバックアップを取得します。
2. 「インポート」タブを開きます。
3. NX Import Exportで作成したZIPを選択します。
4. 処理内容、重複時の処理、必要に応じて移行元・移行先URLを指定します。
5. 「変更内容をプレビュー」を押します。
6. 件数、重複する識別子、設定の差分、URL置換例を確認します。
7. 条件を変える場合は「条件を変更して再プレビュー」を押します。
8. 「確認した内容で実行」を押します。実行直前に自動バックアップします。
9. 完了メッセージと処理件数を確認します。

設定のみを取り込む際、デバッグ設定も反映したい場合は、実行前に
「デバッグ設定も取り込む」を選択してください。標準では選択されていません。

## ファイル構成

```text
nx-import-export/
├─ manifest.json
├─ bootstrap.php
├─ autoload.php
├─ build.php
├─ README.md
│
├─ config/
│  ├─ export-modes.php
│  └─ limits.php
│
├─ routes/
│  └─ admin.php
│
├─ src/
│  ├─ Admin/
│  │  ├─ ExportController.php
│  │  ├─ ImportController.php
│  │  └─ Notice.php
│  │
│  ├─ Export/
│  │  ├─ ExportService.php
│  │  ├─ ContentExporter.php
│  │  ├─ MediaExporter.php
│  │  └─ SettingsExporter.php
│  │
│  ├─ Import/
│  │  ├─ ImportService.php
│  │  ├─ ContentImporter.php
│  │  ├─ MediaImporter.php
│  │  ├─ SettingsImporter.php
│  │  ├─ ImportOptions.php
│  │  ├─ ImportPlanner.php
│  │  ├─ ConflictResolver.php
│  │  ├─ UrlRewriter.php
│  │  ├─ PreviewStore.php
│  │  ├─ BackupService.php
│  │  └─ ImportSnapshot.php
│  │
│  ├─ Package/
│  │  ├─ PackageBuilder.php
│  │  ├─ PackageReader.php
│  │  ├─ PackageManifest.php
│  │  └─ Checksum.php
│  │
│  ├─ Repository/
│  │  ├─ ContentRepository.php
│  │  ├─ MediaRepository.php
│  │  ├─ SettingsRepository.php
│  │  └─ ImportRepository.php
│  │
│  ├─ Validation/
│  │  ├─ ExportValidator.php
│  │  ├─ ImportValidator.php
│  │  ├─ PackageValidator.php
│  │  └─ MediaValidator.php
│  │
│  └─ Support/
│     ├─ Json.php
│     ├─ Zip.php
│     ├─ TempDirectory.php
│     ├─ SafePath.php
│     ├─ OperationLock.php
│     ├─ AtomicFile.php
│     ├─ PrivateStorage.php
│     ├─ Limits.php
│     └─ FileName.php
│
├─ admin/
│  ├─ dashboard.php
│  ├─ sections/
│  │  ├─ export.php
│  │  └─ import.php
│  └─ partials/
│     ├─ notice.php
│     ├─ mode-options.php
│     ├─ import-preview.php
│     └─ backups.php
│
├─ assets/
│  ├─ css/
│  │  └─ import-export.css
│  └─ js/
│     └─ import-export.js
│
├─ tests/
│  ├─ security.php
│  ├─ features.php
│  ├─ feature-cases.php
│  └─ feature-shims.php
└─ dist/
```

## 配布用ZIPの作成

プロジェクトフォルダへ移動します。

```bat
cd /d "D:\github作業フォルダ\nx-import-export"
```

PHPファイルを一括で構文チェックします。

```bat
for /R %f in (*.php) do @php -l "%f"
```

`manifest.json`を確認します。

```bat
php -r "$j=file_get_contents('manifest.json'); json_decode($j,true,512,JSON_THROW_ON_ERROR); echo 'manifest.json OK'.PHP_EOL;"
```

配布用ZIPを作成します。

```bat
php build.php
```

正常に完了すると、次のファイルが作成されます。

```text
dist/nx-import-export-1.4.0.zip
```

ZIP内部を確認します。

```bat
tar -tf "dist\nx-import-export-1.4.0.zip"
```

## 配布ZIP内部

```text
nx-import-export/
├─ manifest.json
├─ bootstrap.php
├─ autoload.php
├─ README.md
├─ config/
├─ routes/
├─ src/
├─ admin/
└─ assets/
```

次の項目は配布ZIPへ含まれません。

```text
build.php
tests/
dist/
.git/
.github/
.idea/
.vscode/
```

## 動作確認

### 回帰テスト

PHP CLIのZipArchive、Fileinfo、PDO SQLite拡張を有効にして実行します。
テスト用の認証・DBクラスを使用し、一時フォルダとメモリ内DBで検証します。
NexaPressの実データにはアクセスしません。テスト用フォルダは終了時に削除します。
Windowsでは、管理者権限を必要としないジャンクションも使って保存先を検証します。

```text
php tests/security.php
php tests/features.php
```

`features.php`はセキュリティ回帰テストを含み、プレビュー・重複処理・URL置換・
復元・失敗時の復旧・管理画面と権限の検証をまとめて実行します。

テストコードは配布ZIPに含めません。

### NexaPress上での確認

1. 拡張機能ZIPをアップロードできる
2. 拡張機能を有効化できる
3. 管理画面を開ける
4. エクスポートとインポートのタブを切り替えられる
5. コンテンツのみをエクスポートできる
6. コンテンツ＋メディアをエクスポートできる
7. 設定のみをエクスポートできる
8. 各ZIPを同じ処理内容でインポートできる
9. 投稿が追加または更新される
10. 固定ページが追加または更新される
11. カテゴリーとの関連付けが維持される
12. メディア情報と実ファイルが復元される
13. 一般設定が復元される
14. URL設定が復元される
15. デバッグ設定が復元される
16. 異なる処理内容を選択すると拒否される
17. 壊れたZIPが拒否される
18. 改変したファイルがチェックサムで拒否される
19. 管理者以外から直接実行すると403になる
20. 既存のユーザー情報が変更されない
21. プレビューで実データが変わらない
22. 上書き・スキップ・別名追加で件数と実際の結果が一致する
23. カテゴリーの別名追加後も投稿との関連付けが維持される
24. メディアの別名追加後に本文内の参照が更新される
25. 移行元URLだけが置換され、似た別URLは変わらない
26. 取り込み成功後もバックアップから復元できる
27. 元の所有者・日時・設定ファイルが復元される
28. プレビュー後や取り込み後の編集がある場合は上書きで進めない
29. DBや設定の保存に失敗した場合に変更前へ戻る
