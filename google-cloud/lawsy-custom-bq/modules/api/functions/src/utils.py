from __future__ import annotations

import logging
import mimetypes
import os
import sys

from gemini_config import DEFAULT_LOG_LEVEL, LOG_LEVEL


def setup_logging():
    """Sets up structured logging."""
    log_level_str = os.environ.get(LOG_LEVEL, DEFAULT_LOG_LEVEL).upper()
    log_level = getattr(logging, log_level_str, logging.INFO)

    # For Google Cloud Logging, we don't need a complex formatter.
    # The library handles JSON structuring automatically.
    logging.basicConfig(level=log_level, stream=sys.stdout, format="%(levelname)s: %(message)s")
    return logging.getLogger()


def get_env_param(name: str, default, converter):
    try:
        return converter(os.environ.get(name))
    except (TypeError, ValueError):
        return default


def create_markdown_links(data):
    """
    Python配列からMarkdown形式のリンクリストを生成する関数

    Args:
        data: リンク情報を含むPython配列

    Returns:
        Markdown形式の文字列
    """

    markdown_text = ""
    for item in data:
        title = item.web.title
        uri = item.web.uri
        if title and uri:
            markdown_text += f"- [{title}]({uri})\n"  # タイトル付きリンクに変更

    return markdown_text


def get_mime_type(filename: str) -> str:
    """ファイル名からMIMEタイプを取得します。"""
    mime_type, _ = mimetypes.guess_type(filename)
    return mime_type or "application/octet-stream"
