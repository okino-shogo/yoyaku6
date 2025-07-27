<?php
/**
 * 予約登録API
 * POST /api/create_reservation.php
 */

require_once __DIR__ . '/../config/database.php';

// CORSヘッダーを設定
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// POSTリクエストのみ受け付け
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendErrorResponse('許可されていないメソッドです', 405);
}

try {
    $pdo = getDatabase();
    if (!$pdo) {
        sendErrorResponse('データベース接続エラーです', 500);
    }

    // リクエストデータの取得
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    // 必須項目のバリデーション
    $required_fields = ['name', 'phone', 'checkinDate', 'checkoutDate', 'adults', 'roomType', 'plan'];
    $validation_errors = validateRequired($input, $required_fields);
    
    if (!empty($validation_errors)) {
        sendErrorResponse(implode(', ', $validation_errors), 400);
    }

    // 日付バリデーション
    if (!validateDate($input['checkinDate']) || !validateDate($input['checkoutDate'])) {
        sendErrorResponse('日付の形式が正しくありません', 400);
    }

    // チェックイン・チェックアウト日の妥当性チェック
    if ($input['checkinDate'] >= $input['checkoutDate']) {
        sendErrorResponse('チェックアウト日はチェックイン日より後にしてください', 400);
    }

    // 人数バリデーション
    if ($input['adults'] < 1) {
        sendErrorResponse('大人の人数は1名以上にしてください', 400);
    }

    $pdo->beginTransaction();

    // 1. プランIDの取得
    $stmt = $pdo->prepare("SELECT id, price FROM plans WHERE name = :plan");
    $stmt->bindValue(':plan', $input['plan']);
    $stmt->execute();
    $plan = $stmt->fetch();
    
    if (!$plan) {
        $pdo->rollBack();
        sendErrorResponse('指定されたプランが見つかりません', 400);
    }

    // 2. 部屋タイプIDの取得
    $stmt = $pdo->prepare("SELECT id FROM room_types WHERE name = :roomType");
    $stmt->bindValue(':roomType', $input['roomType']);
    $stmt->execute();
    $roomType = $stmt->fetch();
    
    if (!$roomType) {
        $pdo->rollBack();
        sendErrorResponse('指定された部屋タイプが見つかりません', 400);
    }

    // 3. 顧客の登録または検索
    $customer_id = null;
    
    // 既存顧客の検索（電話番号またはメールアドレス）
    $stmt = $pdo->prepare("SELECT id FROM customers WHERE phone = :phone OR (email IS NOT NULL AND email = :email)");
    $stmt->bindValue(':phone', $input['phone']);
    $stmt->bindValue(':email', $input['email'] ?? '');
    $stmt->execute();
    $existing_customer = $stmt->fetch();
    
    if ($existing_customer) {
        $customer_id = $existing_customer['id'];
        
        // 既存顧客情報の更新
        $stmt = $pdo->prepare("
            UPDATE customers 
            SET name = :name, kana = :kana, phone = :phone, email = :email, address = :address 
            WHERE id = :id
        ");
        $stmt->bindValue(':name', $input['name']);
        $stmt->bindValue(':kana', $input['kana'] ?? '');
        $stmt->bindValue(':phone', $input['phone']);
        $stmt->bindValue(':email', $input['email'] ?? '');
        $stmt->bindValue(':address', $input['address'] ?? '');
        $stmt->bindValue(':id', $customer_id);
        $stmt->execute();
    } else {
        // 新規顧客の登録
        $stmt = $pdo->prepare("
            INSERT INTO customers (name, kana, phone, email, address) 
            VALUES (:name, :kana, :phone, :email, :address)
        ");
        $stmt->bindValue(':name', $input['name']);
        $stmt->bindValue(':kana', $input['kana'] ?? '');
        $stmt->bindValue(':phone', $input['phone']);
        $stmt->bindValue(':email', $input['email'] ?? '');
        $stmt->bindValue(':address', $input['address'] ?? '');
        $stmt->execute();
        $customer_id = $pdo->lastInsertId();
    }

    // 4. 料金計算
    $total_guests = $input['adults'] + ($input['children'] ?? 0);
    $price = $plan['price'] * $total_guests;

    // 5. 予約の登録
    $stmt = $pdo->prepare("
        INSERT INTO reservations 
        (customer_id, plan_id, checkin_date, checkout_date, adults, children, price, source, notes) 
        VALUES (:customer_id, :plan_id, :checkin_date, :checkout_date, :adults, :children, :price, :source, :notes)
    ");
    
    $stmt->bindValue(':customer_id', $customer_id);
    $stmt->bindValue(':plan_id', $plan['id']);
    $stmt->bindValue(':checkin_date', $input['checkinDate']);
    $stmt->bindValue(':checkout_date', $input['checkoutDate']);
    $stmt->bindValue(':adults', $input['adults']);
    $stmt->bindValue(':children', $input['children'] ?? 0);
    $stmt->bindValue(':price', $price);
    $stmt->bindValue(':source', $input['source'] ?? 'web');
    $stmt->bindValue(':notes', $input['notes'] ?? '');
    
    $stmt->execute();
    $reservation_id = $pdo->lastInsertId();

    $pdo->commit();

    sendJsonResponse([
        'success' => true,
        'reservationId' => $reservation_id,
        'price' => $price
    ], 201);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("create_reservation.php Error: " . $e->getMessage());
    sendErrorResponse('予約登録中にエラーが発生しました', 500);
}