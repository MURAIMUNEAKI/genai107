locals {
  // 英大文字をすべて小文字に
  api_name_lowercased = lower(var.api_name)
  // スペースをすべてハイフンに置換
  api_name_normalized = replace(local.api_name_lowercased, " ", "-")
}

resource "random_string" "api_key_suffix" {
  length  = 6
  special = false
  upper   = false

  keepers = {
    # API名が変わらない限り、同じsuffixを生成する
    # これにより、Terraform stateが失われても同じAPI keyが作成される
    api_name = var.api_name
    project_id = var.project_id
  }
}

resource "google_apikeys_key" "api_key" {
  name         = substr("${local.api_name_normalized}-api-key-${random_string.api_key_suffix.result}", 0, 38)
  display_name = "API Key for ${var.api_name}"
  project      = var.project_id

  restrictions {
    api_targets {
      service = var.genai_api_service
    }

    server_key_restrictions {
      allowed_ips = var.allowed_ips
    }
  }
}
