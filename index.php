<?php
/**
 * 簡易ルーター - 宿泊予約管理システム
 */

$request_uri = $_SERVER['REQUEST_URI'];
$path = parse_url($request_uri, PHP_URL_PATH);

// APIリクエストの処理
if (strpos($path, '/api/') === 0) {
    $api_file = __DIR__ . $path;
    if (file_exists($api_file) && is_file($api_file)) {
        include $api_file;
    } else {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'API endpoint not found']);
    }
    return;
}

// 静的ファイルの処理
if (strpos($path, '/public/') === 0) {
    $static_file = __DIR__ . $path;
    if (file_exists($static_file) && is_file($static_file)) {
        // ファイルタイプに応じてContent-Typeを設定
        $ext = pathinfo($static_file, PATHINFO_EXTENSION);
        switch ($ext) {
            case 'css':
                header('Content-Type: text/css');
                break;
            case 'js':
                header('Content-Type: application/javascript');
                break;
            case 'html':
                header('Content-Type: text/html; charset=utf-8');
                break;
        }
        readfile($static_file);
    } else {
        http_response_code(404);
        echo "File not found";
    }
    return;
}

// ページルーティング
switch ($path) {
    case '/':
        // ルートアクセス時は顧客向け予約フォームへ
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . '/public/index.html');
        break;
        
    case '/admin':
    case '/admin.html':
        // 管理画面へのアクセス
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . '/public/admin.html');
        break;
        
    case '/settings':
    case '/settings.html':
        // 設定画面へのアクセス
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . '/public/settings.html');
        break;

    case '/monthly':
    case '/monthly.html':
        // 月間ビューへのアクセス
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . '/public/monthly.html');
        break;

    case '/daily':
    case '/daily.html':
        // 日間ビューへのアクセス
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . '/public/daily.html');
        break;
        
    case '/reservation':
    case '/booking':
        // 予約フォームへのアクセス
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . '/public/index.html');
        break;
        
    default:
        // 該当しない場合は404エラー
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo "<!DOCTYPE html>
<html lang='ja'>
<head>
    <meta charset='UTF-8'>
    <title>404 - ページが見つかりません</title>
    <style>
        body { font-family: Arial, sans-serif; text-align: center; padding: 50px; }
        h1 { color: #e74c3c; }
        a { color: #3498db; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <h1>404 - ページが見つかりません</h1>
    <p>お探しのページは存在しません。</p>
    <ul style='list-style: none; padding: 0;'>
        <li><a href='/'>🏠 予約フォーム</a></li>
        <li><a href='/admin'>🏢 管理画面</a></li>
        <li><a href='/monthly'>📅 月間ビュー</a></li>
        <li><a href='/daily'>📋 日間ビュー</a></li>
        <li><a href='/settings'>⚙️ 設定画面</a></li>
    </ul>
</body>
</html>";
        break;
}
?> 