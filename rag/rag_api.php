<?php
// rag_api.php - 行政実務用RAG 4段階パイプライン（SSEストリーミング）
// Stage 1: クエリ拡張 → Stage 2: ベクトル検索 → Stage 3: 関連性評価 → Stage 4: 回答生成

require_once __DIR__ . '/ragcore.php';

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
$model  = $env['GEMINI_MODEL'] ?? 'gemini-3.1-flash-lite';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'POST required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$question = trim($input['question'] ?? '');
$nQueries = intval($input['n_queries'] ?? 3);
$showDetail = !empty($input['show_detail']);

if ($question === '') {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '質問を入力してください']);
    exit;
}

$question = strip_tags($question);
$question = mb_substr($question, 0, 5000);
$nQueries = max(1, min(5, $nQueries));

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

// ===== Stage 1: クエリ拡張 =====
sendSSE(['stage' => 1, 'label' => 'クエリ拡張中...']);

$expandedQueries = [$question]; // 元の質問は常に含む

if ($nQueries > 1) {
    $expandPrompt = <<<PROMPT
以下の質問に対して、検索精度を高めるために{$nQueries}個の異なる検索クエリを生成してください。
元の質問の意図を保ちつつ、異なる表現・観点からのクエリを作成してください。

【質問】
{$question}

【出力形式】
JSON配列で出力してください。例: ["クエリ1", "クエリ2", "クエリ3"]
JSON以外のテキストは出力しないでください。
PROMPT;

    $expandResult = callGeminiSync($expandPrompt, $apiKey, $model, 0.3);
    if ($expandResult) {
        // JSON抽出（```json ... ``` ラッパーにも対応）
        $cleaned = preg_replace('/```json\s*/', '', $expandResult);
        $cleaned = preg_replace('/```\s*/', '', $cleaned);
        $cleaned = trim($cleaned);
        $parsed = json_decode($cleaned, true);
        if (is_array($parsed) && !empty($parsed)) {
            $expandedQueries = array_slice($parsed, 0, $nQueries);
        }
    }
}

if ($showDetail) {
    sendSSE(['detail' => '【Stage 1 クエリ拡張結果】' . "\n" . implode("\n", array_map(function($q, $i) { return ($i + 1) . ". " . $q; }, $expandedQueries, array_keys($expandedQueries)))]);
}
sendSSE(['stage_done' => 1]);

// ===== Stage 2: ベクトル検索 =====
sendSSE(['stage' => 2, 'label' => 'ベクトル検索中...']);

$allChunks = [];
$seenIds = [];

if (!file_exists(RAG_DB_PATH)) {
    // フォールバック：DBなし → 全文読み込み
    if ($showDetail) {
        sendSSE(['detail' => '【Stage 2】DBファイル未検出 → 全文読み込みフォールバック']);
    }
    $fallbackContent = loadAllMarkdownFiles();
    if (empty(trim($fallbackContent))) {
        sendSSE(['error' => 'ナレッジベースが空です。data/ ディレクトリにMarkdownファイルを配置し、php rag_lib.php build --force を実行してください。']);
        sendSSE(['stage_done' => 2]);
        sendSSE(['stage_done' => 3]);
        goto stage4_fallback;
    }
    // フォールバック時は直接Stage4へ
    sendSSE(['stage_done' => 2]);
    sendSSE(['stage_done' => 3]);
    goto stage4_fallback;
} else {
    $db = new SQLite3(RAG_DB_PATH, SQLITE3_OPEN_READONLY);
    $countResult = $db->querySingle('SELECT COUNT(*) FROM chunks');
    if ($countResult == 0) {
        $db->close();
        if ($showDetail) {
            sendSSE(['detail' => '【Stage 2】DBにチャンクなし → 全文読み込みフォールバック']);
        }
        $fallbackContent = loadAllMarkdownFiles();
        sendSSE(['stage_done' => 2]);
        sendSSE(['stage_done' => 3]);
        goto stage4_fallback;
    }

    foreach ($expandedQueries as $idx => $query) {
        $queryVector = embedText($query, $apiKey, 'RETRIEVAL_QUERY');
        if ($queryVector === null) continue;

        $topChunks = retrieveTopK($queryVector, $db, 8, 0.25);
        foreach ($topChunks as $chunk) {
            if (!isset($seenIds[$chunk['id']])) {
                $seenIds[$chunk['id']] = true;
                $allChunks[] = $chunk;
            }
        }
    }
    $db->close();

    // スコア順で再ソート
    usort($allChunks, function($a, $b) { return $b['score'] <=> $a['score']; });
    $allChunks = array_slice($allChunks, 0, 20); // 最大20件

    if ($showDetail) {
        $chunkSummary = '【Stage 2 ベクトル検索結果】' . count($allChunks) . '件ヒット';
        foreach (array_slice($allChunks, 0, 5) as $i => $c) {
            $chunkSummary .= "\n" . ($i + 1) . ". [" . round($c['score'], 3) . "] " . mb_substr($c['heading_path'], 0, 60);
        }
        if (count($allChunks) > 5) $chunkSummary .= "\n... 他" . (count($allChunks) - 5) . "件";
        sendSSE(['detail' => $chunkSummary]);
    }
}
sendSSE(['stage_done' => 2]);

// ===== Stage 3: 関連性評価 =====
sendSSE(['stage' => 3, 'label' => '関連性評価中...']);

$relevantChunks = $allChunks;

if (count($allChunks) > 5) {
    // チャンクの要約リストを作成
    $chunkList = '';
    foreach ($allChunks as $i => $chunk) {
        $num = $i + 1;
        $preview = mb_substr(preg_replace('/\s+/', ' ', $chunk['chunk_text']), 0, 200);
        $chunkList .= "[{$num}] {$chunk['heading_path']}: {$preview}\n";
    }

    $ratingPrompt = <<<PROMPT
以下の質問に対して、各ドキュメントチャンクの関連度を0-10で評価してください。
7以上のチャンクの番号のみをJSON配列で出力してください。

【質問】
{$question}

【チャンク一覧】
{$chunkList}

【出力形式】
関連度7以上のチャンク番号のJSON配列。例: [1, 3, 5]
JSON以外のテキストは出力しないでください。
PROMPT;

    $ratingResult = callGeminiSync($ratingPrompt, $apiKey, $model, 0.1);
    if ($ratingResult) {
        $cleaned = preg_replace('/```json\s*/', '', $ratingResult);
        $cleaned = preg_replace('/```\s*/', '', $cleaned);
        $cleaned = trim($cleaned);
        $selectedNums = json_decode($cleaned, true);
        if (is_array($selectedNums) && !empty($selectedNums)) {
            $filtered = [];
            foreach ($selectedNums as $num) {
                $idx = intval($num) - 1;
                if (isset($allChunks[$idx])) {
                    $filtered[] = $allChunks[$idx];
                }
            }
            if (!empty($filtered)) {
                $relevantChunks = $filtered;
            }
        }
    }
}

if ($showDetail) {
    sendSSE(['detail' => '【Stage 3 関連性評価結果】' . count($relevantChunks) . '/' . count($allChunks) . '件が関連あり']);
}
sendSSE(['stage_done' => 3]);

// ===== Stage 4: 回答生成（SSEストリーミング） =====
sendSSE(['stage' => 4, 'label' => '回答生成中...']);

$contextText = formatRetrievedChunks($relevantChunks);
streamAnswerGeneration($question, $contextText, $apiKey, $model);

sendSSE(['stage_done' => 4]);
echo "data: [DONE]\n\n";
if (ob_get_level()) ob_flush();
flush();
exit;

// ===== Fallback Label =====
stage4_fallback:
sendSSE(['stage' => 4, 'label' => '回答生成中（フォールバック）...']);

$contextText = $fallbackContent ?? loadAllMarkdownFiles();
streamAnswerGeneration($question, $contextText, $apiKey, $model);

sendSSE(['stage_done' => 4]);
echo "data: [DONE]\n\n";
if (ob_get_level()) ob_flush();
flush();
exit;

// ===== Helper Functions =====

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

function streamAnswerGeneration($question, $contextText, $apiKey, $model) {
    $systemPrompt = <<<'SYSPROMPT'
あなたは行政実務の専門AIアシスタントです。
提供されたナレッジベースの情報に基づいて、正確で実用的な回答を生成してください。

【ルール】
- ナレッジベースに含まれる情報を優先して回答してください
- 引用元のセクション名や見出しを明記してください
- ナレッジベースにない情報については、その旨を明記してください
- 行政実務者にとって実用的な回答を心がけてください
- Markdown形式で構造化された回答を生成してください
SYSPROMPT;

    $userMessage = <<<USERMSG
【質問】
{$question}

【ナレッジベース（検索結果）】
{$contextText}

上記のナレッジベース情報に基づいて、質問に回答してください。
USERMSG;

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
