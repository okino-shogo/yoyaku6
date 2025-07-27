<?php
/**
 * 予約一覧・検索API
 * GET /api/list_reservations.php
 * パラメータ: 
 *   - checkinDate: チェックイン日で絞り込み
 *   - checkoutDate: チェックアウト日で絞り込み
 *   - status: 予約ステータスで絞り込み
 *   - search: 顧客名での検索
 *   - limit: 取得件数制限（デフォルト50）
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

    // パラメータの取得
    $checkin_date = isset($_GET['checkinDate']) ? trim($_GET['checkinDate']) : '';
    $checkout_date = isset($_GET['checkoutDate']) ? trim($_GET['checkoutDate']) : '';
    $status = isset($_GET['status']) ? trim($_GET['status']) : '';
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;

    // SQLクエリの構築
    $sql = "
        SELECT 
            r.id,
            r.checkin_date,
            r.checkout_date,
            r.adults,
            r.children,
            r.price,
            r.payment_status,
            r.reservation_status,
            r.source,
            r.notes,
            r.created_at,
            c.name as customer_name,
            c.phone as customer_phone,
            c.email as customer_email,
            p.name as plan_name,
            p.price as plan_price,
            room.room_number,
            rt.name as room_type_name
        FROM reservations r
        INNER JOIN customers c ON r.customer_id = c.id
        INNER JOIN plans p ON r.plan_id = p.id
        LEFT JOIN rooms room ON r.room_id = room.id
        LEFT JOIN room_types rt ON room.room_type_id = rt.id
        WHERE 1=1
    ";

    $params = [];

    // 条件の追加
    if ($checkin_date) {
        if (!validateDate($checkin_date)) {
            sendErrorResponse('チェックイン日の形式が正しくありません', 400);
        }
        $sql .= " AND r.checkin_date = ?";
        $params[] = $checkin_date;
    }

    if ($checkout_date) {
        if (!validateDate($checkout_date)) {
            sendErrorResponse('チェックアウト日の形式が正しくありません', 400);
        }
        $sql .= " AND r.checkout_date = ?";
        $params[] = $checkout_date;
    }

    if ($status) {
        $valid_statuses = ['unassigned', 'assigned', 'checked_in', 'checked_out', 'cancelled'];
        if (!in_array($status, $valid_statuses)) {
            sendErrorResponse('無効なステータスです', 400);
        }
        $sql .= " AND r.reservation_status = ?";
        $params[] = $status;
    }

    if ($search) {
        $sql .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)";
        $search_param = '%' . $search . '%';
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }

    // 並び順とLIMIT
    $sql .= " ORDER BY r.checkin_date DESC, r.created_at DESC LIMIT " . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reservations = $stmt->fetchAll();

    // データの整形
    $formatted_reservations = array_map(function($reservation) {
        return [
            'id' => (int)$reservation['id'],
            'checkin_date' => $reservation['checkin_date'],
            'checkout_date' => $reservation['checkout_date'],
            'adults' => (int)$reservation['adults'],
            'children' => (int)$reservation['children'],
            'price' => (float)$reservation['price'],
            'payment_status' => $reservation['payment_status'],
            'reservation_status' => $reservation['reservation_status'],
            'source' => $reservation['source'],
            'notes' => $reservation['notes'],
            'created_at' => $reservation['created_at'],
            'customer' => [
                'name' => $reservation['customer_name'],
                'phone' => $reservation['customer_phone'],
                'email' => $reservation['customer_email']
            ],
            'plan' => [
                'name' => $reservation['plan_name'],
                'price' => (float)$reservation['plan_price']
            ],
            'room' => [
                'room_number' => $reservation['room_number'],
                'room_type_name' => $reservation['room_type_name']
            ]
        ];
    }, $reservations);

    sendJsonResponse($formatted_reservations);

} catch (Exception $e) {
    error_log("list_reservations.php Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}