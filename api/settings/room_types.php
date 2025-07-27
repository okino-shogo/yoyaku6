<?php
/**
 * 部屋タイプ管理API
 * 設計書 4.3.1 に基づく実装
 * 
 * GET /api/settings/room_types.php - 部屋タイプ一覧取得
 * POST /api/settings/room_types.php - 部屋タイプ追加
 * PUT /api/settings/room_types.php - 部屋タイプ更新
 * DELETE /api/settings/room_types.php - 部屋タイプ削除
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
            handleGetRoomTypes($pdo);
            break;
        case 'POST':
            handleCreateRoomType($pdo);
            break;
        case 'PUT':
            handleUpdateRoomType($pdo);
            break;
        case 'DELETE':
            handleDeleteRoomType($pdo);
            break;
        default:
            sendErrorResponse('許可されていないメソッドです', 405);
    }

} catch (Exception $e) {
    error_log("room_types.php Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

/**
 * 部屋タイプ一覧取得
 */
function handleGetRoomTypes($pdo) {
    $stmt = $pdo->prepare("
        SELECT 
            id, 
            name, 
            description, 
            default_capacity_adults, 
            default_capacity_children,
            created_at
        FROM room_types 
        ORDER BY id ASC
    ");
    
    $stmt->execute();
    $room_types = $stmt->fetchAll();
    
    // データの整形
    $formatted_room_types = array_map(function($type) {
        return [
            'id' => (int)$type['id'],
            'name' => $type['name'],
            'description' => $type['description'],
            'default_capacity_adults' => (int)$type['default_capacity_adults'],
            'default_capacity_children' => (int)$type['default_capacity_children'],
            'created_at' => $type['created_at']
        ];
    }, $room_types);
    
    sendJsonResponse($formatted_room_types);
}

/**
 * 部屋タイプ追加
 */
function handleCreateRoomType($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        sendErrorResponse('有効なJSONデータが必要です', 400);
    }

    // バリデーション
    $required_fields = ['name'];
    $validation_errors = validateRequired($input, $required_fields);
    
    if (!empty($validation_errors)) {
        sendErrorResponse(implode(', ', $validation_errors), 400);
    }

    // 名前の重複チェック
    $stmt = $pdo->prepare("SELECT id FROM room_types WHERE name = :name");
    $stmt->bindValue(':name', $input['name']);
    $stmt->execute();
    if ($stmt->fetch()) {
        sendErrorResponse('この部屋タイプ名は既に使用されています', 400);
    }

    // 名前の長さチェック（50文字以内）
    if (strlen($input['name']) > 50) {
        sendErrorResponse('部屋タイプ名は50文字以内にしてください', 400);
    }

    // デフォルト定員のバリデーション
    $default_adults = $input['default_capacity_adults'] ?? 2;
    $default_children = $input['default_capacity_children'] ?? 0;
    
    if ($default_adults < 1) {
        sendErrorResponse('デフォルト大人定員は1名以上にしてください', 400);
    }
    
    if ($default_children < 0) {
        sendErrorResponse('デフォルト子ども定員は0名以上にしてください', 400);
    }

    // 部屋タイプ追加
    $stmt = $pdo->prepare("
        INSERT INTO room_types 
        (name, description, default_capacity_adults, default_capacity_children) 
        VALUES (:name, :description, :default_capacity_adults, :default_capacity_children)
    ");
    
    $stmt->bindValue(':name', $input['name']);
    $stmt->bindValue(':description', $input['description'] ?? '');
    $stmt->bindValue(':default_capacity_adults', $default_adults);
    $stmt->bindValue(':default_capacity_children', $default_children);
    
    $stmt->execute();
    $new_id = $pdo->lastInsertId();
    
    sendJsonResponse(['success' => true, 'id' => (int)$new_id], 201);
}

/**
 * 部屋タイプ更新
 */
function handleUpdateRoomType($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        sendErrorResponse('有効なJSONデータが必要です', 400);
    }

    if (!isset($input['id'])) {
        sendErrorResponse('IDが必要です', 400);
    }

    // 部屋タイプの存在チェック
    $stmt = $pdo->prepare("SELECT id FROM room_types WHERE id = :id");
    $stmt->bindValue(':id', $input['id']);
    $stmt->execute();
    if (!$stmt->fetch()) {
        sendErrorResponse('指定された部屋タイプが存在しません', 404);
    }

    // 名前の重複チェック（自分以外）
    if (isset($input['name'])) {
        $stmt = $pdo->prepare("SELECT id FROM room_types WHERE name = :name AND id != :id");
        $stmt->bindValue(':name', $input['name']);
        $stmt->bindValue(':id', $input['id']);
        $stmt->execute();
        if ($stmt->fetch()) {
            sendErrorResponse('この部屋タイプ名は既に使用されています', 400);
        }
    }

    // バリデーション
    if (isset($input['name']) && strlen($input['name']) > 50) {
        sendErrorResponse('部屋タイプ名は50文字以内にしてください', 400);
    }

    if (isset($input['default_capacity_adults']) && $input['default_capacity_adults'] < 1) {
        sendErrorResponse('デフォルト大人定員は1名以上にしてください', 400);
    }

    if (isset($input['default_capacity_children']) && $input['default_capacity_children'] < 0) {
        sendErrorResponse('デフォルト子ども定員は0名以上にしてください', 400);
    }

    // 更新するフィールドを動的に構築
    $update_fields = [];
    $params = [':id' => $input['id']];
    
    $allowed_fields = ['name', 'description', 'default_capacity_adults', 'default_capacity_children'];
    
    foreach ($allowed_fields as $field) {
        if (isset($input[$field])) {
            $update_fields[] = "$field = :$field";
            $params[":$field"] = $input[$field];
        }
    }

    if (empty($update_fields)) {
        sendErrorResponse('更新するデータがありません', 400);
    }

    $sql = "UPDATE room_types SET " . implode(', ', $update_fields) . " WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->execute();
    
    sendJsonResponse(['success' => true]);
}

/**
 * 部屋タイプ削除
 */
function handleDeleteRoomType($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        sendErrorResponse('有効なJSONデータが必要です', 400);
    }

    if (!isset($input['id'])) {
        sendErrorResponse('IDが必要です', 400);
    }

    // 部屋タイプの存在チェック
    $stmt = $pdo->prepare("SELECT id FROM room_types WHERE id = :id");
    $stmt->bindValue(':id', $input['id']);
    $stmt->execute();
    if (!$stmt->fetch()) {
        sendErrorResponse('指定された部屋タイプが存在しません', 404);
    }

    // 部屋の存在チェック（削除制限）
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM rooms WHERE room_type_id = :room_type_id");
    $stmt->bindValue(':room_type_id', $input['id']);
    $stmt->execute();
    $result = $stmt->fetch();
    
    if ($result['count'] > 0) {
        sendErrorResponse('この部屋タイプを使用している部屋が存在するため削除できません', 400);
    }

    // 部屋タイプ削除
    $stmt = $pdo->prepare("DELETE FROM room_types WHERE id = :id");
    $stmt->bindValue(':id', $input['id']);
    $stmt->execute();
    
    sendJsonResponse(['success' => true]);
}
