<?php
/**
 * 監査ログ強化システム
 * 重要操作の詳細ログ記録と分析機能
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';

class AuditLogger {
    
    /**
     * 予約操作ログ記録
     * @param string $action 操作種別 (CREATE, UPDATE, DELETE, ASSIGN, CHECKIN, CHECKOUT)
     * @param int $reservationId 予約ID
     * @param array $oldData 変更前データ
     * @param array $newData 変更後データ
     * @param array $additionalInfo 追加情報
     */
    public static function logReservationAction($action, $reservationId, $oldData = null, $newData = null, $additionalInfo = []) {
        $userId = null;
        $userSession = Auth::user();
        if ($userSession) {
            $userId = $userSession['id'];
        }
        
        $auditData = [
            'category' => 'RESERVATION',
            'action' => $action,
            'reservation_id' => $reservationId,
            'customer_info' => self::extractCustomerInfo($newData ?: $oldData),
            'changes' => self::calculateChanges($oldData, $newData),
            'business_impact' => self::assessBusinessImpact($action, $oldData, $newData),
            'additional_info' => $additionalInfo
        ];
        
        Auth::logAuditEvent(
            $userId,
            "RESERVATION_$action",
            'reservations',
            $reservationId,
            $oldData,
            $auditData
        );
        
        // 重要操作の場合は別途アラート
        if (self::isHighRiskOperation($action, $oldData, $newData)) {
            self::sendSecurityAlert($action, $auditData);
        }
    }
    
    /**
     * 顧客データアクセスログ
     * @param string $action VIEW, SEARCH, EXPORT
     * @param array $searchParams 検索パラメータ
     * @param int $resultCount 結果件数
     */
    public static function logCustomerDataAccess($action, $searchParams = [], $resultCount = 0) {
        $userId = null;
        $userSession = Auth::user();
        if ($userSession) {
            $userId = $userSession['id'];
        }
        
        $auditData = [
            'category' => 'CUSTOMER_DATA',
            'action' => $action,
            'search_params' => self::sanitizeSearchParams($searchParams),
            'result_count' => $resultCount,
            'privacy_level' => 'HIGH',
            'compliance_notes' => '個人情報保護法対応ログ'
        ];
        
        Auth::logAuditEvent(
            $userId,
            "CUSTOMER_DATA_$action",
            'customers',
            null,
            null,
            $auditData
        );
    }
    
    /**
     * システム設定変更ログ
     * @param string $settingType ROOMS, PLANS, SYSTEM
     * @param string $action CREATE, UPDATE, DELETE
     * @param string $itemName 項目名
     * @param array $oldData 変更前データ
     * @param array $newData 変更後データ
     */
    public static function logSettingsChange($settingType, $action, $itemName, $oldData = null, $newData = null) {
        $userId = null;
        $userSession = Auth::user();
        if ($userSession) {
            $userId = $userSession['id'];
        }
        
        $auditData = [
            'category' => 'SETTINGS',
            'setting_type' => $settingType,
            'action' => $action,
            'item_name' => $itemName,
            'changes' => self::calculateChanges($oldData, $newData),
            'system_impact' => self::assessSystemImpact($settingType, $action)
        ];
        
        Auth::logAuditEvent(
            $userId,
            "SETTINGS_{$settingType}_$action",
            strtolower($settingType),
            null,
            $oldData,
            $auditData
        );
    }
    
    /**
     * セキュリティイベントログ
     * @param string $eventType SUSPICIOUS_ACCESS, BRUTE_FORCE, PRIVILEGE_ESCALATION
     * @param array $details イベント詳細
     */
    public static function logSecurityEvent($eventType, $details = []) {
        $userId = null;
        $userSession = Auth::user();
        if ($userSession) {
            $userId = $userSession['id'];
        }
        
        $auditData = [
            'category' => 'SECURITY',
            'event_type' => $eventType,
            'severity' => self::getSecuritySeverity($eventType),
            'details' => $details,
            'threat_level' => self::assessThreatLevel($eventType, $details),
            'recommended_action' => self::getRecommendedAction($eventType)
        ];
        
        Auth::logAuditEvent(
            $userId,
            "SECURITY_$eventType",
            null,
            null,
            null,
            $auditData
        );
        
        // 高リスクイベントの場合は即座にアラート
        if ($auditData['severity'] === 'HIGH' || $auditData['severity'] === 'CRITICAL') {
            self::sendSecurityAlert($eventType, $auditData);
        }
    }
    
    /**
     * 監査ログ検索・分析
     * @param array $filters 検索フィルター
     * @return array 検索結果
     */
    public static function searchAuditLogs($filters = []) {
        $pdo = getDatabase();
        if (!$pdo) {
            return [];
        }
        
        $sql = "
            SELECT 
                al.*,
                u.username,
                u.role
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE 1=1
        ";
        
        $params = [];
        
        // フィルター条件の追加
        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(al.created_at) >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(al.created_at) <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }
        
        if (!empty($filters['user_id'])) {
            $sql .= " AND al.user_id = :user_id";
            $params[':user_id'] = $filters['user_id'];
        }
        
        if (!empty($filters['action'])) {
            $sql .= " AND al.action LIKE :action";
            $params[':action'] = '%' . $filters['action'] . '%';
        }
        
        if (!empty($filters['table_name'])) {
            $sql .= " AND al.table_name = :table_name";
            $params[':table_name'] = $filters['table_name'];
        }
        
        $sql .= " ORDER BY al.created_at DESC LIMIT 1000";
        
        try {
            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Audit log search error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * セキュリティダッシュボード用統計データ
     * @return array 統計データ
     */
    public static function getSecurityStats() {
        $pdo = getDatabase();
        if (!$pdo) {
            return [];
        }
        
        try {
            // 今日のログイン試行統計
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total_attempts,
                    SUM(CASE WHEN action = 'LOGIN_SUCCESS' THEN 1 ELSE 0 END) as successful_logins,
                    SUM(CASE WHEN action = 'LOGIN_FAILED' THEN 1 ELSE 0 END) as failed_logins,
                    COUNT(DISTINCT user_id) as unique_users
                FROM audit_logs 
                WHERE DATE(created_at) = CURDATE() 
                AND action LIKE 'LOGIN_%'
            ");
            $stmt->execute();
            $loginStats = $stmt->fetch();
            
            // 重要操作の統計
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total_operations,
                    SUM(CASE WHEN action LIKE 'RESERVATION_%' THEN 1 ELSE 0 END) as reservation_ops,
                    SUM(CASE WHEN action LIKE 'SETTINGS_%' THEN 1 ELSE 0 END) as settings_ops,
                    SUM(CASE WHEN action LIKE 'CUSTOMER_DATA_%' THEN 1 ELSE 0 END) as customer_data_ops
                FROM audit_logs 
                WHERE DATE(created_at) = CURDATE()
            ");
            $stmt->execute();
            $operationStats = $stmt->fetch();
            
            // セキュリティイベントの統計
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as security_events,
                    COUNT(DISTINCT ip_address) as unique_ips
                FROM audit_logs 
                WHERE DATE(created_at) = CURDATE() 
                AND action LIKE 'SECURITY_%'
            ");
            $stmt->execute();
            $securityStats = $stmt->fetch();
            
            return [
                'login_stats' => $loginStats,
                'operation_stats' => $operationStats,
                'security_stats' => $securityStats,
                'generated_at' => date('Y-m-d H:i:s')
            ];
            
        } catch (Exception $e) {
            error_log("Security stats error: " . $e->getMessage());
            return [];
        }
    }
    
    // === プライベートヘルパー関数 ===
    
    private static function extractCustomerInfo($data) {
        if (!$data) return null;
        
        return [
            'name' => $data['customer_name'] ?? $data['name'] ?? null,
            'phone_hash' => isset($data['phone']) ? hash('sha256', $data['phone']) : null,
            'checkin_date' => $data['checkin_date'] ?? $data['checkinDate'] ?? null
        ];
    }
    
    private static function calculateChanges($oldData, $newData) {
        if (!$oldData || !$newData) return null;
        
        $changes = [];
        $importantFields = ['name', 'phone', 'email', 'checkin_date', 'checkout_date', 'adults', 'children', 'room_id', 'plan_id'];
        
        foreach ($importantFields as $field) {
            $oldValue = $oldData[$field] ?? null;
            $newValue = $newData[$field] ?? null;
            
            if ($oldValue !== $newValue) {
                $changes[$field] = [
                    'from' => $oldValue,
                    'to' => $newValue
                ];
            }
        }
        
        return empty($changes) ? null : $changes;
    }
    
    private static function assessBusinessImpact($action, $oldData, $newData) {
        switch ($action) {
            case 'CREATE':
                return '新規予約作成';
            case 'DELETE':
            case 'CANCEL':
                return '予約キャンセル - 売上影響あり';
            case 'UPDATE':
                if (isset($newData['room_id']) && $oldData['room_id'] !== $newData['room_id']) {
                    return '部屋変更 - オペレーション影響あり';
                }
                return '予約変更';
            default:
                return null;
        }
    }
    
    private static function assessSystemImpact($settingType, $action) {
        $impacts = [
            'ROOMS' => [
                'CREATE' => '低 - 新規部屋追加',
                'UPDATE' => '中 - 部屋情報変更',
                'DELETE' => '高 - 部屋削除（予約影響の可能性）'
            ],
            'PLANS' => [
                'CREATE' => '低 - 新規プラン追加',
                'UPDATE' => '中 - プラン変更（料金影響の可能性）',
                'DELETE' => '高 - プラン削除（予約影響の可能性）'
            ],
            'SYSTEM' => [
                'UPDATE' => '高 - システム設定変更'
            ]
        ];
        
        return $impacts[$settingType][$action] ?? '不明';
    }
    
    private static function isHighRiskOperation($action, $oldData, $newData) {
        // 高リスク操作の判定
        $highRiskActions = ['DELETE', 'CANCEL'];
        
        if (in_array($action, $highRiskActions)) {
            return true;
        }
        
        // 大量予約の変更
        if ($action === 'UPDATE' && isset($newData['adults'], $oldData['adults'])) {
            $guestDiff = abs($newData['adults'] - $oldData['adults']);
            if ($guestDiff >= 5) {
                return true;
            }
        }
        
        return false;
    }
    
    private static function getSecuritySeverity($eventType) {
        $severityMap = [
            'SUSPICIOUS_ACCESS' => 'MEDIUM',
            'BRUTE_FORCE' => 'HIGH',
            'PRIVILEGE_ESCALATION' => 'CRITICAL',
            'UNAUTHORIZED_API_ACCESS' => 'HIGH',
            'DATA_EXPORT_LARGE' => 'MEDIUM'
        ];
        
        return $severityMap[$eventType] ?? 'LOW';
    }
    
    private static function assessThreatLevel($eventType, $details) {
        // 脅威レベルの算出ロジック
        $baseLevel = 1;
        
        if (isset($details['repeated_attempts']) && $details['repeated_attempts'] > 5) {
            $baseLevel += 2;
        }
        
        if (isset($details['privilege_level']) && $details['privilege_level'] === 'admin') {
            $baseLevel += 1;
        }
        
        return min($baseLevel, 5); // 1-5スケール
    }
    
    private static function getRecommendedAction($eventType) {
        $actions = [
            'SUSPICIOUS_ACCESS' => 'IP監視強化、アクセスパターン分析',
            'BRUTE_FORCE' => 'IP即座ブロック、アカウントロック確認',
            'PRIVILEGE_ESCALATION' => '緊急対応、管理者権限見直し',
            'UNAUTHORIZED_API_ACCESS' => 'APIトークン無効化、アクセス制限強化'
        ];
        
        return $actions[$eventType] ?? '通常監視継続';
    }
    
    private static function sanitizeSearchParams($params) {
        // 個人情報を含む検索パラメータをハッシュ化
        $sanitized = $params;
        
        if (isset($sanitized['search']) && !empty($sanitized['search'])) {
            $sanitized['search_hash'] = hash('sha256', $sanitized['search']);
            unset($sanitized['search']); // 実際の検索語は記録しない
        }
        
        return $sanitized;
    }
    
    private static function sendSecurityAlert($eventType, $data) {
        // 実装例：管理者メール通知、Slack通知など
        $alertMessage = "セキュリティアラート: $eventType\n";
        $alertMessage .= "発生時刻: " . date('Y-m-d H:i:s') . "\n";
        $alertMessage .= "詳細: " . json_encode($data, JSON_UNESCAPED_UNICODE);
        
        error_log("SECURITY_ALERT: " . $alertMessage);
        
        // 将来的にはメール送信やSlack通知を追加
    }
} 