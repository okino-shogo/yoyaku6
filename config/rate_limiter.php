<?php
/**
 * 高性能レート制限システム（Redis使用）
 * スライディングウィンドウ・複数制限・IP/ユーザー単位
 */

class RateLimiter {
    
    private static $redis = null;
    private static $prefix = 'yoyaku_rate_limit:';
    
    /**
     * Redis接続取得
     * @return Redis|null
     */
    private static function getRedis() {
        if (self::$redis === null) {
            try {
                // Redis設定（環境変数から取得）
                $host = $_ENV['REDIS_HOST'] ?? 'localhost';
                $port = $_ENV['REDIS_PORT'] ?? 6379;
                $password = $_ENV['REDIS_PASSWORD'] ?? '';
                $database = $_ENV['REDIS_DB'] ?? 0;
                
                self::$redis = new Redis();
                self::$redis->connect($host, $port);
                
                if (!empty($password)) {
                    self::$redis->auth($password);
                }
                
                self::$redis->select($database);
                
                // 接続テスト
                self::$redis->ping();
                
            } catch (Exception $e) {
                error_log("Redis connection error: " . $e->getMessage());
                // Redis使用不可の場合はファイルベースにフォールバック
                self::$redis = false;
                return null;
            }
        }
        
        return self::$redis === false ? null : self::$redis;
    }
    
    /**
     * レート制限ルール定義
     * @return array
     */
    private static function getRules() {
        return [
            'login' => [
                ['requests' => 5, 'window' => 300],     // 5回/5分
                ['requests' => 20, 'window' => 3600],   // 20回/1時間
                ['requests' => 100, 'window' => 86400]  // 100回/1日
            ],
            'reservation' => [
                ['requests' => 3, 'window' => 60],      // 3回/1分
                ['requests' => 10, 'window' => 3600],   // 10回/1時間
                ['requests' => 50, 'window' => 86400]   // 50回/1日
            ],
            'api_general' => [
                ['requests' => 60, 'window' => 60],     // 60回/1分
                ['requests' => 1000, 'window' => 3600], // 1000回/1時間
                ['requests' => 10000, 'window' => 86400] // 10000回/1日
            ],
            'search' => [
                ['requests' => 30, 'window' => 60],     // 30回/1分
                ['requests' => 300, 'window' => 3600],  // 300回/1時間
            ],
            'admin_action' => [
                ['requests' => 10, 'window' => 60],     // 10回/1分
                ['requests' => 100, 'window' => 3600],  // 100回/1時間
            ]
        ];
    }
    
    /**
     * レート制限チェック（スライディングウィンドウ方式）
     * @param string $identifier 識別子（IP、ユーザーID等）
     * @param string $action アクション種別
     * @param string $identifierType 識別子種別（ip, user, api_key）
     * @return array チェック結果
     */
    public static function checkLimit($identifier, $action, $identifierType = 'ip') {
        $redis = self::getRedis();
        
        // Redisが使用できない場合はファイルベースフォールバック
        if (!$redis) {
            return self::checkLimitFallback($identifier, $action);
        }
        
        $rules = self::getRules();
        
        if (!isset($rules[$action])) {
            // 未定義アクションはデフォルトルール適用
            $rules[$action] = $rules['api_general'];
        }
        
        $currentTime = time();
        $violations = [];
        
        try {
            foreach ($rules[$action] as $rule) {
                $key = self::$prefix . "{$identifierType}:{$identifier}:{$action}:{$rule['window']}";
                $windowStart = $currentTime - $rule['window'];
                
                // 期限切れエントリを削除
                $redis->zRemRangeByScore($key, 0, $windowStart);
                
                // 現在のリクエスト数を取得
                $currentCount = $redis->zCard($key);
                
                if ($currentCount >= $rule['requests']) {
                    $oldestRequest = $redis->zRange($key, 0, 0, true);
                    $resetTime = !empty($oldestRequest) ? 
                        array_values($oldestRequest)[0] + $rule['window'] : 
                        $currentTime + $rule['window'];
                    
                    $violations[] = [
                        'window' => $rule['window'],
                        'limit' => $rule['requests'],
                        'current' => $currentCount,
                        'reset_at' => $resetTime,
                        'reset_in' => max(0, $resetTime - $currentTime)
                    ];
                }
            }
            
            if (!empty($violations)) {
                // セキュリティログ記録
                self::logRateLimitViolation($identifier, $action, $identifierType, $violations);
                
                return [
                    'allowed' => false,
                    'violations' => $violations,
                    'identifier' => $identifier,
                    'action' => $action,
                    'retry_after' => min(array_column($violations, 'reset_in'))
                ];
            }
            
            // リクエストを記録
            self::recordRequest($redis, $identifier, $action, $identifierType, $currentTime);
            
            // 使用状況統計を返す
            $usage = self::getCurrentUsage($redis, $identifier, $action, $identifierType, $rules[$action]);
            
            return [
                'allowed' => true,
                'usage' => $usage,
                'identifier' => $identifier,
                'action' => $action
            ];
            
        } catch (Exception $e) {
            error_log("Rate limiter error: " . $e->getMessage());
            // エラー時は制限なしで続行
            return ['allowed' => true, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * リクエスト記録
     * @param Redis $redis
     * @param string $identifier
     * @param string $action
     * @param string $identifierType
     * @param int $timestamp
     */
    private static function recordRequest($redis, $identifier, $action, $identifierType, $timestamp) {
        $rules = self::getRules()[$action];
        
        foreach ($rules as $rule) {
            $key = self::$prefix . "{$identifierType}:{$identifier}:{$action}:{$rule['window']}";
            $score = $timestamp + ($rule['window'] * 0.000001); // マイクロ秒で一意性確保
            
            // リクエストを記録
            $redis->zAdd($key, $timestamp, $score);
            
            // TTL設定（ウィンドウサイズの2倍）
            $redis->expire($key, $rule['window'] * 2);
        }
    }
    
    /**
     * 現在の使用状況取得
     * @param Redis $redis
     * @param string $identifier
     * @param string $action
     * @param string $identifierType
     * @param array $rules
     * @return array
     */
    private static function getCurrentUsage($redis, $identifier, $action, $identifierType, $rules) {
        $usage = [];
        $currentTime = time();
        
        foreach ($rules as $rule) {
            $key = self::$prefix . "{$identifierType}:{$identifier}:{$action}:{$rule['window']}";
            $windowStart = $currentTime - $rule['window'];
            
            // 期限切れエントリを削除して正確な数を取得
            $redis->zRemRangeByScore($key, 0, $windowStart);
            $currentCount = $redis->zCard($key);
            
            $usage[] = [
                'window' => $rule['window'],
                'limit' => $rule['requests'],
                'used' => $currentCount,
                'remaining' => max(0, $rule['requests'] - $currentCount),
                'percentage' => round(($currentCount / $rule['requests']) * 100, 1)
            ];
        }
        
        return $usage;
    }
    
    /**
     * ファイルベースフォールバック
     * @param string $identifier
     * @param string $action
     * @return array
     */
    private static function checkLimitFallback($identifier, $action) {
        // 簡易ファイルベース制限（既存のlogin.phpと同様）
        $cacheFile = sys_get_temp_dir() . "/rate_limit_" . md5($identifier . $action) . ".json";
        $currentTime = time();
        $maxAttempts = 10; // 簡易制限値
        $timeWindow = 3600; // 1時間
        
        $attempts = [];
        if (file_exists($cacheFile)) {
            $attempts = json_decode(file_get_contents($cacheFile), true) ?: [];
        }
        
        // 期限切れ試行を削除
        $attempts = array_filter($attempts, function($timestamp) use ($currentTime, $timeWindow) {
            return ($currentTime - $timestamp) < $timeWindow;
        });
        
        if (count($attempts) >= $maxAttempts) {
            return [
                'allowed' => false,
                'fallback' => true,
                'message' => 'レート制限に達しました（簡易モード）'
            ];
        }
        
        // 試行を記録
        $attempts[] = $currentTime;
        file_put_contents($cacheFile, json_encode($attempts));
        
        return ['allowed' => true, 'fallback' => true];
    }
    
    /**
     * レート制限違反ログ記録
     * @param string $identifier
     * @param string $action
     * @param string $identifierType
     * @param array $violations
     */
    private static function logRateLimitViolation($identifier, $action, $identifierType, $violations) {
        $logData = [
            'event' => 'RATE_LIMIT_VIOLATION',
            'identifier' => $identifier,
            'identifier_type' => $identifierType,
            'action' => $action,
            'violations' => $violations,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        error_log("RATE_LIMIT_VIOLATION: " . json_encode($logData));
        
        // 監査ログにも記録
        if (class_exists('AuditLogger')) {
            AuditLogger::logSecurityEvent('RATE_LIMIT_VIOLATION', $logData);
        }
    }
    
    /**
     * レート制限統計取得
     * @param string $action
     * @param int $hours 過去N時間
     * @return array
     */
    public static function getStatistics($action = null, $hours = 24) {
        $redis = self::getRedis();
        
        if (!$redis) {
            return ['error' => 'Redis not available'];
        }
        
        try {
            $pattern = self::$prefix . "*";
            if ($action) {
                $pattern = self::$prefix . "*:{$action}:*";
            }
            
            $keys = $redis->keys($pattern);
            $stats = [
                'total_keys' => count($keys),
                'actions' => [],
                'identifiers' => [],
                'generated_at' => date('Y-m-d H:i:s')
            ];
            
            foreach ($keys as $key) {
                $parts = explode(':', str_replace(self::$prefix, '', $key));
                if (count($parts) >= 3) {
                    $identifierType = $parts[0];
                    $identifier = $parts[1];
                    $keyAction = $parts[2];
                    
                    // アクション別統計
                    if (!isset($stats['actions'][$keyAction])) {
                        $stats['actions'][$keyAction] = 0;
                    }
                    $stats['actions'][$keyAction] += $redis->zCard($key);
                    
                    // 識別子タイプ別統計
                    if (!isset($stats['identifiers'][$identifierType])) {
                        $stats['identifiers'][$identifierType] = [];
                    }
                    if (!isset($stats['identifiers'][$identifierType][$identifier])) {
                        $stats['identifiers'][$identifierType][$identifier] = 0;
                    }
                    $stats['identifiers'][$identifierType][$identifier] += $redis->zCard($key);
                }
            }
            
            return $stats;
            
        } catch (Exception $e) {
            error_log("Rate limiter statistics error: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * 制限リセット（管理者用）
     * @param string $identifier
     * @param string $action
     * @param string $identifierType
     * @return bool
     */
    public static function resetLimit($identifier, $action = null, $identifierType = 'ip') {
        $redis = self::getRedis();
        
        if (!$redis) {
            return false;
        }
        
        try {
            if ($action) {
                $pattern = self::$prefix . "{$identifierType}:{$identifier}:{$action}:*";
            } else {
                $pattern = self::$prefix . "{$identifierType}:{$identifier}:*";
            }
            
            $keys = $redis->keys($pattern);
            
            if (!empty($keys)) {
                $redis->del($keys);
                error_log("Rate limit reset: identifier=$identifier, action=$action, keys=" . count($keys));
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("Rate limit reset error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * ヘルパー: IPアドレス取得
     * @return string
     */
    public static function getClientIp() {
        $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP'];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
    
    /**
     * ユーザーIDベースの制限チェック
     * @param int $userId
     * @param string $action
     * @return array
     */
    public static function checkUserLimit($userId, $action) {
        return self::checkLimit("user_$userId", $action, 'user');
    }
    
    /**
     * IPベースの制限チェック
     * @param string $action
     * @param string $ip
     * @return array
     */
    public static function checkIpLimit($action, $ip = null) {
        $ip = $ip ?: self::getClientIp();
        return self::checkLimit($ip, $action, 'ip');
    }
} 