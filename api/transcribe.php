<?php
/**
 * 音声文字起こしプロキシ (Gemini File API 方式)
 * 大容量MP3対応: File API でアップロード後、fileUri で参照
 */
// upload_max_filesize / post_max_size は .htaccess で設定済み
ini_set('max_execution_time', '300');
ini_set('memory_limit', '256M');

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

if (empty($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['error' => '音声ファイルが必要です']);
    exit;
}

$file = $_FILES['file'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'アップロードエラー: code=' . $file['error']]);
    exit;
}
if (!is_uploaded_file($file['tmp_name'])) {
    http_response_code(400);
    echo json_encode(['error' => '不正なアップロードです']);
    exit;
}
// マジックバイト検査（実行ファイルの明示拒否）
$fpHead = fopen($file['tmp_name'], 'rb');
$headBytes = fread($fpHead, 8);
fclose($fpHead);
if (strncmp($headBytes, "MZ", 2) === 0 || strncmp($headBytes, "\x7fELF", 4) === 0 || strncmp($headBytes, "#!", 2) === 0) {
    http_response_code(400);
    echo json_encode(['error' => '実行ファイルはアップロードできません']);
    exit;
}

$fileSize = $file['size'];
$filePath = $file['tmp_name'];
$language = $_POST['language'] ?? 'ja';
$speakers = $_POST['speakers'] ?? '';

// 対応形式・サイズ制限
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowedExts = ['mp3', 'mp4', 'wav', 'flac', 'ogg', 'amr', 'webm', 'm4a'];

// 拡張子から audio MIME を強制（ブラウザが video/mp4 等を送る問題を回避）
$audioMimeMap = [
    'mp3'  => 'audio/mpeg',
    'mp4'  => 'audio/mp4',
    'wav'  => 'audio/wav',
    'flac' => 'audio/flac',
    'ogg'  => 'audio/ogg',
    'amr'  => 'audio/amr',
    'webm' => 'audio/webm',
    'm4a'  => 'audio/mp4',
];
$mimeType = $audioMimeMap[$ext] ?? ($file['type'] ?: 'audio/mpeg');
if (!in_array($ext, $allowedExts)) {
    http_response_code(400);
    echo json_encode(['error' => '対応していないファイル形式です。対応形式: ' . implode(', ', $allowedExts)]);
    exit;
}
if ($fileSize > 50 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['error' => 'ファイルサイズは50MB以下にしてください']);
    exit;
}

// --- プロンプト構築 ---
function buildPrompt($language, $speakers) {
    $base = "この音声を文字起こししてください。言語は{$language}です。タイムスタンプは不要です。";
    if (empty($speakers)) {
        return $base . "話者の区別は不要です。音声の内容をそのまま文字に起こしてください。";
    }
    $names = array_map('trim', explode(',', $speakers));
    $nameList = implode(', ', $names);
    $count = count($names);
    $format = '';
    for ($i = 0; $i < min($count, 3); $i++) {
        $format .= "Speaker {$i}: {$names[$i]}さんの発言内容\n";
    }
    return $base . "\n\n"
        . "この音声には最大{$count}人の話者がいます。話者名: {$nameList}\n"
        . "必ず以下の形式で出力してください。各発言の先頭に「Speaker 番号:」を付けてください。\n\n"
        . "出力形式:\n{$format}\n"
        . "重要: 話者が変わるたびに改行し、必ず「Speaker 0:」「Speaker 1:」のように番号付きラベルで始めてください。"
        . "番号は0から始めてください。ラベル以外の余計な装飾は不要です。";
}

// --- Step 1: Gemini File API にアップロード ---
$uploadUrl = "https://generativelanguage.googleapis.com/upload/v1beta/files";

$ch = curl_init($uploadUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'x-goog-api-key: ' . $apiKey,
    'X-Goog-Upload-Command: start, upload, finalize',
    'X-Goog-Upload-Header-Content-Length: ' . $fileSize,
    'X-Goog-Upload-Header-Content-Type: ' . $mimeType,
    'Content-Type: ' . $mimeType,
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents($filePath));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 120);

$uploadResponse = curl_exec($ch);
$uploadHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($uploadHttpCode !== 200) {
    http_response_code(500);
    echo json_encode(['error' => 'ファイルアップロードに失敗しました', 'details' => $uploadResponse]);
    exit;
}

$uploadJson = json_decode($uploadResponse, true);
$fileUri = $uploadJson['file']['uri'] ?? '';
if (empty($fileUri)) {
    http_response_code(500);
    echo json_encode(['error' => 'ファイルURIが取得できませんでした', 'details' => $uploadResponse]);
    exit;
}

// --- Step 2: fileUri を使って文字起こし ---
$modelId = 'gemini-2.5-flash';
$url = "https://generativelanguage.googleapis.com/v1beta/models/{$modelId}:generateContent";

$body = [
    'contents' => [
        [
            'role' => 'user',
            'parts' => [
                [
                    'fileData' => [
                        'fileUri' => $fileUri,
                        'mimeType' => $mimeType,
                    ]
                ],
                [
                    'text' => buildPrompt($language, $speakers)
                ]
            ]
        ]
    ],
    'generationConfig' => [
        'temperature' => 0.1
    ]
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 300);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    http_response_code(500);
    echo json_encode(['error' => '文字起こしに失敗しました', 'details' => $response]);
    exit;
}

$json = json_decode($response, true);
$text = '';
if (isset($json['candidates'][0]['content']['parts'])) {
    foreach ($json['candidates'][0]['content']['parts'] as $part) {
        if (isset($part['text'])) $text .= $part['text'];
    }
}

echo json_encode([
    'text' => $text,
    'transcripts' => [['transcript' => $text]]
], JSON_UNESCAPED_UNICODE);
