<?php
/**
 * reCAPTCHA設定API
 * フロントエンドでreCAPTCHAサイトキーを動的取得
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// 環境変数読み込み
if (file_exists(__DIR__ . '/../.env')) {
    $envFile = file_get_contents(__DIR__ . '/../.env');
    $envLines = explode("\n", $envFile);
    
    foreach ($envLines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) {
            continue;
        }
        
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/captcha.php';

try {
    $siteKey = $_ENV['RECAPTCHA_SITE_KEY'] ?? '';
    $bypassMode = CaptchaService::isDevelopmentBypass();
    
    // 開発環境の場合は適切なレスポンス
    if ($bypassMode) {
        echo json_encode([
            'success' => true,
            'site_key' => 'development_bypass',
            'bypass_mode' => true,
            'message' => '開発環境：CAPTCHA検証をバイパス中'
        ]);
    } else if (empty($siteKey)) {
        echo json_encode([
            'success' => false,
            'error' => 'reCAPTCHA site key not configured',
            'site_key' => '',
            'bypass_mode' => false
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'site_key' => $siteKey,
            'bypass_mode' => false,
            'script_url' => "https://www.google.com/recaptcha/api.js?render={$siteKey}"
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Configuration error',
        'message' => $e->getMessage()
    ]);
}