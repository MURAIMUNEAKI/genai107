"""
Unit tests for utils/utils.py
"""

import os
import sys

import pytest

# Add the invokeModel directory to the path so we can import from it
sys.path.insert(0, os.path.join(os.path.dirname(__file__), "../../../lib/constructs/rag-lambda/invokeModel"))

from utils.utils import convertToArray, replacePlaceholders


class TestConvertToArray:
    def test_convert_to_array_normal(self):
        """通常入力: 複数ブラケットを含む文字列をリストに変換"""
        result = convertToArray("[item1\nitem2] [item3\nitem4\nitem5]")
        assert result == ["item1", "item2", "item3", "item4", "item5"]

    def test_convert_to_array_single_bracket(self):
        """シングルブラケット: 単一の角括弧を持つ文字列"""
        result = convertToArray("[single item]")
        assert result == ["single item"]

    def test_convert_to_array_empty_raises(self):
        """空入力: ValueError が発生する"""
        with pytest.raises(Exception) as exc_info:
            convertToArray("")
        assert "No matches found in the input" in str(exc_info.value)

    def test_convert_to_array_no_brackets_raises(self):
        """ブラケットなし: ValueError が発生する"""
        with pytest.raises(Exception) as exc_info:
            convertToArray("No brackets here")
        assert "No matches found in the input" in str(exc_info.value)

    def test_convert_to_array_whitespace(self):
        """空白を含む入力: 前後の空白がトリムされる"""
        result = convertToArray("  [  item1  \n  item2  ]  [item3\n  item4  ]  ")
        assert result == ["item1", "item2", "item3", "item4"]


class TestReplacePlaceholders:
    def test_replace_placeholders_basic(self):
        """基本的なプレースホルダー置換"""
        text = "Hello, {{ name }}! You have {{ count }} messages."
        result = replacePlaceholders(text, {"name": "Alice", "count": "3"})
        assert result == "Hello, Alice! You have 3 messages."

    def test_replace_placeholders_unknown_key(self):
        """未知のキーはプレースホルダーがそのまま残る"""
        text = "Hello, {{ name }}! Your score is {{ score }}."
        result = replacePlaceholders(text, {"name": "Bob"})
        assert result == "Hello, Bob! Your score is {{ score }}."

    def test_replace_placeholders_no_spaces(self):
        """空白なしのプレースホルダー"""
        text = "Value: {{key}}"
        result = replacePlaceholders(text, {"key": "test_value"})
        assert result == "Value: test_value"

    def test_replace_placeholders_multiline(self):
        """複数行テキストのプレースホルダー置換"""
        text = "<context>\n{{context}}\n</context>\n<question>{{question}}</question>"
        result = replacePlaceholders(
            text,
            {
                "context": "some context text",
                "question": "what is this?",
            },
        )
        assert result == "<context>\nsome context text\n</context>\n<question>what is this?</question>"

    def test_replace_placeholders_empty_text(self):
        """空のテキスト"""
        result = replacePlaceholders("", {"key": "value"})
        assert result == ""
