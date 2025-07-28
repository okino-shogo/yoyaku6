<?php
/**
 * 認証状態チェックAPI
 * GET /api/auth/check.php
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
    $isAuthenticated = Auth::check();
    $user = null;
    $csrfToken = null;
    
    if ($isAuthenticated) {
        $user = Auth::user();
        $csrfToken = Auth::generateCsrfToken();
    }

    sendJsonResponse([
        'authenticated' => $isAuthenticated,
        'user' => $user,
        'csrf_token' => $csrfToken
    ], 200);

} catch (Exception $e) {
    error_log("auth_check.php Error: " . $e->getMessage());
    sendJsonResponse([
        'authenticated' => false,
        'user' => null,
        'csrf_token' => null
    ], 200);
} 