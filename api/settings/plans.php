<?php
/**
 * プラン管理API
 * 設計書 4.3.2 に基づく実装
 * 
 * GET /api/settings/plans.php - プラン一覧取得
 * POST /api/settings/plans.php - プラン追加
 * PUT /api/settings/plans.php - プラン更新
 * DELETE /api/settings/plans.php - プラン削除（論理削除）
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
            handleGetPlans($pdo);
            break;
        case 'POST':
            handleCreatePlan($pdo);
            break;
        case 'PUT':
            handleUpdatePlan($pdo);
            break;
        case 'DELETE':
            handleDeletePlan($pdo);
            break;
        default:
            sendErrorResponse('許可されていないメソッドです', 405);
    }

} catch (Exception $e) {
    error_log("plans.php Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

/**
 * プラン一覧取得
 */
function handleGetPlans($pdo) {
    // フィルター・検索パラメータの取得
    $is_active = isset($_GET['is_active']) ? $_GET['is_active'] : '';
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    
    $sql = "
        SELECT 
            id,
            name,
            description,
            price,
            is_active,
            display_order,
            created_at
        FROM plans
        WHERE 1=1
    ";
    
    $params = [];
    
    if ($is_active !== '') {
        $sql .= " AND is_active = :is_active";
        $params[':is_active'] = (int)$is_active;
    }
    
    if ($search) {
        $sql .= " AND name LIKE :search";
        $params[':search'] = '%' . $search . '%';
    }
    
    $sql .= " ORDER BY display_order ASC, id ASC";
    
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    
    $plans = $stmt->fetchAll();
    
    // データの整形
    $formatted_plans = array_map(function($plan) {
        return [
            'id' => (int)$plan['id'],
            'name' => $plan['name'],
            'description' => $plan['description'],
            'price' => (float)$plan['price'],
            'is_active' => (bool)$plan['is_active'],
            'display_order' => (int)$plan['display_order'],
            'created_at' => $plan['created_at']
        ];
    }, $plans);
    
    sendJsonResponse($formatted_plans);
}

/**
 * プラン追加
 */
function handleCreatePlan($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        sendErrorResponse('有効なJSONデータが必要です', 400);
    }

    // バリデーション（設計書 4.7 プラン管理）
    $required_fields = ['name', 'price'];
    $validation_errors = validateRequired($input, $required_fields);
    
    if (!empty($validation_errors)) {
        sendErrorResponse(implode(', ', $validation_errors), 400);
    }

    // プラン名の重複チェック
    $stmt = $pdo->prepare("SELECT id FROM plans WHERE name = :name");
    $stmt->bindValue(':name', $input['name']);
    $stmt->execute();
    if ($stmt->fetch()) {
        sendErrorResponse('このプラン名は既に使用されています', 400);
    }

    // プラン名の長さチェック（100文字以内）
    if (strlen($input['name']) > 100) {
        sendErrorResponse('プラン名は100文字以内にしてください', 400);
    }

    // 料金のバリデーション（0円以上100万円以下）
    $price = (float)$input['price'];
    if ($price < 0) {
        sendErrorResponse('料金は0円以上にしてください', 400);
    }
    
    if ($price > 1000000) {
        sendErrorResponse('料金は100万円以下にしてください', 400);
    }

    // 説明の長さチェック（1000文字以内）
    if (isset($input['description']) && strlen($input['description']) > 1000) {
        sendErrorResponse('説明は1000文字以内にしてください', 400);
    }

    // プラン追加
    $stmt = $pdo->prepare("
        INSERT INTO plans 
        (name, description, price, is_active, display_order) 
        VALUES (:name, :description, :price, :is_active, :display_order)
    ");
    
    $stmt->bindValue(':name', $input['name']);
    $stmt->bindValue(':description', $input['description'] ?? '');
    $stmt->bindValue(':price', $price);
    $stmt->bindValue(':is_active', isset($input['is_active']) ? (int)$input['is_active'] : 1);
    $stmt->bindValue(':display_order', $input['display_order'] ?? 0);
    
    $stmt->execute();
    $new_id = $pdo->lastInsertId();
    
    sendJsonResponse(['success' => true, 'id' => (int)$new_id], 201);
}

/**
 * プラン更新
 */
function handleUpdatePlan($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        sendErrorResponse('有効なJSONデータが必要です', 400);
    }

    if (!isset($input['id'])) {
        sendErrorResponse('IDが必要です', 400);
    }

    // プランの存在チェック
    $stmt = $pdo->prepare("SELECT id FROM plans WHERE id = :id");
    $stmt->bindValue(':id', $input['id']);
    $stmt->execute();
    if (!$stmt->fetch()) {
        sendErrorResponse('指定されたプランが存在しません', 404);
    }

    // プラン名の重複チェック（自分以外）
    if (isset($input['name'])) {
        $stmt = $pdo->prepare("SELECT id FROM plans WHERE name = :name AND id != :id");
        $stmt->bindValue(':name', $input['name']);
        $stmt->bindValue(':id', $input['id']);
        $stmt->execute();
        if ($stmt->fetch()) {
            sendErrorResponse('このプラン名は既に使用されています', 400);
        }
    }

    // バリデーション
    if (isset($input['name']) && strlen($input['name']) > 100) {
        sendErrorResponse('プラン名は100文字以内にしてください', 400);
    }

    if (isset($input['price'])) {
        $price = (float)$input['price'];
        if ($price < 0) {
            sendErrorResponse('料金は0円以上にしてください', 400);
        }
        if ($price > 1000000) {
            sendErrorResponse('料金は100万円以下にしてください', 400);
        }
    }

    if (isset($input['description']) && strlen($input['description']) > 1000) {
        sendErrorResponse('説明は1000文字以内にしてください', 400);
    }

    // 更新するフィールドを動的に構築
    $update_fields = [];
    $params = [':id' => $input['id']];
    
    $allowed_fields = ['name', 'description', 'price', 'is_active', 'display_order'];
    
    foreach ($allowed_fields as $field) {
        if (isset($input[$field])) {
            $update_fields[] = "$field = :$field";
            if ($field === 'is_active') {
                $params[":$field"] = (int)$input[$field];
            } elseif ($field === 'price') {
                $params[":$field"] = (float)$input[$field];
            } else {
                $params[":$field"] = $input[$field];
            }
        }
    }

    if (empty($update_fields)) {
        sendErrorResponse('更新するデータがありません', 400);
    }

    $sql = "UPDATE plans SET " . implode(', ', $update_fields) . " WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->execute();
    
    sendJsonResponse(['success' => true]);
}

/**
 * プラン削除（論理削除）
 */
function handleDeletePlan($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        sendErrorResponse('有効なJSONデータが必要です', 400);
    }

    if (!isset($input['id'])) {
        sendErrorResponse('IDが必要です', 400);
    }

    // プランの存在チェック
    $stmt = $pdo->prepare("SELECT id FROM plans WHERE id = :id");
    $stmt->bindValue(':id', $input['id']);
    $stmt->execute();
    if (!$stmt->fetch()) {
        sendErrorResponse('指定されたプランが存在しません', 404);
    }

    // 予約の存在チェック（削除制限）
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM reservations WHERE plan_id = :plan_id");
    $stmt->bindValue(':plan_id', $input['id']);
    $stmt->execute();
    $result = $stmt->fetch();
    
    if ($result['count'] > 0) {
        sendErrorResponse('このプランを使用している予約が存在するため削除できません', 400);
    }

    // プラン削除（物理削除）
    $stmt = $pdo->prepare("DELETE FROM plans WHERE id = :id");
    $stmt->bindValue(':id', $input['id']);
    $stmt->execute();
    
    sendJsonResponse(['success' => true]);
}
