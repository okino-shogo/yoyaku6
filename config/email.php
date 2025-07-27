<?php
/**
 * メール送信ライブラリ
 * PHPのmail()関数を使用したシンプルな実装
 */

require_once __DIR__ . '/database.php';

/**
 * メール設定を取得
 */
function getEmailSettings() {
    try {
        $pdo = getDatabase();
        $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'email_%'");
        $stmt->execute();
        $results = $stmt->fetchAll();
        
        $settings = [];
        foreach ($results as $setting) {
            $settings[$setting['setting_key']] = $setting['setting_value'];
        }
        
        return $settings;
    } catch (Exception $e) {
        error_log("Email settings fetch error: " . $e->getMessage());
        return [];
    }
}

/**
 * メール機能が有効かチェック
 */
function isEmailEnabled() {
    $settings = getEmailSettings();
    return isset($settings['email_enabled']) && $settings['email_enabled'] === 'true';
}

/**
 * 特定の通知が有効かチェック
 */
function isNotificationEnabled($notificationType) {
    if (!isEmailEnabled()) {
        return false;
    }
    
    $settings = getEmailSettings();
    $key = 'email_' . $notificationType;
    return isset($settings[$key]) && $settings[$key] === 'true';
}

/**
 * メール送信
 */
function sendEmail($to, $subject, $message, $type = 'text') {
    if (!isEmailEnabled()) {
        error_log("Email sending skipped: Email feature is disabled");
        return false;
    }
    
    $settings = getEmailSettings();
    $fromAddress = $settings['email_from_address'] ?? '';
    $fromName = $settings['email_from_name'] ?? '';
    
    if (empty($fromAddress)) {
        error_log("Email sending failed: No from address configured");
        return false;
    }
    
    // ヘッダーの作成
    $headers = [];
    $headers[] = 'From: ' . ($fromName ? "{$fromName} <{$fromAddress}>" : $fromAddress);
    $headers[] = 'Reply-To: ' . $fromAddress;
    $headers[] = 'X-Mailer: PHP/' . phpversion();
    
    if ($type === 'html') {
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    }
    
    $headerString = implode("\r\n", $headers);
    
    // メール送信の実行
    $success = mail($to, $subject, $message, $headerString);
    
    // ログ記録
    logEmailSend($to, $subject, $success ? 'success' : 'failed', $success ? '' : 'mail() function returned false');
    
    return $success;
}

/**
 * 予約確認メール送信
 */
function sendReservationConfirmationEmail($reservationData) {
    if (!isNotificationEnabled('reservation_confirmation')) {
        return false;
    }
    
    $subject = "【{$reservationData['facility_name']}】ご予約ありがとうございます（予約番号: {$reservationData['id']}）";
    
    $message = buildReservationConfirmationMessage($reservationData);
    
    return sendEmail($reservationData['customer_email'], $subject, $message);
}

/**
 * 予約変更通知メール送信
 */
function sendReservationUpdateEmail($reservationData) {
    if (!isNotificationEnabled('reservation_update')) {
        return false;
    }
    
    $subject = "【{$reservationData['facility_name']}】ご予約内容変更のお知らせ（予約番号: {$reservationData['id']}）";
    
    $message = buildReservationUpdateMessage($reservationData);
    
    return sendEmail($reservationData['customer_email'], $subject, $message);
}

/**
 * キャンセル通知メール送信
 */
function sendCancellationNoticeEmail($reservationData) {
    if (!isNotificationEnabled('cancellation_notice')) {
        return false;
    }
    
    $subject = "【{$reservationData['facility_name']}】ご予約キャンセルのお知らせ（予約番号: {$reservationData['id']}）";
    
    $message = buildCancellationNoticeMessage($reservationData);
    
    return sendEmail($reservationData['customer_email'], $subject, $message);
}

/**
 * 管理者向け新規予約通知
 */
function sendAdminNewReservationAlert($reservationData) {
    if (!isNotificationEnabled('admin_new_reservation')) {
        return false;
    }
    
    $settings = getEmailSettings();
    $adminEmail = $settings['email_admin_address'] ?? '';
    
    if (empty($adminEmail)) {
        error_log("Admin email alert skipped: No admin email configured");
        return false;
    }
    
    $subject = "【新規予約】Web予約が受付されました（予約番号: {$reservationData['id']}）";
    
    $message = buildAdminNewReservationMessage($reservationData);
    
    return sendEmail($adminEmail, $subject, $message);
}

/**
 * 予約確認メールのメッセージ作成
 */
function buildReservationConfirmationMessage($data) {
    $checkinDate = formatDate($data['checkin_date']);
    $checkoutDate = formatDate($data['checkout_date']);
    $nights = calculateNights($data['checkin_date'], $data['checkout_date']);
    
    $message = $data['customer_name'] . " 様\n\n";
    $message .= "この度は{$data['facility_name']}をご利用いただき、誠にありがとうございます。\n";
    $message .= "ご予約を承りました。詳細は以下の通りです。\n\n";
    $message .= "■ 予約詳細\n";
    $message .= "予約番号: {$data['id']}\n";
    $message .= "お名前: {$data['customer_name']}\n";
    $message .= "ご宿泊期間: {$checkinDate} 〜 {$checkoutDate} ({$nights}泊)\n";
    $message .= "ご利用人数: 大人{$data['adults']}名\n";
    $message .= "プラン: {$data['plan_name']}\n";
    $message .= "ご料金: ¥" . number_format($data['price']) . "\n\n";
    $message .= "■ 当館のご案内\n";
    $message .= "チェックイン: {$data['checkin_time']}\n";
    $message .= "チェックアウト: {$data['checkout_time']}\n";
    $message .= "住所: {$data['facility_address']}\n";
    $message .= "電話: {$data['facility_phone']}\n\n";
    $message .= "■ お問い合わせ\n";
    $message .= "ご質問やご要望がございましたら、お気軽にお電話ください。\n";
    $message .= "スタッフ一同、{$data['customer_name']}様のお越しを心よりお待ちしております。\n\n";
    $message .= "--\n";
    $message .= "{$data['facility_name']}\n";
    $message .= "{$data['facility_phone']}\n";
    $message .= "{$data['facility_email']}";
    
    return $message;
}

/**
 * 予約変更通知メッセージ作成
 */
function buildReservationUpdateMessage($data) {
    $checkinDate = formatDate($data['checkin_date']);
    $checkoutDate = formatDate($data['checkout_date']);
    $nights = calculateNights($data['checkin_date'], $data['checkout_date']);
    
    $message = $data['customer_name'] . " 様\n\n";
    $message .= "いつもお世話になっております。\n";
    $message .= "{$data['facility_name']}でございます。\n\n";
    $message .= "ご予約内容に変更がございましたので、お知らせいたします。\n\n";
    $message .= "■ 変更後の予約詳細\n";
    $message .= "予約番号: {$data['id']}\n";
    $message .= "お名前: {$data['customer_name']}\n";
    $message .= "ご宿泊期間: {$checkinDate} 〜 {$checkoutDate} ({$nights}泊)\n";
    $message .= "ご利用人数: 大人{$data['adults']}名\n";
    $message .= "プラン: {$data['plan_name']}\n";
    $message .= "ご料金: ¥" . number_format($data['price']) . "\n\n";
    $message .= "ご不明な点がございましたら、お気軽にお問い合わせください。\n\n";
    $message .= "--\n";
    $message .= "{$data['facility_name']}\n";
    $message .= "{$data['facility_phone']}\n";
    $message .= "{$data['facility_email']}";
    
    return $message;
}

/**
 * キャンセル通知メッセージ作成
 */
function buildCancellationNoticeMessage($data) {
    $message = $data['customer_name'] . " 様\n\n";
    $message .= "いつもお世話になっております。\n";
    $message .= "{$data['facility_name']}でございます。\n\n";
    $message .= "以下のご予約につきまして、キャンセルを承りました。\n\n";
    $message .= "■ キャンセル済み予約\n";
    $message .= "予約番号: {$data['id']}\n";
    $message .= "お名前: {$data['customer_name']}\n";
    $message .= "ご宿泊期間: " . formatDate($data['checkin_date']) . " 〜 " . formatDate($data['checkout_date']) . "\n";
    $message .= "キャンセル日時: " . date('Y年m月d日 H:i') . "\n\n";
    
    if (!empty($data['refund_amount']) && $data['refund_amount'] > 0) {
        $message .= "■ 返金について\n";
        $message .= "返金額: ¥" . number_format($data['refund_amount']) . "\n";
        $message .= "返金手続きについては、別途ご連絡いたします。\n\n";
    }
    
    $message .= "またのご利用を心よりお待ちしております。\n\n";
    $message .= "--\n";
    $message .= "{$data['facility_name']}\n";
    $message .= "{$data['facility_phone']}\n";
    $message .= "{$data['facility_email']}";
    
    return $message;
}

/**
 * 管理者向け新規予約通知メッセージ作成
 */
function buildAdminNewReservationMessage($data) {
    $checkinDate = formatDate($data['checkin_date']);
    $checkoutDate = formatDate($data['checkout_date']);
    $nights = calculateNights($data['checkin_date'], $data['checkout_date']);
    
    $message = "Web予約システムより新規予約が受付されました。\n\n";
    $message .= "■ 予約詳細\n";
    $message .= "予約番号: {$data['id']}\n";
    $message .= "受付日時: " . date('Y年m月d日 H:i') . "\n";
    $message .= "お名前: {$data['customer_name']}\n";
    $message .= "電話番号: {$data['customer_phone']}\n";
    $message .= "メールアドレス: {$data['customer_email']}\n";
    $message .= "ご宿泊期間: {$checkinDate} 〜 {$checkoutDate} ({$nights}泊)\n";
    $message .= "ご利用人数: 大人{$data['adults']}名\n";
    $message .= "プラン: {$data['plan_name']}\n";
    $message .= "ご料金: ¥" . number_format($data['price']) . "\n";
    $message .= "支払い状況: {$data['payment_status']}\n";
    $message .= "予約状況: {$data['reservation_status']}\n\n";
    $message .= "■ お客様への対応\n";
    $message .= "- 予約確認メールは自動送信済みです\n";
    $message .= "- 必要に応じて部屋割り当てを行ってください\n";
    $message .= "- 特別なご要望がある場合は個別にご連絡ください\n\n";
    $message .= "管理画面: {$data['admin_url']}";
    
    return $message;
}

/**
 * メール送信ログを記録
 */
function logEmailSend($to, $subject, $status, $errorMessage = '') {
    try {
        $pdo = getDatabase();
        $stmt = $pdo->prepare("
            INSERT INTO email_logs (recipient_email, subject, status, sent_at, error_message)
            VALUES (?, ?, ?, NOW(), ?)
        ");
        $stmt->execute([$to, $subject, $status, $errorMessage]);
    } catch (Exception $e) {
        error_log("Email log recording error: " . $e->getMessage());
    }
}

/**
 * 日付フォーマット
 */
function formatDate($date) {
    return date('Y年m月d日', strtotime($date));
}

/**
 * 宿泊日数計算
 */
function calculateNights($checkinDate, $checkoutDate) {
    $checkin = new DateTime($checkinDate);
    $checkout = new DateTime($checkoutDate);
    return $checkin->diff($checkout)->days;
} 