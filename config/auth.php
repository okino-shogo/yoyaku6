<?php
/**
 * 認証システム
 * セッション管理、ユーザー認証、権限チェック機能
 */

require_once __DIR__ . '/database.php';

class Auth {
    private static $pdo = null;
    
    /**
     * データベース接続取得
     */
    private static function getPdo() {
        if (self::$pdo === null) {
            self::$pdo = getDatabase();
        }
        return self::$pdo;
    }
    
    /**
     * セッション初期化
     */
    public static function initSession() {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 28800, // 8時間
                'path' => '/',
                'domain' => $_SERVER['HTTP_HOST'] ?? '',
                'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
                'httponly' => true,
                'samesite' => 'Strict'
            ]);
            session_start();
        }
    }
    
    /**
     * ユーザーログイン
     */
    public static function login($username, $password) {
        $pdo = self::getPdo();
        if (!$pdo) {
            throw new Exception('データベース接続エラー');
        }
        
        // アカウントロック確認
        $stmt = $pdo->prepare("
            SELECT id, username, email, password_hash, role, is_active, 
                   failed_login_attempts, locked_until
            FROM users 
            WHERE username = :username AND is_active = 1
        ");
        $stmt->bindValue(':username', $username);
        $stmt->execute();
        $user = $stmt->fetch();
        
        if (!$user) {
            self::logAuditEvent(null, 'LOGIN_FAILED', null, null, null, ['reason' => 'user_not_found', 'username' => $username]);
            throw new Exception('ユーザー名またはパスワードが間違っています');
        }
        
        // アカウントロック確認
        if ($user['locked_until'] && new DateTime() < new DateTime($user['locked_until'])) {
            self::logAuditEvent($user['id'], 'LOGIN_BLOCKED', null, null, null, ['reason' => 'account_locked']);
            throw new Exception('アカウントがロックされています。しばらくしてから再試行してください。');
        }
        
        // パスワード検証
        if (!password_verify($password, $user['password_hash'])) {
            // 失敗回数を増加
            self::incrementFailedAttempts($user['id']);
            self::logAuditEvent($user['id'], 'LOGIN_FAILED', null, null, null, ['reason' => 'wrong_password']);
            throw new Exception('ユーザー名またはパスワードが間違っています');
        }
        
        // ログイン成功処理
        self::initSession();
        
        // セッションに保存
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['login_time'] = time();
        
        // CSRFトークン生成
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        
        // 失敗回数リセット & 最終ログイン時刻更新
        $stmt = $pdo->prepare("
            UPDATE users 
            SET failed_login_attempts = 0, locked_until = NULL, last_login = NOW() 
            WHERE id = :id
        ");
        $stmt->bindValue(':id', $user['id']);
        $stmt->execute();
        
        // セッショントークン生成・保存
        $sessionToken = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 28800); // 8時間後
        
        $stmt = $pdo->prepare("
            INSERT INTO user_sessions (user_id, session_token, expires_at) 
            VALUES (:user_id, :token, :expires_at)
        ");
        $stmt->bindValue(':user_id', $user['id']);
        $stmt->bindValue(':token', $sessionToken);
        $stmt->bindValue(':expires_at', $expiresAt);
        $stmt->execute();
        
        $_SESSION['session_token'] = $sessionToken;
        
        self::logAuditEvent($user['id'], 'LOGIN_SUCCESS', null, null, null, null);
        
        return [
            'id' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role']
        ];
    }
    
    /**
     * ログアウト
     */
    public static function logout() {
        self::initSession();
        
        if (isset($_SESSION['user_id'])) {
            $userId = $_SESSION['user_id'];
            $sessionToken = $_SESSION['session_token'] ?? null;
            
            // セッショントークン削除
            if ($sessionToken) {
                $pdo = self::getPdo();
                $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE session_token = :token");
                $stmt->bindValue(':token', $sessionToken);
                $stmt->execute();
            }
            
            self::logAuditEvent($userId, 'LOGOUT', null, null, null, null);
        }
        
        // セッション破棄
        session_destroy();
        session_start();
        session_regenerate_id(true);
    }
    
    /**
     * 認証確認
     */
    public static function check() {
        self::initSession();
        
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['session_token'])) {
            return false;
        }
        
        // セッション有効期限確認
        $pdo = self::getPdo();
        $stmt = $pdo->prepare("
            SELECT s.expires_at, u.is_active 
            FROM user_sessions s
            JOIN users u ON s.user_id = u.id
            WHERE s.session_token = :token AND s.user_id = :user_id
        ");
        $stmt->bindValue(':token', $_SESSION['session_token']);
        $stmt->bindValue(':user_id', $_SESSION['user_id']);
        $stmt->execute();
        $session = $stmt->fetch();
        
        if (!$session || new DateTime() > new DateTime($session['expires_at']) || !$session['is_active']) {
            self::logout();
            return false;
        }
        
        return true;
    }
    
    /**
     * 権限チェック
     */
    public static function hasRole($requiredRole) {
        if (!self::check()) {
            return false;
        }
        
        $userRole = $_SESSION['role'];
        $roleHierarchy = ['staff' => 1, 'admin' => 2, 'superadmin' => 3];
        
        return ($roleHierarchy[$userRole] ?? 0) >= ($roleHierarchy[$requiredRole] ?? 0);
    }
    
    /**
     * 認証必須チェック
     */
    public static function requireAuth($requiredRole = 'staff') {
        if (!self::hasRole($requiredRole)) {
            http_response_code(401);
            sendJsonResponse(['error' => '認証が必要です', 'redirect' => '/login']);
            exit;
        }
    }
    
    /**
     * CSRFトークン生成
     */
    public static function generateCsrfToken() {
        self::initSession();
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    
    /**
     * CSRFトークン検証
     */
    public static function verifyCsrfToken($token) {
        self::initSession();
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
    
    /**
     * 失敗回数増加
     */
    private static function incrementFailedAttempts($userId) {
        $pdo = self::getPdo();
        
        $stmt = $pdo->prepare("
            UPDATE users 
            SET failed_login_attempts = failed_login_attempts + 1,
                locked_until = CASE 
                    WHEN failed_login_attempts >= 4 THEN DATE_ADD(NOW(), INTERVAL 30 MINUTE)
                    ELSE locked_until 
                END
            WHERE id = :id
        ");
        $stmt->bindValue(':id', $userId);
        $stmt->execute();
    }
    
    /**
     * 監査ログ記録
     */
    public static function logAuditEvent($userId, $action, $tableName = null, $recordId = null, $oldValues = null, $newValues = null) {
        try {
            $pdo = self::getPdo();
            
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs 
                (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent) 
                VALUES (:user_id, :action, :table_name, :record_id, :old_values, :new_values, :ip_address, :user_agent)
            ");
            
            $stmt->bindValue(':user_id', $userId);
            $stmt->bindValue(':action', $action);
            $stmt->bindValue(':table_name', $tableName);
            $stmt->bindValue(':record_id', $recordId);
            $stmt->bindValue(':old_values', $oldValues ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null);
            $stmt->bindValue(':new_values', $newValues ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null);
            $stmt->bindValue(':ip_address', $_SERVER['REMOTE_ADDR'] ?? '');
            $stmt->bindValue(':user_agent', $_SERVER['HTTP_USER_AGENT'] ?? '');
            
            $stmt->execute();
        } catch (Exception $e) {
            error_log("Audit log error: " . $e->getMessage());
        }
    }
    
    /**
     * 現在のユーザー情報取得
     */
    public static function user() {
        if (!self::check()) {
            return null;
        }
        
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'role' => $_SESSION['role']
        ];
    }
} 