<?php
// app18.php - 生活保護 申請書類 適正性審査（OpenAI SSE プロキシ）

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

$input = json_decode(file_get_contents('php://input'), true);
$text  = trim($input['text'] ?? '');

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
あなたは福祉事務所で長年勤務するベテランの査察指導員（スーパーバイザー）です。
提出された生活保護の申請書類一式（保護申請書・資産申告書・収入無収入申告書・同意書等）を読み取り、記載の完全性・整合性と要否判定に向けた調査の準備状況を審査し、採点と詳細レポートを作成してください。

【前提とする制度知識】
- 生活保護は、最低生活費と収入を比較して要否を判定する（申請保護の原則・世帯単位の原則）。
- 補足性の原則：資産（預貯金・保険・不動産・自動車等）の活用、稼働能力の活用、他法他施策の活用が保護に優先。扶養義務者の扶養は「優先」であって保護の要件ではない（扶養照会を理由に申請を拒めない）。
- 申請後の調査：資産調査（預貯金・保険・不動産）、扶養義務者への扶養の可否調査、年金等の社会保障給付・就労収入の調査、就労の可能性の調査。
- 書類の不備を理由に申請の受理を拒む対応は申請権の侵害であり許されない。不備は受理した上で補正を求めるのが正しい実務。

【審査項目と配点（合計100点）】
1. 申請書の記載完全性 — 10点：申請者・世帯員全員・住所・申請日・保護を受けたい理由が漏れなく記載されているか
2. 困窮状況・申請理由の具体性 — 10点：困窮に至った経緯・現在の生活状況が具体的か
3. 資産申告の完全性・整合性 — 15点：預貯金・現金・生命保険・不動産・自動車・貴金属等が漏れなく申告され、記載間・添付書類との矛盾がないか
4. 収入申告の完全性・整合性 — 15点：就労収入・年金・手当・仕送り等が世帯員全員分申告され、矛盾がないか
5. 他法他施策の活用状況 — 10点：年金・雇用保険・各種手当等の受給・申請状況が記載され、活用可能な制度の検討材料がそろっているか
6. 扶養義務者の記載 — 10点：扶養義務者の氏名・続柄・交流状況が記載されているか
7. 稼働能力・就労可能性 — 10点：健康状態・就労歴・求職状況等、稼働能力の判断材料が記載されているか
8. 添付書類の具備 — 10点：通帳写し・給与明細・年金通知・賃貸借契約書・本人確認書類等がそろっているか
9. 形式要件 — 10点：署名・日付・調査への同意書・世帯員分の申告書がそろっているか

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
- 資産・収入の申告漏れの「疑い」がある場合（記載間の矛盾、生活実態との不整合等）は、断定せずに「確認が必要」として具体的な調査方法を示すこと
- 公平性・公金の適正執行と、申請者の権利保護（申請権の侵害禁止）の両方の観点を保つこと

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

## 重大な不備・要確認事項
- （申告漏れの疑い・記載間の矛盾・同意書欠落など。なければ「特になし」）

## 要否判定に向けた調査事項
- 資産調査：（金融機関照会・保険照会・不動産調査等、この申請で必要な調査を具体的に）
- 扶養調査：（照会先・照会方法の留意点）
- 収入・年金調査：（照会先・確認事項）
- 就労可能性：（稼働能力判定に必要な確認事項）

## 申請者への補正依頼・追加提出書類
- （受理した上で補正を求めるべき事項を具体的に）

## 総評・次のアクション
（ケースワーカーが次に取るべき対応を200〜400字でまとめる。要否判定の最終決定は福祉事務所の算定・決裁によることを踏まえること）

【厳守事項】
- 合計点は必ず項目別採点表の「得点」列の単純合計と一致させること。出力前に実際に足し算して検算し、食い違う場合は表の合計を正とすること
- 全項目が満点（○）の場合の合計は必ず100点であり、95点等と書かないこと
- 最低生活費・保護費の金額計算は行わないこと（「福祉事務所の算定システムで確認」と案内する）
- 書類の不備を理由に申請を受け付けない・却下するという助言をしないこと（補正を求める形で助言する）
- 必ず日本語で出力すること
- 書類に書かれていない事実を創作しないこと
- 専門用語には簡潔な補足を付けること
PROMPT;

$userMessage = "以下は審査対象の生活保護申請書類（申請書・資産申告書・収入無収入申告書等）です。審査基準に沿って採点・レポートしてください。\n\n----- 書類ここから -----\n" . $text . "\n----- 書類ここまで -----";

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
