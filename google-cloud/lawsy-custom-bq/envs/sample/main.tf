terraform {
  required_version = ">= 1.0.0"
  required_providers {
    google = {
      source  = "hashicorp/google"
      version = "~> 4.0"
    }
  }
}

provider "google" {
  project = local.project_id
  region  = local.location
}

# 利用するAPIを有効化
module "service_apis" {
  source  = "../../modules/resource/service_api"
  project = {
    id = local.project_id
    number = local.project_number
  }
  apis    = local.apis
  services = [
    "storage-component.googleapis.com",
    "apigateway.googleapis.com",
    "servicemanagement.googleapis.com",
    "servicecontrol.googleapis.com",
    "cloudfunctions.googleapis.com",
    "cloudbuild.googleapis.com",
    "aiplatform.googleapis.com",
    "apikeys.googleapis.com",
    "generativelanguage.googleapis.com",
    "bigquery.googleapis.com",
  ]
}

# API有効化に時間がかかるため待機
resource "time_sleep" "wait_60_seconds" {
  depends_on = [module.service_apis]

  create_duration = "60s"
}

# 生成AI APIを作成
module "api" {
  depends_on = [module.service_apis, time_sleep.wait_60_seconds]
  source  = "../../modules/api"
  project_id = local.project_id
  project_number = local.project_number
  location = local.location
  service_account_id = local.service_account_id
  api_name = local.api_name
  api_id = local.api_id
  dataset_id = local.dataset_id
  gemini_settings = local.gemini_settings
}

# 生成AI APIを呼び出すためのAPIキーを作成
module "api_key" {
  source        = "../../modules/genai_api_key"
  api_name      = local.api_name
  project_id    = local.project_id
  allowed_ips   = local.allowed_ip_addresses
  genai_api_service = local.genai_api_service
  api_id        = local.api_id
  api_config_id = local.api_config_id
  depends_on = [module.api]
}

# 生成AI APIモジュールの出力を参照するためのローカル変数
locals {
  api_module = module.api
}

# 出力設定
output "function_uri" {
  value = module.api.function_uri
}

output "gateway_url" {
  value = module.api.gateway_url
}

output "api_key_id" {
  description = "API Key ID"
  value       = module.api_key.api_key_id
}
