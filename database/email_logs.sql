-- メール送信ログテーブル
CREATE TABLE IF NOT EXISTS email_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reservation_id INT NULL,
    recipient_email VARCHAR(100) NOT NULL,
    subject VARCHAR(200) NOT NULL,
    status ENUM('success', 'failed') NOT NULL,
    sent_at DATETIME NOT NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reservation_id (reservation_id),
    INDEX idx_recipient_email (recipient_email),
    INDEX idx_sent_at (sent_at),
    FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE SET NULL
); 