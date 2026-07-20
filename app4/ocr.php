<?php
// ocr.php - 画像・スキャンPDFを OpenAI でテキスト化する共通 OCR API（app13〜16 から利用）
// handwriting=false: 標準モデル（gpt-5.4-nano）／ true: 手書きに強い高精度モデル（gpt-5.4-mini）

function loadEnv($path) {
    if (!file_exists($path)) return [];
    $env = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) $env[trim($parts[0])] = trim($parts[1]);
    }
    return $env;
}

header('Content-Type: application/json; charset=utf-8');

$env    = loadEnv(__DIR__ . '/../api/.env');
$apiKey = $env['OPENAI_API_KEY'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}
if ($apiKey === '') {
    http_response_code(500);
    echo json_encode(['error' => '.env に OPENAI_API_KEY が設定されていません']);
    exit;
}

$input       = json_decode(file_get_contents('php://input'), true);
$file        = isset($input['file']) ? $input['file'] : null;
$handwriting = !empty($input['handwriting']);

if (!is_array($file) || !isset($file['mimeType'], $file['data'])) {
    http_response_code(400);
    echo json_encode(['error' => 'ファイルデータがありません']);
    exit;
}
$allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
$mime = (string)$file['mimeType'];
$data = (string)$file['data'];
// base64は実ファイルの約1.33倍（14MB ≒ 実ファイル10MB）
if (!in_array($mime, $allowedMimes, true) || $data === ''
    || strlen($data) > 14 * 1024 * 1024
    || !preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $data)) {
    http_response_code(400);
    echo json_encode(['error' => '読み取れるファイルは JPG / PNG / WebP / PDF（10MBまで）のみです']);
    exit;
}

if ($handwriting) {
    $model = !empty($env['OPENAI_MODEL_HANDWRITING']) ? trim($env['OPENAI_MODEL_HANDWRITING']) : 'gpt-5.4-mini';
} else {
    $model = !empty($env['OPENAI_MODEL']) ? trim($env['OPENAI_MODEL']) : 'gpt-5.4-nano';
}

$systemPrompt = "あなたは高精度なOCRエンジンです。添付された画像またはPDFに書かれている文字をすべて正確に読み取り、テキストのみを出力してください。\n"
    . "- 説明・前置き・要約・感想は一切付けないこと\n"
    . "- 見出し・段落・改行などのレイアウトはできるだけ保つこと。表は「項目：値」の形で書き出すこと\n"
    . "- 手書き文字も文脈を踏まえて正確に判読すること。どうしても判読できない文字は「■」と表記すること\n"
    . "- 書かれていない文字を推測で補って創作しないこと\n"
    . "- チェックボックスや丸囲みの選択肢は「[✔]」「（選択：○○）」のように選択状態がわかるように表記すること";

if ($mime === 'application/pdf') {
    $attachment = ['type' => 'file', 'file' => [
        'filename'  => 'document.pdf',
        'file_data' => 'data:' . $mime . ';base64,' . $data,
    ]];
} else {
    $attachment = ['type' => 'image_url', 'image_url' => [
        'url' => 'data:' . $mime . ';base64,' . $data,
    ]];
}

$payload = json_encode([
    'model'    => $model,
    'messages' => [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => '添付した書類の全文を読み取ってテキスト化してください。'],
            $attachment,
        ]],
    ],
    'temperature' => 0,
    'seed'        => 42,
], JSON_UNESCAPED_UNICODE);

$url = 'https://api.openai.com/v1/chat/completions';

@set_time_limit(300);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 180,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$res      = curl_exec($ch);
$err      = curl_errno($ch) ? curl_error($ch) : '';
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err !== '') {
    http_response_code(502);
    echo json_encode(['error' => 'API通信エラー: ' . $err], JSON_UNESCAPED_UNICODE);
    exit;
}
$json = json_decode($res, true);
if ($httpCode !== 200 || !is_array($json)) {
    $apiMsg = isset($json['error']['message']) ? mb_substr($json['error']['message'], 0, 200) : '';
    http_response_code(502);
    echo json_encode(['error' => "APIエラー (HTTP {$httpCode})" . ($apiMsg !== '' ? ' ' . $apiMsg : '')], JSON_UNESCAPED_UNICODE);
    exit;
}

$text = isset($json['choices'][0]['message']['content']) ? trim($json['choices'][0]['message']['content']) : '';
if ($text === '') {
    http_response_code(502);
    echo json_encode(['error' => '文字を読み取れませんでした'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['text' => $text], JSON_UNESCAPED_UNICODE);
exit;
