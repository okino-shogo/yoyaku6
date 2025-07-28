# ロリポップデプロイガイド

## 前提条件
- ロリポップアカウント（ライト以上推奨）
- MySQL利用可能プラン
- FTPクライアント（FileZilla等）

## Step 1: データベース作成

### 1.1 ロリポップ管理画面にログイン
- https://user.lolipop.jp/ にアクセス
- アカウント情報でログイン

### 1.2 データベース作成
1. サーバー管理・設定 → データベース → MySQL設定
2. 「作成」をクリック
3. データベース設定：
   - データベース名: `yoyaku_system`
   - ユーザー名: 自動設定
   - パスワード: 強固なパスワードを設定
4. 作成完了後、接続情報をメモ

### 1.3 データベース情報の確認
```
データベースサーバー: mysql.lolipop.jp
データベース名: LAA####_yoyaku_system
ユーザー名: LAA####
パスワード: [設定したパスワード]
```

## Step 2: ファイルアップロード

### 2.1 FTPクライアントの設定
- ホスト: ftp.lolipop.jp
- ユーザー名: [FTPアカウント]
- パスワード: [FTPパスワード]
- ポート: 21

### 2.2 ファイル構成
```
public_html/
├── api/
├── config/
├── database/
├── public/
├── .htaccess (新規作成)
└── index.php
```

### 2.3 アップロード対象
- `api/` フォルダ全体
- `config/` フォルダ全体  
- `database/` フォルダ全体
- `public/` フォルダの中身を `public_html/` 直下にコピー
- `index.php`

## Step 3: 設定ファイル調整

### 3.1 config/database.php の編集
```php
$database_config = [
    'host' => 'mysql.lolipop.jp',
    'dbname' => 'LAA####_yoyaku_system', // 実際のDB名
    'username' => 'LAA####',             // 実際のユーザー名
    'password' => 'YOUR_PASSWORD',       // 設定したパスワード
    'charset' => 'utf8mb4',
    // ... 他の設定
];
```

### 3.2 .htaccess ファイル作成
```apache
# カスタムルーティング
RewriteEngine On

# API へのアクセス
RewriteRule ^api/(.*)$ api/$1 [L]

# 管理画面
RewriteRule ^admin/?$ admin.html [L]
RewriteRule ^monthly/?$ monthly.html [L]
RewriteRule ^settings/?$ settings.html [L]
RewriteRule ^daily/?$ daily.html [L]

# メインページ
RewriteRule ^$ index.html [L]

# PHPエラー表示制御
php_value display_errors Off
php_value log_errors On
```

## Step 4: データベース初期化

### 4.1 phpMyAdminアクセス
1. ロリポップ管理画面 → データベース → phpMyAdminへのログイン
2. 作成したデータベースを選択

### 4.2 SQLファイル実行
以下の順序でSQLファイルをインポート：
1. `database/create_tables.sql`
2. `database/settings_extension.sql`
3. `database/email_settings.sql`
4. `database/email_logs.sql`

## Step 5: 動作確認

### 5.1 アクセステスト
- https://[あなたのドメイン]/
- https://[あなたのドメイン]/admin
- https://[あなたのドメイン]/api/plans.php

### 5.2 機能テスト
1. 予約フォーム動作確認
2. 管理画面アクセス確認
3. API レスポンス確認

## トラブルシューティング

### よくある問題
1. **500エラー**: PHPバージョン確認（7.4以上推奨）
2. **データベース接続エラー**: 接続情報の再確認
3. **ファイルパーミッション**: 適切な権限設定（644/755）

### サポート情報
- ロリポップサポート: https://lolipop.jp/support/
- PHP設定: サーバー管理・設定 → PHP設定 