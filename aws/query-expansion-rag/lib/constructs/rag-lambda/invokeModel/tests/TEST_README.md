# BedrockUsageTracker ユニットテスト

## 概要

`test_bedrock_usage_tracker.py` は、`bedrock_usage_tracker.py` の機能を検証するユニットテストです。このテストは、想定される入出力を例示し、使用方法を理解するためのドキュメントとしても機能します。

## テストの実行方法

```bash
# ユニットテストを実行
python3 -m unittest test_bedrock_usage_tracker.py -v

# 特定のテストクラスのみ実行
python3 -m unittest test_bedrock_usage_tracker.TestBedrockUsageTracker -v

# 特定のテストケースのみ実行
python3 -m unittest test_bedrock_usage_tracker.TestBedrockUsageTracker.test_get_usage_summary_single_model -v
```

## テストケース一覧

### 1. PricingUnit Enum のテスト

#### `test_pricing_unit_thousand`
- **目的**: 1000単位の料金単位が正しく定義されているか
- **検証内容**:
  - `PricingUnit.THOUSAND.value_int` が 1000
  - `PricingUnit.THOUSAND.value_str` が "1K"

#### `test_pricing_unit_million`
- **目的**: 1000000単位の料金単位が正しく定義されているか
- **検証内容**:
  - `PricingUnit.MILLION.value_int` が 1000000
  - `PricingUnit.MILLION.value_str` が "1M"

#### `test_from_int_*`
- **目的**: 整数値から PricingUnit を取得できるか
- **検証内容**:
  - `PricingUnit.from_int(1000)` が `PricingUnit.THOUSAND`
  - `PricingUnit.from_int(1_000_000)` が `PricingUnit.MILLION`
  - 未知の値の場合は `None` を返す

### 2. UsageRecord dataclass のテスト

#### `test_usage_record_creation`
- **目的**: UsageRecord が正しく作成されるか
- **入力例**:
  ```python
  UsageRecord(
      model_id="anthropic.claude-3-haiku-20240307-v1:0",
      usage={
          'inputTokens': 100,
          'outputTokens': 50,
          'totalTokens': 150
      }
  )
  ```

### 3. BedrockUsageTracker のテスト

#### `test_initialization`
- **目的**: 初期化時に modelPricing.json を読み込むか
- **検証内容**: `_model_pricing` が正しく設定される

#### `test_add_usage_single_request`
- **目的**: 単一のリクエストの usage を追加できるか
- **入力例**:
  ```python
  tracker.add_usage(
      model_id="anthropic.claude-3-haiku-20240307-v1:0",
      usage={'inputTokens': 100, 'outputTokens': 50, 'totalTokens': 150}
  )
  ```

#### `test_add_usage_multiple_requests`
- **目的**: 複数のリクエストの usage を追加できるか
- **入力例**:
  - 同じモデルで2回
  - 異なるモデルで1回

#### `test_add_usage_with_cache_tokens`
- **目的**: キャッシュトークンを含む usage を追加できるか
- **入力例**:
  ```python
  tracker.add_usage(
      model_id="jp.anthropic.claude-sonnet-4-5-20250929-v1:0",
      usage={
          'inputTokens': 1000,
          'outputTokens': 500,
          'totalTokens': 1500,
          'cacheReadInputTokens': 200,
          'cacheWriteInputTokens': 100
      }
  )
  ```

#### `test_get_usage_summary_single_model`
- **目的**: 単一モデルの usage サマリーを取得できるか
- **入力例**:
  - モデル: claude-3-haiku
  - リクエスト数: 2回
  - inputTokens: 100 + 200 = 300
  - outputTokens: 50 + 100 = 150
- **期待される出力**:
  ```json
  {
    "modelVersion": "anthropic.claude-3-haiku-20240307-v1:0",
    "requestCount": 2,
    "tokens": {
      "inputTokens": 300,
      "outputTokens": 150,
      "totalTokens": 450
    },
    "estimatedCostInfo": {
      "estimatedCost": 0.0002625,
      "currency": "USD",
      "inputTokens": 300,
      "inputToken1KUnitPrice": 0.00025,
      "outputTokens": 150,
      "outputToken1KUnitPrice": 0.00125
    }
  }
  ```
- **コスト計算**:
  ```
  inputCost = (300 / 1000) * 0.00025 = 0.000075
  outputCost = (150 / 1000) * 0.00125 = 0.0001875
  totalCost = 0.0002625 USD
  ```

#### `test_get_usage_summary_multiple_models`
- **目的**: 複数モデルの usage サマリーを取得できるか
- **入力例**:
  - モデル1: claude-3-haiku (2回リクエスト)
  - モデル2: claude-sonnet-4-5 (1回リクエスト)
- **期待される出力**: 2つのモデルのサマリーが返される

#### `test_get_usage_summary_with_cache_tokens`
- **目的**: キャッシュトークンを含む usage サマリーを取得できるか
- **入力例**:
  - モデル: claude-sonnet-4-5
  - inputTokens: 1000
  - outputTokens: 500
  - cacheReadInputTokens: 200
  - cacheWriteInputTokens: 100
- **期待される出力**:
  ```json
  {
    "estimatedCostInfo": {
      "inputTokens": 1300,
      "outputTokens": 500,
      "estimatedCost": 0.012028500000000001
    }
  }
  ```
- **コスト計算**:
  ```
  inputCost = (1000/1000)*0.0033 + (200/1000)*0.00033 + (100/1000)*0.004125 = 0.0037785
  outputCost = (500/1000)*0.0165 = 0.00825
  totalCost = 0.0120285 USD
  ```

#### `test_thread_safety`
- **目的**: 複数スレッドから同時に `add_usage` を呼んでも安全か
- **検証内容**:
  - 10個のスレッドから同時に10回ずつ `add_usage` を呼ぶ
  - 合計100回の呼び出しが正しく記録される

#### `test_empty_usage_summary`
- **目的**: usage が追加されていない場合の空のサマリー
- **期待される出力**: 空の配列 `[]`

#### `test_add_usage_with_none_values`
- **目的**: None や Falsy な値で `add_usage` を呼んだ場合は無視される
- **検証内容**:
  ```python
  tracker.add_usage(None, {'inputTokens': 100})  # 無視される
  tracker.add_usage("model-id", None)            # 無視される
  tracker.add_usage("", {'inputTokens': 100})    # 無視される
  ```

## 想定される入出力の例

### 例1: 単一モデル、複数リクエスト

**入力:**
```python
tracker = BedrockUsageTracker()

# 1回目のリクエスト
tracker.add_usage(
    model_id="anthropic.claude-3-haiku-20240307-v1:0",
    usage={'inputTokens': 100, 'outputTokens': 50, 'totalTokens': 150}
)

# 2回目のリクエスト
tracker.add_usage(
    model_id="anthropic.claude-3-haiku-20240307-v1:0",
    usage={'inputTokens': 200, 'outputTokens': 100, 'totalTokens': 300}
)

summary = tracker.get_usage_summary()
```

**出力:**
```json
[
  {
    "modelVersion": "anthropic.claude-3-haiku-20240307-v1:0",
    "requestCount": 2,
    "tokens": {
      "inputTokens": 300,
      "outputTokens": 150,
      "totalTokens": 450
    },
    "estimatedCostInfo": {
      "estimatedCost": 0.0002625,
      "currency": "USD",
      "inputTokens": 300,
      "inputToken1KUnitPrice": 0.00025,
      "outputTokens": 150,
      "outputToken1KUnitPrice": 0.00125
    }
  }
]
```

### 例2: 複数モデル

**入力:**
```python
tracker = BedrockUsageTracker()

# claude-3-haiku
tracker.add_usage(
    model_id="anthropic.claude-3-haiku-20240307-v1:0",
    usage={'inputTokens': 100, 'outputTokens': 50, 'totalTokens': 150}
)

# claude-sonnet-4-5
tracker.add_usage(
    model_id="jp.anthropic.claude-sonnet-4-5-20250929-v1:0",
    usage={'inputTokens': 500, 'outputTokens': 300, 'totalTokens': 800}
)

summary = tracker.get_usage_summary()
```

**出力:**
```json
[
  {
    "modelVersion": "anthropic.claude-3-haiku-20240307-v1:0",
    "requestCount": 1,
    "tokens": {...},
    "estimatedCostInfo": {...}
  },
  {
    "modelVersion": "jp.anthropic.claude-sonnet-4-5-20250929-v1:0",
    "requestCount": 1,
    "tokens": {...},
    "estimatedCostInfo": {...}
  }
]
```

### 例3: キャッシュトークンを含む

**入力:**
```python
tracker = BedrockUsageTracker()

tracker.add_usage(
    model_id="jp.anthropic.claude-sonnet-4-5-20250929-v1:0",
    usage={
        'inputTokens': 1000,
        'outputTokens': 500,
        'totalTokens': 1500,
        'cacheReadInputTokens': 200,
        'cacheWriteInputTokens': 100
    }
)

summary = tracker.get_usage_summary()
```

**出力:**
```json
[
  {
    "modelVersion": "jp.anthropic.claude-sonnet-4-5-20250929-v1:0",
    "requestCount": 1,
    "tokens": {
      "inputTokens": 1000,
      "outputTokens": 500,
      "totalTokens": 1500,
      "cacheReadInputTokens": 200,
      "cacheWriteInputTokens": 100
    },
    "estimatedCostInfo": {
      "estimatedCost": 0.012028500000000001,
      "currency": "USD",
      "inputTokens": 1300,
      "inputToken1KUnitPrice": 0.0033,
      "outputTokens": 500,
      "outputToken1KUnitPrice": 0.0165
    }
  }
]
```

## テスト結果

```
----------------------------------------------------------------------
Ran 19 tests in 0.007s

OK
```

全19個のテストケースが成功しました。
