<?php
/**
 * 部屋タイプ取得API
 * GET /api/room_types.php
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

    $stmt = $pdo->prepare("SELECT id, name, description, created_at FROM room_types ORDER BY id");
    $stmt->execute();
    $room_types = $stmt->fetchAll();

    sendJsonResponse($room_types);

} catch (Exception $e) {
    error_log("room_types.php Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}
