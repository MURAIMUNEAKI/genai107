<?php
// app14.php - 障害福祉サービス 個別支援計画 適正性審査（Gemini SSE プロキシ）

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
$docType = trim($input['doc_type'] ?? 'shuro');
$text    = trim($input['text'] ?? '');

if ($text === '') {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '審査対象の書類テキストを入力してください']);
    exit;
}

$text = strip_tags($text);
$text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
$text = mb_substr($text, 0, 18000);

$typeLabels = [
    'shuro'    => '就労継続支援・就労移行支援',
    'seikatsu' => '生活介護',
    'gh'       => '共同生活援助（グループホーム）',
    'jido'     => '児童発達支援・放課後等デイサービス',
];
$typeLabel = $typeLabels[$docType] ?? $typeLabels['shuro'];

$systemPrompt = <<<PROMPT
あなたは障害福祉サービスに精通したベテランのサービス管理責任者（児童発達支援管理責任者）であり、自治体の指導検査で個別支援計画を点検する審査員です。
アップロードされた「{$typeLabel}」事業所の個別支援計画書を、障害者総合支援法および指定障害福祉サービスの運営基準（サービス管理責任者によるサービス提供プロセス）に照らして審査し、採点と詳細レポートを作成してください。
※児童発達支援・放課後等デイの場合は「個別支援計画」を「児童発達支援計画／放課後等デイサービス計画」、本人を「児童及び保護者」と読み替えて審査すること。

【審査項目と配点（合計100点）】
1. アセスメント（本人の希望する生活・解決すべき課題の把握）— 15点：本人の意向・生活状況・課題が具体的に把握されているか
2. 本人・家族（児童は保護者）の意向の反映 — 10点：本人主体で、意向が計画に反映されているか
3. 総合的な支援の方針 — 10点：方針が課題・意向と整合し、事業所全体の支援方向が示されているか
4. 長期目標・短期目標の具体性 — 15点：目標が本人主体・測定可能で、達成期間が設定されているか（支援者目線の管理目標になっていないか）
5. 支援内容・到達目標・担当者の明記 — 15点：各目標に対する具体的支援内容、到達目標、担当者が記載されているか
6. 支援の提供期間・頻度・場面 — 10点：いつ・どれくらい・どの場面で支援するかが明確か
7. モニタリングの時期・方法（原則6か月ごと、児童・新規等は3か月ごと）— 10点：モニタリング時期と方法が定められ実施記録があるか
8. 本人・保護者への説明・同意・交付 — 10点：説明し、同意（署名）を得て、交付した記録があるか
9. 定期的な計画の見直し（再アセスメント・更新）の記載 — 5点：見直しのサイクルが位置づけられているか

【採点ルール】
- 各項目を ○（十分）/△（一部不備）/×（不備・記載なし）で判定し、配点に対する得点を付与すること（○=満点、△=配点の約半分、×=0点を目安）
- 書類に該当記載が無い場合は推測で補わず「×（記載なし）」とし、捏造しないこと
- 本人中心（パーソンセンタード）、本人の強み（ストレングス）の活用、意思決定支援の視点を重視すること
- 「支援者にとって都合のよい管理目標（例：問題行動を起こさせない）」になっていないかを厳しく確認すること

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
- （運営基準違反や同意・モニタリング欠落など。なければ「特になし」）

## 改善提案
- （本人主体・自立支援の観点からの具体的助言）

## 総評・次のアクション
（サービス管理責任者が次に取るべき対応を200〜400字でまとめる）

【厳守事項】
- 合計点は必ず項目別採点表の「得点」列の単純合計と一致させること。出力前に各得点（例：15+10+10+15+...）を実際に足し算して検算し、総合評価の合計点と表の合計が1点でも食い違う場合は表の合計値を正として書き直すこと
- 全項目が満点（○）の場合の合計は必ず100点であり、95点等と書かないこと
- 必ず日本語で出力すること
- 書類に書かれていない事実を創作しないこと
- 専門用語には簡潔な補足を付けること
PROMPT;

$userMessage = "以下は審査対象の個別支援計画書（{$typeLabel}）です。審査基準に沿って採点・レポートしてください。\n\n----- 書類ここから -----\n" . $text . "\n----- 書類ここまで -----";

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
