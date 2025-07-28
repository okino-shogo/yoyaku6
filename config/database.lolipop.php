<?php
/**
 * データベース接続設定 - ロリポップ用
 * 
 * デプロイ時の手順:
 * 1. このファイルを database.php にリネーム
 * 2. 下記の設定値を実際のロリポップ情報に変更
 */

// エラー表示を制御（本番環境では非表示）
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

// ロリポップデータベース設定
$database_config = [
    'host' => 'mysql321.phy.lolipop.lan',
    'dbname' => 'LAA1658345-gfatxo',        // ← 実際のデータベース名
    'username' => 'LAA1658345',             // ← 実際のユーザー名
    'password' => 'i2cNIbkoLUMnFshT',       // ← 実際のパスワード
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
        // ロリポップ環境でのデバッグ情報
        error_log("Database connection error: " . $e->getMessage());
        error_log("Host: " . $database_config['host']);
        error_log("Database: " . $database_config['dbname']);
        error_log("Username: " . $database_config['username']);
        
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