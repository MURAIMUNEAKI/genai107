"""
クエリ拡張機能
ユーザーの質問から検索用の複数のクエリを生成する

更新: usage_trackerを追加し、API使用状況を追跡
"""

import json
import re
from typing import TYPE_CHECKING, Any

from aws_lambda_powertools import Logger
from config.config_manager import ConfigManager
from services.converse_helper import invoke_converse_simple
from utils.utils import handleException, replacePlaceholders

if TYPE_CHECKING:
    from services.bedrock_usage_tracker import BedrockUsageTracker

# Set logger
SERVICE_NAME = "query-expansion-rag-lambda"
logger = Logger(service=SERVICE_NAME)


def expand_query(
    question: str,
    n_queries: int = 3,
    file_content_blocks: list[dict[str, Any]] | None = None,
    usage_tracker: BedrockUsageTracker | None = None,
) -> list[str]:
    """
    クエリ拡張を実行

    Args:
        question: ユーザーの質問
        n_queries: 生成するクエリの数
        file_content_blocks: 添付ファイルのコンテンツブロック (オプション)
        usage_tracker: 使用状況を追跡するトラッカー (オプション)

    Returns:
        生成されたクエリのリスト
    """
    try:
        # 設定マネージャーを初期化
        config = ConfigManager("query_expansion")

        # システムプロンプト取得
        system_prompt = config.get_system_prompt()
        if not system_prompt:
            logger.warning("No system prompt found for query_expansion, using fallback")
            system_prompt = "Generate {{n_queries}} search queries for: {{question}}"

        # プロンプトにプレースホルダを適用
        prompt = replacePlaceholders(system_prompt, {"question": question, "n_queries": str(n_queries)})

        # モデルID取得
        model_id = config.get_model_id()
        logger.debug(f"Using model: {model_id}")

        # 推論設定取得
        inference_config = config.get_inference_config()
        logger.debug(f"Inference config: {inference_config}")

        # 添付ファイルがある場合はログに記録
        if file_content_blocks:
            logger.info(f"Query expansion with {len(file_content_blocks)} file attachments")

        # Bedrock Converse APIを使用してクエリを生成
        # converse_helperを使用して、添付ファイルをサポート
        completion, usage = invoke_converse_simple(
            model_id=model_id,
            user_message_text=prompt,
            inference_config=inference_config,
            file_content_blocks=file_content_blocks,
        )

        # usage_trackerが渡されていれば記録
        if usage_tracker and usage:
            usage_tracker.add_usage(model_id, usage)

        logger.debug(f"Query expansion completion: {completion}")

        # クエリ配列をパース
        queries = parse_queries_from_completion(completion)

        logger.info(f"Expanded {len(queries)} queries from question")
        return queries

    except Exception as e:
        logger.error(f"Error in query expansion: {str(e)}")
        handleException(e, logger)
        # エラー時は元の質問のみを返す
        return [question]


def parse_queries_from_completion(completion: str) -> list[str]:
    """
    モデルの出力からクエリ配列をパース

    Args:
        completion: モデルの出力テキスト

    Returns:
        パースされたクエリのリスト
    """
    try:
        # JavaScript配列形式の文字列を探す
        # パターン: ["query1", "query2", ...]

        # JSON配列を抽出
        match = re.search(r"\[.*\]", completion, re.DOTALL)
        if match:
            array_str = match.group(0)
            # JSONとしてパース
            queries = json.loads(array_str)
            if isinstance(queries, list) and all(isinstance(q, str) for q in queries):
                return queries

        # パースに失敗した場合は、行ごとに分割して返す
        logger.warning("Failed to parse queries as JSON array, falling back to line-by-line parsing")
        lines = [line.strip() for line in completion.split("\n") if line.strip()]
        # クォートや番号を除去
        queries = []
        for line in lines:
            # 行頭の番号、クォート、コンマを除去
            cleaned = re.sub(r"^[\d\.\)]+\s*", "", line)  # 1. や 1) を除去
            cleaned = re.sub(r'^["\']|["\'],?$', "", cleaned)  # クォートを除去
            if cleaned and not cleaned.startswith("<") and not cleaned.startswith("["):
                queries.append(cleaned)

        return queries[:10] if queries else []  # 最大10個まで

    except Exception as e:
        logger.error(f"Error parsing queries: {str(e)}")
        return []
