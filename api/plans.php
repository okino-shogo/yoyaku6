<?php
/**
 * プラン取得API
 * GET /api/plans.php
 */

require_once __DIR__ . '/../config/database.php';

// CORSヘッダーを設定
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// GETリクエストのみ受け付け
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendErrorResponse('許可されていないメソッドです', 405);
}

try {
    $pdo = getDatabase();
    if (!$pdo) {
        sendErrorResponse('データベース接続エラーです', 500);
    }

    $stmt = $pdo->prepare("SELECT id, name, description, price, created_at FROM plans ORDER BY id");
    $stmt->execute();
    $plans = $stmt->fetchAll();

    sendJsonResponse($plans);

} catch (Exception $e) {
    error_log("plans.php Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}
