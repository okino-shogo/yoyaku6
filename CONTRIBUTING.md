# 開発ガイド

## 🚀 開発環境のセットアップ

### 前提条件
- PHP 7.4以上
- MySQL 8.0以上
- Git

### セットアップ手順

1. **リポジトリのフォーク・クローン**
   ```bash
   git clone https://github.com/your-username/yoyaku6.git
   cd yoyaku6
   ```

2. **データベースの初期化**
   ```bash
   mysql -u root -p < database/init.sql
   mysql -u root -p yoyaku_system < database/create_tables.sql
   mysql -u root -p yoyaku_system < database/settings_extension.sql
   ```

3. **設定ファイルの作成**
   ```bash
   cp config/database.php.example config/database.php
   # config/database.php を編集してデータベース情報を設定
   ```

4. **開発サーバーの起動**
   ```bash
   php -S localhost:8000 index.php
   ```

## 📁 プロジェクト構造

```
yoyaku6/
├── api/                    # APIエンドポイント
│   ├── *.php              # 各種API（予約、部屋、プラン等）
│   ├── reservations/      # 予約関連API
│   └── settings/          # 設定関連API
├── config/                # 設定ファイル
│   ├── database.php       # DB接続設定（Git管理外）
│   └── database.php.example # 設定サンプル
├── database/              # データベーススクリプト
│   ├── init.sql          # DB作成
│   ├── create_tables.sql # テーブル作成
│   └── settings_extension.sql # 設定テーブル
├── public/               # フロントエンド
│   ├── *.html           # 各画面のHTML
│   ├── css/             # スタイルシート
│   └── js/              # JavaScript
└── docs/                # ドキュメント
```

## 🔧 開発ルール

### コーディング規約

**PHP**
- PSR-12準拠
- 関数名・変数名: camelCase
- クラス名: PascalCase
- 定数: UPPER_SNAKE_CASE

**JavaScript**
- ES6+を使用
- 関数名・変数名: camelCase
- 定数: UPPER_SNAKE_CASE

**SQL**
- テーブル名・カラム名: snake_case
- 予約語は大文字（SELECT, FROM, WHERE等）

### ファイル命名規則

**API**
- 単数形: `room.php`, `plan.php`
- 複数形: `rooms.php`, `plans.php`（一覧取得）
- 動詞: `create_reservation.php`, `update_reservation.php`

**HTML**
- 機能名: `admin.html`, `monthly.html`, `settings.html`

### データベース設計

**テーブル命名**
- 複数形: `rooms`, `reservations`, `customers`
- 中間テーブル: `room_types`, `room_groups`

**カラム命名**
- ID: `id`（主キー）, `room_id`（外部キー）
- 日時: `created_at`, `updated_at`, `checkin_date`
- ステータス: `status`, `payment_status`

## 🔄 開発フロー

### ブランチ戦略

```
main
├── develop              # 開発ブランチ
├── feature/xxx          # 機能開発
├── bugfix/xxx           # バグ修正
└── hotfix/xxx           # 緊急修正
```

### プルリクエスト

1. **ブランチ作成**
   ```bash
   git checkout -b feature/new-feature
   ```

2. **開発・テスト**
   - 機能実装
   - 動作確認
   - コードレビュー

3. **プルリクエスト作成**
   - 変更内容の説明
   - テスト結果の記載
   - スクリーンショット（UI変更の場合）

### コミットメッセージ

```
[type]: [subject]

[body]

[footer]
```

**Type:**
- `feat`: 新機能
- `fix`: バグ修正
- `docs`: ドキュメント
- `style`: コードスタイル
- `refactor`: リファクタリング
- `test`: テスト
- `chore`: その他

**例:**
```
feat: 月間ビューに横跨ぎブロック表示を追加

- 複数日予約を一つの連続ブロックで表示
- チェックアウト日を含まない正確な期間表示
- ホバー時の詳細情報表示

Closes #123
```

## 🧪 テスト

### 手動テスト

**基本機能**
1. 予約フォーム（http://localhost:8000）
2. 管理画面（http://localhost:8000/admin）
3. 月間ビュー（http://localhost:8000/monthly）
4. 日間ビュー（http://localhost:8000/daily）
5. 設定画面（http://localhost:8000/settings）

**API テスト**
```bash
# 予約一覧
curl "http://localhost:8000/api/list_reservations.php"

# 部屋タイプ一覧
curl "http://localhost:8000/api/room_types.php"

# 新規予約作成
curl -X POST "http://localhost:8000/api/create_reservation.php" \
  -H "Content-Type: application/json" \
  -d '{"name":"テスト太郎","phone":"080-1234-5678"}'
```

### ブラウザテスト

**対応ブラウザ**
- Chrome（最新版）
- Firefox（最新版）
- Safari（最新版）
- Edge（最新版）

**レスポンシブテスト**
- デスクトップ（1920x1080）
- タブレット（768x1024）
- スマートフォン（375x667）

## 🐛 デバッグ

### ログの確認

```bash
# PHPエラーログ
tail -f /var/log/php_errors.log

# ブラウザ開発者ツール
# F12 → Console/Network タブ
```

### よくある問題

**データベース接続エラー**
- `config/database.php` の設定確認
- MySQLサービスの起動確認
- ユーザー権限の確認

**API エラー**
- リクエストヘッダーの確認
- JSONフォーマットの確認
- CORSエラーの確認

**画面表示エラー**
- JavaScriptエラーの確認
- CSSファイルの読み込み確認
- APIレスポンスの確認

## 📚 参考資料

- [PHP公式ドキュメント](https://www.php.net/docs.php)
- [MySQL公式ドキュメント](https://dev.mysql.com/doc/)
- [MDN Web Docs](https://developer.mozilla.org/)
- [PSR-12 コーディング規約](https://www.php-fig.org/psr/psr-12/)

## 🤝 コントリビューション

1. このリポジトリをフォーク
2. 機能ブランチを作成 (`git checkout -b feature/amazing-feature`)
3. 変更をコミット (`git commit -m 'feat: 素晴らしい機能を追加'`)
4. ブランチにプッシュ (`git push origin feature/amazing-feature`)
5. プルリクエストを作成

## 📞 サポート

質問や問題がある場合は、以下の方法でお気軽にお問い合わせください：

- GitHub Issues: バグ報告・機能要望
- GitHub Discussions: 質問・議論
- Email: [your-email@example.com]

---

**Happy Coding! 🎉**