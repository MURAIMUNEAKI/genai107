import os
from typing import Any

import toml
from aws_lambda_powertools import Logger
from utils.utils import handleException

# ロガーの設定
logger = Logger(service="query-expansion-rag-lambda")

INFERENCE_PARAMS = ["maxTokens", "temperature", "topP", "topK", "stopSequences"]


class ConfigManager:
    def __init__(self, config_type: str):
        """
        設定マネージャーの初期化

        :param config_type: 推論タイプ (answer_generation, relevance_rating, etc.)
        """
        self.config_type = config_type
        self.app_param_file = os.environ.get("APP_PARAM_FILE", "")

        # Lambdaパッケージ内の設定パスを設定
        # __file__ は既に config/ ディレクトリ内にあるため、親ディレクトリから参照
        base_dir = os.path.dirname(os.path.dirname(__file__))  # invokeModel/ ディレクトリ
        self.default_config_dir = os.path.join(base_dir, "config", "defaults")
        self.app_config_dir = os.path.join(base_dir, "config", "apps")

        # 設定をロード
        # 1. 推論タイプごとのデフォルト設定ファイル（answer_generation.toml など）
        self.type_config = self._load_type_config(self.config_type)

        # 2. アプリケーション固有設定ファイル
        self.app_config = self._load_app_config()

        logger.debug(f"Loaded config for type: {self.config_type}")
        logger.debug(f"Using model: {self.get_model_id()}")

    def _load_type_config(self, config_type: str) -> dict[str, Any]:
        """
        処理タイプ固有の設定ファイルをロード

        :param config_type: 推論タイプ (answer_generation, relevance_rating, etc.)
        :return: 読み込んだ設定の辞書
        :raises FileNotFoundError: 設定ファイルが見つからない場合
        :raises Exception: その他のエラーが発生した場合
        """
        # タイプ別の設定ファイル（例：answer_generation.toml）
        config_file = os.path.join(self.default_config_dir, f"{config_type}.toml")

        if not os.path.exists(config_file):
            error_message = f"Type config file not found: {config_file}"
            logger.error(error_message)
            raise FileNotFoundError(error_message)

        try:
            with open(config_file, encoding="utf-8") as f:
                content = f.read()
            return toml.loads(content)
        except Exception as e:
            logger.error(f"Failed to load type config for {config_type}: {str(e)}")
            handleException(e, logger)
            raise

    def _load_app_config(self) -> dict[str, Any]:
        """アプリケーション固有の設定をロード"""
        try:
            if not self.app_param_file:
                logger.warning("No APP_PARAM_FILE specified, skipping app config")
                return {}

            # アプリ設定ファイルのパス
            app_config_path = os.path.join(self.app_config_dir, f"{self.app_param_file}")
            if not os.path.exists(app_config_path):
                logger.warning(f"App config file not found: {app_config_path}")
                return {}

            with open(app_config_path, encoding="utf-8") as f:
                content = f.read()
            return toml.loads(content)
        except Exception as e:
            logger.error(f"Failed to load app config: {str(e)}")
            return {}

    def get_model_id(self) -> str:
        """現在の設定タイプに対応するモデルIDを取得"""
        # 1. アプリ固有のタイプ別設定を確認
        if self.config_type in self.app_config and "modelId" in self.app_config[self.config_type]:
            return self.app_config[self.config_type]["modelId"]

        # 2. タイプのデフォルト設定を使用
        if "modelId" in self.type_config:
            return self.type_config["modelId"]

        # 3. 最終フォールバック - 通常はここには到達しないはず
        error_message = f"Model ID not found for {self.config_type} in any config"
        logger.error(error_message)
        raise ValueError(error_message)

    def get_system_prompt(self) -> str | None:
        """現在の設定タイプに対応するシステムプロンプトを取得"""
        # 1. アプリ固有のタイプ別設定を確認
        if self.config_type in self.app_config and "systemPrompt" in self.app_config[self.config_type]:
            return self.app_config[self.config_type]["systemPrompt"]

        # 2. タイプのデフォルト設定を使用
        if "systemPrompt" in self.type_config:
            return self.type_config["systemPrompt"]

        return None

    def get_inference_config(self) -> dict[str, Any]:
        """現在のモデルに対応する推論設定を取得"""
        # 基本設定はタイプのデフォルト設定から取得
        inference_params = {}
        for param in INFERENCE_PARAMS:
            if param in self.type_config:
                inference_params[param] = self.type_config[param]

        # アプリ固有の設定で上書き
        if self.config_type in self.app_config:
            for param in INFERENCE_PARAMS:
                if param in self.app_config[self.config_type]:
                    inference_params[param] = self.app_config[self.config_type][param]

        # Noneの値を除外
        return {k: v for k, v in inference_params.items() if v is not None}

    def get_max_citations(self, default: int = 50) -> int:
        """取得する引用の最大件数を取得

        :param default: デフォルト値（設定ファイルに記載がない場合のフォールバック値）
        :return: 最大引用件数
        """
        # 1. アプリ固有のタイプ別設定を確認（最優先）
        if self.config_type in self.app_config and "maxCitations" in self.app_config[self.config_type]:
            max_citations = self.app_config[self.config_type]["maxCitations"]
            logger.debug(f"Using maxCitations from app config: {max_citations}")
            return int(max_citations)

        # 2. タイプのデフォルト設定を使用
        if "maxCitations" in self.type_config:
            max_citations = self.type_config["maxCitations"]
            logger.debug(f"Using maxCitations from type config: {max_citations}")
            return int(max_citations)

        # 3. 最終フォールバック値を使用
        logger.debug(f"Using default maxCitations: {default}")
        return default
