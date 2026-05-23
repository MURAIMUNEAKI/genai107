# core/ ディレクトリ

このディレクトリには**RAGアプリケーションのコアロジック**を配置します。

## 📋 配置基準

以下の特徴を持つモジュールは `core/` に配置してください:

### ✅ 配置すべきもの

- **RAGの主要機能**
  - クエリ拡張、検索、評価、回答生成等
  - アプリケーションの中核となるビジネスロジック

- **ドメインロジック**
  - RAG固有の処理フロー
  - プロンプトエンジニアリング
  - 回答品質の向上ロジック

- **機能間の調整・統合**
  - 複数のservicesを組み合わせた処理
  - ワークフロー制御

### ❌ 配置すべきでないもの

- 外部API呼び出しの詳細 → `services/` へ
- 汎用的なユーティリティ → `utils/` へ
- エントリーポイント → `app.py` へ

## 📁 現在のファイル

### `query_expansion.py`
**役割:** ユーザーの質問を複数の検索クエリに拡張

**主な機能:**
- 質問を複数の異なる表現に変換
- LLMを使用したクエリ生成
- 検索の網羅性向上

```python
from core.query_expansion import expand_query

# 使用例
expanded_queries = expand_query(
    bedrock_client=bedrock,
    user_question="フレックスタイム制について教えてください",
    n_queries=3,
    model_id="anthropic.claude-3-haiku-20240307-v1:0"
)
# -> ["フレックスタイム制の概要", "勤務時間の柔軟性", "コアタイムの規定"]
```

**配置理由:**
- ✅ RAG固有のドメインロジック
- ✅ プロンプトエンジニアリングを含む
- ✅ アプリケーションの中核機能

**依存関係:**
- `services.converse_helper`: LLM呼び出し
- `config.config_manager`: 設定読み込み
- `utils.utils`: エラーハンドリング

---

### `kb_retrieve_and_rating.py`
**役割:** ナレッジベース検索と関連性評価

**主な機能:**
- 複数クエリでの並列検索
- 検索結果の関連性評価（rating）
- 結果の統合とフィルタリング

```python
from core.kb_retrieve_and_rating import invoke_retrives

# 使用例
kb_responses = invoke_retrives(
    bedrock_agent_client=bedrock_agent,
    queries=["クエリ1", "クエリ2", "クエリ3"],
    kb_id="KB123456",
    user_question="元の質問"
)
# -> [KBResponse(content="...", rating=4), ...]
```

**配置理由:**
- ✅ RAG検索の中核プロセス
- ✅ 複数サービスを統合（検索 + 評価）
- ✅ ビジネスロジック（関連性判断）

**依存関係:**
- `services.kb_response_processor`: KB応答処理
- `services.converse_helper`: 関連性評価
- `config.config_manager`: 検索設定
- `utils.utils`: エラーハンドリング

---

### `answer_generation.py`
**役割:** 検索結果を基に最終的な回答を生成

**主な機能:**
- RAG（Retrieval-Augmented Generation）
- コンテキストと質問を組み合わせたプロンプト構築
- LLMによる回答生成

```python
from core.answer_generation import generate_answer

# 使用例
answer = generate_answer(
    bedrock_client=bedrock,
    kb_responses=[...],
    user_question="フレックスタイム制について教えてください",
    file_content_blocks=[],
    model_id="anthropic.claude-3-5-sonnet-20241022-v2:0"
)
# -> "フレックスタイム制は..."
```

**配置理由:**
- ✅ RAGの最終工程
- ✅ プロンプトエンジニアリング
- ✅ アプリケーションの出力品質を決定

**依存関係:**
- `services.kb_response_processor`: KB応答抽出
- `services.converse_helper`: LLM呼び出し
- `config.config_manager`: 回答生成設定
- `utils.utils`: エラーハンドリング

---

### `reference_generation.py`
**役割:** 参考情報（出典）の生成

**主な機能:**
- 検索結果から出典情報を抽出
- 参照リストのフォーマット

```python
from core.reference_generation import generate_reference

# 使用例
references = generate_reference(kb_responses)
# -> "参考:\n- ドキュメント1 (スコア: 0.95)\n- ドキュメント2 (スコア: 0.87)"
```

**配置理由:**
- ✅ RAG回答の品質保証機能
- ✅ ドメイン固有のフォーマット
- ✅ 出典の透明性を提供

**依存関係:**
- `services.kb_response_processor`: KB応答データ構造

---

## 🔄 RAG処理フロー

core/ ディレクトリのモジュールは以下の順序で実行されます:

```
1. query_expansion.py
   ↓ [拡張されたクエリ]

2. kb_retrieve_and_rating.py
   ↓ [評価済み検索結果]

3. answer_generation.py
   ↓ [生成された回答]

4. reference_generation.py
   ↓ [参考情報付き回答]
```

このフローは `app.py` で統合されます。

## 💡 設計原則

### 1. 単一責任の原則
各モジュールは1つの明確な責任を持つ:
- `query_expansion`: クエリ拡張のみ
- `kb_retrieve_and_rating`: 検索と評価のみ
- `answer_generation`: 回答生成のみ

### 2. 依存性逆転の原則
core/ は具体的な実装（services/）に依存するが、インターフェースは core/ が定義:
```python
# core/ がインターフェースを定義
def generate_answer(bedrock_client, kb_responses, ...):
    # services/ の具体実装を使用
    response = converse_helper.invoke_converse_simple(...)
```

### 3. 設定による柔軟性
ハードコードを避け、設定ファイルから読み込む:
```python
from config.config_manager import ConfigManager

config = ConfigManager('answer_generation')
system_prompt = config.get('systemPrompt')
temperature = config.get('temperature')
```

## 🔍 判断フローチャート

新しいモジュールを追加する際の判断基準:

```
RAG処理の一部か？
  ├─ YES
  │   ├─ 主要な機能（検索、生成等）？
  │   │   ├─ YES → core/ に配置 ✅
  │   │   └─ NO → services/ を検討
  │   └─ 複数のservicesを統合する？
  │       ├─ YES → core/ に配置 ✅
  │       └─ NO → services/ を検討
  └─ NO
      ├─ 外部API呼び出し？
      │   ├─ YES → services/ へ
      │   └─ NO → utils/ へ
```

## 🧪 テスト

core/ のモジュールは外部サービスに依存するため、統合テストとモックテストの両方が必要:

```python
# tests/test_query_expansion.py
from unittest.mock import Mock, patch
from core.query_expansion import expand_query

def test_expand_query_with_mock():
    """モックを使用した単体テスト"""
    mock_client = Mock()
    mock_client.converse.return_value = {
        "output": {"message": {"content": [{"text": "クエリ1\nクエリ2"}]}}
    }

    queries = expand_query(mock_client, "質問", 2, "model-id")
    assert len(queries) == 2
```

## 📊 パフォーマンス考慮事項

### 並列処理
KB検索は複数クエリで並列実行:
```python
# kb_retrieve_and_rating.py
with ThreadPoolExecutor(max_workers=5) as executor:
    futures = [executor.submit(retrieve, q) for q in queries]
```

### キャッシング
将来的に検討すべき最適化:
- クエリ拡張結果のキャッシュ
- KB検索結果のキャッシュ
- LLM応答のキャッシュ（同一質問の場合）

## 📚 関連ドキュメント

- [RAG (Retrieval-Augmented Generation) 概要](https://aws.amazon.com/jp/what-is/retrieval-augmented-generation/)
- [プロンプトエンジニアリング ガイド](https://docs.anthropic.com/claude/docs/prompt-engineering)
- [Knowledge Base 検索の最適化](https://docs.aws.amazon.com/bedrock/latest/userguide/kb-test-config.html)
