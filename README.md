日本語 | [English](README.en.md)

# 源内 AI アプリ

## 概要

源内（GenAI）は、デジタル庁が開発・運用する生成 AI 利活用基盤です。行政職員が業務特化の生成 AI アプリケーションを、迅速かつ安全かつ簡単に利用できる環境を提供します。  
源内は大きく分けて2種類のシステムで構成されます。

- [源内 Web](https://github.com/digital-go-jp/genai-web): 利用者が直接さわる Web アプリケーション
- [行政実務用 AI アプリ](https://github.com/digital-go-jp/genai-ai-api): 生成 AI を活用したマイクロサービス

本リポジトリではこのうち、中央省庁で実際に展開されている行政実務用 AI アプリの一部を公開しています。  
源内の取り組み・構想・生成 AI 活用方法の詳細等は、[デジタル庁のTechブログ](https://digital-gov.note.jp/m/m90208c3610d0)で発信中です。

## 機能一覧（源内AI Web v1.0.7）

本パッケージ（`index.htm` / `app/` / `app2/`）で利用できる Web アプリの一覧です。  
PHP が動作する Web サーバーに配置し、`api/.env` に API キーを設定して利用します。

### 共通機能

| 機能 | 説明 | 主なファイル |
|------|------|----------------|
| ログイン | パスワード認証（`sessionStorage`、タブ単位） | `index.htm`, `api/auth.php` |
| ホーム | アプリ一覧・クイックチャット入力 | `app/main.htm` |
| AIアプリ一覧 | 全アプリへのカードナビゲーション | `app/apps.htm` |
| ヘッダーナビ | チャット / AIアプリ / アカウントメニュー | `shared/layout107.js` |
| 利用履歴 | ブラウザ内 IndexedDB に保存（7日保持）、再開・削除 | `app/history.htm`, `shared/history-store.js` |
| 利用状況ログ | アプリ別アクセス件数（非ブロッキング） | `log/count.php` |
| デザイン | DADS 107 準拠 UI | `shared/dads107.css` |

### デジタル庁 提供AIアプリ（`app/`）

| # | アプリ名 | パス | 概要 | 主な技術 |
|---|----------|------|------|----------|
| 1 | **チャット** | `app/chat.htm` | AI との対話。モデル切替（Gemini / OpenAI GPT）、システムプロンプトの設定・保存、プロンプト一覧、ファイル添付（PDF/Office/画像等）、Markdown 表示、Ctrl+Enter 送信 | `api/chat-stream.php` |
| 2 | **文章を生成** | `app/generate.htm` | 「元になる情報」と「文章の形式」から業務文書のドラフトを生成（1000字以上想定）、コピー | Gemini SSE |
| 3 | **翻訳** | `app/translate.htm` | 日本語・英語・中国語・韓国語・フランス語・スペイン語・ドイツ語への翻訳。即時翻訳 ON/OFF、コピー | Gemini SSE |
| 4 | **画像を生成** | `app/image.htm` | Imagen 4.0 Fast による画像生成。チャット形式でプロンプト自動生成、手動プロンプト編集、ネガティブプロンプト、5種アスペクト比、14種スタイルプリセット、ダウンロード | `api/image-generate.php`, `api/chat-stream.php` |
| 5 | **ダイアグラムを生成** | `app/diagram.htm` | テキストから Mermaid 図を生成・プレビュー・SVG/PNG 出力。22種（AI自動選択含む） | Gemini SSE + Mermaid.js |
| 6 | **文字起こし** | `app/transcribe.htm` | 音声ファイル（MP3/WAV 等、50MB以下）の文字起こし。話者認識オプション、コピー | `api/transcribe.php`（Gemini File API） |
| 7 | **行政実務用RAG** | `app/rag.htm` | Markdown ナレッジベースのベクトル検索＋回答生成（デモデータ: 骨太の方針2025）。4段階パイプライン（クエリ拡張→検索→関連性評価→回答）、中間結果表示 | `rag/rag_api.php` |
| 8 | **法令AI Lawsy** | `app/lawsy.htm` | 法令質問に対し e-Gov 法令 API 等と連携した構造化レポート。質問タイプ自動判定（定義・手続・比較・解釈・政策・包括分析） | `lawsy/lawsy_api.php` |

#### ダイアグラム生成で対応する図の種類（22種）

AI自動選択、フローチャート、円グラフ、マインドマップ、4象限チャート、シーケンス図、タイムライン図、Gitグラフ、ER図、クラス図、状態遷移図、XYチャート、ブロック図、アーキテクチャ図、ガントチャート、ユーザージャーニー図、サンキーチャート、要件図、ネットワークパケット図

#### 画像生成のスタイルプリセット（14種）

photographic, anime, cinematic, digital-art, illustration, comic, watercolor, oil-painting, pencil-sketch, 3d-render, pixel-art, minimalist, pop-art, ukiyo-e

#### 画像生成のアスペクト比（5種）

1:1 (1024×1024)、5:4、3:2、16:9、9:16

### 全国874自治体 専用アプリ（`app2/`）

| # | アプリ名 | パス | 概要 | 主な技術 |
|---|----------|------|------|----------|
| 9 | **文章の校正・要約** | `app2/proofread.htm` | モード: 校正 / 推敲 / 要約。テキスト入力またはファイル添付（PDF/DOCX/PPTX/XLSX/TXT/CSV/画像） | `api2/chat-stream.php` |
| 10 | **メール文案・作成** | `app2/email.htm` | タイトル・内容・スタイル（行政文書 / 丁寧 / カジュアル）からメール文案を生成 | `api2/chat-stream.php` |
| 11 | **議会答弁の作成** | `app2/answer.htm` | 議会質問の要旨から答弁案ドラフトを生成。ナレッジ検索に基づく引用元表示 | `api2/dify-answer.php`（Dify） |
| 12 | **補助金制度調査** | `app2/subsidy.htm` | 経済産業省 jGrants API で補助金・助成金を検索・一覧表示 | `api2/jgrants-proxy.php` |
| 13 | **国立国会図書館サーチ** | `app2/tosho.htm` | 国立国会図書館サーチ API（SRU）で蔵書検索（キーワード・タイトル・著者・出版社） | NDL Search API（ブラウザから直接） |
| 14 | **孤独・孤立対策 支援策** | `app2/kodoku.htm` | 孤独・孤立対策重点計画ナレッジ（143施策）を RAG 検索し引用付きで回答 | `api2/kodoku-chat.php` |

### バックエンド API 一覧

#### `api/`（デジタル庁系アプリ向け）

| ファイル | 用途 |
|----------|------|
| `auth.php` | ログイン認証 |
| `chat-stream.php` | Gemini / OpenAI の SSE チャットプロキシ |
| `predict.php` | 非ストリーミング応答 |
| `image-generate.php` | Imagen 4.0 画像生成 |
| `transcribe.php` | 音声文字起こし |
| `file-upload.php` / `file-delete.php` | チャット等のファイル添付 |
| `systemcontexts.php` | システムプロンプト CRUD（`systemcontexts.json`） |

#### `api2/`（自治体追加アプリ向け）

| ファイル | 用途 |
|----------|------|
| `chat-stream.php` | Gemini / OpenAI の SSE チャットプロキシ |
| `dify-answer.php` | 議会答弁（Dify SSE → NDJSON、引用元付き） |
| `kodoku-chat.php` | 孤独・孤立対策チャット（ナレッジ注入 + Gemini SSE） |
| `jgrants-proxy.php` | jGrants API プロキシ |
| `file-upload.php` / `file-delete.php` | ファイル添付（校正等） |

#### その他

| パス | 用途 |
|------|------|
| `rag/rag_api.php` | 行政実務用 RAG |
| `lawsy/lawsy_api.php` | 法令AI Lawsy |
| `log/count.php` | 利用カウント（CSV 累積） |

### ディレクトリ構成（Web アプリ部分）

```
genai107/
├── index.htm              # ログイン画面
├── app/                   # デジタル庁 提供AIアプリ（8機能）
├── app2/                  # 自治体専用追加アプリ（6機能）
├── api/                   # PHP API（.env はここに配置）
├── api2/                  # 自治体向け PHP API
├── shared/                # 共通 JS/CSS（layout, auth, api-client, markdown, history）
├── rag/                   # RAG エンジン・データ
├── lawsy/                 # Lawsy API
├── log/                   # アクセスログ
├── images/                # ファビコン等
├── azure/                 # Azure 向け開発テンプレート（別 README）
├── google-cloud/          # GCP Lawsy 実装テンプレート（別 README）
└── aws/                   # AWS RAG 開発テンプレート（別 README）
```

### セットアップ（Web アプリ）

1. リポジトリを PHP 対応の Web サーバーに配置する
2. `api/.env` を作成し、利用する機能に応じてキーを設定する

```env
# 必須（多くのアプリで使用）
GEMINI_API_KEY=your-gemini-api-key
GENNAI_PASSWORD=kaiin

# チャットで OpenAI を使う場合
OPENAI_API_KEY=your-openai-api-key

# 議会答弁（Dify アプリ連携）
DIFY_ANSWER_KEY=your-dify-api-key

# 任意（モデル指定）
GEMINI_MODEL=gemini-3.1-flash-lite
```

3. ブラウザで `index.htm` を開き、`.env` の `GENNAI_PASSWORD` でログインする
4. `app/main.htm` から各アプリを利用する

### 注意事項（全アプリ共通）

- AI の出力は必ずしも正確ではありません。重要な判断・公表前には原典・担当部署での確認が必要です
- 機密性の高い情報（機密性3情報等）の入力は避けてください
- `api/.env` には API キーを含めます。公開リポジトリへのコミットや Web からの直接参照はしないでください（`.htaccess` で `.env` へのアクセスを拒否）

---

## 公開中の行政実務用 AI アプリ（クラウド開発テンプレート）

源内で利用できる行政実務用 AI アプリは、源内 Web との間のプロトコルに準拠すれば、源内 Web と独立した環境で構築ができ、GUI 等の操作で源内への追加登録ができます。  
以下、ガバメントクラウドに採択されたクラウドサービスごとに、どのような行政実務用 AI アプリを本リポジトリで公開しているか、について簡単にまとめたものです。

### Microsoft Azure

- [LLM（大規模言語モデル）をセルフデプロイして利用する開発テンプレート](./azure/genai-azure/)

### Google Cloud

- [最新の法律条文データを参照・回答する法制度に関するAIアプリの再現可能な実装](./google-cloud/lawsy-custom-bq/)

### Amazon Web Services

- [行政実務用ＲＡＧ（検索拡張生成）の開発テンプレート](./aws/query-expansion-rag/)

それぞれの行政実務用 AI アプリの詳細は、フォルダ内の README ファイルをご参照ください。

## Issue / Pull Request の対応方針

本リポジトリでは、サービスの安定運用に影響する致命的な問題に限り、Issue での報告を受け付けています。Pull Request は受け付けておりません。

### Issues

#### 報告対象となるもの

- データの損失・破損 を引き起こす不具合
- サービスが利用不能になる 障害
- 法令・規則への違反 に関わる問題（例：個人情報の意図しない露出）

#### 報告対象外のもの

以下については、Issue での報告はご遠慮ください。  
テンプレートに合致しない Issue はクローズさせていただく場合があります。

- 機能追加の要望・提案
- 軽微な表示崩れ・誤字脱字
- パフォーマンスの改善提案
- コーディングスタイルに関する指摘
- 質問・使い方の相談

### 対応について

- Issue への対応は、内部の優先度判断に基づき行います
- すべての Issue に対応できるとは限りません
- 対応状況についてのお問い合わせへの個別回答は行っておりません
- 致命的と判断された問題については、可能な範囲で対応状況を Issue 上でお知らせします

## 脆弱性の報告

脆弱性の報告は、https://github.com/digital-go-jp/genai-ai-api/security よりご報告ください。

## このソースコード・ドキュメント等の性格

このリポジトリ（ソースコードおよびドキュメント）は、デジタル庁が作成し、公開するものです。  
公的資源として、OSS コミュニティのすべての方にオープンにしております。そのため、以下のことは禁止します。

- 特定の思想・団体・企業を支持または排除するような行為
- 政治的・宗教的・差別的な内容の発言
- 個人情報や機微情報をリポジトリ上で扱う行為
- セキュリティ脆弱性発見時に、デジタル庁に報告・承諾を得ることなく、脆弱性内容を第三者に開示する行為
- 本ソースコードを他システムへの攻撃を目的として改変する行為

## 関連リンク

- [ガバメント AI、プロジェクト「源内」の構想紹介 - デジタル庁 note 記事](https://digital-gov.note.jp/n/ndc07326b7491)

## License

- Software: Licensed under the [MIT License](LICENSE).
- Documentation: Licensed under the [Creative Commons Attribution 4.0 International License](LICENSE-CC-BY) (CC BY 4.0).
