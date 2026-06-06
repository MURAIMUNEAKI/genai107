<?php
// app39.php - 成績一括分析レポート Geminiプロキシ（同期JSON・ファイルテキスト抽出対応）
require_once __DIR__ . '/gemini_core.php';
header('Content-Type: application/json; charset=UTF-8');

$env = loadEnv(__DIR__ . '/../api/.env');
$apiKey = $env['GEMINI_API_KEY'] ?? '';
$model  = $env['GEMINI_MODEL']   ?? 'gemini-3.1-flash-lite';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'POST required']); exit; }
if (!$apiKey) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'APIキーが設定されていません']); exit; }

$input = json_decode(file_get_contents('php://input'), true);
$uid = trim($input['uid'] ?? '');
$isRetry = !empty($uid);

$systemPrompt = getSystemPrompt(39);
if ($systemPrompt === '') $systemPrompt = '成績データを分析し、学級全体の傾向と個別のフィードバックを含むレポートを作成してください。';

if (!$isRetry) {
    $message = trim($input['message'] ?? '');
    $files = $input['files'] ?? [];

    $fileText = '';
    foreach ($files as $file) {
        if (!empty($file['pdfText'])) {
            $pdfText = mb_convert_encoding($file['pdfText'], 'UTF-8', 'UTF-8');
            $pdfText = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $pdfText);
            if (trim($pdfText) !== '') { $fileText .= "\n[添付ファイル内容]\n" . mb_substr($pdfText, 0, 30000); continue; }
        }
        if (isset($file['data']) && isset($file['mimeType'])) {
            $extracted = extractOfficeText($file['mimeType'], $file['data']);
            if ($extracted !== null) $fileText .= "\n[添付ファイル内容]\n" . mb_substr($extracted, 0, 30000);
        }
    }

    if ($message === '' && $fileText === '') { http_response_code(400); echo json_encode(['ok' => false, 'error' => '入力が空です']); exit; }
    $message = strip_tags(mb_substr($message, 0, 10000)) . $fileText;
    $uid = generateUid();
    $contents = [['role' => 'user', 'parts' => [['text' => $message]]]];
} else {
    $utterance = trim($input['utterance'] ?? '');
    if ($utterance === '') { http_response_code(400); echo json_encode(['ok' => false, 'error' => '指示が空です']); exit; }
    $utterance = strip_tags(mb_substr($utterance, 0, 5000));
    $contents = loadSession($uid);
    $contents[] = ['role' => 'user', 'parts' => [['text' => $utterance]]];
}

$result = callGemini($apiKey, $model, $systemPrompt, $contents);
if ($result['ok']) {
    $contents[] = ['role' => 'model', 'parts' => [['text' => $result['answer']]]];
    saveSession($uid, $contents);
    echo json_encode(['ok' => true, 'answer' => $result['answer'], 'uid' => $uid], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
}
