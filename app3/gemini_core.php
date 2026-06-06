<?php
// gemini_core.php - 教員専用アプリ共通 Gemini API ヘルパー

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

function getSystemPrompt($id) {
    $csvPath = __DIR__ . '/../prompt.csv';
    if (!file_exists($csvPath)) return '';
    $handle = fopen($csvPath, 'r');
    if (!$handle) return '';
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($handle);
    fgetcsv($handle, 0, ',', '"', '\\');
    while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        if (count($data) >= 4 && (int)$data[0] === $id) { fclose($handle); return $data[3]; }
    }
    fclose($handle);
    return '';
}

function generateUid() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));
}

function sessionDir() {
    $dir = sys_get_temp_dir() . '/app3_sessions/';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    return $dir;
}

function loadSession($uid) {
    $file = sessionDir() . preg_replace('/[^a-f0-9\-]/', '', $uid) . '.json';
    if (!file_exists($file)) return [];
    return json_decode(file_get_contents($file), true) ?: [];
}

function saveSession($uid, $contents) {
    $file = sessionDir() . preg_replace('/[^a-f0-9\-]/', '', $uid) . '.json';
    @file_put_contents($file, json_encode($contents, JSON_UNESCAPED_UNICODE));
}

function callGemini($apiKey, $model, $systemPrompt, $contents) {
    $body = [
        'contents' => $contents,
        'generationConfig' => ['temperature' => 0.7, 'maxOutputTokens' => 8192]
    ];
    if ($systemPrompt !== '') {
        $body['system_instruction'] = ['parts' => [['text' => $systemPrompt]]];
    }
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $apiKey
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120
    ]);
    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErr) return ['ok' => false, 'error' => '通信エラー: ' . $curlErr];

    $data = json_decode($response, true);
    if ($httpCode !== 200) {
        $msg = $data['error']['message'] ?? "API エラー (HTTP {$httpCode})";
        return ['ok' => false, 'error' => $msg];
    }
    $answer = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if (!$answer) return ['ok' => false, 'error' => '応答を取得できませんでした。'];
    return ['ok' => true, 'answer' => $answer];
}

function extractOfficeText($mimeType, $base64Data) {
    $mime = strtolower($mimeType);
    if (strpos($mime, 'image/') === 0) return null;
    if (strpos($mime, 'text/') === 0 || $mime === 'application/octet-stream') {
        $decoded = base64_decode($base64Data);
        if ($decoded === false || $decoded === '') return null;
        if (mb_check_encoding($decoded, 'UTF-8')) { $text = $decoded; }
        else { $text = @mb_convert_encoding($decoded, 'UTF-8', 'SJIS,EUC-JP,ASCII'); if ($text === false) $text = ''; }
        $text = preg_replace('/\xEF\xBB\xBF/', '', $text);
        $text = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text));
        return ($text !== '' && mb_check_encoding($text, 'UTF-8')) ? $text : null;
    }
    $data = base64_decode($base64Data);
    if (!$data) return null;
    $tmp = tempnam(sys_get_temp_dir(), 'up_');
    file_put_contents($tmp, $data);
    $text = '';
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($tmp) === true) {
            if (strpos($mime, 'wordprocessingml') !== false) {
                $xml = $zip->getFromName('word/document.xml');
                if ($xml) $text = strip_tags(preg_replace('/<\/w:p>/i', "\n", $xml));
            } elseif (strpos($mime, 'presentationml') !== false) {
                for ($i = 1; $i <= 100; $i++) {
                    $xml = $zip->getFromName("ppt/slides/slide{$i}.xml");
                    if (!$xml) break;
                    $text .= strip_tags(preg_replace('/<\/a:p>/i', "\n", $xml)) . "\n";
                }
            } elseif (strpos($mime, 'spreadsheetml') !== false) {
                $ss = $zip->getFromName('xl/sharedStrings.xml');
                if ($ss) { preg_match_all('/<t[^>]*>([^<]*)<\/t>/s', $ss, $m); $text = implode("\t", $m[1] ?? []); }
            }
            $zip->close();
        }
    }
    @unlink($tmp);
    $text = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text));
    if ($text !== '') return $text;
    if (mb_check_encoding($data, 'UTF-8')) { $raw = $data; }
    else { $raw = @mb_convert_encoding($data, 'UTF-8', 'SJIS,EUC-JP,ASCII'); if ($raw === false) return null; }
    $raw = preg_replace('/\xEF\xBB\xBF/', '', $raw);
    $raw = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $raw));
    return ($raw !== '' && mb_check_encoding($raw, 'UTF-8')) ? $raw : null;
}
