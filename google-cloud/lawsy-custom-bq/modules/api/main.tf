resource "random_id" "default" {
  byte_length = 8
}

resource "google_storage_bucket" "default" {
  name                        = "${random_id.default.hex}-gcf-source"
  location                    = "ASIA-NORTHEAST1"
  uniform_bucket_level_access = true
}

# Cloud Build サービスアカウント仕様変更への対応
# See: https://cloud.google.com/build/docs/cloud-build-service-account-updates
resource "google_project_iam_member" "grant_cloud_build_role" {
  project = var.project_id
  role    = "roles/cloudbuild.builds.builder"
  member  = "serviceAccount:${var.project_number}-compute@developer.gserviceaccount.com"
}

resource "google_project_iam_member" "grant_cloud_function_role" {
  project = var.project_id
  role    = "roles/cloudfunctions.developer"
  member  = "serviceAccount:${var.project_number}-compute@developer.gserviceaccount.com"
}

# Service account for API gateway
resource "random_string" "service_account_suffix" {
  length  = 4
  special = false
  upper   = false
}

resource "google_service_account" "api_gateway_sa" {
  account_id   = "${var.service_account_id}-${random_string.service_account_suffix.result}"
  display_name = "Service Account for API Gateway"
  project      = var.project_id

  lifecycle {
    ignore_changes = [account_id]
  }
}

# Data source to check if the service account already exists
data "google_service_account" "existing_sa" {
  account_id = google_service_account.api_gateway_sa.account_id
  project    = var.project_id
}

# Use local to determine whether to use existing or new service account
locals {
  service_account_email = data.google_service_account.existing_sa.email != "" ? data.google_service_account.existing_sa.email : google_service_account.api_gateway_sa.email
}

resource "google_project_iam_member" "api_gateway_sa_roles" {
  project = var.project_id
  role    = "roles/run.invoker"
  member  = "serviceAccount:${local.service_account_email}"
}

# Python function
resource "random_string" "function_suffix" {
  length  = 4
  special = false
  upper   = false
}

resource "random_string" "function_sa_suffix" {
  length  = 4
  special = false
  upper   = false
}

resource "google_service_account" "function_sa" {
  account_id   = substr("${var.api_id}-gcf-sa-${random_string.function_sa_suffix.result}", 0, 30)
  display_name = "${var.api_name} Service Account"
  project      = var.project_id

  lifecycle {
    create_before_destroy = true
  }
}

# Wait for the service account to be created
resource "time_sleep" "wait_for_function_sa" {
  depends_on = [google_service_account.function_sa]

  create_duration = "30s"
}

resource "google_project_iam_member" "function_sa_roles" {
  project = var.project_id
  role    = "roles/aiplatform.user"
  member  = "serviceAccount:${google_service_account.function_sa.email}"

  depends_on = [time_sleep.wait_for_function_sa]
}


resource "google_project_iam_member" "function_sa_bigquery_user" {
  project = var.project_id
  role    = "roles/bigquery.user"
  member  = "serviceAccount:${google_service_account.function_sa.email}"

  depends_on = [time_sleep.wait_for_function_sa]
}

resource "google_project_iam_member" "function_sa_bigquery_dataViewer" {
  project = var.project_id
  role    = "roles/bigquery.dataViewer"
  member  = "serviceAccount:${google_service_account.function_sa.email}"

  depends_on = [time_sleep.wait_for_function_sa]
}

resource "google_project_iam_member" "function_sa_bigquery_connectionUser" {
  project = var.project_id
  role    = "roles/bigquery.connectionUser"
  member  = "serviceAccount:${google_service_account.function_sa.email}"

  depends_on = [time_sleep.wait_for_function_sa]
}

# Grant the function's service account access to BigQuery
resource "google_project_iam_member" "function_sa_bigquery_access" {
  project = var.project_id
  role    = "roles/bigquery.user"
  member  = "serviceAccount:${google_service_account.function_sa.email}"
}

# Grant the function's service account access to storage
resource "google_service_account_iam_member" "function_sa_user" {
  service_account_id = google_service_account.function_sa.name
  role               = "roles/iam.serviceAccountUser"
  member             = "serviceAccount:${google_service_account.function_sa.email}"

  depends_on = [time_sleep.wait_for_function_sa]
}

resource "null_resource" "check_function_dir" {
  triggers = {
    api_id = var.api_id
  }

  provisioner "local-exec" {
    command = <<EOT
      if [ ! -d "${path.module}/functions/src" ]; then
        echo "Error: Function directory '${path.module}/functions/src' does not exist. Please create it manually and ensure main.py is present."
        exit 1
      fi
      if [ ! -f "${path.module}/functions/src/main.py" ]; then
        echo "Error: main.py not found in '${path.module}/functions/src'. Please add it manually."
        exit 1
      fi
    EOT
  }
}

data "archive_file" "src" {
  type        = "zip"
  output_path = "/tmp/${var.api_id}-function-source.zip"
  source_dir  = "${path.module}/functions/src/"
  depends_on  = [null_resource.check_function_dir]
}

resource "google_storage_bucket_object" "object" {
  name   = "${var.api_id}/function-source-${md5(file("${path.module}/functions/src/requirements.txt"))}.zip"
  bucket = google_storage_bucket.default.name
  source = data.archive_file.src.output_path
}

resource "google_cloudfunctions2_function" "default" {
  name        = "${var.api_id}-${random_string.function_sa_suffix.result}"
  location    = var.location
  description = "A python function for ${var.api_name}"

  build_config {
    runtime     = "python312"
    entry_point = "main"
    source {
      storage_source {
        bucket = google_storage_bucket.default.name
        object = google_storage_bucket_object.object.name
      }
    }
  }

  service_config {
    max_instance_count = 1
    available_cpu      = "1"
    available_memory   = "1024M"
    timeout_seconds    = 3600
    max_instance_request_concurrency = 12
    service_account_email = google_service_account.function_sa.email
    environment_variables = {
      GOOGLE_CLOUD_PROJECT = var.project_id
      BQ_DATASET_ID = var.dataset_id
      INFERENCE_PROJECT_ID = var.gemini_settings.inference_project_id
      INFERENCE_LOCATION = var.gemini_settings.inference_location
      MODEL_ID = var.gemini_settings.model_id
      GENERATION_TEMPERATURE = var.gemini_settings.generation_temperature
      GENERATION_MAX_OUTPUT_TOKENS = var.gemini_settings.generation_max_output_tokens
      GENERATION_TOP_P = var.gemini_settings.generation_top_p
      GENERATION_TOP_K = var.gemini_settings.generation_top_k
      GENERATION_CANDIDATE_COUNT = var.gemini_settings.generation_candidate_count
      GENERATION_SYSTEM_INSTRUCTION = var.gemini_settings.generation_system_instruction
    }
  }

  lifecycle {
    create_before_destroy = true
  }

  depends_on = [
    google_project_iam_member.function_sa_roles,
    google_service_account_iam_member.function_sa_user
  ]
}

# Enable API Gateway API
resource "google_project_service" "api_gateway" {
  project = var.project_id
  service = "apigateway.googleapis.com"

  disable_dependent_services = true
  disable_on_destroy         = false
}

# Define API for API Gateway
locals {
  gateway_id = "${var.api_id}-gateway"
}

# API Gateway API
resource "google_api_gateway_api" "api" {
  provider     = google-beta
  api_id       = var.api_id
  display_name = var.api_name
  project      = var.project_id

  lifecycle {
    create_before_destroy = true
  }

  depends_on = [google_project_service.api_gateway]
}

# API Gateway API Config
resource "google_api_gateway_api_config" "api_config" {
  provider      = google-beta
  api           = google_api_gateway_api.api.api_id
  api_config_id_prefix = "${var.api_id}-config"
  project       = var.project_id

  openapi_documents {
    document {
      path     = "${path.module}/openapi.yaml"
      contents = base64encode(templatefile("${path.module}/openapi.yaml", {
        func_url = google_cloudfunctions2_function.default.url
        api_name = var.api_name
      }))
    }
  }

  gateway_config {
    backend_config {
      google_service_account = local.service_account_email
    }
  }

  lifecycle {
    create_before_destroy = true
  }
}

# API Gateway
resource "google_api_gateway_gateway" "api_gw" {
  provider   = google-beta
  region     = var.location
  api_config = google_api_gateway_api_config.api_config.id
  gateway_id = local.gateway_id
  project    = var.project_id

  lifecycle {
    create_before_destroy = true
  }
}

# Enable the API
resource "google_project_service" "enable_api" {
  project = var.project_id
  service = google_api_gateway_api.api.managed_service

  disable_dependent_services = false
  disable_on_destroy         = false

  depends_on = [google_api_gateway_gateway.api_gw]
}