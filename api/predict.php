<?php
/**
 * Gemini 非ストリーミング応答 (タイトル生成等)
 */

// === PHP エラー HTML 出力を完全遮断 ===
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    @file_put_contents(__DIR__ . '/debug.log',
        date('Y-m-d H:i:s') . " [predict] [{$errno}] {$errstr} in {$errfile}:{$errline}\n",
        FILE_APPEND | LOCK_EX);
    return true;
});
error_reporting(0);
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
@ini_set('memory_limit', '256M');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

// .env 読み込み
$envFile = __DIR__ . '/.env';
if (is_file($envFile)) {
    foreach (@file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
        $ln = trim($ln);
        if ($ln === '' || $ln[0] === '#' || strpos($ln, '=') === false) continue;
        putenv($ln);
    }
}
$apiKey = getenv('GEMINI_API_KEY');

$rawInput = @file_get_contents('php://input');
$input = ($rawInput !== false && $rawInput !== '') ? @json_decode($rawInput, true) : null;

if (!is_array($input)) {
    // post_max_size 超過時でもフロントエンドを壊さないよう空タイトルを返す
    echo json_encode(['text' => ''], JSON_UNESCAPED_UNICODE);
    exit;
}

$messages = isset($input['messages']) && is_array($input['messages']) ? $input['messages'] : [];
$modelId = isset($input['model']) && is_array($input['model']) && isset($input['model']['modelId'])
    ? $input['model']['modelId']
    : 'gemini-3.1-flash-lite';

// メッセージ変換（extraData の base64 は除去してテキストのみ送信）
$systemInstruction = '';
$contents = [];
foreach ($messages as $msg) {
    $role = isset($msg['role']) ? $msg['role'] : 'user';
    $content = '';
    if (isset($msg['content'])) {
        if (is_array($msg['content'])) {
            foreach ($msg['content'] as $part) {
                if (isset($part['body'])) $content .= $part['body'];
            }
        } else {
            $content = is_string($msg['content']) ? $msg['content'] : '';
        }
    }

    if ($role === 'system') {
        $systemInstruction .= $content . "\n";
        continue;
    }
    if ($content === '') continue;
    $contents[] = [
        'role' => ($role === 'assistant') ? 'model' : 'user',
        'parts' => [['text' => $content]]
    ];
}

$body = ['contents' => $contents, 'generationConfig' => ['temperature' => 0.3]];
if ($systemInstruction !== '') {
    $body['systemInstruction'] = ['parts' => [['text' => trim($systemInstruction)]]];
}

$url = "https://generativelanguage.googleapis.com/v1beta/models/{$modelId}:generateContent?key={$apiKey}";

$encoded = @json_encode($body);
if ($encoded === false) {
    http_response_code(500);
    echo json_encode(['error' => 'リクエストのエンコードに失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = @curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    http_response_code(500);
    echo json_encode(['error' => 'Gemini API error', 'details' => $response], JSON_UNESCAPED_UNICODE);
    exit;
}

$json = @json_decode($response, true);
$text = '';
if (is_array($json) && isset($json['candidates'][0]['content']['parts']) && is_array($json['candidates'][0]['content']['parts'])) {
    foreach ($json['candidates'][0]['content']['parts'] as $part) {
        if (isset($part['text'])) $text .= $part['text'];
    }
}

echo json_encode(['text' => $text], JSON_UNESCAPED_UNICODE);
