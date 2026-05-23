"""
file_handler.py
添付ファイルの検証とBedrock Converse API形式への変換を担当

このモジュールは、API Gatewayから受信した添付ファイルを処理します:
- ファイル形式の判定とバリデーション
- ファイルサイズのチェック
- Bedrock Converse API形式への変換
- ファイル名のサニタイズ
"""

import base64
import copy
import hashlib
import re
from typing import Any

from aws_lambda_powertools import Logger

# サポートされる形式の定義
SUPPORTED_DOCUMENT_FORMATS = ["pdf", "csv", "doc", "docx", "xls", "xlsx", "html", "txt", "md"]
SUPPORTED_IMAGE_FORMATS = ["png", "jpeg", "jpg", "webp", "gif"]

# 制限値
MAX_IMAGES = 20
MAX_DOCUMENTS = 5
MAX_IMAGE_SIZE_BYTES = int(3.75 * 1024 * 1024)  # 3.75MB
MAX_DOCUMENT_SIZE_BYTES = int(4.5 * 1024 * 1024)  # 4.5MB

logger = Logger(child=True)


class FileValidationError(ValueError):
    """ファイルバリデーションエラー"""

    pass


def sanitize_filename(filename: str) -> str:
    """
    ファイル名をサニタイズし、衝突を避けるためにハッシュを追加する

    Bedrockのドキュメントブロックで許可されている文字のみを残します:
    - 英数字、スペース、ハイフン、括弧のみ許可
    - 連続する空白やハイフンを1つに集約
    - 元のファイル名のMD5ハッシュの最初の8文字をサフィックスとして追加
      （日本語ファイル名など、同じサニタイズ結果になるファイル名の衝突を回避）

    Args:
        filename: 元のファイル名

    Returns:
        サニタイズされたファイル名（ハッシュ付き）

    例:
        "報告書.pdf"      → "file-a3f2c1b4"
        "document.pdf"   → "file-document-b5c6d7e8"
        "My Report.pdf"  → "file-My-Report-f9a0b1c2"
    """

    # 許可されていない文字をハイフンに置換
    sanitized_name = re.sub(r"[^a-zA-Z0-9\s\-\(\)\[\]]", "-", filename)
    # 連続する空白を1つに
    sanitized_name = re.sub(r"\s+", " ", sanitized_name)
    # 連続するハイフンを1つに
    sanitized_name = re.sub(r"-+", "-", sanitized_name)
    # 前後のハイフンと空白を削除
    sanitized_name = sanitized_name.strip("-").strip()

    # 元のファイル名のハッシュを計算（衝突回避用）
    hash_value = hashlib.md5(filename.encode("utf-8")).hexdigest()[:8]  # noqa: S324

    return f"file-{sanitized_name}-{hash_value}"


def get_file_extension(filename: str) -> str:
    """
    ファイルの拡張子を取得

    Args:
        filename: ファイル名

    Returns:
        小文字に変換された拡張子 (ドットなし)
    """
    parts = filename.rsplit(".", 1)
    if len(parts) > 1:
        return parts[1].lower()
    return ""


def process_files(files_input: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """
    添付ファイルを処理してBedrock Converse API形式に変換

    入力形式:
        [
            {
                "key": "group_id",
                "files": [
                    {"filename": "doc.pdf", "content": "base64..."}
                ]
            }
        ]

    出力形式:
        [
            {
                "document": {
                    "format": "pdf",
                    "name": "sanitized-filename",
                    "source": {"bytes": bytes}
                }
            },
            {
                "image": {
                    "format": "png",
                    "source": {"bytes": bytes}
                }
            }
        ]

    Args:
        files_input: ファイルグループのリスト

    Returns:
        Bedrock Converse APIのcontentブロックのリスト

    Raises:
        FileValidationError: バリデーションエラー
    """
    content_blocks = []
    image_count = 0
    document_count = 0

    if not files_input or not isinstance(files_input, list):
        return content_blocks

    for group in files_input:
        if not isinstance(group, dict) or "files" not in group:
            logger.warning(f"Invalid file group format: {group}")
            continue

        files = group.get("files", [])
        if not isinstance(files, list):
            continue

        for file_item in files:
            if not isinstance(file_item, dict):
                continue

            filename = file_item.get("filename", "")
            content_base64 = file_item.get("content", "")

            if not filename or not content_base64:
                logger.warning("File missing filename or content, skipping")
                continue

            # 拡張子を取得
            ext = get_file_extension(filename)

            # Base64デコード
            try:
                file_bytes = base64.b64decode(content_base64)
            except Exception as e:
                raise FileValidationError(f"Failed to decode file '{filename}': {str(e)}") from e

            file_size = len(file_bytes)

            # ドキュメント形式の処理
            if ext in SUPPORTED_DOCUMENT_FORMATS:
                # バリデーション
                if file_size > MAX_DOCUMENT_SIZE_BYTES:
                    raise FileValidationError(
                        f"Document '{filename}' exceeds the size limit of "
                        f"{MAX_DOCUMENT_SIZE_BYTES / 1024 / 1024:.2f} MB"
                    )

                document_count += 1
                if document_count > MAX_DOCUMENTS:
                    raise FileValidationError(f"Number of documents exceeds the limit of {MAX_DOCUMENTS}")

                # Bedrock形式に変換
                content_blocks.append(
                    {"document": {"format": ext, "name": sanitize_filename(filename), "source": {"bytes": file_bytes}}}
                )
                logger.debug(f"Processed document: {filename} ({file_size} bytes)")

            # 画像形式の処理
            elif ext in SUPPORTED_IMAGE_FORMATS:
                # バリデーション
                if file_size > MAX_IMAGE_SIZE_BYTES:
                    raise FileValidationError(
                        f"Image '{filename}' exceeds the size limit of {MAX_IMAGE_SIZE_BYTES / 1024 / 1024:.2f} MB"
                    )

                image_count += 1
                if image_count > MAX_IMAGES:
                    raise FileValidationError(f"Number of images exceeds the limit of {MAX_IMAGES}")

                # Bedrock形式に変換
                content_blocks.append({"image": {"format": ext, "source": {"bytes": file_bytes}}})
                logger.debug(f"Processed image: {filename} ({file_size} bytes)")

            else:
                logger.warning(f"Unsupported file type '{ext}' for file {filename}. Skipping.")

    logger.info(f"Processed {document_count} documents and {image_count} images")
    return content_blocks


def truncate_files_for_logging(inputs: dict[str, Any], max_length: int = 2048) -> dict[str, Any]:
    """
    ログ出力用にファイル内容をトランケートする

    ファイルの内容(base64エンコード文字列)が長すぎる場合、
    指定された最大長で切り詰めて、元のinputsオブジェクトは変更せずに
    新しいオブジェクトを返します。

    Args:
        inputs: 入力データ (inputs全体)
        max_length: ファイル内容の最大長

    Returns:
        トランケートされた入力データのコピー
    """
    if "files" not in inputs or not isinstance(inputs["files"], list):
        return inputs

    # ディープコピーを作成
    truncated_inputs = copy.deepcopy(inputs)

    for group in truncated_inputs.get("files", []):
        if not isinstance(group, dict) or "files" not in group:
            continue

        for file_item in group.get("files", []):
            if not isinstance(file_item, dict):
                continue

            content = file_item.get("content", "")
            if isinstance(content, str) and len(content) > max_length:
                file_item["content"] = content[:max_length] + "...[TRUNCATED]"

    return truncated_inputs
