<?php
/**
 * 画像生成プロキシ (Imagen 4.0)
 * React フロントエンドの /image/generate リクエストを受け取り、
 * Google Imagen API で画像を生成して base64 文字列を返す
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

// .env 読み込み
foreach (file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
    $ln = trim($ln); if ($ln === '' || $ln[0] === '#' || strpos($ln, '=') === false) continue;
    putenv($ln);
}
$apiKey = getenv('GEMINI_API_KEY');
if ($apiKey === false || $apiKey === '') {
    http_response_code(503);
    echo json_encode(['error' => 'APIキーが設定されていません（.env の GEMINI_API_KEY）']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// React フロントエンドからのリクエスト形式:
// { model: { modelId, type }, params: { textPrompt: [{text, weight}], width, height, aspectRatio, ... } }
$params = $input['params'] ?? $input ?? [];

// プロンプト取得（textPrompt 配列形式 or prompt 文字列）
$prompt = '';
$negativePrompt = '';
if (isset($params['textPrompt']) && is_array($params['textPrompt'])) {
    foreach ($params['textPrompt'] as $tp) {
        if (($tp['weight'] ?? 1) > 0) {
            $prompt .= ($tp['text'] ?? '') . ' ';
        } else {
            $negativePrompt .= ($tp['text'] ?? '') . ' ';
        }
    }
    $prompt = trim($prompt);
    $negativePrompt = trim($negativePrompt);
} else {
    $prompt = $params['prompt'] ?? '';
    $negativePrompt = $params['negativePrompt'] ?? '';
}

if (empty($prompt)) {
    http_response_code(400);
    echo json_encode('Error: prompt is empty');
    exit;
}

// アスペクト比（V7 プリセット: "1:1", "5:4", "3:2", "16:9", "21:9"）
$aspectRatio = $params['aspectRatio'] ?? '1:1';

// Imagen 4.0 API
$model = 'imagen-4.0-fast-generate-001';
$url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:predict";

$body = [
    'instances' => [
        ['prompt' => $prompt]
    ],
    'parameters' => [
        'sampleCount' => 1,
        'aspectRatio' => $aspectRatio,
        'personGeneration' => 'allow_all',
        'outputOptions' => [
            'mimeType' => 'image/jpeg',
            'compressionQuality' => 80,
        ],
    ]
];

if (!empty($negativePrompt)) {
    $body['instances'][0]['negativePrompt'] = $negativePrompt;
}

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'x-goog-api-key: ' . $apiKey
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
curl_setopt($ch, CURLOPT_TIMEOUT, 90);

$response = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $httpCode === 0) {
    http_response_code(504);
    $hint = $curlErr !== '' ? $curlErr : 'ネットワークエラー';
    echo json_encode(['error' => '画像生成APIに接続できませんでした。連続実行の場合は1分ほど置いてから再試行してください。（' . $hint . '）']);
    exit;
}

if ($httpCode !== 200) {
    // レート制限・クォータ超過などは Google 側のステータスをそのまま返す（429/503 等）
    $detail = is_string($response) ? json_decode($response, true) : null;
    $msg = (is_array($detail) && isset($detail['error']['message']))
        ? $detail['error']['message']
        : '画像生成に失敗しました';
    if (in_array($httpCode, [400, 401, 403, 404, 429, 503], true)) {
        http_response_code($httpCode);
    } else {
        http_response_code(502);
    }
    echo json_encode(['error' => $msg]);
    exit;
}

$json = json_decode($response, true);

if (isset($json['predictions'][0]['bytesBase64Encoded'])) {
    echo json_encode(['image' => $json['predictions'][0]['bytesBase64Encoded']]);
} else {
    http_response_code(500);
    echo json_encode(['error' => '画像データが取得できませんでした']);
}
