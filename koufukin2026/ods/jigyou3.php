<?php
// Prevent caching and enable streaming
header('Content-Type: text/plain; charset=utf-8');
header('X-Accel-Buffering: no');
header('Cache-Control: no-cache');

// Enable error reporting for debugging
ini_set('display_errors', 0);
set_time_limit(0); // Allow long execution for 6000+ chars generation
error_reporting(E_ALL);

function logDebug($msg) {
    error_log("[Jigyou3Gen Debug] " . $msg);
}

// ---------------------------------------------------------
// 1. Securely Load Configuration (env_loader)
// ---------------------------------------------------------
require_once dirname(dirname(__DIR__)) . '/env_loader.php';
loadAppEnv('koufukin');
$apiKey = getenv('GEMINI_API_KEY');
$model = getenv('GEMINI_MODEL') ?: 'gemini-3.1-flash-lite';

if (!$apiKey) {
    http_response_code(500);
    echo "[Error] API Key not configured on server.";
    exit;
}

// ---------------------------------------------------------
// 2. Prepare Inputs & Context
// ---------------------------------------------------------
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo "[Error] Invalid Input";
    exit;
}

// Determine Type MD File
$type = isset($input['type']) ? $input['type'] : '';
$typeMdFile = '';
if (stripos($type, 'TYPE A') !== false) {
    $typeMdFile = 'typea.md';
} elseif (stripos($type, 'TYPE V') !== false) {
    $typeMdFile = 'typev.md';
} elseif (stripos($type, 'TYPE S') !== false) {
    $typeMdFile = 'types.md';
}

// Load Context Files
$baseDir = __DIR__ . '/../';
$typeAllPath = $baseDir . 'typeall.md';
$specificTypePath = $typeMdFile ? $baseDir . $typeMdFile : '';

$contextText = "";

if (file_exists($typeAllPath)) {
    $contextText .= "【全タイプ共通ノウハウ (typeall.md)】\n" . file_get_contents($typeAllPath) . "\n\n";
}

if ($specificTypePath && file_exists($specificTypePath)) {
    $contextText .= "【選択タイプ別ノウハウ ({$typeMdFile})】\n" . file_get_contents($specificTypePath) . "\n\n";
} else {
    $contextText .= "（※選択されたタイプに特化したノウハウ資料は見つかりませんでした）\n\n";
}

// ---------------------------------------------------------
// 3. Construct Prompts
// ---------------------------------------------------------
$systemPrompt = "あなたは地域未来交付金の採択を確実にするための、伝説的な行政コンサルタントです。\n" .
    "以下の「申請書作成ノウハウ」をバイブルとし、Typeごとの特性を完璧に網羅した、審査員が感動するレベルの「最終提案書（完全版）」を作成してください。\n" .
    "現在の事業案をベースに、Google検索ツールを自律的に活用して、その自治体の最新の総合計画、人口ビジョン、デジタル戦略、地域の特有の課題、競合する類似事例などをリサーチし、企画書の中に具体的なエビデンスとして組み込んでください。\n" .
    "文字数は6000文字以上を目指し、圧倒的な情報量と論理性を持たせてください。\n" .
    "項目や構成は、指定されたTypeのノウハウ（MDファイル）に従い、必要な項目はすべて追加・補強してください。\n\n" .
    $contextText;

$userPrompt = "以下のこれまでの検討内容（jigyou2_result）をベースに、リサーチを行い、6000文字以上の「地域未来交付金 最終完全提案書」を作成してください。\n\n" .
    "【基本情報】\n" .
    "自治体名: {$input['municipality']}\n" .
    "申請タイプ: {$input['type']}\n" .
    "ジャンル: {$input['genre']}\n" .
    "事業名: {$input['project']}\n" .
    "予算: {$input['budget']}\n" .
    "その他: {$input['others']}\n\n" .
    "【これまでの検討内容（ベース資料）】\n" .
    $input['jigyou2_result'] . "\n\n" .
    "【指示】\n" .
    "1. 自治体の実情に即した具体的なデータを検索して補強すること。\n" .
    "2. Typeごとの加点要素（MDファイル参照）を確実に盛り込むこと。\n" .
    "5. 【重要】出力形式について：Markdown記法（#、##、**、- など）は一切使用しないでください。記号を使わず、改行とインデント、や「・」「１．」などの日本語の箇条書き記号のみで構成された、そのままWord等に貼り付けて使える美しい文書形式で出力すること。\n" .
    "6. 段落や項目間には適切な空行（プレーンテキストの改行）を入れ、読みやすさを維持すること。";

// ---------------------------------------------------------
// 4. Call Gemini API (Streaming) with Google Search Tool
// ---------------------------------------------------------
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
        "maxOutputTokens" => 50000,
        "temperature" => 0.3
    ]
];

// cURL Setup
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$hasOutput = false;

curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) use (&$hasOutput) {
    // Process error response
    if (!$hasOutput && strpos($chunk, '"error":') !== false) {
        logDebug("Google API Error: " . $chunk);
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

// Bypass buffering
echo " " . str_repeat(" ", 4096);
if (ob_get_level() > 0) ob_flush();
flush();

curl_exec($ch);
curl_close($ch);
?>
