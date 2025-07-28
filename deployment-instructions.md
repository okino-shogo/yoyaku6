# 🚀 ロリポップデプロイ実行手順書

## 📋 あなたのアカウント情報

### 確認済みアカウント詳細
```
■ アカウント情報
- アカウントID: LAA1658345
- プラン: ハイスピードプラン
- サーバー番号: spd104

■ ドメイン
- ロリポップドメイン: http://osblog.pecorjp.jp/
- 独自ドメイン: http://osblog45.com/

■ FTP情報
- FTPサーバー: ftp.lolipop.jp
- FTPアカウント: pecorjp-osblog
- FTPパスワード: [設定済み]

■ フルパス
- Webディレクトリ: /home/users/0/pecorjp-osblog/web
```

## Step 1: データベース作成 🗄️

### 1.1 ロリポップ管理画面でMySQL作成
1. **ロリポップ管理画面** にログイン: https://user.lolipop.jp/
2. **サーバー管理・設定** → **データベース** → **MySQL設定**
3. **作成**をクリック
4. 以下の設定でデータベース作成:
   ```
   データベース名: yoyaku_system
   接続パスワード: [強固なパスワードを設定]
   ```

### 1.2 作成されるデータベース情報
```
サーバー: mysql.lolipop.jp
データベース名: LAA1658345_yoyaku_system
ユーザー名: LAA1658345
パスワード: [1.1で設定したパスワード]
```

### 1.3 config/database.php の編集
現在のファイルで以下の行のパスワードを変更:
```php
'password' => 'YOUR_DB_PASSWORD_HERE',  // ← 実際のパスワードに変更
```

## Step 2: データベース初期化 📊

### 2.1 phpMyAdminアクセス
1. ロリポップ管理画面 → **データベース** → **phpMyAdmin**
2. **LAA1658345_yoyaku_system** データベースを選択

### 2.2 SQLファイルインポート
以下の順序でSQLファイルをインポート:

1. **`database/create_tables.sql`**
   - インポートタブ → ファイル選択 → 実行

2. **`database/settings_extension.sql`**
   - インポートタブ → ファイル選択 → 実行

3. **`database/email_settings.sql`**
   - インポートタブ → ファイル選択 → 実行

4. **`database/email_logs.sql`**
   - インポートタブ → ファイル選択 → 実行

## Step 3: ファイルアップロード 📤

### 3.1 FTP接続設定
FTPクライアント（FileZilla推奨）で接続:
```
ホスト: ftp.lolipop.jp
ユーザー名: pecorjp-osblog
パスワード: [FTPパスワード]
ポート: 21
```

### 3.2 アップロード先ディレクトリ
```
/home/users/0/pecorjp-osblog/web/
```

### 3.3 アップロードファイル構成
```
web/ (ルートディレクトリ)
├── .htaccess                    ← プロジェクトルートの.htaccess
├── index.html                   ← public/index.html をコピー
├── admin.html                   ← public/admin.html をコピー
├── monthly.html                 ← public/monthly.html をコピー
├── settings.html                ← public/settings.html をコピー
├── daily.html                   ← public/daily.html をコピー
├── api/                         ← api/フォルダ全体
├── config/                      ← config/フォルダ全体
└── database/                    ← database/フォルダ全体（参考用）
```

### ⚠️ 重要な注意点
- **index.php は アップロード不要**（.htaccessでルーティング）
- **public/フォルダの中身のみ**をwebディレクトリ直下に配置
- **フォルダ構造を正確に**維持してください

## Step 4: 動作確認 🧪

### 4.1 基本ページアクセス
以下のURLで動作確認:

- **メインページ**: http://osblog.pecorjp.jp/ または http://osblog45.com/
- **管理画面**: http://osblog.pecorjp.jp/admin
- **月間ビュー**: http://osblog.pecorjp.jp/monthly
- **設定画面**: http://osblog.pecorjp.jp/settings
- **日間ビュー**: http://osblog.pecorjp.jp/daily

### 4.2 API動作確認
- **プラン一覧**: http://osblog.pecorjp.jp/api/plans.php
- **部屋タイプ**: http://osblog.pecorjp.jp/api/room_types.php
- **システム設定**: http://osblog.pecorjp.jp/api/settings/system.php

### 4.3 機能テスト
1. **予約フォーム**: メインページで予約作成テスト
2. **管理画面**: 予約一覧の表示確認
3. **月間ビュー**: 予約状況の表示確認
4. **設定画面**: 各種設定の動作確認

## 🔧 トラブルシューティング

### よくある問題と解決方法

#### 1. 500エラーが発生する場合
- **PHP設定確認**: ロリポップ管理画面 → サーバー管理・設定 → PHP設定
- **推奨バージョン**: PHP 7.4以上
- **ファイルパーミッション**: 644（ファイル）/ 755（ディレクトリ）

#### 2. データベース接続エラー
- `config/database.php` のパスワード確認
- データベース名が `LAA1658345_yoyaku_system` になっているか確認

#### 3. ページが表示されない
- `.htaccess` ファイルが正しくアップロードされているか確認
- ファイル配置が正しいか確認

#### 4. APIがエラーになる
- ロリポップ管理画面 → アクセスログ・エラーログ でエラー内容確認

## 📞 サポート・参考資料

- **ロリポップサポート**: https://lolipop.jp/support/
- **PHP設定変更**: サーバー管理・設定 → PHP設定
- **エラーログ確認**: サーバー管理・設定 → アクセスログ・エラーログ

## ✅ 完了チェックリスト

### データベース
- [ ] MySQLデータベース作成済み
- [ ] 4つのSQLファイルインポート完了
- [ ] config/database.php のパスワード設定完了

### ファイルアップロード
- [ ] .htaccess アップロード完了
- [ ] HTMLファイル（5個）アップロード完了
- [ ] api/フォルダ アップロード完了
- [ ] config/フォルダ アップロード完了

### 動作確認
- [ ] 全ページ正常表示
- [ ] 全API正常動作
- [ ] 予約フォーム動作確認
- [ ] 管理機能動作確認

## 🎉 デプロイ完了後

デプロイが完了すると、以下のURLで本格的な予約管理システムが利用できます:

- **顧客向け予約**: http://osblog45.com/
- **管理画面**: http://osblog45.com/admin

メール機能の設定は、設定画面の「メール通知」タブで行えます。 