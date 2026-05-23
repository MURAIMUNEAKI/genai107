output "api_key_id" {
  description = "The ID of the created API key"
  value       = google_apikeys_key.api_key.id
}

output "api_key_name" {
  description = "The name of the created API key"
  value       = google_apikeys_key.api_key.name
}
