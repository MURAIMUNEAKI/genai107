<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// ============================================
// ブルートフォース対策: IPごとの試行回数制限
// 10回失敗 → 10分ロック（成功でリセット）
// ============================================
$RATE_MAX_FAILS = 10;        // 許容失敗回数
$RATE_LOCK_SEC  = 600;       // ロック時間（秒）
$rateFile = __DIR__ . '/auth-attempts.json';
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$now = time();

function loadAttempts($rateFile, $now, $lockSec) {
    $data = is_file($rateFile) ? (json_decode(@file_get_contents($rateFile), true) ?: []) : [];
    // 古いエントリを掃除（ロック時間の2倍を超えたものは削除）
    foreach ($data as $ip => $rec) {
        if (($now - ($rec['last'] ?? 0)) > $lockSec * 2) unset($data[$ip]);
    }
    return $data;
}
function saveAttempts($rateFile, $data) {
    @file_put_contents($rateFile, json_encode($data), LOCK_EX);
}

$attempts = loadAttempts($rateFile, $now, $RATE_LOCK_SEC);
$rec = $attempts[$clientIp] ?? ['count' => 0, 'last' => 0];

// ロック中か判定
if ($rec['count'] >= $RATE_MAX_FAILS && ($now - $rec['last']) < $RATE_LOCK_SEC) {
    http_response_code(429);
    $wait = ceil(($RATE_LOCK_SEC - ($now - $rec['last'])) / 60);
    echo json_encode(['success' => false, 'error' => "試行回数が上限に達しました。約{$wait}分後に再度お試しください"], JSON_UNESCAPED_UNICODE);
    exit;
}
// ロック時間経過後はカウンタをリセット
if ($rec['count'] >= $RATE_MAX_FAILS) {
    $rec = ['count' => 0, 'last' => 0];
}

// .env 読み込み
foreach (file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
    $ln = trim($ln); if ($ln === '' || $ln[0] === '#' || strpos($ln, '=') === false) continue;
    putenv($ln);
}

$input = json_decode(file_get_contents('php://input'), true);
$password = $input['password'] ?? '';
$passwords = explode(',', getenv('GENNAI_PASSWORD'));

// タイミング攻撃対策: hash_equals による比較
$matched = false;
foreach ($passwords as $pw) {
    if (hash_equals($pw, $password)) { $matched = true; }
}

if ($matched) {
    // 成功: カウンタをリセット
    unset($attempts[$clientIp]);
    saveAttempts($rateFile, $attempts);
    echo json_encode(['success' => true]);
} else {
    // 失敗: カウンタを加算
    $rec['count'] += 1;
    $rec['last'] = $now;
    $attempts[$clientIp] = $rec;
    saveAttempts($rateFile, $attempts);
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'パスワードが正しくありません']);
}
