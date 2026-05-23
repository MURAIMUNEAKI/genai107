output "function_uri" {
  value = try(google_cloudfunctions2_function.default.service_config[0].uri, null)
}

output "gateway_url" {
  value = "https://${google_api_gateway_gateway.api_gw.default_hostname}/invoke"
  description = "The URL of the API Gateway."
}

output "api_id" {
  value = google_api_gateway_api.api.api_id
  description = "The ID of the created API Gateway API."
}

output "api_name" {
  value = var.api_name
  description = "The name of the API Gateway."
}

output "genai_api_service" {
  value = google_project_service.enable_api.service
}

output "api_config_id" {
  value = google_api_gateway_api_config.api_config.id
}

output "function_service_account_email" {
  description = "The email of the service account used by the Cloud Function."
  value       = google_service_account.function_sa.email
}
