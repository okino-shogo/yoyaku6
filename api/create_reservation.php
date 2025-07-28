<?php
/**
 * 予約登録API
 * POST /api/create_reservation.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/email.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/encryption.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/audit.php';
require_once __DIR__ . '/../config/captcha.php';
require_once __DIR__ . '/../config/rate_limiter.php';

// セキュリティヘッダーを設定
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');
SecurityHelper::setCSPHeaders();

// POSTリクエストのみ受け付け
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    SecurityHelper::sendSecureJsonResponse(['error' => '許可されていないメソッドです'], 405);
}

try {
    $pdo = getDatabase();
    if (!$pdo) {
        SecurityHelper::sendSecureJsonResponse(['error' => 'データベース接続エラーです'], 500);
    }

    // リクエストデータの取得
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    // レート制限チェック
    $clientIp = RateLimiter::getClientIp();
    $rateLimitResult = RateLimiter::checkIpLimit('reservation', $clientIp);
    
    if (!$rateLimitResult['allowed']) {
        $retryAfter = $rateLimitResult['retry_after'] ?? 60;
        $message = "予約フォームの送信回数が上限に達しました。" . ceil($retryAfter / 60) . "分後に再度お試しください。";
        
        header("Retry-After: $retryAfter");
        SecurityHelper::sendSecureJsonResponse(['error' => $message], 429);
    }

    // CSRF対策（Webフォームからの場合のみ）
    if (isset($input['csrf_token'])) {
        Auth::initSession();
        if (!Auth::verifyCsrfToken($input['csrf_token'])) {
            SecurityHelper::sendSecureJsonResponse(['error' => '不正なリクエストです（CSRF）'], 403);
        }
    }

    // CAPTCHA検証（Webフォームからの場合）
    if (isset($input['recaptcha_token'])) {
        $captchaResult = CaptchaService::requireCaptcha('reservation', $input['recaptcha_token']);
        
        if (!$captchaResult['success']) {
            // 監査ログ記録 - CAPTCHA失敗
            AuditLogger::logSecurityEvent('CAPTCHA_FAILED', [
                'action' => 'reservation',
                'score' => $captchaResult['score'] ?? 0.0,
                'error' => $captchaResult['error'] ?? 'unknown',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
            
            SecurityHelper::sendSecureJsonResponse([
                'error' => 'スパム対策のため、しばらく時間をおいてから再度お試しください',
                'captcha_error' => $captchaResult['error']
            ], 429);
        }
        
        // CAPTCHA成功ログ
        error_log("CAPTCHA Success: action=reservation, score=" . ($captchaResult['score'] ?? 'N/A'));
    }

    // 統一バリデーション・サニタイゼーション
    $validationRules = [
        'name' => [
            'required' => true,
            'type' => 'name',
            'label' => '氏名',
            'min_length' => 1,
            'max_length' => 50
        ],
        'kana' => [
            'required' => false,
            'type' => 'text',
            'label' => 'ふりがな',
            'max_length' => 50
        ],
        'phone' => [
            'required' => true,
            'type' => 'phone',
            'label' => '電話番号',
            'min_length' => 10,
            'max_length' => 20
        ],
        'email' => [
            'required' => false,
            'type' => 'email',
            'label' => 'メールアドレス',
            'max_length' => 100
        ],
        'address' => [
            'required' => false,
            'type' => 'address',
            'label' => '住所',
            'max_length' => 200
        ],
        'checkinDate' => [
            'required' => true,
            'type' => 'text',
            'label' => 'チェックイン日'
        ],
        'checkoutDate' => [
            'required' => true,
            'type' => 'text',
            'label' => 'チェックアウト日'
        ],
        'adults' => [
            'required' => true,
            'type' => 'text',
            'label' => '大人人数'
        ],
        'children' => [
            'required' => false,
            'type' => 'text',
            'label' => '子ども人数'
        ],
        'roomType' => [
            'required' => true,
            'type' => 'text',
            'label' => '部屋タイプ',
            'max_length' => 50
        ],
        'plan' => [
            'required' => true,
            'type' => 'text',
            'label' => 'プラン',
            'max_length' => 100
        ],
        'notes' => [
            'required' => false,
            'type' => 'text',
            'label' => '備考',
            'max_length' => 1000
        ],
        'paymentMethod' => [
            'required' => false,
            'type' => 'text',
            'label' => '支払い方法',
            'max_length' => 50
        ]
    ];

    $validation = SecurityHelper::validateAndSanitize($input, $validationRules);
    
    if (!$validation['valid']) {
        SecurityHelper::sendSecureJsonResponse([
            'error' => '入力データに問題があります',
            'details' => $validation['errors']
        ], 400);
    }

    // サニタイズ済みデータを使用
    $input = $validation['sanitized'];

    // 日付バリデーション
    if (!validateDate($input['checkinDate']) || !validateDate($input['checkoutDate'])) {
        SecurityHelper::sendSecureJsonResponse(['error' => '日付の形式が正しくありません'], 400);
    }

    // チェックイン・チェックアウト日の妥当性チェック
    if ($input['checkinDate'] >= $input['checkoutDate']) {
        SecurityHelper::sendSecureJsonResponse(['error' => 'チェックアウト日はチェックイン日より後にしてください'], 400);
    }

    // 人数バリデーション
    $adults = (int)$input['adults'];
    $children = (int)($input['children'] ?? 0);
    
    if ($adults < 1) {
        SecurityHelper::sendSecureJsonResponse(['error' => '大人の人数は1名以上にしてください'], 400);
    }
    
    if ($adults > 20 || $children > 10) {
        SecurityHelper::sendSecureJsonResponse(['error' => '人数が上限を超えています'], 400);
    }

    $pdo->beginTransaction();

    // 1. プランIDの取得
    $stmt = $pdo->prepare("SELECT id, price FROM plans WHERE name = :plan");
    $stmt->bindValue(':plan', $input['plan']);
    $stmt->execute();
    $plan = $stmt->fetch();
    
    if (!$plan) {
        $pdo->rollBack();
        SecurityHelper::sendSecureJsonResponse(['error' => '指定されたプランが見つかりません'], 400);
    }

    // 2. 部屋タイプIDの取得
    $stmt = $pdo->prepare("SELECT id FROM room_types WHERE name = :roomType");
    $stmt->bindValue(':roomType', $input['roomType']);
    $stmt->execute();
    $roomType = $stmt->fetch();
    
    if (!$roomType) {
        $pdo->rollBack();
        SecurityHelper::sendSecureJsonResponse(['error' => '指定された部屋タイプが見つかりません'], 400);
    }

    // 3. 顧客の登録または検索
    $customer_id = null;
    
    // 暗号化対応：ハッシュ値による検索
    $phoneHash = PersonalDataCrypto::searchableHash($input['phone']);
    $emailHash = !empty($input['email']) ? PersonalDataCrypto::searchableHash($input['email']) : '';
    
    // 既存顧客の検索（ハッシュ値による検索）
    $sql = "SELECT id FROM customers WHERE phone_hash = :phone_hash";
    $params = [':phone_hash' => $phoneHash];
    
    if (!empty($emailHash)) {
        $sql .= " OR email_hash = :email_hash";
        $params[':email_hash'] = $emailHash;
    }
    
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $existing_customer = $stmt->fetch();
    
    if ($existing_customer) {
        $customer_id = $existing_customer['id'];
        
        // 暗号化済みデータの準備
        $encryptedData = PersonalDataCrypto::encryptCustomerData([
            'name' => $input['name'],
            'phone' => $input['phone'],
            'email' => $input['email'] ?? '',
            'address' => $input['address'] ?? ''
        ]);
        
        // 既存顧客情報の更新（暗号化済み）
        $stmt = $pdo->prepare("
            UPDATE customers 
            SET name_encrypted = :name_encrypted, name_hash = :name_hash,
                kana = :kana,
                phone_encrypted = :phone_encrypted, phone_hash = :phone_hash,
                email_encrypted = :email_encrypted, email_hash = :email_hash,
                address_encrypted = :address_encrypted, address_hash = :address_hash
            WHERE id = :id
        ");
        $stmt->bindValue(':name_encrypted', $encryptedData['name_encrypted']);
        $stmt->bindValue(':name_hash', $encryptedData['name_hash']);
        $stmt->bindValue(':kana', $input['kana'] ?? '');
        $stmt->bindValue(':phone_encrypted', $encryptedData['phone_encrypted']);
        $stmt->bindValue(':phone_hash', $encryptedData['phone_hash']);
        $stmt->bindValue(':email_encrypted', $encryptedData['email_encrypted']);
        $stmt->bindValue(':email_hash', $encryptedData['email_hash']);
        $stmt->bindValue(':address_encrypted', $encryptedData['address_encrypted']);
        $stmt->bindValue(':address_hash', $encryptedData['address_hash']);
        $stmt->bindValue(':id', $customer_id);
        $stmt->execute();
    } else {
        // 暗号化済みデータの準備
        $encryptedData = PersonalDataCrypto::encryptCustomerData([
            'name' => $input['name'],
            'phone' => $input['phone'],
            'email' => $input['email'] ?? '',
            'address' => $input['address'] ?? ''
        ]);
        
        // 新規顧客の登録（暗号化済み）
        $stmt = $pdo->prepare("
            INSERT INTO customers 
            (name_encrypted, name_hash, kana, phone_encrypted, phone_hash, 
             email_encrypted, email_hash, address_encrypted, address_hash) 
            VALUES 
            (:name_encrypted, :name_hash, :kana, :phone_encrypted, :phone_hash,
             :email_encrypted, :email_hash, :address_encrypted, :address_hash)
        ");
        $stmt->bindValue(':name_encrypted', $encryptedData['name_encrypted']);
        $stmt->bindValue(':name_hash', $encryptedData['name_hash']);
        $stmt->bindValue(':kana', $input['kana'] ?? '');
        $stmt->bindValue(':phone_encrypted', $encryptedData['phone_encrypted']);
        $stmt->bindValue(':phone_hash', $encryptedData['phone_hash']);
        $stmt->bindValue(':email_encrypted', $encryptedData['email_encrypted']);
        $stmt->bindValue(':email_hash', $encryptedData['email_hash']);
        $stmt->bindValue(':address_encrypted', $encryptedData['address_encrypted']);
        $stmt->bindValue(':address_hash', $encryptedData['address_hash']);
        $stmt->execute();
        $customer_id = $pdo->lastInsertId();
    }

    // 4. 料金計算
    $total_guests = $adults + $children;
    
    // 宿泊日数を計算
    $checkin_date_obj = new DateTime($input['checkinDate']);
    $checkout_date_obj = new DateTime($input['checkoutDate']);
    $nights = $checkin_date_obj->diff($checkout_date_obj)->days;
    
    if ($nights <= 0) {
        $pdo->rollBack();
        SecurityHelper::sendSecureJsonResponse(['error' => '宿泊日数が正しくありません'], 400);
    }
    
    // 基本料金計算（1名1泊あたりの料金 × 人数 × 泊数）
    $price = $plan['price'] * $total_guests * $nights;
    
    // 決済方法の設定（デフォルトは現金）
    $payment_method = $input['paymentMethod'] ?? 'cash';
    
    // 決済方法のバリデーション
    $allowed_payment_methods = ['cash', 'stripe_card', 'stripe_konbini'];
    if (!in_array($payment_method, $allowed_payment_methods)) {
        $payment_method = 'cash';
    }

    // 5. 予約の登録
    $stmt = $pdo->prepare("
        INSERT INTO reservations 
        (customer_id, plan_id, checkin_date, checkout_date, adults, children, 
         price, total_amount, payment_method, source, notes) 
        VALUES (:customer_id, :plan_id, :checkin_date, :checkout_date, :adults, :children, 
                :price, :total_amount, :payment_method, :source, :notes)
    ");
    
    $stmt->bindValue(':customer_id', $customer_id);
    $stmt->bindValue(':plan_id', $plan['id']);
    $stmt->bindValue(':checkin_date', $input['checkinDate']);
    $stmt->bindValue(':checkout_date', $input['checkoutDate']);
    $stmt->bindValue(':adults', $adults);
    $stmt->bindValue(':children', $children);
    $stmt->bindValue(':price', $price);
    $stmt->bindValue(':total_amount', $price);
    $stmt->bindValue(':payment_method', $payment_method);
    $stmt->bindValue(':source', $input['source'] ?? 'web');
    $stmt->bindValue(':notes', $input['notes'] ?? '');
    
    $stmt->execute();
    $reservation_id = $pdo->lastInsertId();

    $pdo->commit();

    // 監査ログ記録
    $reservationData = [
        'id' => $reservation_id,
        'customer_name' => $input['name'],
        'phone' => $input['phone'],
        'email' => $input['email'] ?? '',
        'checkin_date' => $input['checkinDate'],
        'checkout_date' => $input['checkoutDate'],
        'adults' => $adults,
        'children' => $children,
        'price' => $price,
        'source' => $input['source'] ?? 'web'
    ];
    
    AuditLogger::logReservationAction(
        'CREATE',
        $reservation_id,
        null,
        $reservationData,
        ['source' => $input['source'] ?? 'web', 'total_price' => $price]
    );

    // メール送信処理
    try {
        // 予約データの取得（メール送信用）
        $reservationData = getReservationDataForEmail($pdo, $reservation_id);
        
        if ($reservationData) {
            // 顧客向け予約確認メール
            sendReservationConfirmationEmail($reservationData);
            
            // 管理者向け新規予約通知
            sendAdminNewReservationAlert($reservationData);
        }
    } catch (Exception $e) {
        // メール送信エラーは予約作成の成功に影響させない
        error_log("Email sending error after reservation creation: " . $e->getMessage());
        // 外部システム連携エラーは予約作成の成功に影響させない
        error_log("External integration error after reservation creation: " . $e->getMessage());
    }

    SecurityHelper::sendSecureJsonResponse([
        'success' => true,
        'reservationId' => $reservation_id,
        'price' => $price,
        'totalAmount' => $price,
        'paymentMethod' => $payment_method,
        'nights' => $nights
    ], 201);

} 
catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("create_reservation.php Error: " . $e->getMessage());
    SecurityHelper::sendSecureJsonResponse(['error' => '予約登録中にエラーが発生しました'], 500);
}

/**
 * メール送信用の予約データを取得
 */
function getReservationDataForEmail($pdo, $reservationId) {
    $stmt = $pdo->prepare("
        SELECT 
            r.id,
            r.checkin_date,
            r.checkout_date,
            r.adults,
            r.children,
            r.price,
            r.payment_status,
            r.reservation_status,
            c.name as customer_name,
            c.phone as customer_phone,
            c.email as customer_email,
            p.name as plan_name,
            s1.setting_value as facility_name,
            s2.setting_value as facility_address,
            s3.setting_value as facility_phone,
            s4.setting_value as facility_email,
            s5.setting_value as checkin_time,
            s6.setting_value as checkout_time
        FROM reservations r
        JOIN customers c ON r.customer_id = c.id
        JOIN plans p ON r.plan_id = p.id
        LEFT JOIN system_settings s1 ON s1.setting_key = 'facility_name'
        LEFT JOIN system_settings s2 ON s2.setting_key = 'facility_address'
        LEFT JOIN system_settings s3 ON s3.setting_key = 'facility_phone'
        LEFT JOIN system_settings s4 ON s4.setting_key = 'facility_email'
        LEFT JOIN system_settings s5 ON s5.setting_key = 'checkin_time'
        LEFT JOIN system_settings s6 ON s6.setting_key = 'checkout_time'
        WHERE r.id = ?
    ");
    
    $stmt->execute([$reservationId]);
    $result = $stmt->fetch();
    
    if ($result) {
        // 管理画面のURLを追加
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $result['admin_url'] = "{$protocol}://{$host}/admin";
        
        return $result;
    }
    
    return null;
}