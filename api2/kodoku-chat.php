<?php
/**
 * 孤独・孤立対策 専用チャット API
 * ナレッジベース（data1.md + data2_extracted.txt）をシステムプロンプトに注入し、
 * Gemini API で応答をストリーミング返却する。
 * 出力形式: NDJSON
 */

// === PHP エラー HTML 出力を完全遮断 ===
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    @file_put_contents(__DIR__ . '/debug.log',
        date('Y-m-d H:i:s') . " [kodoku] [{$errno}] {$errstr} in {$errfile}:{$errline}\n",
        FILE_APPEND | LOCK_EX);
    return true;
});
error_reporting(0);
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
@ini_set('memory_limit', '512M');

while (ob_get_level()) @ob_end_clean();
header('Content-Type: application/x-ndjson; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

function emitError($msg) {
    echo json_encode(['text' => "[エラー] {$msg}", 'stopReason' => 'end_turn'], JSON_UNESCAPED_UNICODE) . "\n";
    @flush();
}

// .env 読み込み
$envFile = __DIR__ . '/../api/.env';
if (is_file($envFile)) {
    foreach (@file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
        $ln = trim($ln);
        if ($ln === '' || $ln[0] === '#' || strpos($ln, '=') === false) continue;
        putenv($ln);
    }
}

$apiKey = getenv('GEMINI_API_KEY');
if (!$apiKey) {
    emitError('APIキーが設定されていません');
    exit;
}

// 入力パース
$rawInput = @file_get_contents('php://input');
$input = ($rawInput !== false && $rawInput !== '') ? @json_decode($rawInput, true) : null;
if (!is_array($input)) {
    emitError('リクエストデータを受信できませんでした');
    exit;
}

$messages = isset($input['messages']) && is_array($input['messages']) ? $input['messages'] : [];
if (empty($messages)) {
    emitError('メッセージがありません');
    exit;
}

// ── ナレッジベース読み込み ──
$kbDir = __DIR__ . '/../app2/kodoku/';
$data1 = @file_get_contents($kbDir . 'data1.md');
$data2 = @file_get_contents($kbDir . 'data2_extracted.txt');

if ($data1 === false && $data2 === false) {
    emitError('ナレッジベースファイルが見つかりません');
    exit;
}

$knowledgeBase = '';
if ($data1 !== false) $knowledgeBase .= "===== 資料1: 孤独・孤立対策重点計画（概要・基本方針） =====\n\n" . $data1 . "\n\n";
if ($data2 !== false) $knowledgeBase .= "===== 資料2: 具体的施策（No.001〜No.143 詳細） =====\n\n" . $data2 . "\n\n";

// ── システムプロンプト構築 ──
$systemPrompt = <<<PROMPT
あなたは「孤独・孤立対策」の専門アドバイザーです。
日本政府の「孤独・孤立対策重点計画」に基づき、自治体職員からの質問に正確かつ丁寧に回答してください。

## 回答ルール

1. **必ず以下のナレッジベースの情報に基づいて回答してください。** ナレッジベースに記載がない情報については、その旨を明記してください。
2. **施策番号の引用**: 回答内で関連する施策に言及する場合、必ず「No.XXX: 施策名【所管省庁】」の形式で引用してください。
3. **引用セクション**: 回答の末尾に、参照した施策の一覧を以下の形式でまとめてください：

---
**📋 参照した施策・資料:**
- No.XXX: 施策名【所管省庁】
- No.YYY: 施策名【所管省庁】
- 資料1: セクション名（該当する場合）

4. 自治体が具体的にどのような支援を行えるか、実践的なアドバイスを含めてください。
5. 基本方針（4つ）との関連を示してください：
   - (1) 孤独・孤立に至っても支援を求める声を上げやすい社会とする
   - (2) 状況に合わせた切れ目のない相談支援につなげる
   - (3) 見守り・交流の場や居場所を確保し、人と人との「つながり」を実感できる地域づくりを行う
   - (4) 孤独・孤立対策に取り組むNPO等の活動をきめ細かく支援し、官・民・NPO等の連携を強化する
6. 回答は日本語で行ってください。

## ナレッジベース

{$knowledgeBase}
PROMPT;

// ── Gemini API 呼び出し ──
$modelId = 'gemini-3.1-flash-lite';

$contents = [];
foreach ($messages as $msg) {
    $role = isset($msg['role']) ? $msg['role'] : 'user';
    $text = is_string($msg['content']) ? $msg['content'] : '';
    if ($text === '') continue;
    $geminiRole = ($role === 'assistant' || $role === 'model') ? 'model' : 'user';
    $contents[] = ['role' => $geminiRole, 'parts' => [['text' => $text]]];
}

if (empty($contents)) {
    emitError('送信するメッセージがありません');
    exit;
}

$body = [
    'contents' => $contents,
    'generationConfig' => ['temperature' => 0.5, 'maxOutputTokens' => 65536],
    'systemInstruction' => ['parts' => [['text' => $systemPrompt]]]
];

$encoded = @json_encode($body);
if ($encoded === false) {
    emitError('リクエストデータのエンコードに失敗しました');
    exit;
}

@file_put_contents(__DIR__ . '/debug.log',
    date('Y-m-d H:i:s') . " [kodoku] requestSize=" . strlen($encoded) . " model={$modelId} kbSize=" . strlen($knowledgeBase) . "\n",
    FILE_APPEND | LOCK_EX);

$url = "https://generativelanguage.googleapis.com/v1beta/models/{$modelId}:streamGenerateContent?alt=sse";

$outputSent = false;
$lineBuffer = '';

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'x-goog-api-key: ' . $apiKey
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 180);

curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$outputSent, &$lineBuffer) {
    $lineBuffer .= $data;
    while (($pos = strpos($lineBuffer, "\n")) !== false) {
        $line = trim(substr($lineBuffer, 0, $pos));
        $lineBuffer = substr($lineBuffer, $pos + 1);
        if ($line === '') continue;

        if (strpos($line, 'data: ') === 0) {
            $json = @json_decode(substr($line, 6), true);
            if (!is_array($json)) continue;

            $text = '';
            $stopReason = '';
            if (isset($json['candidates'][0]['content']['parts']) && is_array($json['candidates'][0]['content']['parts'])) {
                foreach ($json['candidates'][0]['content']['parts'] as $part) {
                    if (isset($part['text'])) $text .= $part['text'];
                }
            }
            if (isset($json['candidates'][0]['finishReason'])) {
                $fr = $json['candidates'][0]['finishReason'];
                $stopReason = ($fr === 'STOP') ? 'end_turn' : $fr;
            }

            echo json_encode([
                'text' => $text,
                'stopReason' => $stopReason,
                'trace' => '',
                'sessionId' => ''
            ], JSON_UNESCAPED_UNICODE) . "\n";
            @flush();
            $outputSent = true;
        }
    }
    return strlen($data);
});

$result = @curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if (!$outputSent) {
    if ($curlErr) {
        emitError('通信エラー: ' . $curlErr);
    } elseif ($httpCode >= 400) {
        emitError('Gemini API エラー (HTTP ' . $httpCode . ')');
    } else {
        emitError('応答を取得できませんでした');
    }
}
