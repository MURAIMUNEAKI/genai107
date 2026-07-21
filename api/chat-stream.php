<?php
/**
 * チャット SSE ストリーミングプロキシ（Gemini / OpenAI 両対応）
 * 出力形式: NDJSON (useChat.ts パーサー互換)
 * {"text":"...","stopReason":"","trace":"","sessionId":""}
 */

// === PHP エラー HTML 出力を完全遮断（php_admin_value でも有効） ===
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    @file_put_contents(__DIR__ . '/debug.log',
        date('Y-m-d H:i:s') . " [{$errno}] {$errstr} in {$errfile}:{$errline}\n",
        FILE_APPEND | LOCK_EX);
    return true;
});
error_reporting(0);
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
@ini_set('memory_limit', '256M');

while (ob_get_level()) @ob_end_clean();
header('Content-Type: application/x-ndjson; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

// ヘルパー: エラーをユーザーに見えるテキストとして出力
function emitError($msg) {
    echo json_encode(['text' => "[エラー] {$msg}", 'stopReason' => 'end_turn', 'trace' => '', 'sessionId' => ''], JSON_UNESCAPED_UNICODE) . "\n";
    @flush();
}

// .env 読み込み
$envFile = __DIR__ . '/.env';
if (is_file($envFile)) {
    foreach (@file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
        $ln = trim($ln);
        if ($ln === '' || $ln[0] === '#' || strpos($ln, '=') === false) continue;
        putenv($ln);
    }
}

// 入力パース
$rawInput = @file_get_contents('php://input');
$input = ($rawInput !== false && $rawInput !== '') ? @json_decode($rawInput, true) : null;

if (!is_array($input)) {
    emitError('リクエストデータを受信できませんでした（ファイルが大きすぎる可能性があります）');
    exit;
}

$messages = isset($input['messages']) && is_array($input['messages']) ? $input['messages'] : [];

// model: 文字列 or オブジェクト両対応
$modelId = 'gemini-3.1-flash-lite';
if (isset($input['model'])) {
    if (is_string($input['model'])) {
        $modelId = $input['model'];
    } elseif (is_array($input['model']) && isset($input['model']['modelId'])) {
        $modelId = $input['model']['modelId'];
    }
}

// systemPrompt → messages 先頭に system ロールとして挿入
$systemPrompt = isset($input['systemPrompt']) ? trim($input['systemPrompt']) : '';
if ($systemPrompt !== '') {
    array_unshift($messages, ['role' => 'system', 'content' => $systemPrompt]);
}

// files → 各メッセージの files プロパティからアップロード済みファイルを読み取り extraData に変換
foreach ($messages as &$msg) {
    if (!isset($msg['files']) || !is_array($msg['files'])) continue;
    $msg['extraData'] = isset($msg['extraData']) ? $msg['extraData'] : [];
    foreach ($msg['files'] as $f) {
        $fname = isset($f['filename']) ? basename($f['filename']) : '';
        $filepath = __DIR__ . '/uploads/' . $fname;
        if ($fname === '' || !is_file($filepath)) continue;
        $mime = isset($f['mimeType']) ? $f['mimeType'] : 'application/octet-stream';
        // MIME が空やgenericの場合、拡張子から推定
        if ($mime === '' || $mime === 'application/octet-stream') {
            $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
            $mimeMap = [
                'pdf'=>'application/pdf','txt'=>'text/plain','csv'=>'text/csv',
                'png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp',
                'docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'pptx'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ];
            if (isset($mimeMap[$ext])) $mime = $mimeMap[$ext];
        }
        $fileData = @file_get_contents($filepath);
        if ($fileData === false) continue;
        $msg['extraData'][] = [
            'source' => [
                'type' => 'base64',
                'data' => base64_encode($fileData),
                'mediaType' => $mime
            ]
        ];
    }
    unset($msg['files']);
}
unset($msg);

// トップレベル files も処理（最後のユーザーメッセージに紐付け）
if (isset($input['files']) && is_array($input['files']) && !empty($input['files'])) {
    // 最後の user メッセージを探す
    $lastUserIdx = -1;
    for ($i = count($messages) - 1; $i >= 0; $i--) {
        if (isset($messages[$i]['role']) && $messages[$i]['role'] === 'user') {
            $lastUserIdx = $i;
            break;
        }
    }
    if ($lastUserIdx >= 0) {
        if (!isset($messages[$lastUserIdx]['extraData'])) $messages[$lastUserIdx]['extraData'] = [];
        foreach ($input['files'] as $f) {
            $fname = isset($f['filename']) ? basename($f['filename']) : '';
            $filepath = __DIR__ . '/uploads/' . $fname;
            if ($fname === '' || !is_file($filepath)) continue;
            $mime = isset($f['mimeType']) ? $f['mimeType'] : 'application/octet-stream';
            if ($mime === '' || $mime === 'application/octet-stream') {
                $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                $mimeMap = [
                    'pdf'=>'application/pdf','txt'=>'text/plain','csv'=>'text/csv',
                    'png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp',
                    'docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'pptx'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                    'xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ];
                if (isset($mimeMap[$ext])) $mime = $mimeMap[$ext];
            }
            $fileData = @file_get_contents($filepath);
            if ($fileData === false) continue;
            // 重複チェック（既にメッセージ内 files で追加済みならスキップ）
            $already = false;
            foreach ($messages[$lastUserIdx]['extraData'] as $ed) {
                if (isset($ed['source']['data']) && md5($ed['source']['data']) === md5(base64_encode($fileData))) {
                    $already = true;
                    break;
                }
            }
            if (!$already) {
                $messages[$lastUserIdx]['extraData'][] = [
                    'source' => [
                        'type' => 'base64',
                        'data' => base64_encode($fileData),
                        'mediaType' => $mime
                    ]
                ];
            }
        }
    }
}

// デバッグ: リクエスト情報をログ
@file_put_contents(__DIR__ . '/debug.log',
    date('Y-m-d H:i:s') . " [INFO] model={$modelId} messages=" . count($messages) .
    " rawInputLen=" . strlen($rawInput) . "\n",
    FILE_APPEND | LOCK_EX);

// モデルIDで振り分け
if (strpos($modelId, 'gpt-') === 0) {
    handleOpenAI($modelId, $messages);
} else {
    handleGemini($modelId, $messages);
}

/**
 * メッセージからテキストを安全に抽出
 */
function extractText($msg) {
    $content = isset($msg['content']) ? $msg['content'] : '';
    if (is_array($content)) {
        $text = '';
        foreach ($content as $part) {
            if (isset($part['body'])) $text .= $part['body'];
        }
        return $text;
    }
    return is_string($content) ? $content : '';
}

/**
 * extraData から base64 メディアを安全に抽出
 */
function extractMedia($msg) {
    $media = [];
    if (!isset($msg['extraData']) || !is_array($msg['extraData'])) return $media;

    foreach ($msg['extraData'] as $data) {
        if (!is_array($data) || !isset($data['source']) || !is_array($data['source'])) continue;
        $src = $data['source'];
        $srcType = isset($src['type']) ? $src['type'] : '';
        $srcData = isset($src['data']) ? $src['data'] : '';
        $mime = isset($src['mediaType']) ? $src['mediaType'] : 'application/octet-stream';

        if ($srcData === '') continue;

        if ($srcType === 'base64') {
            // data:image/png;base64, プレフィックスが付いている場合は除去
            $srcData = preg_replace('#^data:[^;]*;base64,#', '', $srcData);
            $media[] = ['data' => $srcData, 'mime' => $mime];
        } elseif ($srcType === 's3') {
            // S3 URL からローカルファイルを読み込んで base64 化
            $localPath = s3UrlToLocalPath($srcData);
            if ($localPath && is_file($localPath)) {
                $fileData = @file_get_contents($localPath);
                if ($fileData !== false) {
                    $media[] = ['data' => base64_encode($fileData), 'mime' => $mime];
                }
            }
        }
    }

    // デバッグ: メディア情報をログ
    if (!empty($media)) {
        $info = [];
        foreach ($media as $m) {
            $info[] = $m['mime'] . '(' . strlen($m['data']) . 'bytes)';
        }
        @file_put_contents(__DIR__ . '/debug.log',
            date('Y-m-d H:i:s') . " [MEDIA] " . implode(', ', $info) . "\n",
            FILE_APPEND | LOCK_EX);
    }

    return $media;
}

/**
 * S3 URL をローカルファイルパスに変換
 */
function s3UrlToLocalPath($url) {
    if (preg_match('#/api/uploads/([^/?]+)#', $url, $m)) {
        return __DIR__ . '/uploads/' . basename($m[1]);
    }
    return null;
}

/**
 * Gemini inlineData 対応 MIME タイプか判定
 */
function isGeminiSupported($mime) {
    // 画像・音声・動画・テキスト系は inlineData 対応
    if (strpos($mime, 'image/') === 0) return true;
    if (strpos($mime, 'audio/') === 0) return true;
    if (strpos($mime, 'video/') === 0) return true;
    if (strpos($mime, 'text/') === 0) return true;
    // PDF・Office 系はテキスト抽出して送信（Lite モデル互換のため）
    return false;
}

/**
 * Office ファイル (DOCX/PPTX/XLSX) から ZipArchive でテキスト抽出
 */
function extractOfficeText($base64data, $mime) {
    $decoded = @base64_decode($base64data);
    if ($decoded === false || strlen($decoded) < 100) return null;

    $tmpFile = @tempnam(sys_get_temp_dir(), 'office_');
    if (!$tmpFile) return null;
    @file_put_contents($tmpFile, $decoded);

    $text = null;
    $zip = new ZipArchive();
    if ($zip->open($tmpFile) === true) {
        if (strpos($mime, 'wordprocessingml') !== false || strpos($mime, 'msword') !== false) {
            // DOCX
            $xml = $zip->getFromName('word/document.xml');
            if ($xml) $text = strip_tags(str_replace('<', ' <', $xml));
        } elseif (strpos($mime, 'presentationml') !== false || strpos($mime, 'ms-powerpoint') !== false) {
            // PPTX
            $text = '';
            for ($i = 1; $i <= 100; $i++) {
                $xml = $zip->getFromName("ppt/slides/slide{$i}.xml");
                if (!$xml) break;
                $text .= strip_tags(str_replace('<', ' <', $xml)) . "\n";
            }
        } elseif (strpos($mime, 'spreadsheetml') !== false || strpos($mime, 'ms-excel') !== false) {
            // XLSX
            $xml = $zip->getFromName('xl/sharedStrings.xml');
            if ($xml) $text = strip_tags(str_replace('<', ' <', $xml));
        }
        $zip->close();
    }
    @unlink($tmpFile);

    if ($text !== null) {
        // 余分な空白を整理
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
    }
    return $text;
}

/**
 * PDF からテキストを抽出
 */
function extractPdfText($base64data) {
    $decoded = @base64_decode($base64data);
    if ($decoded === false || strlen($decoded) < 100) return null;

    $tmpFile = @tempnam(sys_get_temp_dir(), 'pdf_');
    if (!$tmpFile) return null;
    @file_put_contents($tmpFile, $decoded);

    // 方法1: pdftotext コマンド（最も正確）
    $txtFile = $tmpFile . '.txt';
    @exec('pdftotext -enc UTF-8 ' . escapeshellarg($tmpFile) . ' ' . escapeshellarg($txtFile) . ' 2>&1', $output, $ret);
    if ($ret === 0 && is_file($txtFile)) {
        $text = @file_get_contents($txtFile);
        @unlink($tmpFile);
        @unlink($txtFile);
        if ($text !== false && trim($text) !== '') {
            return trim($text);
        }
    }
    @unlink($tmpFile);
    if (is_file($txtFile)) @unlink($txtFile);

    // 方法2: PDF ストリームから直接テキスト抽出（フォールバック）
    $text = '';

    // FlateDecode ストリームを解凍してテキスト抽出
    if (preg_match_all('/stream\r?\n(.+?)\r?\nendstream/s', $decoded, $streams)) {
        foreach ($streams[1] as $stream) {
            $inflated = @gzuncompress($stream);
            if ($inflated === false) $inflated = @gzinflate($stream);
            if ($inflated === false) continue;

            // BT...ET ブロック内のテキストオペレータを抽出
            if (preg_match_all('/BT\s*(.*?)\s*ET/s', $inflated, $btBlocks)) {
                foreach ($btBlocks[1] as $block) {
                    // () 内のテキスト
                    if (preg_match_all('/\(([^)]*)\)/', $block, $parens)) {
                        foreach ($parens[1] as $t) {
                            // PDF エスケープ解除
                            $t = str_replace(['\\n','\\r','\\t','\\(','\\)','\\\\'], ["\n","\r","\t",'(',')',"\\" ], $t);
                            $text .= $t;
                        }
                    }
                    // Tj/TJ オペレータ後の改行判定
                    if (preg_match('/T[dD*]\s/', $block) || preg_match("/'\s/", $block)) {
                        $text .= "\n";
                    }
                }
            }
        }
    }

    // UTF-8 変換を試行
    if ($text !== '') {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $converted = @mb_convert_encoding($text, 'UTF-8', 'SJIS-win,EUC-JP,ISO-2022-JP,UTF-8');
            if ($converted !== false) $text = $converted;
        }
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
        $text = trim($text);
    }

    return ($text !== '') ? $text : null;
}

/**
 * 非対応ファイルをテキストに変換
 */
function fileToText($base64data, $mime) {
    // PDF ファイルの場合
    if ($mime === 'application/pdf' || strpos($mime, 'pdf') !== false) {
        $text = extractPdfText($base64data);
        if ($text !== null && $text !== '') {
            return mb_substr($text, 0, 50000);
        }
        return '[PDF: テキスト抽出に失敗しました。スキャンPDFの可能性があります]';
    }

    // Office ファイルの場合 ZipArchive で抽出
    if (strpos($mime, 'officedocument') !== false ||
        strpos($mime, 'msword') !== false ||
        strpos($mime, 'ms-powerpoint') !== false ||
        strpos($mime, 'ms-excel') !== false) {
        $text = extractOfficeText($base64data, $mime);
        if ($text !== null && $text !== '') {
            return mb_substr($text, 0, 30000);
        }
        return '[Office文書: テキスト抽出に失敗しました]';
    }

    // その他: テキストとして読めるか試行
    $decoded = @base64_decode($base64data);
    if ($decoded !== false && mb_detect_encoding($decoded, 'UTF-8', true)) {
        return mb_substr($decoded, 0, 10000);
    }
    return '[添付ファイル: ' . $mime . ' - テキスト抽出不可]';
}

// ========== OpenAI ==========
function handleOpenAI($modelId, $messages) {
    $apiKey = getenv('OPENAI_API_KEY');
    if (!$apiKey) {
        emitError('OPENAI_API_KEY が設定されていません');
        exit;
    }

    $openaiMessages = [];
    foreach ($messages as $msg) {
        $role = isset($msg['role']) ? $msg['role'] : 'user';
        // Gemini 形式 'model' → OpenAI 形式 'assistant' に変換
        if ($role === 'model') $role = 'assistant';
        $text = extractText($msg);
        $media = extractMedia($msg);

        // OpenAI は画像(image/*)のみ対応。非画像はテキスト化して送信
        $images = [];
        $extraText = '';
        foreach ($media as $m) {
            if (strpos($m['mime'], 'image/') === 0) {
                $images[] = $m;
            } else {
                $extracted = fileToText($m['data'], $m['mime']);
                $extraText .= "\n\n[添付ファイル内容]\n" . $extracted;
            }
        }

        if (!empty($images)) {
            $contentParts = [];
            if (($text . $extraText) !== '') {
                $contentParts[] = ['type' => 'text', 'text' => $text . $extraText];
            }
            foreach ($images as $img) {
                $contentParts[] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => "data:{$img['mime']};base64,{$img['data']}"]
                ];
            }
            $openaiMessages[] = ['role' => $role, 'content' => $contentParts];
        } else {
            $combined = $text . $extraText;
            if ($combined === '') continue;
            $openaiMessages[] = ['role' => $role, 'content' => $combined];
        }
    }

    $body = [
        'model' => $modelId,
        'messages' => $openaiMessages,
        'temperature' => 0.7,
        'stream' => true,
    ];

    $encoded = @json_encode($body);
    if ($encoded === false) {
        emitError('リクエストデータのエンコードに失敗しました');
        exit;
    }

    $url = 'https://api.openai.com/v1/chat/completions';
    $outputSent = false;
    $errorBuffer = '';
    $lineBuffer = '';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$outputSent, &$errorBuffer, &$lineBuffer) {
        $lineBuffer .= $data;
        // 完結した行（\n で終わるもの）だけ処理、未完結は次チャンクへ持ち越し
        while (($pos = strpos($lineBuffer, "\n")) !== false) {
            $line = trim(substr($lineBuffer, 0, $pos));
            $lineBuffer = substr($lineBuffer, $pos + 1);

            if ($line === '') continue;

            if ($line === 'data: [DONE]') {
                echo json_encode(['text' => '', 'stopReason' => 'end_turn', 'trace' => '', 'sessionId' => ''], JSON_UNESCAPED_UNICODE) . "\n";
                @flush();
                $outputSent = true;
                continue;
            }
            if (strpos($line, 'data: ') === 0) {
                $json = @json_decode(substr($line, 6), true);
                if (!is_array($json)) continue;

                $text = isset($json['choices'][0]['delta']['content']) ? $json['choices'][0]['delta']['content'] : '';
                $finishReason = isset($json['choices'][0]['finish_reason']) ? $json['choices'][0]['finish_reason'] : '';
                $stopReason = ($finishReason === 'stop') ? 'end_turn' : '';

                if ($text !== '' || $stopReason !== '') {
                    echo json_encode([
                        'text' => $text,
                        'stopReason' => $stopReason,
                        'trace' => '',
                        'sessionId' => ''
                    ], JSON_UNESCAPED_UNICODE) . "\n";
                    @flush();
                    $outputSent = true;
                }
            } else {
                $errorBuffer .= $line;
            }
        }
        return strlen($data);
    });

    $result = @curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // SSEデータが1行も来なかった場合 → APIエラーをユーザーに表示
    if (!$outputSent) {
        $errMsg = "OpenAI API エラー (HTTP {$httpCode})";
        if ($errorBuffer !== '') {
            $errJson = @json_decode($errorBuffer, true);
            if (is_array($errJson) && isset($errJson['error']['message'])) {
                $errMsg .= ': ' . $errJson['error']['message'];
            } else {
                $errMsg .= ': ' . mb_substr($errorBuffer, 0, 300);
            }
        }
        if ($result === false) {
            $errMsg = 'cURL Error: ' . curl_error($ch);
        }
        emitError($errMsg);
    }
}

// ========== Gemini ==========
function handleGemini($modelId, $messages) {
    $apiKey = getenv('GEMINI_API_KEY');
    if (!$apiKey) {
        emitError('APIキーが設定されていません');
        exit;
    }

    $systemInstruction = '';
    $contents = [];

    foreach ($messages as $msg) {
        $role = isset($msg['role']) ? $msg['role'] : 'user';
        $text = extractText($msg);

        if ($role === 'system') {
            $systemInstruction .= $text . "\n";
            continue;
        }

        $geminiRole = ($role === 'assistant') ? 'model' : 'user';
        $parts = [];
        if ($text !== '') {
            $parts[] = ['text' => $text];
        }

        // extraData (base64画像・ファイル添付)
        $media = extractMedia($msg);
        foreach ($media as $m) {
            if (isGeminiSupported($m['mime'])) {
                // 対応形式 → inlineData でそのまま送信
                $parts[] = [
                    'inlineData' => [
                        'mimeType' => $m['mime'],
                        'data' => $m['data']
                    ]
                ];
            } else {
                // 非対応形式（Office等）→ テキスト抽出して送信
                $extracted = fileToText($m['data'], $m['mime']);
                $parts[] = ['text' => "\n[添付ファイル内容]\n" . $extracted];
            }
        }

        if (!empty($parts)) {
            $contents[] = ['role' => $geminiRole, 'parts' => $parts];
        }
    }

    if (empty($contents)) {
        emitError('送信するメッセージがありません');
        exit;
    }

    $body = [
        'contents' => $contents,
        'generationConfig' => ['temperature' => 0.7]
    ];
    if ($systemInstruction !== '') {
        $body['systemInstruction'] = ['parts' => [['text' => trim($systemInstruction)]]];
    }

    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$modelId}:streamGenerateContent?alt=sse";

    $encoded = @json_encode($body);
    if ($encoded === false) {
        emitError('リクエストデータのエンコードに失敗しました（画像が大きすぎる可能性があります）');
        exit;
    }

    // デバッグ: APIリクエストサイズをログ
    @file_put_contents(__DIR__ . '/debug.log',
        date('Y-m-d H:i:s') . " [GEMINI] requestSize=" . strlen($encoded) . " model={$modelId}\n",
        FILE_APPEND | LOCK_EX);

    $outputSent = false;
    $errorBuffer = '';
    $lineBuffer = '';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-goog-api-key: ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$outputSent, &$errorBuffer, &$lineBuffer) {
        $lineBuffer .= $data;
        // 完結した行（\n で終わるもの）だけ処理、未完結は次チャンクへ持ち越し
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
            } else {
                $errorBuffer .= $line;
            }
        }
        return strlen($data);
    });

    $result = @curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // SSEデータが1行も来なかった場合 → APIエラーをユーザーに表示
    if (!$outputSent) {
        $errMsg = "Gemini API エラー (HTTP {$httpCode})";
        if ($errorBuffer !== '') {
            $errJson = @json_decode($errorBuffer, true);
            if (is_array($errJson) && isset($errJson['error']['message'])) {
                $errMsg .= ': ' . $errJson['error']['message'];
            } else {
                $errMsg .= ': ' . mb_substr($errorBuffer, 0, 300);
            }
        }
        // デバッグログにも記録
        @file_put_contents(__DIR__ . '/debug.log',
            date('Y-m-d H:i:s') . " [GEMINI-ERR] HTTP={$httpCode} body=" . mb_substr($errorBuffer, 0, 500) . "\n",
            FILE_APPEND | LOCK_EX);
        emitError($errMsg);
    }
}
