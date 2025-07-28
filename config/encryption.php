<?php
/**
 * 個人情報暗号化クラス
 * AES-256-GCM暗号化による個人データ保護
 */

class PersonalDataCrypto {
    private static $algorithm = 'aes-256-gcm';
    private static $key = null;
    
    /**
     * 暗号化キーの取得
     */
    private static function getKey() {
        if (self::$key === null) {
            // 環境変数から暗号化キーを取得
            $keySource = $_ENV['PERSONAL_DATA_KEY'] ?? 'yoyaku_system_default_key_2025';
            self::$key = hash('sha256', $keySource, true);
        }
        return self::$key;
    }
    
    /**
     * データ暗号化
     * @param string $plaintext 平文データ
     * @return string|false 暗号化されたデータ（Base64エンコード済み）
     */
    public static function encrypt($plaintext) {
        if (empty($plaintext)) {
            return $plaintext; // 空の場合はそのまま返す
        }
        
        try {
            $key = self::getKey();
            $iv = random_bytes(12); // GCM用IV（12バイト推奨）
            $tag = '';
            
            $ciphertext = openssl_encrypt(
                $plaintext, 
                self::$algorithm, 
                $key, 
                OPENSSL_RAW_DATA, 
                $iv, 
                $tag
            );
            
            if ($ciphertext === false) {
                throw new Exception('暗号化に失敗しました');
            }
            
            // IV + Tag + Ciphertext を結合してBase64エンコード
            return base64_encode($iv . $tag . $ciphertext);
            
        } catch (Exception $e) {
            error_log("Encryption error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * データ復号化
     * @param string $encryptedData 暗号化されたデータ（Base64エンコード済み）
     * @return string|false 復号化されたデータ
     */
    public static function decrypt($encryptedData) {
        if (empty($encryptedData)) {
            return $encryptedData; // 空の場合はそのまま返す
        }
        
        try {
            $data = base64_decode($encryptedData);
            if ($data === false) {
                throw new Exception('Base64デコードに失敗しました');
            }
            
            // データ長チェック
            if (strlen($data) < 28) { // IV(12) + Tag(16) = 28バイト最小
                throw new Exception('暗号化データが不正です');
            }
            
            $iv = substr($data, 0, 12);
            $tag = substr($data, 12, 16);
            $ciphertext = substr($data, 28);
            
            $key = self::getKey();
            
            $plaintext = openssl_decrypt(
                $ciphertext,
                self::$algorithm,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );
            
            if ($plaintext === false) {
                throw new Exception('復号化に失敗しました');
            }
            
            return $plaintext;
            
        } catch (Exception $e) {
            error_log("Decryption error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 検索可能ハッシュ生成
     * 部分一致検索のためのハッシュ値を生成
     * @param string $data 元データ
     * @return string ハッシュ値
     */
    public static function searchableHash($data) {
        if (empty($data)) {
            return '';
        }
        
        // 正規化（小文字化、空白除去）してハッシュ化
        $normalized = strtolower(trim(preg_replace('/\s+/', '', $data)));
        return hash('sha256', $normalized);
    }
    
    /**
     * 顧客データの暗号化
     * @param array $customerData 顧客データ配列
     * @return array 暗号化済み顧客データ
     */
    public static function encryptCustomerData($customerData) {
        $encryptedData = $customerData;
        
        // 暗号化対象フィールド
        $fieldsToEncrypt = ['name', 'phone', 'email', 'address'];
        
        foreach ($fieldsToEncrypt as $field) {
            if (isset($customerData[$field])) {
                $encryptedData[$field . '_encrypted'] = self::encrypt($customerData[$field]);
                $encryptedData[$field . '_hash'] = self::searchableHash($customerData[$field]);
                // 元データは削除（デバッグ時以外）
                unset($encryptedData[$field]);
            }
        }
        
        return $encryptedData;
    }
    
    /**
     * 顧客データの復号化
     * @param array $encryptedCustomerData 暗号化済み顧客データ
     * @return array 復号化済み顧客データ
     */
    public static function decryptCustomerData($encryptedCustomerData) {
        $decryptedData = $encryptedCustomerData;
        
        // 復号化対象フィールド
        $fieldsToDecrypt = ['name', 'phone', 'email', 'address'];
        
        foreach ($fieldsToDecrypt as $field) {
            $encryptedField = $field . '_encrypted';
            if (isset($encryptedCustomerData[$encryptedField])) {
                $decryptedValue = self::decrypt($encryptedCustomerData[$encryptedField]);
                if ($decryptedValue !== false) {
                    $decryptedData[$field] = $decryptedValue;
                }
                // 暗号化フィールドとハッシュは表示用データから除去
                unset($decryptedData[$encryptedField]);
                unset($decryptedData[$field . '_hash']);
            }
        }
        
        return $decryptedData;
    }
    
    /**
     * 検索用データマッチング
     * @param string $searchTerm 検索語
     * @param array $customerData 顧客データ（暗号化済み）
     * @return bool マッチするかどうか
     */
    public static function searchMatch($searchTerm, $customerData) {
        if (empty($searchTerm)) {
            return true;
        }
        
        $searchHash = self::searchableHash($searchTerm);
        $fieldsToSearch = ['name_hash', 'phone_hash', 'email_hash'];
        
        foreach ($fieldsToSearch as $field) {
            if (isset($customerData[$field]) && $customerData[$field] === $searchHash) {
                return true;
            }
        }
        
        return false;
    }
} 