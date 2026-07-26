# NX Import Export

NexaPressのコンテンツ、メディア、設定を
ZIPファイルとしてエクスポート・インポートする拡張機能です。

## バージョン

```text
1.0.0
```

## 対応環境

- NexaPress 2.2.0以上
- PHP 8.0以上
- PHP PDO MySQL拡張
- PHP ZipArchive拡張
- PHP Fileinfo拡張

## 主な機能

次の3種類から処理内容を選択できます。

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

投稿、固定ページ、カテゴリーは、
スラッグが同じ既存データを更新します。

同じスラッグが存在しない場合は、
新しいデータとして追加します。

インポートした投稿と固定ページの所有者は、
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

同じ保存先のファイルが存在する場合は、
既存ファイルを一時的にバックアップしてから置き換えます。

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
- メディア容量

## 初期制限値

```text
ZIPファイル             最大100MB
JSONファイル1個         最大20MB
メディアファイル1個     最大50MB
ZIP内のファイル数       最大5,000件
メディアファイル数      最大2,000件
一時ファイル保存時間    1時間
```

制限値は次のファイルで管理します。

```text
config/limits.php
```

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
4. ZIPに対応する処理内容を選択します。
5. 「インポートを実行」を押します。
6. 完了メッセージと処理件数を確認します。

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
│  │  └─ SettingsImporter.php
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
│  │  └─ SettingsRepository.php
│  │
│  ├─ Validation/
│  │  ├─ ExportValidator.php
│  │  ├─ ImportValidator.php
│  │  └─ PackageValidator.php
│  │
│  └─ Support/
│     ├─ Json.php
│     ├─ Zip.php
│     ├─ TempDirectory.php
│     └─ FileName.php
│
├─ admin/
│  ├─ dashboard.php
│  ├─ sections/
│  │  ├─ export.php
│  │  └─ import.php
│  └─ partials/
│     ├─ notice.php
│     └─ mode-options.php
│
├─ assets/
│  ├─ css/
│  │  └─ import-export.css
│  └─ js/
│     └─ import-export.js
│
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
dist/nx-import-export-1.0.0.zip
```

ZIP内部を確認します。

```bat
tar -tf "dist\nx-import-export-1.0.0.zip"
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
dist/
.git/
.github/
.idea/
.vscode/
```

## 動作確認

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