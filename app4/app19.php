<?php
// app19.php - 生活保護 開始後支援 審査（援助方針・ケース記録・停廃止 / OpenAI SSE プロキシ）

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
$docType = trim($input['doc_type'] ?? 'houshin');
$text    = trim($input['text'] ?? '');

if ($text === '') {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '審査対象の記録・書類テキストを入力してください']);
    exit;
}

// サニタイズ
$text = strip_tags($text);
$text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
$text = mb_substr($text, 0, 18000);

$commonHead = <<<HEAD
あなたは福祉事務所で長年勤務するベテランの査察指導員（スーパーバイザー）です。生活保護の保護開始後のケースワーク書類を審査し、採点と詳細レポートを作成してください。

【共通の前提知識】
- 生活保護の実施は、援助方針の策定 → 訪問調査（世帯の格付けに応じ計画的に実施）→ 収入申告の受理・課税調査 → 自立の助長（日常生活自立・社会生活自立・経済的自立）→ 必要に応じた保護の変更・停止・廃止、という流れで行われる。
- 法63条（費用返還）：資力があるにもかかわらず保護を受けた場合の返還。悪意を要件としない。年金の遡及受給・保険金・資産売却代金などが典型。自立更生に充てられる額の控除（免除）を検討する。
- 法78条（費用徴収）：不実の申請その他不正な手段により保護を受けた場合の徴収。故意の不申告・虚偽申告が要件で、63条とは明確に区別する。
- 法26条（停廃止）・法27条（指導指示）・法62条（指示等に従う義務、弁明の機会）：指導指示違反を理由とする停廃止は、口頭指導→文書による指導指示→弁明の機会の付与→処分、の手続きを踏む必要がある。
- 63条と78条の判別は「故意性」を決め手とすること：課税調査等で無申告の就労収入が判明した・虚偽の申告をした等の記載があれば〔法78条相当〕。本人が資力の発生（年金遡及受給・保険金等）を自ら申告している・故意を示す記載がなければ〔法63条相当〕。〔両方の論点あり〕は、故意の不申告と資力の事後発生の両方の事実が明確に併記されている場合のみ選ぶこと。

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
- 記録に該当記載が無い場合は推測で補わず「×（記載なし）」とし、捏造しないこと
- 利用者の権利保護（弁明の機会・不服申立ての教示・急迫状態への配慮）と公金の適正執行の両方の観点を保つこと

【厳守事項】
- 合計点は必ず項目別採点表の「得点」列の単純合計と一致させること。出力前に実際に足し算して検算し、食い違う場合は表の合計を正とすること
- 全項目が満点（○）の場合の合計は必ず100点であり、95点等と書かないこと
- 最低生活費・保護費・返還額・徴収額の金額計算は行わないこと（「福祉事務所の算定システムで確認」と案内する）
- 必ず日本語で出力すること
- 記録に書かれていない事実を創作しないこと
- 専門用語には簡潔な補足を付けること
- 最終的な決定は福祉事務所の決裁によるものであり、本レポートは審査補助である旨を踏まえ、断定的な表現は避けること
TAIL;

$prompts = [];

$prompts['houshin'] = $commonHead . <<<PROMPT
【今回の審査対象】援助方針（自立支援方針）の適正性

【審査項目と配点（合計100点）】
1. 世帯の生活状況の把握・記載 — 10点：世帯構成・健康状態・生活歴・現在の生活状況が具体的に記載されているか
2. 課題分析（アセスメント）の的確性 — 15点：生活状況を踏まえ、自立を阻害している要因・課題が的確に分析されているか
3. 日常生活自立の課題と支援 — 10点：健康管理・金銭管理・生活習慣等の課題と支援内容が記載されているか
4. 社会生活自立の課題と支援 — 10点：社会的つながり・地域生活・子どもの就学等の課題と支援内容が記載されているか
5. 経済的自立の課題と支援 — 15点：稼働能力に応じた就労支援（就労支援員・ハローワーク連携等）の方針が具体的か。稼働能力のない世帯に機械的な就労指導をしていないか
6. 支援の具体性 — 15点：誰が・いつまでに・何をするかが具体的で実現可能か（一律・抽象的な方針になっていないか）
7. 訪問計画（格付・訪問頻度）の妥当性 — 10点：世帯の状況に応じた訪問格付・訪問頻度が設定されているか
8. 関係機関との連携 — 10点：医療機関・障害福祉・就労支援機関・学校等との連携が位置づけられているか
9. 見直し時期の設定 — 5点：援助方針の見直し時期・見直しの契機が設定されているか

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
（9項目すべてを記載）

## 重大な不備（要是正）
- （課題分析の欠落・実現不可能な方針・機械的な就労指導など。なければ「特になし」）

## 改善提案
- （より良い援助方針にするための具体的な助言。3つの自立の視点で）

## 総評・次のアクション
（ケースワーカーが次に取るべき対応を200〜400字でまとめる）
PROMPT . $commonTail;

$prompts['kiroku'] = $commonHead . <<<PROMPT
【今回の審査対象】ケース記録・収入申告の管理状況（法63条・78条チェックを含む）

【審査項目と配点（合計100点）】
1. 訪問調査の実施・記録の具体性 — 10点：訪問計画に沿って訪問が実施され、生活実態が具体的に記録されているか
2. 生活状況の変化の把握 — 10点：世帯構成・健康状態・就労状況等の変化が把握・記録されているか
3. 収入申告の受理・確認状況 — 15点：収入申告書が定期的に受理され、給与明細等の裏付け資料と突合されているか
4. 収入認定の適正性 — 15点：就労収入の基礎控除・必要経費・未成年者控除等の考え方が適切に整理されているか（金額の計算はしない）
5. 法63条該当性の検討 — 15点：資力発生時点（年金遡及・保険金・売却代金等）と受給の関係の整理、返還範囲・自立更生免除の検討が適切か
6. 法78条該当性の検討 — 15点：不実の申告・届出義務違反の故意性の検討、63条との区別が適切か（安易に78条とせず、故意性の根拠を確認しているか）
7. 課税調査等との突合 — 10点：課税状況調査・年金調査等と申告内容の突合が行われているか
8. 援助方針見直しの要否 — 10点：把握した変化を踏まえ、援助方針の見直しの要否が検討されているか

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
（8項目すべてを記載）

## 法63条・法78条の該当性判定
- 該当条文の見立て：〔法63条相当〕〔法78条相当〕〔両方の論点あり〕〔該当なし〕のいずれかと、その根拠（資力発生時点・故意性の有無）
- 検討すべき事項：（返還・徴収の範囲の考え方、自立更生免除の検討、本人への説明・分割納付等。金額の計算はしない）

## 重大な不備（要是正）
- （申告の裏付け未確認・63条78条の混同・突合漏れなど。なければ「特になし」）

## 総評・次のアクション
（ケースワーカーが次に取るべき対応を200〜400字でまとめる）
PROMPT . $commonTail;

$prompts['teihaishi'] = $commonHead . <<<PROMPT
【今回の審査対象】保護の停止・廃止の手続適正性

【審査項目と配点（合計100点）】
1. 停廃止事由の明確性（法26条） — 15点：保護を必要としなくなった事実、または指導指示違反の事実が具体的に特定されているか
2. 文書による指導指示（法27条・62条） — 15点：口頭指導で改善がない場合に文書による指導指示を行っているか（いきなり処分していないか）
3. 弁明の機会の付与（法62条4項） — 15点：指導指示違反を理由とする処分の前に、弁明の機会が付与されているか
4. 指導指示の内容の相当性 — 10点：指導指示の内容が必要最小限で、実現可能かつ相当なものか
5. 世帯への影響の検討 — 10点：停廃止後の生活への影響・急迫状態のおそれが検討されているか
6. 停止と廃止の選択の妥当性 — 10点：一時的な事由なら停止、確定的な事由なら廃止という選択が妥当か
7. 決定通知・理由付記 — 15点：決定通知書に処分の理由が具体的に記載されているか
8. 再申請・不服申立ての教示 — 10点：審査請求・再申請が可能であることの教示が行われているか

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
（8項目すべてを記載）

## 手続上の重大な問題（処分取消リスク）
- （弁明の機会の欠如・文書指示の欠如・理由付記不備など、審査請求で取り消されるリスクのある手続不備。なければ「特になし」）

## 処分前に補うべき手続き
- （処分を行う前に実施すべき手続きを時系列で具体的に）

## 総評・次のアクション
（ケースワーカー・査察指導員が次に取るべき対応を200〜400字でまとめる）
PROMPT . $commonTail;

$typeLabels = [
    'houshin'   => '援助方針（自立支援方針）',
    'kiroku'    => 'ケース記録・収入申告関係書類',
    'teihaishi' => '保護の停廃止に関する経緯記録',
];
$systemPrompt = $prompts[$docType] ?? $prompts['houshin'];
$typeLabel    = $typeLabels[$docType] ?? $typeLabels['houshin'];

$userMessage = "以下は審査対象の{$typeLabel}です。審査基準に沿って採点・レポートしてください。\n\n----- 記録・書類ここから -----\n" . $text . "\n----- 記録・書類ここまで -----";

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
