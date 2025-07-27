<?php
/**
 * 予約編集API
 * PUT/POST /api/update_reservation.php
 */

require_once __DIR__ . '/../config/database.php';

// CORSヘッダーを設定
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: PUT, POST, OPTIONS');
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

    if (!in_array($_SERVER['REQUEST_METHOD'], ['PUT', 'POST'])) {
        sendErrorResponse('許可されていないメソッドです', 405);
    }

    handleUpdateReservation($pdo);

} catch (Exception $e) {
    error_log("update_reservation.php Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

/**
 * 予約更新処理
 */
function handleUpdateReservation($pdo) {
    // リクエストデータの取得
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    // 予約IDの必須チェック
    if (!isset($input['id']) || !is_numeric($input['id'])) {
        sendErrorResponse('予約IDは必須です', 400);
    }

    $reservation_id = (int)$input['id'];

    try {
        // トランザクション開始
        $pdo->beginTransaction();

        // 既存予約の取得
        $existing_reservation = getExistingReservation($pdo, $reservation_id);
        if (!$existing_reservation) {
            throw new Exception('指定された予約が見つかりません');
        }

        // キャンセル済み予約は編集不可
        if ($existing_reservation['reservation_status'] === 'cancelled') {
            throw new Exception('キャンセル済みの予約は編集できません');
        }

        // チェックイン後の予約は基本情報の変更を制限
        if ($existing_reservation['reservation_status'] === 'checked_in') {
            // チェックイン後は限定的な編集のみ許可
            $allowed_fields = ['notes', 'payment_status'];
            $update_data = array_intersect_key($input, array_flip($allowed_fields));
            
            if (empty($update_data)) {
                throw new Exception('チェックイン後は備考と支払い状況のみ編集可能です');
            }
        } else {
            // チェックイン前は全項目編集可能
            $update_data = prepareUpdateData($input, $existing_reservation);
        }

        // バリデーション実行
        $validation_errors = validateUpdateData($update_data, $existing_reservation);
        if (!empty($validation_errors)) {
            throw new Exception(implode(', ', $validation_errors));
        }

        // 顧客情報の更新または作成
        if (isset($update_data['customer_data'])) {
            $customer_id = updateOrCreateCustomer($pdo, $update_data['customer_data'], $existing_reservation['customer_id']);
            $update_data['customer_id'] = $customer_id;
            unset($update_data['customer_data']);
        }

        // マスターデータIDの変換
        if (isset($update_data['roomType'])) {
            $room_type_id = getRoomTypeId($pdo, $update_data['roomType']);
            if (!$room_type_id) {
                throw new Exception('指定された部屋タイプが見つかりません');
            }
            unset($update_data['roomType']);
        }

        if (isset($update_data['plan'])) {
            $plan_id = getPlanId($pdo, $update_data['plan']);
            if (!$plan_id) {
                throw new Exception('指定されたプランが見つかりません');
            }
            $update_data['plan_id'] = $plan_id;
            unset($update_data['plan']);
        }

        // 部屋の競合チェック（部屋IDが変更される場合または日程が変更される場合）
        if (isset($update_data['room_id']) || isset($update_data['checkin_date']) || isset($update_data['checkout_date'])) {
            $check_room_id = $update_data['room_id'] ?? $existing_reservation['room_id'];
            $check_checkin = $update_data['checkin_date'] ?? $existing_reservation['checkin_date'];
            $check_checkout = $update_data['checkout_date'] ?? $existing_reservation['checkout_date'];
            
            if ($check_room_id) {
                $conflicts = checkRoomConflicts($pdo, $check_room_id, $check_checkin, $check_checkout, $reservation_id);
                if (!empty($conflicts)) {
                    throw new Exception('指定された期間は既に他の予約が入っています');
                }
            }
        }

        // 予約情報の更新
        if (!empty($update_data)) {
            updateReservation($pdo, $reservation_id, $update_data);
        }

        // 更新後の予約情報を取得
        $updated_reservation = getReservationWithDetails($pdo, $reservation_id);

        // トランザクションコミット
        $pdo->commit();

        $response = [
            'success' => true,
            'message' => '予約が正常に更新されました',
            'reservation' => $updated_reservation
        ];

        sendJsonResponse($response);

    } catch (Exception $e) {
        // トランザクションロールバック
        $pdo->rollBack();
        sendErrorResponse($e->getMessage(), 400);
    }
}

/**
 * 既存予約取得
 */
function getExistingReservation($pdo, $reservation_id) {
    $sql = "
        SELECT 
            r.*,
            c.name as customer_name,
            c.phone as customer_phone,
            c.email as customer_email,
            p.name as plan_name,
            rooms.room_number,
            rt.name as room_type_name
        FROM reservations r
        LEFT JOIN customers c ON r.customer_id = c.id
        LEFT JOIN plans p ON r.plan_id = p.id
        LEFT JOIN rooms ON r.room_id = rooms.id
        LEFT JOIN room_types rt ON rooms.room_type_id = rt.id
        WHERE r.id = ?
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$reservation_id]);
    
    return $stmt->fetch();
}

/**
 * 更新データの準備
 */
function prepareUpdateData($input, $existing_reservation) {
    $update_data = [];
    
    // 顧客情報
    $customer_fields = ['name', 'phone', 'email', 'address'];
    $customer_data = [];
    foreach ($customer_fields as $field) {
        if (isset($input[$field])) {
            $customer_data[$field] = $input[$field];
        }
    }
    if (!empty($customer_data)) {
        $update_data['customer_data'] = $customer_data;
    }

    // 予約情報
    $reservation_fields = [
        'checkinDate' => 'checkin_date',
        'checkoutDate' => 'checkout_date',
        'adults' => 'adults',
        'children' => 'children',
        'price' => 'price',
        'notes' => 'notes',
        'payment_status' => 'payment_status',
        'reservation_status' => 'reservation_status',
        'room_id' => 'room_id'
    ];

    foreach ($reservation_fields as $input_key => $db_key) {
        if (isset($input[$input_key])) {
            $update_data[$db_key] = $input[$input_key];
        }
    }

    // 部屋タイプ・プラン（文字列で受け取り、後でIDに変換）
    if (isset($input['roomType'])) {
        $update_data['roomType'] = $input['roomType'];
    }
    if (isset($input['plan'])) {
        $update_data['plan'] = $input['plan'];
    }

    return $update_data;
}

/**
 * 更新データのバリデーション
 */
function validateUpdateData($update_data, $existing_reservation) {
    $errors = [];

    // 日付バリデーション
    if (isset($update_data['checkin_date'])) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $update_data['checkin_date'])) {
            $errors[] = 'チェックイン日の形式が正しくありません';
        }
    }

    if (isset($update_data['checkout_date'])) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $update_data['checkout_date'])) {
            $errors[] = 'チェックアウト日の形式が正しくありません';
        }
    }

    // チェックイン・チェックアウト日の妥当性
    $checkin_date = $update_data['checkin_date'] ?? $existing_reservation['checkin_date'];
    $checkout_date = $update_data['checkout_date'] ?? $existing_reservation['checkout_date'];
    
    if ($checkin_date >= $checkout_date) {
        $errors[] = 'チェックアウト日はチェックイン日より後にしてください';
    }

    // 人数バリデーション
    if (isset($update_data['adults'])) {
        if (!is_numeric($update_data['adults']) || $update_data['adults'] < 1) {
            $errors[] = '大人の人数は1名以上で入力してください';
        }
    }

    if (isset($update_data['children'])) {
        if (!is_numeric($update_data['children']) || $update_data['children'] < 0) {
            $errors[] = '子どもの人数は0名以上で入力してください';
        }
    }

    // 料金バリデーション
    if (isset($update_data['price'])) {
        if (!is_numeric($update_data['price']) || $update_data['price'] < 0) {
            $errors[] = '料金は0円以上で入力してください';
        }
    }

    // 支払い状況バリデーション
    if (isset($update_data['payment_status'])) {
        if (!in_array($update_data['payment_status'], ['unpaid', 'partial', 'paid'])) {
            $errors[] = '支払い状況の値が正しくありません';
        }
    }

    // 予約状況バリデーション
    if (isset($update_data['reservation_status'])) {
        if (!in_array($update_data['reservation_status'], ['unassigned', 'assigned', 'checked_in', 'checked_out'])) {
            $errors[] = '予約状況の値が正しくありません';
        }
    }

    return $errors;
}

/**
 * 顧客情報の更新または作成
 */
function updateOrCreateCustomer($pdo, $customer_data, $existing_customer_id) {
    // 既存顧客の更新
    if ($existing_customer_id) {
        $set_clauses = [];
        $params = [];
        
        foreach ($customer_data as $key => $value) {
            $set_clauses[] = "$key = ?";
            $params[] = $value;
        }
        
        if (!empty($set_clauses)) {
            $sql = "UPDATE customers SET " . implode(', ', $set_clauses) . " WHERE id = ?";
            $params[] = $existing_customer_id;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }
        
        return $existing_customer_id;
    }
    
    // 新規顧客作成（通常は既存顧客の更新のみ）
    return $existing_customer_id;
}

/**
 * 予約情報の更新
 */
function updateReservation($pdo, $reservation_id, $update_data) {
    $set_clauses = [];
    $params = [];
    
    foreach ($update_data as $key => $value) {
        $set_clauses[] = "$key = ?";
        $params[] = $value;
    }
    
    if (empty($set_clauses)) {
        return;
    }
    
    $sql = "UPDATE reservations SET " . implode(', ', $set_clauses) . ", updated_at = CURRENT_TIMESTAMP WHERE id = ?";
    $params[] = $reservation_id;
    
    $stmt = $pdo->prepare($sql);
    $success = $stmt->execute($params);
    
    if (!$success) {
        throw new Exception('予約の更新に失敗しました');
    }
}

/**
 * 部屋タイプIDの取得
 */
function getRoomTypeId($pdo, $room_type_name) {
    $sql = "SELECT id FROM room_types WHERE name = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$room_type_name]);
    $result = $stmt->fetch();
    
    return $result ? $result['id'] : null;
}

/**
 * プランIDの取得
 */
function getPlanId($pdo, $plan_name) {
    $sql = "SELECT id FROM plans WHERE name = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$plan_name]);
    $result = $stmt->fetch();
    
    return $result ? $result['id'] : null;
}

/**
 * 部屋の競合チェック（assign_reservation.phpから再利用）
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
 * 詳細な予約情報取得
 */
function getReservationWithDetails($pdo, $reservation_id) {
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
            r.created_at,
            r.updated_at,
            c.name as customer_name,
            c.phone as customer_phone,
            c.email as customer_email,
            c.address as customer_address,
            p.name as plan_name,
            rooms.room_number,
            rt.name as room_type_name,
            rg.name as group_name
        FROM reservations r
        LEFT JOIN customers c ON r.customer_id = c.id
        LEFT JOIN plans p ON r.plan_id = p.id
        LEFT JOIN rooms ON r.room_id = rooms.id
        LEFT JOIN room_types rt ON rooms.room_type_id = rt.id
        LEFT JOIN room_groups rg ON rooms.group_id = rg.id
        WHERE r.id = ?
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$reservation_id]);
    
    return $stmt->fetch();
}