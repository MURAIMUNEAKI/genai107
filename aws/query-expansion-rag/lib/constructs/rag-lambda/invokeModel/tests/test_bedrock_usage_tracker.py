"""
test_bedrock_usage_tracker.py

BedrockUsageTrackerのユニットテスト。
想定される入出力を例示し、機能の正確性を検証します。
"""

import json
import unittest
from unittest.mock import mock_open, patch

from services.bedrock_usage_tracker import (
    KEY_CURRENCY,
    KEY_ESTIMATED_COST,
    KEY_ESTIMATED_COST_INFO,
    KEY_MODEL_VERSION,
    KEY_REQUEST_COUNT,
    KEY_TOKENS,
    BedrockUsageTracker,
    PricingUnit,
    UsageRecord,
)


class TestPricingUnit(unittest.TestCase):
    """PricingUnit Enumのテスト"""

    def test_pricing_unit_thousand(self):
        """1000単位の料金単位が正しく定義されているか"""
        unit = PricingUnit.THOUSAND
        self.assertEqual(unit.value_int, 1000)
        self.assertEqual(unit.value_str, "1K")

    def test_pricing_unit_million(self):
        """1000000単位の料金単位が正しく定義されているか"""
        unit = PricingUnit.MILLION
        self.assertEqual(unit.value_int, 1_000_000)
        self.assertEqual(unit.value_str, "1M")

    def test_from_int_thousand(self):
        """整数値からPricingUnitを取得（1000）"""
        unit = PricingUnit.from_int(1000)
        self.assertIsNotNone(unit)
        self.assertEqual(unit, PricingUnit.THOUSAND)

    def test_from_int_million(self):
        """整数値からPricingUnitを取得（1000000）"""
        unit = PricingUnit.from_int(1_000_000)
        self.assertIsNotNone(unit)
        self.assertEqual(unit, PricingUnit.MILLION)

    def test_from_int_unknown(self):
        """未知の整数値の場合はNoneを返す"""
        unit = PricingUnit.from_int(500)
        self.assertIsNone(unit)


class TestUsageRecord(unittest.TestCase):
    """UsageRecord dataclassのテスト"""

    def test_usage_record_creation(self):
        """UsageRecordが正しく作成されるか"""
        record = UsageRecord(
            model_id="anthropic.claude-3-haiku-20240307-v1:0",
            usage={"inputTokens": 100, "outputTokens": 50, "totalTokens": 150},
        )
        self.assertEqual(record.model_id, "anthropic.claude-3-haiku-20240307-v1:0")
        self.assertEqual(record.usage["inputTokens"], 100)
        self.assertEqual(record.usage["outputTokens"], 50)


class TestBedrockUsageTracker(unittest.TestCase):
    """BedrockUsageTrackerクラスのテスト"""

    def setUp(self):
        """各テストの前に実行される初期化処理"""
        # テスト用のmodelPricing.jsonデータ
        self.mock_pricing_data = {
            "anthropic.claude-3-haiku-20240307-v1:0": {
                "currency": "USD",
                "pricingUnit": 1000,
                "tokenCategories": {
                    "inputTokens": {"categoryName": "input", "unitPrice": 0.00025},
                    "outputTokens": {"categoryName": "output", "unitPrice": 0.00125},
                },
            },
            "jp.anthropic.claude-sonnet-4-5-20250929-v1:0": {
                "currency": "USD",
                "pricingUnit": 1000,
                "tokenCategories": {
                    "inputTokens": {"categoryName": "input", "unitPrice": 0.0033},
                    "outputTokens": {"categoryName": "output", "unitPrice": 0.0165},
                    "cacheReadInputTokens": {"categoryName": "input", "unitPrice": 0.00033},
                    "cacheWriteInputTokens": {"categoryName": "input", "unitPrice": 0.004125},
                },
            },
        }

    @patch("services.bedrock_usage_tracker.Path")
    @patch("builtins.open", new_callable=mock_open)
    def test_initialization(self, mock_file, mock_path):
        """初期化時にmodelPricing.jsonを読み込むか"""
        mock_file.return_value.read.return_value = json.dumps(self.mock_pricing_data)
        mock_path.return_value.parent.__truediv__.return_value = "modelPricing.json"

        with patch("json.load", return_value=self.mock_pricing_data):
            tracker = BedrockUsageTracker()
            self.assertIsNotNone(tracker._model_pricing)

    @patch("services.bedrock_usage_tracker.Path")
    def test_add_usage_single_request(self, mock_path):
        """単一のリクエストのusageを追加"""
        with patch("json.load", return_value=self.mock_pricing_data):
            tracker = BedrockUsageTracker()

            # 使用データを追加
            tracker.add_usage(
                model_id="anthropic.claude-3-haiku-20240307-v1:0",
                usage={"inputTokens": 100, "outputTokens": 50, "totalTokens": 150},
            )

            # 内部状態を確認
            self.assertEqual(len(tracker._usages), 1)
            self.assertEqual(tracker._usages[0].model_id, "anthropic.claude-3-haiku-20240307-v1:0")
            self.assertEqual(tracker._usages[0].usage["inputTokens"], 100)

    @patch("services.bedrock_usage_tracker.Path")
    def test_add_usage_multiple_requests(self, mock_path):
        """複数のリクエストのusageを追加"""
        with patch("json.load", return_value=self.mock_pricing_data):
            tracker = BedrockUsageTracker()

            # 1回目
            tracker.add_usage(
                model_id="anthropic.claude-3-haiku-20240307-v1:0",
                usage={"inputTokens": 100, "outputTokens": 50, "totalTokens": 150},
            )

            # 2回目
            tracker.add_usage(
                model_id="anthropic.claude-3-haiku-20240307-v1:0",
                usage={"inputTokens": 200, "outputTokens": 100, "totalTokens": 300},
            )

            # 3回目（別モデル）
            tracker.add_usage(
                model_id="jp.anthropic.claude-sonnet-4-5-20250929-v1:0",
                usage={"inputTokens": 500, "outputTokens": 300, "totalTokens": 800},
            )

            self.assertEqual(len(tracker._usages), 3)

    @patch("services.bedrock_usage_tracker.Path")
    def test_add_usage_with_cache_tokens(self, mock_path):
        """キャッシュトークンを含むusageを追加"""
        with patch("json.load", return_value=self.mock_pricing_data):
            tracker = BedrockUsageTracker()

            # キャッシュトークンを含むデータ
            tracker.add_usage(
                model_id="jp.anthropic.claude-sonnet-4-5-20250929-v1:0",
                usage={
                    "inputTokens": 1000,
                    "outputTokens": 500,
                    "totalTokens": 1500,
                    "cacheReadInputTokens": 200,
                    "cacheWriteInputTokens": 100,
                },
            )

            self.assertEqual(len(tracker._usages), 1)
            self.assertEqual(tracker._usages[0].usage["cacheReadInputTokens"], 200)
            self.assertEqual(tracker._usages[0].usage["cacheWriteInputTokens"], 100)

    def test_get_usage_summary_single_model(self):
        """単一モデルのusageサマリーを取得

        入力例:
            - モデル: claude-3-haiku
            - リクエスト数: 2回
            - inputTokens: 100 + 200 = 300
            - outputTokens: 50 + 100 = 150

        期待される出力:
            - requestCount: 2
            - tokens.inputTokens: 300
            - tokens.outputTokens: 150
            - estimatedCost: (300/1000)*0.00025 + (150/1000)*0.00125 = 0.0002625
        """
        with patch("builtins.open", mock_open(read_data=json.dumps(self.mock_pricing_data))):  # noqa: SIM117
            with patch("json.load", return_value=self.mock_pricing_data):
                tracker = BedrockUsageTracker()

                # 1回目
                tracker.add_usage(
                    model_id="anthropic.claude-3-haiku-20240307-v1:0",
                    usage={"inputTokens": 100, "outputTokens": 50, "totalTokens": 150},
                )

                # 2回目
                tracker.add_usage(
                    model_id="anthropic.claude-3-haiku-20240307-v1:0",
                    usage={"inputTokens": 200, "outputTokens": 100, "totalTokens": 300},
                )

                summary = tracker.get_usage_summary()

                # 検証
                self.assertEqual(len(summary), 1)
                self.assertEqual(summary[0][KEY_MODEL_VERSION], "anthropic.claude-3-haiku-20240307-v1:0")
                self.assertEqual(summary[0][KEY_REQUEST_COUNT], 2)
                self.assertEqual(summary[0][KEY_TOKENS]["inputTokens"], 300)
                self.assertEqual(summary[0][KEY_TOKENS]["outputTokens"], 150)
                self.assertEqual(summary[0][KEY_TOKENS]["totalTokens"], 450)

                # コスト計算の検証
                self.assertIn(KEY_ESTIMATED_COST_INFO, summary[0])
                cost_info = summary[0][KEY_ESTIMATED_COST_INFO]
                self.assertEqual(cost_info[KEY_CURRENCY], "USD")
                # 期待値: (300/1000)*0.00025 + (150/1000)*0.00125 = 0.075 + 0.1875 = 0.2625 / 1000
                expected_cost = (300 / 1000) * 0.00025 + (150 / 1000) * 0.00125
                self.assertAlmostEqual(cost_info[KEY_ESTIMATED_COST], expected_cost, places=6)

    @patch("services.bedrock_usage_tracker.Path")
    def test_get_usage_summary_multiple_models(self, mock_path):
        """複数モデルのusageサマリーを取得

        入力例:
            - モデル1: claude-3-haiku (2回リクエスト)
            - モデル2: claude-sonnet-4-5 (1回リクエスト)

        期待される出力:
            - 2つのモデルのサマリーが返される
            - 各モデルごとに集計されている
        """
        with patch("json.load", return_value=self.mock_pricing_data):
            tracker = BedrockUsageTracker()

            # claude-3-haiku: 1回目
            tracker.add_usage(
                model_id="anthropic.claude-3-haiku-20240307-v1:0",
                usage={"inputTokens": 100, "outputTokens": 50, "totalTokens": 150},
            )

            # claude-3-haiku: 2回目
            tracker.add_usage(
                model_id="anthropic.claude-3-haiku-20240307-v1:0",
                usage={"inputTokens": 200, "outputTokens": 100, "totalTokens": 300},
            )

            # claude-sonnet-4-5: 1回目
            tracker.add_usage(
                model_id="jp.anthropic.claude-sonnet-4-5-20250929-v1:0",
                usage={"inputTokens": 500, "outputTokens": 300, "totalTokens": 800},
            )

            summary = tracker.get_usage_summary()

            # 検証
            self.assertEqual(len(summary), 2)

            # モデルIDでソート
            summary_sorted = sorted(summary, key=lambda x: x[KEY_MODEL_VERSION])

            # claude-3-haiku
            haiku_summary = summary_sorted[0]
            self.assertEqual(haiku_summary[KEY_MODEL_VERSION], "anthropic.claude-3-haiku-20240307-v1:0")
            self.assertEqual(haiku_summary[KEY_REQUEST_COUNT], 2)
            self.assertEqual(haiku_summary[KEY_TOKENS]["inputTokens"], 300)

            # claude-sonnet-4-5
            sonnet_summary = summary_sorted[1]
            self.assertEqual(sonnet_summary[KEY_MODEL_VERSION], "jp.anthropic.claude-sonnet-4-5-20250929-v1:0")
            self.assertEqual(sonnet_summary[KEY_REQUEST_COUNT], 1)
            self.assertEqual(sonnet_summary[KEY_TOKENS]["inputTokens"], 500)

    def test_get_usage_summary_with_cache_tokens(self):
        """キャッシュトークンを含むusageサマリーを取得

        入力例:
            - モデル: claude-sonnet-4-5
            - inputTokens: 1000
            - outputTokens: 500
            - cacheReadInputTokens: 200
            - cacheWriteInputTokens: 100

        期待される出力:
            - estimatedCostInfo.inputTokens: 1000 + 200 + 100 = 1300
            - estimatedCostInfo.outputTokens: 500
            - コストが正しく計算される
        """
        with patch("builtins.open", mock_open(read_data=json.dumps(self.mock_pricing_data))):  # noqa: SIM117
            with patch("json.load", return_value=self.mock_pricing_data):
                tracker = BedrockUsageTracker()

                tracker.add_usage(
                    model_id="jp.anthropic.claude-sonnet-4-5-20250929-v1:0",
                    usage={
                        "inputTokens": 1000,
                        "outputTokens": 500,
                        "totalTokens": 1500,
                        "cacheReadInputTokens": 200,
                        "cacheWriteInputTokens": 100,
                    },
                )

                summary = tracker.get_usage_summary()

                # 検証
                self.assertEqual(len(summary), 1)
                self.assertIn(KEY_ESTIMATED_COST_INFO, summary[0])
                cost_info = summary[0][KEY_ESTIMATED_COST_INFO]

                # inputカテゴリのトークン合計: 1000 + 200 + 100 = 1300
                self.assertEqual(cost_info["inputTokens"], 1300)
                # outputカテゴリのトークン合計: 500
                self.assertEqual(cost_info["outputTokens"], 500)

                # コスト計算
                # input: (1000/1000)*0.0033 + (200/1000)*0.00033 + (100/1000)*0.004125 = 3.7785/1000
                # output: (500/1000)*0.0165 = 8.25/1000
                # total: 12.0285/1000
                expected_cost = (
                    (1000 / 1000) * 0.0033 + (200 / 1000) * 0.00033 + (100 / 1000) * 0.004125 + (500 / 1000) * 0.0165
                )
                self.assertAlmostEqual(cost_info[KEY_ESTIMATED_COST], expected_cost, places=6)

    @patch("services.bedrock_usage_tracker.Path")
    def test_format_pricing_unit_1k(self, mock_path):
        """料金単位のフォーマット（1K）"""
        with patch("json.load", return_value=self.mock_pricing_data):
            tracker = BedrockUsageTracker()
            formatted = tracker._format_pricing_unit(1000)
            self.assertEqual(formatted, "1K")

    @patch("services.bedrock_usage_tracker.Path")
    def test_format_pricing_unit_1m(self, mock_path):
        """料金単位のフォーマット（1M）"""
        with patch("json.load", return_value=self.mock_pricing_data):
            tracker = BedrockUsageTracker()
            formatted = tracker._format_pricing_unit(1_000_000)
            self.assertEqual(formatted, "1M")

    @patch("services.bedrock_usage_tracker.Path")
    def test_format_pricing_unit_unknown(self, mock_path):
        """未知の料金単位のフォーマット"""
        with patch("json.load", return_value=self.mock_pricing_data):
            tracker = BedrockUsageTracker()
            formatted = tracker._format_pricing_unit(500)
            self.assertEqual(formatted, "500")

    @patch("services.bedrock_usage_tracker.Path")
    def test_thread_safety(self, mock_path):
        """スレッドセーフティのテスト（複数スレッドから同時にadd_usageを呼ぶ）"""
        import threading

        with patch("json.load", return_value=self.mock_pricing_data):
            tracker = BedrockUsageTracker()

            def add_usage_multiple_times():
                for _ in range(10):
                    tracker.add_usage(
                        model_id="anthropic.claude-3-haiku-20240307-v1:0",
                        usage={"inputTokens": 10, "outputTokens": 5, "totalTokens": 15},
                    )

            # 10個のスレッドを作成
            threads = []
            for _ in range(10):
                thread = threading.Thread(target=add_usage_multiple_times)
                threads.append(thread)
                thread.start()

            # 全スレッドの完了を待つ
            for thread in threads:
                thread.join()

            # 10スレッド × 10回 = 100回のadd_usageが呼ばれる
            self.assertEqual(len(tracker._usages), 100)

            # 集計結果を確認
            summary = tracker.get_usage_summary()
            self.assertEqual(summary[0][KEY_REQUEST_COUNT], 100)
            self.assertEqual(summary[0][KEY_TOKENS]["inputTokens"], 1000)  # 10 * 100

    @patch("services.bedrock_usage_tracker.Path")
    def test_empty_usage_summary(self, mock_path):
        """usageが追加されていない場合の空のサマリー"""
        with patch("json.load", return_value=self.mock_pricing_data):
            tracker = BedrockUsageTracker()
            summary = tracker.get_usage_summary()
            self.assertEqual(len(summary), 0)

    @patch("services.bedrock_usage_tracker.Path")
    def test_add_usage_with_none_values(self, mock_path):
        """NoneやFalsyな値でadd_usageを呼んだ場合は無視される"""
        with patch("json.load", return_value=self.mock_pricing_data):
            tracker = BedrockUsageTracker()

            # Noneを渡す
            tracker.add_usage(None, {"inputTokens": 100})
            tracker.add_usage("model-id", None)
            tracker.add_usage("", {"inputTokens": 100})

            # 何も追加されていない
            self.assertEqual(len(tracker._usages), 0)


if __name__ == "__main__":
    unittest.main()
