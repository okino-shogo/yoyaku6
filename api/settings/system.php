<?php
/**
 * システム設定API
 * 設計書 4.3.3 に基づく実装
 * 
 * GET /api/settings/system.php - 設定一覧取得
 * PUT /api/settings/system.php - 設定更新
 */

require_once __DIR__ . '/../../config/database.php';

// CORSヘッダーを設定
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, PUT');
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
            handleGetSettings($pdo);
            break;
        case 'PUT':
            handleUpdateSettings($pdo);
            break;
        default:
            sendErrorResponse('許可されていないメソッドです', 405);
    }

} catch (Exception $e) {
    error_log("system.php Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

/**
 * システム設定一覧取得
 */
function handleGetSettings($pdo) {
    $stmt = $pdo->prepare("
        SELECT 
            setting_key,
            setting_value,
            description,
            updated_at
        FROM system_settings 
        ORDER BY id ASC
    ");
    
    $stmt->execute();
    $settings = $stmt->fetchAll();
    
    // 設定をキー・値のペアで返す
    $formatted_settings = [];
    foreach ($settings as $setting) {
        $formatted_settings[$setting['setting_key']] = [
            'value' => $setting['setting_value'],
            'description' => $setting['description'],
            'updated_at' => $setting['updated_at']
        ];
    }
    
    sendJsonResponse($formatted_settings);
}

/**
 * システム設定更新
 */
function handleUpdateSettings($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        sendErrorResponse('有効なJSONデータが必要です', 400);
    }

    // 更新可能な設定キーの定義
    $allowed_keys = [
        'facility_name',
        'facility_address', 
        'facility_phone',
        'facility_email',
        'checkin_time',
        'checkout_time',
        'default_list_limit',
        // メール機能基本設定
        'email_enabled',
        'email_from_address',
        'email_from_name',
        'email_admin_address',
        // 顧客向け通知設定
        'email_reservation_confirmation',
        'email_reservation_update',
        'email_cancellation_notice',
        'email_checkin_reminder',
        'email_checkout_notice',
        'email_payment_reminder',
        // 管理者向け通知設定
        'email_admin_new_reservation',
        'email_admin_daily_summary',
        'email_admin_payment_report',
        'email_admin_system_error'
    ];

    $updates = [];
    $errors = [];

    foreach ($input as $key => $value) {
        if (!in_array($key, $allowed_keys)) {
            $errors[] = "設定キー '{$key}' は更新できません";
            continue;
        }

        // バリデーション（設計書 4.7 システム設定）
        $validation_error = validateSettingValue($key, $value);
        if ($validation_error) {
            $errors[] = $validation_error;
            continue;
        }

        $updates[$key] = $value;
    }

    if (!empty($errors)) {
        sendErrorResponse(implode(', ', $errors), 400);
    }

    if (empty($updates)) {
        sendErrorResponse('更新するデータがありません', 400);
    }

    // トランザクション開始
    $pdo->beginTransaction();

    try {
        foreach ($updates as $key => $value) {
            $stmt = $pdo->prepare("
                UPDATE system_settings 
                SET setting_value = :value, updated_at = CURRENT_TIMESTAMP
                WHERE setting_key = :key
            ");
            $stmt->bindValue(':value', $value);
            $stmt->bindValue(':key', $key);
            $stmt->execute();
        }

        $pdo->commit();
        sendJsonResponse(['success' => true, 'updated_count' => count($updates)]);

    } catch (Exception $e) {
        $pdo->rollback();
        error_log("System settings update error: " . $e->getMessage());
        sendErrorResponse('設定の更新に失敗しました', 500);
    }
}

/**
 * 設定値のバリデーション
 */
function validateSettingValue($key, $value) {
    switch ($key) {
        case 'facility_name':
            if (empty($value)) {
                return '施設名は必須です';
            }
            if (strlen($value) > 100) {
                return '施設名は100文字以内にしてください';
            }
            break;

        case 'facility_address':
            if (strlen($value) > 200) {
                return '施設住所は200文字以内にしてください';
            }
            break;

        case 'facility_phone':
            if (!empty($value)) {
                // 数字・ハイフン・スペース・括弧のみ許可
                if (!preg_match('/^[0-9\-\s\(\)]+$/', $value)) {
                    return '電話番号は数字・ハイフン・括弧のみ入力可能です';
                }
                if (strlen($value) > 20) {
                    return '電話番号は20文字以内にしてください';
                }
            }
            break;

        case 'facility_email':
            if (!empty($value)) {
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return '正しいメールアドレス形式で入力してください';
                }
                if (strlen($value) > 100) {
                    return 'メールアドレスは100文字以内にしてください';
                }
            }
            break;

        case 'checkin_time':
        case 'checkout_time':
            if (!empty($value)) {
                // HH:MM形式のチェック
                if (!preg_match('/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/', $value)) {
                    return '時間はHH:MM形式で入力してください（例：15:00）';
                }
            }
            break;

        case 'default_list_limit':
            $intValue = (int)$value;
            if ($intValue < 10) {
                return 'デフォルト表示件数は10件以上にしてください';
            }
            if ($intValue > 1000) {
                return 'デフォルト表示件数は1000件以下にしてください';
            }
            break;

        // メール機能基本設定のバリデーション
        case 'email_enabled':
        case 'email_reservation_confirmation':
        case 'email_reservation_update':
        case 'email_cancellation_notice':
        case 'email_checkin_reminder':
        case 'email_checkout_notice':
        case 'email_payment_reminder':
        case 'email_admin_new_reservation':
        case 'email_admin_daily_summary':
        case 'email_admin_payment_report':
        case 'email_admin_system_error':
            // ブール値（'true'/'false'文字列）のバリデーション
            if (!in_array($value, ['true', 'false'])) {
                return 'この設定は有効（true）または無効（false）で設定してください';
            }
            break;

        case 'email_from_address':
        case 'email_admin_address':
            if (!empty($value)) {
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return '正しいメールアドレス形式で入力してください';
                }
                if (strlen($value) > 100) {
                    return 'メールアドレスは100文字以内にしてください';
                }
            }
            break;

        case 'email_from_name':
            if (!empty($value)) {
                if (strlen($value) > 50) {
                    return '送信者名は50文字以内にしてください';
                }
            }
            break;
    }

    return null; // エラーなし
}
