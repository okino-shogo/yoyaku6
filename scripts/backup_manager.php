#!/usr/bin/env php
<?php
/**
 * データベースバックアップ暗号化管理システム
 * 自動バックアップ・暗号化・復元機能
 */

require_once __DIR__ . '/../config/database.php';

class BackupManager {
    
    private $backupDir;
    private $encryptionKey;
    private $retentionDays;
    
    public function __construct() {
        $this->backupDir = $_ENV['BACKUP_DIR'] ?? __DIR__ . '/../backups';
        $this->encryptionKey = $this->getEncryptionKey();
        $this->retentionDays = (int)($_ENV['BACKUP_RETENTION_DAYS'] ?? 30);
        
        // バックアップディレクトリ作成
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0700, true);
        }
    }
    
    /**
     * 暗号化キー取得
     * @return string
     */
    private function getEncryptionKey() {
        $key = $_ENV['BACKUP_ENCRYPTION_KEY'] ?? '';
        
        if (empty($key)) {
            throw new Exception('BACKUP_ENCRYPTION_KEY環境変数が設定されていません');
        }
        
        return hash('sha256', $key, true);
    }
    
    /**
     * データベースバックアップ作成
     * @param bool $encrypt 暗号化するかどうか
     * @return array バックアップ結果
     */
    public function createBackup($encrypt = true) {
        $timestamp = date('Y-m-d_H-i-s');
        $dbConfig = $this->getDatabaseConfig();
        
        $sqlFile = $this->backupDir . "/backup_{$timestamp}.sql";
        $compressedFile = $sqlFile . '.gz';
        $encryptedFile = $compressedFile . '.enc';
        
        try {
            // MySQLダンプ実行
            $this->createMysqlDump($dbConfig, $sqlFile);
            
            if (!file_exists($sqlFile)) {
                throw new Exception('SQLダンプファイルの作成に失敗しました');
            }
            
            $sqlSize = filesize($sqlFile);
            echo "SQLダンプ作成完了: " . $this->formatBytes($sqlSize) . "\n";
            
            // GZIP圧縮
            $this->compressFile($sqlFile, $compressedFile);
            $compressedSize = filesize($compressedFile);
            echo "圧縮完了: " . $this->formatBytes($compressedSize) . " (圧縮率: " . 
                 round((1 - $compressedSize / $sqlSize) * 100, 1) . "%)\n";
            
            // 元のSQLファイルを削除
            unlink($sqlFile);
            
            if ($encrypt) {
                // 暗号化
                $this->encryptFile($compressedFile, $encryptedFile);
                $encryptedSize = filesize($encryptedFile);
                echo "暗号化完了: " . $this->formatBytes($encryptedSize) . "\n";
                
                // 圧縮ファイルを削除
                unlink($compressedFile);
                $finalFile = $encryptedFile;
                $finalSize = $encryptedSize;
            } else {
                $finalFile = $compressedFile;
                $finalSize = $compressedSize;
            }
            
            // チェックサム計算
            $checksum = hash_file('sha256', $finalFile);
            
            // メタデータファイル作成
            $metaFile = $finalFile . '.meta';
            $metadata = [
                'backup_date' => date('Y-m-d H:i:s'),
                'database' => $dbConfig['database'],
                'encrypted' => $encrypt,
                'original_size' => $sqlSize,
                'compressed_size' => $encrypt ? $compressedSize : null,
                'final_size' => $finalSize,
                'checksum' => $checksum,
                'compression_ratio' => round((1 - $finalSize / $sqlSize) * 100, 1),
                'retention_until' => date('Y-m-d', strtotime("+{$this->retentionDays} days"))
            ];
            
            file_put_contents($metaFile, json_encode($metadata, JSON_PRETTY_PRINT));
            
            echo "バックアップ完了: $finalFile\n";
            echo "チェックサム: $checksum\n";
            
            return [
                'success' => true,
                'file' => $finalFile,
                'metadata' => $metadata
            ];
            
        } catch (Exception $e) {
            // エラー時のクリーンアップ
            foreach ([$sqlFile, $compressedFile, $encryptedFile] as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
            
            throw $e;
        }
    }
    
    /**
     * バックアップファイル復元
     * @param string $backupFile バックアップファイルパス
     * @return array 復元結果
     */
    public function restoreBackup($backupFile) {
        if (!file_exists($backupFile)) {
            throw new Exception("バックアップファイルが見つかりません: $backupFile");
        }
        
        $metaFile = $backupFile . '.meta';
        $metadata = [];
        
        if (file_exists($metaFile)) {
            $metadata = json_decode(file_get_contents($metaFile), true);
            
            // チェックサム検証
            $currentChecksum = hash_file('sha256', $backupFile);
            if ($currentChecksum !== $metadata['checksum']) {
                throw new Exception('バックアップファイルのチェックサムが一致しません。ファイルが破損している可能性があります。');
            }
        }
        
        $timestamp = date('Y-m-d_H-i-s');
        $tempDir = sys_get_temp_dir() . "/restore_$timestamp";
        mkdir($tempDir, 0700);
        
        try {
            $isEncrypted = isset($metadata['encrypted']) ? $metadata['encrypted'] : 
                          (pathinfo($backupFile, PATHINFO_EXTENSION) === 'enc');
            
            if ($isEncrypted) {
                // 復号化
                $decryptedFile = $tempDir . '/backup.sql.gz';
                $this->decryptFile($backupFile, $decryptedFile);
                echo "復号化完了\n";
            } else {
                $decryptedFile = $backupFile;
            }
            
            // 展開
            $sqlFile = $tempDir . '/backup.sql';
            $this->decompressFile($decryptedFile, $sqlFile);
            echo "展開完了\n";
            
            // データベース復元
            $dbConfig = $this->getDatabaseConfig();
            $this->restoreMysqlDump($dbConfig, $sqlFile);
            echo "データベース復元完了\n";
            
            // 一時ファイル削除
            $this->cleanupDirectory($tempDir);
            
            return [
                'success' => true,
                'metadata' => $metadata,
                'message' => 'バックアップの復元が完了しました'
            ];
            
        } catch (Exception $e) {
            // エラー時のクリーンアップ
            if (is_dir($tempDir)) {
                $this->cleanupDirectory($tempDir);
            }
            
            throw $e;
        }
    }
    
    /**
     * MySQLダンプ作成
     * @param array $config データベース設定
     * @param string $outputFile 出力ファイル
     */
    private function createMysqlDump($config, $outputFile) {
        $command = sprintf(
            'mysqldump --single-transaction --routines --triggers --lock-tables=false -h%s -P%s -u%s -p%s %s > %s 2>&1',
            escapeshellarg($config['host']),
            escapeshellarg($config['port']),
            escapeshellarg($config['username']),
            escapeshellarg($config['password']),
            escapeshellarg($config['database']),
            escapeshellarg($outputFile)
        );
        
        echo "MySQLダンプ実行中...\n";
        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new Exception('mysqldumpコマンドが失敗しました: ' . implode("\n", $output));
        }
    }
    
    /**
     * MySQLダンプ復元
     * @param array $config データベース設定
     * @param string $sqlFile SQLファイル
     */
    private function restoreMysqlDump($config, $sqlFile) {
        echo "データベースに復元中...\n";
        echo "警告: 既存のデータは上書きされます。\n";
        
        // 確認プロンプト（CLI実行時のみ）
        if (php_sapi_name() === 'cli') {
            echo "続行しますか？ (yes/no): ";
            $handle = fopen("php://stdin", "r");
            $confirmation = trim(fgets($handle));
            fclose($handle);
            
            if (strtolower($confirmation) !== 'yes') {
                throw new Exception('復元がキャンセルされました');
            }
        }
        
        $command = sprintf(
            'mysql -h%s -P%s -u%s -p%s %s < %s 2>&1',
            escapeshellarg($config['host']),
            escapeshellarg($config['port']),
            escapeshellarg($config['username']),
            escapeshellarg($config['password']),
            escapeshellarg($config['database']),
            escapeshellarg($sqlFile)
        );
        
        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new Exception('mysql復元コマンドが失敗しました: ' . implode("\n", $output));
        }
    }
    
    /**
     * ファイル暗号化
     * @param string $inputFile 入力ファイル
     * @param string $outputFile 出力ファイル
     */
    private function encryptFile($inputFile, $outputFile) {
        $iv = random_bytes(16);
        $tag = '';
        
        $inputHandle = fopen($inputFile, 'rb');
        $outputHandle = fopen($outputFile, 'wb');
        
        // IVを最初に書き込み
        fwrite($outputHandle, $iv);
        
        $ctx = hash_init('sha256', HASH_HMAC, $this->encryptionKey);
        
        while (!feof($inputHandle)) {
            $chunk = fread($inputHandle, 8192);
            
            if ($chunk !== false && strlen($chunk) > 0) {
                $encrypted = openssl_encrypt($chunk, 'aes-256-ctr', $this->encryptionKey, 
                                           OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
                fwrite($outputHandle, $encrypted);
                hash_update($ctx, $chunk);
                
                // CTRモードでIVをインクリメント
                $iv = $this->incrementIv($iv);
            }
        }
        
        // HMAC認証タグを最後に追加
        $hmac = hash_final($ctx, true);
        fwrite($outputHandle, $hmac);
        
        fclose($inputHandle);
        fclose($outputHandle);
    }
    
    /**
     * ファイル復号化
     * @param string $inputFile 暗号化ファイル
     * @param string $outputFile 出力ファイル
     */
    private function decryptFile($inputFile, $outputFile) {
        $inputHandle = fopen($inputFile, 'rb');
        $outputHandle = fopen($outputFile, 'wb');
        
        // IVを読み取り
        $iv = fread($inputHandle, 16);
        
        $ctx = hash_init('sha256', HASH_HMAC, $this->encryptionKey);
        $fileSize = filesize($inputFile);
        $dataSize = $fileSize - 16 - 32; // ファイルサイズ - IV - HMAC
        $bytesRead = 0;
        
        while ($bytesRead < $dataSize) {
            $chunkSize = min(8192, $dataSize - $bytesRead);
            $chunk = fread($inputHandle, $chunkSize);
            
            if ($chunk !== false && strlen($chunk) > 0) {
                $decrypted = openssl_decrypt($chunk, 'aes-256-ctr', $this->encryptionKey, 
                                           OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
                fwrite($outputHandle, $decrypted);
                hash_update($ctx, $decrypted);
                
                $iv = $this->incrementIv($iv);
                $bytesRead += strlen($chunk);
            }
        }
        
        // HMAC検証
        $expectedHmac = fread($inputHandle, 32);
        $actualHmac = hash_final($ctx, true);
        
        if (!hash_equals($expectedHmac, $actualHmac)) {
            fclose($inputHandle);
            fclose($outputHandle);
            unlink($outputFile);
            throw new Exception('ファイルの整合性チェックに失敗しました');
        }
        
        fclose($inputHandle);
        fclose($outputHandle);
    }
    
    /**
     * CTRモード用IV増分
     * @param string $iv
     * @return string
     */
    private function incrementIv($iv) {
        $counter = unpack('N4', $iv);
        $counter[4]++;
        
        for ($i = 4; $i >= 1; $i--) {
            if ($counter[$i] > 0xFFFFFFFF) {
                $counter[$i] = 0;
                if ($i > 1) $counter[$i-1]++;
            } else {
                break;
            }
        }
        
        return pack('N4', $counter[1], $counter[2], $counter[3], $counter[4]);
    }
    
    /**
     * ファイル圧縮
     * @param string $inputFile
     * @param string $outputFile
     */
    private function compressFile($inputFile, $outputFile) {
        $inputHandle = fopen($inputFile, 'rb');
        $outputHandle = gzopen($outputFile, 'wb9');
        
        while (!feof($inputHandle)) {
            $chunk = fread($inputHandle, 8192);
            if ($chunk !== false) {
                gzwrite($outputHandle, $chunk);
            }
        }
        
        fclose($inputHandle);
        gzclose($outputHandle);
    }
    
    /**
     * ファイル展開
     * @param string $inputFile
     * @param string $outputFile
     */
    private function decompressFile($inputFile, $outputFile) {
        $inputHandle = gzopen($inputFile, 'rb');
        $outputHandle = fopen($outputFile, 'wb');
        
        while (!gzeof($inputHandle)) {
            $chunk = gzread($inputHandle, 8192);
            if ($chunk !== false) {
                fwrite($outputHandle, $chunk);
            }
        }
        
        gzclose($inputHandle);
        fclose($outputHandle);
    }
    
    /**
     * 古いバックアップファイル削除
     * @return array 削除結果
     */
    public function cleanupOldBackups() {
        $cutoffDate = strtotime("-{$this->retentionDays} days");
        $deleted = [];
        $errors = [];
        
        $files = glob($this->backupDir . '/backup_*.{sql.gz.enc,sql.gz}', GLOB_BRACE);
        
        foreach ($files as $file) {
            $metaFile = $file . '.meta';
            
            if (file_exists($metaFile)) {
                $metadata = json_decode(file_get_contents($metaFile), true);
                $backupDate = strtotime($metadata['backup_date']);
                
                if ($backupDate < $cutoffDate) {
                    try {
                        unlink($file);
                        unlink($metaFile);
                        $deleted[] = basename($file);
                    } catch (Exception $e) {
                        $errors[] = "削除失敗: " . basename($file) . " - " . $e->getMessage();
                    }
                }
            } else {
                // メタファイルがない古いファイル
                if (filemtime($file) < $cutoffDate) {
                    try {
                        unlink($file);
                        $deleted[] = basename($file);
                    } catch (Exception $e) {
                        $errors[] = "削除失敗: " . basename($file) . " - " . $e->getMessage();
                    }
                }
            }
        }
        
        return ['deleted' => $deleted, 'errors' => $errors];
    }
    
    /**
     * バックアップ一覧取得
     * @return array
     */
    public function listBackups() {
        $backups = [];
        $files = glob($this->backupDir . '/backup_*.{sql.gz.enc,sql.gz}', GLOB_BRACE);
        
        foreach ($files as $file) {
            $metaFile = $file . '.meta';
            
            $backup = [
                'file' => basename($file),
                'path' => $file,
                'size' => filesize($file),
                'created' => date('Y-m-d H:i:s', filemtime($file))
            ];
            
            if (file_exists($metaFile)) {
                $metadata = json_decode(file_get_contents($metaFile), true);
                $backup = array_merge($backup, $metadata);
            }
            
            $backups[] = $backup;
        }
        
        // 作成日時でソート（新しい順）
        usort($backups, function($a, $b) {
            return strtotime($b['created']) - strtotime($a['created']);
        });
        
        return $backups;
    }
    
    /**
     * データベース設定取得
     * @return array
     */
    private function getDatabaseConfig() {
        // database.phpから設定を取得
        $pdo = getDatabase();
        
        // PDOから接続情報を抽出する方法が限られているため、環境変数から取得
        return [
            'host' => $_ENV['DB_HOST'] ?? 'localhost',
            'port' => $_ENV['DB_PORT'] ?? '3306',
            'database' => $_ENV['DB_NAME'] ?? 'yoyaku_system',
            'username' => $_ENV['DB_USER'] ?? 'root',
            'password' => $_ENV['DB_PASS'] ?? ''
        ];
    }
    
    /**
     * ディレクトリクリーンアップ
     * @param string $dir
     */
    private function cleanupDirectory($dir) {
        if (is_dir($dir)) {
            $files = array_diff(scandir($dir), ['.', '..']);
            foreach ($files as $file) {
                $filePath = $dir . '/' . $file;
                is_dir($filePath) ? $this->cleanupDirectory($filePath) : unlink($filePath);
            }
            rmdir($dir);
        }
    }
    
    /**
     * バイト数フォーマット
     * @param int $bytes
     * @return string
     */
    private function formatBytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}

// CLI実行時のコマンドライン処理
if (php_sapi_name() === 'cli') {
    $action = $argv[1] ?? 'help';
    $backup = new BackupManager();
    
    switch ($action) {
        case 'create':
            try {
                echo "データベースバックアップを作成中...\n";
                $result = $backup->createBackup(true);
                echo "成功: バックアップが作成されました\n";
                echo "ファイル: " . $result['file'] . "\n";
            } catch (Exception $e) {
                echo "エラー: " . $e->getMessage() . "\n";
                exit(1);
            }
            break;
            
        case 'restore':
            $file = $argv[2] ?? '';
            if (empty($file)) {
                echo "使用法: php backup_manager.php restore <backup_file>\n";
                exit(1);
            }
            
            try {
                echo "バックアップを復元中...\n";
                $result = $backup->restoreBackup($file);
                echo "成功: " . $result['message'] . "\n";
            } catch (Exception $e) {
                echo "エラー: " . $e->getMessage() . "\n";
                exit(1);
            }
            break;
            
        case 'list':
            try {
                $backups = $backup->listBackups();
                echo "利用可能なバックアップ:\n";
                echo str_repeat("-", 80) . "\n";
                printf("%-30s %-20s %-15s %-10s\n", "ファイル名", "作成日時", "サイズ", "暗号化");
                echo str_repeat("-", 80) . "\n";
                
                foreach ($backups as $b) {
                    printf("%-30s %-20s %-15s %-10s\n", 
                        $b['file'], 
                        $b['created'], 
                        $backup->formatBytes($b['size']),
                        isset($b['encrypted']) && $b['encrypted'] ? '有' : '無'
                    );
                }
            } catch (Exception $e) {
                echo "エラー: " . $e->getMessage() . "\n";
                exit(1);
            }
            break;
            
        case 'cleanup':
            try {
                echo "古いバックアップを削除中...\n";
                $result = $backup->cleanupOldBackups();
                echo "削除されたファイル: " . count($result['deleted']) . "件\n";
                foreach ($result['deleted'] as $file) {
                    echo "  削除: $file\n";
                }
                if (!empty($result['errors'])) {
                    echo "エラー:\n";
                    foreach ($result['errors'] as $error) {
                        echo "  $error\n";
                    }
                }
            } catch (Exception $e) {
                echo "エラー: " . $e->getMessage() . "\n";
                exit(1);
            }
            break;
            
        case 'help':
        default:
            echo "予約システム バックアップ管理ツール\n\n";
            echo "使用法:\n";
            echo "  php backup_manager.php create          - 新しいバックアップを作成\n";
            echo "  php backup_manager.php restore <file>  - バックアップを復元\n";
            echo "  php backup_manager.php list            - バックアップ一覧表示\n";
            echo "  php backup_manager.php cleanup         - 古いバックアップを削除\n";
            echo "  php backup_manager.php help            - このヘルプを表示\n\n";
            echo "環境変数:\n";
            echo "  BACKUP_DIR              - バックアップ保存ディレクトリ\n";
            echo "  BACKUP_ENCRYPTION_KEY   - バックアップ暗号化キー（必須）\n";
            echo "  BACKUP_RETENTION_DAYS   - バックアップ保持日数（デフォルト: 30日）\n";
            break;
    }
} 