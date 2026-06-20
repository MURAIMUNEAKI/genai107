<?php
// app13.php - 介護ケアプラン適正性審査（Gemini SSE プロキシ）

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

$env    = loadEnv(__DIR__ . '/../api/.env');
$apiKey = $env['GEMINI_API_KEY'] ?? '';
$model  = $env['GEMINI_MODEL']   ?? 'gemini-3.1-flash-lite';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'POST required']);
    exit;
}
if ($apiKey === '') {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '.env に GEMINI_API_KEY が設定されていません']);
    exit;
}

$input   = json_decode(file_get_contents('php://input'), true);
$docType = trim($input['doc_type'] ?? 'kyotaku');
$text    = trim($input['text'] ?? '');

if ($text === '') {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '審査対象の書類テキストを入力してください']);
    exit;
}

// サニタイズ
$text = strip_tags($text);
$text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
$text = mb_substr($text, 0, 18000);

$typeLabels = [
    'kyotaku' => '居宅サービス計画書（ケアプラン）',
    'shisetsu' => '施設サービス計画書',
    'yobo' => '介護予防サービス・支援計画書',
];
$typeLabel = $typeLabels[$docType] ?? $typeLabels['kyotaku'];

$systemPrompt = <<<PROMPT
あなたは介護保険制度に精通したベテランの主任介護支援専門員（主任ケアマネジャー）であり、保険者（市町村）の運営指導でケアプラン点検を担当する審査員です。
アップロードされた「{$typeLabel}」を、指定居宅介護支援等の運営に関する基準 第13条（介護支援専門員の運営基準・ケアマネジメントプロセス）に照らして審査し、採点と詳細レポートを作成してください。

【審査項目と配点（合計100点）】
1. 利用者・家族の生活意向の記載（第1表）— 10点：本人および家族の「生活に対する意向」が具体的に記載されているか
2. 総合的な援助の方針の妥当性 — 10点：方針が課題・意向と整合し、緊急連絡先・対応方針を含むか
3. アセスメント課題（生活全般の解決すべき課題＝ニーズ）の明確性 — 15点：課題分析に基づき自立支援に資するニーズが導かれているか
4. 長期目標・短期目標の具体性と期間設定 — 15点：目標が測定可能・具体的で、達成期間（開始〜終了）が設定されているか
5. 目標とサービス内容の整合性 — 15点：各目標に対応する援助内容が論理的につながっているか
6. サービス種別・事業所・頻度の明記 — 10点：サービス種別、提供事業所、回数・頻度が明記されているか
7. 第2表と週間サービス計画表（第3表）の整合 — 10点：曜日・時間・サービスが矛盾なく対応しているか
8. 利用者同意（説明・同意の署名・交付日） — 10点：利用者への説明・同意・交付の記録があるか
9. インフォーマルサポート（家族・地域・ボランティア等）の活用 — 5点：保険外の支援が位置づけられているか

【採点ルール】
- 各項目を ○（十分）/△（一部不備）/×（不備・記載なし）で判定し、配点に対する得点を付与すること（○=満点、△=配点の約半分、×=0点を目安）
- 書類に該当記載が無い場合は推測で補わず「×（記載なし）」とし、捏造しないこと
- 自立支援・重度化防止の視点、本人の主体性（本人主体の目標になっているか）を重視すること

【出力フォーマット（Markdownで必ずこの構成）】
## 総合評価
- 評価ランク：〔A：90点以上〕〔B：75〜89点〕〔C：60〜74点〕〔D：60点未満〕のいずれか
- 合計点：◯◯ / 100点
- 一言講評：（2〜3行）

## 項目別採点表
| No. | 審査項目 | 配点 | 判定 | 得点 | 指摘事項 |
|-----|----------|------|------|------|----------|
（9項目すべてを記載）

## 重大な不備（要是正）
- （運営基準違反や同意欠落など、是正が必要な事項。なければ「特になし」）

## 改善提案
- （より良いケアプランにするための具体的な助言）

## 総評・次のアクション
（ケアマネジャーが次に取るべき対応を200〜400字でまとめる）

【厳守事項】
- 合計点は必ず項目別採点表の「得点」列の単純合計と一致させること。出力前に各得点（例：10+10+15+...）を実際に足し算して検算し、総合評価の合計点と表の合計が1点でも食い違う場合は表の合計値を正として書き直すこと
- 全項目が満点（○）の場合の合計は必ず100点であり、95点等と書かないこと
- 必ず日本語で出力すること
- 書類に書かれていない事実を創作しないこと
- 専門用語には簡潔な補足を付けること
PROMPT;

$userMessage = "以下は審査対象の{$typeLabel}です。審査基準に沿って採点・レポートしてください。\n\n----- 書類ここから -----\n" . $text . "\n----- 書類ここまで -----";

$payload = json_encode([
    'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
    'contents' => [['role' => 'user', 'parts' => [['text' => $userMessage]]]],
    'generationConfig' => ['temperature' => 0.1],
], JSON_UNESCAPED_UNICODE);

$url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:streamGenerateContent?alt=sse";

while (ob_get_level()) ob_end_clean();
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

function sendSSE($data) {
    echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey],
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_TIMEOUT        => 120,
    CURLOPT_SSL_VERIFYPEER => false,
]);

$buffer = '';
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$buffer) {
    $buffer .= $data;
    while (($pos = strpos($buffer, "\n")) !== false) {
        $line = substr($buffer, 0, $pos);
        $buffer = substr($buffer, $pos + 1);
        $line = rtrim($line, "\r");
        if ($line === '' || strpos($line, 'data:') !== 0) continue;
        $jsonStr = ltrim(substr($line, 5));
        if ($jsonStr === '' || $jsonStr === '[DONE]') continue;
        $event = json_decode($jsonStr, true);
        if (!is_array($event)) continue;
        $t = $event['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if ($t !== '') sendSSE(['text' => $t]);
    }
    return strlen($data);
});

curl_exec($ch);
$err = curl_errno($ch) ? curl_error($ch) : '';
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err) sendSSE(['error' => 'API通信エラー: ' . $err]);
if ($httpCode !== 200 && $httpCode !== 0) sendSSE(['error' => "APIエラー (HTTP {$httpCode})"]);

echo "data: [DONE]\n\n";
flush();
exit;
