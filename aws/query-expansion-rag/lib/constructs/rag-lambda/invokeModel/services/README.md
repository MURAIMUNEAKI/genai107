# services/ ディレクトリ

このディレクトリには**外部サービスとの統合**を担当するモジュールを配置します。

## 📋 配置基準

以下の特徴を持つモジュールは `services/` に配置してください:

### ✅ 配置すべきもの

- **外部API・サービスとの通信**
  - AWS Bedrock、Knowledge Base等の外部APIクライアントのラッパー
  - サービス固有のリクエスト/レスポンス処理

- **ビジネスロジックを含む処理**
  - 特定ドメイン（RAG、LLM等）のビジネスルール
  - サービス固有のエラーハンドリングやリトライロジック

- **状態を持つ処理**
  - 使用状況の追跡・蓄積
  - キャッシュやセッション管理

- **特定サービスのデータ変換**
  - サービス固有のレスポンス形式のパース
  - ドメインモデルへの変換

### ❌ 配置すべきでないもの

- 汎用的なユーティリティ関数 → `utils/` へ
- RAGのコアロジック → `core/` へ
- 設定管理 → `config/` へ

## 📁 現在のファイル

### `bedrock_usage_tracker.py`
**役割:** AWS Bedrockの使用量追跡とコスト計算

- Bedrockモデルの入力/出力トークン数を追跡
- モデルごとの料金計算
- 使用状況の集計

**配置理由:**
- ✅ Bedrock固有のビジネスロジック
- ✅ 状態を持つ（使用量データの蓄積）
- ✅ モデル料金という特定ドメイン知識

```python
# 使用例
from services.bedrock_usage_tracker import BedrockUsageTracker

tracker = BedrockUsageTracker()
tracker.track_model_usage(model_id, input_tokens, output_tokens)
usage_summary = tracker.get_usage_summary()
```

### `converse_helper.py`
**役割:** AWS Bedrock Converse API呼び出しのヘルパー

- Converse APIの呼び出しラッパー
- リクエストパラメータの構築
- レスポンスの標準化

**配置理由:**
- ✅ Bedrock Converse APIという特定サービスへの依存
- ✅ API固有のエラーハンドリング
- ✅ RAG固有のパラメータ調整ロジック

```python
# 使用例
from services.converse_helper import invoke_converse_simple

response = invoke_converse_simple(
    client=bedrock_client,
    model_id="anthropic.claude-3-haiku-20240307-v1:0",
    messages=[{"role": "user", "content": "質問"}]
)
```

### `kb_response_processor.py`
**役割:** Knowledge Base検索結果の処理

- KB API応答のパース
- 検索結果のフィルタリング・ソート
- ドメインモデル（KBResponse）への変換

**配置理由:**
- ✅ Knowledge Baseという特定サービスのレスポンス形式への依存
- ✅ RAG固有のデータ変換ロジック
- ✅ 検索結果の加工というビジネスロジック

```python
# 使用例
from services.kb_response_processor import process_kb_response, KBResponse

kb_responses: list[KBResponse] = process_kb_response(raw_response)
texts = extract_texts_from_kb_response(kb_responses)
```

## 🔍 判断フローチャート

新しいモジュールを追加する際の判断基準:

```
外部API/サービスと通信する？
  ├─ YES → services/ に配置
  └─ NO
      ├─ 特定サービスのデータ形式に依存する？
      │   ├─ YES → services/ に配置
      │   └─ NO
      │       ├─ ビジネスロジックを含む？
      │       │   ├─ YES → core/ または services/ を検討
      │       │   └─ NO → utils/ に配置
      │       └─ 状態を持つ？
      │           ├─ YES → services/ に配置
      │           └─ NO → utils/ に配置
```

## 💡 命名規則

- **ファイル名:** `{service_name}_{purpose}.py`
  - 例: `bedrock_usage_tracker.py`, `kb_response_processor.py`

- **クラス名:** `{ServiceName}{Purpose}`
  - 例: `BedrockUsageTracker`, `KBResponseProcessor`

- **関数名:** 動詞始まり、何をするかが明確
  - 例: `invoke_converse_simple()`, `process_kb_response()`

## 🧪 テスト

services/ のモジュールは外部サービスに依存するため、モックを使用したテストを推奨:

```python
# tests/test_bedrock_usage_tracker.py
from unittest.mock import Mock, patch
from services.bedrock_usage_tracker import BedrockUsageTracker

def test_track_model_usage():
    tracker = BedrockUsageTracker()
    tracker.track_model_usage("model-id", 100, 200)
    assert tracker.get_usage_summary() is not None
```

## 📚 関連ドキュメント

- [AWS Bedrock API リファレンス](https://docs.aws.amazon.com/bedrock/latest/APIReference/)
- [Knowledge Base API](https://docs.aws.amazon.com/bedrock/latest/userguide/knowledge-base.html)
