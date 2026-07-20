<?php
// app15.php - 事業所 運営指導コンプライアンス審査（OpenAI SSE プロキシ）

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
- 各項目を ○（適合）/△（一部不備）/×（不備・未整備）で判定し、次の固定得点を付与すること：○=配点の満点、△=配点15点の項目は8点・配点10点の項目は5点・配点5点の項目は3点、×=0点。これ以外の中間点は付けないこと
- ○の基準：確認要素が書類の記載として満たされていること。書類に「確認済」「添付あり」「一致」「記載あり」等の記載があれば、その事実は満たされているとみなすこと（写し・原本の実物照合は窓口・調査業務であり、本審査は書類のテキスト記載のみで判断する。実物確認が必要な点は減点せず「次のアクション」等で案内する）
- △の基準：書類の記載として明確に欠けている・不十分な・矛盾している要素を、指摘事項に具体的に書けること。具体的に指摘できなければ○とすること
- ×の基準：その項目の中核となる記載自体が書類に無い場合、または記載間に明らかな矛盾がある場合のみ。裏付けが書類の外にあること（納期限日との対比・照会結果・実物の写し等）だけを理由に×にしないこと
- その書類・事案に該当しない審査項目（その事案では不要な書類・証明等）は、減点せず○（該当なし）とし、指摘事項に「該当なし」と記すこと
- 書類が「職員が別途確認する」「面接記録に基づき確認」等と役所側の後続確認に委ねている事項は、書類の不備ではないため減点せず○（後続確認）とすること
- 添付書類・確認欄に「確認済」とある事項は、金額・期間等の詳細が本文に転記されていなくても満たされているとみなし、詳細の未転記を理由に△にしないこと
- 記載間の明らかな矛盾（番号・日付・金額・氏名・曜日等の不一致）がある項目は必ず×とし、矛盾箇所を指摘事項に明記すること
- 判定手順：各項目について、(1)項目説明に挙げた確認要素を心の中で列挙し、(2)書類の記載・確認欄と1つずつ突合し、(3)欠落・矛盾した要素が0なら○、1つ以上あるが中核の記載はあるなら△、中核の記載が無い・矛盾があるなら×、と機械的に決めること。印象・文章の巧拙・書きぶりで判定を上下させないこと
- この審査は再現性が最重要である。同じ書類を何回審査しても、必ず同じ判定・同じ得点・同じ合計点を返すこと
- 書類に該当記載が無い場合は推測で補わず「×（未整備・記載なし）」とし、捏造しないこと
- 2024年度から義務化された項目（虐待防止・BCP・身体拘束適正化・感染症対策）の未整備は「重大な不備」として明記すること
- 運営規程と重要事項説明書の料金等の不整合は具体的に指摘すること

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
