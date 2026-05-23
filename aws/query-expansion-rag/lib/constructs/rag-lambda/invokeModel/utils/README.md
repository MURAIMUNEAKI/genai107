# utils/ ディレクトリ

このディレクトリには**汎用的なユーティリティ関数**を配置します。

## 📋 配置基準

以下の特徴を持つモジュールは `utils/` に配置してください:

### ✅ 配置すべきもの

- **プロジェクト間で再利用可能な機能**
  - 他のプロジェクトにコピーしても使える汎用性
  - 特定のビジネスドメインに依存しない

- **ステートレスな関数**
  - 状態を持たない純粋関数
  - 同じ入力に対して常に同じ出力

- **標準的なデータ変換**
  - 配列変換、文字列処理、日付フォーマット等
  - 一般的なアルゴリズムの実装

- **技術的なヘルパー関数**
  - ファイル処理、エンコード/デコード
  - バリデーション、ログ出力補助

### ❌ 配置すべきでないもの

- 外部サービスと通信する処理 → `services/` へ
- RAG固有のビジネスロジック → `core/` へ
- 設定ファイルの読み込み → `config/` へ

## 📁 現在のファイル

### `utils.py`
**役割:** 汎用的なユーティリティ関数の集合

**主な関数:**

#### `convertToArray(value)`
任意の値を配列に変換

```python
from utils.utils import convertToArray

# 使用例
convertToArray("single")      # -> ["single"]
convertToArray(["a", "b"])    # -> ["a", "b"]
convertToArray(None)          # -> []
```

**配置理由:**
- ✅ プロジェクト間で再利用可能
- ✅ ビジネスロジックを含まない
- ✅ ステートレスな純粋関数

#### `replacePlaceholders(text, variables)`
文字列内のプレースホルダーを変数で置換

```python
from utils.utils import replacePlaceholders

# 使用例
template = "Hello {{name}}, you have {{count}} messages"
variables = {"name": "Alice", "count": 5}
result = replacePlaceholders(template, variables)
# -> "Hello Alice, you have 5 messages"
```

**配置理由:**
- ✅ 文字列処理という汎用機能
- ✅ 外部サービスに依存しない
- ✅ どのプロジェクトでも使える

#### `handleException(e, logger)`
例外を適切にログ出力し、ユーザーフレンドリーなエラーメッセージを返す

```python
from utils.utils import handleException

# 使用例
try:
    # 何か処理
    pass
except Exception as e:
    error_message = handleException(e, logger)
    return {"error": error_message}
```

**配置理由:**
- ✅ エラーハンドリングという汎用的な処理
- ✅ ビジネスロジックを含まない
- ✅ ログ出力の補助機能

---

### `file_handler.py`
**役割:** ファイル処理関連のユーティリティ

**主な関数:**

#### `process_files(files_input)`
ファイル配列を処理し、Bedrock APIで使用可能な形式に変換

```python
from utils.file_handler import process_files

# 使用例
files = [
    {
        "name": "document.pdf",
        "data": "base64_encoded_data...",
        "type": "application/pdf"
    }
]
content_blocks = process_files(files)
```

**配置理由:**
- ✅ ファイル処理という技術的な機能
- ✅ base64エンコード/デコードという汎用処理
- ✅ ファイルバリデーション（サイズ、形式チェック）

#### `truncate_files_for_logging(inputs)`
ログ出力用にファイルデータを省略

```python
from utils.file_handler import truncate_files_for_logging

# 使用例
truncated = truncate_files_for_logging({
    "question": "質問",
    "files": [{"data": "very_long_base64_string..."}]
})
logger.info(f"Input: {truncated}")
```

**配置理由:**
- ✅ ログ出力補助という技術的な機能
- ✅ データのトランケーション（切り詰め）という汎用処理

## 🔍 判断フローチャート

新しいモジュールを追加する際の判断基準:

```
外部サービスに依存する？
  ├─ YES → services/ に配置
  └─ NO
      ├─ ビジネスロジックを含む？
      │   ├─ YES → core/ に配置
      │   └─ NO
      │       ├─ 他のプロジェクトでも使える？
      │       │   ├─ YES → utils/ に配置 ✅
      │       │   └─ NO → 別のディレクトリを検討
      │       └─ ステートレスな純粋関数？
      │           ├─ YES → utils/ に配置 ✅
      │           └─ NO → services/ または core/ を検討
```

## 💡 命名規則

### ファイル名
- **機能ごとにファイルを分ける**
  - `file_handler.py`: ファイル処理関連
  - `utils.py`: その他の汎用関数
  - `validators.py`: バリデーション関数（将来追加する場合）

### 関数名
- **動詞始まり、何をするかが明確**
  - `convert`, `replace`, `validate`, `format`, `parse` 等
  - 例: `convertToArray()`, `replacePlaceholders()`, `validateEmail()`

### 関数の設計原則
- **単一責任の原則**: 1つの関数は1つのことだけを行う
- **純粋関数**: 副作用なし、同じ入力→同じ出力
- **型ヒント**: 引数と戻り値の型を明示

```python
# Good example
def convertToArray(value: any) -> list:
    """任意の値を配列に変換する純粋関数"""
    if isinstance(value, list):
        return value
    return [value] if value is not None else []
```

## 🧪 テスト

utils/ のモジュールは外部依存が少ないため、単体テストが容易:

```python
# tests/test_utils.py
from utils.utils import convertToArray, replacePlaceholders

def test_convert_to_array():
    assert convertToArray("single") == ["single"]
    assert convertToArray(["a", "b"]) == ["a", "b"]
    assert convertToArray(None) == []

def test_replace_placeholders():
    template = "Hello {{name}}"
    result = replacePlaceholders(template, {"name": "Alice"})
    assert result == "Hello Alice"
```

## 📦 utils/ と services/ の違い

| 観点 | utils/ | services/ |
|------|--------|-----------|
| **依存関係** | 標準ライブラリ中心 | AWS SDK、外部API |
| **ドメイン知識** | 不要 | 必要（RAG、Bedrock等） |
| **状態** | ステートレス | 状態を持つ場合あり |
| **再利用性** | プロジェクト間で再利用可 | プロジェクト固有 |
| **テスト** | モック不要 | モック必要 |

### 具体例

```python
# ✅ utils/ に配置
def format_timestamp(timestamp: int) -> str:
    """タイムスタンプを人間が読める形式に変換"""
    # 汎用的、ステートレス、再利用可能
    pass

# ❌ utils/ に配置すべきでない
def track_bedrock_usage(model_id: str, tokens: int):
    """Bedrockの使用量を追跡"""
    # Bedrock固有、状態を持つ → services/ へ
    pass
```

## 📚 参考

- [Python標準ライブラリ](https://docs.python.org/ja/3/library/)
- [クリーンコード原則](https://qiita.com/baby-degu/items/d058a62f145235a0f007)
