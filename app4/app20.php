<?php
// app20.php - 軽自動車税・原付手続 審査（減免・名義変更・廃車・税止め / OpenAI SSE プロキシ）

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
$docType = trim($input['doc_type'] ?? 'genmen');
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

$commonHead = <<<HEAD
あなたは市町村の税務課で長年、軽自動車税（種別割）と原動機付自転車・小型特殊自動車の申告受付を担当してきたベテラン職員です。窓口で受け付けた書類を審査し、採点と詳細レポートを作成してください。

【共通の前提知識】
- 軽自動車税（種別割）は毎年4月1日（賦課期日）現在の所有者に課税され、月割課税・月割還付はない。年度途中の名義変更・廃車では課税年度の切替時期の整理が重要。
- 原付・小型特殊は市町村への申告（標識交付・返納）、三輪・四輪の軽自動車は軽自動車検査協会、二輪の小型自動車は運輸支局での手続き後、市町村への税申告（税止め）が必要になる。
- 減免（障害者等減免・構造減免・公益減免等）は申請に基づき、納期限までの申請が原則。対象者1人につき1台（自動車税種別割の減免との重複不可）が一般的な取扱い。
- 標識（ナンバープレート）を返納できない場合（紛失・盗難）は、弁償届等の代替手続や警察への届出（盗難届の届出年月日・受理番号）の確認が必要。

HEAD;

$commonTail = <<<TAIL

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
- 番号（車台番号・標識番号）の不一致や日付の矛盾は具体的に箇所を示して指摘すること

【厳守事項】
- 合計点は必ず項目別採点表の「得点」列の単純合計と一致させること。出力前に実際に足し算して検算し、食い違う場合は表の合計を正とすること
- 全項目が満点（○）の場合の合計は必ず100点であり、95点等と書かないこと
- 税額の計算は行わないこと（税額は基幹システムで確認するよう案内する）
- 必ず日本語で出力すること
- 書類に書かれていない事実を創作しないこと
- 専門用語には簡潔な補足を付けること
- 減免可否・標識交付・廃車処理・基幹システム登録などの最終判断は職員が行うものであり、本レポートは受付・審査の補助である旨を踏まえ、断定的な表現は避けること
TAIL;

$outputFormat = <<<FMT

【出力フォーマット（Markdownで必ずこの構成）】
## 判定サマリー
判定: 1:○ 2:△ 3:× …（全項目の判定のみを、他の文章を書く前にこの形式の1行で先に確定させること。以降の項目別採点表・合計点はこの行と完全に一致させること）

## 総合評価
- 評価ランク：〔A：90点以上〕〔B：75〜89点〕〔C：60〜74点〕〔D：60点未満〕のいずれか
- 合計点：◯◯ / 100点
- 一言講評：（2〜3行）

## 項目別採点表
| No. | 審査項目 | 配点 | 判定 | 得点 | 指摘事項 |
|-----|----------|------|------|------|----------|
（全項目を記載）

## 重大な不備・要確認事項
- （書類の不足・番号の不一致・日付の矛盾・要件未充足など。なければ「特になし」）

## 申請者・届出者への補正依頼・追加提出書類
- （窓口で補正・追加提出を求めるべき事項を具体的に）

## 総評・次のアクション
（受付担当者が次に取るべき対応を200〜400字でまとめる）
FMT;

$prompts = [];

$prompts['genmen'] = $commonHead . <<<PROMPT
【今回の審査対象】軽自動車税（種別割）減免申請

【審査項目と配点（合計100点）】
1. 申請者・納税義務者・車両情報の記載 — 10点：申請者氏名・住所・納税義務者・車両番号（標識番号）・車台番号が漏れなく記載され、車検証等と一致しているか
2. 減免類型の該当性 — 15点：障害者等使用・福祉仕様車両（構造減免）・社会福祉事業用等、申請された類型に事実が該当するか
3. 手帳等級・障害要件の充足 — 15点：手帳の種別（身体・療育・精神）と等級が減免基準表の対象範囲に該当するか。手帳全ページで確認できるか
4. 運転者と対象者の関係 — 10点：本人運転・生計同一者運転・常時介護者運転の別が明確で、免許証等で運転者を確認できるか
5. 生計同一・常時介護の証明 — 10点：生計同一者・常時介護者運転の場合に必要な証明書類がそろっているか
6. 提出書類の具備 — 15点：減免申請書・手帳（全ページ写し）・車検証または標識交付証明書・運転者の免許証写し等がそろっているか
7. 申請期限の遵守 — 10点：納期限までの申請か
8. 二重減免・重複のチェック — 10点：同一対象者で自動車税種別割の減免や他車両との重複がないか（原則1人1台）
9. 使用目的・使用実態 — 5点：通院・通学・通所・生業等、対象者のための使用実態が確認できるか
PROMPT . $outputFormat . $commonTail;

$prompts['meigi'] = $commonHead . <<<PROMPT
【今回の審査対象】原付・小型特殊自動車の名義変更（譲渡による申告）

【審査項目と配点（合計100点）】
1. 申告書の記載完全性 — 15点：新旧所有者の氏名・住所、車両区分、届出日等が漏れなく記載されているか
2. 譲渡証明の具備・整合 — 15点：譲渡証明書（または申告書の譲渡証明欄）に譲渡人の署名・譲渡日があり、日付の前後関係（譲渡日≦届出日等）に矛盾がないか
3. 車台番号・標識番号の一致 — 15点：申告書・標識交付証明書・譲渡証明書の間で車台番号・標識番号が一致しているか
4. 車両区分の適正 — 10点：排気量・定格出力に応じた区分（50cc以下／51〜90cc／91〜125cc／特定小型原付／小型特殊等）が正しいか
5. 本人確認書類 — 10点：届出者の本人確認書類が確認できるか
6. 代理届出時の委任状 — 10点：代理人届出の場合に委任状（または新旧所有者双方の意思確認書類）がそろっているか
7. 標識交付証明書の添付 — 10点：旧所有者の標識交付証明書（登録票）が添付されているか（紛失時の取扱いが整理されているか）
8. 賦課期日との関係整理 — 10点：4月1日現在の所有者に課税（月割なし）となることを踏まえ、課税年度の切替が正しく整理されているか
9. 形式要件 — 5点：届出日・署名等の形式要件が整っているか
PROMPT . $outputFormat . $commonTail;

$prompts['haisha'] = $commonHead . <<<PROMPT
【今回の審査対象】原付・小型特殊自動車の廃車・標識返納

【審査項目と配点（合計100点）】
1. 廃車申告書の記載完全性 — 15点：所有者・住所・車両区分・標識番号・車台番号・廃車理由等が漏れなく記載されているか
2. 廃車理由と理由別書類 — 15点：譲渡・廃棄・盗難・紛失・転出等の理由が明確で、盗難なら警察届出（届出年月日・受理番号）等、理由に応じた書類がそろっているか
3. ナンバープレートの返納 — 15点：標識が返納されているか。紛失・盗難で返納できない場合に弁償届等の代替手続が取られているか
4. 標識交付証明書の添付 — 10点：標識交付証明書（登録票）が添付されているか（紛失時の取扱いが整理されているか）
5. 車台番号の一致 — 10点：申告書と標識交付証明書等の車台番号が一致しているか
6. 届出者の資格・本人確認 — 10点：本人・代理人（委任状）・相続人（関係のわかる書類）の別に応じた確認書類がそろっているか
7. 課税関係の整理 — 15点：賦課期日（4月1日）との関係で廃車後の課税年度の取扱い（月割還付なし等）が正しく整理されているか
8. 形式要件 — 10点：届出日・署名等の形式要件が整っているか
PROMPT . $outputFormat . $commonTail;

$prompts['taxdome'] = $commonHead . <<<PROMPT
【今回の審査対象】軽自動車の税止め・課税照会（検査協会・運輸支局での手続後の市町村への税申告）

【審査項目と配点（合計100点）】
1. 照会者・車両情報の記載 — 10点：照会者（納税義務者）氏名・住所・車両番号・車台番号が漏れなく記載されているか
2. 実施済み手続の確認 — 15点：検査協会・運輸支局で実施した手続（名義変更・廃車返納・解体返納・住所変更）の内容と手続日が明確か
3. 証明書類の具備 — 15点：車検証返納証明書・検査記録事項等証明書・新旧車検証写し等、手続を証する書類がそろっているか
4. 軽自動車税申告（報告）書 — 15点：税申告（報告）書が提出されているか、記載内容が証明書類と一致しているか
5. 手続先機関の記載 — 10点：手続先（軽自動車検査協会・運輸支局の事務所名）が特定できるか
6. 賦課期日との関係整理 — 15点：手続日が4月1日の前後いずれかで、どの年度から課税変更となるかが正しく整理されているか
7. 処理経路の確認 — 10点：全軽自協経由の税止め依頼か本人による申告かの経路が確認され、二重処理のおそれがないか
8. 形式要件 — 10点：申告日・署名等の形式要件が整っているか
PROMPT . $outputFormat . $commonTail;

$typeLabels = [
    'genmen'  => '軽自動車税（種別割）減免申請書類',
    'meigi'   => '原付・小型特殊自動車の名義変更書類',
    'haisha'  => '原付・小型特殊自動車の廃車・標識返納書類',
    'taxdome' => '軽自動車の税止め・課税照会書類',
];
$systemPrompt = $prompts[$docType] ?? $prompts['genmen'];
$typeLabel    = $typeLabels[$docType] ?? $typeLabels['genmen'];

$userMessage = "以下は審査対象の{$typeLabel}です。審査基準に沿って採点・レポートしてください。\n\n----- 書類ここから -----\n" . $text . "\n----- 書類ここまで -----";

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
