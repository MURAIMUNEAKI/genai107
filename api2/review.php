<?php
/**
 * 行政事業レビューAI 専用 SSE ストリーミングプロキシ（Gemini）
 * 出力形式: NDJSON {"text":"...","stopReason":"","trace":"","sessionId":""}
 *
 * 受信(JSON):
 *   project_name, fiscal_year, department, mode,
 *   files: [{ filename, mimeType, originalName }]   ← file-upload.php でアップロード済み
 *
 * APIキーは共有 api/.env の GEMINI_API_KEY を使用（他アプリと同一）。
 */

// === PHP エラー HTML 出力を遮断 ===
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
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

function emitError($msg) {
    echo json_encode(['text' => "[エラー] {$msg}", 'stopReason' => 'end_turn', 'trace' => '', 'sessionId' => ''], JSON_UNESCAPED_UNICODE) . "\n";
    @flush();
}

// .env 読み込み（他PHPと同方式）
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
$modelId = getenv('GEMINI_MODEL');
if (!$modelId) $modelId = 'gemini-3.1-flash-lite';

// 入力パース
$rawInput = @file_get_contents('php://input');
$input = ($rawInput !== false && $rawInput !== '') ? @json_decode($rawInput, true) : null;
if (!is_array($input)) {
    emitError('リクエストデータを受信できませんでした');
    exit;
}

$projectName = sanitize((string)($input['project_name'] ?? ''));
$fiscalYear  = sanitize((string)($input['fiscal_year'] ?? ''));
$department  = sanitize((string)($input['department'] ?? ''));
$files       = (isset($input['files']) && is_array($input['files'])) ? $input['files'] : [];

if (empty($files)) {
    emitError('ファイルが送信されていません');
    exit;
}

// アップロード済みファイルを読み込み、Gemini parts を構築
$fileParts = [];
$fileSummaries = [];
foreach ($files as $f) {
    $fname = isset($f['filename']) ? basename((string)$f['filename']) : '';
    if ($fname === '') continue;
    $filepath = __DIR__ . '/uploads/' . $fname;
    if (!is_file($filepath)) continue;

    $original = sanitize((string)($f['originalName'] ?? $fname));
    $mime = isset($f['mimeType']) ? (string)$f['mimeType'] : '';
    if ($mime === '' || $mime === 'application/octet-stream') {
        $mime = mimeFromExt($fname);
    }

    $data = @file_get_contents($filepath);
    if ($data === false) continue;
    $base64 = base64_encode($data);

    if (isGeminiSupported($mime)) {
        // PDF・画像・テキスト系 → inlineData でそのまま Gemini へ
        $fileParts[] = ['inlineData' => ['mimeType' => $mime, 'data' => $base64]];
        $fileParts[] = ['text' => "\n（上記ファイル: {$original}）\n"];
        $fileSummaries[] = ['name' => $original, 'type' => $mime, 'method' => 'inlineData'];
    } else {
        // Office 等 → テキスト抽出して送信
        $extracted = fileToText($base64, $mime);
        $fileParts[] = ['text' => "\n[ファイル: {$original}]\n" . $extracted . "\n"];
        $fileSummaries[] = ['name' => $original, 'type' => $mime, 'method' => 'extractedText'];
    }
}

if (empty($fileParts)) {
    emitError('アップロードされたファイルを読み込めませんでした');
    exit;
}

// プロンプト構築
$systemPrompt = buildReviewSystemPrompt();
$userPromptHead = buildReviewUserPrompt(
    $projectName !== '' ? $projectName : '未入力',
    $fiscalYear !== '' ? $fiscalYear : '未入力',
    $department !== '' ? $department : '未入力',
    $fileSummaries
);

// Gemini contents
$parts = array_merge([['text' => $userPromptHead]], $fileParts);
$contents = [['role' => 'user', 'parts' => $parts]];

$body = [
    'contents' => $contents,
    'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
    'generationConfig' => ['temperature' => 0.4, 'maxOutputTokens' => 65536],
];

$encoded = @json_encode($body);
if ($encoded === false) {
    emitError('リクエストデータのエンコードに失敗しました（ファイルが大きすぎる可能性があります）');
    exit;
}

@file_put_contents(__DIR__ . '/debug.log',
    date('Y-m-d H:i:s') . " [REVIEW] requestSize=" . strlen($encoded) . " files=" . count($fileSummaries) . " model={$modelId}\n",
    FILE_APPEND | LOCK_EX);

$url = "https://generativelanguage.googleapis.com/v1beta/models/{$modelId}:streamGenerateContent?alt=sse";

$outputSent = false;
$errorBuffer = '';
$lineBuffer = '';

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'x-goog-api-key: ' . $apiKey,
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 180);

curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$outputSent, &$errorBuffer, &$lineBuffer) {
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
        } else {
            $errorBuffer .= $line;
        }
    }
    return strlen($data);
});

$result = @curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

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
    @file_put_contents(__DIR__ . '/debug.log',
        date('Y-m-d H:i:s') . " [REVIEW-ERR] HTTP={$httpCode} body=" . mb_substr($errorBuffer, 0, 500) . "\n",
        FILE_APPEND | LOCK_EX);
    emitError($errMsg);
}

/* ==================== ヘルパー ==================== */

function sanitize($text) {
    $text = (string)$text;
    if (!mb_check_encoding($text, 'UTF-8')) {
        $conv = @mb_convert_encoding($text, 'UTF-8', 'UTF-8, SJIS-win, CP932, EUC-JP, ISO-2022-JP');
        if ($conv !== false) $text = $conv;
    }
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text);
    return trim($text);
}

function mimeFromExt($fname) {
    $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
    $map = [
        'pdf'  => 'application/pdf',
        'txt'  => 'text/plain',
        'csv'  => 'text/csv',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];
    return isset($map[$ext]) ? $map[$ext] : 'application/octet-stream';
}

function isGeminiSupported($mime) {
    if (strpos($mime, 'image/') === 0) return true;
    if (strpos($mime, 'text/') === 0) return true;
    if ($mime === 'application/pdf') return true;
    return false;
}

function fileToText($base64data, $mime) {
    if ($mime === 'application/pdf' || strpos($mime, 'pdf') !== false) {
        $text = extractPdfText($base64data);
        if ($text !== null && $text !== '') return mb_substr($text, 0, 50000);
        return '[PDF: テキスト抽出に失敗しました。スキャンPDFの可能性があります]';
    }
    if (strpos($mime, 'officedocument') !== false ||
        strpos($mime, 'msword') !== false ||
        strpos($mime, 'ms-powerpoint') !== false ||
        strpos($mime, 'ms-excel') !== false) {
        $text = extractOfficeText($base64data, $mime);
        if ($text !== null && $text !== '') return mb_substr($text, 0, 40000);
        return '[Office文書: テキスト抽出に失敗しました]';
    }
    $decoded = @base64_decode($base64data);
    if ($decoded !== false && mb_detect_encoding($decoded, 'UTF-8', true)) {
        return mb_substr($decoded, 0, 40000);
    }
    return '[添付ファイル: ' . $mime . ' - テキスト抽出不可]';
}

function extractOfficeText($base64data, $mime) {
    $decoded = @base64_decode($base64data);
    if ($decoded === false || strlen($decoded) < 100) return null;
    if (!class_exists('ZipArchive')) return null;

    $tmpFile = @tempnam(sys_get_temp_dir(), 'office_');
    if (!$tmpFile) return null;
    @file_put_contents($tmpFile, $decoded);

    $text = null;
    $zip = new ZipArchive();
    if ($zip->open($tmpFile) === true) {
        if (strpos($mime, 'wordprocessingml') !== false || strpos($mime, 'msword') !== false) {
            $xml = $zip->getFromName('word/document.xml');
            if ($xml) $text = strip_tags(str_replace('<', ' <', $xml));
        } elseif (strpos($mime, 'presentationml') !== false || strpos($mime, 'ms-powerpoint') !== false) {
            $text = '';
            for ($i = 1; $i <= 200; $i++) {
                $xml = $zip->getFromName("ppt/slides/slide{$i}.xml");
                if (!$xml) break;
                $text .= strip_tags(str_replace('<', ' <', $xml)) . "\n";
            }
        } elseif (strpos($mime, 'spreadsheetml') !== false || strpos($mime, 'ms-excel') !== false) {
            $xml = $zip->getFromName('xl/sharedStrings.xml');
            if ($xml) $text = strip_tags(str_replace('<', ' <', $xml));
        }
        $zip->close();
    }
    @unlink($tmpFile);

    if ($text !== null) {
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
    }
    return $text;
}

function extractPdfText($base64data) {
    $decoded = @base64_decode($base64data);
    if ($decoded === false || strlen($decoded) < 100) return null;

    $tmpFile = @tempnam(sys_get_temp_dir(), 'pdf_');
    if (!$tmpFile) return null;
    @file_put_contents($tmpFile, $decoded);

    // 方法1: pdftotext
    $txtFile = $tmpFile . '.txt';
    @exec('pdftotext -enc UTF-8 ' . escapeshellarg($tmpFile) . ' ' . escapeshellarg($txtFile) . ' 2>&1', $output, $ret);
    if ($ret === 0 && is_file($txtFile)) {
        $text = @file_get_contents($txtFile);
        @unlink($tmpFile);
        @unlink($txtFile);
        if ($text !== false && trim($text) !== '') return trim($text);
    }
    @unlink($tmpFile);
    if (is_file($txtFile)) @unlink($txtFile);

    // 方法2: PDF ストリームから直接抽出（フォールバック）
    $text = '';
    if (preg_match_all('/stream\r?\n(.+?)\r?\nendstream/s', $decoded, $streams)) {
        foreach ($streams[1] as $stream) {
            $inflated = @gzuncompress($stream);
            if ($inflated === false) $inflated = @gzinflate($stream);
            if ($inflated === false) continue;
            if (preg_match_all('/BT\s*(.*?)\s*ET/s', $inflated, $btBlocks)) {
                foreach ($btBlocks[1] as $block) {
                    if (preg_match_all('/\(([^)]*)\)/', $block, $parens)) {
                        foreach ($parens[1] as $t) {
                            $t = str_replace(['\\n','\\r','\\t','\\(','\\)','\\\\'], ["\n","\r","\t",'(',')',"\\"], $t);
                            $text .= $t;
                        }
                    }
                    if (preg_match('/T[dD*]\s/', $block) || preg_match("/'\s/", $block)) {
                        $text .= "\n";
                    }
                }
            }
        }
    }
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

/* ==================== プロンプト ==================== */

function buildReviewSystemPrompt() {
    return "あなたは行政事業レビュー支援AIです。\n"
        . "入力される内容は、PDF・Word・CSVから抽出または読み取った行政事業の資料です。\n"
        . "元資料の表やレイアウトは崩れている可能性があります。\n\n"
        . "厳守事項:\n"
        . "- 根拠がある内容だけを書く\n"
        . "- 推測で金額・年度・成果・執行率を補完しない\n"
        . "- 不明な場合は「資料上確認できない」と書く\n"
        . "- 行政事業レビューの補助分析として出力する\n"
        . "- 最終判断は人が行う前提で書く\n"
        . "- 重要な数値・KPI・執行状況・支出先・課題・改善提案を優先する\n"
        . "- 同じ指摘を繰り返さない\n"
        . "- 読みやすい日本語で、短い見出しと箇条書き（Markdown）を使う";
}

function buildReviewUserPrompt($projectName, $fiscalYear, $department, $fileSummaries) {
    $fileList = '';
    foreach ($fileSummaries as $i => $s) {
        $fileList .= '- ' . $s['name'] . "\n";
    }

    $header = "添付した資料をもとに、行政事業レビューを実施してください。\n\n"
        . "利用者入力:\n"
        . "- 事業名: {$projectName}\n"
        . "- 年度: {$fiscalYear}\n"
        . "- 所管課: {$department}\n\n"
        . "対象ファイル:\n{$fileList}\n";

    $body = "以下の5部構成のレポートを、この順番で1つにまとめて出力してください。\n"
        . "（冒頭は幹部向け要約、末尾は長文の改善提案です）\n\n"
        . "出力形式（Markdown）:\n\n"

        // 1. 幹部向け要約
        . "# 1. 幹部向け要約\n"
        . "多忙な幹部が30秒で把握できるよう、結論を冒頭に置き、簡潔にまとめてください。\n"
        . "## 結論（1行）\n"
        . "総合判定（現状通り/執行改善/一部改善/抜本的見直し/縮減/廃止検討）と最大の理由を1行で。\n"
        . "## 要点\n- 事業の狙いと規模（予算額・対象）を1〜2行\n- 成果・KPIの状況を1〜2行\n"
        . "## 幹部が判断すべき論点\n- 3点以内（意思決定が必要な点）\n\n"

        // 2. レビューシート
        . "# 2. レビューシート\n"
        . "行政事業レビューシートに転記できるよう、網羅的に整理してください。\n"
        . "## 基本情報\n- 事業名\n- 所管\n- 年度\n- 資料の性質\n"
        . "## 事業概要\n- 目的\n- 現状課題\n- 実施内容\n- 対象者\n"
        . "## 予算・執行\n- 予算額\n- 執行額\n- 執行率\n- 支出先・資金の流れ\n"
        . "## KPI・成果\n- KPI一覧\n- 成果の有無\n- KPI妥当性\n\n"

        // 3. 観点別採点
        . "# 3. 観点別採点\n"
        . "## 観点別採点表\n"
        . "次の表を必ず埋めてください（各観点20点満点・配点合計100点）。\n"
        . "| 観点 | 配点 | 採点 | 判断根拠（1〜2行） |\n"
        . "| --- | --- | --- | --- |\n"
        . "| 必要性 | 20 |  |  |\n"
        . "| 有効性 | 20 |  |  |\n"
        . "| 効率性 | 20 |  |  |\n"
        . "| 執行適正 | 20 |  |  |\n"
        . "| 透明性・KPI妥当性 | 20 |  |  |\n"
        . "## 総合スコア\n"
        . "- 合計: ◯◯ / 100 点\n"
        . "- 総合判定: 現状通り / 執行改善 / 事業内容の一部改善 / 抜本的見直し / 縮減 / 廃止検討\n\n"

        // 4. 改善提案（長文）
        . "# 4. 改善提案（詳細）\n"
        . "実行可能な改善提案を、厚く具体的に記述してください。各提案には必ず「狙う効果」と「想定される障害」を添えること。\n"
        . "## 短期（次年度予算・運用で対応可能）\n- 提案 / 狙う効果 / 想定される障害 を3件以上\n"
        . "## 中期（1〜3年）\n- 提案 / 狙う効果 / 想定される障害 を3件以上\n"
        . "## 構造見直し（制度・実施体制）\n- 提案 / 狙う効果 / 想定される障害 を2件以上\n"
        . "## 優先度の高い打ち手 TOP3\n1.\n2.\n3.\n\n"

        // 5. 追加確認事項・根拠箇所
        . "# 5. 追加確認事項・根拠箇所\n"
        . "## 追加確認事項\n- 3〜5点\n"
        . "## 根拠箇所\n- ファイル名やページ番号が分かる場合は書く\n";

    $commonRules = "\n共通ルール:\n"
        . "- 根拠がある内容だけを書き、推測で数値を補完しない\n"
        . "- 不明点は必ず「資料上確認できない」と書く\n"
        . "- 数値は元資料の表記を尊重する\n"
        . "- 5部すべてを省略せず出力する\n";

    return $header . $body . $commonRules;
}
