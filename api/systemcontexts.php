<?php
/**
 * システムプロンプト CRUD API — systemcontexts.json の読み書き
 */
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$file = __DIR__ . '/systemcontexts.json';

function readCtx() {
    global $file;
    return file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
}
function writeCtx($items) {
    global $file;
    file_put_contents($file, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

$method = $_SERVER['REQUEST_METHOD'];

// GET — 一覧取得（新しい順）
if ($method === 'GET') {
    $items = readCtx();
    usort($items, function($a, $b) { return ($b['createdAt'] ?? 0) - ($a['createdAt'] ?? 0); });
    echo json_encode($items);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// POST — 新規保存
if ($method === 'POST') {
    $title = trim($input['title'] ?? '');
    $prompt = trim($input['systemContext'] ?? '');
    if (!$title || !$prompt) {
        http_response_code(400);
        echo json_encode(['error' => 'タイトルとプロンプトは必須です']);
        exit;
    }
    $items = readCtx();
    $newItem = [
        'id' => 'sc_' . uniqid(),
        'title' => $title,
        'systemContext' => $prompt,
        'createdAt' => time()
    ];
    $items[] = $newItem;
    writeCtx($items);
    echo json_encode(['ok' => true, 'item' => $newItem]);
    exit;
}

// PUT — タイトル変更
if ($method === 'PUT') {
    $id = $input['id'] ?? '';
    $title = trim($input['title'] ?? '');
    if (!$id || !$title) {
        http_response_code(400);
        echo json_encode(['error' => 'IDとタイトルは必須です']);
        exit;
    }
    $items = readCtx();
    foreach ($items as &$item) {
        if ($item['id'] === $id) {
            $item['title'] = $title;
            break;
        }
    }
    writeCtx($items);
    echo json_encode(['ok' => true]);
    exit;
}

// DELETE — 削除
if ($method === 'DELETE') {
    $id = $input['id'] ?? ($_GET['id'] ?? '');
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'IDは必須です']);
        exit;
    }
    $items = readCtx();
    $items = array_values(array_filter($items, function($i) use ($id) { return $i['id'] !== $id; }));
    writeCtx($items);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
