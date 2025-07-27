<?php
/**
 * 日間ビューAPI
 * 設計書 2.2.2 dailyRoomGrid に基づく実装
 * 
 * GET /api/daily_view.php - 日間ビューデータ取得
 */

require_once __DIR__ . '/../config/database.php';

// CORSヘッダーを設定
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
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

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        sendErrorResponse('許可されていないメソッドです', 405);
    }

    handleGetDailyView($pdo);

} catch (Exception $e) {
    error_log("daily_view.php Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

/**
 * 日間ビューデータ取得
 */
function handleGetDailyView($pdo) {
    // パラメータの取得
    $date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
    $group_id = isset($_GET['group_id']) ? (int)$_GET['group_id'] : null;
    $view_mode = isset($_GET['view_mode']) ? $_GET['view_mode'] : 'all'; // all, checkin, checkout, staying
    $keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';

    // 日付の妥当性チェック
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        sendErrorResponse('無効な日付形式です', 400);
    }

    try {
        // 部屋一覧を取得
        $rooms = getRoomsForDaily($pdo, $group_id);
        
        // 指定日の予約データを取得
        $reservations = getDailyReservations($pdo, $date, $keyword, $view_mode);
        
        // 部屋グリッドデータを構築
        $room_grid = buildRoomGrid($rooms, $reservations, $date);
        
        // 未割当予約を取得
        $unassigned_reservations = getUnassignedDailyReservations($pdo, $date, $keyword, $view_mode);

        // 部屋グループ一覧を取得
        $room_groups = getRoomGroups($pdo);

        $response = [
            'date' => $date,
            'view_mode' => $view_mode,
            'group_id' => $group_id,
            'keyword' => $keyword,
            'rooms' => $rooms,
            'room_grid' => $room_grid,
            'unassigned_reservations' => $unassigned_reservations,
            'room_groups' => $room_groups,
            'total_rooms' => count($rooms)
        ];

        sendJsonResponse($response);
    } catch (Exception $e) {
        error_log("handleGetDailyView Error: " . $e->getMessage());
        sendErrorResponse('データ取得エラー: ' . $e->getMessage(), 500);
    }
}

/**
 * 日間ビュー用部屋一覧取得
 */
function getRoomsForDaily($pdo, $group_id = null) {
    $sql = "
        SELECT 
            r.id,
            r.room_number,
            r.room_type_id,
            r.group_id,
            r.capacity_adults,
            r.capacity_children,
            r.display_order,
            rt.name as room_type_name,
            rg.name as group_name
        FROM rooms r
        LEFT JOIN room_types rt ON r.room_type_id = rt.id
        LEFT JOIN room_groups rg ON r.group_id = rg.id
        WHERE 1=1
    ";
    
    $params = [];
    
    if ($group_id) {
        $sql .= " AND r.group_id = ?";
        $params[] = $group_id;
    }
    
    $sql .= " ORDER BY r.display_order ASC, r.room_number ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    return $stmt->fetchAll();
}

/**
 * 指定日の予約データ取得
 */
function getDailyReservations($pdo, $date, $keyword = '', $view_mode = 'all') {
    $sql = "
        SELECT 
            r.id,
            r.customer_id,
            r.plan_id,
            r.room_id,
            r.checkin_date,
            r.checkout_date,
            r.adults,
            r.children,
            r.price,
            r.payment_status,
            r.reservation_status,
            r.notes,
            c.name as customer_name,
            c.kana as customer_kana,
            c.phone as customer_phone,
            p.name as plan_name,
            rooms.room_number,
            rt.name as room_type_name
        FROM reservations r
        LEFT JOIN customers c ON r.customer_id = c.id
        LEFT JOIN plans p ON r.plan_id = p.id
        LEFT JOIN rooms ON r.room_id = rooms.id
        LEFT JOIN room_types rt ON rooms.room_type_id = rt.id
        WHERE r.room_id IS NOT NULL
        AND r.reservation_status != 'cancelled'
    ";
    
    $params = [];
    
    // 表示モードに応じた日付フィルタリング
    switch ($view_mode) {
        case 'checkin':
            $sql .= " AND r.checkin_date = ?";
            $params[] = $date;
            break;
        case 'checkout':
            $sql .= " AND r.checkout_date = ?";
            $params[] = $date;
            break;
        case 'staying':
            $sql .= " AND r.checkin_date <= ? AND r.checkout_date > ?";
            $params[] = $date;
            $params[] = $date;
            break;
        default: // 'all'
            $sql .= " AND ((r.checkin_date <= ? AND r.checkout_date > ?) OR r.checkin_date = ? OR r.checkout_date = ?)";
            $params[] = $date;
            $params[] = $date;
            $params[] = $date;
            $params[] = $date;
            break;
    }
    
    if ($keyword) {
        $sql .= " AND (c.name LIKE ? OR p.name LIKE ? OR rooms.room_number LIKE ?)";
        $keyword_param = '%' . $keyword . '%';
        $params[] = $keyword_param;
        $params[] = $keyword_param;
        $params[] = $keyword_param;
    }
    
    $sql .= " ORDER BY rooms.display_order ASC, rooms.room_number ASC, r.checkin_date ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    $reservations = $stmt->fetchAll();
    
    // 各予約に当日のステータスを追加
    return array_map(function($reservation) use ($date) {
        $status = 'staying';
        if ($reservation['checkin_date'] === $date) {
            $status = 'checkin';
        } elseif ($reservation['checkout_date'] === $date) {
            $status = 'checkout';
        }
        
        return [
            'id' => (int)$reservation['id'],
            'customer_id' => (int)$reservation['customer_id'],
            'plan_id' => (int)$reservation['plan_id'],
            'room_id' => (int)$reservation['room_id'],
            'checkin_date' => $reservation['checkin_date'],
            'checkout_date' => $reservation['checkout_date'],
            'adults' => (int)$reservation['adults'],
            'children' => (int)$reservation['children'],
            'price' => (float)$reservation['price'],
            'payment_status' => $reservation['payment_status'],
            'reservation_status' => $reservation['reservation_status'],
            'notes' => $reservation['notes'],
            'customer_name' => $reservation['customer_name'],
            'customer_kana' => $reservation['customer_kana'],
            'customer_phone' => $reservation['customer_phone'],
            'plan_name' => $reservation['plan_name'],
            'room_number' => $reservation['room_number'],
            'room_type_name' => $reservation['room_type_name'],
            'daily_status' => $status
        ];
    }, $reservations);
}

/**
 * 未割当予約取得（日間ビュー用）
 */
function getUnassignedDailyReservations($pdo, $date, $keyword = '', $view_mode = 'all') {
    $sql = "
        SELECT 
            r.id,
            r.customer_id,
            r.plan_id,
            r.checkin_date,
            r.checkout_date,
            r.adults,
            r.children,
            r.price,
            r.payment_status,
            r.reservation_status,
            r.notes,
            c.name as customer_name,
            c.kana as customer_kana,
            c.phone as customer_phone,
            p.name as plan_name
        FROM reservations r
        LEFT JOIN customers c ON r.customer_id = c.id
        LEFT JOIN plans p ON r.plan_id = p.id
        WHERE r.room_id IS NULL
        AND r.reservation_status != 'cancelled'
    ";
    
    $params = [];
    
    // 表示モードに応じた日付フィルタリング
    switch ($view_mode) {
        case 'checkin':
            $sql .= " AND r.checkin_date = ?";
            $params[] = $date;
            break;
        case 'checkout':
            $sql .= " AND r.checkout_date = ?";
            $params[] = $date;
            break;
        case 'staying':
            $sql .= " AND r.checkin_date <= ? AND r.checkout_date > ?";
            $params[] = $date;
            $params[] = $date;
            break;
        default: // 'all'
            $sql .= " AND ((r.checkin_date <= ? AND r.checkout_date > ?) OR r.checkin_date = ? OR r.checkout_date = ?)";
            $params[] = $date;
            $params[] = $date;
            $params[] = $date;
            $params[] = $date;
            break;
    }
    
    if ($keyword) {
        $sql .= " AND (c.name LIKE ? OR p.name LIKE ?)";
        $keyword_param = '%' . $keyword . '%';
        $params[] = $keyword_param;
        $params[] = $keyword_param;
    }
    
    $sql .= " ORDER BY r.checkin_date ASC, r.id ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    $reservations = $stmt->fetchAll();
    
    // 各予約に当日のステータスを追加
    return array_map(function($reservation) use ($date) {
        $status = 'staying';
        if ($reservation['checkin_date'] === $date) {
            $status = 'checkin';
        } elseif ($reservation['checkout_date'] === $date) {
            $status = 'checkout';
        }
        
        return [
            'id' => (int)$reservation['id'],
            'customer_id' => (int)$reservation['customer_id'],
            'plan_id' => (int)$reservation['plan_id'],
            'checkin_date' => $reservation['checkin_date'],
            'checkout_date' => $reservation['checkout_date'],
            'adults' => (int)$reservation['adults'],
            'children' => (int)$reservation['children'],
            'price' => (float)$reservation['price'],
            'payment_status' => $reservation['payment_status'],
            'reservation_status' => $reservation['reservation_status'],
            'notes' => $reservation['notes'],
            'customer_name' => $reservation['customer_name'],
            'customer_kana' => $reservation['customer_kana'],
            'customer_phone' => $reservation['customer_phone'],
            'plan_name' => $reservation['plan_name'],
            'daily_status' => $status
        ];
    }, $reservations);
}

/**
 * 部屋グリッドデータ構築
 */
function buildRoomGrid($rooms, $reservations, $date) {
    $grid = [];
    
    foreach ($rooms as $room) {
        $room_reservations = array_filter($reservations, function($r) use ($room) {
            return $r['room_id'] == $room['id'];
        });
        
        // 利用人数の計算
        $total_guests = 0;
        foreach ($room_reservations as $reservation) {
            $total_guests += $reservation['adults'] + $reservation['children'];
        }
        
        // 定員と利用人数の比較
        $capacity = $room['capacity_adults'] + $room['capacity_children'];
        $occupancy_rate = $capacity > 0 ? ($total_guests / $capacity) * 100 : 0;
        
        // 部屋の状態を決定
        $room_status = 'available'; // 空室
        if ($total_guests > 0) {
            $room_status = 'occupied'; // 利用中
            if ($total_guests > $capacity) {
                $room_status = 'overbooked'; // 定員オーバー
            }
        }
        
        $grid[] = [
            'room' => $room,
            'reservations' => array_values($room_reservations),
            'total_guests' => $total_guests,
            'capacity' => $capacity,
            'occupancy_rate' => round($occupancy_rate, 1),
            'room_status' => $room_status,
            'can_assign' => $total_guests < $capacity || $room_status === 'available'
        ];
    }
    
    return $grid;
}

/**
 * 部屋グループ一覧取得
 */
function getRoomGroups($pdo) {
    $sql = "SELECT id, name FROM room_groups ORDER BY id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    
    return $stmt->fetchAll();
}
