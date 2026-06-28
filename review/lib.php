<?php
declare(strict_types=1);

function sendSse(string $event, array $data): void
{
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    flush();
}

function loadEnvFile(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }

    $env = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || substr($line, 0, 1) === '#') {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $value = trim($parts[1]);
        $value = trim($value, "\"'");
        $env[$key] = $value;
    }

    return $env;
}

function sanitizeUtf8(string $text): string
{
    $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8, SJIS-win, CP932, EUC-JP, ISO-2022-JP, auto');
    $text = iconv('UTF-8', 'UTF-8//IGNORE', $text) ?: $text;
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? $text;
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace("/[ \t]+\n/u", "\n", $text) ?? $text;
    $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;
    return trim($text);
}

function detectExtension(string $filename): string
{
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

function normalizeDisplayName(string $name): string
{
    return preg_replace('/[\\\\\\/:*?"<>|]+/', '_', $name) ?: 'review-output';
}

function buildUploadedFilesArray(array $files): array
{
    $result = [];
    if (!isset($files['name']) || !is_array($files['name'])) {
        return $result;
    }

    foreach ($files['name'] as $index => $name) {
        $result[] = [
            'name' => (string)$name,
            'type' => (string)($files['type'][$index] ?? ''),
            'tmp_name' => (string)($files['tmp_name'][$index] ?? ''),
            'error' => (int)($files['error'][$index] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int)($files['size'][$index] ?? 0),
        ];
    }

    return $result;
}

function extractTextForFile(array $file, array $clientExtractedMap): array
{
    $name = $file['name'];
    $tmpPath = $file['tmp_name'];
    $ext = detectExtension($name);
    $text = '';
    $notes = [];

    if (!is_uploaded_file($tmpPath) && !is_file($tmpPath)) {
        throw new RuntimeException("アップロード一時ファイルを確認できません: {$name}");
    }

    switch ($ext) {
        case 'pdf':
            $clientText = trim((string)($clientExtractedMap[$name] ?? ''));
            if ($clientText !== '') {
                $text = $clientText;
                $notes[] = 'client_pdf_text';
            } else {
                $text = extractPdfText($tmpPath, $notes);
            }
            break;

        case 'docx':
            $text = extractDocxText($tmpPath);
            $notes[] = 'docx_ziparchive';
            break;

        case 'csv':
            $text = extractCsvText($tmpPath);
            $notes[] = 'csv_text';
            break;

        case 'txt':
            $text = extractPlainText($tmpPath);
            $notes[] = 'plain_text';
            break;

        default:
            throw new RuntimeException("未対応のファイル形式です: {$name}");
    }

    $text = sanitizeUtf8($text);
    if ($text === '') {
        throw new RuntimeException("テキストを抽出できませんでした: {$name}");
    }

    return [
        'name' => $name,
        'ext' => $ext,
        'text' => $text,
        'char_count' => mb_strlen($text),
        'notes' => $notes,
    ];
}

function extractPlainText(string $path): string
{
    $data = file_get_contents($path);
    if ($data === false) {
        return '';
    }

    return sanitizeUtf8($data);
}

function extractCsvText(string $path): string
{
    $raw = file_get_contents($path);
    if ($raw === false) {
        return '';
    }

    $encoding = mb_detect_encoding($raw, ['UTF-8', 'SJIS-win', 'CP932', 'EUC-JP', 'ISO-2022-JP'], true) ?: 'UTF-8';
    $text = mb_convert_encoding($raw, 'UTF-8', $encoding);
    $lines = preg_split("/\r\n|\n|\r/", $text) ?: [];
    if (!$lines) {
        return '';
    }

    $delimiter = detectCsvDelimiter($lines);
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return sanitizeUtf8($text);
    }

    $rows = [];
    $header = null;
    $rowIndex = 0;

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        $rowIndex++;
        $row = array_map(
            static fn($value) => sanitizeUtf8(mb_convert_encoding((string)$value, 'UTF-8', $encoding)),
            $row
        );

        if ($rowIndex === 1) {
            $header = $row;
            $rows[] = 'ヘッダ: ' . implode(' | ', $header);
            continue;
        }

        if ($header !== null && count($header) === count($row)) {
            $pairs = [];
            foreach ($row as $idx => $value) {
                $column = trim((string)$header[$idx]);
                $pairs[] = ($column !== '' ? $column : '列' . ($idx + 1)) . ': ' . $value;
            }
            $rows[] = '行' . ($rowIndex - 1) . ': ' . implode(' / ', $pairs);
        } else {
            $rows[] = '行' . $rowIndex . ': ' . implode(' | ', $row);
        }

        if ($rowIndex >= 2001) {
            $rows[] = '... 行数が多いため途中で省略 ...';
            break;
        }
    }

    fclose($handle);
    return implode("\n", $rows);
}

function detectCsvDelimiter(array $lines): string
{
    $sample = implode("\n", array_slice($lines, 0, 5));
    $candidates = [',' => substr_count($sample, ','), "\t" => substr_count($sample, "\t"), ';' => substr_count($sample, ';')];
    arsort($candidates);
    $delimiter = array_key_first($candidates);
    return is_string($delimiter) ? $delimiter : ',';
}

function extractDocxText(string $path): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive が使えないため DOCX を処理できません。');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('DOCX を開けませんでした。');
    }

    $xml = $zip->getFromName('word/document.xml');
    $zip->close();

    if ($xml === false || $xml === '') {
        return '';
    }

    $xml = preg_replace('/<\/w:p>/i', "\n", $xml) ?? $xml;
    $xml = preg_replace('/<\/w:tr>/i', "\n", $xml) ?? $xml;
    return strip_tags($xml);
}

function extractPdfText(string $path, array &$notes): string
{
    $pdftotextOutput = extractPdfTextWithPdftotext($path);
    if ($pdftotextOutput !== '') {
        $notes[] = 'pdftotext';
        return $pdftotextOutput;
    }

    $phpOutput = extractPdfTextWithPhpFallback($path);
    if ($phpOutput !== '') {
        $notes[] = 'php_fallback';
        return $phpOutput;
    }

    return '';
}

function extractPdfTextWithPdftotext(string $path): string
{
    $commands = ['pdftotext', '/usr/bin/pdftotext', '/usr/local/bin/pdftotext'];
    $cmd = null;

    foreach ($commands as $candidate) {
        $out = [];
        $code = 0;
        @exec($candidate . ' -v 2>&1', $out, $code);
        if ($code === 0 || $code === 1) {
            $cmd = $candidate;
            break;
        }
    }

    if ($cmd === null) {
        return '';
    }

    $tmpTxt = tempnam(sys_get_temp_dir(), 'pdf_txt_');
    if ($tmpTxt === false) {
        return '';
    }

    $out = [];
    $code = 0;
    @exec($cmd . ' ' . escapeshellarg($path) . ' ' . escapeshellarg($tmpTxt) . ' 2>&1', $out, $code);
    if ($code !== 0 || !is_file($tmpTxt)) {
        @unlink($tmpTxt);
        return '';
    }

    $text = file_get_contents($tmpTxt) ?: '';
    @unlink($tmpTxt);
    return sanitizeUtf8($text);
}

function extractPdfTextWithPhpFallback(string $path): string
{
    $content = file_get_contents($path);
    if ($content === false || $content === '') {
        return '';
    }

    if (!preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $content, $matches)) {
        return '';
    }

    $text = '';
    foreach ($matches[1] as $stream) {
        $decoded = @gzuncompress($stream);
        if ($decoded === false) {
            $decoded = @gzinflate($stream);
        }
        if ($decoded === false) {
            $decoded = $stream;
        }

        if (!preg_match_all('/BT\s*(.*?)\s*ET/s', $decoded, $blocks)) {
            continue;
        }

        foreach ($blocks[1] as $block) {
            if (preg_match_all('/\(([^)]*)\)\s*Tj/s', $block, $tjMatches)) {
                foreach ($tjMatches[1] as $item) {
                    $text .= decodePdfLiteralString($item);
                }
            }

            if (preg_match_all('/\[(.*?)\]\s*TJ/s', $block, $tjArrayMatches)) {
                foreach ($tjArrayMatches[1] as $arrayBlock) {
                    if (preg_match_all('/\(([^)]*)\)/s', $arrayBlock, $items)) {
                        foreach ($items[1] as $item) {
                            $text .= decodePdfLiteralString($item);
                        }
                    }
                }
            }
        }
        $text .= "\n";
    }

    return sanitizeUtf8($text);
}

function decodePdfLiteralString(string $input): string
{
    $input = str_replace(['\\n', '\\r', '\\t', '\\(', '\\)', '\\\\'], ["\n", "\r", "\t", '(', ')', '\\'], $input);
    $input = preg_replace_callback(
        '/\\\\([0-7]{1,3})/',
        static fn(array $matches): string => chr(octdec($matches[1])),
        $input
    ) ?? $input;

    return $input;
}

function trimSourceText(string $text, int $maxChars): string
{
    $length = mb_strlen($text);
    if ($length <= $maxChars) {
        return $text;
    }

    return mb_substr($text, 0, $maxChars) . "\n\n... 文字数上限のため後半を省略 ...";
}

function buildSourceBundle(array $extractedFiles, int $perFileLimit = 30000, int $totalLimit = 120000): string
{
    $parts = [];
    $total = 0;

    foreach ($extractedFiles as $file) {
        $body = trimSourceText($file['text'], $perFileLimit);
        $section = "[ファイル開始]\n"
            . 'ファイル名: ' . $file['name'] . "\n"
            . '種別: ' . strtoupper($file['ext']) . "\n"
            . '抽出文字数: ' . $file['char_count'] . "\n\n"
            . $body
            . "\n[ファイル終了]\n";

        $remaining = $totalLimit - $total;
        if ($remaining <= 0) {
            $parts[] = "\n... 全体文字数上限のため残りファイルは省略 ...";
            break;
        }

        if (mb_strlen($section) > $remaining) {
            $parts[] = mb_substr($section, 0, $remaining) . "\n... 全体文字数上限のため途中で省略 ...";
            break;
        }

        $parts[] = $section;
        $total += mb_strlen($section);
    }

    return implode("\n", $parts);
}

function callOpenAiResponses(string $apiKey, string $model, string $systemPrompt, string $userPrompt): array
{
    $url = 'https://api.openai.com/v1/responses';
    $payload = [
        'model' => $model,
        'input' => [
            [
                'role' => 'system',
                'content' => [
                    ['type' => 'input_text', 'text' => $systemPrompt],
                ],
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => $userPrompt],
                ],
            ],
        ],
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('OpenAI 送信用JSONの生成に失敗しました。');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
    ]);

    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('OpenAI API 通信エラー: ' . $error);
    }

    $decoded = json_decode($raw, true);
    if ($status < 200 || $status >= 300) {
        $message = is_array($decoded) && isset($decoded['error']['message'])
            ? (string)$decoded['error']['message']
            : 'OpenAI API でエラーが発生しました。';
        throw new RuntimeException($message);
    }

    if (!is_array($decoded)) {
        throw new RuntimeException('OpenAI API 応答のJSONを解釈できませんでした。');
    }

    $text = extractOpenAiOutputText($decoded);
    if ($text === '') {
        throw new RuntimeException('OpenAI API から本文テキストを取得できませんでした。');
    }

    return [
        'text' => sanitizeUtf8($text),
        'response' => $decoded,
    ];
}

function extractOpenAiOutputText(array $response): string
{
    if (isset($response['output_text']) && is_string($response['output_text']) && $response['output_text'] !== '') {
        return $response['output_text'];
    }

    $texts = [];
    $output = $response['output'] ?? [];
    if (!is_array($output)) {
        return '';
    }

    foreach ($output as $item) {
        $content = $item['content'] ?? [];
        if (!is_array($content)) {
            continue;
        }

        foreach ($content as $contentItem) {
            if (isset($contentItem['text']) && is_string($contentItem['text'])) {
                $texts[] = $contentItem['text'];
            } elseif (isset($contentItem['type'], $contentItem['text']) && $contentItem['type'] === 'output_text' && is_string($contentItem['text'])) {
                $texts[] = $contentItem['text'];
            }
        }
    }

    return trim(implode("\n", $texts));
}

function chunkText(string $text, int $maxChunkLength = 900): array
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }

    $chunks = [];
    $remaining = $text;
    while (mb_strlen($remaining) > $maxChunkLength) {
        $slice = mb_substr($remaining, 0, $maxChunkLength);
        $breakPos = mb_strrpos($slice, "\n");
        if ($breakPos === false || $breakPos < 200) {
            $breakPos = $maxChunkLength;
        }

        $chunks[] = mb_substr($remaining, 0, $breakPos);
        $remaining = ltrim(mb_substr($remaining, $breakPos));
    }

    if ($remaining !== '') {
        $chunks[] = $remaining;
    }

    return $chunks;
}
