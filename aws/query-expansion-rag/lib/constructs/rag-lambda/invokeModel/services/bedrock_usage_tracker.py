"""
bedrock_usage_tracker.py

Bedrock APIの利用状況(トークン数)を追跡し、概算コストを計算するためのヘルパークラス。
TypeScript版の bedrockUsageTracker.ts の設計を忠実に移植し、
modelPricing.jsonの変更のみで新しいトークンタイプに対応できる完全動的な実装。

スレッドセーフティ: threading.Lockを使用して、複数スレッドからの同時アクセスを保護。
"""

from __future__ import annotations

import json
import threading
from dataclasses import dataclass
from enum import Enum
from pathlib import Path
from typing import Any, TypedDict

# ============================
# 定数定義
# ============================

# ファイル名
MODEL_PRICING_FILENAME = "modelPricing.json"

# modelPricing.jsonのキー名（外部JSONフォーマットに依存するため文字列定数を維持）
KEY_TOKEN_CATEGORIES = "tokenCategories"  # noqa: S105
KEY_PRICING_UNIT = "pricingUnit"
KEY_CURRENCY = "currency"
KEY_CATEGORY_NAME = "categoryName"
KEY_UNIT_PRICE = "unitPrice"

# 出力サマリーのキー名（外部APIレスポンスフォーマットに依存するため文字列定数を維持）
KEY_MODEL_VERSION = "modelVersion"
KEY_ESTIMATED_COST_INFO = "estimatedCostInfo"
KEY_ESTIMATED_COST = "estimatedCost"
KEY_REQUEST_COUNT = "requestCount"
KEY_TOKENS = "tokens"

# 動的に生成されるキーのサフィックス
SUFFIX_TOKENS = "Tokens"


# ============================
# Enum定義
# ============================


class PricingUnit(Enum):
    """料金単位の定義"""

    THOUSAND = (1000, "1K")
    MILLION = (1_000_000, "1M")

    def __init__(self, value: int, display: str):
        self._value = value
        self._display = display

    @property
    def value_int(self) -> int:
        """数値としての料金単位を取得"""
        return self._value

    @property
    def value_str(self) -> str:
        """表示文字列としての料金単位を取得"""
        return self._display

    @classmethod
    def from_int(cls, value: int) -> PricingUnit | None:
        """整数値からPricingUnitを取得"""
        for unit in cls:
            if unit.value_int == value:
                return unit
        return None


# ============================
# dataclass定義
# ============================


@dataclass
class UsageRecord:
    """内部で使用する利用状況レコード"""

    model_id: str
    usage: dict[str, Any]


# ============================
# TypedDict定義
# ============================


class TokenPricingInfo(TypedDict, total=False):
    """トークンカテゴリごとの料金情報"""

    categoryName: str  # "input" | "output"
    unitPrice: float


class ModelPricing(TypedDict, total=False):
    """モデルごとの料金設定"""

    currency: str
    pricingUnit: int
    tokenCategories: dict[str, TokenPricingInfo]


class EstimatedCostInfo(TypedDict, total=False):
    """コスト計算結果の詳細"""

    estimatedCost: float
    currency: str
    # 動的に追加されるフィールド:
    # - inputTokens, outputTokens, etc.
    # - inputToken1KUnitPrice, outputToken1KUnitPrice, etc.


class UsageSummary(TypedDict, total=False):
    """最終的な利用状況サマリー"""

    modelVersion: str
    requestCount: int
    tokens: dict[str, Any]
    estimatedCostInfo: EstimatedCostInfo


class BedrockUsageTracker:
    """
    Bedrockの利用状況を追跡し、サマリーを生成するヘルパークラス。

    TypeScript版の設計を踏襲し、modelPricing.jsonの変更のみで
    新しいトークンタイプに対応できる完全動的な実装。
    """

    def __init__(self):
        """初期化：モデル価格情報を読み込む"""
        self._usages: list[UsageRecord] = []
        self._model_pricing: dict[str, ModelPricing] = self._load_model_pricing()
        self._lock = threading.Lock()  # スレッドセーフティのためのロック

    def _load_model_pricing(self) -> dict[str, ModelPricing]:
        """
        modelPricing.jsonを読み込む

        Returns:
            モデル価格情報の辞書。読み込みに失敗した場合は空の辞書
        """
        try:
            pricing_file_path = Path(__file__).parent / MODEL_PRICING_FILENAME
            with open(pricing_file_path, encoding="utf-8") as f:
                return json.load(f)
        except Exception as e:
            print(f"Failed to load model pricing data: {e}")
            return {}

    def add_usage(self, model_id: str, usage: dict[str, Any]) -> None:
        """
        レスポンスから得られた利用状況データを追加

        スレッドセーフ: 複数のスレッドから同時に呼ばれても安全に動作します。

        Args:
            model_id: 使用されたモデルのID
            usage: Bedrock APIの usage オブジェクト（そのまま）
                   例: {'inputTokens': 100, 'outputTokens': 50, 'totalTokens': 150, ...}
        """
        if not model_id or not usage:
            return
        with self._lock:
            self._usages.append(UsageRecord(model_id=model_id, usage=usage))

    def _format_pricing_unit(self, unit: int) -> str:
        """
        料金単位を整形するプライベートメソッド

        Args:
            unit: 料金単位（1000, 1000000など）

        Returns:
            整形された文字列 (例: 1000 -> "1K", 1000000 -> "1M")
        """
        pricing_unit = PricingUnit.from_int(unit)
        if pricing_unit:
            return pricing_unit.value_str
        else:
            return str(unit)

    def _calculate_estimated_cost(
        self, model_id: str, aggregated_tokens: dict[str, Any]
    ) -> EstimatedCostInfo | None:
        """
        集計されたトークン数から概算コストを計算

        完全に動的な実装：
        - modelPricing.jsonのtokenCategoriesをループ
        - 各tokenKeyに対応するトークン数をaggregated_tokensから取得
        - カテゴリごとに集計して出力を動的生成

        Args:
            model_id: モデルID
            aggregated_tokens: 集計されたトークン使用量

        Returns:
            コスト情報の辞書。価格情報がない場合はNone
        """
        model_pricing = self._model_pricing.get(model_id)
        if not model_pricing or KEY_TOKEN_CATEGORIES not in model_pricing:
            return None

        total_cost = 0.0
        tokens_by_category: dict[str, int] = {}

        # tokenCategoriesをループしてコストを計算（TypeScript版と同じロジック）
        token_categories = model_pricing[KEY_TOKEN_CATEGORIES]
        for token_key, pricing_info in token_categories.items():
            if not pricing_info:
                continue

            # aggregated_tokensから対応するトークン数を取得
            # 新しいトークンタイプが追加されても、ここで自動的に処理される
            token_count = aggregated_tokens.get(token_key, 0)
            if token_count and token_count > 0:
                cost = (token_count / model_pricing[KEY_PRICING_UNIT]) * pricing_info[KEY_UNIT_PRICE]
                total_cost += cost

                # カテゴリごとにトークンを集計
                category_name = pricing_info[KEY_CATEGORY_NAME]
                current_tokens = tokens_by_category.get(category_name, 0)
                tokens_by_category[category_name] = current_tokens + token_count

        # レスポンスオブジェクトを構築
        unit_str = self._format_pricing_unit(model_pricing[KEY_PRICING_UNIT])
        cost_details: EstimatedCostInfo = {
            KEY_ESTIMATED_COST: total_cost,
            KEY_CURRENCY: model_pricing[KEY_CURRENCY],
        }

        # カテゴリごとのトークン数を追加 (例: "inputTokens", "outputTokens")
        for category, total_tokens in tokens_by_category.items():
            cost_details[f"{category}{SUFFIX_TOKENS}"] = total_tokens  # type: ignore

        # カテゴリごとの単価を追加 (例: "inputToken1KUnitPrice")
        unique_categories: dict[str, float] = {}
        for pricing_info in token_categories.values():
            if pricing_info:
                category_name = pricing_info[KEY_CATEGORY_NAME]
                if category_name not in unique_categories:
                    unique_categories[category_name] = pricing_info[KEY_UNIT_PRICE]

        for category, unit_price in unique_categories.items():
            tokens_key = f"{category}{SUFFIX_TOKENS}"
            if tokens_key in cost_details:
                unit_price_key = f"{category}Token{unit_str}UnitPrice"
                cost_details[unit_price_key] = unit_price  # type: ignore

        return cost_details

    def get_usage_summary(self) -> list[UsageSummary]:
        """
        蓄積された全利用情報から、モデルごとに集計されたサマリーを生成

        Returns:
            UsageSummaryオブジェクトの配列
            各要素の形式:
            {
                'modelVersion': str,
                'requestCount': int,
                'tokens': {
                    'inputTokens': int,
                    'outputTokens': int,
                    'totalTokens': int,
                    ...
                },
                'estimatedCostInfo': {
                    'estimatedCost': float,
                    'currency': str,
                    'inputTokens': int,
                    'inputToken1KUnitPrice': float,
                    ...
                }
            }
        """
        summary_data: dict[str, dict[str, Any]] = {}

        # モデルごとにトークンを集計
        for record in self._usages:
            model_id = record.model_id
            usage = record.usage

            if model_id not in summary_data:
                summary_data[model_id] = {KEY_REQUEST_COUNT: 0, KEY_TOKENS: {}}

            current = summary_data[model_id]
            current[KEY_REQUEST_COUNT] += 1

            # トークンを動的に集計（全てのキーを処理）
            for key, value in usage.items():
                if isinstance(value, (int, float)):
                    current[KEY_TOKENS][key] = current[KEY_TOKENS].get(key, 0) + value

        # 出力サマリーを生成
        output_summary: list[UsageSummary] = []
        for model_id, data in summary_data.items():
            estimated_cost_info = self._calculate_estimated_cost(model_id, data[KEY_TOKENS])

            summary_entry: UsageSummary = {
                KEY_MODEL_VERSION: model_id,
                KEY_REQUEST_COUNT: data[KEY_REQUEST_COUNT],
                KEY_TOKENS: data[KEY_TOKENS],
            }

            if estimated_cost_info:
                summary_entry[KEY_ESTIMATED_COST_INFO] = estimated_cost_info

            output_summary.append(summary_entry)

        return output_summary
