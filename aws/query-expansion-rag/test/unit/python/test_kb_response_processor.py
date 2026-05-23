"""
Unit tests for services/kb_response_processor.py
"""

import os
import sys

# Add the invokeModel directory to the path so we can import from it
sys.path.insert(0, os.path.join(os.path.dirname(__file__), "../../../lib/constructs/rag-lambda/invokeModel"))

from services.kb_response_processor import (
    Citation,
    KBResponse,
    extract_texts_from_kb_response,
    process_kb_response,
)


class TestProcessKBResponse:
    def test_process_kb_response(self):
        """正常系: 2件の引用を持つレスポンスが正しくパースされる"""
        test_response = {
            "citations": [
                {
                    "generatedResponsePart": {"textResponsePart": {"text": "Sample text 1"}},
                    "retrievedReferences": [
                        {
                            "metadata": {
                                "file_name": "doc1",
                                "url": "http://example.com/doc1",
                                "x-amz-bedrock-kb-document-page-number": 1,
                            }
                        }
                    ],
                },
                {
                    "generatedResponsePart": {"textResponsePart": {"text": "Sample text 2"}},
                    "retrievedReferences": [
                        {
                            "metadata": {
                                "file_name": "doc2",
                                "url": "http://example.com/doc2",
                                "x-amz-bedrock-kb-document-page-number": 2,
                            }
                        }
                    ],
                },
            ]
        }

        kb_response = process_kb_response(test_response)

        assert isinstance(kb_response, KBResponse)
        assert len(kb_response.citations) == 2
        assert kb_response.citations[0].text == "Sample text 1"
        assert kb_response.citations[1].text == "Sample text 2"
        assert kb_response.citations[0].metadata[0].file_name == "doc1"
        assert kb_response.citations[1].metadata[0].file_name == "doc2"

    def test_extract_texts_from_kb_response(self):
        """テキスト抽出: 引用テキストのリストが返される"""
        kb_response = KBResponse()
        kb_response.citations = [Citation(text="Text 1"), Citation(text="Text 2"), Citation(text="Text 3")]

        texts = extract_texts_from_kb_response(kb_response)

        assert texts == ["Text 1", "Text 2", "Text 3"]

    def test_empty_response(self):
        """空レスポンス: 引用なしの KBResponse が返される"""
        empty_response = {}
        kb_response = process_kb_response(empty_response)
        assert isinstance(kb_response, KBResponse)
        assert len(kb_response.citations) == 0

    def test_missing_metadata(self):
        """メタデータなし: メタデータの各フィールドが None になる"""
        test_response = {
            "citations": [
                {"generatedResponsePart": {"textResponsePart": {"text": "Sample text"}}, "retrievedReferences": [{}]}
            ]
        }

        kb_response = process_kb_response(test_response)
        assert len(kb_response.citations) == 1
        assert kb_response.citations[0].text == "Sample text"
        assert kb_response.citations[0].metadata[0].file_name is None
        assert kb_response.citations[0].metadata[0].url is None
        assert kb_response.citations[0].metadata[0].page_number is None

    def test_page_number_zero(self):
        """ページ番号0: page_number=0 が正しく処理される（is not None チェックの確認）"""
        test_response = {
            "citations": [
                {
                    "generatedResponsePart": {"textResponsePart": {"text": "Page zero text"}},
                    "retrievedReferences": [
                        {
                            "metadata": {
                                "file_name": "doc0",
                                "url": "http://example.com/doc0",
                                "x-amz-bedrock-kb-document-page-number": 0,
                            }
                        }
                    ],
                }
            ]
        }

        kb_response = process_kb_response(test_response)
        assert len(kb_response.citations) == 1
        assert kb_response.citations[0].metadata[0].page_number == 0
