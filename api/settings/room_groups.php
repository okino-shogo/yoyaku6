<?php
/**
 * 部屋グループ管理API
 * 設計書 4.3.1 に基づく実装
 * 
 * GET /api/settings/room_groups.php - 部屋グループ一覧取得
 * POST /api/settings/room_groups.php - 部屋グループ追加
 * PUT /api/settings/room_groups.php - 部屋グループ更新
 * DELETE /api/settings/room_groups.php - 部屋グループ削除
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
            handleGetRoomGroups($pdo);
            break;
        case 'POST':
            handleCreateRoomGroup($pdo);
            break;
        case 'PUT':
            handleUpdateRoomGroup($pdo);
            break;
        case 'DELETE':
            handleDeleteRoomGroup($pdo);
            break;
        default:
            sendErrorResponse('許可されていないメソッドです', 405);
    }

} catch (Exception $e) {
    error_log("room_groups.php Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

/**
 * 部屋グループ一覧取得
 */
function handleGetRoomGroups($pdo) {
    $stmt = $pdo->prepare("
        SELECT 
            id, 
            name, 
            created_at
        FROM room_groups 
        ORDER BY id ASC
    ");
    
    $stmt->execute();
    $room_groups = $stmt->fetchAll();
    
    // データの整形
    $formatted_room_groups = array_map(function($group) {
        return [
            'id' => (int)$group['id'],
            'name' => $group['name'],
            'created_at' => $group['created_at']
        ];
    }, $room_groups);
    
    sendJsonResponse($formatted_room_groups);
}

/**
 * 部屋グループ追加
 */
function handleCreateRoomGroup($pdo) {
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
    $stmt = $pdo->prepare("SELECT id FROM room_groups WHERE name = :name");
    $stmt->bindValue(':name', $input['name']);
    $stmt->execute();
    if ($stmt->fetch()) {
        sendErrorResponse('この部屋グループ名は既に使用されています', 400);
    }

    // 名前の長さチェック（50文字以内）
    if (strlen($input['name']) > 50) {
        sendErrorResponse('部屋グループ名は50文字以内にしてください', 400);
    }

    // 部屋グループ追加
    $stmt = $pdo->prepare("
        INSERT INTO room_groups (name) 
        VALUES (:name)
    ");
    
    $stmt->bindValue(':name', $input['name']);
    $stmt->execute();
    $new_id = $pdo->lastInsertId();
    
    sendJsonResponse(['success' => true, 'id' => (int)$new_id], 201);
}

/**
 * 部屋グループ更新
 */
function handleUpdateRoomGroup($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        sendErrorResponse('有効なJSONデータが必要です', 400);
    }

    if (!isset($input['id'])) {
        sendErrorResponse('IDが必要です', 400);
    }

    // 部屋グループの存在チェック
    $stmt = $pdo->prepare("SELECT id FROM room_groups WHERE id = :id");
    $stmt->bindValue(':id', $input['id']);
    $stmt->execute();
    if (!$stmt->fetch()) {
        sendErrorResponse('指定された部屋グループが存在しません', 404);
    }

    // 名前の重複チェック（自分以外）
    if (isset($input['name'])) {
        $stmt = $pdo->prepare("SELECT id FROM room_groups WHERE name = :name AND id != :id");
        $stmt->bindValue(':name', $input['name']);
        $stmt->bindValue(':id', $input['id']);
        $stmt->execute();
        if ($stmt->fetch()) {
            sendErrorResponse('この部屋グループ名は既に使用されています', 400);
        }
    }

    // バリデーション
    if (isset($input['name']) && strlen($input['name']) > 50) {
        sendErrorResponse('部屋グループ名は50文字以内にしてください', 400);
    }

    // 名前が指定されていない場合はエラー
    if (!isset($input['name'])) {
        sendErrorResponse('更新するデータがありません', 400);
    }

    // 部屋グループ更新
    $stmt = $pdo->prepare("UPDATE room_groups SET name = :name WHERE id = :id");
    $stmt->bindValue(':name', $input['name']);
    $stmt->bindValue(':id', $input['id']);
    $stmt->execute();
    
    sendJsonResponse(['success' => true]);
}

/**
 * 部屋グループ削除
 */
function handleDeleteRoomGroup($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        sendErrorResponse('有効なJSONデータが必要です', 400);
    }

    if (!isset($input['id'])) {
        sendErrorResponse('IDが必要です', 400);
    }

    // 部屋グループの存在チェック
    $stmt = $pdo->prepare("SELECT id FROM room_groups WHERE id = :id");
    $stmt->bindValue(':id', $input['id']);
    $stmt->execute();
    if (!$stmt->fetch()) {
        sendErrorResponse('指定された部屋グループが存在しません', 404);
    }

    // 部屋の存在チェック（削除制限）
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM rooms WHERE group_id = :group_id");
    $stmt->bindValue(':group_id', $input['id']);
    $stmt->execute();
    $result = $stmt->fetch();
    
    if ($result['count'] > 0) {
        sendErrorResponse('この部屋グループを使用している部屋が存在するため削除できません', 400);
    }

    // 部屋グループ削除
    $stmt = $pdo->prepare("DELETE FROM room_groups WHERE id = :id");
    $stmt->bindValue(':id', $input['id']);
    $stmt->execute();
    
    sendJsonResponse(['success' => true]);
}
