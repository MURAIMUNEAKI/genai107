<?php
// proxy.php
// CORS対応のためにPHP経由でJ-Grants APIにアクセスするプロキシ

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: X-API-KEY");
header("Content-Type: application/json");

// URLパラメータを取得
$url = $_GET['url'] ?? '';

if (empty($url)) {
    http_response_code(400);
    echo json_encode(["error" => "No URL provided"]);
    exit;
}

// セキュリティ: J-Grantsのドメインのみ許可
if (strpos($url, "https://api.jgrants-portal.go.jp/") !== 0) {
    http_response_code(403);
    echo json_encode(["error" => "Invalid domain restricted"]);
    exit;
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // ローカル開発用にSSL検証をスキップ

// クライアントから送られてきたAPIキーがあれば転送
$headers = [];
$requestHeaders = getallheaders();
// getallheadersはApache環境以外(php -Sなど)で動かないことがあるので、$_SERVERも見る
if (isset($requestHeaders['X-API-KEY'])) {
    $headers[] = "X-API-KEY: " . $requestHeaders['X-API-KEY'];
} elseif (isset($_SERVER['HTTP_X_API_KEY'])) {
    $headers[] = "X-API-KEY: " . $_SERVER['HTTP_X_API_KEY'];
}

if (!empty($headers)) {
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
}

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if (curl_errno($ch)) {
    http_response_code(500);
    echo json_encode(["error" => "Request Error: " . curl_error($ch)]);
} else {
    http_response_code($httpCode);
    echo $response;
}

curl_close($ch);
?>
