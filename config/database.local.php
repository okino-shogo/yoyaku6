<?php
/**
 * データベース接続設定 - ローカル環境用
 */

// エラー表示を有効にする（開発環境）
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ローカルデータベース設定
$database_config = [
    'host' => 'localhost',
    'dbname' => 'yoyaku_system',
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8mb4',
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 30,
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
        error_log("Database connection error: " . $e->getMessage());
        echo "データベース接続エラー: " . $e->getMessage() . "\n";
        echo "Host: " . $database_config['host'] . "\n";
        echo "Database: " . $database_config['dbname'] . "\n";
        echo "Username: " . $database_config['username'] . "\n";
        
        return null;
    }
}

/**
 * JSONレスポンスの送信
 */
function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * エラーレスポンスの送信
 */
function sendErrorResponse($message, $statusCode = 400) {
    sendJsonResponse(['error' => $message], $statusCode);
}

/**
 * 必須項目のバリデーション
 */
function validateRequired($data, $required_fields) {
    $errors = [];
    foreach ($required_fields as $field) {
        if (!isset($data[$field]) || empty(trim($data[$field]))) {
            $errors[] = "{$field} は必須項目です";
        }
    }
    return $errors;
}

/**
 * 日付バリデーション
 */
function validateDate($date) {
    $format = 'Y-m-d';
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
} 