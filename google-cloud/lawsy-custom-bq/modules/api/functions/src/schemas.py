from enum import Enum
from typing import Any

from pydantic import BaseModel, Field, root_validator


class Grounding(Enum):
    WEB = "web_search"
    URL_CONTEXT = "url_context"


class HarmCategory(Enum):
    HARM_CATEGORY_HATE_SPEECH = "HARM_CATEGORY_HATE_SPEECH"
    HARM_CATEGORY_DANGEROUS_CONTENT = "HARM_CATEGORY_DANGEROUS_CONTENT"
    HARM_CATEGORY_SEXUALLY_EXPLICIT = "HARM_CATEGORY_SEXUALLY_EXPLICIT"
    HARM_CATEGORY_HARASSMENT = "HARM_CATEGORY_HARASSMENT"


class HarmBlockThreshold(Enum):
    BLOCK_NONE = "BLOCK_NONE"
    BLOCK_ONLY_HIGH = "BLOCK_ONLY_HIGH"
    BLOCK_MEDIUM_AND_ABOVE = "BLOCK_MEDIUM_AND_ABOVE"
    BLOCK_LOW_AND_ABOVE = "BLOCK_LOW_AND_ABOVE"


class FileInput(BaseModel):
    key: str
    filename: str
    content: str | None = None
    gcs_uri: str | None = None

    @root_validator(pre=True)
    def check_content_or_gcs_uri_exclusive(cls, values):
        content, gcs_uri = values.get("content"), values.get("gcs_uri")
        if (content is None and gcs_uri is None) or (content is not None and gcs_uri is not None):
            raise ValueError('Either "content" or "gcs_uri" must be provided, but not both.')
        return values


class RequestBody(BaseModel):
    input_text: str
    chat_history: list[dict[str, Any]] | None = None
    grounding: str | bool | None = None
    temperature: float | None = Field(default=None, ge=0.0, le=1.0)
    max_output_tokens: int | None = Field(default=None, gt=0)
    top_p: float | None = Field(default=None, ge=0.0, le=1.0)
    top_k: int | None = Field(default=None, gt=0)
    candidate_count: int | None = Field(default=None, gt=0)
    system_instruction: str | None = None
    files: list[FileInput] | None = None
    thinking_budget: int | None = Field(default=None, ge=0)


class EstimatedCostInfo(BaseModel):
    estimatedCost: float
    currency: str

    class Config:
        extra = "allow"  # Allow dynamic fields like inputTokens, etc.


class UsageSummaryEntry(BaseModel):
    modelVersion: str
    requestCount: int
    tokens: dict[str, int]
    estimatedCostInfo: EstimatedCostInfo | None = None


class ResponseBody(BaseModel):
    outputs: str
    usageMetadata: list[UsageSummaryEntry] | None = None


class ErrorResponse(BaseModel):
    error: str


# 構造化出力用のスキーマ
class LawNamesEstimation(BaseModel):
    law_names: list[str] = Field(description="推定された関連法令名のリスト")


class SelectionResult(BaseModel):
    selected_indices: list[int] = Field(description="選択された項目のインデックス番号のリスト")


class Reference(BaseModel):
    title: str = Field(description="参照文献のタイトル")
    source: str = Field(description="参照元（法令名、URL等）")
    content_summary: str = Field(description="引用内容の要約")


class ReportReferences(BaseModel):
    references: list[Reference] = Field(description="レポートで使用された参考文献のリスト")
