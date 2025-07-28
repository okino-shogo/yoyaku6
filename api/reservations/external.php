<?php
/**
 * 外部システム向け予約情報API
 * 外部システムから予約情報を取得するためのエンドポイント
 * 
 * GET /api/reservations/external.php - 予約一覧取得
 */

require_once __DIR__ . '/../../config/database.php';

// CORSヘッダーを設定
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
header('Content-Type: application/json; charset=utf-8');

// OPTIONSリクエストの処理（CORS プリフライト）
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $pdo = getDatabase();
    if (!$pdo) {
        sendErrorResponse('データベース接続エラーです', 500);
    }

    // API機能が有効かチェック
    if (!isApiEnabled($pdo)) {
        sendErrorResponse('API機能が無効です', 403);
    }

    // API認証
    if (!authenticateApiRequest($pdo)) {
        sendErrorResponse('認証に失敗しました', 401);
    }

    // CORS設定チェック
    if (!checkCorsOrigin($pdo)) {
        sendErrorResponse('許可されていないオリジンです', 403);
    }

    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            handleGetReservations($pdo);
            break;
        default:
            sendErrorResponse('許可されていないメソッドです', 405);
    }

} catch (Exception $e) {
    error_log("external.php Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

/**
 * API機能が有効かチェック
 */
function isApiEnabled($pdo) {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'api_enabled'");
    $stmt->execute();
    $result = $stmt->fetch();
    return $result && $result['setting_value'] === 'true';
}

/**
 * API認証
 */
function authenticateApiRequest($pdo) {
    // ヘッダーからAPIキーを取得
    $apiKey = null;
    
    // X-API-Keyヘッダーをチェック
    if (isset($_SERVER['HTTP_X_API_KEY'])) {
        $apiKey = $_SERVER['HTTP_X_API_KEY'];
    }
    // Authorizationヘッダーもチェック（Bearer形式）
    elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['HTTP_AUTHORIZATION'];
        if (preg_match('/Bearer\s+(.*)$/i', $auth, $matches)) {
            $apiKey = $matches[1];
        }
    }
    
    if (!$apiKey) {
        return false;
    }
    
    // データベースからAPIキーを取得して照合
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'api_key'");
    $stmt->execute();
    $result = $stmt->fetch();
    
    return $result && !empty($result['setting_value']) && $result['setting_value'] === $apiKey;
}

/**
 * CORS設定チェック
 */
function checkCorsOrigin($pdo) {
    // Originヘッダーを取得
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    
    if (empty($origin)) {
        return true; // Originがない場合は許可（直接アクセス等）
    }
    
    // 許可するオリジンを取得
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'allowed_origins'");
    $stmt->execute();
    $result = $stmt->fetch();
    
    if (!$result || empty($result['setting_value'])) {
        return true; // 設定がない場合は全て許可
    }
    
    $allowedOrigins = explode("\n", $result['setting_value']);
    foreach ($allowedOrigins as $allowedOrigin) {
        $allowedOrigin = trim($allowedOrigin);
        if (!empty($allowedOrigin) && $origin === $allowedOrigin) {
            return true;
        }
    }
    
    return false;
}

/**
 * 予約一覧取得
 */
function handleGetReservations($pdo) {
    // クエリパラメータの取得
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $status = $_GET['status'] ?? '';
    $dateFrom = $_GET['date_from'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';
    
    // バリデーション
    if ($limit > 1000) $limit = 1000;
    if ($limit < 1) $limit = 50;
    if ($offset < 0) $offset = 0;
    
    // WHERE条件の構築
    $whereConditions = [];
    $params = [];
    
    if (!empty($status)) {
        $whereConditions[] = "r.status = :status";
        $params[':status'] = $status;
    }
    
    if (!empty($dateFrom)) {
        $whereConditions[] = "r.checkin_date >= :date_from";
        $params[':date_from'] = $dateFrom;
    }
    
    if (!empty($dateTo)) {
        $whereConditions[] = "r.checkin_date <= :date_to";
        $params[':date_to'] = $dateTo;
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // 予約データの取得
    $sql = "
        SELECT 
            r.id,
            r.guest_name,
            r.guest_email,
            r.guest_phone,
            r.checkin_date,
            r.checkout_date,
            r.adults,
            r.children,
            r.total_amount,
            r.status,
            r.special_requests,
            r.created_at,
            r.updated_at,
            rm.room_number,
            rt.name as room_type_name,
            p.name as plan_name
        FROM reservations r
        LEFT JOIN rooms rm ON r.room_id = rm.id
        LEFT JOIN room_types rt ON rm.room_type_id = rt.id
        LEFT JOIN plans p ON r.plan_id = p.id
        {$whereClause}
        ORDER BY r.created_at DESC
        LIMIT :limit OFFSET :offset
    ";
    
    $stmt = $pdo->prepare($sql);
    
    // パラメータをバインド
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $reservations = $stmt->fetchAll();
    
    // 総件数の取得
    $countSql = "
        SELECT COUNT(*) as total
        FROM reservations r
        LEFT JOIN rooms rm ON r.room_id = rm.id
        LEFT JOIN room_types rt ON rm.room_type_id = rt.id
        LEFT JOIN plans p ON r.plan_id = p.id
        {$whereClause}
    ";
    
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalCount = $countStmt->fetch()['total'];
    
    // レスポンスの構築
    $response = [
        'success' => true,
        'data' => $reservations,
        'pagination' => [
            'total' => (int)$totalCount,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < $totalCount
        ],
        'timestamp' => date('c')
    ];
    
    sendJsonResponse($response);
}

/**
 * JSONレスポンスの送信
 */
function sendJsonResponse($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * エラーレスポンスの送信
 */
function sendErrorResponse($message, $code = 400) {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error' => $message,
        'timestamp' => date('c')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>