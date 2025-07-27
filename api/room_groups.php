<?php
/**
 * 部屋グループ取得API
 * GET /api/room_groups.php
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

    $stmt = $pdo->prepare("SELECT id, name, created_at FROM room_groups ORDER BY id");
    $stmt->execute();
    $room_groups = $stmt->fetchAll();

    sendJsonResponse($room_groups);

} catch (Exception $e) {
    error_log("room_groups.php Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}
