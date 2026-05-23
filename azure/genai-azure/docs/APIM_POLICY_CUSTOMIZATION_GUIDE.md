# APIMポリシーカスタマイズガイド

本ドキュメントでは、Azure API Managementのポリシーをカスタマイズして、OpenAI互換APIから独自のリクエスト/レスポンス形式に変換する方法を解説します。Plamo翻訳モデル用のカスタムフォーマットを例に、ステップバイステップでポリシー修正の手順を説明します。

## 目次

- [ポリシーカスタマイズの概要](#ポリシーカスタマイズの概要)
- [ステップバイステップガイド](#ステップバイステップガイド)
  - [Step 1: 目標とするAPI仕様を設計する](#step-1-目標とするapi仕様を設計する)
  - [Step 2: バックエンドが期待する形式を理解する](#step-2-バックエンドが期待する形式を理解する)
  - [Step 3: inboundポリシーでリクエスト変換を実装する](#step-3-inboundポリシーでリクエスト変換を実装する)
  - [Step 4: outboundポリシーでレスポンス変換を実装する](#step-4-outboundポリシーでレスポンス変換を実装する)
  - [Step 5: テストとデバッグ](#step-5-テストとデバッグ)
  - [Step 6: デプロイ](#step-6-デプロイ)
- [APIMポリシーの基礎知識](#apimポリシーの基礎知識)
  - [ポリシーとは](#ポリシーとは)
  - [ポリシーの処理フロー](#ポリシーの処理フロー)
  - [ポリシー式の記法まとめ](#ポリシー式の記法まとめ)
- [よくあるユースケース](#よくあるユースケース)
- [デバッグのヒント](#デバッグのヒント)
- [参考リンク](#参考リンク)

---

# ポリシーカスタマイズの概要

## なぜポリシーカスタマイズが必要か

vLLMのOpenAI互換APIは強力ですが、以下のような課題があります：

1. **リクエストが複雑**: モデル固有のプロンプト形式（特殊トークンなど）をクライアントが意識する必要がある
2. **レスポンスが冗長**: クライアントに必要な情報は一部だけなのに、大量のメタデータが含まれる
3. **使いやすさの向上**: エンドユーザー向けにシンプルなAPI仕様を提供したい

APIMポリシーを使えば、**クライアント向けのシンプルなAPI** と **バックエンド（vLLM）が期待する複雑なAPI** の間の変換を透過的に行えます。

## 本ガイドで作成するもの

Plamo翻訳モデルを例に、以下の変換を実装します：

**クライアントが送るシンプルなリクエスト:**
```json
{
  "inputs": {
    "input_text": "今日はいい天気です",
    "option": "Jp2En"
  }
}
```

**vLLMが期待する複雑なリクエスト:**
```json
{
  "model": "pfnet/plamo-2-translate",
  "max_tokens": 1024,
  "temperature": 0,
  "stop": "<|plamo:op|>",
  "prompt": "<|plamo:op|>dataset translation <|plamo:op|>input lang=Japanese 今日はいい天気です <|plamo:op|>output lang=English\n"
}
```

**vLLMが返す複雑なレスポンス:**
```json
{
  "id": "cmpl-xxx",
  "object": "text_completion",
  "choices": [{"text": "It's nice weather today.\n", "finish_reason": "stop"}],
  "usage": {"prompt_tokens": 22, "total_tokens": 32}
}
```

**クライアントに返すシンプルなレスポンス:**
```json
{
  "statusCode": 200,
  "outputs": "It's nice weather today."
}
```

---

# ステップバイステップガイド

## Step 1: 目標とするAPI仕様を設計する

まず、クライアントに公開したいAPI仕様を設計します。以下の観点で検討してください：

### 1.1 リクエスト形式の設計

**検討ポイント:**
- クライアントが指定すべき必須パラメータは何か？
- モデル固有の詳細（プロンプト形式、特殊トークン等）を隠蔽できるか？
- 拡張性はあるか？

**Plamo翻訳の例:**
```json
{
  "inputs": {
    "input_text": "翻訳したいテキスト",
    "option": "Jp2En または En2Jp"
  }
}
```

| フィールド | 説明 | 理由 |
|-----------|------|------|
| `inputs.input_text` | 翻訳対象のテキスト | 必須の入力データ |
| `inputs.option` | 翻訳方向（Jp2En/En2Jp） | ユーザーが選択する唯一のオプション |

### 1.2 レスポンス形式の設計

**検討ポイント:**
- クライアントに必要な情報は何か？
- エラー時のレスポンス形式はどうするか？
- 将来の拡張に備えた構造か？

**Plamo翻訳の例:**
```json
{
  "statusCode": 200,
  "outputs": "翻訳結果のテキスト"
}
```

| フィールド | 説明 | 理由 |
|-----------|------|------|
| `statusCode` | HTTPステータスコード | 成功/失敗の判定に使用 |
| `outputs` | 翻訳結果 | クライアントが必要とする唯一の出力 |

---

## Step 2: バックエンドが期待する形式を理解する

vLLMが期待するリクエスト/レスポンス形式を確認します。

### 2.1 vLLM Completions APIのリクエスト形式

```bash
# パススルーモード（enablePlamoCustomApiTransform = false）で実際にリクエストして確認
curl -X POST "https://apim-xyz.azure-api.net/vllm/v1/completions" \
  -H "Content-Type: application/json" \
  -H "x-api-key: your-subscription-key" \
  -d '{
    "model": "pfnet/plamo-2-translate",
    "max_tokens": 1024,
    "temperature": 0,
    "stop": "<|plamo:op|>",
    "prompt": "<|plamo:op|>dataset translation <|plamo:op|>input lang=Japanese テスト <|plamo:op|>output lang=English\n"
  }'
```

### 2.2 モデル固有のプロンプト形式を調査

Plamo翻訳モデルの場合、Hugging Faceのモデルカードやドキュメントから以下のプロンプト形式が必要と判明：

```
<|plamo:op|>dataset translation <|plamo:op|>input lang={入力言語} {入力テキスト} <|plamo:op|>output lang={出力言語}\n
```

### 2.3 変換ルールを整理

| クライアント入力 | 変換先 |
|-----------------|--------|
| `option: "Jp2En"` | `input lang=Japanese`, `output lang=English` |
| `option: "En2Jp"` | `input lang=English`, `output lang=Japanese` |
| `input_text` | プロンプト内の入力テキスト部分 |

---

## Step 3: inboundポリシーでリクエスト変換を実装する

`infra/app/apim-api-policy.xml`の`<inbound>`セクションを編集します。

### 3.1 リクエストBodyから値を抽出

まず、クライアントからのリクエストを解析して必要な値を変数に保存します：

```xml
<inbound>
    <!-- 既存のポリシー（認証、CORS等）はそのまま -->
    
    <!-- Step 3.1: リクエストBodyから値を抽出 -->
    <set-variable name="opt" value="@((string)context.Request.Body.As<Newtonsoft.Json.Linq.JObject>(true).SelectToken("inputs.option"))" />
    <set-variable name="txt" value="@((string)context.Request.Body.As<Newtonsoft.Json.Linq.JObject>(true).SelectToken("inputs.input_text"))" />
```

**ポイント:**
- `.As<JObject>(true)` の `true` はBodyを保持するオプション（後続の処理でもBodyを参照可能）
- `.SelectToken()` はJSONパス記法で値を抽出

### 3.2 条件分岐でリクエストBodyを変換

抽出した値を使って、vLLMが期待する形式のリクエストBodyを構築します：

```xml
    <!-- Step 3.2: 条件分岐でリクエストを変換 -->
    <choose>
        <!-- 日本語→英語の場合 -->
        <when condition="@(((string)context.Variables["opt"]) == "Jp2En")">
            <set-body><![CDATA[@{
                var inputText = (string)context.Variables["txt"] ?? string.Empty;

                // Plamo翻訳モデル用のプロンプトを構築
                var prompt = "<|plamo:op|>dataset translation "
                            + "<|plamo:op|>input lang=Japanese " + inputText + " "
                            + "<|plamo:op|>output lang=English\n";

                // vLLM Completions APIが期待するJSONを構築
                var body = new Newtonsoft.Json.Linq.JObject(
                    new Newtonsoft.Json.Linq.JProperty("model", "pfnet/plamo-2-translate"),
                    new Newtonsoft.Json.Linq.JProperty("max_tokens", 1024),
                    new Newtonsoft.Json.Linq.JProperty("temperature", 0),
                    new Newtonsoft.Json.Linq.JProperty("stop", "<|plamo:op|>"),
                    new Newtonsoft.Json.Linq.JProperty("prompt", prompt)
                );

                return body.ToString(Newtonsoft.Json.Formatting.None);
            }]]></set-body>
        </when>
        
        <!-- 英語→日本語の場合 -->
        <when condition="@(((string)context.Variables["opt"]) == "En2Jp")">
            <set-body><![CDATA[@{
                var inputText = (string)context.Variables["txt"] ?? string.Empty;

                var prompt = "<|plamo:op|>dataset translation "
                            + "<|plamo:op|>input lang=English " + inputText + " "
                            + "<|plamo:op|>output lang=Japanese\n";

                var body = new Newtonsoft.Json.Linq.JObject(
                    new Newtonsoft.Json.Linq.JProperty("model", "pfnet/plamo-2-translate"),
                    new Newtonsoft.Json.Linq.JProperty("max_tokens", 1024),
                    new Newtonsoft.Json.Linq.JProperty("temperature", 0),
                    new Newtonsoft.Json.Linq.JProperty("stop", "<|plamo:op|>"),
                    new Newtonsoft.Json.Linq.JProperty("prompt", prompt)
                );

                return body.ToString(Newtonsoft.Json.Formatting.None);
            }]]></set-body>
        </when>
        
        <!-- optionが指定されていない場合はパススルー（変換なし） -->
        <otherwise>
            <!-- 何もしない = 元のリクエストをそのまま転送 -->
        </otherwise>
    </choose>
</inbound>
```

**ポイント:**
- `<![CDATA[@{ ... }]]>` でC#コードブロックを記述
- `<otherwise>` で変換対象外のリクエストはパススルー
- モデル名、max_tokens等はハードコードまたはパラメータ化

---

## Step 4: outboundポリシーでレスポンス変換を実装する

`<outbound>`セクションでvLLMからのレスポンスをクライアント向けに整形します。

### 4.1 レスポンスから必要な値を抽出

```xml
<outbound>
    <!-- Step 4.1: レスポンスから翻訳結果を抽出 -->
    <set-variable name="resp_text" value="@(((string)context.Response.Body.As<Newtonsoft.Json.Linq.JObject>(true)
                .SelectToken("choices[0].text") ?? string.Empty)
            .TrimEnd((char)13,(char)10))" />
```

**ポイント:**
- `choices[0].text` でvLLMレスポンスから翻訳結果を抽出
- `.TrimEnd((char)13,(char)10)` で末尾の改行コード（CR/LF）を削除

### 4.2 成功時のみレスポンスを整形

```xml
    <!-- Step 4.2: 成功レスポンスの場合のみ整形 -->
    <choose>
        <when condition="@((int)context.Response.StatusCode == 200)">
            <set-header name="Content-Type" exists-action="override">
                <value>application/json; charset=utf-8</value>
            </set-header>
            <set-body template="liquid">{"statusCode": 200,"outputs": "{{ context.Variables.resp_text|default: ""|json}}"}</set-body>
        </when>
        <!-- エラー時は元のレスポンスをそのまま返す -->
    </choose>
</outbound>
```

**ポイント:**
- `template="liquid"` でLiquidテンプレートを使用
- `|json` フィルターで特殊文字をエスケープ
- エラー時（200以外）は変換せず、バックエンドのエラーをそのまま返す

---

## Step 5: テストとデバッグ

### 5.1 ローカルでのポリシー構文チェック

ポリシーXMLの構文エラーがないか確認します。特に以下に注意：
- `<![CDATA[ ]]>` の閉じ忘れ
- C#コードの構文エラー
- XMLの特殊文字（`<`, `>`, `&`）のエスケープ

### 5.2 Azure Portalでのテスト

1. Azure Portal > API Management > APIs > Test タブを開く
2. リクエストBodyにテストデータを入力
3. 「Send」をクリックしてレスポンスを確認
4. 「Trace」を有効にすると、各ポリシーの実行結果を確認可能

### 5.3 トレースログの追加

デバッグ用に変数の値をログ出力：

```xml
<trace source="debug-inbound">@{
    return $"opt={context.Variables["opt"]}, txt={context.Variables["txt"]}";
}</trace>
```

---

## Step 6: デプロイ

### 6.1 enablePlamoCustomApiTransformをtrueに設定

`infra/main.parameters.json`を編集：

```json
{
  "parameters": {
    "enablePlamoCustomApiTransform": {
      "value": true
    }
  }
}
```

### 6.2 再デプロイ

```bash
azd provision
```

### 6.3 動作確認

```bash
curl -X POST "https://apim-xyz.azure-api.net/vllm/v1/completions" \
  -H "Content-Type: application/json" \
  -H "x-api-key: your-subscription-key" \
  -d '{"inputs": {"input_text": "今日はいい天気です", "option": "Jp2En"}}'
```

**期待されるレスポンス:**
```json
{"statusCode": 200, "outputs": "It's nice weather today."}
```

---

# APIMポリシーの基礎知識

## ポリシーとは

Azure API Management（APIM）のポリシーは、APIリクエスト・レスポンスを柔軟に変換・制御するための仕組みです。本プロジェクトでは、クライアントから受け取ったシンプルなリクエストを、バックエンドのvLLMが理解できる形式に変換し、レスポンスも再び整形してクライアントに返しています。

## ポリシーの処理フロー

ポリシーは4つのセクションで構成され、リクエスト・レスポンスの流れに沿って順番に実行されます：

```
クライアント → [inbound] → [backend] → バックエンドサービス(VMSS)
                                              ↓
クライアント ← [outbound] ← [on-error] ←  バックエンドサービス(VMSS)
```

| セクション | 実行タイミング | 主な用途 |
|-----------|--------------|---------|
| `<inbound>` | リクエスト受信後、バックエンド転送前 | 認証、リクエスト変換、変数設定 |
| `<backend>` | バックエンドへのリクエスト送信時 | ルーティング制御 |
| `<outbound>` | レスポンス受信後、クライアント返送前 | レスポンス変換、ヘッダー編集 |
| `<on-error>` | エラー発生時 | エラーハンドリング |

---

## ポリシー式の記法まとめ

### 基本構文

| 記法 | 説明 | 使用例 |
|------|------|--------|
| `@(...)` | C#式の評価結果を埋め込む | `@((string)context.Variables["opt"])` |
| `@{ ... }` | 複数行のC#コードブロック | `<![CDATA[@{ ... }]]>` で囲んで使用 |
| `{{ 変数名 }}` | Liquidテンプレート記法 | `{{ context.Variables.resp_text }}` |

### コンテキストオブジェクト

| オブジェクト | 説明 | 主なプロパティ |
|-------------|------|---------------|
| `context.Request` | リクエスト情報 | `.Body`, `.Headers`, `.Url` |
| `context.Response` | レスポンス情報 | `.Body`, `.Headers`, `.StatusCode` |
| `context.Variables` | 変数コレクション | `["変数名"]` でアクセス |

### JSON操作メソッド

| メソッド | 説明 | 使用例 |
|---------|------|--------|
| `.As<JObject>(true)` | JSONオブジェクトとしてパース | `context.Request.Body.As<JObject>(true)` |
| `.SelectToken("path")` | JSONパス記法で値を抽出 | `.SelectToken("choices[0].text")` |
| `new JObject(...)` | JSONオブジェクトを新規作成 | `new JObject(new JProperty("key", "value"))` |
| `new JProperty("key", value)` | JSONプロパティを作成 | キーと値のペア |
| `.ToString(Formatting.None)` | JSON文字列に変換 | 改行なしの圧縮形式 |

### Liquidフィルター

| フィルター | 説明 | 使用例 |
|-----------|------|--------|
| `\|json` | JSON文字列としてエスケープ | `{{ value \| json }}` |
| `\|default: "値"` | デフォルト値を設定 | `{{ value \| default: "" }}` |
| `\|Date: "format"` | 日時のフォーマット | `{{ "now" \| Date: "yyyy-MM-dd" }}` |

---

# よくあるユースケース

## 1. リクエストヘッダーの追加・変更
```xml
<set-header name="X-Custom-Header" exists-action="override">
    <value>custom-value</value>
</set-header>

<!-- 動的な値の設定 -->
<set-header name="X-Request-Time" exists-action="override">
    <value>@(DateTime.UtcNow.ToString("o"))</value>
</set-header>
```

## 2. クエリパラメータの取得と利用
```xml
<set-variable name="userId" value="@(context.Request.Url.Query.GetValueOrDefault("user_id", "anonymous"))" />
```

## 3. 複数条件の分岐
```xml
<choose>
    <when condition="@(context.Variables["type"] == "A")">
        <!-- 処理A -->
    </when>
    <when condition="@(context.Variables["type"] == "B")">
        <!-- 処理B -->
    </when>
    <otherwise>
        <!-- デフォルト処理 -->
    </otherwise>
</choose>
```

## 4. エラーレスポンスのカスタマイズ
```xml
<on-error>
    <set-body>@{
        return new JObject(
            new JProperty("error", context.LastError.Message),
            new JProperty("source", context.LastError.Source),
            new JProperty("timestamp", DateTime.UtcNow.ToString("o"))
        ).ToString();
    }</set-body>
    <set-header name="Content-Type" exists-action="override">
        <value>application/json</value>
    </set-header>
</on-error>
```

## 5. リクエストBodyの部分的な変更
```xml
<set-body>@{
    var body = context.Request.Body.As<JObject>(true);
    body["newField"] = "newValue";
    body["existingField"] = "updatedValue";
    return body.ToString();
}</set-body>
```

## 6. リクエストBodyをLiquidテンプレートで変更
```xml
<!-- 事前に必要な変数を設定 -->
<set-variable name="userId" value="@(context.Request.Headers.GetValueOrDefault("X-User-ID", "anonymous"))" />
<set-variable name="requestTime" value="@(DateTime.UtcNow.ToString("yyyy-MM-ddTHH:mm:ssZ"))" />

<!-- LiquidテンプレートでリクエストBodyを作成 -->
<set-body template="liquid">
{
  "originalData": {{ body | json }},
  "metadata": {
    "userId": "{{ context.Variables.userId }}",
    "requestTime": "{{ context.Variables.requestTime }}",
    "apiVersion": "v1"
  },
  "transformedRequest": {
    "action": "{{ body.action | default: 'unknown' }}",
    "parameters": {{ body.parameters | json }}
  }
}
</set-body>
```

**使用例:**
元のリクエスト:
```json
{"action": "translate", "parameters": {"text": "Hello", "lang": "ja"}}
```

変換後のリクエスト:
```json
{
  "originalData": {"action": "translate", "parameters": {"text": "Hello", "lang": "ja"}},
  "metadata": {
    "userId": "user123",
    "requestTime": "2025-10-03T10:30:00Z",
    "apiVersion": "v1"
  },
  "transformedRequest": {
    "action": "translate",
    "parameters": {"text": "Hello", "lang": "ja"}
  }
}
```

---

# デバッグのヒント

## 1. 変数の値をログ出力
APIMのトレース機能を使用して、変数の値を確認できます：
```xml
<trace source="custom">@{
    return $"opt={context.Variables["opt"]}, txt={context.Variables["txt"]}";
}</trace>
```

## 2. リクエスト/レスポンスBodyの確認
Azure Portal > API Management > APIs > Test タブから、リクエストとレスポンスの詳細を確認できます。

## 3. ポリシーのテスト
本番環境に適用する前に、テスト環境やAPIのテスト機能でポリシーの動作を確認してください。

---

# 参考リンク

- [API Management ポリシー リファレンス](https://learn.microsoft.com/ja-jp/azure/api-management/api-management-policies) - 頻繁に利用されるポリシーの一覧と記載例
- [API Management ポリシー式](https://learn.microsoft.com/ja-jp/azure/api-management/api-management-policy-expressions) - C#式の詳細な記法
- [set-body ポリシー](https://learn.microsoft.com/ja-jp/azure/api-management/set-body-policy) - リクエスト/レスポンスBodyの設定方法
- [Liquid テンプレート リファレンス](https://shopify.github.io/liquid/) - Liquidテンプレートの文法
