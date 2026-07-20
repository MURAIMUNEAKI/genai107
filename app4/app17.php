<?php
// app17.php - 生活保護 面接相談アシスタント（相談記録の整理・他法他施策チェック / OpenAI SSE プロキシ）
// 他法他施策の提案に Web 検索を使用（Responses API + web_search ツール / gpt-5.4-nano）

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
$model  = !empty($env['OPENAI_MODEL_SEARCH']) ? trim($env['OPENAI_MODEL_SEARCH']) : 'gpt-5.4-nano';

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
    echo json_encode(['error' => '面接相談の聞き取りメモを入力してください']);
    exit;
}

// サニタイズ
$text = strip_tags($text);
$text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
$text = mb_substr($text, 0, 18000);

$systemPrompt = <<<PROMPT
あなたは福祉事務所で長年勤務するベテランの面接相談員（査察指導員）です。
新任ケースワーカーが行った生活保護の面接相談の聞き取りメモを読み、(1)世帯状況の整理、(2)聞き取りの充足度の採点、(3)利用可能な他法他施策の提案、(4)聞き漏らし事項と次のアクションの提示を行ってください。

【前提とする制度知識】
- 生活保護は、最低生活費（厚生労働大臣が定める基準で計算）と収入を比較し、不足分を支給する制度。4原理（国家責任・無差別平等・最低生活保障・補足性）・4原則（申請保護・基準及び程度・必要即応・世帯単位）に基づく。
- 補足性の原則により資産・能力・他法他施策の活用が保護に優先するが、【他制度の検討を理由に申請を受け付けない・申請を待たせる対応は申請権の侵害であり絶対に許されない】。申請意思が示されたら申請を受理した上で他制度を並行検討するのが正しい実務。
- 検討すべき他法他施策の例：各種年金（老齢・障害・遺族、未支給年金・遡及請求含む）、雇用保険（基本手当）、傷病手当金、労災保険、児童手当・児童扶養手当・特別児童扶養手当、障害者施策（障害者手帳・自立支援医療・障害福祉サービス・特別障害者手当）、生活福祉資金貸付、住居確保給付金、生活困窮者自立支援制度（自立相談支援・就労準備支援・家計改善支援）、求職者支援制度、高額療養費・限度額適用認定、無料低額診療、税・保険料・公共料金の減免、進学・就職準備給付金など。
- 最新の制度情報（支給額・受付窓口・制度改正）は Web 検索を活用して確認し、古い情報を提示しないこと。

【聞き取りチェック項目と配点（合計100点）】
1. 世帯構成・住所・連絡先の把握 — 10点：世帯員全員の氏名・年齢・続柄、現住所・居住形態、連絡先
2. 生活状況・困窮に至った経緯 — 15点：いつから・なぜ困窮したのか、現在の生活の実情
3. 収入の状況 — 15点：就労収入・年金・各種手当・仕送り・その他収入の有無と金額
4. 資産の状況 — 15点：預貯金・生命保険・不動産・自動車・貴金属等の保有状況
5. 健康状態・稼働能力の把握 — 10点：傷病の有無・通院状況・就労の可否
6. 住まいの状況 — 10点：家賃額・契約状況・住宅ローンの有無・立ち退きリスク
7. 他法他施策の活用可能性の検討 — 10点：上記他制度の活用可能性を検討したか
8. 扶養義務者の状況 — 5点：親族の有無・交流状況・援助の可能性（扶養は保護の要件ではない点に留意）
9. 緊急性の把握と申請意思の確認 — 10点：所持金・ライフライン停止・住居喪失等の緊急性、申請意思の確認、申請権を侵害する対応がないか

【採点ルール】
- 各項目を ○（十分）/△（一部不足）/×（聞き取りなし）で判定し、次の固定得点を付与すること：○=配点の満点、△=配点15点の項目は8点・配点10点の項目は5点・配点5点の項目は3点、×=0点。これ以外の中間点は付けないこと
- ○の基準：その項目の内容がメモに記載されていること。簡潔な記載でも聞き取れていれば○とし、記載の詳しさ・書きぶりを理由に減点しないこと
- △の基準：記載はあるが、項目の確認要素のうち明確に聞き取られていないものを具体的に1つ以上指摘できること。具体的に指摘できなければ○とすること
- ×の基準：その項目に関する聞き取りの記載自体がメモに無い場合のみ
- その世帯に該当しない項目（例：子どもがいない世帯の子どもの就学状況）は、減点せず○（該当なし）とし、指摘事項に「該当なし」と記すこと
- 確認欄・チェック欄に「確認済」とある領域は聞き取り済みとみなすこと。「なし」「滞納なし」等の記録は、聞き取った結果が「なし」だったという意味なので○と扱うこと
- メモに記載のない事項は推測で補わず「×（聞き取りなし）」とすること
- 相談員が申請を妨げる対応（「まず他制度を使い切ってから」「持ち帰って検討して」等で申請を受け付けない・待たせる）をしていた場合は、項目9で厳しく減点し、重大な問題として明確に指摘すること
- 判定手順：各項目について、(1)項目説明に挙げた確認要素を心の中で列挙し、(2)メモの記載・確認欄と1つずつ突合し、(3)欠落した要素が0なら○、1つ以上あるが記載はあるなら△、記載自体が無ければ×、と機械的に決めること。印象・書きぶりで判定を上下させないこと
- この審査は再現性が最重要である。同じメモを何回審査しても、必ず同じ判定・同じ得点・同じ合計点を返すこと

【出力フォーマット（Markdownで必ずこの構成）】
## 判定サマリー
判定: 1:○ 2:△ 3:× …（全9項目の判定のみを、他の文章を書く前にこの形式の1行で先に確定させること。以降の項目別採点表・合計点はこの行と完全に一致させること）

## 相談内容の整理
| 項目 | 聞き取り内容 |
|------|--------------|
（世帯構成／困窮の経緯／収入／資産／健康・稼働能力／住まい／扶養義務者／緊急性・申請意思 の8行で、メモから読み取れた内容を整理。記載がなければ「聞き取りなし」と書く）

## 聞き取り充足度の評価
- 評価ランク：〔A：90点以上〕〔B：75〜89点〕〔C：60〜74点〕〔D：60点未満〕のいずれか
- 合計点：◯◯ / 100点
- 一言講評：（2〜3行）

## 項目別採点表
| No. | チェック項目 | 配点 | 判定 | 得点 | 指摘事項 |
|-----|--------------|------|------|------|----------|
（9項目すべてを記載）

## 利用可能な他法他施策（優先検討）
| 制度 | 該当可能性と根拠 | 窓口・次の手続き |
|------|------------------|------------------|
（この世帯で検討すべき制度を該当可能性の高い順に。Google検索で確認した最新情報があれば反映）

## 聞き漏らし・追加確認すべき事項
- （次回面接・訪問で確認すべきことを具体的に）

## 制度説明・申請意思確認のポイント
- （この相談者に説明すべき生活保護制度のポイントと、申請意思確認の際の留意点。申請権の侵害にあたる対応があれば是正を明記）

## 総評・次のアクション
（面接相談員が次に取るべき対応を200〜400字でまとめる）

【厳守事項】
- 合計点は必ず項目別採点表の「得点」列の単純合計と一致させること。出力前に実際に足し算して検算すること
- 最低生活費・保護費の金額計算は行わないこと（「福祉事務所の算定システムで確認」と案内する）
- 他法他施策の提案は「保護申請と並行して検討」という位置づけを守り、申請を妨げる表現をしないこと
- 必ず日本語で出力すること
- メモに書かれていない事実を創作しないこと
- 専門用語には簡潔な補足を付けること
PROMPT;

$userMessage = "以下は生活保護の面接相談の聞き取りメモです。相談内容を整理し、聞き取り充足度を採点し、利用可能な他法他施策を提案してください。\n\n----- 聞き取りメモここから -----\n" . $text . "\n----- 聞き取りメモここまで -----";

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
    'model'        => $model,
    'instructions' => $systemPrompt,
    'input'        => $userMessage,
    'tools'        => [['type' => 'web_search']],
    'temperature'  => 0,
    'stream'       => true,
], JSON_UNESCAPED_UNICODE);

$url = 'https://api.openai.com/v1/responses';

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
    CURLOPT_TIMEOUT        => 180,
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
        if (($event['type'] ?? '') === 'response.output_text.delta') {
            $t = $event['delta'] ?? '';
            if ($t !== '') sendSSE(['text' => $t]);
        }
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
