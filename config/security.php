<?php
/**
 * セキュリティ関数ライブラリ
 * XSS対策、出力エスケープ、サニタイゼーション機能
 */

class SecurityHelper {
    
    /**
     * HTMLエスケープ関数
     * @param string $str エスケープする文字列
     * @param bool $doubleEncode 二重エンコードを行うかどうか
     * @return string エスケープされた文字列
     */
    public static function h($str, $doubleEncode = false) {
        if ($str === null || $str === '') {
            return $str;
        }
        return htmlspecialchars((string)$str, ENT_QUOTES | ENT_HTML5, 'UTF-8', $doubleEncode);
    }
    
    /**
     * 属性値用エスケープ
     * @param string $str 属性値文字列
     * @return string エスケープされた属性値
     */
    public static function attr($str) {
        if ($str === null || $str === '') {
            return $str;
        }
        return htmlspecialchars((string)$str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    /**
     * URL用エスケープ
     * @param string $url URL文字列
     * @return string エスケープされたURL
     */
    public static function url($url) {
        if ($url === null || $url === '') {
            return $url;
        }
        return htmlspecialchars(filter_var($url, FILTER_SANITIZE_URL), ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * JavaScript用エスケープ
     * @param string $str JavaScript文字列
     * @return string エスケープされた文字列
     */
    public static function js($str) {
        if ($str === null || $str === '') {
            return $str;
        }
        return json_encode((string)$str, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    }
    
    /**
     * CSS用エスケープ
     * @param string $str CSS値文字列
     * @return string エスケープされた文字列
     */
    public static function css($str) {
        if ($str === null || $str === '') {
            return $str;
        }
        return preg_replace('/[^a-zA-Z0-9\-_\s]/', '', (string)$str);
    }
    
    /**
     * 安全なJSONレスポンス生成
     * @param mixed $data レスポンスデータ
     * @param int $statusCode HTTPステータスコード
     * @param array $headers 追加ヘッダー
     */
    public static function sendSecureJsonResponse($data, $statusCode = 200, $headers = []) {
        // セキュリティヘッダーを設定
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        
        // 追加ヘッダーを設定
        foreach ($headers as $name => $value) {
            header("$name: $value");
        }
        
        // JSONエンコード（XSS対策フラグ付き）
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        exit;
    }
    
    /**
     * テキスト入力サニタイゼーション
     * @param string $input 入力文字列
     * @param int $maxLength 最大長
     * @return string サニタイズされた文字列
     */
    public static function sanitizeText($input, $maxLength = 1000) {
        if ($input === null || $input === '') {
            return $input;
        }
        
        // HTMLタグを除去
        $sanitized = strip_tags((string)$input);
        
        // 制御文字を除去（改行・タブは保持）
        $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $sanitized);
        
        // 長さ制限
        if ($maxLength > 0 && mb_strlen($sanitized) > $maxLength) {
            $sanitized = mb_substr($sanitized, 0, $maxLength);
        }
        
        return trim($sanitized);
    }
    
    /**
     * 氏名サニタイゼーション
     * @param string $name 氏名
     * @return string サニタイズされた氏名
     */
    public static function sanitizeName($name) {
        if ($name === null || $name === '') {
            return $name;
        }
        
        // HTMLタグ除去
        $sanitized = strip_tags((string)$name);
        
        // 英数字・ひらがな・カタカナ・漢字・空白のみ許可
        $sanitized = preg_replace('/[^\p{Hiragana}\p{Katakana}\p{Han}a-zA-Z0-9\s　\-]/u', '', $sanitized);
        
        // 連続する空白を単一に
        $sanitized = preg_replace('/\s+/', ' ', $sanitized);
        
        return trim($sanitized);
    }
    
    /**
     * 電話番号サニタイゼーション
     * @param string $phone 電話番号
     * @return string サニタイズされた電話番号
     */
    public static function sanitizePhone($phone) {
        if ($phone === null || $phone === '') {
            return $phone;
        }
        
        // 数字とハイフンのみ許可
        $sanitized = preg_replace('/[^0-9\-]/', '', (string)$phone);
        
        return $sanitized;
    }
    
    /**
     * メールアドレス検証・サニタイゼーション
     * @param string $email メールアドレス
     * @return string|false 有効なメールアドレスまたはfalse
     */
    public static function sanitizeEmail($email) {
        if ($email === null || $email === '') {
            return $email;
        }
        
        $sanitized = filter_var(trim((string)$email), FILTER_SANITIZE_EMAIL);
        
        if (filter_var($sanitized, FILTER_VALIDATE_EMAIL)) {
            return $sanitized;
        }
        
        return false;
    }
    
    /**
     * 住所サニタイゼーション
     * @param string $address 住所
     * @return string サニタイズされた住所
     */
    public static function sanitizeAddress($address) {
        if ($address === null || $address === '') {
            return $address;
        }
        
        // HTMLタグ除去
        $sanitized = strip_tags((string)$address);
        
        // 危険な文字を除去
        $sanitized = preg_replace('/[<>"\'\x00-\x1F\x7F]/', '', $sanitized);
        
        // 長さ制限
        if (mb_strlen($sanitized) > 200) {
            $sanitized = mb_substr($sanitized, 0, 200);
        }
        
        return trim($sanitized);
    }
    
    /**
     * Content Security Policy ヘッダーを設定
     */
    public static function setCSPHeaders() {
        $cspDirectives = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline'", // 将来的にはnonce使用に移行
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "object-src 'none'"
        ];
        
        header("Content-Security-Policy: " . implode('; ', $cspDirectives));
    }
    
    /**
     * 入力データの包括的バリデーション
     * @param array $data 入力データ
     * @param array $rules バリデーションルール
     * @return array バリデーション結果 ['valid' => bool, 'errors' => array, 'sanitized' => array]
     */
    public static function validateAndSanitize($data, $rules) {
        $errors = [];
        $sanitized = [];
        
        foreach ($rules as $field => $rule) {
            $value = $data[$field] ?? null;
            
            // 必須チェック
            if (isset($rule['required']) && $rule['required'] && empty($value)) {
                $errors[$field] = ($rule['label'] ?? $field) . ' は必須項目です';
                continue;
            }
            
            // 値が空の場合はスキップ（非必須項目）
            if (empty($value)) {
                $sanitized[$field] = $value;
                continue;
            }
            
            // タイプ別サニタイゼーション
            switch ($rule['type'] ?? 'text') {
                case 'name':
                    $sanitized[$field] = self::sanitizeName($value);
                    break;
                case 'phone':
                    $sanitized[$field] = self::sanitizePhone($value);
                    break;
                case 'email':
                    $sanitized[$field] = self::sanitizeEmail($value);
                    if ($sanitized[$field] === false) {
                        $errors[$field] = '有効なメールアドレスを入力してください';
                    }
                    break;
                case 'address':
                    $sanitized[$field] = self::sanitizeAddress($value);
                    break;
                case 'text':
                default:
                    $maxLength = $rule['max_length'] ?? 1000;
                    $sanitized[$field] = self::sanitizeText($value, $maxLength);
                    break;
            }
            
            // 長さチェック
            if (isset($rule['min_length']) && mb_strlen($sanitized[$field]) < $rule['min_length']) {
                $errors[$field] = ($rule['label'] ?? $field) . ' は' . $rule['min_length'] . '文字以上で入力してください';
            }
            
            if (isset($rule['max_length']) && mb_strlen($sanitized[$field]) > $rule['max_length']) {
                $errors[$field] = ($rule['label'] ?? $field) . ' は' . $rule['max_length'] . '文字以内で入力してください';
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'sanitized' => $sanitized
        ];
    }
}

// グローバル関数エイリアス（後方互換性）
if (!function_exists('h')) {
    function h($str) {
        return SecurityHelper::h($str);
    }
}

if (!function_exists('sendJsonResponse')) {
    function sendJsonResponse($data, $statusCode = 200) {
        SecurityHelper::sendSecureJsonResponse($data, $statusCode);
    }
} 