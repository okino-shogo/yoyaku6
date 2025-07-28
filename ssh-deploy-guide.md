# 🚀 ロリポップ SSH デプロイガイド

## 📋 SSH接続情報（確認済み）

```
■ SSH接続設定
- サーバー: ssh.lolipop.jp
- アカウント: pecori.jp-osblog
- ポート: 2222
- パスワード: MQ5n7y77yZzPGIxg87zInU9vPK6g5GhT

■ Webディレクトリ
- パス: /home/users/0/pecorjp-osblog/yoyaku6/
```

## 🎯 SSH デプロイのメリット

- ✅ **高速転送**: FTPより高速
- ✅ **一括操作**: コマンドで効率的に作業
- ✅ **Git連携**: 将来的にGit経由でのデプロイも可能
- ✅ **リアルタイム**: 即座に動作確認

## Step 1: SSH接続確認 🔑

### 1.1 ローカルからSSH接続テスト
```bash
ssh -p 2222 pecorjp-osblog@ssh.lolipop.jp
```

### 1.2 接続成功後の確認
```bash
# 現在位置確認
pwd
# /home/users/0/pecorjp-osblog

# Webディレクトリへ移動
cd web/
pwd
# /home/users/0/pecorjp-osblog/web

# ディレクトリ作成テスト
mkdir -p api config database
ls -la
```

## Step 2: ファイル転送方法 📤

### 方法A: SCP使用（推奨）
```bash
# プロジェクトディレクトリから実行
cd /Users/okinotakumiware/yoyaku6

# HTMLファイル転送
scp -P 2222 public/*.html pecorjp-osblog@ssh.lolipop.jp:web/

# .htaccess転送
scp -P 2222 .htaccess pecorjp-osblog@ssh.lolipop.jp:web/

# APIフォルダ転送
scp -P 2222 -r api/ pecorjp-osblog@ssh.lolipop.jp:web/

# Configフォルダ転送
scp -P 2222 -r config/ pecorjp-osblog@ssh.lolipop.jp:web/

# Databaseフォルダ転送（参考用）
scp -P 2222 -r database/ pecorjp-osblog@ssh.lolipop.jp:web/
```

### 方法B: rsync使用（差分同期）
```bash
# 全ファイル一括同期
rsync -avz -e "ssh -p 2222" \
  --exclude='.git' \
  --exclude='node_modules' \
  --exclude='.cursor' \
  --include='public/*.html' \
  --include='api/' \
  --include='config/' \
  --include='database/' \
  --include='.htaccess' \
  ./ pecorjp-osblog@ssh.lolipop.jp:web/

# HTMLファイルのみ同期
rsync -avz -e "ssh -p 2222" public/ pecorjp-osblog@ssh.lolipop.jp:web/
```

## Step 3: SSH経由でのファイル配置 📁

### 3.1 SSH接続して直接作業
```bash
# SSH接続
ssh -p 2222 pecorjp-osblog@ssh.lolipop.jp

# Webディレクトリへ移動
cd web/

# ディレクトリ構成確認
ls -la
# 期待される構成:
# .htaccess
# index.html
# admin.html
# monthly.html
# settings.html
# daily.html
# api/
# config/
# database/

# パーミッション設定
chmod 644 *.html .htaccess
chmod -R 755 api/ config/ database/
find api/ config/ -name "*.php" -exec chmod 644 {} \;
```

### 3.2 設定ファイル確認・編集
```bash
# データベース設定確認
cat config/database.php | grep -A 5 "database_config"

# 必要に応じてviエディタで編集
vi config/database.php

# ファイルサイズ確認
du -sh api/ config/ database/
```

## Step 4: Git経由デプロイ（応用編） 🔧

### 4.1 SSH上でGitリポジトリ設定
```bash
# SSH接続後
cd /home/users/0/pecorjp-osblog/

# Gitリポジトリクローン（将来用）
# git clone https://github.com/your-repo/yoyaku6.git
# cd yoyaku6
# cp -r public/* ../web/
# cp -r api config database ../web/
```

### 4.2 自動デプロイスクリプト作成
```bash
# デプロイスクリプト作成
cat > deploy.sh << 'EOF'
#!/bin/bash
echo "=== Yoyaku System Deployment ==="
cd /home/users/0/pecorjp-osblog/web

# バックアップ
cp -r . ../backup/$(date +%Y%m%d_%H%M%S)

# 権限設定
chmod 644 *.html .htaccess
chmod -R 755 api/ config/ database/
find api/ config/ -name "*.php" -exec chmod 644 {} \;

echo "Deployment completed!"
EOF

chmod +x deploy.sh
```

## Step 5: 動作確認 & デバッグ 🧪

### 5.1 SSH上での動作確認
```bash
# PHPバージョン確認
php -v

# エラーログ確認
tail -f /home/users/0/pecorjp-osblog/logs/php_error.log

# API動作テスト
curl -I http://osblog45.com/api/plans.php

# データベース接続テスト
php -r "
require_once '/home/users/0/pecorjp-osblog/web/config/database.php';
\$pdo = getDatabase();
if (\$pdo) echo 'DB Connection OK'; else echo 'DB Connection Failed';
"
```

### 5.2 リアルタイムログ監視
```bash
# リアルタイムでエラーログ監視
tail -f /home/users/0/pecorjp-osblog/logs/php_error.log

# アクセスログ監視
tail -f /home/users/0/pecorjp-osblog/logs/access.log
```

## 🚀 ワンライナーデプロイ

### 完全自動デプロイコマンド
```bash
# ローカルから一発デプロイ
cd /Users/okinotakumiware/yoyaku6 && \
rsync -avz -e "ssh -p 2222" \
  --delete \
  --exclude='.git' \
  --exclude='*.md' \
  --exclude='.cursor' \
  public/ pecorjp-osblog@ssh.lolipop.jp:web/ && \
scp -P 2222 .htaccess pecorjp-osblog@ssh.lolipop.jp:web/ && \
scp -P 2222 -r api config database pecorjp-osblog@ssh.lolipop.jp:web/ && \
ssh -p 2222 pecorjp-osblog@ssh.lolipop.jp "cd web && chmod 644 *.html .htaccess && chmod -R 755 api config database && find api config -name '*.php' -exec chmod 644 {} \;" && \
echo "✅ Deploy completed! Check: http://osblog45.com/"
```

## 🔧 便利なSSHエイリアス設定

### ~/.ssh/config に追加
```
Host lolipop
    HostName ssh.lolipop.jp
    User pecorjp-osblog
    Port 2222
    ServerAliveInterval 60
```

これで `ssh lolipop` で簡単接続できます！

## 📊 パフォーマンス比較

| 方法 | 速度 | 効率 | 自動化 |
|------|------|------|--------|
| FTP | ⭐⭐ | ⭐⭐ | ⭐ |
| SSH/SCP | ⭐⭐⭐⭐ | ⭐⭐⭐⭐ | ⭐⭐⭐ |
| rsync | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐ |

## 🆘 トラブルシューティング

### SSH接続できない場合
```bash
# 接続テスト
telnet ssh.lolipop.jp 2222

# 詳細デバッグ
ssh -v -p 2222 pecorjp-osblog@ssh.lolipop.jp
```

### パーミッションエラー
```bash
# 権限リセット
find web/ -type f -exec chmod 644 {} \;
find web/ -type d -exec chmod 755 {} \;
```

### ファイル同期エラー
```bash
# 差分確認
rsync -avz -n -e "ssh -p 2222" ./ pecorjp-osblog@ssh.lolipop.jp:web/
```

## ✅ SSH デプロイのメリット

1. **速度向上**: FTPの3-5倍高速
2. **効率化**: 一括操作で時間短縮
3. **自動化**: スクリプト化で繰り返し作業簡素化
4. **デバッグ**: リアルタイムでログ確認
5. **Git連携**: 将来的な継続的デプロイ対応

SSH経由なら、変更のたびに数分でデプロイ完了です！🚀 