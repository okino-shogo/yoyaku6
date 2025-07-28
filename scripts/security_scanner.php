#!/usr/bin/env php
<?php
/**
 * セキュリティ脆弱性スキャナー
 * Webアプリケーション・設定・依存関係の脆弱性チェック
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/audit.php';

class SecurityScanner {
    
    private $results = [];
    private $webRoot;
    private $configDir;
    
    public function __construct() {
        $this->webRoot = __DIR__ . '/..';
        $this->configDir = __DIR__ . '/../config';
    }
    
    /**
     * 包括的セキュリティスキャン実行
     * @return array スキャン結果
     */
    public function runFullScan() {
        echo "🔍 セキュリティスキャンを開始します...\n\n";
        
        $this->results = [
            'scan_date' => date('Y-m-d H:i:s'),
            'scan_duration' => 0,
            'summary' => [
                'critical' => 0,
                'high' => 0,
                'medium' => 0,
                'low' => 0,
                'info' => 0
            ],
            'checks' => []
        ];
        
        $startTime = microtime(true);
        
        // 各種チェック実行
        $this->checkFilePermissions();
        $this->checkDatabaseSecurity();
        $this->checkConfigurationSecurity();
        $this->checkWebServerSecurity();
        $this->checkPHPSecurity();
        $this->checkApplicationSecurity();
        $this->checkDependencies();
        $this->checkBackupSecurity();
        $this->checkLogSecurity();
        $this->checkNetworkSecurity();
        
        $this->results['scan_duration'] = round(microtime(true) - $startTime, 2);
        
        // サマリー更新
        foreach ($this->results['checks'] as $check) {
            foreach ($check['findings'] as $finding) {
                $this->results['summary'][$finding['severity']]++;
            }
        }
        
        echo "\n📊 スキャン完了\n";
        echo "実行時間: {$this->results['scan_duration']}秒\n";
        echo "検出された問題:\n";
        foreach ($this->results['summary'] as $severity => $count) {
            if ($count > 0) {
                $emoji = $this->getSeverityEmoji($severity);
                echo "  $emoji $severity: $count件\n";
            }
        }
        
        return $this->results;
    }
    
    /**
     * ファイル・ディレクトリ権限チェック
     */
    private function checkFilePermissions() {
        echo "📁 ファイル権限チェック...\n";
        
        $findings = [];
        
        $criticalPaths = [
            $this->configDir => 'config',
            $this->webRoot . '/database' => 'database',
            $this->webRoot . '/scripts' => 'scripts',
            $this->webRoot . '/backups' => 'backups'
        ];
        
        foreach ($criticalPaths as $path => $type) {
            if (is_dir($path)) {
                $perms = fileperms($path) & 0777;
                
                // 推奨権限チェック
                $recommendedPerms = [
                    'config' => 0700,
                    'database' => 0700,
                    'scripts' => 0700,
                    'backups' => 0700
                ];
                
                if ($perms > $recommendedPerms[$type]) {
                    $findings[] = [
                        'type' => 'file_permissions',
                        'severity' => 'high',
                        'title' => "ディレクトリ権限が緩すぎます: $path",
                        'description' => sprintf("現在の権限: %o, 推奨権限: %o", $perms, $recommendedPerms[$type]),
                        'remediation' => "chmod " . decoct($recommendedPerms[$type]) . " $path"
                    ];
                }
            }
        }
        
        // 重要ファイルの権限チェック
        $importantFiles = [
            $this->configDir . '/database.php',
            $this->configDir . '/auth.php',
            $this->configDir . '/encryption.php'
        ];
        
        foreach ($importantFiles as $file) {
            if (file_exists($file)) {
                $perms = fileperms($file) & 0777;
                
                if ($perms > 0600) {
                    $findings[] = [
                        'type' => 'file_permissions',
                        'severity' => 'medium',
                        'title' => "設定ファイル権限が緩すぎます: " . basename($file),
                        'description' => sprintf("現在の権限: %o, 推奨権限: 0600", $perms),
                        'remediation' => "chmod 600 $file"
                    ];
                }
            }
        }
        
        $this->results['checks'][] = [
            'category' => 'File Permissions',
            'findings' => $findings
        ];
    }
    
    /**
     * データベースセキュリティチェック
     */
    private function checkDatabaseSecurity() {
        echo "🗄️ データベースセキュリティチェック...\n";
        
        $findings = [];
        
        try {
            $pdo = getDatabase();
            
            // デフォルトアカウントチェック
            $stmt = $pdo->query("SELECT User, Host FROM mysql.user WHERE User IN ('root', '', 'test') AND Host != 'localhost'");
            $dangerousUsers = $stmt->fetchAll();
            
            foreach ($dangerousUsers as $user) {
                $findings[] = [
                    'type' => 'database_users',
                    'severity' => 'high',
                    'title' => "危険なデータベースユーザーが存在します",
                    'description' => "ユーザー: {$user['User']}@{$user['Host']}",
                    'remediation' => "不要なユーザーアカウントを削除してください"
                ];
            }
            
            // パスワードポリシーチェック
            $stmt = $pdo->query("SHOW VARIABLES LIKE 'validate_password%'");
            $passwordSettings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            if (empty($passwordSettings)) {
                $findings[] = [
                    'type' => 'password_policy',
                    'severity' => 'medium',
                    'title' => "パスワード検証プラグインが無効です",
                    'description' => "validate_passwordプラグインが設定されていません",
                    'remediation' => "INSTALL PLUGIN validate_password SONAME 'validate_password.so'"
                ];
            }
            
            // SSL設定チェック
            $stmt = $pdo->query("SHOW VARIABLES LIKE 'have_ssl'");
            $sslStatus = $stmt->fetch();
            
            if ($sslStatus['Value'] !== 'YES') {
                $findings[] = [
                    'type' => 'ssl_config',
                    'severity' => 'high',
                    'title' => "データベースSSLが無効です",
                    'description' => "MySQLでSSL/TLS暗号化が有効になっていません",
                    'remediation' => "MySQL設定でSSLを有効化してください"
                ];
            }
            
        } catch (Exception $e) {
            $findings[] = [
                'type' => 'database_connection',
                'severity' => 'critical',
                'title' => "データベース接続エラー",
                'description' => $e->getMessage(),
                'remediation' => "データベース設定を確認してください"
            ];
        }
        
        $this->results['checks'][] = [
            'category' => 'Database Security',
            'findings' => $findings
        ];
    }
    
    /**
     * 設定セキュリティチェック
     */
    private function checkConfigurationSecurity() {
        echo "⚙️ 設定セキュリティチェック...\n";
        
        $findings = [];
        
        // 環境変数チェック
        $requiredEnvVars = [
            'PERSONAL_DATA_KEY' => 'critical',
            'BACKUP_ENCRYPTION_KEY' => 'high',
            'RECAPTCHA_SECRET_KEY' => 'medium'
        ];
        
        foreach ($requiredEnvVars as $var => $severity) {
            if (empty($_ENV[$var])) {
                $findings[] = [
                    'type' => 'missing_env_var',
                    'severity' => $severity,
                    'title' => "必要な環境変数が設定されていません: $var",
                    'description' => "セキュリティに重要な環境変数が未設定です",
                    'remediation' => "$var環境変数を設定してください"
                ];
            }
        }
        
        // HTTPS設定チェック
        if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') {
            $findings[] = [
                'type' => 'https_config',
                'severity' => 'high',
                'title' => "HTTPS接続が確立されていません",
                'description' => "本番環境ではHTTPS接続が必須です",
                'remediation' => "Webサーバーの SSL/TLS 設定を確認してください"
            ];
        }
        
        // セッション設定チェック
        $sessionConfig = [
            'session.cookie_secure' => '1',
            'session.cookie_httponly' => '1',
            'session.use_strict_mode' => '1'
        ];
        
        foreach ($sessionConfig as $setting => $recommended) {
            $current = ini_get($setting);
            if ($current !== $recommended) {
                $findings[] = [
                    'type' => 'session_config',
                    'severity' => 'medium',
                    'title' => "セッション設定が推奨値と異なります: $setting",
                    'description' => "現在値: $current, 推奨値: $recommended",
                    'remediation' => "php.iniまたはコード内で $setting = $recommended を設定"
                ];
            }
        }
        
        $this->results['checks'][] = [
            'category' => 'Configuration Security',
            'findings' => $findings
        ];
    }
    
    /**
     * Webサーバーセキュリティチェック
     */
    private function checkWebServerSecurity() {
        echo "🌐 Webサーバーセキュリティチェック...\n";
        
        $findings = [];
        
        // セキュリティヘッダーチェック
        $requiredHeaders = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-XSS-Protection' => '1; mode=block',
            'Strict-Transport-Security' => null, // 値は確認しない
            'Content-Security-Policy' => null
        ];
        
        // .htaccessファイルの存在チェック
        $htaccessFile = $this->webRoot . '/.htaccess';
        if (!file_exists($htaccessFile)) {
            $findings[] = [
                'type' => 'missing_htaccess',
                'severity' => 'medium',
                'title' => '.htaccessファイルが存在しません',
                'description' => 'Webサーバー設定の強化が必要です',
                'remediation' => '.htaccessファイルでセキュリティヘッダーを設定してください'
            ];
        } else {
            $htaccessContent = file_get_contents($htaccessFile);
            
            foreach ($requiredHeaders as $header => $expectedValue) {
                if (strpos($htaccessContent, $header) === false) {
                    $findings[] = [
                        'type' => 'missing_security_header',
                        'severity' => 'medium',
                        'title' => "セキュリティヘッダーが設定されていません: $header",
                        'description' => '.htaccessファイルに記載がありません',
                        'remediation' => ".htaccessにHeader always set $header を追加"
                    ];
                }
            }
        }
        
        // 不要ファイルチェック
        $dangerousFiles = [
            '.env', '.env.local', '.env.production',
            'phpinfo.php', 'info.php', 'test.php',
            '.git', '.svn', '.DS_Store',
            'composer.json', 'package.json'
        ];
        
        foreach ($dangerousFiles as $file) {
            $path = $this->webRoot . '/' . $file;
            if (file_exists($path)) {
                $severity = in_array($file, ['.env', '.env.local', '.env.production']) ? 'critical' : 'medium';
                $findings[] = [
                    'type' => 'dangerous_file',
                    'severity' => $severity,
                    'title' => "機密ファイルがWeb公開ディレクトリに存在します: $file",
                    'description' => "このファイルは外部からアクセス可能な場所に配置すべきではありません",
                    'remediation' => "ファイルを削除または非公開ディレクトリに移動してください"
                ];
            }
        }
        
        $this->results['checks'][] = [
            'category' => 'Web Server Security',
            'findings' => $findings
        ];
    }
    
    /**
     * PHPセキュリティチェック
     */
    private function checkPHPSecurity() {
        echo "🐘 PHPセキュリティチェック...\n";
        
        $findings = [];
        
        // PHP設定チェック
        $phpSettings = [
            'expose_php' => ['recommended' => 'Off', 'severity' => 'low'],
            'display_errors' => ['recommended' => 'Off', 'severity' => 'medium'],
            'allow_url_fopen' => ['recommended' => 'Off', 'severity' => 'medium'],
            'allow_url_include' => ['recommended' => 'Off', 'severity' => 'high']
        ];
        
        foreach ($phpSettings as $setting => $config) {
            $current = ini_get($setting);
            if ($current !== $config['recommended']) {
                $findings[] = [
                    'type' => 'php_config',
                    'severity' => $config['severity'],
                    'title' => "PHP設定が推奨値と異なります: $setting",
                    'description' => "現在値: $current, 推奨値: {$config['recommended']}",
                    'remediation' => "php.iniで $setting = {$config['recommended']} を設定"
                ];
            }
        }
        
        // PHPバージョンチェック
        $phpVersion = PHP_VERSION;
        $majorVersion = (int)explode('.', $phpVersion)[0];
        $minorVersion = (int)explode('.', $phpVersion)[1];
        
        if ($majorVersion < 8 || ($majorVersion === 8 && $minorVersion < 1)) {
            $findings[] = [
                'type' => 'php_version',
                'severity' => 'high',
                'title' => "PHPバージョンが古い可能性があります",
                'description' => "現在のバージョン: $phpVersion",
                'remediation' => "PHP 8.1以上にアップグレードすることを推奨します"
            ];
        }
        
        // 危険な関数チェック
        $dangerousFunctions = ['exec', 'system', 'shell_exec', 'passthru', 'eval'];
        foreach ($dangerousFunctions as $func) {
            if (function_exists($func)) {
                $findings[] = [
                    'type' => 'dangerous_function',
                    'severity' => 'medium',
                    'title' => "危険な関数が有効です: $func",
                    'description' => "セキュリティリスクとなる可能性があります",
                    'remediation' => "disable_functions設定で無効化を検討してください"
                ];
            }
        }
        
        $this->results['checks'][] = [
            'category' => 'PHP Security',
            'findings' => $findings
        ];
    }
    
    /**
     * アプリケーションセキュリティチェック
     */
    private function checkApplicationSecurity() {
        echo "🛡️ アプリケーションセキュリティチェック...\n";
        
        $findings = [];
        
        // デフォルトアカウントチェック
        try {
            $pdo = getDatabase();
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = 'admin' AND password_hash = ?");
            $defaultHash = password_hash('admin', PASSWORD_DEFAULT);
            $stmt->execute([$defaultHash]);
            
            // より適切なチェック: よく使われる弱いパスワード
            $weakPasswords = ['admin', 'password', '123456', 'admin123'];
            foreach ($weakPasswords as $weakPass) {
                $stmt = $pdo->prepare("SELECT username FROM users WHERE username = 'admin'");
                $stmt->execute();
                $user = $stmt->fetch();
                
                if ($user && password_verify($weakPass, $user['password_hash'] ?? '')) {
                    $findings[] = [
                        'type' => 'weak_password',
                        'severity' => 'critical',
                        'title' => "デフォルト・弱いパスワードが使用されています",
                        'description' => "adminユーザーが弱いパスワードを使用しています",
                        'remediation' => "強力なパスワードに変更してください"
                    ];
                    break;
                }
            }
            
        } catch (Exception $e) {
            // ユーザーテーブルが存在しない場合は無視
        }
        
        // セッションセキュリティチェック
        if (session_status() === PHP_SESSION_ACTIVE) {
            $sessionCookieParams = session_get_cookie_params();
            
            if (!$sessionCookieParams['secure']) {
                $findings[] = [
                    'type' => 'session_security',
                    'severity' => 'high',
                    'title' => "セッションCookieがSecure属性を使用していません",
                    'description' => "HTTPS環境でセキュアCookieが設定されていません",
                    'remediation' => "session_set_cookie_params()でsecure=trueを設定"
                ];
            }
            
            if (!$sessionCookieParams['httponly']) {
                $findings[] = [
                    'type' => 'session_security',
                    'severity' => 'medium',
                    'title' => "セッションCookieがHttpOnly属性を使用していません",
                    'description' => "XSS攻撃でセッションが盗まれる可能性があります",
                    'remediation' => "session_set_cookie_params()でhttponly=trueを設定"
                ];
            }
        }
        
        // ファイルアップロード設定チェック
        $uploadMaxSize = ini_get('upload_max_filesize');
        $postMaxSize = ini_get('post_max_size');
        
        if ($this->parseSize($uploadMaxSize) > 10 * 1024 * 1024) { // 10MB
            $findings[] = [
                'type' => 'upload_config',
                'severity' => 'medium',
                'title' => "ファイルアップロードサイズ制限が大きすぎます",
                'description' => "現在の制限: $uploadMaxSize",
                'remediation' => "必要最小限のサイズに制限することを推奨します"
            ];
        }
        
        $this->results['checks'][] = [
            'category' => 'Application Security',
            'findings' => $findings
        ];
    }
    
    /**
     * 依存関係セキュリティチェック
     */
    private function checkDependencies() {
        echo "📦 依存関係セキュリティチェック...\n";
        
        $findings = [];
        
        // composer.jsonが存在する場合の脆弱性チェック
        $composerFile = $this->webRoot . '/composer.json';
        if (file_exists($composerFile)) {
            $findings[] = [
                'type' => 'dependency_check',
                'severity' => 'info',
                'title' => "Composer依存関係チェック推奨",
                'description' => "composer.jsonが存在します",
                'remediation' => "composer audit を定期実行して脆弱性をチェックしてください"
            ];
        }
        
        // 必要なPHP拡張チェック
        $requiredExtensions = [
            'openssl' => 'critical',
            'pdo_mysql' => 'critical',
            'curl' => 'high',
            'mbstring' => 'high',
            'redis' => 'medium'
        ];
        
        foreach ($requiredExtensions as $ext => $severity) {
            if (!extension_loaded($ext)) {
                $findings[] = [
                    'type' => 'missing_extension',
                    'severity' => $severity,
                    'title' => "必要なPHP拡張が見つかりません: $ext",
                    'description' => "アプリケーションの動作に影響する可能性があります",
                    'remediation' => "PHP拡張 $ext をインストールしてください"
                ];
            }
        }
        
        $this->results['checks'][] = [
            'category' => 'Dependencies',
            'findings' => $findings
        ];
    }
    
    /**
     * バックアップセキュリティチェック
     */
    private function checkBackupSecurity() {
        echo "💾 バックアップセキュリティチェック...\n";
        
        $findings = [];
        
        $backupDir = $_ENV['BACKUP_DIR'] ?? $this->webRoot . '/backups';
        
        if (is_dir($backupDir)) {
            // バックアップディレクトリ権限チェック
            $perms = fileperms($backupDir) & 0777;
            if ($perms > 0700) {
                $findings[] = [
                    'type' => 'backup_permissions',
                    'severity' => 'high',
                    'title' => "バックアップディレクトリの権限が緩すぎます",
                    'description' => sprintf("現在の権限: %o", $perms),
                    'remediation' => "chmod 700 $backupDir"
                ];
            }
            
            // 暗号化されていないバックアップファイルチェック
            $backupFiles = glob($backupDir . '/*.sql*');
            $unencryptedFiles = array_filter($backupFiles, function($file) {
                return !str_ends_with($file, '.enc');
            });
            
            if (!empty($unencryptedFiles)) {
                $findings[] = [
                    'type' => 'unencrypted_backup',
                    'severity' => 'high',
                    'title' => "暗号化されていないバックアップファイルが存在します",
                    'description' => count($unencryptedFiles) . " 件の非暗号化ファイル",
                    'remediation' => "バックアップファイルを暗号化してください"
                ];
            }
        } else {
            $findings[] = [
                'type' => 'missing_backup_dir',
                'severity' => 'medium',
                'title' => "バックアップディレクトリが存在しません",
                'description' => "定期バックアップが設定されていない可能性があります",
                'remediation' => "バックアップシステムの設定を確認してください"
            ];
        }
        
        $this->results['checks'][] = [
            'category' => 'Backup Security',
            'findings' => $findings
        ];
    }
    
    /**
     * ログセキュリティチェック
     */
    private function checkLogSecurity() {
        echo "📝 ログセキュリティチェック...\n";
        
        $findings = [];
        
        // PHPエラーログ設定チェック
        $logErrors = ini_get('log_errors');
        $errorLog = ini_get('error_log');
        
        if (!$logErrors) {
            $findings[] = [
                'type' => 'log_config',
                'severity' => 'medium',
                'title' => "PHPエラーログが無効です",
                'description' => "log_errors = Off",
                'remediation' => "php.iniでlog_errors = Onを設定"
            ];
        }
        
        if (empty($errorLog)) {
            $findings[] = [
                'type' => 'log_config',
                'severity' => 'medium',
                'title' => "エラーログファイルが指定されていません",
                'description' => "error_logが設定されていません",
                'remediation' => "php.iniでerror_logパスを設定"
            ];
        }
        
        // ログファイル権限チェック
        if (!empty($errorLog) && file_exists($errorLog)) {
            $perms = fileperms($errorLog) & 0777;
            if ($perms > 0640) {
                $findings[] = [
                    'type' => 'log_permissions',
                    'severity' => 'medium',
                    'title' => "ログファイルの権限が緩すぎます",
                    'description' => sprintf("ファイル: %s, 権限: %o", $errorLog, $perms),
                    'remediation' => "chmod 640 $errorLog"
                ];
            }
        }
        
        $this->results['checks'][] = [
            'category' => 'Log Security',
            'findings' => $findings
        ];
    }
    
    /**
     * ネットワークセキュリティチェック
     */
    private function checkNetworkSecurity() {
        echo "🌐 ネットワークセキュリティチェック...\n";
        
        $findings = [];
        
        // オープンポートチェック（簡易版）
        $dangerousPorts = [
            22 => 'SSH',
            23 => 'Telnet',
            25 => 'SMTP',
            53 => 'DNS',
            110 => 'POP3',
            143 => 'IMAP',
            993 => 'IMAPS',
            995 => 'POP3S'
        ];
        
        foreach ($dangerousPorts as $port => $service) {
            $connection = @fsockopen('localhost', $port, $errno, $errstr, 1);
            if ($connection) {
                fclose($connection);
                $findings[] = [
                    'type' => 'open_port',
                    'severity' => 'info',
                    'title' => "ポート $port ($service) が開いています",
                    'description' => "必要のないサービスは停止することを推奨します",
                    'remediation' => "不要なサービスを停止またはファイアウォールで制限"
                ];
            }
        }
        
        $this->results['checks'][] = [
            'category' => 'Network Security',
            'findings' => $findings
        ];
    }
    
    /**
     * スキャン結果をファイルに保存
     * @param string $format 'json' または 'html'
     * @return string 保存されたファイルパス
     */
    public function saveResults($format = 'json') {
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "security_scan_{$timestamp}." . $format;
        $filepath = $this->webRoot . "/reports/$filename";
        
        // reportsディレクトリ作成
        $reportsDir = dirname($filepath);
        if (!is_dir($reportsDir)) {
            mkdir($reportsDir, 0700, true);
        }
        
        if ($format === 'json') {
            file_put_contents($filepath, json_encode($this->results, JSON_PRETTY_PRINT));
        } elseif ($format === 'html') {
            $html = $this->generateHtmlReport();
            file_put_contents($filepath, $html);
        }
        
        return $filepath;
    }
    
    /**
     * HTMLレポート生成
     * @return string HTML形式のレポート
     */
    private function generateHtmlReport() {
        $html = "<!DOCTYPE html>
<html lang='ja'>
<head>
    <meta charset='UTF-8'>
    <title>セキュリティスキャンレポート - {$this->results['scan_date']}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .header { background: #f4f4f4; padding: 20px; border-radius: 5px; }
        .summary { margin: 20px 0; }
        .severity-critical { color: #d32f2f; font-weight: bold; }
        .severity-high { color: #f57c00; font-weight: bold; }
        .severity-medium { color: #fbc02d; font-weight: bold; }
        .severity-low { color: #388e3c; }
        .severity-info { color: #1976d2; }
        .finding { border-left: 4px solid #ddd; padding: 10px; margin: 10px 0; }
        .finding.critical { border-left-color: #d32f2f; }
        .finding.high { border-left-color: #f57c00; }
        .finding.medium { border-left-color: #fbc02d; }
        .finding.low { border-left-color: #388e3c; }
        .finding.info { border-left-color: #1976d2; }
    </style>
</head>
<body>
    <div class='header'>
        <h1>🔍 セキュリティスキャンレポート</h1>
        <p>スキャン日時: {$this->results['scan_date']}</p>
        <p>実行時間: {$this->results['scan_duration']}秒</p>
    </div>
    
    <div class='summary'>
        <h2>📊 サマリー</h2>";
        
        foreach ($this->results['summary'] as $severity => $count) {
            if ($count > 0) {
                $emoji = $this->getSeverityEmoji($severity);
                $html .= "<p class='severity-$severity'>$emoji $severity: $count件</p>";
            }
        }
        
        $html .= "</div>";
        
        foreach ($this->results['checks'] as $check) {
            if (!empty($check['findings'])) {
                $html .= "<h2>{$check['category']}</h2>";
                
                foreach ($check['findings'] as $finding) {
                    $html .= "<div class='finding {$finding['severity']}'>
                        <h3>{$finding['title']}</h3>
                        <p><strong>重要度:</strong> {$finding['severity']}</p>
                        <p><strong>説明:</strong> {$finding['description']}</p>
                        <p><strong>対処法:</strong> {$finding['remediation']}</p>
                    </div>";
                }
            }
        }
        
        $html .= "</body></html>";
        
        return $html;
    }
    
    /**
     * 深刻度に対応する絵文字取得
     * @param string $severity
     * @return string
     */
    private function getSeverityEmoji($severity) {
        $emojis = [
            'critical' => '🚨',
            'high' => '⚠️',
            'medium' => '⚡',
            'low' => '💡',
            'info' => 'ℹ️'
        ];
        
        return $emojis[$severity] ?? '❓';
    }
    
    /**
     * サイズ文字列をバイト数に変換
     * @param string $size
     * @return int
     */
    private function parseSize($size) {
        $units = ['B' => 1, 'K' => 1024, 'M' => 1024*1024, 'G' => 1024*1024*1024];
        $size = trim($size);
        $last = strtoupper(substr($size, -1));
        $size = (int)$size;
        
        if (isset($units[$last])) {
            $size *= $units[$last];
        }
        
        return $size;
    }
}

// CLI実行時の処理
if (php_sapi_name() === 'cli') {
    $action = $argv[1] ?? 'scan';
    $scanner = new SecurityScanner();
    
    switch ($action) {
        case 'scan':
            try {
                $results = $scanner->runFullScan();
                
                $format = $argv[2] ?? 'json';
                $reportFile = $scanner->saveResults($format);
                
                echo "\n📄 レポートが保存されました: $reportFile\n";
                
                // 重要な問題がある場合はエラーコードで終了
                $criticalCount = $results['summary']['critical'] ?? 0;
                $highCount = $results['summary']['high'] ?? 0;
                
                if ($criticalCount > 0) {
                    echo "\n🚨 重大な問題が検出されました。直ちに対処してください。\n";
                    exit(1);
                } elseif ($highCount > 0) {
                    echo "\n⚠️ 重要な問題が検出されました。対処を推奨します。\n";
                    exit(2);
                } else {
                    echo "\n✅ 重大な問題は検出されませんでした。\n";
                    exit(0);
                }
                
            } catch (Exception $e) {
                echo "エラー: " . $e->getMessage() . "\n";
                exit(1);
            }
            break;
            
        case 'help':
        default:
            echo "セキュリティスキャナー\n\n";
            echo "使用法:\n";
            echo "  php security_scanner.php scan [format]  - セキュリティスキャン実行\n";
            echo "  php security_scanner.php help           - このヘルプを表示\n\n";
            echo "フォーマット:\n";
            echo "  json  - JSON形式でレポート出力（デフォルト）\n";
            echo "  html  - HTML形式でレポート出力\n\n";
            echo "終了コード:\n";
            echo "  0 - 重大な問題なし\n";
            echo "  1 - 重大な問題あり\n";
            echo "  2 - 重要な問題あり\n";
            break;
    }
} 