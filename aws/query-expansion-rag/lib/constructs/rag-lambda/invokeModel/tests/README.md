# tests/ ディレクトリ

このディレクトリには**テストコード**を配置します。

## 📋 配置基準

以下のファイルは `tests/` に配置してください:

### ✅ 配置すべきもの

- **単体テスト**
  - 個々の関数・クラスのテスト
  - モックを使用したテスト

- **統合テスト**
  - 複数モジュールの連携テスト
  - 外部サービスとの統合テスト

- **テストヘルパー・フィクスチャ**
  - テストデータ生成
  - テスト用のモック

- **テストドキュメント**
  - テスト方法の説明
  - テストケースの一覧

### ❌ 配置すべきでないもの

- 本番コード → 他のディレクトリへ
- テスト用設定 → `config/` の test用サブディレクトリへ

## 📁 現在のファイル

### `test_bedrock_usage_tracker.py`
**役割:** BedrockUsageTrackerクラスの単体テスト

**テスト内容:**
- 使用量追跡の正確性
- コスト計算の正確性
- エラーハンドリング
- マルチスレッド対応

```python
# tests/test_bedrock_usage_tracker.py の一部
import unittest
from services.bedrock_usage_tracker import BedrockUsageTracker

class TestBedrockUsageTracker(unittest.TestCase):
    def setUp(self):
        """各テストの前に実行"""
        self.tracker = BedrockUsageTracker()

    def test_track_model_usage(self):
        """モデル使用量の追跡をテスト"""
        self.tracker.track_model_usage(
            model_id="anthropic.claude-3-haiku-20240307-v1:0",
            input_tokens=100,
            output_tokens=200
        )
        summary = self.tracker.get_usage_summary()
        self.assertIsNotNone(summary)

    def test_cost_calculation(self):
        """コスト計算の正確性をテスト"""
        # テストコード...
        pass
```

**実行方法:**
```bash
# カレントディレクトリで実行
python -m pytest tests/test_bedrock_usage_tracker.py

# または unittest で実行
python -m unittest tests.test_bedrock_usage_tracker
```

---

### `TEST_README.md`
**役割:** テストに関する詳細ドキュメント

- テストの実行方法
- テストケースの説明
- モックの使用方法

---

## 🧪 テスト戦略

### テストピラミッド

このプロジェクトのテスト戦略:

```
        /\
       /  \     E2E Tests (少数)
      /----\
     /      \   Integration Tests (中程度)
    /--------\
   /          \ Unit Tests (多数)
  /------------\
```

### 1. 単体テスト (Unit Tests)
**対象:** 個々の関数・クラス
**モック:** 外部依存をモック化
**実行速度:** 高速

```python
# 単体テストの例
from unittest.mock import Mock, patch
from utils.utils import convertToArray

def test_convert_to_array():
    """convertToArray関数の単体テスト"""
    assert convertToArray("single") == ["single"]
    assert convertToArray(["a", "b"]) == ["a", "b"]
    assert convertToArray(None) == []
```

### 2. 統合テスト (Integration Tests)
**対象:** 複数モジュールの連携
**モック:** 外部サービスのみモック化
**実行速度:** 中速

```python
# 統合テストの例
from unittest.mock import Mock
from core.query_expansion import expand_query
from config.config_manager import ConfigManager

def test_query_expansion_integration():
    """クエリ拡張の統合テスト"""
    mock_client = Mock()
    # Bedrockのレスポンスをモック
    mock_client.converse.return_value = {...}

    # 実際の設定ファイルを使用
    config = ConfigManager('query_expansion')

    queries = expand_query(mock_client, "質問", 3, config.get('modelId'))
    assert len(queries) == 3
```

### 3. E2Eテスト (End-to-End Tests)
**対象:** アプリケーション全体
**モック:** なし（実際のAWS環境）
**実行速度:** 低速

```python
# E2Eテストの例（将来実装）
def test_full_rag_pipeline():
    """RAGパイプライン全体のE2Eテスト"""
    # 実際のBedrockとKBを使用
    event = {
        "body": json.dumps({
            "inputs": {"question": "フレックスタイム制について"}
        })
    }
    response = lambda_handler(event, None)
    assert response["statusCode"] == 200
```

## 🎯 テストカバレッジ目標

| ディレクトリ | 目標カバレッジ |
|-------------|---------------|
| `core/` | 80%以上 |
| `services/` | 70%以上 |
| `config/` | 90%以上 |
| `utils/` | 90%以上 |

カバレッジ測定:
```bash
# pytest-cov を使用
pytest --cov=. --cov-report=html tests/

# または coverage を使用
coverage run -m pytest tests/
coverage report
coverage html
```

## 🛠️ テスト環境のセットアップ

### 必要なパッケージ

```bash
# requirements-dev.txt
pytest>=7.0.0
pytest-cov>=4.0.0
pytest-mock>=3.10.0
moto>=4.0.0  # AWSサービスのモック
```

インストール:
```bash
pip install -r requirements-dev.txt
```

### 環境変数の設定

```bash
# テスト用環境変数
export APP_NAME="test-app"
export APP_PARAM_FILE="test.toml"
export KNOWLEDGE_BASE_ID="TEST_KB_ID"
export AWS_DEFAULT_REGION="ap-northeast-1"
```

## 💡 テスト作成のベストプラクティス

### 1. AAA パターン
**Arrange-Act-Assert** パターンを使用:

```python
def test_example():
    # Arrange: テストデータの準備
    tracker = BedrockUsageTracker()
    model_id = "test-model"

    # Act: テスト対象の実行
    tracker.track_model_usage(model_id, 100, 200)

    # Assert: 結果の検証
    summary = tracker.get_usage_summary()
    assert summary is not None
```

### 2. テスト名は明確に
テスト名から目的が分かるように:

```python
# ✅ 良い例
def test_track_model_usage_records_correct_token_counts():
    pass

def test_track_model_usage_raises_error_for_invalid_model_id():
    pass

# ❌ 悪い例
def test1():
    pass

def test_tracker():
    pass
```

### 3. モックは最小限に
必要な部分だけモック化:

```python
# ✅ 良い例: 外部API呼び出しのみモック
@patch('services.converse_helper.invoke_converse_simple')
def test_generate_answer(mock_converse):
    mock_converse.return_value = {"text": "回答"}
    # テストコード...

# ❌ 悪い例: 不要な部分までモック
@patch('config.config_manager.ConfigManager')
@patch('utils.utils.handleException')
@patch('services.converse_helper.invoke_converse_simple')
def test_generate_answer(mock_converse, mock_exception, mock_config):
    # モックが多すぎて管理が困難
    pass
```

### 4. テストは独立させる
各テストは他のテストに依存しない:

```python
# ✅ 良い例: setUp で初期化
class TestBedrockUsageTracker(unittest.TestCase):
    def setUp(self):
        self.tracker = BedrockUsageTracker()

    def test_first(self):
        # self.trackerを使用
        pass

    def test_second(self):
        # 独立したself.trackerを使用
        pass

# ❌ 悪い例: グローバル変数を共有
tracker = BedrockUsageTracker()

def test_first():
    tracker.track_model_usage(...)  # 状態を変更

def test_second():
    # test_first の影響を受ける
    pass
```

## 🚀 CI/CD での実行

### GitHub Actions の例

```yaml
# .github/workflows/test.yml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - uses: actions/setup-python@v4
        with:
          python-version: '3.13'
      - name: Install dependencies
        run: |
          pip install -r requirements.txt
          pip install -r requirements-dev.txt
      - name: Run tests
        run: |
          pytest tests/ --cov=. --cov-report=xml
      - name: Upload coverage
        uses: codecov/codecov-action@v3
```

## 📚 参考リソース

- [pytest Documentation](https://docs.pytest.org/)
- [unittest Documentation](https://docs.python.org/ja/3/library/unittest.html)
- [Python Testing Best Practices](https://realpython.com/pytest-python-testing/)
- [Mocking AWS Services with moto](https://github.com/getmoto/moto)
