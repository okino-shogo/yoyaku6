<?php
/**
 * ログアウトAPI
 * POST /api/auth/logout.php
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';

// CORSヘッダーを設定
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// POSTリクエストのみ受け付け
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendErrorResponse('許可されていないメソッドです', 405);
}

try {
    // ログアウト処理
    Auth::logout();

    sendJsonResponse([
        'success' => true,
        'message' => 'ログアウトしました'
    ], 200);

} catch (Exception $e) {
    error_log("logout.php Error: " . $e->getMessage());
    sendErrorResponse('ログアウト処理中にエラーが発生しました', 500);
} 