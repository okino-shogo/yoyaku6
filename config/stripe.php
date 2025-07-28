<?php
/**
 * Stripe決済設定
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Stripe APIキーの設定
$stripe_config = [
    // テスト環境
    'test' => [
        'publishable_key' => getenv('STRIPE_TEST_PUBLISHABLE_KEY') ?: 'pk_test_51xxxxx',
        'secret_key' => getenv('STRIPE_TEST_SECRET_KEY') ?: 'sk_test_51xxxxx',
        'webhook_secret' => getenv('STRIPE_TEST_WEBHOOK_SECRET') ?: 'whsec_test_xxxxx'
    ],
    // 本番環境
    'live' => [
        'publishable_key' => getenv('STRIPE_LIVE_PUBLISHABLE_KEY') ?: '',
        'secret_key' => getenv('STRIPE_LIVE_SECRET_KEY') ?: '',
        'webhook_secret' => getenv('STRIPE_LIVE_WEBHOOK_SECRET') ?: ''
    ]
];

// 現在の環境を判定（デフォルトはテスト環境）
$is_live_mode = getenv('STRIPE_LIVE_MODE') === 'true';
$current_env = $is_live_mode ? 'live' : 'test';

// Stripe APIキーを設定
\Stripe\Stripe::setApiKey($stripe_config[$current_env]['secret_key']);

/**
 * Stripe設定を取得
 */
function getStripeConfig() {
    global $stripe_config, $current_env;
    return $stripe_config[$current_env];
}

/**
 * Stripeクライアントを取得
 */
function getStripeClient() {
    global $stripe_config, $current_env;
    return new \Stripe\StripeClient($stripe_config[$current_env]['secret_key']);
}

/**
 * 決済金額を計算（円→セント変換）
 */
function calculateStripeAmount($yen_amount) {
    return (int)($yen_amount * 1); // 日本円の場合は1倍（セント単位なし）
}

/**
 * Stripe金額を日本円に変換
 */
function convertFromStripeAmount($stripe_amount) {
    return (int)$stripe_amount; // 日本円の場合はそのまま
}

/**
 * 決済成功URLを生成
 */
function getSuccessUrl($reservation_id = null) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:3000';
    $url = "{$protocol}://{$host}/payment/success";
    
    if ($reservation_id) {
        $url .= "?reservation_id=" . urlencode($reservation_id);
    }
    
    return $url;
}

/**
 * 決済キャンセルURLを生成
 */
function getCancelUrl($reservation_id = null) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:3000';
    $url = "{$protocol}://{$host}/payment/cancel";
    
    if ($reservation_id) {
        $url .= "?reservation_id=" . urlencode($reservation_id);
    }
    
    return $url;
}

/**
 * Webhook署名を検証
 */
function verifyWebhookSignature($payload, $signature) {
    $config = getStripeConfig();
    $webhook_secret = $config['webhook_secret'];
    
    try {
        $event = \Stripe\Webhook::constructEvent(
            $payload,
            $signature,
            $webhook_secret
        );
        return $event;
    } catch (\UnexpectedValueException $e) {
        // Invalid payload
        error_log('Stripe Webhook: Invalid payload');
        return false;
    } catch (\Stripe\Exception\SignatureVerificationException $e) {
        // Invalid signature
        error_log('Stripe Webhook: Invalid signature');
        return false;
    }
}

/**
 * 支払い方法のタイプを取得
 */
function getPaymentMethodTypes() {
    return [
        'card',           // クレジットカード
        'konbini',        // コンビニ決済
        'customer_balance' // 残高決済（将来の拡張用）
    ];
}

/**
 * 通貨設定を取得
 */
function getCurrency() {
    return 'jpy'; // 日本円
}

/**
 * Stripe決済ログを記録
 */
function logStripeAction($action, $data = []) {
    $log_data = [
        'timestamp' => date('Y-m-d H:i:s'),
        'action' => $action,
        'data' => $data,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
    ];
    
    error_log('STRIPE_ACTION: ' . json_encode($log_data, JSON_UNESCAPED_UNICODE));
}