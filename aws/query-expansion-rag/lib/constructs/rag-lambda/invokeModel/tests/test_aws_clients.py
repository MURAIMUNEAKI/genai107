"""
test_aws_clients.py

services/aws_clients モジュールのユニットテスト。
Singleton boto3 クライアントがモジュールレベルで定義され、
テスト時にモック差し替え可能であることを検証します。
"""

import unittest
from unittest.mock import MagicMock, patch


class TestAwsClientsModuleLevel(unittest.TestCase):
    """aws_clients モジュールの Singleton 定義テスト"""

    def test_bedrock_runtime_is_defined(self):
        """bedrock_runtime がモジュールレベルで定義されていること"""
        import services.aws_clients as aws_clients

        self.assertTrue(hasattr(aws_clients, "bedrock_runtime"))

    def test_bedrock_agent_runtime_is_defined(self):
        """bedrock_agent_runtime がモジュールレベルで定義されていること"""
        import services.aws_clients as aws_clients

        self.assertTrue(hasattr(aws_clients, "bedrock_agent_runtime"))

    def test_bedrock_runtime_is_patchable(self):
        """@patch('services.aws_clients.bedrock_runtime') でモック差し替え可能であること"""
        with patch("services.aws_clients.bedrock_runtime") as mock_client:
            mock_client.converse = MagicMock(return_value={"output": {}})
            import services.aws_clients as aws_clients

            result = aws_clients.bedrock_runtime.converse(modelId="test", messages=[])
            mock_client.converse.assert_called_once()
            self.assertEqual(result, {"output": {}})

    def test_bedrock_agent_runtime_is_patchable(self):
        """@patch('services.aws_clients.bedrock_agent_runtime') でモック差し替え可能であること"""
        with patch("services.aws_clients.bedrock_agent_runtime") as mock_client:
            mock_client.retrieve_and_generate = MagicMock(return_value={"output": {}})
            import services.aws_clients as aws_clients

            result = aws_clients.bedrock_agent_runtime.retrieve_and_generate(input={"text": "test"})
            mock_client.retrieve_and_generate.assert_called_once()
            self.assertEqual(result, {"output": {}})


if __name__ == "__main__":
    unittest.main()
