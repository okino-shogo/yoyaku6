<?php
/**
 * Google reCAPTCHA v3 設定と検証機能
 * ボット対策・スパム防止
 */

class CaptchaService {
    
    // reCAPTCHA設定（環境変数から取得）
    private static function getSiteKey() {
        return $_ENV['RECAPTCHA_SITE_KEY'] ?? '';
    }
    
    private static function getSecretKey() {
        return $_ENV['RECAPTCHA_SECRET_KEY'] ?? '';
    }
    
    /**
     * reCAPTCHA トークン検証
     * @param string $token クライアントから送信されたトークン
     * @param string $action 実行アクション（'reservation', 'login'等）
     * @param float $scoreThreshold スコア閾値（デフォルト: 0.5）
     * @return array 検証結果
     */
    public static function verifyToken($token, $action = 'reservation', $scoreThreshold = 0.5) {
        $secretKey = self::getSecretKey();
        
        if (empty($secretKey) || empty($token)) {
            return [
                'success' => false,
                'error' => 'CAPTCHA設定エラー',
                'score' => 0.0
            ];
        }
        
        // Google reCAPTCHA API呼び出し
        $verifyUrl = 'https://www.google.com/recaptcha/api/siteverify';
        $postData = [
            'secret' => $secretKey,
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $verifyUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'YoyakuSystem/1.0'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError || $httpCode !== 200) {
            error_log("reCAPTCHA API Error: HTTP $httpCode, cURL: $curlError");
            return [
                'success' => false,
                'error' => 'CAPTCHA検証通信エラー',
                'score' => 0.0
            ];
        }
        
        $result = json_decode($response, true);
        
        if (!$result) {
            return [
                'success' => false,
                'error' => 'CAPTCHA応答解析エラー',
                'score' => 0.0
            ];
        }
        
        // 基本検証
        if (!$result['success']) {
            $errorCodes = $result['error-codes'] ?? ['unknown-error'];
            error_log("reCAPTCHA Verification Failed: " . implode(', ', $errorCodes));
            
            return [
                'success' => false,
                'error' => 'CAPTCHA検証失敗',
                'error_codes' => $errorCodes,
                'score' => 0.0
            ];
        }
        
        // アクション検証
        if (isset($result['action']) && $result['action'] !== $action) {
            return [
                'success' => false,
                'error' => 'CAPTCHAアクション不一致',
                'score' => $result['score'] ?? 0.0
            ];
        }
        
        // スコア検証
        $score = $result['score'] ?? 0.0;
        $scorePass = $score >= $scoreThreshold;
        
        // 結果ログ記録
        $logLevel = $scorePass ? 'INFO' : 'WARNING';
        error_log("[$logLevel] reCAPTCHA: action=$action, score=$score, threshold=$scoreThreshold, pass=" . ($scorePass ? 'true' : 'false'));
        
        return [
            'success' => $scorePass,
            'score' => $score,
            'action' => $result['action'] ?? '',
            'timestamp' => $result['challenge_ts'] ?? '',
            'hostname' => $result['hostname'] ?? '',
            'threshold_met' => $scorePass,
            'error' => $scorePass ? null : 'CAPTCHAスコアが閾値を下回りました'
        ];
    }
    
    /**
     * CAPTCHAスクリプトタグ生成
     * @return string HTMLスクリプトタグ
     */
    public static function getScriptTag() {
        $siteKey = self::getSiteKey();
        
        if (empty($siteKey)) {
            return '<!-- reCAPTCHA設定エラー -->';
        }
        
        return sprintf(
            '<script src="https://www.google.com/recaptcha/api.js?render=%s"></script>',
            htmlspecialchars($siteKey, ENT_QUOTES)
        );
    }
    
    /**
     * CAPTCHAトークン取得JavaScript生成
     * @param string $action アクション名
     * @param string $callback コールバック関数名
     * @return string JavaScript コード
     */
    public static function getTokenScript($action = 'reservation', $callback = 'setCaptchaToken') {
        $siteKey = self::getSiteKey();
        
        if (empty($siteKey)) {
            return '// reCAPTCHA設定エラー';
        }
        
        return sprintf(
            "
            function getCaptchaToken() {
                return new Promise((resolve, reject) => {
                    grecaptcha.ready(function() {
                        grecaptcha.execute('%s', {action: '%s'}).then(function(token) {
                            resolve(token);
                        }).catch(reject);
                    });
                });
            }
            
            async function %s(token) {
                const hiddenInput = document.getElementById('recaptcha_token');
                if (hiddenInput) {
                    hiddenInput.value = token || await getCaptchaToken();
                }
            }
            ",
            $siteKey,
            $action,
            $callback
        );
    }
    
    /**
     * アクション別スコア閾値設定
     * @param string $action アクション名
     * @return float スコア閾値
     */
    public static function getScoreThreshold($action) {
        $thresholds = [
            'reservation' => 0.5,    // 予約フォーム - 中程度
            'login' => 0.3,          // ログイン - 低め（利便性重視）
            'admin_action' => 0.7,   // 管理操作 - 高め
            'contact' => 0.4,        // お問い合わせ - やや低め
            'search' => 0.3          // 検索 - 低め
        ];
        
        return $thresholds[$action] ?? 0.5;
    }
    
    /**
     * CAPTCHA統計情報取得
     * @param int $days 対象日数
     * @return array 統計データ
     */
    public static function getCaptchaStats($days = 7) {
        // 実装例：ログファイルから統計を集計
        $logFile = '/var/log/recaptcha.log';
        
        if (!file_exists($logFile)) {
            return [
                'total_verifications' => 0,
                'successful_verifications' => 0,
                'average_score' => 0.0,
                'blocked_attempts' => 0
            ];
        }
        
        // 簡易統計（実際の実装ではより詳細な分析を行う）
        $recentLogs = shell_exec("tail -1000 $logFile | grep reCAPTCHA");
        $lines = explode("\n", $recentLogs);
        
        $total = 0;
        $successful = 0;
        $scoreSum = 0.0;
        $blocked = 0;
        
        foreach ($lines as $line) {
            if (strpos($line, 'reCAPTCHA:') !== false) {
                $total++;
                
                if (strpos($line, 'pass=true') !== false) {
                    $successful++;
                }
                
                if (strpos($line, 'pass=false') !== false) {
                    $blocked++;
                }
                
                // スコア抽出（正規表現使用）
                if (preg_match('/score=([\d.]+)/', $line, $matches)) {
                    $scoreSum += floatval($matches[1]);
                }
            }
        }
        
        return [
            'total_verifications' => $total,
            'successful_verifications' => $successful,
            'average_score' => $total > 0 ? round($scoreSum / $total, 3) : 0.0,
            'blocked_attempts' => $blocked,
            'success_rate' => $total > 0 ? round(($successful / $total) * 100, 1) : 0.0
        ];
    }
    
    /**
     * 開発環境用のCAPTCHAバイパス
     * @return bool バイパス有効かどうか
     */
    public static function isDevelopmentBypass() {
        return isset($_ENV['CAPTCHA_BYPASS']) && $_ENV['CAPTCHA_BYPASS'] === 'true';
    }
    
    /**
     * CAPTCHA必須チェック
     * @param string $action アクション名
     * @param string $token CAPTCHAトークン
     * @return array チェック結果
     */
    public static function requireCaptcha($action, $token) {
        // 開発環境バイパス
        if (self::isDevelopmentBypass()) {
            return [
                'success' => true,
                'score' => 1.0,
                'bypass' => true
            ];
        }
        
        // CAPTCHAトークンが空の場合
        if (empty($token)) {
            return [
                'success' => false,
                'error' => 'CAPTCHAトークンが必要です',
                'score' => 0.0
            ];
        }
        
        $threshold = self::getScoreThreshold($action);
        return self::verifyToken($token, $action, $threshold);
    }
} 