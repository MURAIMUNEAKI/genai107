<?php
// ragcore.php - RAG共通関数（Embedding・検索・チャンキング）

define('RAG_DB_PATH', __DIR__ . '/rag_vectors.db');
define('EMBEDDING_MODEL', 'gemini-embedding-001');
define('EMBEDDING_DIMENSIONS', 768);
define('CHUNK_TARGET_MIN_CHARS', 1200);
define('CHUNK_TARGET_MAX_CHARS', 3500);
define('CHUNK_OVERLAP_CHARS', 200);
define('CHUNK_MIN_CHARS', 800);
define('CHUNK_SPLIT_LEVEL', 2);

// ===== Gemini Embedding API =====

function embedText($text, $apiKey, $taskType = 'RETRIEVAL_DOCUMENT') {
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
         . EMBEDDING_MODEL . ':embedContent';

    $body = [
        'model' => 'models/' . EMBEDDING_MODEL,
        'content' => ['parts' => [['text' => $text]]],
        'taskType' => $taskType,
        'outputDimensionality' => EMBEDDING_DIMENSIONS,
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey],
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) { error_log("embedText curl error: $curlError"); return null; }
    if ($httpCode !== 200) { error_log("embedText HTTP $httpCode: $response"); return null; }

    $result = json_decode($response, true);
    return $result['embedding']['values'] ?? null;
}

function batchEmbedTexts($texts, $apiKey, $taskType = 'RETRIEVAL_DOCUMENT') {
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
         . EMBEDDING_MODEL . ':batchEmbedContents';

    $requests = [];
    foreach ($texts as $text) {
        $requests[] = [
            'model' => 'models/' . EMBEDDING_MODEL,
            'content' => ['parts' => [['text' => $text]]],
            'taskType' => $taskType,
            'outputDimensionality' => EMBEDDING_DIMENSIONS,
        ];
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey],
        CURLOPT_POSTFIELDS     => json_encode(['requests' => $requests]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) { error_log("batchEmbedTexts curl error: $curlError"); return null; }
    if ($httpCode !== 200) { error_log("batchEmbedTexts HTTP $httpCode: $response"); return null; }

    $result = json_decode($response, true);
    if (!isset($result['embeddings'])) return null;

    $vectors = [];
    foreach ($result['embeddings'] as $emb) {
        $vectors[] = $emb['values'] ?? null;
    }
    return $vectors;
}

// ===== SQLite Vector Storage =====

function vectorToBlob($vector) {
    return pack('f' . count($vector), ...$vector);
}

function blobToVector($blob) {
    $count = strlen($blob) / 4;
    $values = unpack('f' . $count, $blob);
    return array_values($values);
}

// ===== Cosine Similarity Search =====

function cosineSimilarity($a, $b) {
    $dot = 0.0; $normA = 0.0; $normB = 0.0;
    $len = count($a);
    for ($i = 0; $i < $len; $i++) {
        $dot   += $a[$i] * $b[$i];
        $normA += $a[$i] * $a[$i];
        $normB += $b[$i] * $b[$i];
    }
    $denom = sqrt($normA) * sqrt($normB);
    return $denom == 0 ? 0.0 : $dot / $denom;
}

function retrieveTopK($queryVector, $db, $k = 12, $minScore = 0.3) {
    $stmt = $db->query('SELECT id, source_file, section_title, heading_path, chunk_text, char_count, vector FROM chunks');
    $results = [];
    while ($row = $stmt->fetchArray(SQLITE3_ASSOC)) {
        $chunkVector = blobToVector($row['vector']);
        $score = cosineSimilarity($queryVector, $chunkVector);
        if ($score >= $minScore) {
            $results[] = [
                'id' => $row['id'], 'source_file' => $row['source_file'],
                'section_title' => $row['section_title'], 'heading_path' => $row['heading_path'],
                'chunk_text' => $row['chunk_text'], 'char_count' => $row['char_count'], 'score' => $score,
            ];
        }
    }
    usort($results, function($a, $b) { return $b['score'] <=> $a['score']; });
    return array_slice($results, 0, $k);
}

// ===== Markdown Chunking =====

function chunkMarkdown($content, $sourceFile) {
    $lines = explode("\n", $content);
    $docTitle = '';
    $l2Sections = [];
    $currentSection = null;
    $headingStack = [];

    foreach ($lines as $line) {
        if (preg_match('/^(#{1,4})\s+(.+)$/', $line, $m)) {
            $level = strlen($m[1]);
            $title = trim($m[2]);

            if ($level === 1) {
                $docTitle = $title;
                if ($currentSection === null) {
                    $headingStack = [['level' => 1, 'title' => $title]];
                    $currentSection = ['title' => $title, 'heading_path' => $title, 'content' => $line . "\n"];
                } else {
                    $currentSection['content'] .= $line . "\n";
                }
                continue;
            }

            $headingStack = array_filter($headingStack, function($h) use ($level) { return $h['level'] < $level; });
            $headingStack[] = ['level' => $level, 'title' => $title];

            if ($level === CHUNK_SPLIT_LEVEL) {
                if ($currentSection !== null) $l2Sections[] = $currentSection;
                $headingPath = implode(' > ', array_column($headingStack, 'title'));
                $currentSection = ['title' => $title, 'heading_path' => $headingPath, 'content' => $line . "\n"];
                continue;
            }
        }

        if ($currentSection === null) {
            $currentSection = ['title' => basename($sourceFile, '.md'), 'heading_path' => basename($sourceFile), 'content' => $line . "\n"];
        } else {
            $currentSection['content'] .= $line . "\n";
        }
    }
    if ($currentSection !== null) $l2Sections[] = $currentSection;

    $sections = [];
    foreach ($l2Sections as $section) {
        if (mb_strlen(trim($section['content'])) <= CHUNK_TARGET_MAX_CHARS) {
            $sections[] = $section;
        } else {
            $sections = array_merge($sections, splitBySubHeading(trim($section['content']), $section, $sourceFile));
        }
    }

    $chunks = [];
    foreach ($sections as $section) {
        $text = trim($section['content']);
        $charCount = mb_strlen($text);
        if ($charCount <= CHUNK_TARGET_MAX_CHARS) {
            $chunks[] = ['source_file' => $sourceFile, 'section_title' => $section['title'],
                         'heading_path' => $section['heading_path'], 'chunk_text' => $text, 'char_count' => $charCount];
        } else {
            $chunks = array_merge($chunks, splitByParagraph($text, $section, $sourceFile));
        }
    }

    $chunks = mergeSmallChunks($chunks);

    if ($docTitle !== '') {
        foreach ($chunks as &$chunk) {
            $prefix = "【{$docTitle}】";
            if (!empty($chunk['heading_path']) && $chunk['heading_path'] !== $docTitle) {
                $prefix .= " > " . $chunk['heading_path'];
            }
            $prefix .= "\n\n";
            if (mb_strpos($chunk['chunk_text'], '【') !== 0) {
                $chunk['chunk_text'] = $prefix . $chunk['chunk_text'];
                $chunk['char_count'] = mb_strlen($chunk['chunk_text']);
            }
        }
        unset($chunk);
    }

    foreach ($chunks as $i => &$chunk) { $chunk['chunk_position'] = $i; }
    unset($chunk);
    return $chunks;
}

function splitBySubHeading($text, $parentSection, $sourceFile) {
    $lines = explode("\n", $text);
    $subSections = [];
    $currentSub = null;
    $parentHeading = $parentSection['heading_path'];
    $introSection = null;

    foreach ($lines as $line) {
        if (preg_match('/^(#{3,4})\s+(.+)$/', $line, $m)) {
            $title = trim($m[2]);
            if ($currentSub !== null) $subSections[] = $currentSub;
            $currentSub = ['title' => $title, 'heading_path' => $parentHeading . ' > ' . $title, 'content' => $line . "\n"];
            continue;
        }
        if ($currentSub === null) {
            if ($introSection === null) {
                $introSection = ['title' => $parentSection['title'], 'heading_path' => $parentHeading, 'content' => $line . "\n"];
            } else {
                $introSection['content'] .= $line . "\n";
            }
        } else {
            $currentSub['content'] .= $line . "\n";
        }
    }
    if ($currentSub !== null) $subSections[] = $currentSub;
    if (empty($subSections)) return [$parentSection];

    $result = [];
    $parentContext = '';
    if ($introSection !== null && mb_strlen(trim($introSection['content'])) > 50) {
        $result[] = $introSection;
    } elseif ($introSection !== null) {
        $parentContext = trim($introSection['content']);
    }

    foreach ($subSections as &$sub) {
        if (!empty($parentContext) && mb_strlen($parentContext) < 300) {
            $sub['content'] = $parentContext . "\n\n" . $sub['content'];
        }
    }
    unset($sub);
    return array_merge($result, $subSections);
}

function splitByParagraph($text, $section, $sourceFile) {
    $blocks = preg_split('/(\n\n+|\n(?=###\s))/', $text, -1, PREG_SPLIT_NO_EMPTY);
    $chunks = [];
    $currentText = '';

    foreach ($blocks as $block) {
        $block = trim($block);
        if ($block === '') continue;
        $combined = $currentText === '' ? $block : $currentText . "\n\n" . $block;

        if (mb_strlen($combined) > CHUNK_TARGET_MAX_CHARS && $currentText !== '') {
            $chunks[] = ['source_file' => $sourceFile, 'section_title' => $section['title'],
                         'heading_path' => $section['heading_path'], 'chunk_text' => trim($currentText),
                         'char_count' => mb_strlen(trim($currentText))];
            $overlap = mb_substr($currentText, -CHUNK_OVERLAP_CHARS);
            $currentText = $overlap . "\n\n" . $block;
        } else {
            $currentText = $combined;
        }
    }
    if (trim($currentText) !== '') {
        $chunks[] = ['source_file' => $sourceFile, 'section_title' => $section['title'],
                     'heading_path' => $section['heading_path'], 'chunk_text' => trim($currentText),
                     'char_count' => mb_strlen(trim($currentText))];
    }
    return $chunks;
}

function mergeSmallChunks($chunks) {
    if (count($chunks) <= 1) return $chunks;
    for ($round = 0; $round < 3; $round++) {
        $merged = [];
        $i = 0;
        $changed = false;
        while ($i < count($chunks)) {
            $chunk = $chunks[$i];
            if ($chunk['char_count'] < CHUNK_MIN_CHARS) {
                if (count($merged) > 0) {
                    $li = count($merged) - 1;
                    $prev = $merged[$li];
                    if ($prev['source_file'] === $chunk['source_file']
                        && ($prev['char_count'] + $chunk['char_count']) <= CHUNK_TARGET_MAX_CHARS + 400) {
                        $merged[$li]['chunk_text'] .= "\n\n" . $chunk['chunk_text'];
                        $merged[$li]['char_count'] = mb_strlen($merged[$li]['chunk_text']);
                        if ($prev['heading_path'] !== $chunk['heading_path']) {
                            $merged[$li]['section_title'] .= ' / ' . $chunk['section_title'];
                        }
                        $changed = true; $i++; continue;
                    }
                }
                if ($i + 1 < count($chunks)) {
                    $next = $chunks[$i + 1];
                    if ($next['source_file'] === $chunk['source_file']
                        && ($next['char_count'] + $chunk['char_count']) <= CHUNK_TARGET_MAX_CHARS + 400) {
                        $chunks[$i + 1]['chunk_text'] = $chunk['chunk_text'] . "\n\n" . $next['chunk_text'];
                        $chunks[$i + 1]['char_count'] = mb_strlen($chunks[$i + 1]['chunk_text']);
                        if ($next['heading_path'] !== $chunk['heading_path']) {
                            $chunks[$i + 1]['section_title'] = $chunk['section_title'] . ' / ' . $next['section_title'];
                        }
                        $changed = true; $i++; continue;
                    }
                }
            }
            $merged[] = $chunk;
            $i++;
        }
        $chunks = $merged;
        if (!$changed) break;
    }
    return $chunks;
}

// ===== Fulltext Fallback =====

function loadAllMarkdownFiles() {
    $dir = __DIR__ . '/data';
    $content = '';
    foreach (glob($dir . '/*.md') as $file) {
        $content .= file_get_contents($file) . "\n\n---\n\n";
    }
    return $content;
}

function formatRetrievedChunks($chunks) {
    if (empty($chunks)) return '';
    $output = '';
    foreach ($chunks as $i => $chunk) {
        $num = $i + 1;
        $score = round($chunk['score'], 3);
        $output .= "### [{$num}] {$chunk['heading_path']} (関連度: {$score})\n";
        $output .= $chunk['chunk_text'] . "\n\n---\n\n";
    }
    return $output;
}

// ===== CLI Build Command =====

if (php_sapi_name() === 'cli' && isset($argv[1])) {
    $command = $argv[1];

    if ($command === 'build') {
        $force = in_array('--force', $argv);
        $dataDir = __DIR__ . '/data';

        $envFile = __DIR__ . '/../api/.env';
        if (file_exists($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') continue;
                if (strpos($line, '=') !== false) {
                    list($key, $val) = explode('=', $line, 2);
                    $_ENV[trim($key)] = trim($val);
                }
            }
        }
        $apiKey = $_ENV['GEMINI_API_KEY'] ?? '';
        if (!$apiKey) { echo "Error: GEMINI_API_KEY not set in .env\n"; exit(1); }

        if ($force && file_exists(RAG_DB_PATH)) unlink(RAG_DB_PATH);
        $db = new SQLite3(RAG_DB_PATH);
        $db->exec('CREATE TABLE IF NOT EXISTS chunks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_file TEXT NOT NULL, section_title TEXT, heading_path TEXT,
            chunk_text TEXT NOT NULL, char_count INTEGER, chunk_position INTEGER,
            vector BLOB NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_source ON chunks(source_file)');

        $mdFiles = glob($dataDir . '/*.md');
        if (empty($mdFiles)) { echo "No .md files found in {$dataDir}\n"; exit(1); }

        $totalChunks = 0;
        foreach ($mdFiles as $file) {
            $content = file_get_contents($file);
            $baseName = basename($file);
            $chunks = chunkMarkdown($content, $baseName);
            echo "{$baseName}: " . count($chunks) . " chunks\n";

            $texts = array_column($chunks, 'chunk_text');
            $allVectors = [];
            foreach (array_chunk($texts, 100) as $batch) {
                $vectors = batchEmbedTexts($batch, $apiKey);
                if ($vectors) $allVectors = array_merge($allVectors, $vectors);
                usleep(500000);
            }

            $stmt = $db->prepare('INSERT INTO chunks (source_file, section_title, heading_path, chunk_text, char_count, chunk_position, vector) VALUES (?,?,?,?,?,?,?)');
            foreach ($chunks as $i => $chunk) {
                if (!isset($allVectors[$i]) || $allVectors[$i] === null) continue;
                $stmt->bindValue(1, $chunk['source_file']);
                $stmt->bindValue(2, $chunk['section_title']);
                $stmt->bindValue(3, $chunk['heading_path']);
                $stmt->bindValue(4, $chunk['chunk_text']);
                $stmt->bindValue(5, $chunk['char_count']);
                $stmt->bindValue(6, $chunk['chunk_position']);
                $stmt->bindValue(7, vectorToBlob($allVectors[$i]), SQLITE3_BLOB);
                $stmt->execute();
                $totalChunks++;
            }
        }

        $db->close();
        $dbSize = filesize(RAG_DB_PATH);
        echo "\nDone: {$totalChunks} chunks, DB size: " . number_format($dbSize) . " bytes\n";
    }

    if ($command === 'check') {
        if (!file_exists(RAG_DB_PATH)) { echo "DB not found\n"; exit(1); }
        $db = new SQLite3(RAG_DB_PATH, SQLITE3_OPEN_READONLY);
        $stmt = $db->query('SELECT source_file, section_title, char_count FROM chunks ORDER BY source_file, id');
        while ($row = $stmt->fetchArray(SQLITE3_ASSOC)) {
            echo sprintf("%-25s %-30s %5d chars\n", $row['source_file'], mb_substr($row['section_title'], 0, 25), $row['char_count']);
        }
        $total = $db->querySingle('SELECT COUNT(*) FROM chunks');
        $totalChars = $db->querySingle('SELECT SUM(char_count) FROM chunks');
        echo "\nTotal: {$total} chunks, " . number_format($totalChars) . " chars\n";
        $db->close();
    }
}
