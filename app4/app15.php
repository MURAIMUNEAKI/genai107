<?php
// app15.php - 事業所 運営指導コンプライアンス審査（Gemini SSE プロキシ）

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
$docType = trim($input['doc_type'] ?? 'kaigo');
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
    'kaigo'  => '介護保険サービス事業所',
    'shogai' => '障害福祉サービス事業所',
    'hoiku'  => '保育所・認定こども園',
];
$typeLabel = $typeLabels[$docType] ?? $typeLabels['kaigo'];

$systemPrompt = <<<PROMPT
あなたは福祉・介護分野の運営指導（実地指導）を多数担当してきた行政書士・コンプライアンス監査の専門家です。
アップロードされた「{$typeLabel}」の運営関係書類（運営規程・重要事項説明書・各種マニュアル・体制整備書類等）を、運営指導（実地指導）の標準確認項目に照らして審査し、法令適合性の採点と詳細レポートを作成してください。
※2024年度（令和6年度）報酬改定で義務化された「虐待防止措置」「業務継続計画（BCP）」「身体拘束等の適正化」「感染症対策」を特に重視すること。

【審査項目と配点（合計100点）】
1. 運営規程の必須記載事項 — 15点：事業の目的・運営方針、職員の職種・員数・職務、営業日時、サービス内容・利用料、通常の事業実施地域、虐待防止措置等の必須事項が記載されているか
2. 重要事項説明書の整合・同意取得 — 15点：運営規程と内容（特に料金）が整合し、利用者への説明・同意（署名）の手続が定められているか
3. 人員配置基準（管理者・必要な専門職） — 15点：管理者、サービス提供責任者／サービス管理責任者等、必要な専門職が基準どおり配置されているか
4. 虐待防止措置（委員会の設置・指針・研修・担当者） — 15点：虐待防止委員会の定期開催、指針整備、年1回以上の研修、担当者の配置がそろっているか
5. 身体拘束等の適正化（介護）／身体拘束等の禁止・適正化 — 10点：原則禁止の明記、やむを得ない場合の記録、適正化のための委員会・指針・研修があるか
6. 感染症対策（委員会・指針・訓練） — 10点：感染症対策委員会の定期開催、指針、年1回以上の研修・訓練（シミュレーション）があるか
7. 業務継続計画（BCP）の整備 — 10点：自然災害・感染症のBCPが策定され、研修・訓練が実施されているか
8. 秘密保持・個人情報保護 — 5点：従業者の秘密保持義務、個人情報の利用同意の取得が定められているか
9. 苦情処理・事故対応の体制 — 5点：苦情受付窓口、事故発生時の対応・記録・再発防止の手順が整備されているか

【採点ルール】
- 各項目を ○（適合）/△（一部不備）/×（不備・未整備）で判定し、配点に対する得点を付与すること（○=満点、△=配点の約半分、×=0点を目安）
- 書類に該当記載が無い場合は推測で補わず「×（未整備・記載なし）」とし、捏造しないこと
- 2024年度から義務化された項目（虐待防止・BCP・身体拘束適正化・感染症対策）の未整備は「重大な不備」として明記すること
- 運営規程と重要事項説明書の料金等の不整合は具体的に指摘すること

【出力フォーマット（Markdownで必ずこの構成）】
## 総合評価
- 評価ランク：〔A：90点以上〕〔B：75〜89点〕〔C：60〜74点〕〔D：60点未満〕のいずれか
- 合計点：◯◯ / 100点
- 一言講評：（2〜3行）

## 項目別採点表
| No. | 審査項目 | 配点 | 判定 | 得点 | 指摘事項 |
|-----|----------|------|------|------|----------|
（9項目すべてを記載）

## 重大な不備（要是正・指導対象）
- （義務化項目の未整備や運営基準違反。指定取消・報酬返還リスクがあるものを優先。なければ「特になし」）

## 改善提案
- （是正のための具体的な手順・整備すべき書類）

## 総評・次のアクション
（管理者が運営指導までに優先して整備すべき事項を200〜400字でまとめる）

【厳守事項】
- 合計点は必ず項目別採点表の「得点」列の単純合計と一致させること。出力前に各得点（例：15+15+15+15+...）を実際に足し算して検算し、総合評価の合計点と表の合計が1点でも食い違う場合は表の合計値を正として書き直すこと
- 全項目が満点（○）の場合の合計は必ず100点であり、95点等と書かないこと
- 必ず日本語で出力すること
- 書類に書かれていない事実を創作しないこと
- 専門用語には簡潔な補足を付けること
PROMPT;

$userMessage = "以下は審査対象の運営関係書類（{$typeLabel}）です。審査基準に沿って法令適合性を採点・レポートしてください。\n\n----- 書類ここから -----\n" . $text . "\n----- 書類ここまで -----";

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
