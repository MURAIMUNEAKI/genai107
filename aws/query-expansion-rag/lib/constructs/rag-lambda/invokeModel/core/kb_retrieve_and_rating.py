import os
import re
from queue import Queue
from threading import Thread
from typing import TYPE_CHECKING

from aws_lambda_powertools import Logger
from config.config_manager import ConfigManager
from services.aws_clients import bedrock_agent_runtime, bedrock_runtime
from services.kb_response_processor import KBResponse, extract_texts_from_kb_response, process_kb_response
from utils.utils import convertToArray, handleException, replacePlaceholders

if TYPE_CHECKING:
    from services.bedrock_usage_tracker import BedrockUsageTracker

# Constants
KNOWLEDGE_BASE_ID = os.environ["KNOWLEDGE_BASE_ID"]
KB_NUM_RESULTS = int(os.environ["KB_NUM_RESULTS"])
REGION = os.environ.get("AWS_REGION")

# Set logger and tracer
SERVICE_NAME = "query-expansion-rag-lambda"
logger = Logger(service=SERVICE_NAME)

# 推論設定の読み込み
try:
    retrieve_generate_config = ConfigManager("retrieve_and_generate")
    rating_config = ConfigManager("relevance_rating")
except Exception as e:
    logger.info("Error loading inference configurations:")
    handleException(e, logger)
    raise


def build_model_arn(model_id: str) -> str:
    """
    モデルIDからARNを生成する

    inference profileとfoundation modelを自動判定し、適切なARN形式を返す。

    - inference profile形式（例: jp.anthropic.claude-sonnet-4-5-20250929-v1:0）の場合:
      arn:aws:bedrock:{region}:{account}:inference-profile/{modelId}

    - foundation model形式（例: anthropic.claude-3-haiku-20240307-v1:0）の場合:
      arn:aws:bedrock:{region}::foundation-model/{modelId}

    Args:
        model_id: モデルID

    Returns:
        ARN文字列

    Raises:
        ValueError: inference profile形式でAWS_ACCOUNT_IDが取得できない場合
    """
    region = os.environ.get("AWS_REGION", "ap-northeast-1")

    # inference profile形式の判定
    # リージョンコード (us, eu, jp, apac, global) で始まるパターン
    inference_profile_pattern = re.compile(r"^(us|eu|jp|apac|global)\.")

    if inference_profile_pattern.match(model_id):
        # inference profile形式の場合
        account_id = os.environ.get("AWS_ACCOUNT_ID")
        if not account_id:
            raise ValueError(f"AWS_ACCOUNT_ID environment variable is required for inference profile: {model_id}")
        return f"arn:aws:bedrock:{region}:{account_id}:inference-profile/{model_id}"
    else:
        # foundation model形式の場合
        return f"arn:aws:bedrock:{region}::foundation-model/{model_id}"


def map_rating(converse_resp_text: str, kb_response: KBResponse) -> KBResponse:
    try:
        if re.search(r"\[([^\]]+)\]", converse_resp_text):
            parsed_rating = convertToArray(converse_resp_text)

            # 抜粋番号が一致するKnowledge Base応答があれば関連度の評価結果をセット
            for rating_result in parsed_rating:
                try:
                    # 対応策A: 最初のコロンだけで分割（maxsplit=1）
                    parts = rating_result.split(":", 1)
                    if len(parts) != 2:
                        logger.warning(f"Invalid rating format (missing colon): {rating_result}")
                        continue

                    citation_no, rating_str = parts

                    # 抜粋番号のパース
                    match = re.match(r"^抜粋(\d+)", citation_no.strip())
                    if not match:
                        logger.warning(f"Unexpected citation expression: {rating_result}")
                        continue

                    citation_index = int(match.group(1)) - 1

                    # 評価値から数字のみを抽出（堅牢化）
                    # 例: "3", " 3 ", "評価: 3", "3点" などに対応
                    rating_match = re.search(r"\d+", rating_str.strip())
                    if not rating_match:
                        logger.warning(f"No numeric rating found in: {rating_str}")
                        continue

                    rating_value = int(rating_match.group())

                    # 評価値の範囲チェック（1-5の範囲を想定）
                    if not (1 <= rating_value <= 5):
                        logger.warning(f"Rating value out of range (1-5): {rating_value}")
                        # 範囲外でも一応セットするか、スキップするかは要件次第
                        # ここでは範囲外をクリップする例
                        rating_value = max(1, min(5, rating_value))

                    # 該当する引用が存在すればセット
                    if citation_index < len(kb_response.citations):
                        kb_response.citations[citation_index].relevance_rating = rating_value
                        logger.debug(f"Set rating {rating_value} for citation {citation_index + 1}")
                    else:
                        logger.warning(
                            f"Citation index {citation_index + 1} out of range (total: {len(kb_response.citations)})"
                        )

                except (ValueError, IndexError) as e:
                    logger.warning(f'Error parsing rating result "{rating_result}": {str(e)}')
                    continue
        else:
            logger.info(
                f'Relevance rating result does not contain expected array expression "[xxx, xxx]", {converse_resp_text}'
            )
    except Exception as e:
        logger.info(f'Unexpected error occurred when mapping relevance rating", {converse_resp_text}')
        handleException(e, logger)

    return kb_response


def retrieve_kb_and_rating(
    user_question: str,
    query: str,
    result_queue: Queue,
    usage_tracker: BedrockUsageTracker | None = None,
    metadata_filters: list[dict] | None = None,
):
    """
    個別のクエリを処理する関数
    この関数内でretrieve_and_generateを呼び出し、LLMで評価します

    Args:
        user_question: ユーザーの質問
        query: 拡張されたクエリ
        result_queue: 結果を格納するキュー
        usage_tracker: 使用状況を追跡するトラッカー (オプション)
        metadata_filters: メタデータフィルタのリスト (オプション)
    """
    try:
        retrieval_configuration = {
            "vectorSearchConfiguration": {
                "numberOfResults": KB_NUM_RESULTS,
            },
        }
        if metadata_filters:
            retrieval_configuration["vectorSearchConfiguration"]["filter"] = metadata_filters

        response = bedrock_agent_runtime.retrieve_and_generate(
            retrieveAndGenerateConfiguration={
                "type": "KNOWLEDGE_BASE",
                "knowledgeBaseConfiguration": {
                    "generationConfiguration": {
                        "inferenceConfig": {"textInferenceConfig": retrieve_generate_config.get_inference_config()},
                        "promptTemplate": {
                            "textPromptTemplate": retrieve_generate_config.get_system_prompt(),
                        },
                    },
                    "knowledgeBaseId": KNOWLEDGE_BASE_ID,
                    "modelArn": build_model_arn(retrieve_generate_config.get_model_id()),
                    "retrievalConfiguration": retrieval_configuration,
                },
            },
            input={"text": query},
        )
        logger.debug(f"Retrieve and generation query:{query}  response: {response}")

        kb_response = process_kb_response(response)
        kb_response_texts = extract_texts_from_kb_response(kb_response)
        kb_response_texts = [f"抜粋{i + 1}: {text}" for i, text in enumerate(kb_response_texts)]

        # プレースホルダをユーザーの質問及び拡張クエリで置き換え
        system_prompt = rating_config.get_system_prompt()
        if not system_prompt:
            raise ValueError("No system prompt found for relevance_rating configuration")

        placeholder_replaced_prompt = replacePlaceholders(
            system_prompt,
            {
                "question": user_question,
                "context": "\n".join(kb_response_texts),
            },
        )
        logger.debug(f"Prompt for relevance rating: {placeholder_replaced_prompt}")

        # Knowledge Baseからの出力がユーザーの質問とどれだけ関連があるか評価
        converse_response = bedrock_runtime.converse(
            modelId=rating_config.get_model_id(),
            messages=[{"role": "user", "content": [{"text": placeholder_replaced_prompt}]}],
            inferenceConfig=rating_config.get_inference_config(),
        )
        logger.debug(f"Relevance rating response: {converse_response}")

        converse_content = converse_response.get("output", {}).get("message", {}).get("content", [])
        converse_resp_text = ""
        for item in converse_content:
            converse_resp_text += item.get("text", "")

        # usage_trackerが渡されていれば記録
        if usage_tracker and "usage" in converse_response:
            usage_tracker.add_usage(rating_config.get_model_id(), converse_response["usage"])

        kb_response = map_rating(converse_resp_text, kb_response)

        result_queue.put(kb_response)

    except Exception as e:
        logger.info("Error in retrieve_kb_and_rating for query")
        handleException(e, logger)
        result_queue.put(None)


def invoke_retrives(
    user_question: str,
    queries: list[str],
    usage_tracker: BedrockUsageTracker | None = None,
    metadata_filters: list[dict] | None = None,
) -> KBResponse:
    """
    複数のクエリを並列で処理する関数

    Args:
        user_question: ユーザーの質問
        queries: 拡張されたクエリのリスト
        usage_tracker: 使用状況を追跡するトラッカー (オプション)
        metadata_filters: メタデータフィルタのリスト (オプション)

    Returns:
        集約されたKnowledge Base応答
    """
    threads = []
    result_queue = Queue()

    for query in queries:
        thread = Thread(
            target=retrieve_kb_and_rating, args=(user_question, query, result_queue, usage_tracker, metadata_filters)
        )
        threads.append(thread)
        thread.start()

    for thread in threads:
        thread.join()

    # 並列で処理したRetrieveと関連度評価の結果を収集
    aggregated_results = KBResponse()
    while not result_queue.empty():
        result = result_queue.get()
        if result:
            aggregated_results.citations.extend(result.citations)

    # 関連度が高い順に並び替え
    aggregated_results.citations = [
        item for item in sorted(aggregated_results.citations, key=lambda x: x.relevance_rating, reverse=True)
    ]

    # 設定ファイルから最大引用件数を取得し、上位n件のみに絞り込む
    # 優先順位: apps配下の個別アプリ定義 > relevance_rating.toml > デフォルト値(50)
    max_citations = rating_config.get_max_citations(default=50)
    if len(aggregated_results.citations) > max_citations:
        logger.info(
            f"Limiting citations from {len(aggregated_results.citations)} to top {max_citations} by relevance rating"
        )
        aggregated_results.citations = aggregated_results.citations[:max_citations]

    return aggregated_results
