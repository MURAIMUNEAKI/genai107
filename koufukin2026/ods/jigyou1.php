<?php
// Prevent caching and enable streaming
header('Content-Type: text/plain; charset=utf-8');
header('X-Accel-Buffering: no');
header('Cache-Control: no-cache');

// Enable error reporting for debugging (REMOVE IN PRODUCTION AFTER FIX)
ini_set('display_errors', 0); // Don't break JSON/Stream output with PHP errors
error_reporting(E_ALL);

function logDebug($msg) {
    error_log("[JigyouGen Debug] " . $msg);
}

// ---------------------------------------------------------
// 1. Securely Load Configuration (env_loader)
// ---------------------------------------------------------
require_once dirname(dirname(__DIR__)) . '/env_loader.php';
loadAppEnv('koufukin');
$apiKey = getenv('GEMINI_API_KEY');
// Use model from env, or fallback to the one that worked
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
// 2. Prepare Context & Input
// ---------------------------------------------------------
$gaiyouPath = __DIR__ . '/../gaiyou.md';
$gaiyouContext = file_exists($gaiyouPath) ? file_get_contents($gaiyouPath) : "（概要資料が見つかりませんでした）";

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    logDebug("Invalid Input JSON");
    echo "[Error] Invalid Input";
    exit;
}

// ---------------------------------------------------------
// 3. Construct Prompts
// ---------------------------------------------------------
$systemPrompt = "あなたは日本の自治体向け「地域未来交付金」の企画立案アドバイザーです。\n" .
    "以下の「制度概要資料」を熟読し、ユーザーが提案する事業がこの交付金制度に適合するように、魅力的な「事業概要」を作成してください。\n" .
    "文字数は400字～600字程度を目安にしてください。\n" .
    "文体は「である」調で、格調高く記述してください。\n\n" .
    "【制度概要資料】\n" . $gaiyouContext;

$userPrompt = "以下の事業案について、地域未来交付金の趣旨に沿った事業概要案を作成してください。\n\n" .
    "自治体名: {$input['municipality']}\n" .
    "ジャンル: {$input['genre']}\n" .
    "事業名: {$input['project']}\n" .
    "予算: {$input['budget']}\n" .
    "原案: {$input['overview']}\n" .
    "その他: {$input['others']}";

// ---------------------------------------------------------
// 4. Call Gemini API (Streaming)
// ---------------------------------------------------------
// Model is already set from env
$url = "https://generativelanguage.googleapis.com/v1beta/models/$model:streamGenerateContent?key=$apiKey";

$payload = [
    "contents" => [
        ["role" => "user", "parts" => [["text" => $userPrompt]]]
    ],
    "systemInstruction" => [
        "parts" => [["text" => $systemPrompt]]
    ],
    "generationConfig" => [
        "maxOutputTokens" => 10000
    ]
];

logDebug("Starting cURL request to: " . $url);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Allow redirects
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For debugging purposes only, in case of SSL issues on some shared hosts

$hasOutput = false;

curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) use (&$hasOutput) {
    // logDebug("Received chunk: size=" . strlen($chunk)); // Too verbose for prod logs usually, enable if needed
    
    // Check for error JSON from Google
    if (!$hasOutput && strpos($chunk, '"error":') !== false) {
        logDebug("Google API Error received: " . $chunk);
        echo "[API_Error] " . $chunk; // Show raw error to frontend for debugging
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
