<?php
/**
 * セキュリティダッシュボードAPI
 * 統計情報・アラート・リアルタイムステータス
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/audit.php';
require_once __DIR__ . '/../../config/rate_limiter.php';
require_once __DIR__ . '/../../config/captcha.php';
require_once __DIR__ . '/../../config/security.php';

// セキュリティヘッダー設定
SecurityHelper::setCSPHeaders();

// 管理者認証必須
Auth::requireAuth('admin');

// GETリクエストのみ受け付け
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    SecurityHelper::sendSecureJsonResponse(['error' => '許可されていないメソッドです'], 405);
}

// レート制限チェック
$user = Auth::user();
if ($user) {
    $rateLimitResult = RateLimiter::checkUserLimit($user['id'], 'admin_action');
    if (!$rateLimitResult['allowed']) {
        $retryAfter = $rateLimitResult['retry_after'] ?? 60;
        header("Retry-After: $retryAfter");
        SecurityHelper::sendSecureJsonResponse([
            'error' => 'アクセス回数制限に達しました',
            'retry_after' => $retryAfter
        ], 429);
    }
}

try {
    $action = $_GET['action'] ?? 'overview';
    
    switch ($action) {
        case 'overview':
            $data = getSecurityOverview();
            break;
            
        case 'alerts':
            $data = getSecurityAlerts();
            break;
            
        case 'logs':
            $data = getSecurityLogs();
            break;
            
        case 'stats':
            $data = getSecurityStats();
            break;
            
        case 'rate_limits':
            $data = getRateLimitStats();
            break;
            
        case 'captcha_stats':
            $data = getCaptchaStats();
            break;
            
        case 'system_health':
            $data = getSystemHealth();
            break;
            
        default:
            SecurityHelper::sendSecureJsonResponse(['error' => '無効なアクションです'], 400);
    }
    
    // 監査ログ記録
    AuditLogger::logSecurityEvent('DASHBOARD_ACCESS', [
        'action' => $action,
        'user_id' => $user['id'],
        'username' => $user['username'],
        'data_accessed' => array_keys($data)
    ]);
    
    SecurityHelper::sendSecureJsonResponse($data);
    
} catch (Exception $e) {
    error_log("Security Dashboard Error: " . $e->getMessage());
    SecurityHelper::sendSecureJsonResponse(['error' => 'ダッシュボードデータの取得に失敗しました'], 500);
}

/**
 * セキュリティ概要取得
 * @return array
 */
function getSecurityOverview() {
    $overview = [
        'status' => 'healthy',
        'last_updated' => date('Y-m-d H:i:s'),
        'alerts' => [
            'critical' => 0,
            'high' => 0,
            'medium' => 0,
            'total' => 0
        ],
        'security_score' => 85, // 実装に応じて計算
        'services' => [
            'authentication' => getServiceStatus('auth'),
            'encryption' => getServiceStatus('encryption'),
            'rate_limiting' => getServiceStatus('rate_limit'),
            'captcha' => getServiceStatus('captcha'),
            'backups' => getServiceStatus('backup'),
            'logging' => getServiceStatus('logging')
        ],
        'recent_activity' => getRecentSecurityActivity(5)
    ];
    
    // アラート数集計
    $alerts = getActiveAlerts();
    foreach ($alerts as $alert) {
        $severity = $alert['severity'] ?? 'medium';
        if (isset($overview['alerts'][$severity])) {
            $overview['alerts'][$severity]++;
        }
        $overview['alerts']['total']++;
    }
    
    // 全体ステータス判定
    if ($overview['alerts']['critical'] > 0) {
        $overview['status'] = 'critical';
    } elseif ($overview['alerts']['high'] > 0) {
        $overview['status'] = 'warning';
    }
    
    return $overview;
}

/**
 * セキュリティアラート取得
 * @return array
 */
function getSecurityAlerts() {
    $alerts = getActiveAlerts();
    
    return [
        'active_alerts' => $alerts,
        'total_count' => count($alerts),
        'by_severity' => array_count_values(array_column($alerts, 'severity')),
        'recent_resolved' => getRecentResolvedAlerts(3)
    ];
}

/**
 * セキュリティログ取得
 * @return array
 */
function getSecurityLogs() {
    $filters = [
        'date_from' => $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days')),
        'date_to' => $_GET['date_to'] ?? date('Y-m-d'),
        'action' => $_GET['log_action'] ?? null
    ];
    
    $logs = AuditLogger::searchAuditLogs($filters);
    
    // 統計情報も追加
    $stats = [
        'total_events' => count($logs),
        'by_action' => [],
        'by_user' => [],
        'timeline' => []
    ];
    
    foreach ($logs as $log) {
        // アクション別統計
        $action = $log['action'] ?? 'unknown';
        $stats['by_action'][$action] = ($stats['by_action'][$action] ?? 0) + 1;
        
        // ユーザー別統計
        $username = $log['username'] ?? 'system';
        $stats['by_user'][$username] = ($stats['by_user'][$username] ?? 0) + 1;
        
        // タイムライン（時間別）
        $hour = date('H', strtotime($log['created_at']));
        $stats['timeline'][$hour] = ($stats['timeline'][$hour] ?? 0) + 1;
    }
    
    return [
        'logs' => array_slice($logs, 0, 100), // 最新100件
        'filters' => $filters,
        'statistics' => $stats
    ];
}

/**
 * セキュリティ統計取得
 * @return array
 */
function getSecurityStats() {
    $auditStats = AuditLogger::getSecurityStats();
    
    return [
        'audit_logs' => $auditStats,
        'login_attempts' => getLoginAttemptStats(),
        'failed_authentications' => getFailedAuthStats(),
        'security_events' => getSecurityEventStats(),
        'data_access' => getDataAccessStats()
    ];
}

/**
 * レート制限統計取得
 * @return array
 */
function getRateLimitStats() {
    $stats = RateLimiter::getStatistics();
    
    return [
        'rate_limit_stats' => $stats,
        'active_limits' => getActiveRateLimits(),
        'violations_today' => getRateLimitViolationsToday()
    ];
}

/**
 * CAPTCHA統計取得
 * @return array
 */
function getCaptchaStats() {
    $stats = CaptchaService::getCaptchaStats(7); // 過去7日間
    
    return [
        'captcha_stats' => $stats,
        'success_rate' => $stats['success_rate'] ?? 0,
        'average_score' => $stats['average_score'] ?? 0,
        'blocked_attempts' => $stats['blocked_attempts'] ?? 0
    ];
}

/**
 * システムヘルス取得
 * @return array
 */
function getSystemHealth() {
    return [
        'php_version' => PHP_VERSION,
        'memory_usage' => [
            'used' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'limit' => ini_get('memory_limit')
        ],
        'disk_space' => getDiskSpaceInfo(),
        'database_status' => getDatabaseStatus(),
        'redis_status' => getRedisStatus(),
        'ssl_certificate' => getSSLCertificateInfo(),
        'security_modules' => getSecurityModulesStatus()
    ];
}

/**
 * サービスステータス取得
 * @param string $service
 * @return array
 */
function getServiceStatus($service) {
    $status = ['status' => 'unknown', 'message' => '', 'last_check' => date('Y-m-d H:i:s')];
    
    switch ($service) {
        case 'auth':
            try {
                $pdo = getDatabase();
                $stmt = $pdo->query("SELECT COUNT(*) FROM users LIMIT 1");
                $status['status'] = 'healthy';
                $status['message'] = '認証システム正常';
            } catch (Exception $e) {
                $status['status'] = 'error';
                $status['message'] = '認証システムエラー';
            }
            break;
            
        case 'encryption':
            $status['status'] = class_exists('PersonalDataCrypto') ? 'healthy' : 'error';
            $status['message'] = $status['status'] === 'healthy' ? '暗号化モジュール正常' : '暗号化モジュールエラー';
            break;
            
        case 'rate_limit':
            try {
                $redis = new Redis();
                $redis->connect('localhost', 6379);
                $redis->ping();
                $status['status'] = 'healthy';
                $status['message'] = 'レート制限正常（Redis）';
            } catch (Exception $e) {
                $status['status'] = 'degraded';
                $status['message'] = 'レート制限（ファイルフォールバック）';
            }
            break;
            
        case 'captcha':
            $status['status'] = !empty($_ENV['RECAPTCHA_SECRET_KEY']) ? 'healthy' : 'warning';
            $status['message'] = $status['status'] === 'healthy' ? 'CAPTCHA設定正常' : 'CAPTCHA設定不完全';
            break;
            
        case 'backup':
            $backupDir = $_ENV['BACKUP_DIR'] ?? __DIR__ . '/../../backups';
            $status['status'] = is_dir($backupDir) && is_writable($backupDir) ? 'healthy' : 'warning';
            $status['message'] = $status['status'] === 'healthy' ? 'バックアップシステム正常' : 'バックアップ設定要確認';
            break;
            
        case 'logging':
            $status['status'] = ini_get('log_errors') ? 'healthy' : 'warning';
            $status['message'] = $status['status'] === 'healthy' ? 'ログシステム正常' : 'ログ設定要確認';
            break;
    }
    
    return $status;
}

/**
 * アクティブアラート取得
 * @return array
 */
function getActiveAlerts() {
    $alerts = [];
    
    // データベースエラーチェック
    try {
        $pdo = getDatabase();
    } catch (Exception $e) {
        $alerts[] = [
            'id' => 'db_error',
            'severity' => 'critical',
            'title' => 'データベース接続エラー',
            'description' => 'データベースに接続できません',
            'created_at' => date('Y-m-d H:i:s'),
            'category' => 'infrastructure'
        ];
    }
    
    // HTTPS設定チェック
    if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') {
        $alerts[] = [
            'id' => 'no_https',
            'severity' => 'high',
            'title' => 'HTTPS未設定',
            'description' => 'HTTPS接続が確立されていません',
            'created_at' => date('Y-m-d H:i:s'),
            'category' => 'security'
        ];
    }
    
    // 暗号化キーチェック
    if (empty($_ENV['PERSONAL_DATA_KEY'])) {
        $alerts[] = [
            'id' => 'missing_crypto_key',
            'severity' => 'critical',
            'title' => '暗号化キー未設定',
            'description' => 'PERSONAL_DATA_KEY環境変数が設定されていません',
            'created_at' => date('Y-m-d H:i:s'),
            'category' => 'security'
        ];
    }
    
    // ディスク容量チェック
    $diskFree = disk_free_space('/');
    $diskTotal = disk_total_space('/');
    $usagePercent = (1 - $diskFree / $diskTotal) * 100;
    
    if ($usagePercent > 90) {
        $alerts[] = [
            'id' => 'disk_space_low',
            'severity' => 'high',
            'title' => 'ディスク容量不足',
            'description' => "ディスク使用率: " . round($usagePercent, 1) . "%",
            'created_at' => date('Y-m-d H:i:s'),
            'category' => 'infrastructure'
        ];
    }
    
    return $alerts;
}

/**
 * 最近の解決済みアラート取得
 * @param int $limit
 * @return array
 */
function getRecentResolvedAlerts($limit = 5) {
    // 実装例（実際には専用テーブルから取得）
    return [];
}

/**
 * 最近のセキュリティ活動取得
 * @param int $limit
 * @return array
 */
function getRecentSecurityActivity($limit = 10) {
    $filters = [
        'date_from' => date('Y-m-d', strtotime('-24 hours')),
        'action' => 'SECURITY_%'
    ];
    
    $logs = AuditLogger::searchAuditLogs($filters);
    
    return array_slice($logs, 0, $limit);
}

/**
 * その他のヘルパー関数群
 */
function getLoginAttemptStats() {
    try {
        $pdo = getDatabase();
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_attempts,
                SUM(CASE WHEN action = 'LOGIN_SUCCESS' THEN 1 ELSE 0 END) as successful,
                SUM(CASE WHEN action = 'LOGIN_FAILED' THEN 1 ELSE 0 END) as failed
            FROM audit_logs 
            WHERE DATE(created_at) = CURDATE() 
            AND action LIKE 'LOGIN_%'
        ");
        $stmt->execute();
        return $stmt->fetch() ?: ['total_attempts' => 0, 'successful' => 0, 'failed' => 0];
    } catch (Exception $e) {
        return ['total_attempts' => 0, 'successful' => 0, 'failed' => 0];
    }
}

function getFailedAuthStats() {
    try {
        $pdo = getDatabase();
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count, DATE(created_at) as date
            FROM audit_logs 
            WHERE action = 'LOGIN_FAILED'
            AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY DATE(created_at)
            ORDER BY date DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

function getSecurityEventStats() {
    try {
        $pdo = getDatabase();
        $stmt = $pdo->prepare("
            SELECT action, COUNT(*) as count
            FROM audit_logs 
            WHERE action LIKE 'SECURITY_%'
            AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY action
            ORDER BY count DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

function getDataAccessStats() {
    try {
        $pdo = getDatabase();
        $stmt = $pdo->prepare("
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as total_access,
                COUNT(DISTINCT user_id) as unique_users
            FROM audit_logs 
            WHERE action LIKE 'CUSTOMER_DATA_%'
            AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY DATE(created_at)
            ORDER BY date DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

function getActiveRateLimits() {
    // Redis統計から現在アクティブな制限を取得
    try {
        $redis = new Redis();
        $redis->connect('localhost', 6379);
        $keys = $redis->keys('yoyaku_rate_limit:*');
        
        $activeLimits = [];
        foreach (array_slice($keys, 0, 10) as $key) { // 最大10件
            $parts = explode(':', str_replace('yoyaku_rate_limit:', '', $key));
            if (count($parts) >= 3) {
                $activeLimits[] = [
                    'identifier' => $parts[1],
                    'action' => $parts[2],
                    'window' => $parts[3] ?? 'unknown',
                    'current_count' => $redis->zCard($key)
                ];
            }
        }
        
        return $activeLimits;
    } catch (Exception $e) {
        return [];
    }
}

function getRateLimitViolationsToday() {
    try {
        $pdo = getDatabase();
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as violations
            FROM audit_logs 
            WHERE action = 'RATE_LIMIT_VIOLATION'
            AND DATE(created_at) = CURDATE()
        ");
        $stmt->execute();
        $result = $stmt->fetch();
        return $result['violations'] ?? 0;
    } catch (Exception $e) {
        return 0;
    }
}

function getDiskSpaceInfo() {
    $free = disk_free_space('/');
    $total = disk_total_space('/');
    
    return [
        'total' => $total,
        'free' => $free,
        'used' => $total - $free,
        'usage_percent' => round((1 - $free / $total) * 100, 1)
    ];
}

function getDatabaseStatus() {
    try {
        $pdo = getDatabase();
        $stmt = $pdo->query("SELECT VERSION() as version");
        $version = $stmt->fetch()['version'];
        
        return [
            'status' => 'connected',
            'version' => $version,
            'connection_time' => microtime(true)
        ];
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'error' => $e->getMessage(),
            'connection_time' => null
        ];
    }
}

function getRedisStatus() {
    try {
        $redis = new Redis();
        $redis->connect('localhost', 6379);
        $info = $redis->info();
        
        return [
            'status' => 'connected',
            'version' => $info['redis_version'] ?? 'unknown',
            'memory_used' => $info['used_memory_human'] ?? 'unknown'
        ];
    } catch (Exception $e) {
        return [
            'status' => 'unavailable',
            'error' => $e->getMessage()
        ];
    }
}

function getSSLCertificateInfo() {
    // 簡易実装（実際の本番環境では適切な証明書チェックが必要）
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        return [
            'status' => 'active',
            'protocol' => $_SERVER['SERVER_PROTOCOL'] ?? 'unknown'
        ];
    }
    
    return [
        'status' => 'inactive',
        'message' => 'HTTPS接続が確立されていません'
    ];
}

function getSecurityModulesStatus() {
    return [
        'openssl' => extension_loaded('openssl'),
        'pdo_mysql' => extension_loaded('pdo_mysql'),
        'curl' => extension_loaded('curl'),
        'mbstring' => extension_loaded('mbstring'),
        'redis' => extension_loaded('redis'),
        'hash' => extension_loaded('hash')
    ];
} 