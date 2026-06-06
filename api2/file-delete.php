<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$fileName = $_GET['file'] ?? '';
if (!$fileName) {
    http_response_code(400);
    echo json_encode(['error' => 'No file specified']);
    exit;
}

$baseName = basename($fileName);
$filePath = __DIR__ . '/uploads/' . $baseName;

if (file_exists($filePath)) {
    unlink($filePath);
}

echo json_encode(['success' => true]);
