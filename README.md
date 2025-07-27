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

## 🛠 技術スタック

- **バックエンド**: PHP 7.4+
- **データベース**: MySQL 8.0+
- **フロントエンド**: HTML5, CSS3, Vanilla JavaScript
- **アーキテクチャ**: REST API (JSON)

## 📦 セットアップ

### 1. 必要な環境

- PHP 7.4以上
- MySQL 8.0以上
- Webサーバー（Apache/Nginx）

### 2. データベースの設定

MySQLにログインして、データベースを作成します：

```bash
mysql -u root -p
```

```sql
source database/init.sql
```

### 3. データベース接続設定

`config/database.php` ファイルを編集して、データベース接続情報を設定してください：

```php
$database_config = [
    'host' => 'localhost',
    'dbname' => 'yoyaku_system',
    'username' => 'your_username',    // <- 変更
    'password' => 'your_password',    // <- 変更
    'charset' => 'utf8mb4',
    // ...
];
```

### 4. Webサーバーの起動

#### 開発環境（PHPビルトインサーバー）

```bash
cd public
php -S localhost:8000
```

ブラウザで `http://localhost:8000` にアクセスして予約フォームを確認できます。

#### 本番環境（Apache/Nginx）

Webサーバーのドキュメントルートを `public/` ディレクトリに設定してください。

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
- [ ] メール通知機能

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