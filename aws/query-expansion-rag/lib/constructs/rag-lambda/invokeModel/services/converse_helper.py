"""
converse_helper.py
Bedrock Converse API呼び出しのヘルパー関数

このモジュールは、Converse API呼び出しを共通化し、
添付ファイル(file_content_blocks)の統合を容易にします。

更新: usage取得機能を追加。Bedrock APIのusageオブジェクトをそのまま返却することで、
APIが拡張された際にもコード変更不要で対応可能。
"""

from typing import Any

from aws_lambda_powertools import Logger

from services.aws_clients import bedrock_runtime

logger = Logger(child=True)


def build_user_message(text_content: str, file_content_blocks: list[dict[str, Any]] | None = None) -> dict[str, Any]:
    """
    Converse APIのユーザーメッセージを構築

    テキストと添付ファイルを含むメッセージを構築します。
    Converse APIでは、contentは配列形式で、テキスト、ドキュメント、画像を含めることができます。

    Args:
        text_content: メッセージのテキスト内容
        file_content_blocks: 添付ファイルのコンテンツブロック (file_handler.pyから取得)

    Returns:
        Converse API形式のメッセージオブジェクト
    """
    # テキストコンテンツから開始
    content_blocks = [{"text": text_content}]

    # 添付ファイルがある場合は追加
    if file_content_blocks:
        content_blocks.extend(file_content_blocks)
        logger.debug(f"Added {len(file_content_blocks)} file attachments to message")

    return {"role": "user", "content": content_blocks}


def invoke_converse(
    model_id: str,
    user_message_text: str,
    inference_config: dict[str, Any],
    file_content_blocks: list[dict[str, Any]] | None = None,
    system_prompt: str | None = None,
) -> tuple[str, dict[str, Any] | None]:
    """
    Bedrock Converse APIを呼び出して応答とusageを取得

    Args:
        model_id: 使用するモデルのID
        user_message_text: ユーザーメッセージのテキスト
        inference_config: 推論設定 (maxTokens, temperature, topP等)
        file_content_blocks: 添付ファイルのコンテンツブロック (オプション)
        system_prompt: システムプロンプト (オプション)

    Returns:
        Tuple[str, Optional[Dict[str, Any]]]: (応答テキスト, usageオブジェクト)
        usageは Bedrock API の response.usage をそのまま返却。
        APIが拡張された際にもコード変更不要で対応可能。

    Raises:
        Exception: Converse API呼び出しに失敗した場合
    """
    # ユーザーメッセージを構築
    user_message = build_user_message(user_message_text, file_content_blocks)

    # API呼び出しのパラメータを構築
    api_params = {"modelId": model_id, "messages": [user_message], "inferenceConfig": inference_config}

    # システムプロンプトが指定されている場合は追加
    if system_prompt:
        api_params["system"] = [{"text": system_prompt}]

    logger.debug(f"Invoking Converse API with model: {model_id}")
    if file_content_blocks:
        logger.info(f"Including {len(file_content_blocks)} file attachments in Converse API call")

    # Converse APIを呼び出し
    response = bedrock_runtime.converse(**api_params)

    # レスポンスからテキストを抽出
    content = response.get("output", {}).get("message", {}).get("content", [])
    response_text = ""
    for item in content:
        if "text" in item:
            response_text += item["text"]

    logger.debug(f"Received response from model (length: {len(response_text)})")

    # usageをそのまま返却（キーの変換や抽出を行わない）
    usage = response.get("usage")

    return response_text, usage


def invoke_converse_with_system(
    model_id: str,
    system_prompt: str,
    user_message_text: str,
    inference_config: dict[str, Any],
    file_content_blocks: list[dict[str, Any]] | None = None,
) -> tuple[str, dict[str, Any] | None]:
    """
    システムプロンプト付きでBedrock Converse APIを呼び出す

    システムプロンプトを明示的に分離したバージョン。
    query_expansion.pyやanswer_generation.pyで使用することを想定。

    Args:
        model_id: 使用するモデルのID
        system_prompt: システムプロンプト
        user_message_text: ユーザーメッセージのテキスト
        inference_config: 推論設定
        file_content_blocks: 添付ファイルのコンテンツブロック (オプション)

    Returns:
        Tuple[str, Optional[Dict[str, Any]]]: (応答テキスト, usageオブジェクト)
    """
    return invoke_converse(
        model_id=model_id,
        user_message_text=user_message_text,
        inference_config=inference_config,
        file_content_blocks=file_content_blocks,
        system_prompt=system_prompt,
    )


def invoke_converse_simple(
    model_id: str,
    user_message_text: str,
    inference_config: dict[str, Any],
    file_content_blocks: list[dict[str, Any]] | None = None,
) -> tuple[str, dict[str, Any] | None]:
    """
    システムプロンプトなしでBedrock Converse APIを呼び出す

    シンプルなバージョン。ユーザーメッセージにシステムプロンプトが
    既に含まれている場合に使用。

    Args:
        model_id: 使用するモデルのID
        user_message_text: ユーザーメッセージのテキスト (システムプロンプト含む場合あり)
        inference_config: 推論設定
        file_content_blocks: 添付ファイルのコンテンツブロック (オプション)

    Returns:
        Tuple[str, Optional[Dict[str, Any]]]: (応答テキスト, usageオブジェクト)
    """
    return invoke_converse(
        model_id=model_id,
        user_message_text=user_message_text,
        inference_config=inference_config,
        file_content_blocks=file_content_blocks,
        system_prompt=None,
    )
