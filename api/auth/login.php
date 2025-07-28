<?php
/**
 * ログインAPI
 * POST /api/auth/login.php
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/captcha.php';
require_once __DIR__ . '/../../config/audit.php';
require_once __DIR__ . '/../../config/rate_limiter.php';

// CORSヘッダーを設定
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// POSTリクエストのみ受け付け
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendErrorResponse('許可されていないメソッドです', 405);
}

try {
    // リクエストデータの取得
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    // 必須項目のバリデーション
    $required_fields = ['username', 'password'];
    $validation_errors = validateRequired($input, $required_fields);
    
    if (!empty($validation_errors)) {
        sendErrorResponse(implode(', ', $validation_errors), 400);
    }

    // CAPTCHA検証
    if (isset($input['recaptcha_token'])) {
        $captchaResult = CaptchaService::requireCaptcha('login', $input['recaptcha_token']);
        
        if (!$captchaResult['success']) {
            // 監査ログ記録 - CAPTCHA失敗
            AuditLogger::logSecurityEvent('CAPTCHA_FAILED', [
                'action' => 'login',
                'username' => $input['username'],
                'score' => $captchaResult['score'] ?? 0.0,
                'error' => $captchaResult['error'] ?? 'unknown',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
            
            sendErrorResponse('スパム対策のため、しばらく時間をおいてから再度お試しください', 429);
        }
        
        // CAPTCHA成功ログ
        error_log("CAPTCHA Success: action=login, username=" . $input['username'] . ", score=" . ($captchaResult['score'] ?? 'N/A'));
    }

    // レート制限チェック（Redis使用高性能版）
    $clientIp = RateLimiter::getClientIp();
    $rateLimitResult = RateLimiter::checkIpLimit('login', $clientIp);
    
    if (!$rateLimitResult['allowed']) {
        $retryAfter = $rateLimitResult['retry_after'] ?? 300;
        $message = "ログイン試行回数が上限に達しました。" . ceil($retryAfter / 60) . "分後に再度お試しください。";
        
        header("Retry-After: $retryAfter");
        sendErrorResponse($message, 429);
    }

    // ログイン処理
    $user = Auth::login(trim($input['username']), $input['password']);
    
    // 成功時はレート制限をクリア
    if (file_exists($attemptsFile)) {
        unlink($attemptsFile);
    }

    sendJsonResponse([
        'success' => true,
        'user' => $user,
        'csrf_token' => Auth::generateCsrfToken(),
        'message' => 'ログインに成功しました'
    ], 200);

} catch (Exception $e) {
    // 失敗時はレート制限に記録
    $attempts[] = $currentTime;
    file_put_contents($attemptsFile, json_encode($attempts));
    
    error_log("login.php Error: " . $e->getMessage());
    sendErrorResponse($e->getMessage(), 401);
} 