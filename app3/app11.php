<?php
/**
 * app11.php - 日本成長戦略 チャット相談（Dify SSE プロキシ）
 * 国家戦略「日本成長戦略」のナレッジベースを持つ Dify アプリに接続し、
 * SSE を PHP 側でパースして NDJSON 形式で再送信する。
 *
 * 出力形式: NDJSON
 *   {"conversation_id":"..."}           会話ID（初回に1度だけ・マルチターン継続用）
 *   {"text":"...","stopReason":""}      テキスト増分
 *   {"text":"","stopReason":"end_turn"} 完了
 *   {"citations":[...]}                 引用元（retriever_resources）
 */

// === PHP エラー HTML 出力を完全遮断 ===
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    @file_put_contents(__DIR__ . '/debug.log',
        date('Y-m-d H:i:s') . " [app11] [{$errno}] {$errstr} in {$errfile}:{$errline}\n",
        FILE_APPEND | LOCK_EX);
    return true;
});
error_reporting(0);
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');

while (ob_get_level()) @ob_end_clean();
header('Content-Type: application/x-ndjson; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

function emitError($msg) {
    echo json_encode(['text' => "[エラー] {$msg}", 'stopReason' => 'end_turn'], JSON_UNESCAPED_UNICODE) . "\n";
    @flush();
}

// .env 読み込み
$envFile = __DIR__ . '/../api/.env';
$apiKey = '';
if (is_file($envFile)) {
    foreach (@file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
        $ln = trim($ln);
        if ($ln === '' || $ln[0] === '#' || strpos($ln, '=') === false) continue;
        $p = explode('=', $ln, 2);
        $k = trim($p[0]);
        $v = trim($p[1]);
        if ($k === 'DIFY_GROWTH_KEY') $apiKey = $v;
    }
}

if (!$apiKey) {
    emitError('DIFY_GROWTH_KEY が設定されていません');
    exit;
}

// 入力パース
$rawInput = @file_get_contents('php://input');
$input = ($rawInput !== false && $rawInput !== '') ? @json_decode($rawInput, true) : null;
if (!is_array($input)) {
    emitError('リクエストデータを受信できませんでした');
    exit;
}

$query = isset($input['query']) ? trim($input['query']) : '';
if ($query === '') {
    emitError('質問が入力されていません');
    exit;
}
$query = mb_substr($query, 0, 10000);

$conversationId = isset($input['conversation_id']) ? trim($input['conversation_id']) : '';
$user = 'gennai-user';

// Dify API リクエストボディ
$data = [
    'inputs'          => new \stdClass(),
    'query'           => $query,
    'response_mode'   => 'streaming',
    'conversation_id' => $conversationId,
    'user'            => $user,
];

$apiUrl = 'https://api.dify.ai/v1/chat-messages';

$encoded = json_encode($data, JSON_UNESCAPED_UNICODE);

@file_put_contents(__DIR__ . '/debug.log',
    date('Y-m-d H:i:s') . " [app11] query=" . mb_substr($query, 0, 80) .
    " convId=" . ($conversationId ?: '(new)') . "\n",
    FILE_APPEND | LOCK_EX);

// ── Dify SSE をパースして NDJSON に変換 ──
$state = [
    'buffer'         => '',
    'outputSent'     => false,
    'convIdSent'     => false,
    'toolByPosition' => [],
    'observations'   => [],
    'citationsSent'  => false,
];

$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $encoded,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ],
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_TIMEOUT        => 300,
]);

curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$state) {
    $state['buffer'] .= $data;

    while (($nlPos = strpos($state['buffer'], "\n")) !== false) {
        $line = substr($state['buffer'], 0, $nlPos);
        $state['buffer'] = substr($state['buffer'], $nlPos + 1);
        $line = rtrim($line, "\r");

        if ($line === '' || strpos($line, 'data:') !== 0) continue;

        $jsonStr = ltrim(substr($line, 5));
        if ($jsonStr === '' || $jsonStr === '[DONE]') continue;

        $event = @json_decode($jsonStr, true);
        if (!is_array($event)) continue;

        $eventType = isset($event['event']) ? $event['event'] : '';

        // 会話IDを初回に1度だけクライアントへ返す（マルチターン継続用）
        if (!$state['convIdSent'] && isset($event['conversation_id']) && $event['conversation_id'] !== '') {
            echo json_encode(['conversation_id' => $event['conversation_id']], JSON_UNESCAPED_UNICODE) . "\n";
            @flush();
            $state['convIdSent'] = true;
        }

        switch ($eventType) {
            case 'message':
            case 'agent_message':
                // この Dify 環境: answer は増分テキスト（各イベントがデルタ）
                $answer = isset($event['answer']) ? $event['answer'] : '';
                if ($answer !== '') {
                    echo json_encode(['text' => $answer, 'stopReason' => ''], JSON_UNESCAPED_UNICODE) . "\n";
                    @flush();
                    $state['outputSent'] = true;
                }
                break;

            case 'message_end':
                // 引用元抽出
                $resources = [];
                if (isset($event['metadata']) && is_array($event['metadata'])) {
                    $resources = isset($event['metadata']['retriever_resources']) ? $event['metadata']['retriever_resources'] : [];
                }
                if (!is_array($resources) || empty($resources)) {
                    $resources = isset($event['retriever_resources']) ? $event['retriever_resources'] : [];
                }

                if (is_array($resources) && !empty($resources) && !$state['citationsSent']) {
                    $citations = [];
                    foreach ($resources as $r) {
                        $title = isset($r['document_name']) ? $r['document_name']
                            : (isset($r['dataset_name']) ? $r['dataset_name']
                            : (isset($r['title']) ? $r['title'] : ''));
                        $citations[] = [
                            'title'   => $title,
                            'content' => isset($r['content']) ? $r['content'] : '',
                            'score'   => isset($r['score']) ? $r['score'] : 0,
                        ];
                    }
                    echo json_encode(['citations' => $citations], JSON_UNESCAPED_UNICODE) . "\n";
                    @flush();
                    $state['citationsSent'] = true;
                }

                // observation フォールバック（agent モードで retriever_resources がない場合）
                if (!$state['citationsSent'] && !empty($state['observations'])) {
                    $citations = [];
                    foreach ($state['observations'] as $o) {
                        $citations[] = [
                            'title'   => $o['tool'],
                            'content' => $o['observation'],
                            'score'   => 0,
                        ];
                    }
                    echo json_encode(['citations' => $citations], JSON_UNESCAPED_UNICODE) . "\n";
                    @flush();
                    $state['citationsSent'] = true;
                }

                // 完了シグナル
                echo json_encode(['text' => '', 'stopReason' => 'end_turn'], JSON_UNESCAPED_UNICODE) . "\n";
                @flush();
                break;

            case 'agent_thought':
                // position でツール名を追跡
                $thoughtPos = isset($event['position']) ? $event['position'] : (isset($event['id']) ? $event['id'] : '');
                $tool = isset($event['tool']) ? trim($event['tool']) : '';
                if ($tool !== '' && $thoughtPos !== '') {
                    $state['toolByPosition'][$thoughtPos] = $tool;
                }

                // agent_thought 内の retriever_resources
                $agentRes = [];
                if (isset($event['metadata']) && is_array($event['metadata'])) {
                    $agentRes = isset($event['metadata']['retriever_resources']) ? $event['metadata']['retriever_resources'] : [];
                }
                if (!is_array($agentRes) || empty($agentRes)) {
                    $agentRes = isset($event['retriever_resources']) ? $event['retriever_resources'] : [];
                }
                if (is_array($agentRes) && !empty($agentRes) && !$state['citationsSent']) {
                    $citations = [];
                    foreach ($agentRes as $r) {
                        $title = isset($r['document_name']) ? $r['document_name']
                            : (isset($r['dataset_name']) ? $r['dataset_name']
                            : (isset($r['title']) ? $r['title'] : ''));
                        $citations[] = [
                            'title'   => $title,
                            'content' => isset($r['content']) ? $r['content'] : '',
                            'score'   => isset($r['score']) ? $r['score'] : 0,
                        ];
                    }
                    echo json_encode(['citations' => $citations], JSON_UNESCAPED_UNICODE) . "\n";
                    @flush();
                    $state['citationsSent'] = true;
                }

                // observation 蓄積
                $obs = isset($event['observation']) ? trim($event['observation']) : '';
                if ($obs !== '') {
                    $resolvedTool = $tool !== '' ? $tool
                        : (isset($state['toolByPosition'][$thoughtPos]) ? $state['toolByPosition'][$thoughtPos] : 'ナレッジ検索');
                    $state['observations'][] = [
                        'tool'        => $resolvedTool,
                        'observation' => $obs,
                    ];
                }
                break;

            case 'error':
                $errMsg = isset($event['message']) ? $event['message'] : 'Dify API エラー';
                emitError($errMsg);
                break;
        }
    }
    return strlen($data);
});

$result = @curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if (!$state['outputSent']) {
    if ($curlErr) {
        emitError('通信エラー: ' . $curlErr);
    } elseif ($httpCode >= 400) {
        emitError('Dify API エラー (HTTP ' . $httpCode . ')');
    } else {
        emitError('応答を取得できませんでした');
    }
}
