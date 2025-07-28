# 宿泊業向け予約管理システム

宿泊業向けの予約管理システムです。フロント業務効率化と顧客向けオンライン予約を両立します。

## 📋 主な機能

- ✅ **予約登録** - 顧客向けWebフォームと管理者向け画面
- ✅ **予約検索・一覧** - 日付、ステータス、顧客名での絞り込み
- ✅ **マスターデータ管理** - 部屋グループ、部屋タイプ、プラン管理
- ✅ **月間ビュー** - 部屋別1ヶ月予約状況表示（横跨ぎブロック対応）
- ✅ **設定画面** - 部屋管理、プラン管理、システム設定
- ✅ **日間ビュー** - 当日部屋配置・稼働状況（ドラッグ&ドロップ対応）
- 🔄 **台帳管理** - 予約者情報・部屋割・会計（開発予定）

### 🎯 月間ビュー機能詳細

**横跨ぎブロック表示**:
- 複数日予約は一つの連続ブロックで表示
- チェックイン日に「顧客名 | プラン名 | IN 3名(2泊)」形式
- 1日予約は通常ブロック表示
- チェックアウト日を含まない正確な期間表示

**UI改善機能**:
- セル上下分割（最大2予約/日/部屋）
- 予約状態の色分け（チェックイン・滞在中・チェックアウト）
- ホバー詳細情報（顧客情報・支払状況・備考）
- 未払い予約のアニメーション表示
- フィルター機能（グループ・キーワード検索）

### 🏠 日間ビュー機能詳細

**部屋グリッド表示**:
- 全部屋をカード形式でグリッド表示
- 部屋状態の色分け（空室・利用中・定員オーバー）
- 利用人数/定員の視覚的表示（例：3/4名）
- 予約者情報とプラン詳細の表示

**表示モード切り替え**:
- 全て・チェックイン・チェックアウト・宿泊中の4モード
- リアルタイムフィルタリング
- 日付選択（前日・翌日ナビゲーション対応）

**ドラッグ&ドロップ機能**:
- 未割り当て予約から部屋カードへのドラッグ操作
- 部屋の定員チェック・競合チェック
- 視覚的フィードバック（ドラッグオーバー時のハイライト）
- エラーハンドリング（定員オーバー・期間競合の警告）

**APIエンドポイント**:
- `GET /api/daily_view.php` - 日間ビューデータ取得
- `POST /api/assign_reservation.php` - 予約割り当て処理

### ✏️ 予約編集・キャンセル機能詳細

**予約編集機能**:
- 管理画面からの予約情報変更
- 顧客情報・宿泊期間・人数・プラン・料金の編集
- リアルタイムバリデーション（日程・定員・料金チェック）
- チェックイン後は制限された編集（備考・支払い状況のみ）

**予約キャンセル機能**:
- 論理削除によるキャンセル処理
- キャンセル理由・返金額の記録
- 部屋割り当ての自動解除
- 支払い履歴への返金記録

**権限制御**:
- チェックアウト済み予約はキャンセル不可
- チェックイン済み予約は管理者権限が必要
- キャンセル済み予約の編集制限

**APIエンドポイント**:
- `PUT /api/update_reservation.php` - 予約情報更新
- `POST /api/cancel_reservation.php` - 予約キャンセル処理

### 📧 メール通知機能詳細

**基本機能**:
- PHPのmail()関数を使用したシンプルな実装
- システム設定画面での通知ON/OFF設定
- 送信ログ機能によるメール送信状況の追跡

**顧客向け通知**:
- 予約確認メール（新規予約完了時）
- 予約変更通知（編集・部屋割り当て時）
- キャンセル通知（予約キャンセル時の返金案内）
- チェックイン案内（前日または当日の到着案内）
- チェックアウト案内（精算・退室手続きの案内）
- 支払い督促（未払い予約に対する催促メール）

**管理者向け通知**:
- 新規予約アラート（Web予約受付時の即座な通知）
- 日次サマリー（当日のチェックイン・アウト予定）
- 未払いレポート（定期的な支払い状況の報告）
- システムエラー通知（API障害・データベース異常の報告）

**設定可能項目**:
- メール機能の有効/無効
- 送信元メールアドレス・送信者名
- 管理者メールアドレス
- 各通知の個別ON/OFF設定

## 🛠 技術スタック

- **バックエンド**: PHP 7.4+
- **データベース**: MySQL 8.0+
- **フロントエンド**: HTML5, CSS3, Vanilla JavaScript
- **アーキテクチャ**: REST API (JSON)

## 📦 セットアップ

### 🚀 クイックスタート（5分で起動）

```bash
# 1. リポジトリをクローン
git clone https://github.com/your-username/yoyaku6.git
cd yoyaku6

# 2. データベースを作成・初期化
mysql -u root -p < database/init.sql
mysql -u root -p yoyaku_system < database/create_tables.sql
mysql -u root -p yoyaku_system < database/settings_extension.sql

# 3. データベース接続設定をコピー
cp config/database.php.example config/database.php
# config/database.php を編集してデータベース情報を設定

# 4. 開発サーバー起動
php -S localhost:8000 index.php

# 5. ブラウザでアクセス
# http://localhost:8000 - 顧客向け予約フォーム
# http://localhost:8000/admin - 管理画面
# http://localhost:8000/monthly - 月間ビュー
# http://localhost:8000/daily - 日間ビュー
# http://localhost:8000/settings - 設定画面
```

### 📋 協力開発のための情報

**開発環境の統一:**
- PHP 8.0+ 推奨
- MySQL 8.0+ または MariaDB 10.4+
- Git 2.30+

**ブランチ戦略:**
- `main` - 本番リリース用
- `develop` - 開発統合ブランチ
- `feature/*` - 機能開発用
- `hotfix/*` - 緊急修正用

**コミットメッセージ規約:**
```
feat: 新機能追加
fix: バグ修正
docs: ドキュメント更新
style: コードフォーマット
refactor: リファクタリング
test: テスト追加・修正
chore: その他の変更
```

**プルリクエスト手順:**
1. `develop` ブランチから `feature/機能名` ブランチを作成
2. 機能開発・テスト実施
3. `develop` ブランチへプルリクエスト作成
4. コードレビュー後マージ

### 📋 詳細セットアップ手順

#### 1. 必要な環境

**必須要件:**
- PHP 7.4以上（推奨: PHP 8.0+）
- MySQL 8.0以上（または MariaDB 10.4+）
- Git

**開発環境での確認方法:**
```bash
# PHPバージョン確認
php --version

# MySQLバージョン確認
mysql --version

# 必要なPHP拡張機能の確認
php -m | grep -E "(pdo|mysql|json|mbstring)"
```

#### 2. プロジェクトのクローン

```bash
# HTTPSでクローン
git clone https://github.com/your-username/yoyaku6.git
cd yoyaku6

# または SSHでクローン
git clone git@github.com:your-username/yoyaku6.git
cd yoyaku6
```

#### 3. データベースの設定

**3.1 MySQLサービスの起動確認**
```bash
# macOS (Homebrew)
brew services start mysql

# Ubuntu/Debian
sudo systemctl start mysql

# Windows (XAMPP)
# XAMPPコントロールパネルからMySQLを起動
```

**3.2 データベースとテーブルの作成**
```bash
# データベース作成
mysql -u root -p < database/init.sql

# テーブル作成と初期データ投入
mysql -u root -p yoyaku_system < database/create_tables.sql

# 設定機能用テーブル追加
mysql -u root -p yoyaku_system < database/settings_extension.sql
```

**3.3 データベース接続設定**

設定ファイルをコピーして編集：
```bash
# 設定ファイルのコピー（初回のみ）
cp config/database.php.example config/database.php
```

`config/database.php` を編集：
```php
<?php
$database_config = [
    'host' => 'localhost',
    'dbname' => 'yoyaku_system',
    'username' => 'root',           // ← あなたのMySQLユーザー名
    'password' => 'your_password',  // ← あなたのMySQLパスワード
    'charset' => 'utf8mb4',
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
];
```

#### 4. 開発サーバーの起動

**方法1: PHPビルトインサーバー（推奨）**
```bash
# プロジェクトルートで実行
php -S localhost:8000 index.php
```

**方法2: 異なるポートで起動**
```bash
# ポート8000が使用中の場合
php -S localhost:8001 index.php
```

**方法3: 外部からアクセス可能にする**
```bash
# 同一ネットワーク内の他のデバイスからアクセス可能
php -S 0.0.0.0:8000 index.php
```

#### 5. 動作確認

**基本画面へのアクセス:**
- 🏠 **顧客向け予約フォーム**: http://localhost:8000
- 👨‍💼 **管理画面**: http://localhost:8000/admin
- 📅 **月間ビュー**: http://localhost:8000/monthly
- 🏨 **日間ビュー**: http://localhost:8000/daily
- ⚙️ **設定画面**: http://localhost:8000/settings

**API動作確認:**
```bash
# 部屋タイプ一覧取得
curl "http://localhost:8000/api/room_types.php"

# プラン一覧取得
curl "http://localhost:8000/api/plans.php"

# 予約一覧取得
curl "http://localhost:8000/api/list_reservations.php"
```

#### 6. 本番環境での設定

**Apache設定例:**
```apache
<VirtualHost *:80>
    ServerName your-domain.com
    DocumentRoot /path/to/yoyaku6/public
    
    <Directory /path/to/yoyaku6/public>
        AllowOverride All
        Require all granted
    </Directory>
    
    # PHPの設定
    php_value upload_max_filesize 10M
    php_value post_max_size 10M
</VirtualHost>
```

**Nginx設定例:**
```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/yoyaku6/public;
    index index.php index.html;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.0-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### 🔧 開発者向け設定

#### デバッグモードの有効化

`config/database.php` にデバッグ設定を追加：
```php
// デバッグモード（開発環境のみ）
define('DEBUG_MODE', true);
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}
```

#### ログファイルの確認

```bash
# PHPエラーログ
tail -f /var/log/php_errors.log

# Apacheエラーログ
tail -f /var/log/apache2/error.log

# Nginxエラーログ
tail -f /var/log/nginx/error.log
```

## 📚 API仕様

### マスターデータ取得

- `GET /api/room_groups.php` - 部屋グループ一覧
- `GET /api/room_types.php` - 部屋タイプ一覧  
- `GET /api/plans.php` - プラン一覧
- `GET /api/customers.php?search=検索語` - 顧客検索

### 予約管理

- `POST /api/create_reservation.php` - 予約登録
- `GET /api/list_reservations.php` - 予約一覧・検索

### 予約登録例

```bash
curl -X POST http://localhost:8000/api/create_reservation.php \
  -H "Content-Type: application/json" \
  -d '{
    "name": "山田 太郎",
    "phone": "080-1234-5678",
    "email": "taro@example.com",
    "checkinDate": "2025-08-10",
    "checkoutDate": "2025-08-12",
    "adults": 2,
    "children": 1,
    "roomType": "ダブル",
    "plan": "朝食付き",
    "notes": "特になし"
  }'
```

### 予約検索例

```bash
# 全予約取得
curl "http://localhost:8000/api/list_reservations.php"

# 日付で絞り込み
curl "http://localhost:8000/api/list_reservations.php?checkinDate=2025-08-10"

# ステータスで絞り込み
curl "http://localhost:8000/api/list_reservations.php?status=unassigned"

# 顧客名で検索
curl "http://localhost:8000/api/list_reservations.php?search=山田"
```

## 📁 ディレクトリ構造

```
yoyaku6/
├── README.md
├── sekkei.md                 # 設計書
├── index.php                # カスタムルーター
├── database/
│   ├── create_db.sql        # データベース作成
│   ├── create_tables.sql    # テーブル作成・初期データ
│   └── settings_extension.sql # 設定機能用テーブル拡張
├── config/
│   └── database.php         # データベース設定・共通関数
├── api/                     # APIエンドポイント
│   ├── monthly_view.php     # 月間ビューデータ取得
│   ├── room_groups.php      # 部屋グループ管理
│   ├── room_types.php       # 部屋タイプ管理
│   ├── plans.php           # プラン管理
│   ├── customers.php       # 顧客管理
│   ├── create_reservation.php # 予約登録
│   ├── list_reservations.php # 予約一覧・検索
│   └── settings/           # 設定画面用API
│       ├── rooms.php       # 部屋管理API
│       ├── room_types.php  # 部屋タイプ管理API
│       ├── room_groups.php # 部屋グループ管理API
│       ├── plans.php       # プラン管理API
│       └── system.php      # システム設定API
└── public/                 # 公開ディレクトリ
    ├── index.html         # 顧客向け予約フォーム
    ├── admin.html         # 管理画面・予約一覧
    ├── monthly.html       # 月間ビュー（横跨ぎブロック表示）
    └── settings.html      # 設定画面（タブ式）
```

## 🔄 開発ロードマップ

### Phase 1: 基本機能 ✅
- [x] データベース設計・初期データ投入
- [x] 基本API実装（予約CRUD、マスターデータ）
- [x] 顧客向け予約フォーム作成
- [x] カスタムルーター実装（クリーンURL）

### Phase 2: 管理画面 ✅
- [x] 予約一覧・検索機能
- [x] 月間ビュー実装（横跨ぎブロック表示）
- [x] 設定画面実装（部屋・プラン・システム管理）
- [x] 統一ナビゲーション
- [x] 予約編集・キャンセル機能

### Phase 3: 高度な機能 📋
- [ ] ログイン・認証機能
- [x] 日間ビュー実装
- [x] ドラッグ&ドロップ機能
- [ ] 会計処理機能
- [ ] レポート・CSV出力機能
- [x] メール通知機能

## 📖 開発ガイド

詳細な開発情報については、以下のドキュメントを参照してください：

- **[CONTRIBUTING.md](CONTRIBUTING.md)** - 開発ガイドライン、コーディング規約、テスト方法
- **[sekkei.md](sekkei.md)** - システム設計書、アーキテクチャ詳細
- **[k.mdc](.cursor/rules/k.mdc)** - 開発ルール、ベストプラクティス

### 🤝 コントリビューション

このプロジェクトへの貢献を歓迎します！

1. このリポジトリをフォーク
2. 機能ブランチを作成 (`git checkout -b feature/amazing-feature`)
3. 変更をコミット (`git commit -m 'feat: 素晴らしい機能を追加'`)
4. ブランチにプッシュ (`git push origin feature/amazing-feature`)
5. プルリクエストを作成

詳細は [CONTRIBUTING.md](CONTRIBUTING.md) をご覧ください。

## 🧪 動作確認

### 基本セットアップ確認
1. PHPサーバー起動: `php -S localhost:3000`
2. ブラウザで `http://localhost:3000` にアクセス
3. 予約フォームに必要事項を入力
4. 「予約を送信」ボタンをクリック
5. 成功メッセージが表示されることを確認

### 月間ビュー機能確認
1. `http://localhost:3000/monthly` にアクセス
2. 横跨ぎブロック表示の確認
   - 複数日予約: 一つの連続ブロック
   - 1日予約: 通常ブロック
   - ホバーで詳細情報表示
3. フィルター機能（グループ・キーワード）
4. 月切り替え機能

### 設定画面機能確認
1. `http://localhost:3000/settings` にアクセス
2. 各タブ（部屋・部屋タイプ・部屋グループ・プラン・システム設定）
3. CRUD操作（追加・編集・削除）
4. バリデーション機能

### 日間ビュー機能確認
1. `http://localhost:3000/daily` にアクセス
2. 部屋グリッド表示の確認
   - 部屋状態の色分け（空室・利用中・定員オーバー）
   - 利用人数/定員の表示
3. 表示モード切り替え（全て・チェックイン・チェックアウト・宿泊中）
4. フィルター機能（グループ・キーワード検索）
5. 日付ナビゲーション（前日・翌日・日付選択）
6. ドラッグ&ドロップ機能
   - 未割り当て予約から部屋カードへのドラッグ
   - 定員チェック・競合チェック
7. 予約詳細モーダル表示

### 予約編集・キャンセル機能確認
1. `http://localhost:3000/admin` にアクセス
2. 予約一覧で「編集」ボタンをクリック
   - 予約情報編集モーダルが表示される
   - 顧客情報・宿泊期間・人数・プラン・料金を変更
   - バリデーション機能の確認
3. 予約一覧で「キャンセル」ボタンをクリック
   - キャンセル確認モーダルが表示される
   - キャンセル理由・返金額を入力
   - キャンセル実行後、ステータスが「キャンセル」に変更
4. キャンセル済み予約の編集制限確認
5. チェックイン後予約の編集制限確認

### メール通知機能確認
1. `http://localhost:3000/settings` にアクセス
2. 「メール通知」タブをクリック
3. 基本設定の確認
   - メール機能を有効にする
   - 送信元メールアドレス・送信者名を設定
   - 管理者メールアドレスを設定
4. 通知設定の確認
   - 顧客向け通知（6種類）の個別ON/OFF
   - 管理者向け通知（4種類）の個別ON/OFF
5. 設定保存と動作確認
   - 設定を保存してメール機能を有効化
   - 新規予約作成時の自動メール送信確認
   - サーバーログでメール送信状況を確認

### API動作確認
```bash
# 予約一覧を確認
curl "http://localhost:3000/api/list_reservations.php" | jq .

# 月間ビューデータ取得
curl "http://localhost:3000/api/monthly_view.php?year=2025&month=8" | jq .

# 設定データ取得
curl "http://localhost:3000/api/settings/rooms.php" | jq .

# 日間ビューデータ取得
curl "http://localhost:3000/api/daily_view.php?date=2025-08-16&view_mode=all" | jq .

# 予約割り当て
curl -X POST "http://localhost:3000/api/assign_reservation.php" \
  -H "Content-Type: application/json" \
  -d '{"reservation_id": 1, "room_id": 3}' | jq .

# 予約編集
curl -X POST "http://localhost:3000/api/update_reservation.php" \
  -H "Content-Type: application/json" \
  -d '{"id": 1, "adults": 3, "price": 35000, "notes": "人数変更"}' | jq .

# 予約キャンセル
curl -X POST "http://localhost:3000/api/cancel_reservation.php" \
  -H "Content-Type: application/json" \
  -d '{"id": 2, "cancel_reason": "お客様都合", "refund_amount": 10000}' | jq .

# メール設定取得
curl "http://localhost:3000/api/settings/system.php" | jq '.email_enabled, .email_from_address'

# メール設定更新
curl -X PUT "http://localhost:3000/api/settings/system.php" \
  -H "Content-Type: application/json" \
  -d '{"email_enabled": "true", "email_from_address": "noreply@example.com", "email_admin_address": "admin@example.com"}' | jq .
```

## 🐛 トラブルシューティング

### データベース接続エラー
- MySQLサービスが起動していることを確認
- `config/database.php` の接続情報を確認
- データベースとテーブルが作成されていることを確認

### APIエラー
- PHPエラーログを確認: `tail -f /var/log/apache2/error.log`
- ブラウザの開発者ツールでネットワークタブを確認

### CORS エラー
開発環境で異なるポートからアクセスする場合、CORSエラーが発生する可能性があります。APIファイルにCORSヘッダーは設定済みですが、必要に応じて調整してください。

## 📄 ライセンス

このプロジェクトは開発中のプロトタイプです。