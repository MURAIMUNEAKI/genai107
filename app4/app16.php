<?php
// app16.php - 国民健康保険税（料）減免申請書 適正性審査（OpenAI SSE プロキシ）

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

// 判定の多数決: 同じ書類への判定を安定させるため、判定のみを9回並列で先に取得して多数決を取る
function voteJudgments($apiKey, $model, $systemPrompt, $userMessage) {
    $voteBase = [
        'model'    => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt . "\n\n【今回の特別指示】出力は判定サマリー1行のみとすること。形式：「判定: 1:○ 2:△ 3:× …」（全項目）。表・講評・説明など他の文字は一切出力しないこと。○か△（または△か×）で判断が割れるような微妙な項目は、必ず低い方の判定を選ぶこと。"],
            ['role' => 'user',   'content' => $userMessage],
        ],
        'temperature' => 0,
        'max_completion_tokens' => 300,
    ];

    $mh  = curl_multi_init();
    $chs = [];
    for ($i = 0; $i < 9; $i++) {
        $voteBase['seed'] = 101 + $i; // 票ごとに独立させる（全票同seedだと相関して多数決にならない）
        $c = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($c, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($voteBase, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        curl_multi_add_handle($mh, $c);
        $chs[] = $c;
    }
    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) curl_multi_select($mh, 1.0);
    } while ($active && $status === CURLM_OK);

    $seqs = [];
    foreach ($chs as $c) {
        $res = curl_multi_getcontent($c);
        curl_multi_remove_handle($mh, $c);
        curl_close($c);
        $j = json_decode($res, true);
        $content = isset($j['choices'][0]['message']['content']) ? $j['choices'][0]['message']['content'] : '';
        if (preg_match_all('/(\d+)\s*[:：]\s*([○◯△×])/u', $content, $m, PREG_SET_ORDER) && count($m) >= 4) {
            $seq = [];
            foreach ($m as $pair) {
                $idx = (int)$pair[1];
                if ($idx === 1 && count($seq) > 0) break;   // サマリーが繰り返された場合は1周目のみ採用
                if ($idx !== count($seq) + 1) continue;     // 1から始まる連番のみ採用
                $seq[] = ($pair[2] === '◯') ? '○' : $pair[2];
            }
            if (count($seq) >= 4) $seqs[] = $seq;
        }
    }
    curl_multi_close($mh);
    if (count($seqs) === 0) return null;

    // 最頻の項目数に揃え、位置ごとの多数決（同数なら△）
    $lenCounts = [];
    foreach ($seqs as $s) {
        $n = count($s);
        $lenCounts[$n] = isset($lenCounts[$n]) ? $lenCounts[$n] + 1 : 1;
    }
    arsort($lenCounts);
    reset($lenCounts);
    $len = (int)key($lenCounts);

    $verdict = [];
    for ($i = 0; $i < $len; $i++) {
        $counts = [];
        foreach ($seqs as $s) {
            if (count($s) !== $len) continue;
            $counts[$s[$i]] = isset($counts[$s[$i]]) ? $counts[$s[$i]] + 1 : 1;
        }
        if (count($counts) === 0) { $verdict[] = ($i + 1) . ':△'; continue; }
        arsort($counts);
        reset($counts);
        $top  = key($counts);
        $vals = array_values($counts);
        if (count($vals) > 1 && $vals[0] === $vals[1]) $top = '△';
        $verdict[] = ($i + 1) . ':' . $top;
    }
    return '判定: ' . implode(' ', $verdict);
}

$env    = loadEnv(__DIR__ . '/../api/.env');
$apiKey = $env['OPENAI_API_KEY'] ?? '';
$model  = $env['OPENAI_MODEL']   ?? 'gpt-5.4-nano';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'POST required']);
    exit;
}
if ($apiKey === '') {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '.env に OPENAI_API_KEY が設定されていません']);
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
- 各項目を ○（十分）/△（一部不備）/×（不備・記載なし）で判定し、次の固定得点を付与すること：○=配点の満点、△=配点15点の項目は8点・配点10点の項目は5点・配点5点の項目は3点、×=0点。これ以外の中間点は付けないこと
- ○の基準：確認要素が書類の記載として満たされていること。書類に「確認済」「添付あり」「一致」「記載あり」等の記載があれば、その事実は満たされているとみなすこと（写し・原本の実物照合は窓口・調査業務であり、本審査は書類のテキスト記載のみで判断する。実物確認が必要な点は減点せず「次のアクション」等で案内する）
- △の基準：書類の記載として明確に欠けている・不十分な・矛盾している要素を、指摘事項に具体的に書けること。具体的に指摘できなければ○とすること
- ×の基準：その項目の中核となる記載自体が書類に無い場合、または記載間に明らかな矛盾がある場合のみ。裏付けが書類の外にあること（納期限日との対比・照会結果・実物の写し等）だけを理由に×にしないこと
- その書類・事案に該当しない審査項目（その事案では不要な書類・証明等）は、減点せず○（該当なし）とし、指摘事項に「該当なし」と記すこと
- 書類が「職員が別途確認する」「面接記録に基づき確認」等と役所側の後続確認に委ねている事項は、書類の不備ではないため減点せず○（後続確認）とすること
- 添付書類・確認欄に「確認済」とある事項は、金額・期間等の詳細が本文に転記されていなくても満たされているとみなし、詳細の未転記を理由に△にしないこと
- 記載間の明らかな矛盾（番号・日付・金額・氏名・曜日等の不一致）がある項目は必ず×とし、矛盾箇所を指摘事項に明記すること
- 判定手順：各項目について、(1)項目説明に挙げた確認要素を心の中で列挙し、(2)書類の記載・確認欄と1つずつ突合し、(3)欠落・矛盾した要素が0なら○、1つ以上あるが中核の記載はあるなら△、中核の記載が無い・矛盾があるなら×、と機械的に決めること。印象・文章の巧拙・書きぶりで判定を上下させないこと
- この審査は再現性が最重要である。同じ書類を何回審査しても、必ず同じ判定・同じ得点・同じ合計点を返すこと
- 書類に該当記載が無い場合は推測で補わず「×（記載なし）」とし、捏造しないこと
- 公平性・公金の適正執行の観点から、安易な減免認定を避け、要件未充足や証明不足は明確に指摘すること
- 判定した制度に正しく合致した適正な申請は、制度名・様式の呼称の違いだけを理由に項目2・5・7で減点しないこと

【出力フォーマット（Markdownで必ずこの構成）】
## 減免区分の判定
- 判定した制度：A〜Dのいずれか（例：D 非自発的失業者の軽減）と、そう判定した根拠（1〜2行）
- 該当性：〔該当〕〔要追加確認〕〔非該当の疑い〕のいずれかと、その理由（2〜3行）

## 判定サマリー
判定: 1:○ 2:△ 3:× …（全9項目の判定のみを、他の文章を書く前にこの形式の1行で先に確定させること。以降の項目別採点表・合計点はこの行と完全に一致させること）

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

// 判定固定記録: 同一書類には常に同一の判定を使う（初回のみ9票多数決で確定し、以後は記録を再利用。レポート文は毎回AIが新規生成）
$verdictDir  = __DIR__ . '/verdicts';
$verdictFile = $verdictDir . '/' . hash('sha256', $model . "\n" . $systemPrompt . "\n" . $userMessage) . '.txt';
$fixedVerdict = null;
if (is_file($verdictFile)) {
    $saved = trim((string)file_get_contents($verdictFile));
    if ($saved !== '' && strpos($saved, '判定') === 0) $fixedVerdict = $saved;
}
if ($fixedVerdict === null) {
    $fixedVerdict = voteJudgments($apiKey, $model, $systemPrompt, $userMessage);
    if ($fixedVerdict !== null) {
        if (!is_dir($verdictDir)) @mkdir($verdictDir, 0755, true);
        @file_put_contents($verdictFile, $fixedVerdict);
    }
}
if ($fixedVerdict !== null) {
    $systemPrompt .= "\n\n【確定済み判定】\nこの書類は事前審査により各項目の判定が次のとおり確定している。判定サマリー・項目別採点表の判定・得点・合計点は、必ずこの判定と完全に一致させること（変更禁止）：\n" . $fixedVerdict;
}

$payload = json_encode([
    'model'    => $model,
    'messages' => [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user',   'content' => $userMessage],
    ],
    'temperature' => 0,
    'seed'        => 42,
    'stream'      => true,
], JSON_UNESCAPED_UNICODE);

$url = 'https://api.openai.com/v1/chat/completions';

while (ob_get_level()) ob_end_clean();
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

function sendSSE($data) {
    echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
}

if ($fixedVerdict !== null) sendSSE(['verdict' => $fixedVerdict]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_TIMEOUT        => 120,
    CURLOPT_SSL_VERIFYPEER => false,
]);

$buffer = '';
$raw    = '';
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$buffer, &$raw) {
    if (strlen($raw) < 4000) $raw .= $data;
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
        $t = $event['choices'][0]['delta']['content'] ?? '';
        if ($t !== '') sendSSE(['text' => $t]);
    }
    return strlen($data);
});

curl_exec($ch);
$err = curl_errno($ch) ? curl_error($ch) : '';
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err) sendSSE(['error' => 'API通信エラー: ' . $err]);
if ($httpCode !== 200 && $httpCode !== 0) {
    $j = json_decode($raw, true);
    $apiMsg = isset($j['error']['message']) ? mb_substr($j['error']['message'], 0, 200) : '';
    sendSSE(['error' => "APIエラー (HTTP {$httpCode})" . ($apiMsg !== '' ? ' ' . $apiMsg : '')]);
}

echo "data: [DONE]\n\n";
flush();
exit;
