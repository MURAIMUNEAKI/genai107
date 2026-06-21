<?php
// count.php - アクセスカウント（sendBeacon受信）
// gennai-log.csv の該当行を +1 インクリメント

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(204);
    exit;
}

$app = trim($_POST['app'] ?? '');
$whitelist = ['main','chat','bunsho','honyaku','gazou','daia','moji','lawsy','rag',
              'govbot','document','email','answer','kaigo','proofread','kodoku','seisakuhyouka','subsidy','tosho',
              'careplan','shienkeikaku','uneishidou','kokuhogenmen'];

if (!in_array($app, $whitelist, true)) {
    http_response_code(204);
    exit;
}

$csvFile = __DIR__ . '/gennai-log.csv';

$fp = fopen($csvFile, 'c+');
if (!$fp) {
    http_response_code(204);
    exit;
}

if (flock($fp, LOCK_EX)) {
    // 読み込み（BOM除去 + 連想配列で重複防止）
    $content = stream_get_contents($fp);
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

    $counts = [];
    foreach (explode("\n", $content) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $parts = explode(',', $line, 2);
        $key = trim($parts[0]);
        $val = intval($parts[1] ?? 0);
        // 重複行があれば合算
        $counts[$key] = ($counts[$key] ?? 0) + $val;
    }

    // インクリメント
    $counts[$app] = ($counts[$app] ?? 0) + 1;

    // 書き戻し（BOMなし）
    ftruncate($fp, 0);
    rewind($fp);
    foreach ($counts as $key => $val) {
        fwrite($fp, $key . ',' . $val . "\n");
    }

    flock($fp, LOCK_UN);
}
fclose($fp);

http_response_code(204);
