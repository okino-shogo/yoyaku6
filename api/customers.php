<?php
/**
 * 顧客取得・検索API
 * GET /api/customers.php
 * パラメータ: search (名前、電話番号、メールアドレスでの検索)
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

    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    
    if ($search) {
        // 検索機能
        $stmt = $pdo->prepare("
            SELECT id, name, kana, phone, email, address, created_at 
            FROM customers 
            WHERE name LIKE :search 
               OR kana LIKE :search 
               OR phone LIKE :search 
               OR email LIKE :search 
            ORDER BY created_at DESC 
            LIMIT 50
        ");
        $search_param = '%' . $search . '%';
        $stmt->bindParam(':search', $search_param);
    } else {
        // 全件取得（最新50件）
        $stmt = $pdo->prepare("
            SELECT id, name, kana, phone, email, address, created_at 
            FROM customers 
            ORDER BY created_at DESC 
            LIMIT 50
        ");
    }
    
    $stmt->execute();
    $customers = $stmt->fetchAll();

    sendJsonResponse($customers);

} catch (Exception $e) {
    error_log("customers.php Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}
