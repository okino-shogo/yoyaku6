<?php
/**
 * 顧客取得・検索API
 * GET /api/customers.php
 * パラメータ: search (名前、電話番号、メールアドレスでの検索)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/encryption.php';
require_once __DIR__ . '/../config/audit.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/rate_limiter.php';

// セキュリティヘッダーを設定
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');
SecurityHelper::setCSPHeaders();

// GETリクエストのみ受け付け
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    SecurityHelper::sendSecureJsonResponse(['error' => '許可されていないメソッドです'], 405);
}

// 認証チェック（管理者のみアクセス可能）
Auth::requireAuth('staff');

// レート制限チェック（認証済みユーザー向け）
$user = Auth::user();
if ($user) {
    $rateLimitResult = RateLimiter::checkUserLimit($user['id'], 'search');
    
    if (!$rateLimitResult['allowed']) {
        $retryAfter = $rateLimitResult['retry_after'] ?? 60;
        $message = "検索回数が上限に達しました。" . ceil($retryAfter / 60) . "分後に再度お試しください。";
        
        header("Retry-After: $retryAfter");
        SecurityHelper::sendSecureJsonResponse(['error' => $message], 429);
    }
}

try {
    $pdo = getDatabase();
    if (!$pdo) {
        SecurityHelper::sendSecureJsonResponse(['error' => 'データベース接続エラーです'], 500);
    }

    $search = isset($_GET['search']) ? SecurityHelper::sanitizeText(trim($_GET['search']), 50) : '';
    
    // 監査ログ記録
    AuditLogger::logCustomerDataAccess(
        $search ? 'SEARCH' : 'VIEW',
        ['search_term' => $search ? 'provided' : 'none'], // 実際の検索語は記録しない
        0 // 結果件数は後で更新
    );
    
    if ($search) {
        // 暗号化対応検索機能
        $searchHash = PersonalDataCrypto::searchableHash($search);
        
        // ハッシュ値による検索（完全一致）
        $stmt = $pdo->prepare("
            SELECT id, name_encrypted, kana, phone_encrypted, email_encrypted, 
                   address_encrypted, created_at 
            FROM customers 
            WHERE name_hash = :name_hash 
               OR phone_hash = :phone_hash 
               OR email_hash = :email_hash 
            ORDER BY created_at DESC 
            LIMIT 50
        ");
        $stmt->bindValue(':name_hash', $searchHash);
        $stmt->bindValue(':phone_hash', $searchHash);
        $stmt->bindValue(':email_hash', $searchHash);
    } else {
        // 全件取得（最新50件）- 暗号化フィールド対応
        $stmt = $pdo->prepare("
            SELECT id, name_encrypted, kana, phone_encrypted, email_encrypted, 
                   address_encrypted, created_at 
            FROM customers 
            ORDER BY created_at DESC 
            LIMIT 50
        ");
    }
    
    $stmt->execute();
    $customers = $stmt->fetchAll();

    // 暗号化データを復号化
    $decryptedCustomers = [];
    foreach ($customers as $customer) {
        $decryptedCustomer = [
            'id' => $customer['id'],
            'name' => PersonalDataCrypto::decrypt($customer['name_encrypted']),
            'kana' => $customer['kana'],
            'phone' => PersonalDataCrypto::decrypt($customer['phone_encrypted']),
            'email' => PersonalDataCrypto::decrypt($customer['email_encrypted']),
            'address' => PersonalDataCrypto::decrypt($customer['address_encrypted']),
            'created_at' => $customer['created_at']
        ];
        
        // 復号化に失敗した場合は空文字として処理
        foreach (['name', 'phone', 'email', 'address'] as $field) {
            if ($decryptedCustomer[$field] === false) {
                $decryptedCustomer[$field] = '[復号化エラー]';
            }
        }
        
        $decryptedCustomers[] = $decryptedCustomer;
    }

    // 監査ログに結果件数を記録
    AuditLogger::logCustomerDataAccess(
        $search ? 'SEARCH' : 'VIEW',
        ['search_provided' => !empty($search)],
        count($decryptedCustomers)
    );

    SecurityHelper::sendSecureJsonResponse($decryptedCustomers);

} catch (Exception $e) {
    error_log("customers.php Error: " . $e->getMessage());
    SecurityHelper::sendSecureJsonResponse(['error' => 'サーバーエラーが発生しました'], 500);
}
