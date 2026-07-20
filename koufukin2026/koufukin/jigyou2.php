<?php
// Prevent caching and enable streaming
header('Content-Type: text/plain; charset=utf-8');
header('X-Accel-Buffering: no');
header('Cache-Control: no-cache');

// Enable error reporting for debugging
ini_set('display_errors', 0);
error_reporting(E_ALL);

function logDebug($msg) {
    error_log("[Jigyou2Gen Debug] " . $msg);
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
$systemPrompt = "あなたは地域未来交付金の申請書作成の超一流エキスパートです。\n" .
    "以下の「申請書作成ノウハウ」を熟読し、これを最大限に活用して、採択される可能性が極めて高い「地域未来交付金 実施計画」を作成してください。\n" .
    "ノウハウに記載されている重要キーワードやロジック（例：KPIの設定方法、自立性の担保など）を必ず反映させてください。\n" .
    "合計2000文字以上で記述してください。\n\n" .
    $contextText;

$userPrompt = "以下の情報に基づき、「地域未来交付金 実施計画」を作成してください。\n\n" .
    "【基本情報】\n" .
    "自治体名: {$input['municipality']}\n" .
    "申請タイプ: {$input['type']}\n" .
    "ジャンル: {$input['genre']}\n" .
    "事業名: {$input['project']}\n" .
    "予算: {$input['budget']}\n" .
    "その他: {$input['others']}\n\n" .
    "【事業概要 (予備生成結果)】\n" .
    $input['jigyou1_result'] . "\n\n" .
    "【現状調査結果 (予備生成結果)】\n" .
    $input['chousa1_result'] . "\n\n" .
    "【出力フォーマット】\n" .
    "地域未来交付金 実施計画\n\n" .
    "１．申請者情報\n" .
    "市町村名　{$input['municipality']}\n\n" .
    "２．交付対象事業の名称等\n" .
    "{$input['project']}\n\n" .
    "３．交付対象事業の背景・概要\n" .
    "３－Ａ．地方創生として目指す将来像（交付対象事業の背景）\n" .
    "(詳細に記述)\n\n" .
    "３－Ｂ．地方創生の実現における構造的な課題\n" .
    "(詳細に記述)\n\n" .
    "３－Ｃ．交付対象事業の概要\n" .
    "(詳細に記述)\n\n" .
    "４．ジャンル\n" .
    "{$input['genre']}\n\n" .
    "５．交付対象事業の重要業績評価指標（KPI）\n" .
    "KPI①　(具体的数値目標を含む)\n" .
    "KPI②　(具体的数値目標を含む)\n" .
    "KPI③　(具体的数値目標を含む)\n\n" .
    "６．自立性\n" .
    "取組内容（事業推進主体が将来的に本交付金に頼らず継続できる仕組み）\n" .
    "【A】自主財源の種類\n" .
    "(詳細)\n" .
    "【B】自主財源の種類\n" .
    "(詳細)\n" .
    "【C】自主財源の種類\n" .
    "(詳細)\n\n" .
    "７．地域の多様な主体の参画\n" .
    "(詳細に記述)\n\n" .
    "８．推進体制\n" .
    "(詳細に記述)\n\n" .
    "９．実行計画\n" .
    "(詳細に記述)\n\n" .
    "１０．予算\n" .
    "{$input['budget']}\n\n" .
    "１１．加点項目\n" .
    "(ノウハウに基づき記述)\n\n" .
    "１２．その他\n" .
    "{$input['others']}";

// ---------------------------------------------------------
// 4. Call Gemini API (Streaming)
// ---------------------------------------------------------
$url = "https://generativelanguage.googleapis.com/v1beta/models/$model:streamGenerateContent?key=$apiKey";

$payload = [
    "contents" => [
        ["role" => "user", "parts" => [["text" => $userPrompt]]]
    ],
    "systemInstruction" => [
        "parts" => [["text" => $systemPrompt]]
    ],
    "generationConfig" => [
        "maxOutputTokens" => 10000,
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
