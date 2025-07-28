<?php
/**
 * Stripe Webhook エンドポイント
 * POST /api/stripe/webhook.php
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/stripe.php';
require_once __DIR__ . '/../../config/email.php';
require_once __DIR__ . '/../../config/audit.php';

// リクエストボディとSignatureヘッダーを取得
$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if (empty($payload) || empty($sig_header)) {
    http_response_code(400);
    exit('Invalid request');
}

try {
    // Webhook署名を検証
    $event = verifyWebhookSignature($payload, $sig_header);
    
    if (!$event) {
        http_response_code(400);
        exit('Invalid signature');
    }

    $pdo = getDatabase();
    if (!$pdo) {
        error_log('Database connection failed in webhook');
        http_response_code(500);
        exit('Database error');
    }

    // イベントの重複処理を防ぐ
    $stmt = $pdo->prepare("SELECT id FROM stripe_events WHERE stripe_event_id = ?");
    $stmt->execute([$event->id]);
    if ($stmt->fetch()) {
        // 既に処理済み
        http_response_code(200);
        exit('Event already processed');
    }

    // イベントログを記録
    $stmt = $pdo->prepare("
        INSERT INTO stripe_events 
        (stripe_event_id, event_type, object_id, event_data, processed) 
        VALUES (?, ?, ?, ?, FALSE)
    ");
    $stmt->execute([
        $event->id,
        $event->type,
        $event->data->object->id ?? '',
        json_encode($event->data)
    ]);
    $event_log_id = $pdo->lastInsertId();

    // イベントタイプに応じた処理
    $processed = false;
    
    switch ($event->type) {
        case 'checkout.session.completed':
            $processed = handleCheckoutSessionCompleted($pdo, $event, $event_log_id);
            break;
            
        case 'payment_intent.succeeded':
            $processed = handlePaymentIntentSucceeded($pdo, $event, $event_log_id);
            break;
            
        case 'payment_intent.payment_failed':
            $processed = handlePaymentIntentFailed($pdo, $event, $event_log_id);
            break;
            
        case 'charge.dispute.created':
            $processed = handleChargeDisputeCreated($pdo, $event, $event_log_id);
            break;
            
        case 'invoice.payment_succeeded':
            $processed = handleInvoicePaymentSucceeded($pdo, $event, $event_log_id);
            break;
            
        default:
            // 未対応のイベントタイプ
            error_log("Unhandled webhook event type: " . $event->type);
            $processed = true; // エラーにはしない
            break;
    }

    // 処理完了フラグを更新
    if ($processed) {
        $stmt = $pdo->prepare("
            UPDATE stripe_events 
            SET processed = TRUE, processed_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$event_log_id]);
    }

    // Stripe決済ログ記録
    logStripeAction('webhook_processed', [
        'event_id' => $event->id,
        'event_type' => $event->type,
        'processed' => $processed
    ]);

    http_response_code(200);
    echo json_encode(['status' => 'success', 'processed' => $processed]);

} catch (Exception $e) {
    error_log("Webhook processing error: " . $e->getMessage());
    http_response_code(500);
    exit('Webhook error');
}

/**
 * Checkout Session完了イベントの処理
 */
function handleCheckoutSessionCompleted($pdo, $event, $event_log_id) {
    try {
        $session = $event->data->object;
        $reservation_id = $session->metadata->reservation_id ?? null;
        
        if (!$reservation_id) {
            error_log("No reservation_id in checkout session metadata");
            return false;
        }

        $pdo->beginTransaction();

        // 予約ステータスを更新
        $stmt = $pdo->prepare("
            UPDATE reservations 
            SET payment_status = 'paid',
                stripe_payment_status = 'succeeded',
                stripe_payment_intent_id = ?,
                payment_completed_at = NOW()
            WHERE id = ? AND stripe_checkout_session_id = ?
        ");
        $stmt->execute([
            $session->payment_intent,
            $reservation_id,
            $session->id
        ]);

        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            error_log("No reservation updated for checkout session: " . $session->id);
            return false;
        }

        // 支払い履歴を記録
        $stmt = $pdo->prepare("
            INSERT INTO payments 
            (reservation_id, amount, payment_method, payment_status, 
             stripe_payment_intent_id, stripe_charge_id, currency, paid_at) 
            VALUES (?, ?, ?, 'succeeded', ?, ?, 'JPY', NOW())
        ");
        $stmt->execute([
            $reservation_id,
            convertFromStripeAmount($session->amount_total),
            $session->metadata->payment_method ?? 'stripe_card',
            $session->payment_intent,
            null // charge_idは後でpayment_intent.succeededで更新
        ]);

        // イベントログに予約IDを関連付け
        $stmt = $pdo->prepare("
            UPDATE stripe_events 
            SET reservation_id = ? 
            WHERE id = ?
        ");
        $stmt->execute([$reservation_id, $event_log_id]);

        $pdo->commit();

        // 決済完了メール送信
        try {
            $reservationData = getReservationDataForEmail($pdo, $reservation_id);
            if ($reservationData) {
                sendPaymentConfirmationEmail($reservationData);
            }
        } catch (Exception $e) {
            error_log("Payment confirmation email error: " . $e->getMessage());
        }

        // 監査ログ記録
        AuditLogger::logPaymentAction(
            'PAYMENT_COMPLETED',
            $reservation_id,
            null,
            [
                'stripe_session_id' => $session->id,
                'payment_intent_id' => $session->payment_intent,
                'amount' => convertFromStripeAmount($session->amount_total),
                'currency' => 'JPY'
            ]
        );

        return true;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error handling checkout.session.completed: " . $e->getMessage());
        return false;
    }
}

/**
 * Payment Intent成功イベントの処理
 */
function handlePaymentIntentSucceeded($pdo, $event, $event_log_id) {
    try {
        $payment_intent = $event->data->object;
        
        // 関連する決済レコードを更新（charge_idを設定）
        if (!empty($payment_intent->charges->data)) {
            $charge = $payment_intent->charges->data[0];
            
            $stmt = $pdo->prepare("
                UPDATE payments 
                SET stripe_charge_id = ?,
                    receipt_url = ?,
                    net_amount = amount - stripe_fee
                WHERE stripe_payment_intent_id = ?
            ");
            $stmt->execute([
                $charge->id,
                $charge->receipt_url ?? null,
                $payment_intent->id
            ]);
        }

        return true;

    } catch (Exception $e) {
        error_log("Error handling payment_intent.succeeded: " . $e->getMessage());
        return false;
    }
}

/**
 * Payment Intent失敗イベントの処理
 */
function handlePaymentIntentFailed($pdo, $event, $event_log_id) {
    try {
        $payment_intent = $event->data->object;
        
        // 関連する予約のステータスを更新
        $stmt = $pdo->prepare("
            UPDATE reservations 
            SET stripe_payment_status = 'failed'
            WHERE stripe_payment_intent_id = ?
        ");
        $stmt->execute([$payment_intent->id]);

        // 支払い履歴を更新
        $stmt = $pdo->prepare("
            UPDATE payments 
            SET payment_status = 'failed',
                failure_reason = ?
            WHERE stripe_payment_intent_id = ?
        ");
        $stmt->execute([
            $payment_intent->last_payment_error->message ?? 'Payment failed',
            $payment_intent->id
        ]);

        // 決済失敗通知メール送信
        try {
            $stmt = $pdo->prepare("
                SELECT r.id 
                FROM reservations r 
                WHERE r.stripe_payment_intent_id = ?
            ");
            $stmt->execute([$payment_intent->id]);
            $reservation = $stmt->fetch();

            if ($reservation) {
                $reservationData = getReservationDataForEmail($pdo, $reservation['id']);
                if ($reservationData) {
                    sendPaymentFailureEmail($reservationData);
                }
            }
        } catch (Exception $e) {
            error_log("Payment failure email error: " . $e->getMessage());
        }

        return true;

    } catch (Exception $e) {
        error_log("Error handling payment_intent.payment_failed: " . $e->getMessage());
        return false;
    }
}

/**
 * チャージバック作成イベントの処理
 */
function handleChargeDisputeCreated($pdo, $event, $event_log_id) {
    try {
        $dispute = $event->data->object;
        
        // 管理者に通知メール送信
        error_log("CHARGEBACK ALERT: Dispute created for charge: " . $dispute->charge);
        
        // 必要に応じて追加の処理を実装
        
        return true;

    } catch (Exception $e) {
        error_log("Error handling charge.dispute.created: " . $e->getMessage());
        return false;
    }
}

/**
 * インボイス支払い成功イベントの処理（将来の拡張用）
 */
function handleInvoicePaymentSucceeded($pdo, $event, $event_log_id) {
    try {
        // 将来的にサブスクリプションやリカーring決済を実装する場合に使用
        return true;

    } catch (Exception $e) {
        error_log("Error handling invoice.payment_succeeded: " . $e->getMessage());
        return false;
    }
}

/**
 * メール送信用の予約データを取得（create_reservation.phpから移植）
 */
function getReservationDataForEmail($pdo, $reservationId) {
    $stmt = $pdo->prepare("
        SELECT 
            r.id,
            r.checkin_date,
            r.checkout_date,
            r.adults,
            r.children,
            r.total_amount as price,
            r.payment_status,
            r.reservation_status,
            c.name_encrypted as customer_name_encrypted,
            c.phone_encrypted as customer_phone_encrypted,
            c.email_encrypted as customer_email_encrypted,
            p.name as plan_name,
            s1.setting_value as facility_name,
            s2.setting_value as facility_address,
            s3.setting_value as facility_phone,
            s4.setting_value as facility_email,
            s5.setting_value as checkin_time,
            s6.setting_value as checkout_time
        FROM reservations r
        JOIN customers c ON r.customer_id = c.id
        JOIN plans p ON r.plan_id = p.id
        LEFT JOIN system_settings s1 ON s1.setting_key = 'facility_name'
        LEFT JOIN system_settings s2 ON s2.setting_key = 'facility_address'
        LEFT JOIN system_settings s3 ON s3.setting_key = 'facility_phone'
        LEFT JOIN system_settings s4 ON s4.setting_key = 'facility_email'
        LEFT JOIN system_settings s5 ON s5.setting_key = 'checkin_time'
        LEFT JOIN system_settings s6 ON s6.setting_key = 'checkout_time'
        WHERE r.id = ?
    ");
    
    $stmt->execute([$reservationId]);
    $result = $stmt->fetch();
    
    if ($result) {
        // 暗号化されたデータを復号化
        require_once __DIR__ . '/../../config/encryption.php';
        $result['customer_name'] = PersonalDataCrypto::decrypt($result['customer_name_encrypted']);
        $result['customer_phone'] = PersonalDataCrypto::decrypt($result['customer_phone_encrypted']);
        $result['customer_email'] = !empty($result['customer_email_encrypted']) 
            ? PersonalDataCrypto::decrypt($result['customer_email_encrypted']) 
            : '';
        
        // 管理画面のURLを追加
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $result['admin_url'] = "{$protocol}://{$host}/admin";
        
        return $result;
    }
    
    return null;
}

/**
 * 決済完了メール送信
 */
function sendPaymentConfirmationEmail($reservationData) {
    // メール送信処理を実装
    // 既存のメール関数を拡張または新規作成
    error_log("Sending payment confirmation email for reservation: " . $reservationData['id']);
}

/**
 * 決済失敗メール送信
 */
function sendPaymentFailureEmail($reservationData) {
    // メール送信処理を実装
    error_log("Sending payment failure email for reservation: " . $reservationData['id']);
}