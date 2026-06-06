<?php
// app9.php - テキストからパワポ Geminiプロキシ（同期JSON・マルチターン対応）
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

// スライド構成用システムプロンプト（parseSlides()が解釈できるテキスト形式で出力させる）
$systemPrompt = <<<'SYSPROMPT'
あなたはプレゼンテーション構成の専門家です。入力テキストを分析し、最適なスライド構成に変換してください。

【18種類のスライドタイプ】
1. title - 表紙。タイトル・サブタイトル・日付
2. agenda - 目次。セクションタイトルをリスト表示
3. content - 1列の箇条書きコンテンツ
4. content2 - 2列の箇条書きコンテンツ（左右に分けて表示）
5. compare - 2つの項目を左右で比較
6. process - 手順・ステップを番号付きで表示
7. timeline - 時系列の出来事を表示
8. diagram - 中心から放射状に要素を配置
9. cards - カード形式で3〜4項目を横並び表示
10. headerCards - 上部に大きな見出し、下にカード群
11. table - 表形式でデータを整理
12. progress - 進捗バー付きの項目表示（入力テキストに具体的な数値・割合が複数ある場合のみ使用。数値がない場合は絶対に使わない）
13. quote - 引用文を大きく表示
14. kpi - 数値指標を大きく表示（3〜4個）
15. bulletCards - 各項目にアイコン番号付きカード
16. faq - Q&A形式で表示
17. statsCompare - 2つの数値を左右で比較
18. closing - 結びのスライド

【出力フォーマット】
スライドは「---」で区切り、各スライドはkey: value形式で記述する。余計な解説や前置きは絶対に入れず、フォーマットのみ出力する。
例:
---
type: title
title: メインタイトル
subtitle: サブタイトル
---
type: agenda
title: 目次
- セクション1
- セクション2
---
type: content
title: セクションタイトル
- 項目1
- 項目2
- 項目3
---

各タイプの記述方法:
- content/agenda: 「- 項目」で箇条書き
- content2/compare: left_title: + 左の「- 項目」、right_title: + 右の「- 項目」
- process: 「step: ステップ説明」
- timeline: 「event: 日付|説明」
- diagram: 「center: 中心テーマ」+「- 要素」
- cards/headerCards: 「card: 見出し|説明文」（headerCardsは追加で「header: 大見出し」）
- table: 「columns: 列1|列2|列3」+「row: 値1|値2|値3」
- progress: 「progress: 項目名|数値(0-100)」
- quote: 「quote: 引用文」+「author: 発言者」
- kpi: 「metric: 指標名|数値|単位」
- bulletCards: 「bcard: 見出し|説明文」
- faq: 「q: 質問」+「a: 回答」
- statsCompare: left_label/left_value/right_label/right_value/description
- closing: title + subtitle

【重要ルール】
1. 必ず最初は type: title で始めること
2. title の次に type: agenda を入れること
3. 最後は type: closing で終えること
4. スライド枚数の絶対ルール：
   - 入力が短い（200文字未満）→ 5〜8スライド
   - 入力が中程度（200〜500文字）→ 10〜14スライド
   - 入力が長い（500文字以上）→ 15〜18スライド
5. 入力テキストの内容を忠実に反映し、勝手に情報を追加しない
6. 各項目は具体的に書く。タイトルは短く、本文は詳しく丁寧に
7. 余計な解説や前置きは絶対に入れず、フォーマットのみ出力する
8. スライド区切りの「---」は必ず独立した行にすること
9. illustタイプは使用禁止。絶対に type: illust を出力しないこと
10. sectionタイプは使用禁止。絶対に type: section を出力しないこと
11. 同じタイプの連続禁止：同じスライドタイプを2枚連続で使わないこと
12. できるだけ多くの種類を使い分けること

【★空スライド禁止ルール】
- 各スライドは「type: と title:」だけで終わらせてはならない。必ず本文フィールドを含めること
- 1スライドに少なくとも箇条書き3項目以上、または同等の本文を入れること
SYSPROMPT;

if (!$isRetry) {
    $message = trim($input['message'] ?? '');
    if ($message === '') { http_response_code(400); echo json_encode(['ok' => false, 'error' => '入力が空です']); exit; }
    $message = strip_tags(mb_substr($message, 0, 10000));
    $uid = generateUid();

    // 文字数に応じたスライド枚数ヒント
    $charCount = mb_strlen($message, 'UTF-8');
    if ($charCount >= 500) {
        $slideHint = "入力は{$charCount}文字です。500文字以上なので、必ず15〜18スライドで構成してください。\n\n";
    } elseif ($charCount >= 200) {
        $slideHint = "入力は{$charCount}文字です。200文字以上なので、必ず10〜14スライドで構成してください。\n\n";
    } else {
        $slideHint = "";
    }
    $userMessage = $slideHint . "以下のテキストをプレゼンテーション用のスライド構成に変換してください。\n\n" . $message;

    $contents = [['role' => 'user', 'parts' => [['text' => $userMessage]]]];
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
