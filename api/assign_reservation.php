<?php
/**
 * 予約割り当てAPI
 * ドラッグ&ドロップによる部屋割り当て機能
 * 
 * POST /api/assign_reservation.php - 予約を部屋に割り当て
 */

require_once __DIR__ . '/../config/database.php';

// CORSヘッダーを設定
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

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

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendErrorResponse('許可されていないメソッドです', 405);
    }

    handleAssignReservation($pdo);

} catch (Exception $e) {
    error_log("assign_reservation.php Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

/**
 * 予約割り当て処理
 */
function handleAssignReservation($pdo) {
    // リクエストボディを取得
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        sendErrorResponse('無効なJSONデータです', 400);
    }

    // 必須パラメータの検証
    if (!isset($data['reservation_id']) || !isset($data['room_id'])) {
        sendErrorResponse('reservation_id と room_id は必須です', 400);
    }

    $reservation_id = (int)$data['reservation_id'];
    $room_id = (int)$data['room_id'];

    if ($reservation_id <= 0 || $room_id <= 0) {
        sendErrorResponse('無効なIDが指定されました', 400);
    }

    try {
        // トランザクション開始
        $pdo->beginTransaction();

        // 予約が存在し、未割り当てかチェック
        $reservation = getReservation($pdo, $reservation_id);
        if (!$reservation) {
            throw new Exception('指定された予約が見つかりません');
        }

        if ($reservation['reservation_status'] === 'cancelled') {
            throw new Exception('キャンセルされた予約は割り当てできません');
        }

        // 部屋が存在するかチェック
        $room = getRoom($pdo, $room_id);
        if (!$room) {
            throw new Exception('指定された部屋が見つかりません');
        }

        // 部屋の空き状況をチェック（同じ期間に他の予約がないか）
        $conflicts = checkRoomConflicts($pdo, $room_id, $reservation['checkin_date'], $reservation['checkout_date'], $reservation_id);
        if (!empty($conflicts)) {
            throw new Exception('指定された期間は既に他の予約が入っています');
        }

        // 定員チェック
        $occupancy = calculateRoomOccupancy($pdo, $room_id, $reservation['checkin_date'], $reservation['checkout_date'], $reservation_id);
        $total_guests = $occupancy['total_guests'] + $reservation['adults'] + $reservation['children'];
        $room_capacity = $room['capacity_adults'] + $room['capacity_children'];

        // 定員オーバーの場合は警告（ただし割り当ては可能）
        $is_overbooked = $total_guests > $room_capacity;

        // 予約を部屋に割り当て
        assignReservationToRoom($pdo, $reservation_id, $room_id);

        // 予約ステータスを「割り当て済み」に更新
        updateReservationStatus($pdo, $reservation_id, 'assigned');

        // トランザクションコミット
        $pdo->commit();

        $response = [
            'success' => true,
            'message' => '予約を部屋に割り当てました',
            'reservation_id' => $reservation_id,
            'room_id' => $room_id,
            'room_number' => $room['room_number'],
            'is_overbooked' => $is_overbooked
        ];

        if ($is_overbooked) {
            $response['warning'] = '定員を超過しています';
        }

        sendJsonResponse($response);

    } catch (Exception $e) {
        // トランザクションロールバック
        $pdo->rollBack();
        sendErrorResponse($e->getMessage(), 400);
    }
}

/**
 * 予約情報取得
 */
function getReservation($pdo, $reservation_id) {
    $sql = "
        SELECT 
            r.id,
            r.room_id,
            r.checkin_date,
            r.checkout_date,
            r.adults,
            r.children,
            r.reservation_status,
            c.name as customer_name
        FROM reservations r
        LEFT JOIN customers c ON r.customer_id = c.id
        WHERE r.id = ?
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$reservation_id]);
    
    return $stmt->fetch();
}

/**
 * 部屋情報取得
 */
function getRoom($pdo, $room_id) {
    $sql = "
        SELECT 
            r.id,
            r.room_number,
            r.capacity_adults,
            r.capacity_children,
            rt.name as room_type_name
        FROM rooms r
        LEFT JOIN room_types rt ON r.room_type_id = rt.id
        WHERE r.id = ?
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$room_id]);
    
    return $stmt->fetch();
}

/**
 * 部屋の競合チェック
 */
function checkRoomConflicts($pdo, $room_id, $checkin_date, $checkout_date, $exclude_reservation_id = null) {
    $sql = "
        SELECT 
            r.id,
            r.checkin_date,
            r.checkout_date,
            c.name as customer_name
        FROM reservations r
        LEFT JOIN customers c ON r.customer_id = c.id
        WHERE r.room_id = ?
        AND r.reservation_status != 'cancelled'
        AND (
            (r.checkin_date < ? AND r.checkout_date > ?)
            OR (r.checkin_date < ? AND r.checkout_date > ?)
            OR (r.checkin_date >= ? AND r.checkout_date <= ?)
        )
    ";
    
    $params = [$room_id, $checkout_date, $checkin_date, $checkout_date, $checkin_date, $checkin_date, $checkout_date];
    
    if ($exclude_reservation_id) {
        $sql .= " AND r.id != ?";
        $params[] = $exclude_reservation_id;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    return $stmt->fetchAll();
}

/**
 * 部屋の利用状況計算
 */
function calculateRoomOccupancy($pdo, $room_id, $checkin_date, $checkout_date, $exclude_reservation_id = null) {
    $sql = "
        SELECT 
            SUM(r.adults + r.children) as total_guests,
            COUNT(r.id) as reservation_count
        FROM reservations r
        WHERE r.room_id = ?
        AND r.reservation_status != 'cancelled'
        AND (
            (r.checkin_date < ? AND r.checkout_date > ?)
            OR (r.checkin_date < ? AND r.checkout_date > ?)
            OR (r.checkin_date >= ? AND r.checkout_date <= ?)
        )
    ";
    
    $params = [$room_id, $checkout_date, $checkin_date, $checkout_date, $checkin_date, $checkin_date, $checkout_date];
    
    if ($exclude_reservation_id) {
        $sql .= " AND r.id != ?";
        $params[] = $exclude_reservation_id;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    $result = $stmt->fetch();
    
    return [
        'total_guests' => (int)($result['total_guests'] ?? 0),
        'reservation_count' => (int)($result['reservation_count'] ?? 0)
    ];
}

/**
 * 予約を部屋に割り当て
 */
function assignReservationToRoom($pdo, $reservation_id, $room_id) {
    $sql = "UPDATE reservations SET room_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([$room_id, $reservation_id]);
    
    if (!$result) {
        throw new Exception('予約の割り当てに失敗しました');
    }
    
    return true;
}

/**
 * 予約ステータス更新
 */
function updateReservationStatus($pdo, $reservation_id, $status) {
    $sql = "UPDATE reservations SET reservation_status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([$status, $reservation_id]);
    
    if (!$result) {
        throw new Exception('予約ステータスの更新に失敗しました');
    }
    
    return true;
}
