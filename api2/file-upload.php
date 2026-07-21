<?php
/**
 * ファイルアップロード (api2用)
 * セキュリティ: 拡張子ホワイトリスト + finfo実MIME判定 + マジックバイト検査 + ランダムリネーム
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// ============================================
// アップロード検証（ホワイトリスト方式・PHP 7互換）
// ============================================

// 許可する拡張子と、その拡張子で正当なMIMEタイプの対応表
function get_allowed_types() {
    return array(
        'pdf'  => array('application/pdf'),
        'docx' => array('application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'),
        'pptx' => array('application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'),
        'xlsx' => array('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'),
        'csv'  => array('text/csv', 'text/plain', 'application/csv'),
        'txt'  => array('text/plain'),
        'png'  => array('image/png'),
        'jpg'  => array('image/jpeg'),
        'jpeg' => array('image/jpeg'),
        'gif'  => array('image/gif'),
        'webp' => array('image/webp'),
        'bmp'  => array('image/bmp', 'image/x-ms-bmp'),
        'heic' => array('image/heic', 'image/heif'),
        'heif' => array('image/heif', 'image/heic'),
    );
}

// 検証本体: 成功なら array('ok'=>true, 'ext'=>...)、失敗なら array('ok'=>false, 'error'=>...)
function validate_upload($file, $max_bytes) {
    $allowed = get_allowed_types();

    // アップロード自体の正当性
    if (!isset($file) || !is_array($file)) {
        return array('ok' => false, 'error' => 'ファイルが送信されていません');
    }
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return array('ok' => false, 'error' => 'アップロードエラー: ' . (isset($file['error']) ? $file['error'] : '?'));
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return array('ok' => false, 'error' => '不正なアップロードです');
    }

    // サイズ上限
    if ($file['size'] <= 0 || $file['size'] > $max_bytes) {
        return array('ok' => false, 'error' => 'ファイルサイズが不正です（上限 ' . round($max_bytes / 1048576) . 'MB）');
    }

    // 拡張子ホワイトリスト（NULLバイト・制御文字対策込み）
    $name = $file['name'];
    if (strpos($name, "\0") !== false || preg_match('/[\x00-\x1f]/', $name)) {
        return array('ok' => false, 'error' => 'ファイル名が不正です');
    }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === '' || !isset($allowed[$ext])) {
        return array('ok' => false, 'error' => 'このファイル形式は許可されていません');
    }
    // 二重拡張子対策: ファイル名のどこかに実行系拡張子が含まれていたら拒否
    $dangerous = array('php','php3','php4','php5','php7','phtml','phar','exe','dll','bat','cmd','com','scr','msi','vbs','js','jse','wsf','ps1','sh','cgi','pl','py','jsp','asp','aspx','htaccess');
    $parts = explode('.', strtolower($name));
    array_shift($parts); // 先頭（ベース名）は除外
    foreach ($parts as $p) {
        if (in_array($p, $dangerous, true)) {
            return array('ok' => false, 'error' => '危険な拡張子を含むファイル名は許可されていません');
        }
    }

    // finfo による実MIME判定（$_FILES['type'] は使わない）
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $real_mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if ($real_mime === false || !in_array($real_mime, $allowed[$ext], true)) {
        return array('ok' => false, 'error' => 'ファイルの内容が拡張子と一致しません');
    }

    // マジックバイト検査（実行ファイルの明示拒否）
    $fp = fopen($file['tmp_name'], 'rb');
    $head = fread($fp, 8);
    fclose($fp);
    if (strncmp($head, "MZ", 2) === 0 ||
        strncmp($head, "\x7fELF", 4) === 0 ||
        strncmp($head, "#!", 2) === 0) {
        return array('ok' => false, 'error' => '実行ファイルはアップロードできません');
    }
    // テキスト系拡張子の場合はPHPコード混入も拒否
    if (in_array($ext, array('txt', 'csv'), true)) {
        $content_head = file_get_contents($file['tmp_name'], false, null, 0, 4096);
        if (stripos($content_head, '<?php') !== false || stripos($content_head, '<?=') !== false) {
            return array('ok' => false, 'error' => 'スクリプトを含むファイルはアップロードできません');
        }
    }

    return array('ok' => true, 'ext' => $ext, 'mime' => $real_mime);
}

// アップロードサイズ制限 (20MB)
$maxSize = 20 * 1024 * 1024;

if (empty($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['error' => 'ファイルが選択されていません']);
    exit;
}

$file = $_FILES['file'];

$check = validate_upload($file, $maxSize);
if (!$check['ok']) {
    http_response_code(400);
    echo json_encode(['error' => $check['error']], JSON_UNESCAPED_UNICODE);
    exit;
}

// ランダムリネーム（拡張子は検証済みの値のみ使用）
$safeName = bin2hex(random_bytes(16)) . '.' . $check['ext'];
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
