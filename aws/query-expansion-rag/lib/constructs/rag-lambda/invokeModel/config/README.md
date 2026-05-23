# config/ ディレクトリ

このディレクトリには**設定管理**に関するモジュールを配置します。

## 📋 配置基準

以下の特徴を持つモジュールは `config/` に配置してください:

### ✅ 配置すべきもの

- **設定ファイルの読み込み・管理**
  - TOML、JSON、YAML等の設定ファイル読み込み
  - デフォルト設定とアプリ固有設定のマージ

- **設定のデータ構造定義**
  - 型定義、データクラス
  - 設定項目の型安全性

- **設定データ**
  - モデル価格情報
  - プロンプトテンプレート
  - その他の静的設定データ

### ❌ 配置すべきでないもの

- ビジネスロジック → `core/` へ
- 外部API呼び出し → `services/` へ
- 汎用ユーティリティ → `utils/` へ

## 📁 現在のファイル

### `config_manager.py`
**役割:** 設定ファイルの読み込みと管理

**主な機能:**
- デフォルト設定とアプリ固有設定の読み込み
- 階層的な設定のマージ
- 環境変数ベースの設定切り替え

```python
from config.config_manager import ConfigManager

# 使用例
config = ConfigManager('answer_generation')

# デフォルト設定の取得
model_id = config.default_config.get('modelId')

# アプリ固有設定の取得（オーバーライド）
system_prompt = config.get('systemPrompt')
temperature = config.get('temperature', default=0.0)
```

**配置理由:**
- ✅ 設定ファイル読み込みという明確な責任
- ✅ アプリケーション全体で使用される共通機能
- ✅ ビジネスロジックを含まない

**設定ファイル階層:**
```
config/
├── defaults/               # デフォルト設定（雛形）
│   ├── answer_generation.toml
│   ├── query_expansion.toml
│   └── ...
└── apps/                   # アプリ固有設定（差分）
    └── qerag.toml          # デフォルトを上書き
```

**設定のマージロジック:**
1. `defaults/{config_type}.toml` を読み込み
2. `apps/{APP_PARAM_FILE}` を読み込み
3. アプリ固有設定でデフォルトを上書き
4. 環境変数 `APP_PARAM_FILE` で設定ファイルを切り替え

---

### `config_types.py`
**役割:** 設定のデータ構造定義

**主な内容:**
- 設定項目の型定義
- データクラス、Enum等
- 設定の妥当性検証

```python
from config.config_types import ConfigType

# 使用例（将来的な拡張）
from dataclasses import dataclass
from enum import Enum

class ModelProvider(Enum):
    ANTHROPIC = "anthropic"
    AMAZON = "amazon"

@dataclass
class AnswerGenerationConfig:
    model_id: str
    temperature: float
    max_tokens: int
    system_prompt: str
```

**配置理由:**
- ✅ 設定の型安全性を提供
- ✅ 設定項目の明示的な定義
- ✅ IDEの補完サポート

---

### `modelPricing.json`
**役割:** Bedrockモデルの価格情報

**データ構造:**
```json
{
  "anthropic.claude-3-5-sonnet-20241022-v2:0": {
    "input": 0.003,    // 入力1000トークンあたりの価格（USD）
    "output": 0.015    // 出力1000トークンあたりの価格（USD）
  },
  "anthropic.claude-3-haiku-20240307-v1:0": {
    "input": 0.00025,
    "output": 0.00125
  }
}
```

**使用例:**
```python
from config.config_manager import ConfigManager
import json

# モデル価格の読み込み
with open('config/modelPricing.json') as f:
    pricing = json.load(f)

model_id = "anthropic.claude-3-5-sonnet-20241022-v2:0"
input_price = pricing[model_id]["input"]
output_price = pricing[model_id]["output"]

# コスト計算
input_tokens = 1000
output_tokens = 500
cost = (input_tokens / 1000) * input_price + (output_tokens / 1000) * output_price
```

**配置理由:**
- ✅ 静的な設定データ
- ✅ ビジネスロジック（コスト計算）から分離
- ✅ 価格更新時に一箇所だけ修正すれば良い

---

## 🔧 設定ファイルの構造

### デフォルト設定例 (`defaults/answer_generation.toml`)

```toml
# モデル設定
modelId = "anthropic.claude-3-5-sonnet-20241022-v2:0"
temperature = 0.0
maxTokens = 4096

# システムプロンプト
systemPrompt = '''
あなたは優秀なアシスタントです。
提供された情報を元に、正確に回答してください。
'''

# その他の設定
streamingEnabled = false
stopSequences = []
```

### アプリ固有設定例 (`apps/qerag.toml`)

```toml
# アプリケーション名
name = "qerag"
description = "Query Expansion RAG"

# デフォルトを上書き
[answer_generation]
temperature = 0.1  # デフォルトの0.0を上書き
systemPrompt = '''
カスタムプロンプト...
'''

# レスポンスフッター
responseFooter = "※ この回答は生成AIにより作成されています。"
```

## 💡 設計原則

### 1. DRY原則（Don't Repeat Yourself）
共通設定はデフォルトに、差分のみアプリ固有設定に記述:

```toml
# ❌ 悪い例: apps/qerag.toml
modelId = "anthropic.claude-3-5-sonnet-20241022-v2:0"  # デフォルトと同じ
temperature = 0.1  # 変更箇所
maxTokens = 4096   # デフォルトと同じ

# ✅ 良い例: apps/qerag.toml
temperature = 0.1  # 変更箇所のみ
```

### 2. 型安全性
可能な限り型定義を使用:

```python
# ✅ 型安全
@dataclass
class Config:
    temperature: float  # 型が明確
    max_tokens: int

# ❌ 型が不明瞭
config = {"temperature": 0.1, "max_tokens": 4096}
```

### 3. 環境分離
環境（dev/stg/prd）ごとに設定を分離:

```python
# 環境変数で切り替え
APP_PARAM_FILE = os.environ.get('APP_PARAM_FILE', 'qerag.toml')
config = ConfigManager('answer_generation')
```

## 🔍 設定の優先順位

設定値は以下の優先順位で決定されます:

```
1. 環境変数（最優先）
   ↓
2. アプリ固有設定 (apps/*.toml)
   ↓
3. デフォルト設定 (defaults/*.toml)
   ↓
4. コード内デフォルト値（フォールバック）
```

実装例:
```python
# config_manager.py
def get(self, key: str, default=None):
    # 1. アプリ固有設定
    if key in self.app_config:
        return self.app_config[key]

    # 2. デフォルト設定
    if key in self.default_config:
        return self.default_config[key]

    # 3. フォールバック
    return default
```

## 🧪 テスト

設定の妥当性をテスト:

```python
# tests/test_config_manager.py
from config.config_manager import ConfigManager

def test_config_loading():
    """設定ファイルが正しく読み込まれることを確認"""
    config = ConfigManager('answer_generation')

    assert config.get('modelId') is not None
    assert isinstance(config.get('temperature'), (int, float))
    assert config.get('maxTokens') > 0

def test_config_override():
    """アプリ固有設定がデフォルトを上書きすることを確認"""
    config = ConfigManager('answer_generation')

    # アプリ固有設定で上書きされていることを確認
    # （具体的な値は apps/*.toml に依存）
    pass
```

## 📚 関連ドキュメント

- [TOML仕様](https://toml.io/ja/)
- [Python dataclasses](https://docs.python.org/ja/3/library/dataclasses.html)
- [12 Factor App: Config](https://12factor.net/ja/config)
