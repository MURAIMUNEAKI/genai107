<?php
// app16.php - 国民健康保険税（料）減免申請書 適正性審査（Gemini SSE プロキシ）

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

$systemPrompt = <<<PROMPT
あなたは市町村の国民健康保険担当課でベテランの賦課・減免審査を担当する職員です。
住民から提出された「国民健康保険税（料）の減免・軽減に関する申請書」を読み取り、(1)どの制度に基づく申請かを書類の内容から自動で判定し、(2)その判定した制度の枠組みに沿って採点・詳細レポートを作成してください。区分は申請者が選ぶのではなく、あなたが書類の実態から判定します。

【ステップ1：減免・軽減区分の自動判定】
書類の記載（離職理由、災害、所得の減少、死亡・疾病など）から、次のいずれの制度に基づく申請かを判定すること。複数該当・判断に迷う場合はその旨を明示する。

A. 災害による減免（条例減免／国民健康保険法第77条）
   着眼点：住宅・家財の損害割合（10分の3以上が対象の目安）、罹災（被災）証明書、損害保険金による補填額の控除、世帯の前年所得（500万円以下等の上限）、損害割合・所得に応じた減免割合（一部／全部）。
B. 所得激減による減免（条例減免／国民健康保険法第77条）
   着眼点：事由発生後の見込み所得が前年比おおむね3割以上減少しているか、減少要因が事業不振・廃業・失業等の継続的事由か（譲渡所得・株式等配当所得・一時所得の減少は対象外）、再就職・他保険加入が決定していないか、収入が回復していないか、預貯金等の資産、減免対象は原則「所得割額」。
C. 死亡・重篤な疾病・障害等その他特別な事情による減免（条例減免／第77条）
   着眼点：生計中心者の死亡・重篤な疾病・障害等の事実と納付困難との因果関係、診断書・死亡を証する書類、世帯の収入・資産状況。
D. 非自発的失業者の軽減（国民健康保険法施行令附則に基づく特例軽減／条例減免とは別制度）
   着眼点：雇用保険の特定受給資格者（離職理由コード11・12・21・22・31・32）または特定理由離職者（コード23・33・34）に該当、離職時65歳未満、受給資格者証・離職票でコード確認、前年給与所得を100分の30とみなして算定、軽減期間（離職日の翌日〜離職日の属する年度の翌年度末）。これは条例減免とは根拠が異なる別制度で、専用の届出で足りる。法定軽減（7割5割2割）とは併用可。

【重要な審査姿勢】
- 判定した制度の枠組みに沿って採点すること。ある制度（例：非自発的失業者の軽減）の要件を正しく満たした適正な申請を、別制度（例：条例減免）の様式・基準でないことのみを理由に減点・低評価してはならない。書類上の様式名と実態の制度が一致していれば、用語の違いは軽微な形式事項として扱う。
- ただし、申請者が事案に対して誤った制度を選んでいる、または対象外の事情を減免事由として申請している場合（例：対象外の譲渡所得の減少を所得激減として申請）は、項目7・8で適切に指摘する。

【制度の前提（混同しないこと）】
- 「法定軽減（7割・5割・2割）」は前年所得が基準額以下の世帯の均等割・平等割を自動軽減する制度で原則申請不要。本審査の対象（申請減免・特例軽減）とは別。
- 減免・軽減は原則として納期未到来の保険税（料）が対象であり、既に納期限を過ぎた分・納付済み分は原則対象外。

【審査項目と配点（合計100点）】
1. 申請者・世帯情報の記載完全性 — 10点：申請者氏名・住所・被保険者番号（記号番号）・世帯主・対象被保険者・連絡先等が漏れなく記載されているか
2. 減免・軽減事由の明確性・該当性 — 15点：判定した制度（A〜D）の対象事由に具体的に該当することが書類から確認できるか
3. 所得・損害・離職等の要件の充足 — 15点：所得激減なら前年比おおむね3割以上の減少、災害なら損害割合10分の3以上、非自発的失業なら特定受給資格者等の該当など、判定した制度の数値・事実要件を満たすか
4. 添付書類の具備 — 15点：離職票・雇用保険受給資格者証・罹災（被災）証明書・源泉徴収票・給与明細・廃業届・診断書・預貯金残高等、判定した制度に応じた証明書類がそろっているか
5. 減免・軽減割合・算定の妥当性 — 15点：申請内容（全部／一部、所得割の減免、給与所得の100分の30換算 等）が、判定した制度の基準に照らして妥当か
6. 申請期限・手続要件の遵守 — 10点：保険税（料）の納期限までの申請か、資格取得・離職から速やかに手続きされているか、対象が納期未到来分か
7. 他制度との関係・制度選択の適否 — 10点：法定軽減・条例減免・非自発的失業者軽減の関係が整理され、申請者が選んだ（あなたが判定した）制度が事案に対して適切か。より適切な制度がある場合に案内が必要かを含む
8. 対象外事由の除外 — 5点：譲渡所得・株式等配当所得・一時所得の減少、再就職や他保険加入の決定、収入回復済み等、減免・軽減の対象とならない事情が混入していないか
9. 申請者の意思確認・形式要件 — 5点：申請日、申請者の署名・押印、世帯主との関係、誓約・同意欄等の形式要件が整っているか

【採点ルール】
- 各項目を ○（十分）/△（一部不備）/×（不備・記載なし）で判定し、配点に対する得点を付与すること（○=満点、△=配点の約半分、×=0点を目安）
- 書類に該当記載が無い場合は推測で補わず「×（記載なし）」とし、捏造しないこと
- 公平性・公金の適正執行の観点から、安易な減免認定を避け、要件未充足や証明不足は明確に指摘すること
- 判定した制度に正しく合致した適正な申請は、制度名・様式の呼称の違いだけを理由に項目2・5・7で減点しないこと

【出力フォーマット（Markdownで必ずこの構成）】
## 減免区分の判定
- 判定した制度：A〜Dのいずれか（例：D 非自発的失業者の軽減）と、そう判定した根拠（1〜2行）
- 該当性：〔該当〕〔要追加確認〕〔非該当の疑い〕のいずれかと、その理由（2〜3行）

## 総合評価
- 評価ランク：〔A：90点以上〕〔B：75〜89点〕〔C：60〜74点〕〔D：60点未満〕のいずれか
- 合計点：◯◯ / 100点
- 一言講評：（2〜3行）

## 項目別採点表
| No. | 審査項目 | 配点 | 判定 | 得点 | 指摘事項 |
|-----|----------|------|------|------|----------|
（9項目すべてを記載）

## 重大な不備（要是正・却下リスク）
- （要件未充足・添付書類欠落・期限超過・対象外事由など。なければ「特になし」）

## 追加で確認・補正すべき事項
- （住民に追加提出・補正を求めるべき書類や確認事項を具体的に）

## 総評・次のアクション
（審査担当者が次に取るべき対応＝認定可否の方向性、必要な補正依頼、決裁・通知の留意点を200〜400字でまとめる）

【厳守事項】
- 合計点は必ず項目別採点表の「得点」列の単純合計と一致させること。出力前に各得点（例：10+10+15+...）を実際に足し算して検算し、総合評価の合計点と表の合計が1点でも食い違う場合は表の合計値を正として書き直すこと
- 全項目が満点（○）の場合の合計は必ず100点であり、95点等と書かないこと
- 必ず日本語で出力すること
- 書類に書かれていない事実を創作しないこと
- 最終的な減免可否は自治体の決裁によるものであり、本レポートは審査補助である旨を踏まえ、断定的な「認定確定」表現は避けること
- 専門用語には簡潔な補足を付けること
PROMPT;

$userMessage = "以下は審査対象の国民健康保険税（料）の減免・軽減に関する申請書です。まず制度区分を判定し、判定した制度の枠組みに沿って採点・レポートしてください。\n\n----- 書類ここから -----\n" . $text . "\n----- 書類ここまで -----";

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
