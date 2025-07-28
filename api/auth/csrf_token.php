<?php
/**
 * CSRFトークン生成API
 * GET /api/auth/csrf_token.php
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';

// CORSヘッダーを設定
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

// GETリクエストのみ受け付け
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendErrorResponse('許可されていないメソッドです', 405);
}

try {
    // セッション初期化とCSRFトークン生成
    Auth::initSession();
    $csrfToken = Auth::generateCsrfToken();

    sendJsonResponse([
        'csrf_token' => $csrfToken
    ], 200);

} catch (Exception $e) {
    error_log("csrf_token.php Error: " . $e->getMessage());
    sendErrorResponse('CSRFトークン生成中にエラーが発生しました', 500);
} 