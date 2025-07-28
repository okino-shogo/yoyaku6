<?php
/**
 * Stripe Checkout Session作成API
 * POST /api/stripe/create-checkout-session.php
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/stripe.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../config/audit.php';

// セキュリティヘッダーを設定
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');
SecurityHelper::setCSPHeaders();

// POSTリクエストのみ受け付け
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    SecurityHelper::sendSecureJsonResponse(['error' => '許可されていないメソッドです'], 405);
}

try {
    $pdo = getDatabase();
    if (!$pdo) {
        SecurityHelper::sendSecureJsonResponse(['error' => 'データベース接続エラーです'], 500);
    }

    // リクエストデータの取得
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        SecurityHelper::sendSecureJsonResponse(['error' => '無効なリクエストデータです'], 400);
    }

    // 必須パラメータの検証
    $required_fields = ['reservation_id', 'payment_method'];
    foreach ($required_fields as $field) {
        if (!isset($input[$field]) || empty($input[$field])) {
            SecurityHelper::sendSecureJsonResponse(['error' => "{$field}は必須です"], 400);
        }
    }

    $reservation_id = (int)$input['reservation_id'];
    $payment_method = $input['payment_method'];

    // 支払い方法の検証
    $allowed_methods = ['stripe_card', 'stripe_konbini'];
    if (!in_array($payment_method, $allowed_methods)) {
        SecurityHelper::sendSecureJsonResponse(['error' => '無効な支払い方法です'], 400);
    }

    // 予約データの取得
    $stmt = $pdo->prepare("
        SELECT 
            r.id,
            r.total_amount,
            r.payment_status,
            r.stripe_checkout_session_id,
            r.checkin_date,
            r.checkout_date,
            r.adults,
            r.children,
            c.name_encrypted as customer_name_encrypted,
            c.email_encrypted as customer_email_encrypted,
            p.name as plan_name
        FROM reservations r
        JOIN customers c ON r.customer_id = c.id
        JOIN plans p ON r.plan_id = p.id
        WHERE r.id = ?
    ");
    $stmt->execute([$reservation_id]);
    $reservation = $stmt->fetch();

    if (!$reservation) {
        SecurityHelper::sendSecureJsonResponse(['error' => '予約が見つかりません'], 404);
    }

    // 既に決済済みの場合はエラー
    if ($reservation['payment_status'] === 'paid') {
        SecurityHelper::sendSecureJsonResponse(['error' => 'この予約は既に決済済みです'], 400);
    }

    // 既にCheckout Sessionが作成済みの場合は既存のセッションを返す
    if (!empty($reservation['stripe_checkout_session_id'])) {
        try {
            $stripe = getStripeClient();
            $existing_session = $stripe->checkout->sessions->retrieve($reservation['stripe_checkout_session_id']);
            
            // セッションが有効な場合は既存のURLを返す
            if ($existing_session->status === 'open') {
                SecurityHelper::sendSecureJsonResponse([
                    'success' => true,
                    'checkout_url' => $existing_session->url,
                    'session_id' => $existing_session->id
                ]);
            }
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            // セッションが存在しない場合は新規作成を続行
            error_log("Existing Stripe session not found: " . $e->getMessage());
        }
    }

    // 決済金額の計算
    $amount = calculateStripeAmount($reservation['total_amount']);
    
    if ($amount <= 0) {
        SecurityHelper::sendSecureJsonResponse(['error' => '決済金額が無効です'], 400);
    }

    // 顧客データの復号化
    require_once __DIR__ . '/../../config/encryption.php';
    $customer_name = PersonalDataCrypto::decrypt($reservation['customer_name_encrypted']);
    $customer_email = !empty($reservation['customer_email_encrypted']) 
        ? PersonalDataCrypto::decrypt($reservation['customer_email_encrypted']) 
        : '';

    // Stripe Checkout Sessionのパラメータを設定
    $session_params = [
        'payment_method_types' => [$payment_method === 'stripe_card' ? 'card' : 'konbini'],
        'line_items' => [[
            'price_data' => [
                'currency' => getCurrency(),
                'product_data' => [
                    'name' => '宿泊予約 - ' . $reservation['plan_name'],
                    'description' => sprintf(
                        '%s〜%s（%d泊） %d名様',
                        $reservation['checkin_date'],
                        $reservation['checkout_date'],
                        (strtotime($reservation['checkout_date']) - strtotime($reservation['checkin_date'])) / (60*60*24),
                        $reservation['adults'] + $reservation['children']
                    )
                ],
                'unit_amount' => $amount,
            ],
            'quantity' => 1,
        ]],
        'mode' => 'payment',
        'success_url' => getSuccessUrl($reservation_id),
        'cancel_url' => getCancelUrl($reservation_id),
        'customer_email' => $customer_email ?: null,
        'metadata' => [
            'reservation_id' => $reservation_id,
            'customer_name' => $customer_name,
            'payment_method' => $payment_method
        ],
        'expires_at' => time() + (30 * 60), // 30分後に期限切れ
        'automatic_tax' => [
            'enabled' => false,
        ],
    ];

    // コンビニ決済の場合の追加設定
    if ($payment_method === 'stripe_konbini') {
        $session_params['payment_method_options'] = [
            'konbini' => [
                'expires_after_days' => 7 // 7日後に期限切れ
            ]
        ];
    }

    // Stripe Checkout Sessionを作成
    $stripe = getStripeClient();
    $session = $stripe->checkout->sessions->create($session_params);

    // データベースにCheckout Session IDを保存
    $stmt = $pdo->prepare("
        UPDATE reservations 
        SET stripe_checkout_session_id = ?,
            payment_method = ?,
            stripe_payment_status = 'pending',
            payment_due_date = DATE_ADD(NOW(), INTERVAL 7 DAY)
        WHERE id = ?
    ");
    $stmt->execute([$session->id, $payment_method, $reservation_id]);

    // 監査ログ記録
    AuditLogger::logPaymentAction(
        'CHECKOUT_SESSION_CREATED',
        $reservation_id,
        null,
        [
            'stripe_session_id' => $session->id,
            'payment_method' => $payment_method,
            'amount' => $amount,
            'currency' => getCurrency()
        ]
    );

    // Stripe決済ログ記録
    logStripeAction('checkout_session_created', [
        'session_id' => $session->id,
        'reservation_id' => $reservation_id,
        'payment_method' => $payment_method,
        'amount' => $amount
    ]);

    SecurityHelper::sendSecureJsonResponse([
        'success' => true,
        'checkout_url' => $session->url,
        'session_id' => $session->id,
        'expires_at' => $session->expires_at
    ]);

} catch (\Stripe\Exception\CardException $e) {
    // カードエラー
    error_log("Stripe Card Error: " . $e->getMessage());
    SecurityHelper::sendSecureJsonResponse(['error' => 'カード決済でエラーが発生しました'], 400);

} catch (\Stripe\Exception\RateLimitException $e) {
    // レート制限
    error_log("Stripe Rate Limit Error: " . $e->getMessage());
    SecurityHelper::sendSecureJsonResponse(['error' => 'しばらく時間をおいてから再度お試しください'], 429);

} catch (\Stripe\Exception\InvalidRequestException $e) {
    // 無効なリクエスト
    error_log("Stripe Invalid Request Error: " . $e->getMessage());
    SecurityHelper::sendSecureJsonResponse(['error' => '決済処理でエラーが発生しました'], 400);

} catch (\Stripe\Exception\AuthenticationException $e) {
    // 認証エラー
    error_log("Stripe Authentication Error: " . $e->getMessage());
    SecurityHelper::sendSecureJsonResponse(['error' => '決済システムの設定エラーです'], 500);

} catch (\Stripe\Exception\ApiConnectionException $e) {
    // 接続エラー
    error_log("Stripe API Connection Error: " . $e->getMessage());
    SecurityHelper::sendSecureJsonResponse(['error' => '決済システムに接続できません'], 503);

} catch (\Stripe\Exception\ApiErrorException $e) {
    // その他のStripe API エラー
    error_log("Stripe API Error: " . $e->getMessage());
    SecurityHelper::sendSecureJsonResponse(['error' => '決済処理でエラーが発生しました'], 500);

} catch (Exception $e) {
    error_log("Checkout Session Creation Error: " . $e->getMessage());
    SecurityHelper::sendSecureJsonResponse(['error' => 'セッション作成中にエラーが発生しました'], 500);
}