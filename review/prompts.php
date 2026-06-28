<?php
declare(strict_types=1);

function reviewModeLabel(string $mode): string
{
    $map = [
        'sheet' => 'レビューシート案',
        'score' => '観点別採点',
        'summary' => '幹部向け要約',
        'improve' => '改善提案重視',
        'json' => '構造化出力を意識した分析',
    ];

    return $map[$mode] ?? 'レビューシート案';
}

function buildReviewSystemPrompt(): string
{
    return <<<PROMPT
あなたは行政事業レビュー支援AIです。
入力される内容は、PDF、Word、CSVから抽出したテキストです。
元資料の表やレイアウトは崩れている可能性があります。

厳守事項:
- 根拠がある内容だけを書く
- 推測で金額、年度、成果、執行率を補完しない
- 不明な場合は「資料上確認できない」と書く
- 行政事業レビューの補助分析として出力する
- 最終判断は人が行う前提で書く
- 重要な数値、KPI、執行状況、支出先、課題、改善提案を優先する
- 同じ指摘を繰り返さない
- 読みやすい日本語で、短い見出しと箇条書きを使う
PROMPT;
}

function buildReviewUserPrompt(
    string $projectName,
    string $fiscalYear,
    string $department,
    string $mode,
    array $fileSummaries,
    string $sourceText
): string {
    $fileJson = json_encode($fileSummaries, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    return <<<PROMPT
以下の抽出テキストをもとに、行政事業レビューを実施してください。

出力モード:
{$mode}

利用者入力:
- 事業名: {$projectName}
- 年度: {$fiscalYear}
- 所管課: {$department}

対象ファイル:
{$fileJson}

出力形式:
## 3行要約
- 3点

## 基本情報
- 事業名
- 所管
- 年度
- 資料の性質

## 事業概要
- 目的
- 現状課題
- 実施内容
- 対象者

## 予算・執行
- 予算額
- 執行額
- 執行率
- 支出先・資金の流れ

## KPI・成果
- KPI一覧
- 成果の有無
- KPI妥当性

## 観点別評価
- 必要性: 1〜5
- 有効性: 1〜5
- 効率性: 1〜5
- 執行適正: 1〜5
- 透明性: 1〜5
- KPI妥当性: 1〜5
- 総合判定: 現状通り / 執行改善 / 事業内容の一部改善 / 抜本的見直し / 縮減 / 廃止検討

## 主な問題点
- 3〜5点

## 改善提案
- 短期
- 中期
- 構造見直し

## 追加確認事項
- 3〜5点

## 根拠箇所
- ファイル名やページ番号が分かる場合は書く

ルール:
- 表形式は使わず、見出しと箇条書きで出力する
- 不明点は必ず「資料上確認できない」と書く
- 数値は元の表記を尊重する

抽出テキスト:
{$sourceText}
PROMPT;
}
