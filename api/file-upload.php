<?php
/**
 * ファイルアップロード (S3 代替)
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// アップロードサイズ制限 (20MB)
$maxSize = 20 * 1024 * 1024;

if (empty($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['error' => 'ファイルが選択されていません']);
    exit;
}

$file = $_FILES['file'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'アップロードエラー: ' . $file['error']]);
    exit;
}

if ($file['size'] > $maxSize) {
    http_response_code(400);
    echo json_encode(['error' => 'ファイルサイズが20MBを超えています']);
    exit;
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$safeName = uniqid('file_', true) . '.' . $ext;
$targetPath = $uploadDir . $safeName;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'ファイル保存に失敗しました']);
    exit;
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl = $protocol . '://' . $_SERVER['HTTP_HOST'];
$scriptDir = dirname($_SERVER['SCRIPT_NAME']);
$fileUrl = $baseUrl . $scriptDir . '/uploads/' . $safeName;

echo json_encode([
    'url' => $fileUrl,
    'filename' => $safeName,
    'originalName' => $file['name'],
    'size' => $file['size'],
    'mimeType' => $file['type']
], JSON_UNESCAPED_UNICODE);
