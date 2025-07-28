-- 設定画面用データベース拡張スクリプト
-- 設計書 4.4 に基づく実装

USE yoyaku_system;

-- 4.4.1 新規テーブル作成
-- システム設定テーブル
CREATE TABLE system_settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(100) NOT NULL UNIQUE,
  setting_value TEXT,
  description TEXT,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 初期設定データ
INSERT INTO system_settings (setting_key, setting_value, description) VALUES
('facility_name', 'サンプル宿泊施設', '施設名'),
('facility_address', '', '施設住所'),
('facility_phone', '', '施設電話番号'),
('facility_email', '', '施設メールアドレス'),
('checkin_time', '15:00', 'チェックイン時間'),
('checkout_time', '10:00', 'チェックアウト時間'),
('default_list_limit', '50', 'デフォルト一覧表示件数'),
-- API設定
('api_enabled', 'false', 'API機能の有効/無効'),
('api_key', '', 'API認証キー'),
('allowed_origins', '', '許可するオリジン（CORS設定）');

-- 4.4.2 既存テーブルの拡張
-- プランテーブルに状態フラグ追加
ALTER TABLE plans ADD COLUMN is_active BOOLEAN DEFAULT TRUE;
ALTER TABLE plans ADD COLUMN display_order INT DEFAULT 0;

-- 部屋テーブルに備考フィールド追加
ALTER TABLE rooms ADD COLUMN notes TEXT;

-- 部屋タイプテーブルにデフォルト定員追加
ALTER TABLE room_types ADD COLUMN default_capacity_adults INT DEFAULT 2;
ALTER TABLE room_types ADD COLUMN default_capacity_children INT DEFAULT 0;

-- 既存データの更新（display_orderを設定）
UPDATE plans SET display_order = id WHERE display_order = 0;
UPDATE room_types SET 
  default_capacity_adults = CASE 
    WHEN name = 'シングル' THEN 1
    WHEN name = 'ダブル' THEN 2  
    WHEN name = 'ツイン' THEN 2
    WHEN name = 'ファミリー' THEN 4
    ELSE 2
  END,
  default_capacity_children = CASE
    WHEN name = 'シングル' THEN 0
    WHEN name = 'ダブル' THEN 1
    WHEN name = 'ツイン' THEN 1  
    WHEN name = 'ファミリー' THEN 2
    ELSE 1
  END;