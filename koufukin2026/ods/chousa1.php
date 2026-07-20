<?php
// Prevent caching and enable streaming
header('Content-Type: text/plain; charset=utf-8');
header('X-Accel-Buffering: no');
header('Cache-Control: no-cache');

// Enable error reporting for debugging
ini_set('display_errors', 0);
error_reporting(E_ALL);

function logDebug($msg) {
    error_log("[ChousaGen Debug] " . $msg);
}

// ---------------------------------------------------------
// 1. Securely Load Configuration (env_loader)
// ---------------------------------------------------------
require_once dirname(dirname(__DIR__)) . '/env_loader.php';
loadAppEnv('koufukin');
$apiKey = getenv('GEMINI_API_KEY');
// Use model from env, or fallback
$model = getenv('GEMINI_MODEL') ?: 'gemini-3.1-flash-lite';

if (!$apiKey) {
    logDebug("API Key NOT found.");
    http_response_code(500);
    echo "[Error] API Key not configured on server.";
    exit;
} else {
    logDebug("API Key found (length: " . strlen($apiKey) . ")");
    logDebug("Model selected: " . $model);
}

// ---------------------------------------------------------
// 2. Prepare Input
// ---------------------------------------------------------
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    logDebug("Invalid Input JSON");
    echo "[Error] Invalid Input";
    exit;
}

// ---------------------------------------------------------
// 3. Construct Prompts
// ---------------------------------------------------------
$systemPrompt = "あなたは自治体の行政課題調査のプロフェッショナルです。\n" .
    "Google検索ツールを活用し、指定された自治体の現状、統計データ、課題、および関連する最新ニュースを調査してください。\n" .
    "特に指定されたジャンルにおけるデジタル活用の可能性や、類似する先行事例があれば言及してください。";

$userPrompt = "以下の自治体における、指定されたジャンルに関する現状と課題を調査し、まとめてください。\n\n" .
    "自治体名: {$input['municipality']}\n" .
    "ジャンル: {$input['genre']}\n" .
    "関連事業名: {$input['project']}\n\n" .
    "【重要】出力は500文字以上、1000文字以内で記述してください。\n" .
    "具体的な事実、数値、または実際の検索結果に基づいて記述してください。";

// ---------------------------------------------------------
// 4. Call Gemini API (Streaming)
// ---------------------------------------------------------
// Model is already set from env (see step 1) but note:
// If this task depends on google_search, gemini-3.1-flash-lite usually supports it on v1beta.
// Confirming usage of $model from env.

$url = "https://generativelanguage.googleapis.com/v1beta/models/$model:streamGenerateContent?key=$apiKey";

$payload = [
    "contents" => [
        ["role" => "user", "parts" => [["text" => $userPrompt]]]
    ],
    "systemInstruction" => [
        "parts" => [["text" => $systemPrompt]]
    ],
    "tools" => [
        ["google_search" => new stdClass()]
    ],
    "generationConfig" => [
        "maxOutputTokens" => 10000,
        "temperature" => 0.2
    ]
];

logDebug("Starting cURL request to: " . $url . " with model " . $model);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$hasOutput = false;

curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) use (&$hasOutput) {
    // Check for error JSON from Google
    if (!$hasOutput && strpos($chunk, '"error":') !== false) {
        logDebug("Google API Error received: " . $chunk);
        echo "[API_Error] " . $chunk;
        return strlen($chunk);
    }

    if (preg_match_all('/"text":\s*"([^"]*)"/', $chunk, $matches)) {
        foreach ($matches[1] as $text) {
            $cleanText = json_decode('"' . $text . '"');
            if ($cleanText !== null) {
                echo $cleanText;
                $hasOutput = true;
                if (ob_get_level() > 0) ob_flush();
                flush();
            }
        }
    }
    return strlen($chunk);
});

// Send 4KB logic to bypass browser buffering
echo " " . str_repeat(" ", 4096);
if (ob_get_level() > 0) ob_flush();
flush();

curl_exec($ch);

if (curl_errno($ch)) {
    $err = curl_error($ch);
    logDebug("cURL Error: " . $err);
    echo "\n[System Error] Network request failed: " . $err;
} else {
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    logDebug("Request completed. HTTP Code: " . $code);
    if ($code !== 200 && !$hasOutput) {
        echo "\n[System Error] API returned HTTP " . $code;
    }
}

curl_close($ch);
?>
