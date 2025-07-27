<?php
/**
 * 予約キャンセルAPI
 * POST /api/cancel_reservation.php
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

    handleCancelReservation($pdo);

} catch (Exception $e) {
    error_log("cancel_reservation.php Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

/**
 * 予約キャンセル処理
 */
function handleCancelReservation($pdo) {
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
    $cancel_reason = isset($input['cancel_reason']) ? trim($input['cancel_reason']) : '';
    $refund_amount = isset($input['refund_amount']) ? (float)$input['refund_amount'] : 0;

    try {
        // トランザクション開始
        $pdo->beginTransaction();

        // 既存予約の取得
        $existing_reservation = getReservationForCancel($pdo, $reservation_id);
        if (!$existing_reservation) {
            throw new Exception('指定された予約が見つかりません');
        }

        // 既にキャンセル済みの場合
        if ($existing_reservation['reservation_status'] === 'cancelled') {
            throw new Exception('この予約は既にキャンセル済みです');
        }

        // チェックアウト済みの予約はキャンセル不可
        if ($existing_reservation['reservation_status'] === 'checked_out') {
            throw new Exception('チェックアウト済みの予約はキャンセルできません');
        }

        // キャンセル可能期間のチェック（当日以降はキャンセル制限）
        $today = date('Y-m-d');
        $checkin_date = $existing_reservation['checkin_date'];
        
        if ($checkin_date <= $today && $existing_reservation['reservation_status'] === 'checked_in') {
            // チェックイン済みの場合は特別な確認が必要
            if (!isset($input['force_cancel']) || !$input['force_cancel']) {
                throw new Exception('チェックイン済みの予約をキャンセルするには管理者権限が必要です');
            }
        }

        // 返金額のバリデーション
        if ($refund_amount < 0 || $refund_amount > $existing_reservation['price']) {
            throw new Exception('返金額が正しくありません');
        }

        // 予約のキャンセル処理
        cancelReservation($pdo, $reservation_id, $cancel_reason);

        // 部屋の割り当てを解除（room_idをNULLに設定）
        if ($existing_reservation['room_id']) {
            unassignRoomFromReservation($pdo, $reservation_id);
        }

        // 返金処理の記録（返金額が設定されている場合）
        if ($refund_amount > 0) {
            recordRefund($pdo, $reservation_id, $refund_amount, $cancel_reason);
        }

        // キャンセル後の予約情報を取得
        $cancelled_reservation = getReservationWithDetails($pdo, $reservation_id);

        // トランザクションコミット
        $pdo->commit();

        $response = [
            'success' => true,
            'message' => '予約が正常にキャンセルされました',
            'reservation' => $cancelled_reservation,
            'cancel_reason' => $cancel_reason,
            'refund_amount' => $refund_amount
        ];

        sendJsonResponse($response);

    } catch (Exception $e) {
        // トランザクションロールバック
        $pdo->rollBack();
        sendErrorResponse($e->getMessage(), 400);
    }
}

/**
 * キャンセル用予約情報取得
 */
function getReservationForCancel($pdo, $reservation_id) {
    $sql = "
        SELECT 
            r.id,
            r.customer_id,
            r.room_id,
            r.checkin_date,
            r.checkout_date,
            r.price,
            r.payment_status,
            r.reservation_status,
            c.name as customer_name,
            c.phone as customer_phone,
            c.email as customer_email
        FROM reservations r
        LEFT JOIN customers c ON r.customer_id = c.id
        WHERE r.id = ?
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$reservation_id]);
    
    return $stmt->fetch();
}

/**
 * 予約をキャンセル状態に更新
 */
function cancelReservation($pdo, $reservation_id, $cancel_reason = '') {
    $sql = "
        UPDATE reservations 
        SET 
            reservation_status = 'cancelled',
            notes = CASE 
                WHEN notes IS NULL OR notes = '' THEN ?
                ELSE CONCAT(notes, '\n[キャンセル] ', ?)
            END,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ";
    
    $cancel_note = 'キャンセル日時: ' . date('Y-m-d H:i:s');
    if ($cancel_reason) {
        $cancel_note .= ' 理由: ' . $cancel_reason;
    }
    
    $stmt = $pdo->prepare($sql);
    $success = $stmt->execute([$cancel_note, $cancel_note, $reservation_id]);
    
    if (!$success) {
        throw new Exception('予約のキャンセル処理に失敗しました');
    }
}

/**
 * 部屋の割り当てを解除
 */
function unassignRoomFromReservation($pdo, $reservation_id) {
    $sql = "UPDATE reservations SET room_id = NULL WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $success = $stmt->execute([$reservation_id]);
    
    if (!$success) {
        throw new Exception('部屋の割り当て解除に失敗しました');
    }
}

/**
 * 返金処理の記録
 */
function recordRefund($pdo, $reservation_id, $refund_amount, $cancel_reason) {
    // paymentsテーブルに返金記録を挿入（負の金額で記録）
    $sql = "
        INSERT INTO payments (reservation_id, amount, payment_method, paid_at, created_at) 
        VALUES (?, ?, 'refund', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ";
    
    $stmt = $pdo->prepare($sql);
    $success = $stmt->execute([$reservation_id, -$refund_amount]);
    
    if (!$success) {
        throw new Exception('返金記録の作成に失敗しました');
    }

    // 予約の支払い状況を更新
    updatePaymentStatusAfterRefund($pdo, $reservation_id, $refund_amount);
}

/**
 * 返金後の支払い状況更新
 */
function updatePaymentStatusAfterRefund($pdo, $reservation_id, $refund_amount) {
    // 現在の支払い合計を計算
    $sql = "
        SELECT 
            r.price,
            COALESCE(SUM(p.amount), 0) as total_paid
        FROM reservations r
        LEFT JOIN payments p ON r.id = p.reservation_id
        WHERE r.id = ?
        GROUP BY r.id, r.price
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$reservation_id]);
    $result = $stmt->fetch();
    
    if ($result) {
        $total_price = $result['price'];
        $total_paid = $result['total_paid'];
        
        // 支払い状況を判定
        $payment_status = 'unpaid';
        if ($total_paid > 0 && $total_paid < $total_price) {
            $payment_status = 'partial';
        } elseif ($total_paid >= $total_price) {
            $payment_status = 'paid';
        }
        
        // 予約の支払い状況を更新
        $update_sql = "UPDATE reservations SET payment_status = ? WHERE id = ?";
        $update_stmt = $pdo->prepare($update_sql);
        $update_stmt->execute([$payment_status, $reservation_id]);
    }
}

/**
 * 詳細な予約情報取得（update_reservation.phpから再利用）
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

/**
 * 一括キャンセル処理（管理者向け）
 */
function handleBulkCancel($pdo, $reservation_ids, $cancel_reason = '') {
    if (empty($reservation_ids) || !is_array($reservation_ids)) {
        throw new Exception('キャンセルする予約IDが指定されていません');
    }

    $cancelled_count = 0;
    $errors = [];

    foreach ($reservation_ids as $reservation_id) {
        try {
            // 個別のキャンセル処理
            $existing_reservation = getReservationForCancel($pdo, $reservation_id);
            if ($existing_reservation && $existing_reservation['reservation_status'] !== 'cancelled') {
                cancelReservation($pdo, $reservation_id, $cancel_reason);
                if ($existing_reservation['room_id']) {
                    unassignRoomFromReservation($pdo, $reservation_id);
                }
                $cancelled_count++;
            }
        } catch (Exception $e) {
            $errors[] = "予約ID {$reservation_id}: " . $e->getMessage();
        }
    }

    return [
        'cancelled_count' => $cancelled_count,
        'errors' => $errors
    ];
}