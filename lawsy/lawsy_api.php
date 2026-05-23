<?php
// lawsy_api.php - 法令AI Lawsy 4段階パイプライン（SSEストリーミング）
// Stage 1: 法令名推定（google_search） → Stage 2: e-Gov法令API検索
// → Stage 3: 法令条文取得 → Stage 4: レポート生成（SSEストリーミング）

// .env読み込み
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

$env = loadEnv(__DIR__ . '/../api/.env');
$apiKey = $env['GEMINI_API_KEY'] ?? '';
$modelLite  = $env['GEMINI_MODEL'] ?? 'gemini-3.1-flash-lite';
$modelFlash = 'gemini-2.5-flash'; // google_search ツール対応モデル

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'POST required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$question = trim($input['question'] ?? '');
$queryType = trim($input['query_type'] ?? 'definition');

if ($question === '') {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '質問を入力してください']);
    exit;
}

$question = strip_tags($question);
$question = mb_substr($question, 0, 5000);

// 質問タイプ定義
$queryTypes = [
    'definition'    => ['label' => '定義確認型', 'desc' => '法律用語や概念の定義を確認'],
    'procedure'     => ['label' => '手続き確認型', 'desc' => '申請・届出等の手続きを確認'],
    'comparison'    => ['label' => '比較検討型', 'desc' => '複数の法制度を比較'],
    'interpretation'=> ['label' => '解釈適用型', 'desc' => '条文の解釈・具体的適用を検討'],
    'policy'        => ['label' => '政策研究型', 'desc' => '政策の背景・経緯・効果を分析'],
    'comprehensive' => ['label' => '包括分析型', 'desc' => '複数法令にまたがる総合分析'],
];

$typeLabel = $queryTypes[$queryType]['label'] ?? '定義確認型';

// SSEヘッダー
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

function sendSSE($data) {
    echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

// ===== Stage 1: 法令名推定（google_search グラウンディング） =====
sendSSE(['stage' => 1, 'label' => '法令名推定中...']);

$estimatePrompt = <<<PROMPT
以下の質問に関連する日本の法令名（法律、政令、省令、条例など）を推定してください。
Google検索を使って正確な法令名を調べてください。

【質問】
{$question}

【出力形式】
関連する法令名をJSON配列で出力してください。正式名称を使用してください。
最も関連性の高い順に、最大5件まで出力してください。
例: ["個人情報の保護に関する法律", "行政手続における特定の個人を識別するための番号の利用等に関する法律"]
JSON以外のテキストは出力しないでください。
PROMPT;

$lawNames = [];

// google_search ツール付きで法令名推定（gemini-2.5-flash 使用、v1beta必須）
$estimateUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$modelFlash}:generateContent";
$estimatePayload = json_encode([
    'contents' => [['role' => 'user', 'parts' => [['text' => $estimatePrompt]]]],
    'tools' => [['google_search' => new \stdClass()]],
    'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 4096]
], JSON_UNESCAPED_UNICODE);

$ch = curl_init($estimateUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $estimatePayload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    $result = json_decode($response, true);
    $text = '';
    // google_search の結果は複数パートに分かれることがある
    if (isset($result['candidates'][0]['content']['parts'])) {
        foreach ($result['candidates'][0]['content']['parts'] as $part) {
            if (isset($part['text'])) $text .= $part['text'];
        }
    }
    if ($text) {
        $cleaned = preg_replace('/```json\s*/', '', $text);
        $cleaned = preg_replace('/```\s*/', '', $cleaned);
        $cleaned = trim($cleaned);
        $parsed = json_decode($cleaned, true);
        if (is_array($parsed) && !empty($parsed)) {
            $lawNames = array_slice($parsed, 0, 5);
        }
    }
}

// フォールバック：google_search が失敗した場合、通常のGeminiで推定
if (empty($lawNames)) {
    $fallbackResult = callGeminiSync($estimatePrompt, $apiKey, $modelLite, 0.3);
    if ($fallbackResult) {
        $cleaned = preg_replace('/```json\s*/', '', $fallbackResult);
        $cleaned = preg_replace('/```\s*/', '', $cleaned);
        $cleaned = trim($cleaned);
        $parsed = json_decode($cleaned, true);
        if (is_array($parsed) && !empty($parsed)) {
            $lawNames = array_slice($parsed, 0, 5);
        }
    }
}

// それでも空なら質問文からキーワード抽出
if (empty($lawNames)) {
    $lawNames = [$question];
}

sendSSE(['detail' => '【Stage 1 法令名推定結果】' . "\n" . implode("\n", array_map(function($n, $i) { return ($i + 1) . '. ' . $n; }, $lawNames, array_keys($lawNames)))]);
sendSSE(['stage_done' => 1]);

// ===== Stage 2: e-Gov法令API検索 =====
// e-Gov /api/2/keyword は本文全文検索のため、「刑法」で検索すると本文中に
// 「刑法」を含むだけの無関係な法令（爆発物取締罰則・建築基準法施行令など）も
// ヒットする。そこで limit を広めに取り、Stage 1 で推定した法令名との
// タイトル一致度でスコアリングしてから採用する。
sendSSE(['stage' => 2, 'label' => '法令検索中...']);

$foundLaws = [];

foreach ($lawNames as $lawName) {
    $keyword = urlencode($lawName);
    $searchUrl = "https://laws.e-gov.go.jp/api/2/keyword?keyword={$keyword}&limit=30";

    $ch = curl_init($searchUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $searchResponse = curl_exec($ch);
    $searchHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($searchHttpCode !== 200) continue;

    $searchResult = json_decode($searchResponse, true);
    $laws = $searchResult['items'] ?? [];

    // タイトル一致度スコアでフィルタ・ソート
    $scored = [];
    foreach ($laws as $law) {
        $lawId    = $law['law_info']['law_id'] ?? '';
        $lawTitle = $law['revision_info']['law_title'] ?? '';
        $lawNum   = $law['law_info']['law_num'] ?? '';
        if (!$lawId || !$lawTitle) continue;

        $score = scoreTitleMatch($lawName, $lawTitle);
        if ($score <= 0) continue; // タイトルに一致なし（全文ヒットのみ）は除外

        $scored[] = [
            'law_id'   => $lawId,
            'law_name' => $lawTitle,
            'law_num'  => $lawNum,
            'score'    => $score,
        ];
    }

    // スコア降順でソート、各検索語あたり上位3件まで採用
    usort($scored, function($a, $b) { return $b['score'] - $a['score']; });
    $scored = array_slice($scored, 0, 3);

    foreach ($scored as $law) {
        $lawId = $law['law_id'];
        if (!isset($foundLaws[$lawId]) || $foundLaws[$lawId]['score'] < $law['score']) {
            $foundLaws[$lawId] = $law;
        }
    }
}

// 全体をスコア降順でソートし、最大5件に制限
$foundLaws = array_values($foundLaws);
usort($foundLaws, function($a, $b) { return $b['score'] - $a['score']; });
$foundLaws = array_slice($foundLaws, 0, 5);

if (empty($foundLaws)) {
    sendSSE(['detail' => '【Stage 2】e-Gov APIで該当法令が見つかりませんでした。一般的な法律知識で回答します。']);
    sendSSE(['stage_done' => 2]);
    sendSSE(['stage' => 3, 'label' => '条文取得スキップ']);
    sendSSE(['stage_done' => 3]);
    goto stage4_no_law;
}

$lawListText = '【Stage 2 法令検索結果】' . count($foundLaws) . '件' . "\n";
foreach ($foundLaws as $i => $law) {
    $lawListText .= ($i + 1) . '. ' . $law['law_name'] . '（' . $law['law_num'] . '）' . "\n";
}
sendSSE(['detail' => $lawListText]);
sendSSE(['stage_done' => 2]);

// ===== Stage 3: 法令条文取得 =====
sendSSE(['stage' => 3, 'label' => '条文取得中...']);

$lawTexts = [];
$totalChars = 0;
$maxTotalChars = 80000; // 全体のテキスト量制限

foreach ($foundLaws as $law) {
    if ($totalChars >= $maxTotalChars) break;

    $lawDataUrl = "https://laws.e-gov.go.jp/api/2/law_data/{$law['law_id']}";

    $ch = curl_init($lawDataUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $lawDataResponse = curl_exec($ch);
    $lawDataHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($lawDataHttpCode === 200) {
        // XMLからテキストを抽出
        $textContent = extractLawText($lawDataResponse, $law['law_name']);
        if ($textContent) {
            // テキスト量制限
            $remaining = $maxTotalChars - $totalChars;
            if (mb_strlen($textContent) > $remaining) {
                $textContent = mb_substr($textContent, 0, $remaining) . "\n\n（※以下省略）";
            }
            $lawTexts[] = [
                'law_name' => $law['law_name'],
                'law_num' => $law['law_num'],
                'text' => $textContent,
            ];
            $totalChars += mb_strlen($textContent);
        }
    }
}

if (empty($lawTexts)) {
    sendSSE(['detail' => '【Stage 3】条文の取得に失敗しました。法令名の情報のみで回答します。']);
} else {
    $detail = '【Stage 3 条文取得結果】' . count($lawTexts) . '件取得';
    foreach ($lawTexts as $i => $lt) {
        $detail .= "\n" . ($i + 1) . '. ' . $lt['law_name'] . '（' . number_format(mb_strlen($lt['text'])) . '文字）';
    }
    sendSSE(['detail' => $detail]);
}
sendSSE(['stage_done' => 3]);

// ===== Stage 4: レポート生成（SSEストリーミング） =====
sendSSE(['stage' => 4, 'label' => 'レポート生成中...']);

// 法令条文コンテキスト構築
$contextText = '';
foreach ($lawTexts as $lt) {
    $contextText .= "## {$lt['law_name']}（{$lt['law_num']}）\n\n{$lt['text']}\n\n---\n\n";
}

// 法令名リスト（条文取得できなかった分も含む）
$lawNameList = implode('、', array_map(function($l) { return $l['law_name']; }, $foundLaws));

$typePrompt = getTypePrompt($queryType, $typeLabel);

$systemPrompt = <<<SYSPROMPT
あなたは日本の法令に精通した法務AIアシスタントです。
提供された法令条文に基づいて、正確で実用的なレポートを生成してください。

【基本ルール】
- 条文を正確に引用し、条・項・号まで明記してください
- 引用は「第○条第○項」の形式で統一してください
- 法令に記載のない内容を創作しないでください
- 関連する判例・通達がある場合は言及してください
- Markdown形式で構造化されたレポートを生成してください

{$typePrompt}
SYSPROMPT;

$userMessage = <<<USERMSG
【質問】
{$question}

【質問タイプ】{$typeLabel}

【関連法令】{$lawNameList}

【法令条文】
{$contextText}

上記の法令条文に基づいて、質問タイプに適した形式でレポートを作成してください。
USERMSG;

streamGeminiSSE($userMessage, $systemPrompt, $apiKey, $modelLite);
sendSSE(['stage_done' => 4]);
echo "data: [DONE]\n\n";
if (ob_get_level()) ob_flush();
flush();
exit;

// ===== 法令なしフォールバック =====
stage4_no_law:
sendSSE(['stage' => 4, 'label' => 'レポート生成中（一般知識）...']);

$typePrompt = getTypePrompt($queryType, $typeLabel);

$systemPrompt = <<<SYSPROMPT
あなたは日本の法令に精通した法務AIアシスタントです。
e-Gov法令APIで該当する法令が見つからなかったため、一般的な法律知識に基づいて回答してください。

【基本ルール】
- 正確な法令名と条文番号を可能な限り示してください
- 不確実な情報には「※確認が必要」と明記してください
- Markdown形式で構造化されたレポートを生成してください

{$typePrompt}

【重要な注意】
この回答は法令データベースからの直接引用ではなく、一般的な法律知識に基づいています。
正確性の確認のため、e-Gov法令検索（https://laws.e-gov.go.jp/）での原文確認を推奨してください。
SYSPROMPT;

$userMessage = <<<USERMSG
【質問】
{$question}

【質問タイプ】{$typeLabel}

上記の質問に対して、質問タイプに適した形式でレポートを作成してください。
USERMSG;

streamGeminiSSE($userMessage, $systemPrompt, $apiKey, $modelLite);
sendSSE(['stage_done' => 4]);
echo "data: [DONE]\n\n";
if (ob_get_level()) ob_flush();
flush();
exit;

// ===== Helper Functions =====

/**
 * Stage 1 推定法令名と検索結果タイトルの一致度をスコアリング
 *  100点: 完全一致（例: 「刑法」 == 「刑法」）
 *   80点: タイトルが検索語を含む（例: 検索語「刑法」→ 「刑法施行法」）
 *   60点: 検索語がタイトルを含む（例: 検索語「個人情報保護法施行令」→ 「個人情報保護法」）
 *   40点: 漢字主要部分が前方一致など部分一致
 *    0点: タイトルに一致なし（全文ヒットのみ → 除外対象）
 */
function scoreTitleMatch($queryName, $title) {
    // 全角・半角スペース・括弧などのノイズを除去
    $normalize = function($s) {
        $s = preg_replace('/[\s　]+/u', '', $s);
        $s = preg_replace('/[（）()「」『』、,。\.]/u', '', $s);
        return $s;
    };
    $q = $normalize($queryName);
    $t = $normalize($title);
    if ($q === '' || $t === '') return 0;

    if ($q === $t) return 100;
    if (mb_strpos($t, $q) !== false) return 80;
    if (mb_strpos($q, $t) !== false) return 60;

    // 前方一致（先頭2文字以上）の部分一致
    $minLen = min(mb_strlen($q), mb_strlen($t));
    $common = 0;
    for ($i = 0; $i < $minLen; $i++) {
        if (mb_substr($q, $i, 1) === mb_substr($t, $i, 1)) $common++;
        else break;
    }
    if ($common >= 2) return 40;

    return 0;
}

/**
 * 質問タイプ別プロンプト生成
 */
function getTypePrompt($queryType, $typeLabel) {
    $prompts = [
        'definition' => <<<'P'
【定義確認型 レポート構成】
1. 定義の概要（法令名・条文番号を明記）
2. 条文の正確な引用
3. 定義の構成要素の分析
4. 関連する用語・概念との比較
5. 実務上の適用ポイント
P,
        'procedure' => <<<'P'
【手続き確認型 レポート構成】
1. 手続きの概要と根拠法令
2. 手続きのステップ（時系列順に整理）
3. 必要書類・添付書類の一覧
4. 提出先・期限・手数料
5. 注意事項・よくある不備
P,
        'comparison' => <<<'P'
【比較検討型 レポート構成】
1. 比較対象の概要
2. 共通点の整理
3. 相違点の比較表（Markdown表形式）
4. 各制度の特徴・メリット・デメリット
5. 適用場面の違い
P,
        'interpretation' => <<<'P'
【解釈適用型 レポート構成】
1. 該当条文の引用
2. 条文の文理解釈
3. 立法趣旨・制定経緯
4. 通説的な解釈
5. 具体的な適用事例
6. 関連する判例・行政解釈
P,
        'policy' => <<<'P'
【政策研究型 レポート構成】
1. 政策の概要と根拠法令
2. 立法の背景・社会的要因
3. 政策目的と期待される効果
4. 制定・改正の経緯（時系列）
5. 関連政策との位置づけ
6. 課題と今後の展望
P,
        'comprehensive' => <<<'P'
【包括分析型 レポート構成】
1. 関連法令の全体像マップ
2. 各法令の概要と相互関係
3. 基本法と個別法の関係性
4. 横断的な規制・義務の整理
5. 実務上の適用フロー
6. 最近の法改正動向
P,
    ];

    return $prompts[$queryType] ?? $prompts['definition'];
}

/**
 * e-Gov法令XMLからテキストを抽出
 */
function extractLawText($xmlString, $lawName) {
    // XML宣言前のBOM除去
    $xmlString = preg_replace('/^\xEF\xBB\xBF/', '', $xmlString);

    // JSONレスポンスの場合（API v2はJSONで返すことがある）
    $jsonData = json_decode($xmlString, true);
    if ($jsonData !== null) {
        // JSON形式の場合、law_full_text を探す
        if (isset($jsonData['law_full_text'])) {
            return processLawFullText($jsonData['law_full_text'], $lawName);
        }
        // XMLが入っている場合
        if (isset($jsonData['law_data'])) {
            $xmlString = $jsonData['law_data'];
        }
    }

    // XML解析を試行
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlString);
    if ($xml === false) {
        // XMLパース失敗 → プレーンテキストとして抽出
        $text = strip_tags($xmlString);
        $text = preg_replace('/\s+/', ' ', $text);
        return mb_substr(trim($text), 0, 50000);
    }

    $output = "# {$lawName}\n\n";
    $output .= extractXmlNode($xml);

    return $output;
}

/**
 * JSON法令フルテキストの処理
 */
function processLawFullText($fullText, $lawName) {
    if (is_string($fullText)) {
        return "# {$lawName}\n\n" . $fullText;
    }
    if (is_array($fullText)) {
        return "# {$lawName}\n\n" . json_encode($fullText, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    return '';
}

/**
 * XMLノードを再帰的にテキスト抽出
 */
function extractXmlNode($node, $depth = 0) {
    $text = '';

    // ノードのテキストコンテンツ
    $nodeName = $node->getName();

    // 見出し系タグ
    $headingTags = ['LawTitle', 'TOCLabel', 'ArticleCaption', 'ParagraphCaption',
                     'ChapterTitle', 'SectionTitle', 'SubsectionTitle', 'DivisionTitle',
                     'PartTitle', 'SupplProvisionLabel'];

    if (in_array($nodeName, $headingTags)) {
        $level = min($depth + 1, 4);
        $hashes = str_repeat('#', $level);
        $content = trim((string)$node);
        if ($content !== '') {
            $text .= "\n{$hashes} {$content}\n\n";
        }
        return $text;
    }

    // 条文番号
    if ($nodeName === 'ArticleTitle' || $nodeName === 'ParagraphNum') {
        $content = trim((string)$node);
        if ($content !== '') {
            $text .= "**{$content}** ";
        }
        return $text;
    }

    // センテンス
    if ($nodeName === 'Sentence') {
        $content = trim((string)$node);
        if ($content !== '') {
            $text .= $content;
        }
        return $text;
    }

    // 号
    if ($nodeName === 'ItemTitle') {
        $content = trim((string)$node);
        if ($content !== '') {
            $text .= "- **{$content}** ";
        }
        return $text;
    }

    if ($nodeName === 'ItemSentence') {
        $content = trim(getFullText($node));
        if ($content !== '') {
            $text .= $content . "\n";
        }
        return $text;
    }

    // 段落の区切り
    if ($nodeName === 'Paragraph' || $nodeName === 'Article') {
        $text .= "\n";
    }

    // 子ノードを再帰処理
    foreach ($node->children() as $child) {
        $text .= extractXmlNode($child, $depth + 1);
    }

    // 段落の後に改行
    if ($nodeName === 'Paragraph') {
        $text .= "\n";
    }

    return $text;
}

/**
 * ノード以下の全テキストを結合
 */
function getFullText($node) {
    $text = '';
    if (count($node->children()) === 0) {
        return trim((string)$node);
    }
    foreach ($node->children() as $child) {
        $childText = getFullText($child);
        if ($childText !== '') {
            $text .= $childText;
        }
    }
    $ownText = trim(dom_import_simplexml($node)->textContent ?? '');
    return $ownText ?: $text;
}

/**
 * Gemini同期呼び出し（Stage 1フォールバック用）
 */
function callGeminiSync($prompt, $apiKey, $model, $temperature = 0.3) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
    $payload = json_encode([
        'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
        'generationConfig' => ['temperature' => $temperature, 'maxOutputTokens' => 4096]
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) { error_log("callGeminiSync HTTP $httpCode: $response"); return null; }

    $result = json_decode($response, true);
    return $result['candidates'][0]['content']['parts'][0]['text'] ?? null;
}

/**
 * Gemini SSEストリーミング出力
 */
function streamGeminiSSE($userMessage, $systemPrompt, $apiKey, $model) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:streamGenerateContent?alt=sse";
    $payload = json_encode([
        'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
        'contents' => [['role' => 'user', 'parts' => [['text' => $userMessage]]]],
        'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 65536]
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 180);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $buffer = '';
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$buffer) {
        $buffer .= $data;
        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 1);
            $line = trim($line);
            if ($line === '' || $line === 'data: [DONE]') continue;
            if (strpos($line, 'data: ') === 0) {
                $json = json_decode(substr($line, 6), true);
                if (isset($json['candidates'][0]['content']['parts'])) {
                    foreach ($json['candidates'][0]['content']['parts'] as $part) {
                        if (isset($part['text'])) {
                            sendSSE(['text' => $part['text']]);
                        }
                    }
                }
                if (isset($json['error'])) {
                    sendSSE(['error' => 'APIエラー: ' . ($json['error']['message'] ?? '不明')]);
                }
            }
        }
        return strlen($data);
    });

    curl_exec($ch);

    // バッファ残り処理
    if (trim($buffer) !== '' && strpos($buffer, 'data: ') === 0) {
        $json = json_decode(substr(trim($buffer), 6), true);
        if (isset($json['candidates'][0]['content']['parts'])) {
            foreach ($json['candidates'][0]['content']['parts'] as $part) {
                if (isset($part['text'])) {
                    sendSSE(['text' => $part['text']]);
                }
            }
        }
    }

    if (curl_errno($ch)) {
        sendSSE(['error' => 'API通信エラー: ' . curl_error($ch)]);
    }
    curl_close($ch);
}
