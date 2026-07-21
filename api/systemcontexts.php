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

// GET — 一覧取得（新しい順・自分のオーナー分のみ）
if ($method === 'GET') {
    $owner = trim($_GET['owner'] ?? '');
    if ($owner === '') {
        // オーナー未指定は他人のプロンプトを漏らさないため空を返す
        echo json_encode([]);
        exit;
    }
    $items = readCtx();
    // 自分の ownerId と一致するもののみ（owner 情報のない既存データは非表示）
    $items = array_values(array_filter($items, function($i) use ($owner) {
        return isset($i['ownerId']) && $i['ownerId'] === $owner;
    }));
    usort($items, function($a, $b) { return ($b['createdAt'] ?? 0) - ($a['createdAt'] ?? 0); });
    echo json_encode($items);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// POST — 新規保存
if ($method === 'POST') {
    $title = trim($input['title'] ?? '');
    $prompt = trim($input['systemContext'] ?? '');
    $ownerId = trim($input['ownerId'] ?? '');
    if (!$title || !$prompt) {
        http_response_code(400);
        echo json_encode(['error' => 'タイトルとプロンプトは必須です']);
        exit;
    }
    if (!$ownerId) {
        http_response_code(400);
        echo json_encode(['error' => 'オーナーIDは必須です']);
        exit;
    }
    $items = readCtx();
    $newItem = [
        'id' => 'sc_' . uniqid(),
        'title' => $title,
        'systemContext' => $prompt,
        'ownerId' => $ownerId,
        'createdAt' => time()
    ];
    $items[] = $newItem;
    writeCtx($items);
    echo json_encode(['ok' => true, 'item' => $newItem]);
    exit;
}

// PUT — タイトル変更（自分のオーナー分のみ）
if ($method === 'PUT') {
    $id = $input['id'] ?? '';
    $title = trim($input['title'] ?? '');
    $ownerId = trim($input['ownerId'] ?? '');
    if (!$id || !$title || !$ownerId) {
        http_response_code(400);
        echo json_encode(['error' => 'ID・タイトル・オーナーIDは必須です']);
        exit;
    }
    $items = readCtx();
    $found = false;
    foreach ($items as &$item) {
        if ($item['id'] === $id && ($item['ownerId'] ?? '') === $ownerId) {
            $item['title'] = $title;
            $found = true;
            break;
        }
    }
    unset($item);
    if (!$found) {
        http_response_code(403);
        echo json_encode(['error' => '対象のプロンプトが見つからないか、変更する権限がありません']);
        exit;
    }
    writeCtx($items);
    echo json_encode(['ok' => true]);
    exit;
}

// DELETE — 削除（自分のオーナー分のみ）
if ($method === 'DELETE') {
    $id = $input['id'] ?? ($_GET['id'] ?? '');
    $ownerId = trim($input['ownerId'] ?? ($_GET['owner'] ?? ''));
    if (!$id || !$ownerId) {
        http_response_code(400);
        echo json_encode(['error' => 'IDとオーナーIDは必須です']);
        exit;
    }
    $items = readCtx();
    $before = count($items);
    // id が一致し、かつ自分の ownerId のものだけ削除する
    $items = array_values(array_filter($items, function($i) use ($id, $ownerId) {
        return !($i['id'] === $id && ($i['ownerId'] ?? '') === $ownerId);
    }));
    if (count($items) === $before) {
        http_response_code(403);
        echo json_encode(['error' => '対象のプロンプトが見つからないか、削除する権限がありません']);
        exit;
    }
    writeCtx($items);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
