-- メール通知設定の追加
-- 顧客向け通知設定
INSERT INTO system_settings (setting_key, setting_value, description) VALUES
('email_reservation_confirmation', 'true', '予約確認メール送信（顧客向け）'),
('email_reservation_update', 'true', '予約変更通知（顧客向け）'),
('email_cancellation_notice', 'true', 'キャンセル通知（顧客向け）'),
('email_checkin_reminder', 'false', 'チェックイン案内（顧客向け）'),
('email_checkout_notice', 'false', 'チェックアウト案内（顧客向け）'),
('email_payment_reminder', 'false', '支払い督促（顧客向け）'),

-- 管理者向け通知設定
('email_admin_new_reservation', 'true', '新規予約アラート（管理者向け）'),
('email_admin_daily_summary', 'false', '日次サマリー（管理者向け）'),
('email_admin_payment_report', 'false', '未払いレポート（管理者向け）'),
('email_admin_system_error', 'true', 'システムエラー通知（管理者向け）'),

-- メール送信基本設定
('email_from_address', '', 'メール送信元アドレス'),
('email_from_name', '', 'メール送信元名'),
('email_admin_address', '', '管理者メールアドレス'),
('email_enabled', 'false', 'メール機能有効フラグ'); 