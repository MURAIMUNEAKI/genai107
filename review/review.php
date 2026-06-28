<?php
declare(strict_types=1);

header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
@set_time_limit(0);

while (ob_get_level() > 0) {
    ob_end_clean();
}

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/prompts.php';

sendSse('ready', ['message' => 'stream-open']);

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('POST で送信してください。');
    }

    if (!isset($_FILES['files'])) {
        throw new RuntimeException('ファイルが送信されていません。');
    }

    $env = loadEnvFile(__DIR__ . '/.env');
    $apiKey = trim((string)($env['OPENAI_API_KEY'] ?? ''));
    $model = trim((string)($env['OPENAI_MODEL'] ?? 'gpt-5.4-nano'));

    if ($apiKey === '') {
        throw new RuntimeException('.env に OPENAI_API_KEY を設定してください。');
    }

    $projectName = sanitizeUtf8(trim((string)($_POST['project_name'] ?? '')));
    $fiscalYear = sanitizeUtf8(trim((string)($_POST['fiscal_year'] ?? '')));
    $department = sanitizeUtf8(trim((string)($_POST['department'] ?? '')));
    $mode = sanitizeUtf8(trim((string)($_POST['mode'] ?? 'sheet')));

    $clientExtractedMap = [];
    $clientExtractedJson = (string)($_POST['client_extracted_json'] ?? '');
    if ($clientExtractedJson !== '') {
        $decoded = json_decode($clientExtractedJson, true);
        if (is_array($decoded)) {
            foreach ($decoded as $filename => $text) {
                if (is_string($filename) && is_string($text)) {
                    $clientExtractedMap[$filename] = sanitizeUtf8($text);
                }
            }
        }
    }

    $uploadedFiles = buildUploadedFilesArray($_FILES['files']);
    if (!$uploadedFiles) {
        throw new RuntimeException('ファイル一覧を解釈できませんでした。');
    }

    sendSse('stage', ['message' => 'ファイルからテキストを抽出しています...']);

    $extractedFiles = [];
    foreach ($uploadedFiles as $file) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('アップロードに失敗しました: ' . $file['name']);
        }
        if ($file['size'] > 20 * 1024 * 1024) {
            throw new RuntimeException('20MBを超えるファイルは扱えません: ' . $file['name']);
        }

        $extracted = extractTextForFile($file, $clientExtractedMap);
        $extractedFiles[] = $extracted;

        sendSse('stage', [
            'message' => $file['name'] . ' から ' . $extracted['char_count'] . ' 文字を抽出しました。',
        ]);
    }

    $sourceText = buildSourceBundle($extractedFiles);
    if ($sourceText === '') {
        throw new RuntimeException('AIに渡す抽出テキストが空です。');
    }

    $fileSummaries = array_map(
        static fn(array $item): array => [
            'name' => $item['name'],
            'type' => $item['ext'],
            'char_count' => $item['char_count'],
            'notes' => $item['notes'],
        ],
        $extractedFiles
    );

    sendSse('stage', ['message' => 'AIで行政事業レビューを生成しています...']);

    $systemPrompt = buildReviewSystemPrompt();
    $userPrompt = buildReviewUserPrompt(
        $projectName !== '' ? $projectName : '未入力',
        $fiscalYear !== '' ? $fiscalYear : '未入力',
        $department !== '' ? $department : '未入力',
        reviewModeLabel($mode),
        $fileSummaries,
        $sourceText
    );

    $ai = callOpenAiResponses($apiKey, $model, $systemPrompt, $userPrompt);

    foreach (chunkText($ai['text']) as $chunk) {
        sendSse('chunk', ['text' => $chunk . "\n"]);
    }

    sendSse('json', [
        'data' => [
            'project_name' => $projectName,
            'fiscal_year' => $fiscalYear,
            'department' => $department,
            'mode' => $mode,
            'model' => $model,
            'files' => $fileSummaries,
            'ai_text' => $ai['text'],
        ],
    ]);

    sendSse('done', ['message' => 'ok', 'model' => $model]);
} catch (Throwable $e) {
    sendSse('error', ['message' => $e->getMessage()]);
}
