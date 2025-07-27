<?php
/**
 * 部屋管理API
 * 設計書 4.3.1 に基づく実装
 * 
 * GET /api/settings/rooms.php - 部屋一覧取得
 * POST /api/settings/rooms.php - 部屋追加
 * PUT /api/settings/rooms.php - 部屋更新
 * DELETE /api/settings/rooms.php - 部屋削除
 */

require_once __DIR__ . '/../../config/database.php';

// CORSヘッダーを設定
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
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

    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            handleGetRooms($pdo);
            break;
        case 'POST':
            handleCreateRoom($pdo);
            break;
        case 'PUT':
            handleUpdateRoom($pdo);
            break;
        case 'DELETE':
            handleDeleteRoom($pdo);
            break;
        default:
            sendErrorResponse('許可されていないメソッドです', 405);
    }

} catch (Exception $e) {
    error_log("rooms.php Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

/**
 * 部屋一覧取得
 */
function handleGetRooms($pdo) {
    // フィルター・検索パラメータの取得
    $group_id = isset($_GET['group_id']) ? (int)$_GET['group_id'] : null;
    $room_type_id = isset($_GET['room_type_id']) ? (int)$_GET['room_type_id'] : null;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    
    $sql = "
        SELECT 
            r.id,
            r.room_number,
            r.room_type_id,
            r.group_id,
            r.capacity_adults,
            r.capacity_children,
            r.display_order,
            r.notes,
            r.created_at,
            r.updated_at,
            rt.name as room_type_name,
            rg.name as group_name
        FROM rooms r
        LEFT JOIN room_types rt ON r.room_type_id = rt.id
        LEFT JOIN room_groups rg ON r.group_id = rg.id
        WHERE 1=1
    ";
    
    $params = [];
    
    if ($group_id) {
        $sql .= " AND r.group_id = :group_id";
        $params[':group_id'] = $group_id;
    }
    
    if ($room_type_id) {
        $sql .= " AND r.room_type_id = :room_type_id";
        $params[':room_type_id'] = $room_type_id;
    }
    
    if ($search) {
        $sql .= " AND r.room_number LIKE :search";
        $params[':search'] = '%' . $search . '%';
    }
    
    $sql .= " ORDER BY r.display_order ASC, r.room_number ASC";
    
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    
    $rooms = $stmt->fetchAll();
    
    // データの整形
    $formatted_rooms = array_map(function($room) {
        return [
            'id' => (int)$room['id'],
            'room_number' => $room['room_number'],
            'room_type_id' => (int)$room['room_type_id'],
            'group_id' => $room['group_id'] ? (int)$room['group_id'] : null,
            'capacity_adults' => (int)$room['capacity_adults'],
            'capacity_children' => (int)$room['capacity_children'],
            'display_order' => (int)$room['display_order'],
            'notes' => $room['notes'],
            'created_at' => $room['created_at'],
            'updated_at' => $room['updated_at'],
            'room_type_name' => $room['room_type_name'],
            'group_name' => $room['group_name']
        ];
    }, $rooms);
    
    sendJsonResponse($formatted_rooms);
}

/**
 * 部屋追加
 */
function handleCreateRoom($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        sendErrorResponse('有効なJSONデータが必要です', 400);
    }

    // バリデーション（設計書 4.7 部屋管理）
    $required_fields = ['room_number', 'room_type_id', 'capacity_adults'];
    $validation_errors = validateRequired($input, $required_fields);
    
    if (!empty($validation_errors)) {
        sendErrorResponse(implode(', ', $validation_errors), 400);
    }

    // 部屋番号の重複チェック
    $stmt = $pdo->prepare("SELECT id FROM rooms WHERE room_number = :room_number");
    $stmt->bindValue(':room_number', $input['room_number']);
    $stmt->execute();
    if ($stmt->fetch()) {
        sendErrorResponse('この部屋番号は既に使用されています', 400);
    }

    // 部屋番号の長さチェック（10文字以内）
    if (strlen($input['room_number']) > 10) {
        sendErrorResponse('部屋番号は10文字以内にしてください', 400);
    }

    // 定員のバリデーション（大人1名以上、合計20名以下）
    if ($input['capacity_adults'] < 1) {
        sendErrorResponse('大人の定員は1名以上にしてください', 400);
    }
    
    $total_capacity = $input['capacity_adults'] + ($input['capacity_children'] ?? 0);
    if ($total_capacity > 20) {
        sendErrorResponse('定員の合計は20名以下にしてください', 400);
    }

    // 部屋タイプ・グループの存在チェック
    if ($input['room_type_id']) {
        $stmt = $pdo->prepare("SELECT id FROM room_types WHERE id = :id");
        $stmt->bindValue(':id', $input['room_type_id']);
        $stmt->execute();
        if (!$stmt->fetch()) {
            sendErrorResponse('指定された部屋タイプが存在しません', 400);
        }
    }

    if (!empty($input['group_id'])) {
        $stmt = $pdo->prepare("SELECT id FROM room_groups WHERE id = :id");
        $stmt->bindValue(':id', $input['group_id']);
        $stmt->execute();
        if (!$stmt->fetch()) {
            sendErrorResponse('指定された部屋グループが存在しません', 400);
        }
    }

    // 部屋追加
    $stmt = $pdo->prepare("
        INSERT INTO rooms 
        (room_number, room_type_id, group_id, capacity_adults, capacity_children, display_order, notes) 
        VALUES (:room_number, :room_type_id, :group_id, :capacity_adults, :capacity_children, :display_order, :notes)
    ");
    
    $stmt->bindValue(':room_number', $input['room_number']);
    $stmt->bindValue(':room_type_id', $input['room_type_id']);
    $stmt->bindValue(':group_id', !empty($input['group_id']) ? $input['group_id'] : null);
    $stmt->bindValue(':capacity_adults', $input['capacity_adults']);
    $stmt->bindValue(':capacity_children', $input['capacity_children'] ?? 0);
    $stmt->bindValue(':display_order', $input['display_order'] ?? 0);
    $stmt->bindValue(':notes', $input['notes'] ?? '');
    
    $stmt->execute();
    $new_id = $pdo->lastInsertId();
    
    sendJsonResponse(['success' => true, 'id' => (int)$new_id], 201);
}

/**
 * 部屋更新
 */
function handleUpdateRoom($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        sendErrorResponse('有効なJSONデータが必要です', 400);
    }

    if (!isset($input['id'])) {
        sendErrorResponse('IDが必要です', 400);
    }

    // 部屋の存在チェック
    $stmt = $pdo->prepare("SELECT id FROM rooms WHERE id = :id");
    $stmt->bindValue(':id', $input['id']);
    $stmt->execute();
    if (!$stmt->fetch()) {
        sendErrorResponse('指定された部屋が存在しません', 404);
    }

    // 部屋番号の重複チェック（自分以外）
    if (isset($input['room_number'])) {
        $stmt = $pdo->prepare("SELECT id FROM rooms WHERE room_number = :room_number AND id != :id");
        $stmt->bindValue(':room_number', $input['room_number']);
        $stmt->bindValue(':id', $input['id']);
        $stmt->execute();
        if ($stmt->fetch()) {
            sendErrorResponse('この部屋番号は既に使用されています', 400);
        }
    }

    // バリデーション
    if (isset($input['room_number']) && strlen($input['room_number']) > 10) {
        sendErrorResponse('部屋番号は10文字以内にしてください', 400);
    }

    if (isset($input['capacity_adults']) && $input['capacity_adults'] < 1) {
        sendErrorResponse('大人の定員は1名以上にしてください', 400);
    }

    // 更新するフィールドを動的に構築
    $update_fields = [];
    $params = [':id' => $input['id']];
    
    $allowed_fields = ['room_number', 'room_type_id', 'group_id', 'capacity_adults', 'capacity_children', 'display_order', 'notes'];
    
    foreach ($allowed_fields as $field) {
        if (isset($input[$field])) {
            $update_fields[] = "$field = :$field";
            $params[":$field"] = $input[$field];
        }
    }

    if (empty($update_fields)) {
        sendErrorResponse('更新するデータがありません', 400);
    }

    $sql = "UPDATE rooms SET " . implode(', ', $update_fields) . " WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->execute();
    
    sendJsonResponse(['success' => true]);
}

/**
 * 部屋削除
 */
function handleDeleteRoom($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        sendErrorResponse('有効なJSONデータが必要です', 400);
    }

    if (!isset($input['id'])) {
        sendErrorResponse('IDが必要です', 400);
    }

    // 部屋の存在チェック
    $stmt = $pdo->prepare("SELECT id FROM rooms WHERE id = :id");
    $stmt->bindValue(':id', $input['id']);
    $stmt->execute();
    if (!$stmt->fetch()) {
        sendErrorResponse('指定された部屋が存在しません', 404);
    }

    // 予約の存在チェック（削除制限）
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM reservations WHERE room_id = :room_id");
    $stmt->bindValue(':room_id', $input['id']);
    $stmt->execute();
    $result = $stmt->fetch();
    
    if ($result['count'] > 0) {
        sendErrorResponse('この部屋には予約が存在するため削除できません', 400);
    }

    // 部屋削除
    $stmt = $pdo->prepare("DELETE FROM rooms WHERE id = :id");
    $stmt->bindValue(':id', $input['id']);
    $stmt->execute();
    
    sendJsonResponse(['success' => true]);
}
