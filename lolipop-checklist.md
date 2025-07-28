# ロリポップデプロイ チェックリスト

## 📋 事前準備

### ✅ ロリポップアカウント確認
- [ ] ロリポップ契約プラン確認（ライト以上推奨）
- [ ] MySQL利用可能プラン確認
- [ ] 独自ドメインまたはサブドメイン設定済み

### ✅ 必要な情報収集
- [ ] FTPアカウント情報
  ```
  ホスト: ftp.lolipop.jp
  ユーザー名: [FTPアカウント]
  パスワード: [FTPパスワード]
  ```

- [ ] 管理画面ログイン情報
  ```
  URL: https://user.lolipop.jp/
  アカウント: [メールアドレス]
  パスワード: [管理画面パスワード]
  ```

## 🗄️ データベース作成

### Step 1: MySQL作成
- [ ] ロリポップ管理画面にログイン
- [ ] サーバー管理・設定 → データベース → MySQL設定
- [ ] 新規データベース作成
  - データベース名: `yoyaku_system`
  - パスワード: 強固なパスワード設定
- [ ] データベース情報をメモ
  ```
  サーバー: mysql.lolipop.jp
  DB名: LAA####_yoyaku_system
  ユーザー: LAA####
  パスワード: [設定したパスワード]
  ```

### Step 2: データベース初期化
- [ ] phpMyAdminにログイン
- [ ] 以下SQLファイルを順番にインポート:
  1. `database/create_tables.sql`
  2. `database/settings_extension.sql`  
  3. `database/email_settings.sql`
  4. `database/email_logs.sql`

## 📁 ファイル準備

### ✅ 設定ファイル調整
- [ ] `config/database.lolipop.php` を `config/database.php` にコピー
- [ ] データベース接続情報を実際の値に変更:
  ```php
  'host' => 'mysql.lolipop.jp',
  'dbname' => 'LAA####_yoyaku_system',  // ← 実際のDB名
  'username' => 'LAA####',              // ← 実際のユーザー名  
  'password' => 'YOUR_PASSWORD',        // ← 実際のパスワード
  ```

### ✅ .htaccessファイル
- [ ] `.htaccess` ファイルをルートディレクトリに配置
- [ ] アカウント名部分を実際の値に変更:
  ```
  error_log /home/[your-account]/logs/php_error.log
  ```

## 📤 ファイルアップロード

### FTP接続
- [ ] FTPクライアント（FileZilla等）で接続
- [ ] `public_html/` ディレクトリに移動

### アップロード対象
- [ ] `api/` フォルダ全体
- [ ] `config/` フォルダ全体
- [ ] `database/` フォルダ全体（参考用）
- [ ] `public/` フォルダの **中身** を `public_html/` **直下** に配置
  ```
  public_html/
  ├── index.html     ← public/index.html
  ├── admin.html     ← public/admin.html
  ├── monthly.html   ← public/monthly.html
  ├── settings.html  ← public/settings.html
  ├── daily.html     ← public/daily.html
  ├── api/
  ├── config/
  ├── database/
  └── .htaccess
  ```

### ⚠️ 重要なファイル配置
- [ ] **index.phpは不要**（.htaccessでルーティング）
- [ ] HTMLファイルは `public_html/` 直下に配置
- [ ] APIフォルダは `public_html/api/` に配置

## 🧪 動作確認

### 基本アクセステスト
- [ ] メインページ: `https://[ドメイン]/`
- [ ] 管理画面: `https://[ドメイン]/admin`
- [ ] 月間ビュー: `https://[ドメイン]/monthly`
- [ ] 設定画面: `https://[ドメイン]/settings`
- [ ] 日間ビュー: `https://[ドメイン]/daily`

### API動作確認
- [ ] プラン取得: `https://[ドメイン]/api/plans.php`
- [ ] 部屋タイプ: `https://[ドメイン]/api/room_types.php`
- [ ] システム設定: `https://[ドメイン]/api/settings/system.php`

### 機能テスト
- [ ] 予約フォーム送信テスト
- [ ] 管理画面での予約一覧表示
- [ ] 月間ビューの表示確認
- [ ] 設定画面での設定変更

## 🔧 トラブルシューティング

### よくある問題
- [ ] **500エラー**: PHPバージョン確認（7.4以上推奨）
- [ ] **データベース接続エラー**: 接続情報の再確認
- [ ] **ファイルが見つからない**: パス・ファイル配置の確認
- [ ] **パーミッションエラー**: ファイル権限設定（644/755）

### ログ確認
- [ ] ロリポップ管理画面 → アクセスログ・エラーログ
- [ ] PHPエラーログの確認

## 📞 サポート情報
- ロリポップサポート: https://lolipop.jp/support/
- PHP設定変更: サーバー管理・設定 → PHP設定
- .htaccess設定: サーバー管理・設定 → .htaccess設定

## ✅ 完了チェック
- [ ] 全ページ正常表示
- [ ] 予約作成機能動作
- [ ] 管理機能動作  
- [ ] メール機能設定完了
- [ ] SSL証明書設定（推奨） 