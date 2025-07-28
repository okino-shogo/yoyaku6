-- 顧客テーブルに暗号化フィールドを追加
USE yoyaku_system;

-- 暗号化されたフィールドを追加
ALTER TABLE customers 
ADD COLUMN name_encrypted TEXT AFTER name,
ADD COLUMN name_hash VARCHAR(64) AFTER name_encrypted,
ADD COLUMN phone_encrypted TEXT AFTER phone,
ADD COLUMN phone_hash VARCHAR(64) AFTER phone_encrypted,
ADD COLUMN email_encrypted TEXT AFTER email,
ADD COLUMN email_hash VARCHAR(64) AFTER email_encrypted,
ADD COLUMN address_encrypted TEXT AFTER address,
ADD COLUMN address_hash VARCHAR(64) AFTER address_encrypted;

-- 検索用インデックスを追加
CREATE INDEX idx_customers_name_hash ON customers(name_hash);
CREATE INDEX idx_customers_phone_hash ON customers(phone_hash);
CREATE INDEX idx_customers_email_hash ON customers(email_hash);

-- 注意: 既存データがある場合は、段階的移行スクリプトが必要です
-- 本番環境では以下の手順を推奨：
-- 1. 新しいフィールドを追加（上記SQL）
-- 2. 既存データを暗号化して新フィールドに移行
-- 3. アプリケーションを新バージョンにデプロイ
-- 4. 古いフィールドのデータを確認後削除 