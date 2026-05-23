<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// .env 読み込み
foreach (file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
    $ln = trim($ln); if ($ln === '' || $ln[0] === '#' || strpos($ln, '=') === false) continue;
    putenv($ln);
}

$input = json_decode(file_get_contents('php://input'), true);
$password = $input['password'] ?? '';
$passwords = explode(',', getenv('GENNAI_PASSWORD'));

if (in_array($password, $passwords, true)) {
    echo json_encode(['success' => true]);
} else {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'パスワードが正しくありません']);
}
