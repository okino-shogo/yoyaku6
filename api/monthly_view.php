<?php
/**
 * 月間ビューAPI
 * 設計書 2.2.1 に基づく実装
 * 
 * GET /api/monthly_view.php - 月間ビューデータ取得
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

    handleGetMonthlyView($pdo);

} catch (Exception $e) {
    error_log("monthly_view.php Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

/**
 * 月間ビューデータ取得
 */
function handleGetMonthlyView($pdo) {
    // パラメータの取得
    $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
    $month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
    $group_id = isset($_GET['group_id']) ? (int)$_GET['group_id'] : null;
    $keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
    $view_type = isset($_GET['view_type']) ? $_GET['view_type'] : 'room'; // room, type, daily

    // 月の妥当性チェック
    if ($month < 1 || $month > 12) {
        sendErrorResponse('無効な月が指定されました', 400);
    }

    // 年月の範囲を計算
    $start_date = sprintf('%04d-%02d-01', $year, $month);
    $end_date = date('Y-m-t', strtotime($start_date)); // 月末日

    try {
        // 部屋一覧を取得
        $rooms = getRooms($pdo, $group_id);
        
        // 予約データを取得
        $reservations = getReservations($pdo, $start_date, $end_date, $keyword);
        
        // カレンダーデータを構築
        $calendar_data = buildCalendarData($year, $month, $rooms, $reservations);
        
        // 未割当予約を取得
        $unassigned_reservations = getUnassignedReservations($pdo, $start_date, $end_date, $keyword);

        $response = [
            'year' => $year,
            'month' => $month,
            'view_type' => $view_type,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'rooms' => $rooms,
            'calendar_data' => $calendar_data,
            'unassigned_reservations' => $unassigned_reservations,
            'total_days' => (int)date('t', strtotime($start_date))
        ];

        sendJsonResponse($response);
    } catch (Exception $e) {
        error_log("handleGetMonthlyView Error: " . $e->getMessage());
        sendErrorResponse('データ取得エラー: ' . $e->getMessage(), 500);
    }
}

/**
 * 部屋一覧取得
 */
function getRooms($pdo, $group_id = null) {
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
 * 予約データ取得
 */
function getReservations($pdo, $start_date, $end_date, $keyword = '') {
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
        WHERE (
            (r.checkin_date <= ? AND r.checkout_date > ?)
            OR (r.checkin_date >= ? AND r.checkin_date <= ?)
        )
        AND r.reservation_status != 'cancelled'
    ";
    
    $params = [$end_date, $start_date, $start_date, $end_date];
    
    if ($keyword) {
        $sql .= " AND (c.name LIKE ? OR p.name LIKE ? OR rooms.room_number LIKE ?)";
        $keyword_param = '%' . $keyword . '%';
        $params[] = $keyword_param;
        $params[] = $keyword_param;
        $params[] = $keyword_param;
    }
    
    $sql .= " ORDER BY r.checkin_date ASC, r.id ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    $reservations = $stmt->fetchAll();
    
    // データの整形
    return array_map(function($reservation) {
        return [
            'id' => (int)$reservation['id'],
            'customer_id' => (int)$reservation['customer_id'],
            'plan_id' => (int)$reservation['plan_id'],
            'room_id' => $reservation['room_id'] ? (int)$reservation['room_id'] : null,
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
            'room_type_name' => $reservation['room_type_name']
        ];
    }, $reservations);
}

/**
 * 未割当予約取得
 */
function getUnassignedReservations($pdo, $start_date, $end_date, $keyword = '') {
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
        AND (
            (r.checkin_date <= ? AND r.checkout_date > ?)
            OR (r.checkin_date >= ? AND r.checkin_date <= ?)
        )
        AND r.reservation_status != 'cancelled'
    ";
    
    $params = [$end_date, $start_date, $start_date, $end_date];
    
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
    
    // データの整形
    return array_map(function($reservation) {
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
            'plan_name' => $reservation['plan_name']
        ];
    }, $reservations);
}

/**
 * カレンダーデータ構築
 */
function buildCalendarData($year, $month, $rooms, $reservations) {
    $total_days = (int)date('t', strtotime("$year-$month-01"));
    $calendar = [];
    
    // 各部屋ごとにカレンダーデータを構築
    foreach ($rooms as $room) {
        $room_calendar = [];
        
        for ($day = 1; $day <= $total_days; $day++) {
            $current_date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $day_reservations = [];
            
            // この日にこの部屋で有効な予約を探す
            foreach ($reservations as $reservation) {
                if ($reservation['room_id'] == $room['id']) {
                    // チェックイン日以降、チェックアウト日以下の期間（チェックアウト日も含む）
                    if ($current_date >= $reservation['checkin_date'] && 
                        $current_date <= $reservation['checkout_date']) {
                        $day_reservations[] = $reservation;
                    }
                }
            }
            
            $room_calendar[$day] = [
                'date' => $current_date,
                'day' => $day,
                'reservations' => $day_reservations
            ];
        }
        
        $calendar[$room['id']] = [
            'room' => $room,
            'days' => $room_calendar
        ];
    }
    
    return $calendar;
}
