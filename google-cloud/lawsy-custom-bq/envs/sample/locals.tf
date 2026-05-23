locals {
    # APIの名称を開発するAPIごとに適切な名前に変更する
    api_name = "YOUR_API_NAME"         # 例: "lawsy-bq"
    api_id   = "YOUR_API_ID"           # 例: "lawsy-bq-api"

    # Google Cloud プロジェクト設定
    # GCPコンソール > プロジェクト情報 で確認できます
    project_id     = "YOUR_PROJECT_ID"      # 例: "my-gcp-project"
    project_number = "YOUR_PROJECT_NUMBER"  # 例: "123456789012"

    # BigQueryのデータセットID
    # preprocess/ でデータを投入する際に指定したデータセット名と合わせる
    dataset_id = "e_laws_search"

    # Gemini設定
    # inference_project_id: Vertex AI APIを有効化したGCPプロジェクトIDを指定
    gemini_settings = {
      model_id                      = "gemini-2.5-flash"
      inference_project_id          = "YOUR_INFERENCE_PROJECT_ID"  # 例: "my-gcp-project"
      inference_location            = "asia-northeast1"
      generation_temperature        = 0.0
      generation_max_output_tokens  = 65535
      generation_top_p              = 1.0
      generation_top_k              = 10
      generation_candidate_count    = 1
      generation_system_instruction = "You are a friendly and helpful assistant. Ensure answers are complete unless the user requests brevity. When generating code, include explanations."
    }

    # APIキーのアクセス許可IPアドレスリスト
    # このAPIにアクセスを許可するIPアドレスを CIDR 形式で列挙する
    allowed_ip_addresses = [
      "YOUR_IP_ADDRESS_1/32",
      "YOUR_IP_ADDRESS_2/32",
    ]

    # 固定設定のため必要な場合のみ変更
    location           = "asia-northeast1"
    service_account_id = "apig-function-invoke"
    genai_api_service  = module.api.genai_api_service
    api_config_id      = module.api.api_config_id

    # resource/service_apiモジュール
    apis = {
        disable_dependent_services = false
        disable_on_destroy         = false
    }
}
