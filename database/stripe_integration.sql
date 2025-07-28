-- Stripe決済統合のためのデータベース拡張

-- reservationsテーブルにStripe関連フィールドを追加
ALTER TABLE reservations 
ADD COLUMN stripe_payment_intent_id VARCHAR(255) NULL COMMENT 'Stripe PaymentIntent ID',
ADD COLUMN stripe_checkout_session_id VARCHAR(255) NULL COMMENT 'Stripe Checkout Session ID',
ADD COLUMN payment_method ENUM('cash', 'card', 'konbini', 'online', 'stripe_card', 'stripe_konbini') DEFAULT 'cash' COMMENT '支払い方法',
ADD COLUMN stripe_payment_status ENUM('pending', 'processing', 'succeeded', 'failed', 'canceled', 'requires_action') NULL COMMENT 'Stripe決済ステータス',
ADD COLUMN total_amount DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '総額（税込）',
ADD COLUMN payment_due_date DATE NULL COMMENT '支払い期限',
ADD COLUMN payment_completed_at TIMESTAMP NULL COMMENT '決済完了日時',
ADD COLUMN refund_amount DECIMAL(10,2) DEFAULT 0 COMMENT '返金額',
ADD COLUMN refund_reason TEXT NULL COMMENT '返金理由';

-- paymentsテーブルにStripe関連フィールドを追加
ALTER TABLE payments
ADD COLUMN stripe_payment_intent_id VARCHAR(255) NULL COMMENT 'Stripe PaymentIntent ID',
ADD COLUMN stripe_charge_id VARCHAR(255) NULL COMMENT 'Stripe Charge ID',
ADD COLUMN stripe_refund_id VARCHAR(255) NULL COMMENT 'Stripe Refund ID（返金時）',
ADD COLUMN payment_status ENUM('pending', 'processing', 'succeeded', 'failed', 'canceled', 'refunded', 'partially_refunded') DEFAULT 'pending' COMMENT '決済ステータス',
ADD COLUMN currency VARCHAR(3) DEFAULT 'JPY' COMMENT '通貨',
ADD COLUMN stripe_fee DECIMAL(10,2) DEFAULT 0 COMMENT 'Stripe手数料',
ADD COLUMN net_amount DECIMAL(10,2) DEFAULT 0 COMMENT '手数料差引後金額',
ADD COLUMN failure_reason TEXT NULL COMMENT '決済失敗理由',
ADD COLUMN receipt_url VARCHAR(500) NULL COMMENT 'Stripe領収書URL',
ADD COLUMN metadata JSON NULL COMMENT 'Stripe metadata';

-- Stripe決済ログテーブルを新規作成
CREATE TABLE stripe_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    stripe_event_id VARCHAR(255) NOT NULL UNIQUE COMMENT 'Stripe Event ID',
    event_type VARCHAR(100) NOT NULL COMMENT 'イベントタイプ',
    object_id VARCHAR(255) NOT NULL COMMENT '対象オブジェクトID',
    reservation_id INT NULL COMMENT '関連予約ID',
    payment_id INT NULL COMMENT '関連支払いID',
    processed BOOLEAN DEFAULT FALSE COMMENT '処理済みフラグ',
    event_data JSON NULL COMMENT 'イベントデータ',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    FOREIGN KEY (reservation_id) REFERENCES reservations(id),
    FOREIGN KEY (payment_id) REFERENCES payments(id),
    INDEX idx_stripe_event_id (stripe_event_id),
    INDEX idx_event_type (event_type),
    INDEX idx_processed (processed),
    INDEX idx_created_at (created_at)
) COMMENT 'Stripe Webhookイベントログ';

-- 決済設定テーブルを新規作成
CREATE TABLE payment_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    description TEXT,
    is_sensitive BOOLEAN DEFAULT FALSE COMMENT '機密情報フラグ',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) COMMENT '決済関連設定';

-- 決済設定の初期データ
INSERT INTO payment_settings (setting_key, setting_value, description, is_sensitive) VALUES
('stripe_enabled', 'true', 'Stripe決済の有効/無効', false),
('payment_methods', '["stripe_card", "stripe_konbini", "cash"]', '利用可能な支払い方法', false),
('default_payment_method', 'stripe_card', 'デフォルト支払い方法', false),
('payment_due_days', '7', '支払い期限（日数）', false),
('konbini_payment_enabled', 'true', 'コンビニ決済の有効/無効', false),
('auto_capture', 'true', '自動決済確定の有効/無効', false),
('webhook_url', '', 'Webhook URL', false),
('stripe_public_key', '', 'Stripe公開可能キー', true),
('stripe_secret_key', '', 'Stripeシークレットキー', true),
('stripe_webhook_secret', '', 'Stripe Webhookシークレット', true);

-- 既存のsystem_settingsテーブルに決済関連設定を追加
INSERT INTO system_settings (setting_key, setting_value, description) VALUES
('payment_enabled', 'true', 'オンライン決済機能の有効/無効'),
('payment_confirmation_email', 'true', '決済完了メール通知の有効/無効'),
('payment_failure_email', 'true', '決済失敗メール通知の有効/無効'),
('refund_notification_email', 'true', '返金通知メール送信の有効/無効')
ON DUPLICATE KEY UPDATE 
setting_value = VALUES(setting_value),
description = VALUES(description);

-- インデックスの追加（パフォーマンス向上）
ALTER TABLE reservations 
ADD INDEX idx_stripe_payment_intent (stripe_payment_intent_id),
ADD INDEX idx_stripe_checkout_session (stripe_checkout_session_id),
ADD INDEX idx_payment_method (payment_method),
ADD INDEX idx_stripe_payment_status (stripe_payment_status),
ADD INDEX idx_payment_due_date (payment_due_date);

ALTER TABLE payments
ADD INDEX idx_stripe_payment_intent (stripe_payment_intent_id),
ADD INDEX idx_stripe_charge (stripe_charge_id),
ADD INDEX idx_payment_status (payment_status);

-- 既存データの更新（total_amountをpriceから設定）
UPDATE reservations SET total_amount = price WHERE total_amount = 0;

-- 外部キー制約の追加（データ整合性確保）
-- ALTER TABLE stripe_events 
-- ADD CONSTRAINT fk_stripe_events_reservation 
-- FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE SET NULL,
-- ADD CONSTRAINT fk_stripe_events_payment 
-- FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL;