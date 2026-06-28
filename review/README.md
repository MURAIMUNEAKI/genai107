# review

PDF / DOCX / CSV をアップロードして、抽出テキストだけを OpenAI に渡して行政事業レビューを返す簡易アプリです。

## ファイル構成

- `index.htm` UI
- `review.php` SSE エンドポイント
- `lib.php` 抽出・共通処理
- `prompts.php` プロンプト定義
- `.env` API キーとモデル名
- `.htaccess` `.env` 保護

## 初期設定

1. `review/.env` のダミー値を本物に差し替える

```env
OPENAI_API_KEY=sk-...
OPENAI_MODEL=gpt-5.4-nano
```

2. Web サーバーで `review/index.htm` を開く

## 注意

- OpenAI 側の公開モデル名は変更されることがあります。
- 現在の実装では `.env` の `OPENAI_MODEL` をそのまま送るため、実運用時は利用可能な正式モデル名に変更してください。
- PDF はブラウザの `pdf.js` 抽出を優先し、失敗時はサーバー側でフォールバックします。
