<?php
/**
 * データベース接続設定
 */

// エラー表示を制御（本番環境では非表示にする）
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

// データベース設定
$database_config = [
    'host' => 'localhost',
    'dbname' => 'yoyaku_system',
    'username' => 'root',
    'password' => '',  // パスワードなし
    'charset' => 'utf8mb4',
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
];

/**
 * データベース接続を取得
 */
function getDatabase() {
    global $database_config;
    
    try {
        $dsn = "mysql:host={$database_config['host']};dbname={$database_config['dbname']};charset={$database_config['charset']}";
        $pdo = new PDO($dsn, $database_config['username'], $database_config['password'], $database_config['options']);
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        return null;
    }
}

/**
 * JSONレスポンスを送信
 */
function sendJsonResponse($data, $status_code = 200) {
    http_response_code($status_code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * エラーレスポンスを送信
 */
function sendErrorResponse($message, $status_code = 400) {
    sendJsonResponse(['error' => $message], $status_code);
}

/**
 * バリデーション関数
 */
function validateRequired($data, $required_fields) {
    $errors = [];
    foreach ($required_fields as $field) {
        if (!isset($data[$field]) || empty(trim($data[$field]))) {
            $errors[] = "{$field}は必須項目です";
        }
    }
    return $errors;
}

/**
 * 日付形式のバリデーション
 */
function validateDate($date) {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}