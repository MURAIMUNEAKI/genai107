from dataclasses import dataclass, field
from typing import Any


@dataclass
class ModelParams:
    maxTokens: int  # noqa: N815
    temperature: float | None = None
    topP: float | None = None  # noqa: N815
    topK: int | None = None  # noqa: N815
    stopSequences: list[str] | None = None  # noqa: N815


# 処理タイプ別設定ファイルの構造
@dataclass
class TypeConfig:
    modelId: str  # noqa: N815
    maxTokens: int  # noqa: N815
    temperature: float
    topP: float  # noqa: N815
    topK: int | None = None  # noqa: N815
    systemPrompt: str | None = None  # noqa: N815
    stopSequences: list[str] | None = None  # noqa: N815


# アプリ個別の設定ファイル フォーマット定義
@dataclass
class AppConfig:
    name: str
    description: str | None = None
    idcUserNames: list[str] = field(default_factory=list)  # noqa: N815
    responseFooter: str | None = None  # noqa: N815
    logLevel: str | None = None  # noqa: N815
    answer_generation: dict[str, Any] | None = None
    answer_generation_detail: dict[str, Any] | None = None
    relevance_rating: dict[str, Any] | None = None
    retrieve_and_generate: dict[str, Any] | None = None
